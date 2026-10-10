"""Tell a technician on WhatsApp when a ticket is assigned to their billing employee (Desk → টেকনিশিয়ান → বিলিং কর্মী).

ticket_sync calls notify() with the tickets whose staff changed in this sync, so an assignment made in the desk or
straight in the billing software both arrive here. One message per ticket and technician (ticket_notifications).
Inside the 24-hour window (the technician wrote to us lately) it is a normal text; otherwise the approved utility
template TEMPLATE, which this module submits to Meta itself the first time it is needed.
"""
import logging
import os
import re
import time

import httpx

from engine import db
from engine.tenant import Tenant
from engine.whatsapp import GRAPH, _check, send_text

log = logging.getLogger("zyro-ticket-notify")
DRY_RUN = os.environ.get("ENGINE_DRY_RUN", "1") == "1"

TEMPLATE = "ticket_assigned"
TEMPLATE_LANG = "bn"
TEMPLATE_BODY = ("আপনার নামে একটি নতুন সাপোর্ট টিকিট দেওয়া হয়েছে।\n"
                 "টিকিট নং: {{1}}\nকাস্টমার: {{2}}\nসমস্যা: {{3}}\nঠিকানা: {{4}}\nযোগাযোগ: {{5}}\n"
                 "বিস্তারিত জানতে এই নম্বরে কাস্টমার ID লিখে মেসেজ দিন।")
TEMPLATE_EXAMPLE = ["57301", "Rajib (ID 0071, pp.rajib)", "Line Off - সকাল থেকে লাইন নেই",
                    "Binodpur, Box 12", "01711000111"]
_status: dict[int, tuple[float, str | None]] = {}  # company -> (checked at, template status)


def linked_technicians(company_id: int) -> list[dict]:
    return db.all_rows("""SELECT id, name, wa_number, billing_employee_id FROM technicians
                          WHERE company_id = %s AND active AND notify_tickets AND billing_employee_id IS NOT NULL
                            AND billing_employee_id <> ''""", (company_id,))


def _one_line(s, limit: int = 200) -> str:
    """Template parameters may not hold new lines, tabs or runs of spaces."""
    s = re.sub(r"\s+", " ", str(s or "")).strip()
    return (s[:limit - 1] + "…") if len(s) > limit else (s or "-")


def ticket_facts(company_id: int, complain_id: str) -> dict:
    """The ticket with its customer's address from our copy of billing."""
    return db.one(
        """SELECT bt.complain_id, bt.customer_id, bt.customer_name, bt.username, bt.mobile, bt.zone, bt.subzone, bt.box,
                  bt.category, bt.priority, bt.description, bt.raw->>'ComplainNumber' AS complain_mobile,
                  bc.address, bc.house, bc.road
           FROM billing_tickets bt
           LEFT JOIN billing_customers bc ON bc.company_id = bt.company_id AND bc.header_id = bt.customer_header_id
           WHERE bt.company_id = %s AND bt.complain_id = %s""", (company_id, str(complain_id))) or {}


def _address(f: dict) -> str:
    parts = [f.get("address"), f.get("house"), f.get("road"), f.get("subzone"), f.get("zone"),
             f"Box {f['box']}" if f.get("box") else None]
    seen, out = set(), []
    for p in parts:
        p = (p or "").strip()
        if p and p.lower() not in seen:
            seen.add(p.lower())
            out.append(p)
    return ", ".join(out)


def text_message(f: dict) -> str:
    who = f"*{f.get('customer_name') or '-'}* (ID {f.get('customer_id') or '-'})"
    lines = [f"🛠️ *নতুন টিকিট #{f.get('complain_id')}* আপনার নামে", f"কাস্টমার: {who}"]
    if f.get("username"):
        lines.append(f"PPPoE: {f['username']}")
    phone = f.get("complain_mobile") or f.get("mobile")
    if phone:
        lines.append(f"মোবাইল: {phone}")
    if _address(f):
        lines.append(f"ঠিকানা: {_address(f)}")
    problem = f.get("category") or "-"
    if f.get("priority"):
        problem += f" ({f['priority'].capitalize()})"
    lines.append(f"সমস্যা: {problem}")
    if (f.get("description") or "").strip():
        lines.append(f"বিবরণ: {f['description'].strip()[:600]}")
    lines.append("\nলাইনের অবস্থা জানতে এখানে কাস্টমার ID লিখুন।")
    return "\n".join(lines)


