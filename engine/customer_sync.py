"""Copy every customer of a company's billing software into billing_customers (nightly, zyro-customer-sync.timer).

Three cheap list calls give nearly everything: Customer list (identity, address, network, package), Billing list
(this month's bill) and the Left list (customers who left; same CustomerHeaderId space, is_left = true, their PPPoE
user is deleted from the MikroTik). A customer moves between Active and Left both ways; every move the sync sees is
written to billing_customer_changes. PPPoE passwords are stored encrypted (Laravel-compatible, APP_KEY); portal passwords never.
  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.customer_sync [--company ID] [--new]

--new (every 20 minutes, zyro-customer-new.timer): only look for customer IDs after the highest one we have
(5959 -> 5960, 5961, ...), one billing search per ID, and copy the ones found. Stops after NEW_MISSES IDs in a row
are not found, so a deleted ID in between doesn't stop it.
"""
import argparse
import json
import logging
import re
from datetime import datetime

from engine import db, tenant as tenants
from engine.ispdigital import normalize_bd_mobile

log = logging.getLogger("zyro-customer-sync")
PAGE = 500
NEVER_KEEP = {"Password", "LoginPassword", "vPassword"}


def fetch_all(api, path: str) -> list[dict]:
    rows, start = [], 0
    while True:
        d = api._get(path, {"draw": 1, "start": start, "length": PAGE, "search[value]": "", "search[regex]": "false"})
        batch = d.get("aaData") or []
        rows += batch
        start += PAGE
        if len(batch) < PAGE or start >= int(d.get("iTotalDisplayRecords") or 0):
            return rows


def money(v):
    try:
        return round(float(str(v).replace(",", "")), 2) if v not in (None, "") else None
    except ValueError:
        return None


def day(v, *formats):
    for f in formats:
        try:
            return datetime.strptime(str(v).strip(), f).date()
        except (TypeError, ValueError):
            continue
    return None


def row_for(company_id: int, c: dict, bill: dict | None, left: bool = False) -> tuple:
    merged = {**c, **{k: v for k, v in (bill or {}).items() if v not in (None, "")}}
    extra = {k: v for k, v in merged.items() if k not in NEVER_KEEP}
    pw = c.get("Password")
    mobile = (merged.get("MobileNumber") or "").strip() or None
    bill_day = str(merged.get("BillingLastDate") or "").strip()
    return (
        company_id, int(merged["CustomerHeaderId"]), merged.get("CustomerId"), merged.get("UserName"),
        db.encrypt(pw) if pw else None, merged.get("CustomerName"), mobile,
        ("88" + normalize_bd_mobile(mobile)) if mobile and len(normalize_bd_mobile(mobile)) == 11 else None,
        merged.get("EmailAddress") or None, merged.get("NationalId") or None, merged.get("Address") or None,
        merged.get("HouseNo") or None, merged.get("RoadNo") or None, merged.get("Thana") or None,
        merged.get("District") or None, merged.get("ZoneName") or None, merged.get("SubZoneName") or None,
        merged.get("BoxName") or None, merged.get("Package") or None, merged.get("PackageId"),
        merged.get("Speed") or None, money(merged.get("MonthlyBill")), merged.get("ConnectionType") or None,
        merged.get("CustomerType") or None, merged.get("Protocol") or None, merged.get("Server") or None,
        merged.get("Status") or None, bool(merged.get("Disabled")), bool(merged.get("IsVIPClient")),
        int(bill_day) if bill_day.isdigit() else None,
        money((bill or {}).get("PayabaleBill")), money((bill or {}).get("PaidAmount")),
        money((bill or {}).get("BalanceDue") if not left else c.get("Due")), money((bill or {}).get("AdvancePayemnt")),
        day((bill or {}).get("PaymentDate"), "%b-%d-%Y"), merged.get("AssignedEmployee") or None,
        day(merged.get("ClientJoiningDate"), "%d-%b-%Y"), day(merged.get("RegistrationDate"), "%d-%m-%Y", "%d-%b-%Y"),
        merged.get("Device") or None, json.dumps(extra, ensure_ascii=False),
        left, day(c.get("LeftDate"), "%d %b %Y") if left else None,
    )


COLS = """company_id, header_id, customer_id, username, pppoe_password, name, mobile, mobile_normalized, email, nid,
    address, house, road, thana, district, zone, subzone, box, package, package_id, speed, monthly_bill,
    connection_type, customer_type, protocol, server, status, disabled, is_vip, bill_day, payable, paid, due, advance,
    last_payment_date, assigned_employee, joined_on, registered_on, device, extra, is_left, left_on"""
