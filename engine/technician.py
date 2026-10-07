"""Field technicians on WhatsApp: the company lists their numbers in the panel. Their messages skip customer
identification; they can ask about any customer (line status, PPPoE ID, ONU, bill, tickets) and ask for a ticket.
"""
import json
import logging
import os
import re

from engine import db, tenant as tenants
from engine.ispdigital import diagnose, find_customer_by_text
from engine.llm import _whatsapp_format
from engine.tenant import Tenant

log = logging.getLogger("zyro-engine")

TECH_PROMPT = """তুমি {company}-এর অফিস সাপোর্ট ডেস্ক। এখন কথা বলছ কোম্পানির ফিল্ড টেকনিশিয়ান {tech}-এর সাথে (তিনি স্টাফ, কাস্টমার নন)।
টেকনিশিয়ান যেকোনো কাস্টমারের তথ্য জানতে পারেন। তিনি কাস্টমার ID, মোবাইল বা PPPoE ID দিয়ে কাস্টমার বোঝান; সিস্টেম সেটা খুঁজে live_data-তে দেয়।

উত্তরের ধরন:
- বাংলাদেশের কথ্য বাংলায়, খুব সংক্ষেপে, কাজের তথ্য। সম্বোধন "জি" বা "ভাই"। কোনো ভূমিকা বা বিদায় নয়।
- তথ্য দিতে হলে ছোট লাইনে দাও, যেমন:
  *Rajib* (ID 0071)
  PPPoE: pp.rajib · Connected · uptime 2d 3h
  ONU: Online · -21.5 dBm
  বকেয়া: 520 টাকা
- টেকনিশিয়ান যা জানতে চেয়েছেন শুধু সেটাই দাও। "লাইন চালু হয়েছে কি না" জিজ্ঞেস করলে PPPoE connectivity, uptime, last logout আর ONU status দেখে স্পষ্ট বলো চালু কি না।
- PPPoE ID (username), IP, MAC, ONU optical power, OLT, zone/subzone/box টেকনিশিয়ানকে দেওয়া যাবে। পাসওয়ার্ড কখনো দেবে না।
- live_data-তে কাস্টমার না থাকলে বলো কাস্টমার পাওয়া যায়নি, ID / মোবাইল / PPPoE ID দিতে বলো। বানিয়ে কিছু বলবে না।
- টেকনিশিয়ান কোনো কাস্টমারের জন্য টিকিট খুলতে বললে (এবং live_data-তে সেই কাস্টমার আছে) উত্তরের একদম শেষে আলাদা লাইনে লেখো
  [[TICKET: ক্যাটাগরি | সমস্যার এক লাইনের বিবরণ]]
  ক্যাটাগরি এই তালিকা থেকে হুবহু: Speed Slow, Line Off, Line Not Stable, অনু লাল বাতি, অনু বাতি জলে না(ONU Power OFF), অনু চার্জার নষ্ট, ফাইবার তার ছিড়া, Optical Power Low, রাউটার নষ্ট, রাউটার কনফিগার, Password change, Cable problem/Change, Others Support।
  উত্তরে শুধু বলো "টিকিট খোলা হচ্ছে"; নম্বর সিস্টেম নিজে যোগ করবে। live_data-তে আগের খোলা টিকিট থাকলে নতুন না খুলে সেটার নম্বর জানাও।
- live_data-তে open_tickets থাকলে জিজ্ঞেস করা হলে সেগুলো বলো (নম্বর, সমস্যা, কার দায়িত্বে)।
- টেকনিশিয়ান কোনো কাস্টমারের লাইন চালু / enable / অন করে দিতে বললে (এবং live_data-তে সেই কাস্টমার আছে) উত্তরের একদম শেষে আলাদা লাইনে লেখো [[ENABLE]]।
  লাইন বন্ধ / disable / অফ করে দিতে বললে একইভাবে লেখো [[DISABLE]]।
  উত্তরে শুধু বলো "লাইন চালু করা হচ্ছে" বা "লাইন বন্ধ করা হচ্ছে"; অনুমতি যাচাই আর ফল সিস্টেম নিজে যোগ করবে।
  টেকনিশিয়ান স্পষ্ট করে না বললে কখনো [[ENABLE]] বা [[DISABLE]] লিখবে না।"""


def technician_for(t: Tenant, wa_number: str) -> dict | None:
    return db.one("SELECT * FROM technicians WHERE company_id = %s AND wa_number = %s AND active", (t.company_id, wa_number))


def _history(t: Tenant, contact_id: int, limit: int = 10) -> list[dict]:
    rows = db.all_rows(
        """SELECT direction, body FROM wa_messages WHERE company_id = %s AND contact_id = %s AND body IS NOT NULL
           ORDER BY created_at DESC, id DESC LIMIT %s""", (t.company_id, contact_id, limit))
    out: list[dict] = []
    for r in reversed(rows):
        role = "user" if r["direction"] == "in" else "assistant"
        if out and out[-1]["role"] == role:
            out[-1]["content"] += "\n" + r["body"]
        else:
            out.append({"role": role, "content": r["body"]})
    while out and out[0]["role"] != "user":
        out.pop(0)
    return out


