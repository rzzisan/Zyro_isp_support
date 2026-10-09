"""Zyro Support engine — one WhatsApp webhook for every company.

The receiving business number (phone_number_id) decides the company; that company's billing
connection, AI keys and bot settings are used. ENGINE_DRY_RUN=1: nothing is sent to WhatsApp and
no billing tickets are opened (drafts are stored with mode 'dry_run').
"""
import dataclasses
import hashlib
import hmac
import json
import logging
import os
import re
import threading
import time
from datetime import datetime, timedelta, timezone

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import PlainTextResponse

from engine import customers, db, tenant as tenants, webpush
from engine.ispdigital import diagnose, find_customer_by_text, find_customer_by_whatsapp, normalize_bd_mobile
from engine.tenant import Tenant

VERIFY_TOKEN = os.environ.get("WA_VERIFY_TOKEN", "")
APP_SECRET = os.environ.get("META_APP_SECRET", "")
DRY_RUN = os.environ.get("ENGINE_DRY_RUN", "1") == "1"
# the old single-company bot keeps a copy of every webhook (it runs with bot_mode=off, so it only logs)
LEGACY_FORWARD_URL = os.environ.get("LEGACY_FORWARD_URL", "")
# the panel calls /internal/* (127.0.0.1 only; nginx exposes just /webhook) with this shared key
INTERNAL_KEY = os.environ.get("INTERNAL_API_KEY", "")
MEDIA_TYPES = ("audio", "image", "video", "document", "sticker")

log = logging.getLogger("zyro-engine")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
app = FastAPI(docs_url=None, redoc_url=None, openapi_url=None)


@app.on_event("startup")
def _push_keys() -> None:
    try:
        webpush.keys()  # the panel needs the public key before the first browser can subscribe
    except Exception:
        log.exception("could not load/create web push keys")


@app.get("/health")
def health():
    return {"ok": True, "dry_run": DRY_RUN}


@app.get("/webhook")
def verify(request: Request):
    q = request.query_params
    if q.get("hub.mode") == "subscribe" and VERIFY_TOKEN and hmac.compare_digest(q.get("hub.verify_token", ""), VERIFY_TOKEN):
        return PlainTextResponse(q.get("hub.challenge", ""))
    raise HTTPException(status_code=403)


def signature_ok(raw: bytes, header: str) -> bool:
    if not APP_SECRET or not header.startswith("sha256="):
        return False
    return hmac.compare_digest(header[7:], hmac.new(APP_SECRET.encode(), raw, hashlib.sha256).hexdigest())


# --- storage helpers ---------------------------------------------------------------------
def contact_for(t: Tenant, wa: str, name: str | None) -> dict:
    return db.execute(
        """INSERT INTO wa_contacts (company_id, wa_number, name, last_message_at, created_at, updated_at)
           VALUES (%s, %s, %s, now(), now(), now())
           ON CONFLICT (company_id, wa_number) DO UPDATE
             SET name = COALESCE(EXCLUDED.name, wa_contacts.name), last_message_at = now(), updated_at = now()
           RETURNING *""",
        (t.company_id, wa, name),
    )


def save_message(t: Tenant, contact_id: int, wa_message_id: str | None, direction: str, sender: str,
                 mtype: str, body: str | None, media: dict | None = None) -> dict | None:
    return db.execute(
        """INSERT INTO wa_messages (company_id, contact_id, wa_account_id, wa_message_id, direction, sender,
                                    type, body, media_id, media_mime, created_at)
           VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, now())
           ON CONFLICT (wa_message_id) DO NOTHING RETURNING id""",
        (t.company_id, contact_id, t.wa_account_id, wa_message_id, direction, sender, mtype, body,
         media.get("id") if media else None, media.get("mime_type") if media else None),
    )


def history_for(t: Tenant, contact_id: int, limit: int = 6) -> list[dict]:
    rows = db.all_rows(
        """SELECT direction, sender, body FROM wa_messages
           WHERE company_id = %s AND contact_id = %s AND type IN ('text', 'audio') AND body IS NOT NULL
           ORDER BY created_at DESC, id DESC LIMIT %s""",
        (t.company_id, contact_id, limit),
    )
    merged: list[dict] = []
    for r in reversed(rows):
        role = "user" if r["direction"] == "in" else "assistant"
        # a person from the office wrote this one (panel or the Business app), not the bot
        body = f"[স্টাফ] {r['body']}" if r["direction"] == "out" and r["sender"] in ("staff", "app") else r["body"]
        if merged and merged[-1]["role"] == role:
            merged[-1]["content"] += "\n" + body
        else:
            merged.append({"role": role, "content": body})
    while merged and merged[0]["role"] != "user":
        merged.pop(0)
    return merged


def save_draft(t: Tenant, contact_id: int, message_id: int | None, mode: str, draft: str | None,
               context=None, provider=None, model=None, error=None, ticket_note=None) -> None:
    db.execute(
        """INSERT INTO wa_drafts (company_id, contact_id, message_id, context, draft, mode, provider, model, error,
                                  ticket_note, created_at)
           VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, now())""",
        (t.company_id, contact_id, message_id, json.dumps(context, ensure_ascii=False) if context else None,
         draft, mode, provider, model, error, ticket_note),
    )


# --- webhook ---------------------------------------------------------------------------------
def forward_to_legacy(raw: bytes, signature: str) -> None:
    try:
        import httpx
        httpx.post(LEGACY_FORWARD_URL, content=raw, timeout=10,
                   headers={"x-hub-signature-256": signature, "content-type": "application/json"})
    except Exception as e:
        log.warning("legacy forward failed: %s", e)