def template_params(f: dict) -> list[str]:
    customer = f"{f.get('customer_name') or '-'} (ID {f.get('customer_id') or '-'}" + \
               (f", {f['username']})" if f.get("username") else ")")
    problem = (f.get("category") or "-") + (f" - {f['description']}" if (f.get("description") or "").strip() else "")
    return [_one_line(f.get("complain_id"), 20), _one_line(customer, 120), _one_line(problem, 300),
            _one_line(_address(f), 200), _one_line(f.get("complain_mobile") or f.get("mobile"), 40)]


def template_status(t: Tenant) -> str | None:
    """APPROVED / PENDING / REJECTED ..., or None when it isn't there (then it is submitted). Cached 10 minutes."""
    at, status = _status.get(t.company_id, (0, None))
    if time.time() - at < 600 and status == "APPROVED":
        return status
    auth = {"Authorization": f"Bearer {t.wa_token}"}
    rows = _check(httpx.get(f"{GRAPH}/{t.waba_id}/message_templates", params={"name": TEMPLATE, "fields": "name,status,language"},
                            headers=auth, timeout=30)).get("data") or []
    row = next((r for r in rows if r.get("name") == TEMPLATE and r.get("language") == TEMPLATE_LANG), None)
    if not row:
        res = _check(httpx.post(f"{GRAPH}/{t.waba_id}/message_templates", headers=auth, timeout=30, json={
            "name": TEMPLATE, "language": TEMPLATE_LANG, "category": "UTILITY",
            "components": [{"type": "BODY", "text": TEMPLATE_BODY, "example": {"body_text": [TEMPLATE_EXAMPLE]}}]}))
        log.info("company %s: template %s submitted: %s", t.company_id, TEMPLATE, res)
        status = res.get("status") or "PENDING"
    else:
        status = row.get("status")
    _status[t.company_id] = (time.time(), status)
    return status


def send_template(t: Tenant, to: str, params: list[str]) -> dict:
    r = httpx.post(f"{GRAPH}/{t.phone_number_id}/messages", headers={"Authorization": f"Bearer {t.wa_token}"}, timeout=30,
                   json={"messaging_product": "whatsapp", "to": to, "type": "template",
                         "template": {"name": TEMPLATE, "language": {"code": TEMPLATE_LANG},
                                      "components": [{"type": "body", "parameters": [
                                          {"type": "text", "text": p} for p in params]}]}})
    return _check(r)


def _contact(t: Tenant, tech: dict) -> dict:
    return db.execute(
        """INSERT INTO wa_contacts (company_id, wa_number, name, last_message_at, created_at, updated_at)
           VALUES (%s, %s, %s, now(), now(), now())
           ON CONFLICT (company_id, wa_number) DO UPDATE SET last_message_at = now(), updated_at = now()
           RETURNING *""", (t.company_id, tech["wa_number"], tech["name"]))


def _in_window(t: Tenant, wa_number: str) -> bool:
    return bool(db.one(
        """SELECT 1 FROM wa_messages m JOIN wa_contacts c ON c.id = m.contact_id
           WHERE c.company_id = %s AND c.wa_number = %s AND m.direction = 'in'
             AND m.created_at > now() - interval '23 hours 50 minutes' LIMIT 1""", (t.company_id, wa_number)))


