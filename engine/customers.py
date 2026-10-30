"""Customer lookups from our own copy (billing_customers) first, the billing software only when needed.

Rows come back in the billing software's shape (CustomerHeaderId, CustomerId, UserName, ...) so the rest of the
engine does not care where they came from. Live parts (bill due, connection) are refreshed with one billing call.
"""
import json
import logging
import re

from engine import db
from engine.ispdigital import (ISPDigital, diagnose, find_customer_by_text, find_customer_by_whatsapp,
                               normalize_bd_mobile, number_text, without_urls)
from engine.tenant import Tenant

log = logging.getLogger("zyro-engine")
ID_HINT = re.compile(r"(\bid\b|আইডি|আই ডি|customer|কাস্টমার|গ্রাহক|user)", re.I)


def _rows(t: Tenant, where: str, params: tuple) -> list[dict]:
    rows = db.all_rows(f"SELECT extra FROM billing_customers WHERE company_id = %s AND gone_at IS NULL AND NOT is_left AND {where} LIMIT 3",
                       (t.company_id, *params))
    return [r["extra"] for r in rows if r["extra"]]


def _one(rows: list[dict]) -> dict | None:
    return rows[0] if len(rows) == 1 else None


def by_alt_number(t: Tenant, mobile: str) -> dict | None:
    """The one customer whose other number this is (customer_alt_numbers: profile phone, ticket, WhatsApp, SMS or
    added by staff in Desk → বিকল্প নম্বর; numbers shared by several customers are never there)."""
    rows = db.all_rows("""SELECT bc.extra FROM customer_alt_numbers a
                          JOIN billing_customers bc ON bc.company_id = a.company_id AND bc.header_id = a.customer_header_id
                          WHERE a.company_id = %s AND a.number = %s AND a.removed_at IS NULL
                            AND bc.gone_at IS NULL AND NOT bc.is_left LIMIT 3""", (t.company_id, mobile))
    return _one([r["extra"] for r in rows if r["extra"]])


def local_by_mobile(t: Tenant, mobile: str) -> dict | None:
    """A line by its registered mobile (8801…); a number on no line at all: by the customer's other numbers.
    A number on several lines finds nothing (the bot asks for the ID)."""
    rows = _rows(t, "mobile_normalized = %s", (mobile,))
    return _one(rows) if rows else by_alt_number(t, mobile)


def local_by_whatsapp(t: Tenant, wa_number: str) -> dict | None:
    mobile = normalize_bd_mobile(wa_number)
    return local_by_mobile(t, "88" + mobile) if len(mobile) == 11 else None


def local_by_text(t: Tenant, text: str, allow_bare_id: bool = True) -> dict | None:
    """Same rules as ispdigital.find_customer_by_text, against our table."""
    text = without_urls(text)
    nums = number_text(text)
    for m in re.findall(r"(?:\+?88)?01[3-9]\d{8}", re.sub(r"[\s-]", "", nums)):
        hit = local_by_mobile(t, "88" + normalize_bd_mobile(m))
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


BN_DIGITS = str.maketrans("০১২৩৪৫৬৭৮৯", "0123456789")


def by_text(t: Tenant, api: ISPDigital, text: str, allow_bare_id: bool = True) -> dict | None:
    text = (text or "").translate(BN_DIGITS)  # "৩২৬৯" is ID 3269
    hit = local_by_text(t, text, allow_bare_id)
    if hit:
        return hit
    try:
        hit = find_customer_by_text(api, text, allow_bare_id=allow_bare_id)
    except Exception as e:  # billing slow or down: same as not found, the bot still answers
        log.warning("billing search failed: %s", e)
        hit = None
    if hit:
        remember(t, hit)
    return hit


def by_id(t: Tenant, api: ISPDigital, customer_id: str) -> dict | None:
    """A line by its exact customer ID (a desk agent linked the chat to it); a Left line too."""
    cid = str(customer_id).strip()
    row = db.one("""SELECT extra FROM billing_customers WHERE company_id = %s AND gone_at IS NULL AND extra IS NOT NULL
                      AND ltrim(customer_id, '0') = ltrim(%s, '0') ORDER BY is_left LIMIT 1""", (t.company_id, cid))
    return row["extra"] if row else by_text(t, api, cid, allow_bare_id=True)