N = COLS.count(",") + 1
UPSERT = f"""INSERT INTO billing_customers ({COLS}, synced_at, gone_at, created_at, updated_at)
    VALUES ({", ".join(["%s"] * N)}, now(), NULL, now(), now())
    ON CONFLICT (company_id, header_id) DO UPDATE SET
    {", ".join(f"{c} = COALESCE(EXCLUDED.{c}, billing_customers.{c})" if c == "last_payment_date" else f"{c} = EXCLUDED.{c}"
               for c in (c.strip() for c in COLS.split(",")) if c not in ("company_id", "header_id"))},
    synced_at = now(), gone_at = NULL, updated_at = now()
    RETURNING (xmax = 0) AS inserted"""


def sync_company(t) -> dict:
    api = tenants.billing(t)
    sync = db.execute("INSERT INTO billing_customer_syncs (company_id, started_at) VALUES (%s, now()) RETURNING id",
                      (t.company_id,))
    try:
        customers = fetch_all(api, "/Customer/AjaxCustomerList")
        bills = {int(b["CustomerHeaderId"]): b for b in fetch_all(api, "/Billing/AjaxCustomerBillList")}
        active_ids = {int(r["CustomerHeaderId"]) for r in customers}
        # a customer moved while we paged would be in both lists: the Active list wins
        left = [r for r in fetch_all(api, "/Customer/AjaxLeftCustomerList") if int(r["CustomerHeaderId"]) not in active_ids]
        created = 0
        with db.conn() as c:
            with c.transaction():
                was_left = {r["header_id"]: r["is_left"] for r in c.execute(
                    "SELECT header_id, is_left FROM billing_customers WHERE company_id = %s", (t.company_id,)).fetchall()}
                changes = []
                for row in customers:
                    hid = int(row["CustomerHeaderId"])
                    r = c.execute(UPSERT, row_for(t.company_id, row, bills.get(hid))).fetchone()
                    created += 1 if r["inserted"] else 0
                    if was_left.get(hid) is True:
                        changes.append((t.company_id, hid, row.get("CustomerId"), row.get("CustomerName"), "returned", None))
                for row in left:
                    hid = int(row["CustomerHeaderId"])
                    vals = row_for(t.company_id, row, None, left=True)
                    r = c.execute(UPSERT, vals).fetchone()
                    created += 1 if r["inserted"] else 0
                    if was_left.get(hid) is False:
                        changes.append((t.company_id, hid, row.get("CustomerId"), row.get("CustomerName"), "left", vals[-1]))
                if changes:
                    c.cursor().executemany("""INSERT INTO billing_customer_changes
                        (company_id, header_id, customer_id, name, change, left_on) VALUES (%s, %s, %s, %s, %s, %s)""", changes)
                ids = list(active_ids) + [int(r["CustomerHeaderId"]) for r in left]
                gone = c.execute("""WITH g AS (UPDATE billing_customers SET gone_at = now(), updated_at = now()
                                     WHERE company_id = %s AND gone_at IS NULL AND NOT (header_id = ANY(%s)) RETURNING 1)
                                    SELECT count(*) AS n FROM g""", (t.company_id, ids)).fetchone()["n"]
        became_left = sum(1 for x in changes if x[4] == "left")
        res = {"total": len(customers), "created": created, "gone": gone, "left_total": len(left),
               "became_left": became_left, "came_back": len(changes) - became_left}
        db.execute("""UPDATE billing_customer_syncs SET finished_at = now(), total = %s, created = %s, gone = %s,
                      left_total = %s, became_left = %s, came_back = %s WHERE id = %s""",
                   (res["total"], created, gone, res["left_total"], res["became_left"], res["came_back"], sync["id"]))
        return res
    except Exception as e:
        db.execute("UPDATE billing_customer_syncs SET finished_at = now(), error = %s WHERE id = %s", (str(e)[:1000], sync["id"]))
        raise


NEW_MISSES = 3
NEW_LIMIT = 50


def _id_num(v) -> int | None:
    v = str(v or "").strip()
    return int(v) if v.isdigit() else None


def _search(api, path: str, query: str) -> list[dict]:
    d = api._get(path, {"draw": 1, "start": 0, "length": 100, "search[value]": query, "search[regex]": "false"})
    return d.get("aaData") or []


def find_by_id(api, n: int) -> tuple[dict, dict | None] | None:
    """(customer list row, bill row) of the customer whose ID is n (leading zeros ignored), or None."""
    q = str(n)
    rows = [r for r in _search(api, "/Customer/AjaxCustomerList", q) if _id_num(r.get("CustomerId")) == n]
    bills = [b for b in _search(api, "/Billing/AjaxCustomerBillList", q) if _id_num(b.get("CustomerId")) == n]
    if not rows and bills and bills[0].get("UserName"):
        # the customer list search may not look at the ID column: find the row by its PPPoE user instead
        hid = int(bills[0]["CustomerHeaderId"])
        rows = [r for r in _search(api, "/Customer/AjaxCustomerList", bills[0]["UserName"]) if int(r["CustomerHeaderId"]) == hid]
    if not rows:
        return None
    hid = int(rows[0]["CustomerHeaderId"])
    return rows[0], next((b for b in bills if int(b["CustomerHeaderId"]) == hid), None)