@app.post("/webhook")
async def receive(request: Request):
    raw = await request.body()
    if not signature_ok(raw, request.headers.get("x-hub-signature-256", "")):
        raise HTTPException(status_code=403)
    if LEGACY_FORWARD_URL:
        threading.Thread(target=forward_to_legacy, args=(raw, request.headers["x-hub-signature-256"]), daemon=True).start()
    payload = json.loads(raw)
    jobs = []
    for entry in payload.get("entry", []):
        for change in entry.get("changes", []):
            value = change.get("value", {})
            pnid = value.get("metadata", {}).get("phone_number_id")
            t = tenants.by_phone_number_id(pnid) if pnid else None
            db.execute("INSERT INTO wa_events (company_id, payload, received_at) VALUES (%s, %s, now())",
                       (t.company_id if t else None, json.dumps({"field": change.get("field"), "value": value})))
            if not t:
                continue  # a number no company owns
            if change.get("field") == "messages":
                for s in value.get("statuses", []):
                    db.execute("UPDATE wa_messages SET status = %s WHERE wa_message_id = %s AND company_id = %s",
                               (s.get("status"), s.get("id"), t.company_id))
            if change.get("field") not in ("messages", "smb_message_echoes"):
                continue
            echo = change.get("field") == "smb_message_echoes"
            names = {c.get("wa_id"): c.get("profile", {}).get("name") for c in value.get("contacts", [])}
            for m in value.get("messages", []) + value.get("message_echoes", []):
                wa = m.get("to") if echo else m.get("from")
                c = contact_for(t, wa, names.get(wa))
                mtype = m.get("type")
                media = m.get(mtype) if mtype in MEDIA_TYPES else None
                body = (m.get("text") or {}).get("body") or (media or {}).get("caption") \
                    or ((m.get("button") or {}).get("text") if mtype == "button" else None)
                from engine.technician import technician_for
                sender = "app" if echo else ("technician" if technician_for(t, wa) else "customer")  # technicians are staff
                saved = save_message(t, c["id"], m.get("id"), "out" if echo else "in", sender,
                                     mtype, body, media)
                if not echo and saved:  # skip Meta retries
                    jobs.append((t, c, m, saved["id"]))
                    webpush.new_message(t.company_id, c, mtype, body, sender == "technician")
    for job in jobs:
        threading.Thread(target=handle_message, args=job, daemon=True).start()
    return {"ok": True}


# --- identification flow ----------------------------------------------------------------------
YES = ("হ্যাঁ", "হ্যা", "হা", "হুম", "জি", "জ্বি", "জী", "ঠিক", "yes", "ha", "hae", "hea", "hya", "hu", "hum", "hm", "ji",
       "je", "ok", "oke", "okay", "thik", "right", "correct", "y")
NO = ("না", "নাহ", "no", "na", "nah", "not", "noi", "nai", "n")
# a whole message made of these (or only emoji) just closes the talk
ACKS = {"ok", "oke", "okay", "thanks", "thank", "you", "thx", "tnx", "ধন্যবাদ", "ঠিক", "আছে", "ওকে", "আচ্ছা", "acha",
        "thik", "ase", "ache", "hm", "হুম", "ji", "জি", "জ্বি", "in", "sha", "allah", "ইনশাআল্লাহ", "alhamdulillah",
        "আলহামদুলিল্লাহ", "vai", "bhai", "ভাই", "ভাইয়া", "vaiya"}
ASK_ID = "আপনি লাইন নেওয়ার সময় যে ফোন নম্বর দিয়েছিলেন সেই নম্বরটা দিন, অথবা কাস্টমার ID জানা থাকলে বলুন।"
AGENT = "ঠিক আছে, শিগগিরই আমাদের একজন সাপোর্ট এজেন্ট আপনার সাথে যোগাযোগ করবেন।"
OK_DAYS = 7  # an identified chat stays identified this long; unfinished identification restarts after a day


def _words(text: str) -> list[str]:
    """Lowercase words; repeated latin letters squeezed ("Haaa" -> "ha", "okkk" -> "ok")."""
    w = re.sub(r"[^\wঀ-৿ ]", " ", (text or "").lower()).split()
    return [re.sub(r"([a-z])\1+", r"\1", x) for x in w]


def _yes_no(text: str) -> str | None:
    w = _words(text)
    if not w:
        return None
    if w[0] in NO or "না" in w[:3]:
        return "no"
    if w[0] in YES:
        return "yes"
    return None


def _is_ack(text: str) -> bool:
    w = _words(text)
    acks = set(_words(" ".join(ACKS)))
    return len(w) <= 5 and all(x in acks for x in w)


def _mobile_matches(customer: dict, wa: str) -> bool:
    m = normalize_bd_mobile(customer.get("MobileNumber") or "")
    return bool(m) and m == normalize_bd_mobile(wa)


def _names_match(customer: dict, text: str) -> bool:
    """Someone writing from another number proves the line is theirs by writing the account name."""
    common = set(_words("mohammad mohammed muhammad mohammod abdul abdur hossain hosen islam uddin ahmed begum khatun "
                        "akter akhter miah mia sheikh shaikh মোহাম্মদ মোঃ"))
    said = {x for x in _words(text) if len(x) >= 3}
    name = [x for x in _words(customer.get("CustomerName") or "") if len(x) >= 3 and x not in common]
    return bool(name) and any(x in said for x in name)