def by_whatsapp(t: Tenant, api: ISPDigital, wa_number: str) -> dict | None:
    hit = local_by_whatsapp(t, wa_number)
    if hit:
        return hit
    try:
        hit = find_customer_by_whatsapp(api, wa_number)
    except Exception as e:  # billing slow or down: same as not found, the bot still answers
        log.warning("billing search failed: %s", e)
        hit = None
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


def note_fallback(t: Tenant, kind: str, reason: str) -> None:
    """Count each time billing had to fill in for our own MikroTik/OLT (shown on the বিলিং সংযোগ page), so the
    owner can see when the billing switches can go off."""
    try:
        db.execute("""INSERT INTO billing_fallbacks (company_id, day, kind, reason, count)
                      VALUES (%s, (now() AT TIME ZONE 'Asia/Dhaka')::date, %s, %s, 1)
                      ON CONFLICT (company_id, day, kind, reason) DO UPDATE SET count = billing_fallbacks.count + 1""",
                   (t.company_id, kind, reason))
    except Exception:
        log.exception("could not count billing fallback")


def own_line(t: Tenant, username: str, server: str | None) -> tuple[dict | None, str | None]:
    """(MikroTik's answer now or None, the router MAC: this session's, else the last one we saw)."""
    from engine.ppp_sync import online_now
    try:
        mk = online_now(t.company_id, username or "", server)
    except Exception:
        log.exception("mikrotik check failed")
        mk = None
    mac = (mk or {}).get("caller_id")
    if not mac and username:
        last = db.one("SELECT caller_id FROM ppp_sessions WHERE company_id = %s AND username = %s", (t.company_id, username))
        mac = last and last["caller_id"]
    return mk, mac or None


def own_onu(t: Tenant, mac: str | None) -> dict | None:
    """Our own OLT's ONU behind that router MAC, read now (billing-like shape)."""
    if not mac:
        return None
    from engine.olt_sync import onu_for_mac
    try:
        return onu_for_mac(t.company_id, mac)
    except Exception:
        log.exception("own OLT read failed")
        return None


def _gb(n) -> str | None:
    return None if n is None else f"{n / 1024 ** 3:.2f} GB"


def live_context(t: Tenant, api: ISPDigital, customer: dict, billing_onu: bool = True) -> dict:
    """Everything the bot / customer page / technician needs about one line, from our own MikroTik and OLT first.
    The billing software is asked only for what we don't have, and only while the company's switches allow it
    (বিলিং সংযোগ page): its live page when no router answered or we never saw the MAC, its ONU page when the
    MAC isn't on our OLTs. billing_onu=False: never billing's ONU page (slow, 15+ s; the voice bot)."""
    mk, mac = own_line(t, customer.get("UserName") or "", customer.get("Server"))
    live = None
    if (mk is None or not mac) and t.billing_live:
        note_fallback(t, "live", "no_router" if mk is None else "no_mac")
        try:
            live = api.live_status(customer["CustomerHeaderId"])
        except Exception as e:
            log.warning("billing live status failed for %s: %s", customer.get("CustomerHeaderId"), e)
        mac = mac or (live or {}).get("calledid") or None
    onu = own_onu(t, mac)
    billing_onu_row = None
    if not onu and mac and billing_onu and t.billing_onu:
        note_fallback(t, "onu", "not_on_own_olt")
        try:
            billing_onu_row = api.onu_info(mac)
        except Exception as e:
            log.warning("billing ONU info failed: %s", e)
    context = diagnose(api, customer, live=live or {}, onu=billing_onu_row, ask_billing=False)
    if not live and mk:  # the connection from our router instead of billing's page
        context["pppoe"] = {"connectivity": "Blocked" if mk.get("blocked") else ("Connected" if mk.get("online") else "Disconnected"),
                            "uptime": mk.get("uptime"), "last_logout": None, "ip": mk.get("address"),
                            "downloaded": _gb(mk.get("download_bytes"))}
    context["mikrotik"] = mk
    if onu:  # our OLT's answer (right now)
        context["onu"] = {"olt": onu["OLTName"], "port": onu["OLTPort"], "status": onu["OnuStatus"],
                          "optical_power_dbm": onu["OpticalPower"], "distance_m": onu["Distance"],
                          "last_change": onu["LastChange"], "source": "own OLT"}
    elif context.get("onu"):
        context["onu"]["source"] = "billing"
    context["sources"] = {"connection": "mikrotik" if mk else ("billing" if live else None),
                          "onu": "own OLT" if onu else ("billing" if billing_onu_row else None)}
    context["mac"] = mac  # staff only: customer_view / safe_view drop it
    return context
