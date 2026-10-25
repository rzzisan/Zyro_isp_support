"""A customer asks the bot (WhatsApp chat, WhatsApp call, hotline) to turn their line back on.

Rule (Zisan 2026-10-25): only a line the billing software turned off for the bill, only while the due is at most one
month's bill, and once per customer per calendar month (Bangladesh time). Every request, done or refused, is logged
in line_enables (source customer_*), the same log as technicians' and the Desk's switches. Off unless the company
turns it on (Bot settings: self_reconnect).
"""
import logging
import os
from datetime import datetime, timedelta, timezone

from engine import db, tenant as tenants
from engine.ispdigital import _num, find_customer_by_text
from engine.tenant import Tenant

log = logging.getLogger("zyro-engine")

SOURCES = {"whatsapp": "WhatsApp চ্যাট", "wa_call": "WhatsApp কল", "hotline": "হটলাইন কল"}
SLACK = 1.0  # taka: a due of 500.40 on a 500 bill is still one month


def _month_start_utc() -> datetime:
    """The first moment of this month in Bangladesh (UTC+6), as naive UTC like the panel stores it."""
    bd = datetime.now(timezone.utc) + timedelta(hours=6)
    return bd.replace(day=1, hour=0, minute=0, second=0, microsecond=0, tzinfo=None) - timedelta(hours=6)


def used_this_month(t: Tenant, customer: dict) -> datetime | None:
    """When the customer's own request turned this line on this month (BD time), if it did."""
    row = db.one("""SELECT created_at FROM line_enables WHERE company_id = %s AND customer_header_id = %s
                      AND source LIKE 'customer%%' AND result = 'enabled' AND created_at >= %s
                    ORDER BY created_at LIMIT 1""",
                 (t.company_id, int(customer.get("CustomerHeaderId") or 0), _month_start_utc()))
    return row["created_at"] + timedelta(hours=6) if row else None


def _stored_monthly(t: Tenant, customer: dict) -> float:
    """The monthly bill from our copy: billing's customer search (the fresh row) has no MonthlyBill, only the Billing
    list the customer sync reads does (hotline calls 239/240, 2026-10-25: every line came out "not_bill")."""
    row = db.one("SELECT monthly_bill FROM billing_customers WHERE company_id = %s AND header_id = %s",
                 (t.company_id, int(customer.get("CustomerHeaderId") or 0)))
    return _num(row["monthly_bill"]) if row else 0.0


def check(t: Tenant, customer: dict) -> tuple[str, dict]:
    """(verdict, numbers) for a fresh billing row. verdict: ok | off | already_active | not_bill | due_too_high |
    used_this_month."""
    due, monthly = _num(customer.get("BalanceDue")), _num(customer.get("MonthlyBill")) or _stored_monthly(t, customer)
    info = {"due": round(due), "monthly_bill": round(monthly)}
    if not t.bot.get("self_reconnect"):
        return "off", info
    if not customer.get("Disabled"):
        return "already_active", info
    if due <= 0 or monthly <= 0 or (customer.get("Status") or "Active") != "Active":
        return "not_bill", info  # Inactive lines are off on purpose and carry no due
    if due > monthly + SLACK:
        return "due_too_high", info
    used = used_this_month(t, customer)
    if used:
        return "used_this_month", {**info, "used_on": used.strftime("%d-%m-%Y")}
    return "ok", info


def status(t: Tenant, customer: dict) -> dict | None:
    """For the bots' customer data: whether asking would turn this line on now (None = feature off / line on)."""
    verdict, info = check(t, customer)
    if verdict in ("off", "already_active"):
        return None
    return {"can_reconnect_now": verdict == "ok", "why_not": None if verdict == "ok" else verdict, **info}