def identify(t: Tenant, contact: dict, text: str):
    """Returns (customer, fixed_reply, pending_question, agent_note, verified).

    verified = the chat is from the line's registered number (or the sender wrote the account name);
    only then the bot may talk about the bill, payments or the name.
    """
    api = tenants.billing(t)
    state = contact.get("ident_state") or {}
    if state.get("at"):
        keep = timedelta(days=OK_DAYS) if state.get("stage") == "ok" else timedelta(hours=24)
        if datetime.fromisoformat(state["at"]) < datetime.now(timezone.utc) - keep:
            state = {}
    stage = state.get("stage", "new")

    def save(**kw):
        db.execute("UPDATE wa_contacts SET ident_state = %s, updated_at = now() WHERE id = %s",
                   (json.dumps({**kw, "at": datetime.now(timezone.utc).isoformat()}, ensure_ascii=False), contact["id"]))

    def ok(customer, pending=None, verified=None):
        same = state.get("stage") == "ok" and state.get("customer_id") == customer.get("CustomerId")
        if verified is None:
            verified = _mobile_matches(customer, contact["wa_number"]) or (same and state.get("verified", True)) \
                or _names_match(customer, text)
        save(stage="ok", customer_id=customer.get("CustomerId"), verified=bool(verified))
        db.execute("UPDATE wa_contacts SET customer_id = %s WHERE id = %s", (customer.get("CustomerId"), contact["id"]))
        return customer, None, pending, None, bool(verified)

    typed = customers.by_text(t, api, text, allow_bare_id=stage in ("ask_id", "confirm"))
    if typed:
        return ok(typed, state.get("pending"))
    if stage == "ok" and state.get("customer_id"):
        # an already identified chat: retry once so a billing hiccup doesn't restart identification
        c = customers.by_text(t, api, state["customer_id"], allow_bare_id=True) \
            or find_customer_by_text(tenants.billing(t), state["customer_id"], allow_bare_id=True)
        if c:
            return ok(c)
    if stage == "confirm":
        # the number is the line's registered number; anything but a clear "no" (a "Haaa", or the problem
        # itself) means yes, so the customer is not asked the same question again
        if _yes_no(text) != "no":
            c = customers.by_text(t, api, state.get("candidate", ""))
            if c:
                pending = "\n".join(x for x in (state.get("pending"), None if _yes_no(text) else text) if x) or None
                return ok(c, pending, verified=True)
        save(stage="ask_id", tries=1, pending=state.get("pending"))
        return None, ASK_ID, None, None, False
    if stage == "ask_id":
        tries = state.get("tries", 1)
        if tries >= 3:
            save(stage="agent", pending=state.get("pending"))
            return None, AGENT, None, "কাস্টমার শনাক্ত করা যায়নি, এজেন্ট যোগাযোগ করবেন", False
        save(stage="ask_id", tries=tries + 1, pending=state.get("pending"))
        if re.search(r"\d{2,}", text):
            return None, "দুঃখিত, এই নম্বর/ID দিয়ে কোনো লাইন খুঁজে পাইনি। " + ASK_ID, None, None, False
        return None, "জি, আপনার লাইনটা খুঁজে বের করতে কাস্টমার ID বা লাইনের মোবাইল নম্বরটা লিখে দিন।", None, None, False
    if stage == "agent":
        return None, None, None, None, False
    own = customers.by_whatsapp(t, api, contact["wa_number"])
    if own:
        save(stage="confirm", candidate=own.get("CustomerId"), candidate_name=own.get("CustomerName"), pending=text)
        return None, (f"আসসালামু আলাইকুম। আপনি কি *{own.get('CustomerName')}* (ID {own.get('CustomerId')}) "
                      "লাইনের বিষয়ে কথা বলছেন?"), None, None, False
    save(stage="ask_id", tries=1, pending=text)
    return None, "আসসালামু আলাইকুম। " + ASK_ID, None, None, False


# the customer talks about a payment: then the bot gets the last 6 payments, otherwise only the latest one
PAYMENT_WORDS = re.compile(r"পেমেন্ট|পরিশোধ|জমা|দিয়েছি|দিছি|দিলাম|দিসি|দিয়েছিলাম|বিকাশ|নগদ|রকেট|টাকা|মাস|রিসিট|"
                           r"pay|paid|bkash|bikash|nagad|rocket|diyechi|disi|dilam|joma|taka|month|history|receipt", re.I)


def customer_view(context: dict, verified: bool, text: str = "") -> dict:
    """What the customer-facing bot may see: never mobile numbers, IPs or MACs; nothing about the bill,
    payments or the name unless the chat is verified."""
    ctx = json.loads(json.dumps(context, ensure_ascii=False, default=str))
    c = ctx.get("customer") or {}
    c.pop("registered_mobile", None)
    (ctx.get("pppoe") or {}).pop("ip", None)
    for k in ("address", "caller_id"):
        (ctx.get("mikrotik") or {}).pop(k, None)
    if not verified:
        ctx.pop("bill", None)
        ctx.pop("payments", None)
        for k in ("name", "username", "package", "zone", "bill_day"):
            c.pop(k, None)
    if ctx.get("payments") and not PAYMENT_WORDS.search(text or ""):
        ctx["payments"] = ctx["payments"][:1]
    ctx["verified"] = verified
    ctx["today"] = (datetime.now(timezone.utc) + timedelta(hours=6)).strftime("%Y-%m-%d")  # Asia/Dhaka
    return ctx


# --- one reply per burst of messages ------------------------------------------------------------
BURST_WAIT = 7  # seconds to wait for the rest of a burst ("হায়" / "নেট নাই" / "চালান যায় না")
_contact_locks: dict[int, threading.Lock] = {}