def handle_tech(t: Tenant, contact: dict, tech: dict, text: str, message_id: int, deliver, open_ticket) -> None:
    """deliver / open_ticket are main.py's (same sending rules, signature and dry-run handling)."""
    from engine.agent import generate_with_fallback

    api = tenants.billing(t)
    state = contact.get("ident_state") or {}
    customer = find_customer_by_text(api, text, allow_bare_id=True)
    if not customer and state.get("tech_customer"):
        # follow-up about the customer talked about last ("ওর বিল কত?")
        customer = find_customer_by_text(api, state["tech_customer"], allow_bare_id=True)
    context = None
    if customer:
        db.execute("UPDATE wa_contacts SET ident_state = %s, updated_at = now() WHERE id = %s",
                   (json.dumps({"mode": "technician", "tech_customer": customer.get("CustomerId")}), contact["id"]))
        context = diagnose(api, customer)
        context["customer"]["mac"] = (api.live_status(customer["CustomerHeaderId"]) or {}).get("calledid")
        try:
            context["open_tickets"] = [
                {"no": x.get("ComplainId"), "problem": x.get("Problem"), "opened": x.get("CreatedOn"),
                 "assigned": x.get("SolvedBy")} for x in api.open_tickets_for(customer.get("UserName") or "")]
        except Exception:
            context["open_tickets"] = None
    history = _history(t, contact["id"])
    if not history or history[-1]["role"] != "user":
        history.append({"role": "user", "content": text})
    ctx = json.dumps(context, ensure_ascii=False) if context else "এই মেসেজে কোনো কাস্টমার চেনা যায়নি।"
    history[-1] = {"role": "user", "content": f"<live_data>\n{ctx}\n</live_data>\n\nটেকনিশিয়ানের মেসেজ:\n{history[-1]['content']}"}
    system = TECH_PROMPT.format(company=t.name, tech=tech["name"])
    draft, provider, model = generate_with_fallback(t, system, history)
    draft = _whatsapp_format(draft)
    if not draft:
        return
    note = None
    mm = re.search(r"\[\[TICKET:\s*(.*?)\]\]", draft, re.S)
    if mm:
        note = mm.group(1).strip()[:300]
        draft = (draft[:mm.start()] + draft[mm.end():]).strip()
        if customer:
            note, extra = open_ticket(t, customer, contact["wa_number"], note, requested_by=f"টেকনিশিয়ান {tech['name']}")
            if extra:
                draft += "\n" + extra
        else:
            note = None
    for marker, action in (("[[ENABLE]]", "enable"), ("[[DISABLE]]", "disable")):
        if marker in draft:
            draft = draft.replace(marker, "").strip()
            if customer:
                # the system line states the real outcome; drop the model's "...করা হচ্ছে" placeholder
                draft = "\n".join(l for l in draft.splitlines() if not re.search(r"লাইন\s*(চালু|বন্ধ)\s*করা\s*হচ্ছে", l)).strip()
                draft = (draft + "\n" if draft else "") + switch_line(t, tech, customer, text, action)
    deliver(t, contact, message_id, draft, note, context=context, provider=provider, model=f"{model} · technician")


def switch_line(t: Tenant, tech: dict, customer: dict, request: str, action: str) -> str:
    """Turn the customer's line on/off in the billing software, only for technicians the owner allowed.
    Every request (allowed or not) is logged in line_enables."""
    on = action == "enable"
    word = "চালু" if on else "বন্ধ"
    api = tenants.billing(t)
    fresh = find_customer_by_text(api, customer.get("CustomerId") or "", allow_bare_id=True) or customer
    due = fresh.get("BalanceDue")
    result, error = ("enabled" if on else "disabled"), None
    if not tech.get("can_switch_lines"):
        result, reply = "not_allowed", f"দুঃখিত, আপনার লাইন {word} করার অনুমতি নেই। অফিসে জানান।"
    elif bool(fresh.get("Disabled")) != on:
        result, reply = ("already_active" if on else "already_disabled"), f"লাইনটা বিলিংয়ে আগে থেকেই {word} আছে।"
    elif os.environ.get("ENGINE_DRY_RUN", "1") == "1":
        result, reply = "dry_run", f"(টেস্ট মোড: লাইন আসলে {word} করা হয়নি)"
    else:
        try:
            (api.enable_customer if on else api.disable_customer)(int(fresh["CustomerHeaderId"]))
            after = find_customer_by_text(api, fresh.get("CustomerId") or "", allow_bare_id=True)
            if after and bool(after.get("Disabled")) == on:
                result, error = "failed", f"billing still shows the line {'disabled' if on else 'enabled'}"
                reply = f"লাইন {word} করার চেষ্টা করেছি, কিন্তু বিলিংয়ে বদলায়নি। অফিসে জানান।"
            else:
                reply = f"✅ লাইন {word} করা হয়েছে।" + (f" বকেয়া: {due} টাকা" if on and due not in (None, '', 0, '0') else "")
        except Exception as e:
            log.exception("line %s failed", action)
            result, error = "failed", str(e)[:500]
            reply = f"লাইন {word} করা যায়নি, বিলিং সফটওয়্যারে সমস্যা হয়েছে। অফিসে জানান।"
    db.execute(
        """INSERT INTO line_enables (company_id, technician_id, technician_name, technician_number, customer_id,
               customer_header_id, customer_name, username, due, request, action, result, error, created_at)
           VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, now())""",
        (t.company_id, tech.get("id"), tech["name"], tech.get("wa_number"), fresh.get("CustomerId"),
         fresh.get("CustomerHeaderId"), fresh.get("CustomerName"), fresh.get("UserName"),
         None if due is None else str(due), request[:2000], action, result, error))
    return reply