def message(verdict: str, info: dict) -> str:
    """What the customer is told: the bots send / say this, never their own version."""
    due = info.get("due")
    if verdict == "enabled":
        return (f"আপনার লাইন চালু করে দেওয়া হলো। এই সুবিধা মাসে শুধু একবার পাওয়া যায়, এই মাসে আর পাবেন না। "
                f"বকেয়া {due} টাকা তাড়াতাড়ি পরিশোধ করে দেবেন।")
    if verdict == "used_this_month":
        return (f"এই মাসে ({info.get('used_on')}) একবার আপনার লাইন চালু করে দেওয়া হয়েছে, এই মাসে আর চালু করা সম্ভব না। "
                f"বকেয়া {due} টাকা পরিশোধ করলে লাইন চালু হয়ে যাবে।")
    if verdict == "due_too_high":
        return (f"আপনার বকেয়া {due} টাকা, এক মাসের বিলের ({info.get('monthly_bill')} টাকা) বেশি। এক মাসের বেশি বকেয়া "
                "থাকলে এভাবে লাইন চালু করা যায় না; বকেয়া পরিশোধ করলে লাইন চালু হয়ে যাবে।")
    if verdict == "already_active":
        return "আপনার লাইন বিলের কারণে বন্ধ নেই, চালুই আছে।"
    if verdict == "not_bill":
        return "আপনার লাইন বিলের কারণে বন্ধ না, তাই এভাবে চালু করা যাচ্ছে না। আমাদের অফিসের একজন দেখে যোগাযোগ করবেন।"
    if verdict == "dry_run":
        return "(টেস্ট মোড: লাইন আসলে চালু করা হয়নি)"
    if verdict == "off":
        return "এভাবে লাইন চালু করার ব্যবস্থা নেই। বকেয়া পরিশোধ করলে লাইন চালু হয়ে যাবে।"
    return "এই মুহূর্তে লাইন চালু করা গেল না। আমাদের অফিসের একজন দেখে যোগাযোগ করবেন।"


def reconnect(t: Tenant, customer: dict, source: str, number: str | None, request: str) -> dict:
    """Check the rule against billing right now and turn the line on if it holds. One request per line at a time
    (a Postgres lock: the chat and the voice bot run in different processes). Returns {result, say, ...}."""
    hid = int(customer.get("CustomerHeaderId") or 0)
    api = tenants.billing(t)
    with db.conn() as c:
        c.execute("SELECT pg_advisory_lock(%s, %s)", (7301, hid))
        try:
            fresh = find_customer_by_text(api, customer.get("CustomerId") or "", allow_bare_id=True)
            # billing's answer now (Disabled, BalanceDue) over what we had; fields it doesn't send keep our values
            fresh = {**customer, **{k: v for k, v in fresh.items() if v is not None}} \
                if fresh and int(fresh.get("CustomerHeaderId") or 0) == hid else customer
            verdict, info = check(t, fresh)
            result, error = verdict, None
            if verdict == "ok":
                if os.environ.get("ENGINE_DRY_RUN", "1") == "1":
                    result = "dry_run"
                else:
                    try:
                        api.enable_customer(hid)
                        after = find_customer_by_text(api, fresh.get("CustomerId") or "", allow_bare_id=True)
                        if after and int(after.get("CustomerHeaderId") or 0) == hid and after.get("Disabled"):
                            result, error = "failed", "billing still shows the line disabled"
                        else:
                            result = "enabled"
                    except Exception as e:
                        log.exception("customer reconnect failed for %s", hid)
                        result, error = "failed", str(e)[:500]
            if result == "enabled":
                db.execute("UPDATE billing_customers SET disabled = false, updated_at = now() "
                           "WHERE company_id = %s AND header_id = %s", (t.company_id, hid))
            c.execute(
                """INSERT INTO line_enables (company_id, technician_name, technician_number, customer_id, customer_header_id,
                       customer_name, username, due, request, action, result, error, source, created_at)
                   VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, 'enable', %s, %s, %s, now())""",
                (t.company_id, f"কাস্টমার নিজে ({SOURCES.get(source, source)})", number, fresh.get("CustomerId"), hid,
                 fresh.get("CustomerName"), fresh.get("UserName"), str(info["due"]), (request or "")[:2000],
                 result, error, f"customer_{source}"))
        finally:
            c.execute("SELECT pg_advisory_unlock(%s, %s)", (7301, hid))
    log.info("company %s customer %s asked to reconnect (%s): %s", t.company_id, fresh.get("CustomerId"), source, result)
    return {"result": result, "say": message(result, info), **info}