def send_one(t: Tenant, tech: dict, complain_id: str, employee_id: str) -> str:
    """Claims (ticket, technician) so a parallel sync can't send it twice, then sends. Returns the status.
    One that failed or was held (template not approved yet) is tried again every 15 minutes for 6 hours."""
    claimed = db.execute(
        """INSERT INTO ticket_notifications (company_id, complain_id, technician_id, employee_id, wa_number, status,
                                             created_at, updated_at)
           VALUES (%s, %s, %s, %s, %s, 'pending', now(), now())
           ON CONFLICT (company_id, complain_id, technician_id) DO UPDATE SET status = 'pending', updated_at = now()
             WHERE ticket_notifications.status IN ('failed', 'skipped')
               AND ticket_notifications.created_at > now() - interval '6 hours'
               AND ticket_notifications.updated_at < now() - interval '15 minutes'
           RETURNING id""",
        (t.company_id, str(complain_id), tech["id"], str(employee_id), tech["wa_number"]))
    if not claimed:
        return "already"
    f = ticket_facts(t.company_id, complain_id)
    channel, body, status, error = None, None, "sent", None
    try:
        if DRY_RUN:
            status, error = "skipped", "টেস্ট মোড (ENGINE_DRY_RUN), পাঠানো হয়নি"
        elif _in_window(t, tech["wa_number"]):
            channel, body = "text", text_message(f)
            res = send_text(t, tech["wa_number"], body)
            _save(t, tech, res, "text", body)
        else:
            channel = "template"
            params = template_params(f)
            body = TEMPLATE_BODY
            for i, p in enumerate(params, 1):
                body = body.replace("{{%d}}" % i, p)
            st = template_status(t)
            if st != "APPROVED":
                status = "skipped"
                error = (f"টেকনিশিয়ান গত ২৪ ঘণ্টায় মেসেজ দেননি, আর WhatsApp টেমপ্লেট '{TEMPLATE}' এখনো Meta-তে অনুমোদিত নয় "
                         f"({st or 'জমা হয়নি'})")
            else:
                res = send_template(t, tech["wa_number"], params)
                _save(t, tech, res, "template", body)
    except Exception as e:
        log.exception("ticket %s: message to technician %s failed", complain_id, tech["id"])
        status, error = "failed", str(e)[:500]
    db.execute("""UPDATE ticket_notifications SET channel = %s, status = %s, body = %s, error = %s, updated_at = now()
                  WHERE id = %s""", (channel, status, body, error, claimed["id"]))
    log.info("company %s ticket %s -> technician %s: %s %s", t.company_id, complain_id, tech["id"], channel, status)
    return status


def _save(t: Tenant, tech: dict, res: dict, mtype: str, body: str) -> None:
    """Into the technician's chat in the desk, as a message from the office."""
    try:
        c = _contact(t, tech)
        db.execute(
            """INSERT INTO wa_messages (company_id, contact_id, wa_account_id, wa_message_id, direction, sender, type, body,
                                        created_at)
               VALUES (%s, %s, %s, %s, 'out', 'bot', 'text', %s, now()) ON CONFLICT (wa_message_id) DO NOTHING""",
            (t.company_id, c["id"], t.wa_account_id, (res.get("messages") or [{}])[0].get("id"), body))
    except Exception:
        log.warning("could not store the ticket message in the chat", exc_info=True)


def notify(t: Tenant, api, complain_ids: list[str]) -> int:
    """For each ticket whose staff just changed: ask billing which employees it has now; message their technicians."""
    techs = linked_technicians(t.company_id)
    if not techs:
        return 0
    by_employee: dict[str, list[dict]] = {}
    for tech in techs:
        by_employee.setdefault(str(tech["billing_employee_id"]).strip(), []).append(tech)
    retry = [r["complain_id"] for r in db.all_rows(
        """SELECT DISTINCT n.complain_id FROM ticket_notifications n
           JOIN billing_tickets bt ON bt.company_id = n.company_id AND bt.complain_id = n.complain_id
           WHERE n.company_id = %s AND n.status IN ('failed', 'skipped') AND n.created_at > now() - interval '6 hours'
             AND n.updated_at < now() - interval '15 minutes' AND bt.state IN ('pending', 'processing')""",
        (t.company_id,))]
    sent = 0
    for cid in dict.fromkeys([*map(str, complain_ids), *retry]):
        try:
            ids = {str(e) for e in ((api.ticket_solvers(int(cid)) or {}).get("EmployeeIds") or [])}
        except Exception:
            log.warning("ticket %s: could not read its staff from billing", cid, exc_info=True)
            continue
        for emp in ids & by_employee.keys():
            for tech in by_employee[emp]:
                if send_one(t, tech, cid, emp) == "sent":
                    sent += 1
    return sent
