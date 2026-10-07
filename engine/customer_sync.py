"""Copy every customer of a company's billing software into billing_customers (nightly, zyro-customer-sync.timer).

Two cheap list calls give nearly everything: Customer list (identity, address, network, package) and Billing list
(this month's bill). PPPoE passwords are stored encrypted (Laravel-compatible, APP_KEY); portal passwords never.
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


def row_for(company_id: int, c: dict, bill: dict | None) -> tuple:
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
        money((bill or {}).get("BalanceDue")), money((bill or {}).get("AdvancePayemnt")),
        day((bill or {}).get("PaymentDate"), "%b-%d-%Y"), merged.get("AssignedEmployee") or None,
        day(merged.get("ClientJoiningDate"), "%d-%b-%Y"), day(merged.get("RegistrationDate"), "%d-%m-%Y", "%d-%b-%Y"),
        merged.get("Device") or None, json.dumps(extra, ensure_ascii=False),
    )


COLS = """company_id, header_id, customer_id, username, pppoe_password, name, mobile, mobile_normalized, email, nid,
    address, house, road, thana, district, zone, subzone, box, package, package_id, speed, monthly_bill,
    connection_type, customer_type, protocol, server, status, disabled, is_vip, bill_day, payable, paid, due, advance,
    last_payment_date, assigned_employee, joined_on, registered_on, device, extra"""
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
        created = 0
        with db.conn() as c:
            with c.transaction():
                for row in customers:
                    r = c.execute(UPSERT, row_for(t.company_id, row, bills.get(int(row["CustomerHeaderId"])))).fetchone()
                    created += 1 if r["inserted"] else 0
                ids = [int(r["CustomerHeaderId"]) for r in customers]
                gone = c.execute("""WITH g AS (UPDATE billing_customers SET gone_at = now(), updated_at = now()
                                     WHERE company_id = %s AND gone_at IS NULL AND NOT (header_id = ANY(%s)) RETURNING 1)
                                    SELECT count(*) AS n FROM g""", (t.company_id, ids)).fetchone()["n"]
        res = {"total": len(customers), "created": created, "gone": gone}
        db.execute("UPDATE billing_customer_syncs SET finished_at = now(), total = %s, created = %s, gone = %s WHERE id = %s",
                   (res["total"], created, gone, sync["id"]))
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