def newer_inbound(t: Tenant, contact_id: int, message_id: int) -> bool:
    return bool(db.one(
        """SELECT 1 FROM wa_messages WHERE company_id = %s AND contact_id = %s AND direction = 'in' AND id > %s
             AND (type IN ('text', 'audio', 'button') OR body IS NOT NULL) LIMIT 1""",
        (t.company_id, contact_id, message_id)))


def unanswered_text(t: Tenant, contact_id: int) -> str:
    """The customer's messages since our last message (at most 10 minutes back), as one text."""
    rows = db.all_rows(
        """SELECT body FROM wa_messages WHERE company_id = %s AND contact_id = %s AND direction = 'in' AND body IS NOT NULL
             AND created_at > now() - interval '10 minutes'
             AND id > COALESCE((SELECT max(id) FROM wa_messages WHERE company_id = %s AND contact_id = %s
                                AND direction = 'out'), 0)
           ORDER BY id DESC LIMIT 5""",
        (t.company_id, contact_id, t.company_id, contact_id))
    return "\n".join(re.sub(r"^\[ভয়েস\]\s*", "", r["body"]) for r in reversed(rows) if r["body"].strip())


def ack_already_answered(t: Tenant, contact_id: int) -> bool:
    """A second "ok" in a row: our last message already answered an "ok", so the talk is over."""
    rows = db.all_rows(
        """SELECT direction, sender, body FROM wa_messages WHERE company_id = %s AND contact_id = %s
           ORDER BY id DESC LIMIT 6""", (t.company_id, contact_id))
    last_out = next((i for i, r in enumerate(rows) if r["direction"] == "out"), None)
    if last_out is None or rows[last_out]["sender"] != "bot":
        return False
    before = next((r for r in rows[last_out + 1:] if r["direction"] == "in"), None)
    return bool(before and before["body"] is not None and _is_ack(before["body"]))


def said_recently(t: Tenant, contact_id: int, text: str, hours: int = 12) -> bool:
    return bool(db.one(
        """SELECT 1 FROM wa_messages WHERE company_id = %s AND contact_id = %s AND direction = 'out'
             AND created_at > now() - make_interval(hours => %s) AND body LIKE %s LIMIT 1""",
        (t.company_id, contact_id, hours, f"%{text}%")))


# --- rules ------------------------------------------------------------------------------------
def bot_paused(contact: dict) -> bool:
    row = db.one("SELECT bot_paused OR COALESCE(bot_paused_until > now(), false) AS paused FROM wa_contacts WHERE id = %s",
                 (contact["id"],))
    return bool(row and row["paused"])


def may_send(t: Tenant, wa: str) -> bool:
    if DRY_RUN or (t.bot.get("bot_mode") or "shadow") != "live":
        return False
    allow = [n.strip() for n in (t.bot.get("live_allowlist") or "").replace("\n", ",").split(",") if n.strip()]
    if allow and normalize_bd_mobile(wa) not in {normalize_bd_mobile(n) for n in allow}:
        return False
    if t.max_bot_replies and tenants.bot_replies_this_month(t) >= t.max_bot_replies:
        log.warning("company %s reached its monthly bot reply limit", t.company_id)
        return False
    return True


def deliver(t: Tenant, contact: dict, message_id: int, text: str, note: str | None = None, **draft_kw) -> None:
    sig = t.bot.get("reply_signature")
    body = text.rstrip() + (f"\n\n{sig}" if sig and not text.rstrip().endswith(sig) else "")
    mode = "dry_run" if DRY_RUN else (t.bot.get("bot_mode") or "shadow")
    error = None
    if may_send(t, contact["wa_number"]):
        try:
            from engine import whatsapp
            res = whatsapp.send_text(t, contact["wa_number"], body)
            save_message(t, contact["id"], res.get("messages", [{}])[0].get("id"), "out", "bot", "text", body)
            mode = "sent"
        except Exception as e:
            error = str(e)[:500]
    save_draft(t, contact["id"], message_id, mode, body, ticket_note=note, error=error, **draft_kw)
    return "failed" if error else mode


def open_ticket(t: Tenant, customer: dict, wa: str, note: str, requested_by: str | None = None) -> tuple[str, str | None]:
    if DRY_RUN or not t.bot.get("auto_ticket", True):
        return f"{note} → (টিকেট খোলা হয়নি: {'dry run' if DRY_RUN else 'বন্ধ'})", None
    api = tenants.billing(t)
    category, _, detail = note.partition("|")
    category, detail = category.strip(), (detail.strip() or category.strip())
    try:
        existing = api.open_tickets_for(customer.get("UserName") or "")
        if existing:
            no = existing[0].get("ComplainId")
            return f"{note} → আগেই খোলা টিকেট আছে (#{no})", f"আপনার আগের অভিযোগটা (নম্বর {no}) এখনো খোলা আছে, টিম সেটা দেখছে।"
        _, cats = api.ticket_form()
        cid = cats.get(category) or next((v for k, v in cats.items() if category and category.lower() in k.lower()), None) \
            or cats.get("Others Support")
        mobile = normalize_bd_mobile(customer.get("MobileNumber") or "") or normalize_bd_mobile(wa)
        api.create_ticket(customer["CustomerHeaderId"], cid, 2, mobile, (f"[WhatsApp: {requested_by}] {detail}\nWhatsApp: {wa}" if requested_by else f"[WhatsApp বট] {detail}\nকাস্টমার WhatsApp: {wa}"))
        created = api.open_tickets_for(customer.get("UserName") or "")
        no = created[0].get("ComplainId") if created else None
        return f"{note} → টিকেট খোলা হয়েছে #{no or '?'}", (f"আপনার অভিযোগ নম্বর: {no}" if no else None)
    except Exception as e:
        log.exception("ticket failed")
        return f"{note} → টিকেট খোলা যায়নি: {str(e)[:200]}", None


