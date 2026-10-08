"""Copy every customer of a company's billing software into billing_customers (nightly, zyro-customer-sync.timer).

Three cheap list calls give nearly everything: Customer list (identity, address, network, package), Billing list
(this month's bill) and the Left list (customers who left; same CustomerHeaderId space, is_left = true, their PPPoE
user is deleted from the MikroTik). A customer moves between Active and Left both ways; every move the sync sees is
written to billing_customer_changes. PPPoE passwords are stored encrypted (Laravel-compatible, APP_KEY); portal passwords never.
  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.customer_sync [--company ID]
"""
import argparse
import json
import logging
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
    {", ".join(f"{c.strip()} = EXCLUDED.{c.strip()}" for c in COLS.split(",") if c.strip() not in ("company_id", "header_id"))},
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


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    logging.getLogger("httpx").setLevel(logging.WARNING)
    p = argparse.ArgumentParser()
    p.add_argument("--company", type=int)
    a = p.parse_args()
    from engine.ticket_sync import companies
    for t in companies(a.company):
        try:
            log.info("company %s: %s", t.company_id, sync_company(t))
        except Exception:
            log.exception("customer sync failed for company %s", t.company_id)


if __name__ == "__main__":
    main()