def sync_new(t) -> dict:
    """Copy customers whose ID is above the highest ID we have. Cheap: about two billing searches per ID checked."""
    api = tenants.billing(t)
    last = db.one("""SELECT max(customer_id::bigint) AS n FROM billing_customers
                     WHERE company_id = %s AND customer_id ~ '^[0-9]{1,9}$'""", (t.company_id,))["n"]
    if last is None:
        return {"last_id": None, "checked": 0, "found": [], "note": "no customers yet, run the full sync first"}
    found, n, misses = [], int(last), 0
    while misses < NEW_MISSES and len(found) < NEW_LIMIT:
        n += 1
        hit = find_by_id(api, n)
        if not hit:
            misses += 1
            continue
        misses = 0
        row, bill = hit
        db.execute(UPSERT, row_for(t.company_id, row, bill))
        found.append(row.get("CustomerId"))
    return {"last_id": int(last), "checked": n - int(last), "found": found}


FIND_LIMIT = 20
FIND_SYNC_LIMIT = 5


def find_in_billing(t, q: str) -> dict:
    """Desk header search found nothing in our copy: ask the billing header search, copy the customers we don't have
    yet (at most FIND_SYNC_LIMIT, about two list searches each), and return the header ids of every billing hit."""
    api = tenants.billing(t)
    hits = []
    for h in api.quick_search(q.strip())[:FIND_LIMIT]:
        m = re.match(r"^\s*(.*?)\s*\(([^()]*)\)\s*$", str(h.get("text") or ""))
        if str(h.get("id") or "").isdigit():
            hits.append((int(h["id"]), m.group(1) if m else str(h.get("text") or "").strip()))
    have = {r["header_id"] for r in db.all_rows(
        "SELECT header_id FROM billing_customers WHERE company_id = %s AND header_id = ANY(%s)",
        (t.company_id, [hid for hid, _ in hits]))} if hits else set()
    synced = []
    for hid, user in [h for h in hits if h[0] not in have][:FIND_SYNC_LIMIT]:
        if not user:
            continue
        rows = [r for r in _search(api, "/Customer/AjaxCustomerList", user) if int(r["CustomerHeaderId"]) == hid]
        if rows:
            bill = next((b for b in _search(api, "/Billing/AjaxCustomerBillList", user)
                         if int(b["CustomerHeaderId"]) == hid), None)
            db.execute(UPSERT, row_for(t.company_id, rows[0], bill))
        else:
            rows = [r for r in _search(api, "/Customer/AjaxLeftCustomerList", user) if int(r["CustomerHeaderId"]) == hid]
            if not rows:
                continue
            db.execute(UPSERT, row_for(t.company_id, rows[0], None, left=True))
        synced.append(hid)
    return {"header_ids": [hid for hid, _ in hits if hid in have or hid in synced], "synced": synced}


def backfill_last_payments(t, limit: int | None = None) -> dict:
    """Customers whose last payment was before this bill month have no PaymentDate in the bill list: read their
    payment history (newest first) and keep the latest real receipt's date. Read-only on billing."""
    api = tenants.billing(t)
    todo = [r["header_id"] for r in db.all_rows(
        "SELECT header_id FROM billing_customers WHERE company_id = %s AND last_payment_date IS NULL AND gone_at IS NULL"
        " ORDER BY is_left, header_id", (t.company_id,))][:limit]
    found = 0
    for i, hid in enumerate(todo, 1):
        try:
            pays = [p for p in api.payments(hid, 5) if not p.get("IsBillReceiveCanceled")]
        except Exception as e:
            log.warning("payments of %s: %s", hid, e)
            continue
        d = next((x for x in (day(p.get("PaymentDate") or p.get("BillReceivedDate"), "%d %b %Y") for p in pays) if x), None)
        if d:
            db.execute("UPDATE billing_customers SET last_payment_date = %s WHERE company_id = %s AND header_id = %s"
                       " AND last_payment_date IS NULL", (d, t.company_id, hid))
            found += 1
        if i % 200 == 0:
            log.info("company %s: last payments %s/%s, found %s", t.company_id, i, len(todo), found)
    return {"checked": len(todo), "found": found}


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    logging.getLogger("httpx").setLevel(logging.WARNING)
    p = argparse.ArgumentParser()
    p.add_argument("--company", type=int)
    p.add_argument("--new", action="store_true", help="only look for new customer IDs after the highest one")
    p.add_argument("--last-payments", action="store_true", help="fill missing last payment dates from payment history")
    p.add_argument("--limit", type=int)
    a = p.parse_args()
    from engine.ticket_sync import companies
    for t in companies(a.company):
        try:
            log.info("company %s: %s", t.company_id, backfill_last_payments(t, a.limit) if a.last_payments
                     else sync_new(t) if a.new else sync_company(t))
        except Exception:
            log.exception("customer sync failed for company %s", t.company_id)


if __name__ == "__main__":
    main()