def handle_message(t: Tenant, contact: dict, m: dict, message_id: int) -> None:
    from engine.agent import draft_reply, transcribe

    if not t.active or (t.bot.get("bot_mode") or "shadow") == "off" or bot_paused(contact):
        return
    mtype = m.get("type")
    if mtype == "button":
        m, mtype = {**m, "type": "text", "text": {"body": (m.get("button") or {}).get("text", "")}}, "text"
    if mtype in ("image", "video", "document") and not ((m.get(mtype) or {}).get("caption")):
        deliver(t, contact, message_id, "জি, পেয়েছি। আমাদের টিম দেখে নেবে। সমস্যাটা একটু লিখে জানালে দ্রুত সাহায্য করতে পারব।",
                f"কাস্টমার {mtype} পাঠিয়েছে, টিম দেখবে", provider="flow", model="fixed")
        return
    if mtype in ("image", "video", "document"):
        m = {**m, "type": "text", "text": {"body": m[mtype]["caption"]}}
    if mtype == "audio":
        try:
            from engine import whatsapp
            data, mime = whatsapp.download_media(t, (m.get("audio") or {}).get("id", ""))
            spoken = transcribe(t, data, mime, contact["id"])
        except Exception:
            log.exception("voice transcription failed")
            spoken = None
        if not spoken or len(spoken) < 2:
            deliver(t, contact, message_id, "জি, ভয়েসটা ঠিক বুঝতে পারিনি। একটু লিখে জানাবেন?", provider="flow", model="fixed")
            return
        db.execute("UPDATE wa_messages SET body = %s WHERE id = %s", (f"[ভয়েস] {spoken}", message_id))
        m = {**m, "type": "text", "text": {"body": spoken}}
    elif mtype != "text":
        return

    text = (m.get("text") or {}).get("body", "")
    # several messages in a row get one answer: wait a moment, and leave it to the newest one
    time.sleep(BURST_WAIT)
    if newer_inbound(t, contact["id"], message_id):
        return
    with _contact_locks.setdefault(contact["id"], threading.Lock()):
        contact = db.one("SELECT * FROM wa_contacts WHERE id = %s", (contact["id"],)) or contact
        if bot_paused(contact):  # staff took over while we waited
            return
        text = unanswered_text(t, contact["id"]) or text
        _answer(t, contact, text, message_id)


def _answer(t: Tenant, contact: dict, text: str, message_id: int) -> None:
    from engine.agent import draft_reply
    from engine.technician import handle_tech, technician_for
    tech = technician_for(t, contact["wa_number"])
    if tech:
        try:
            handle_tech(t, contact, tech, text, message_id, deliver, open_ticket)
        except Exception as e:
            log.exception("technician reply failed for company %s", t.company_id)
            save_draft(t, contact["id"], message_id, "error", None, error=str(e)[:500])
        return
    context = None
    try:
        customer, fixed, pending, note, verified = identify(t, contact, text)
        if not customer and not fixed:
            return
        if fixed:
            if note:
                db.execute("UPDATE wa_contacts SET bot_paused = true WHERE id = %s", (contact["id"],))
            deliver(t, contact, message_id, fixed, note, provider="flow", model="identify")
            return
        if not pending and _is_ack(text) and ack_already_answered(t, contact["id"]):
            return  # "ওকে" after our "ঠিক আছে, জানাবেন": nothing more to say
        api = tenants.billing(t)
        context = customer_view(customers.add_online(t, diagnose(api, customers.fresh(api, customer)), customer), verified,
                                "\n".join(x for x in (pending, text) if x))
        history = [{"role": "user", "content": pending + "\n" + text}] if pending else history_for(t, contact["id"])
        if not history or history[-1]["role"] != "user":
            history.append({"role": "user", "content": text})
        draft, provider, model = draft_reply(t, history, context, contact["id"])
        ticket_note = None
        if draft:
            mm = re.search(r"\[\[TICKET:\s*(.*?)\]\]", draft, re.S)
            if mm:
                ticket_note = mm.group(1).strip()[:300]
                draft = (draft[:mm.start()] + draft[mm.end():]).strip()
        if draft and ticket_note and may_send(t, contact["wa_number"]):
            ticket_note, extra = open_ticket(t, customer, contact["wa_number"], ticket_note)
            no = re.search(r"\d{4,}", extra or "")
            if extra and not (no and said_recently(t, contact["id"], no.group(0))):  # the ticket number once, not every reply
                draft += "\n" + extra
        if draft and newer_inbound(t, contact["id"], message_id):
            # the customer wrote again while we were answering: that message's turn answers all of them
            # (otherwise two replies go out for one question)
            save_draft(t, contact["id"], message_id, "superseded", draft, context=context, provider=provider, model=model)
            return
        if draft:
            deliver(t, contact, message_id, draft, ticket_note, context=context, provider=provider, model=model)
    except Exception as e:
        log.exception("engine failed for company %s", t.company_id)
        save_draft(t, contact["id"], message_id, "error", None, context=context, error=str(e)[:500])


# --- internal API for the panel (billing actions) -------------------------------------------
def internal_tenant(request: Request, company_id: int) -> Tenant:
    key = request.headers.get("x-internal-key", "")
    if not INTERNAL_KEY or not hmac.compare_digest(key, INTERNAL_KEY):
        raise HTTPException(status_code=403)
    row = db.one("SELECT phone_number_id FROM wa_accounts WHERE company_id = %s ORDER BY id LIMIT 1", (company_id,))
    t = tenants.by_phone_number_id(row["phone_number_id"]) if row else None
    if not t or not t.billing:
        raise HTTPException(status_code=400, detail="এই কোম্পানির বিলিং সংযোগ নেই")
    return t


