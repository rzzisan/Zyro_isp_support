"""Customer lookups from our own copy (billing_customers) first, the billing software only when needed.

Rows come back in the billing software's shape (CustomerHeaderId, CustomerId, UserName, ...) so the rest of the
engine does not care where they came from. Live parts (bill due, connection) are refreshed with one billing call.
"""
import json
import logging
import re

from engine import db
from engine.ispdigital import (ISPDigital, find_customer_by_text, find_customer_by_whatsapp, normalize_bd_mobile,
                               number_text, without_urls)
from engine.tenant import Tenant

log = logging.getLogger("zyro-engine")
ID_HINT = re.compile(r"(\bid\b|আইডি|আই ডি|customer|কাস্টমার|গ্রাহক|user)", re.I)


def _rows(t: Tenant, where: str, params: tuple) -> list[dict]:
    rows = db.all_rows(f"SELECT extra FROM billing_customers WHERE company_id = %s AND gone_at IS NULL AND NOT is_left AND {where} LIMIT 3",
                       (t.company_id, *params))
    return [r["extra"] for r in rows if r["extra"]]


def _one(rows: list[dict]) -> dict | None:
    return rows[0] if len(rows) == 1 else None


def local_by_whatsapp(t: Tenant, wa_number: str) -> dict | None:
    mobile = normalize_bd_mobile(wa_number)
    return _one(_rows(t, "mobile_normalized = %s", ("88" + mobile,))) if len(mobile) == 11 else None


def local_by_text(t: Tenant, text: str, allow_bare_id: bool = True) -> dict | None:
    """Same rules as ispdigital.find_customer_by_text, against our table."""
    text = without_urls(text)
    nums = number_text(text)
    for m in re.findall(r"(?:\+?88)?01[3-9]\d{8}", re.sub(r"[\s-]", "", nums)):
        hit = _one(_rows(t, "mobile_normalized = %s", ("88" + normalize_bd_mobile(m),)))
        if hit:
            return hit
    bare_ok = allow_bare_id or bool(ID_HINT.search(text)) or text.strip().isdigit()
    for cid in (re.findall(r"(?<!\d)\d{2,6}(?!\d)", nums) if bare_ok else []):
        hit = _one(_rows(t, "ltrim(customer_id, '0') = %s", (cid.lstrip("0") or "0",)))
        if hit:
            return hit
    for uname in re.findall(r"\b[a-zA-Z][\w@-]*[.@][\w.@-]+\b", text):
        hit = _one(_rows(t, "lower(username) = %s", (uname.lower(),)))
        if hit:
            return hit
    return None


def remember(t: Tenant, row: dict) -> None:
    """A customer found only in billing (new since the last sync): keep a copy right away."""
    try:
        from engine.customer_sync import COLS, N, row_for
        db.execute(f"""INSERT INTO billing_customers ({COLS}, synced_at, created_at, updated_at)
                       VALUES ({", ".join(["%s"] * N)}, now(), now(), now())
                       ON CONFLICT (company_id, header_id) DO NOTHING""", row_for(t.company_id, row, row))
    except Exception:
        log.exception("could not remember customer")


def by_text(t: Tenant, api: ISPDigital, text: str, allow_bare_id: bool = True) -> dict | None:
    hit = local_by_text(t, text, allow_bare_id)
    if hit:
        return hit
    hit = find_customer_by_text(api, text, allow_bare_id=allow_bare_id)
    if hit:
        remember(t, hit)
    return hit


def by_whatsapp(t: Tenant, api: ISPDigital, wa_number: str) -> dict | None:
    hit = local_by_whatsapp(t, wa_number)
    if hit:
        return hit
    hit = find_customer_by_whatsapp(api, wa_number)
    if hit:
        remember(t, hit)
    return hit


def fresh(api: ISPDigital, row: dict) -> dict:
    """This month's bill and status change during the day: refresh them from billing (one call) before answering."""
    try:
        key = row.get("UserName") or row.get("CustomerId") or ""
        live = [r for r in api.search_customers(key, limit=10) if r.get("CustomerHeaderId") == row.get("CustomerHeaderId")]
        return {**row, **live[0]} if live else row
    except Exception:
        log.exception("bill refresh failed, using stored data")
        return row


def search(t: Tenant, q: str, limit: int = 10) -> list[dict]:
    """Panel search box: ID, mobile, PPPoE ID or name."""
    q = q.strip()
    digits = re.sub(r"\D", "", q)
    rows = db.all_rows(
        """SELECT extra FROM billing_customers WHERE company_id = %s AND gone_at IS NULL AND NOT is_left AND (
               ltrim(customer_id, '0') = ltrim(%s, '0') OR lower(username) LIKE %s OR name ILIKE %s
               OR (length(%s) >= 5 AND mobile_normalized LIKE %s))
           ORDER BY (ltrim(customer_id, '0') = ltrim(%s, '0')) DESC, customer_id LIMIT %s""",
        (t.company_id, q, f"%{q.lower()}%", f"%{q}%", digits, f"%{digits.lstrip('0')}%", q, limit))
    return [r["extra"] for r in rows if r["extra"]]


def add_online(t: Tenant, context: dict, row: dict) -> dict:
    """Put the MikroTik's answer (online right now, uptime, IP, MAC) into the bot's live data."""
    try:
        from engine.ppp_sync import online_now
        context["mikrotik"] = online_now(t.company_id, row.get("UserName") or "", row.get("Server"))
        mac = (context["mikrotik"] or {}).get("caller_id")
        if not mac:  # offline now: the router MAC from its last session
            last = db.one("SELECT caller_id FROM ppp_sessions WHERE company_id = %s AND username = %s",
                          (t.company_id, row.get("UserName") or ""))
            mac = last and last["caller_id"]
        if mac:
            from engine.olt_sync import onu_for_mac
            own = onu_for_mac(t.company_id, mac)
            if own:  # our OLT's answer (right now) replaces the billing software's copy
                context["onu"] = {"olt": own["OLTName"], "port": own["OLTPort"], "status": own["OnuStatus"],
                                  "optical_power_dbm": own["OpticalPower"], "distance_m": own["Distance"],
                                  "last_change": own["LastChange"], "source": "own OLT"}
    except Exception:
        log.exception("mikrotik check failed")
    return context