def refresh_tickets(t: Tenant) -> None:
    from engine.ticket_sync import sync_company
    try:
        sync_company(t, 1)
    except Exception:
        log.exception("ticket refresh failed")


def billing_call(fn):
    try:
        return fn()
    except HTTPException:
        raise
    except Exception as e:
        log.exception("billing action failed")
        raise HTTPException(status_code=400, detail=str(e)[:300])


@app.get("/internal/{company_id}/ticket-options")
def ticket_options(request: Request, company_id: int):
    t = internal_tenant(request, company_id)
    return billing_call(lambda: tenants.billing(t).support_options())


@app.get("/internal/{company_id}/bot-prompts")
def bot_prompts(request: Request, company_id: int):
    """Bot settings page: the full instructions the bots get now (built-in rules + company instructions + FAQ)."""
    key = request.headers.get("x-internal-key", "")
    if not INTERNAL_KEY or not hmac.compare_digest(key, INTERNAL_KEY):
        raise HTTPException(status_code=403)
    row = db.one("SELECT phone_number_id FROM wa_accounts WHERE company_id = %s ORDER BY id LIMIT 1", (company_id,))
    t = tenants.by_phone_number_id(row["phone_number_id"]) if row else None
    if not t:
        raise HTTPException(status_code=404, detail="এই কোম্পানির WhatsApp নম্বর যুক্ত নেই")
    from engine.agent import system_prompt
    from engine.technician import tech_prompt
    bot = db.one("SELECT * FROM bot_settings WHERE company_id = %s", (company_id,)) or {}
    t = dataclasses.replace(t, bot=dict(bot))  # the saved settings, not the cached copy
    from engine.llm import SYSTEM_PROMPT
    from engine.technician import TECH_PROMPT
    return {"customer": system_prompt(t), "technician": tech_prompt(t, "(টেকনিশিয়ানের নাম)"),
            # the built-in rules, for the edit boxes and "back to default"
            "customer_default": SYSTEM_PROMPT, "technician_default": TECH_PROMPT}


@app.get("/internal/{company_id}/customers")
def customer_search(request: Request, company_id: int, q: str = ""):
    t = internal_tenant(request, company_id)
    if len(q.strip()) < 2:
        return []
    rows = customers.search(t, q, 10) or billing_call(lambda: tenants.billing(t).search_customers(q.strip(), 10))
    return [{"header_id": r.get("CustomerHeaderId"), "customer_id": r.get("CustomerId"), "name": r.get("CustomerName"),
             "mobile": r.get("MobileNumber"), "username": r.get("UserName"),
             "zone": r.get("ZoneName") or r.get("Zone")} for r in rows]


@app.post("/internal/{company_id}/tickets")
async def new_ticket(request: Request, company_id: int):
    t = internal_tenant(request, company_id)
    b = await request.json()
    api = billing_call(lambda: tenants.billing_for_user(t, int(b.get("user_id") or 0)))

    def run():
        msg = api.create_ticket(int(b["header_id"]), str(b["category_id"]), int(b.get("priority") or 2),
                                normalize_bd_mobile(b.get("mobile") or "") or (b.get("mobile") or ""),
                                b.get("comment") or "", send_sms=bool(b.get("sms_client")))
        created = api.open_tickets_for(b.get("username") or "") if b.get("username") else []
        complain_id = created[0].get("ComplainId") if created else None
        if complain_id and b.get("employees"):
            api.assign_ticket(int(complain_id), [int(e) for e in b["employees"]], b.get("dept_id"), bool(b.get("sms_employees")))
        return {"message": msg, "complain_id": complain_id}

    res = billing_call(run)
    refresh_tickets(t)
    return res


@app.post("/internal/{company_id}/tickets/{complain_id}/assign")
async def assign(request: Request, company_id: int, complain_id: int):
    t = internal_tenant(request, company_id)
    b = await request.json()
    if not b.get("employees"):
        raise HTTPException(status_code=400, detail="অন্তত একজন কর্মী বাছাই করুন")
    api = billing_call(lambda: tenants.billing_for_user(t, int(b.get("user_id") or 0)))
    billing_call(lambda: api.assign_ticket(complain_id, [int(e) for e in b["employees"]],
                                                          b.get("dept_id"), bool(b.get("sms_employees"))))
    refresh_tickets(t)
    return {"ok": True}


@app.post("/internal/{company_id}/tickets/{complain_id}/solve")
async def solve(request: Request, company_id: int, complain_id: int):
    """Desk "Solve": only when the customer's PPPoE is online on our MikroTik right now."""
    t = internal_tenant(request, company_id)
    b = await request.json()
    row = db.one("""SELECT bt.username, bt.state, bc.server FROM billing_tickets bt
                    LEFT JOIN billing_customers bc ON bc.company_id = bt.company_id AND bc.header_id = bt.customer_header_id
                    WHERE bt.company_id = %s AND bt.complain_id = %s""", (company_id, str(complain_id)))
    if not row:
        raise HTTPException(status_code=404, detail="টিকিট পাওয়া যায়নি")
    if row["state"] not in ("pending", "processing"):
        raise HTTPException(status_code=400, detail="টিকিটটা আগেই সমাধান হয়েছে")
    from engine.ppp_sync import online_now
    mk = online_now(company_id, row["username"] or "", row["server"])
    if mk is None:
        raise HTTPException(status_code=400, detail="MikroTik থেকে উত্তর আসেনি, তাই অনলাইন কিনা যাচাই করা গেল না")
    if not mk.get("online"):
        raise HTTPException(status_code=400, detail="কাস্টমার MikroTik-এ অফলাইন, লাইন চালু হলে Solve করুন")
    api = billing_call(lambda: tenants.billing_for_user(t, int(b.get("user_id") or 0)))
    billing_call(lambda: api.solve_ticket(complain_id, (b.get("remark") or "")[:500]))
    refresh_tickets(t)
    return {"ok": True, "uptime": mk.get("uptime")}


@app.get("/internal/{company_id}/tickets/{complain_id}/solvers")
def solvers(request: Request, company_id: int, complain_id: int):
    t = internal_tenant(request, company_id)
    return billing_call(lambda: tenants.billing(t).ticket_solvers(complain_id))


@app.post("/internal/{company_id}/tickets/sync")
def sync_now(request: Request, company_id: int):
    t = internal_tenant(request, company_id)
    from engine.ticket_sync import sync_company
    return billing_call(lambda: sync_company(t, 3))


@app.post("/internal/{company_id}/customers/sync")
def customers_sync_now(request: Request, company_id: int):
    t = internal_tenant(request, company_id)
    from engine.customer_sync import sync_company
    return billing_call(lambda: sync_company(t))


_customer_refresh = threading.Lock()


@app.post("/internal/{company_id}/customers/refresh")
def customers_refresh(request: Request, company_id: int, max_age: int = 600):
    """Monitoring page opened: start a background customer sync unless one ran (or is running) in the last max_age seconds."""
    t = internal_tenant(request, company_id)
    if not _customer_refresh.acquire(blocking=False):
        return {"started": False, "running": True}
    try:
        recent = db.one("""SELECT 1 FROM billing_customer_syncs WHERE company_id = %s AND (
                             (finished_at IS NULL AND started_at > now() - interval '5 minutes')
                             OR (error IS NULL AND finished_at > now() - make_interval(secs => %s))) LIMIT 1""",
                        (company_id, max_age))
    except Exception:
        _customer_refresh.release()
        raise
    if recent:
        _customer_refresh.release()
        return {"started": False, "running": False}

    def run():
        from engine.customer_sync import sync_company
        try:
            log.info("customer refresh company %s: %s", company_id, sync_company(t))
        except Exception:
            log.exception("customer refresh failed for company %s", company_id)
        finally:
            _customer_refresh.release()

    threading.Thread(target=run, daemon=True).start()
    return {"started": True, "running": True}


@app.get("/internal/{company_id}/customers/{header_id}/live")
def customer_live(request: Request, company_id: int, header_id: int):
    """Live state for the panel's customer page: connection, ONU, this month's bill, last payments."""
    t = internal_tenant(request, company_id)
    row = db.one("SELECT extra FROM billing_customers WHERE company_id = %s AND header_id = %s", (company_id, header_id))
    if not row or not row["extra"]:
        raise HTTPException(status_code=404, detail="কাস্টমার পাওয়া যায়নি")
    api = tenants.billing(t)
    return billing_call(lambda: customers.add_online(t, diagnose(api, customers.fresh(api, row["extra"])), row["extra"]))


def _norm(name: str | None) -> str:
    return "".join(ch for ch in (name or "").lower() if ch.isalnum())


@app.post("/internal/{company_id}/mikrotik/{router_id}/test")
def mikrotik_test(request: Request, company_id: int, router_id: int):
    """Connect to the router, read its identity, and match it to the billing software's server name."""
    internal_tenant(request, company_id)
    from engine import mikrotik
    r = db.one("SELECT * FROM mikrotik_routers WHERE id = %s AND company_id = %s", (router_id, company_id))
    if not r:
        raise HTTPException(status_code=404)
    try:
        info = mikrotik.check(r["host"], r["api_port"], r["username"], db.decrypt(r["password"]))
    except Exception as e:
        msg = str(e)[:250] or e.__class__.__name__
        db.execute("""UPDATE mikrotik_routers SET last_checked_at = now(), last_check_ok = false, last_check_message = %s,
                      updated_at = now() WHERE id = %s""", (msg, router_id))
        raise HTTPException(status_code=400, detail=f"MikroTik-এ সংযোগ হয়নি: {msg}")
    # only PPPoE users that exist in the billing software count
    info["ppp_active"] = db.one("""SELECT count(*) AS n FROM billing_customers WHERE company_id = %s AND gone_at IS NULL AND NOT is_left
                                   AND username = ANY(%s)""", (company_id, info.pop("names")))["n"]
    servers = [x["server"] for x in db.all_rows(
        "SELECT DISTINCT server FROM billing_customers WHERE company_id = %s AND server IS NOT NULL", (company_id,))]
    match = r["billing_server"] or next((s for s in servers if _norm(s) == _norm(info["identity"])), None)
    db.execute("""UPDATE mikrotik_routers SET identity = %s, version = %s, ppp_active = %s, billing_server = %s,
                  last_checked_at = now(), last_check_ok = true, last_check_message = %s, updated_at = now() WHERE id = %s""",
               (info["identity"], info["version"], info["ppp_active"], match, f"{info.get('board') or ''} · {info['ppp_active']} PPPoE অনলাইন", router_id))
    return {**info, "billing_server": match}


@app.get("/internal/{company_id}/monitor/{header_id}/{what}")
def monitor(request: Request, company_id: int, header_id: int, what: str):
    """Client monitoring actions for the panel (read-only router commands): recheck | traffic | ping."""
    internal_tenant(request, company_id)
    from engine import ppp_sync
    row = db.one("SELECT username, server FROM billing_customers WHERE company_id = %s AND header_id = %s", (company_id, header_id))
    if not row:
        raise HTTPException(status_code=404, detail="কাস্টমার পাওয়া যায়নি")
    try:
        if what == "recheck":
            res = ppp_sync.online_now(company_id, row["username"], row["server"])
            if res and res.get("online"):
                db.execute("""INSERT INTO ppp_sessions (company_id, router_id, username, address, caller_id, uptime, seen_at)
                              SELECT %s, id, %s, %s, %s, %s, now() FROM mikrotik_routers WHERE company_id = %s AND identity = %s LIMIT 1
                              ON CONFLICT (company_id, username) DO UPDATE SET address = EXCLUDED.address, caller_id = EXCLUDED.caller_id,
                                uptime = EXCLUDED.uptime, seen_at = now()""",
                           (company_id, row["username"], res.get("address"), res.get("caller_id"), res.get("uptime"), company_id, res.get("router")))
            return res or {"online": None}
        if what == "traffic":
            return ppp_sync.traffic(company_id, row["username"], row["server"]) or {}
        if what == "ping":
            ip = db.one("SELECT address FROM ppp_sessions WHERE company_id = %s AND username = %s", (company_id, row["username"]))
            if not ip or not ip["address"]:
                raise HTTPException(status_code=400, detail="কাস্টমার এখন অনলাইন নেই, পিং করার IP নেই")
            return ppp_sync.ping(company_id, ip["address"], row["server"]) or {}
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"রাউটার থেকে উত্তর আসেনি: {str(e)[:200]}")
    raise HTTPException(status_code=404)


@app.post("/internal/{company_id}/ppp/sync")
def ppp_sync_now(request: Request, company_id: int):
    """Monitoring page "Sync": read every router's online PPPoE list now."""
    internal_tenant(request, company_id)
    from engine.ppp_sync import sync_router
    out = []
    for r in db.all_rows("SELECT * FROM mikrotik_routers WHERE company_id = %s AND enabled ORDER BY id", (company_id,)):
        try:
            out.append({"router": r["identity"] or r["host"], "online": sync_router(r), "ok": True})
        except Exception as e:
            db.execute("""UPDATE mikrotik_routers SET last_checked_at = now(), last_check_ok = false, last_check_message = %s,
                          updated_at = now() WHERE id = %s""", (str(e)[:250], r["id"]))
            out.append({"router": r["identity"] or r["host"], "ok": False, "error": str(e)[:200]})
    return out


@app.get("/internal/{company_id}/customers/{header_id}/ticket-info")
def ticket_info(request: Request, company_id: int, header_id: int):
    """New-ticket form: customer and bill from our copy, connection from our MikroTik, OLT/ONU from billing."""
    t = internal_tenant(request, company_id)
    row = db.one("""SELECT customer_id, username, name, mobile, address, zone, subzone, box, package, monthly_bill, due, paid,
                           status, disabled, last_payment_date, server, extra
                    FROM billing_customers WHERE company_id = %s AND header_id = %s""", (company_id, header_id))
    if not row:
        raise HTTPException(status_code=404, detail="কাস্টমার পাওয়া যায়নি")
    from engine.ppp_sync import online_now
    try:
        mk = online_now(company_id, row["username"] or "", row["server"])
    except Exception:
        mk = None
    last = db.one("SELECT caller_id, address, uptime, seen_at FROM ppp_sessions WHERE company_id = %s AND username = %s",
                  (company_id, row["username"]))
    mac = (mk or {}).get("caller_id") or (last or {}).get("caller_id")
    if not mac:
        try:  # offline and never seen by us: billing still knows the last router MAC
            mac = tenants.billing(t).live_status(header_id).get("calledid") or None
        except Exception:
            log.exception("billing live status failed")
    onu = None
    if mac:
        from engine.olt_sync import onu_for_mac
        try:
            onu = onu_for_mac(company_id, mac)  # our own OLT, read now
        except Exception:
            log.exception("own OLT read failed")
        if not onu:
            try:
                onu = tenants.billing(t).onu_info(mac)
            except Exception:
                log.exception("onu info failed")
    extra = row.pop("extra") or {}
    return {
        "customer": {k: (str(v) if v is not None and not isinstance(v, (bool, int, float, str)) else v) for k, v in row.items()},
        "payment_status": extra.get("PaymentStatus"),
        "mikrotik": mk,
        "last_seen": {"caller_id": (last or {}).get("caller_id"), "address": (last or {}).get("address"),
                      "seen_at": str(last["seen_at"]) if last and last["seen_at"] else None},
        "mac": mac,
        "onu": onu,
    }


@app.post("/internal/{company_id}/olt/{olt_id}/test")
def olt_test(request: Request, company_id: int, olt_id: int):
    """OLT form "test": read its name/model over SNMP (read-only)."""
    internal_tenant(request, company_id)
    from engine import olt_sync
    o = db.one("SELECT * FROM olts WHERE id = %s AND company_id = %s", (olt_id, company_id))
    if not o:
        raise HTTPException(status_code=404)
    try:
        info = olt_sync.check(o)
    except Exception as e:
        db.execute("UPDATE olts SET last_poll_ok = false, last_poll_message = %s, updated_at = now() WHERE id = %s",
                   (str(e)[:250], olt_id))
        raise HTTPException(status_code=400, detail=f"OLT-এ SNMP সংযোগ হয়নি: {str(e)[:200]}")
    db.execute("UPDATE olts SET sys_name = %s, sys_descr = %s, updated_at = now() WHERE id = %s",
               (info["sys_name"], info["sys_descr"], olt_id))
    return {**info, "supported": o["brand"] in olt_sync.DRIVERS}
