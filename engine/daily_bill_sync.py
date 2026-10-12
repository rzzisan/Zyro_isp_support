"""Copy every collected bill from each company's billing software (Daily Bill Collection) into billing_daily_bills.

Both the normal list (approved + pending) and the deleted list are read for a date range, so approvals and
deletions made later in billing show up on the next run. Run every 10 minutes by zyro-daily-bill-sync.timer
for the last --days days; --month 2026-10 (or --from/--to dd-mm-yyyy) for a backfill.
  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.daily_bill_sync [--days 7] [--month 2026-10] [--company ID]
"""
import argparse
import logging
from datetime import date, datetime, timedelta, timezone
from zoneinfo import ZoneInfo

from engine import db, tenant as tenants
from engine.ticket_sync import companies

log = logging.getLogger("zyro-daily-bill-sync")
DHAKA = ZoneInfo("Asia/Dhaka")
PAGE = 500
COLS = ["CreationTime", "CustomerHeaderId", "CustomerId", "UserName", "CustomerName", "MobileNumber", "Description",
        "EmpHeaderId", "MonthlyBill", "ReceiveAmount", "VatAmount", "DiscountAmount", "BalanceDue", "PaymentMethod",
        "ReceivedBy", "ApprovedBy", "CreatedBy"]


def _utc(local: datetime) -> datetime:
    return local.replace(tzinfo=DHAKA).astimezone(timezone.utc).replace(tzinfo=None)


def parse_created(s: str | None) -> datetime | None:
    """'11 Oct 2026 09:58 PM' (Bangladesh time) -> naive UTC."""
    try:
        return _utc(datetime.strptime((s or "").strip(), "%d %b %Y %I:%M %p"))
    except ValueError:
        return None


def parse_updated(s: str | None) -> datetime | None:
    """'11/10/2026 09:58:34' (dd/mm/yyyy, 24h, Bangladesh time) -> naive UTC."""
    try:
        return _utc(datetime.strptime((s or "").strip(), "%d/%m/%Y %H:%M:%S"))
    except ValueError:
        return None


def _num(v) -> float:
    """525 / '0.00' / '1,050.00'; billing writes a negative balance (advance) as '(520.00)'."""
    s = str(v if v is not None else "").replace(",", "").strip()
    neg = s.startswith("(") and s.endswith(")")
    try:
        n = float(s.strip("()")) if s else 0.0
    except ValueError:
        return 0.0
    return -n if neg else n


def fetch(api, status: str, frm: date, to: date) -> list[dict]:
    """All rows of the Daily Bill Collection list for a status ('' = approved + pending, or 'deleted')."""
    params = {"search[value]": "", "search[regex]": "false", "id": 0, "fromDateString": frm.strftime("%d-%m-%Y"),
              "toDateString": to.strftime("%d-%m-%Y"), "EmpHeaderId": "", "ApprovedById": "", "CreatedBy": "",
              "transStatus": status, "orderBy": "", "paymentMethod": "", "permissionId": 1}
    for i, c in enumerate(COLS):
        params.update({f"columns[{i}][data]": c, f"columns[{i}][name]": "", f"columns[{i}][searchable]": "true",
                       f"columns[{i}][orderable]": "false", f"columns[{i}][search][value]": "",
                       f"columns[{i}][search][regex]": "false"})
    rows, start = [], 0
    while True:
        d = api._get("/DailyAccountClosing/AjaxDailyBillQuery", {"draw": 1, "start": start, "length": PAGE, **params})
        batch = (d or {}).get("data") or []
        rows += batch
        start += PAGE
        if len(batch) < PAGE or start >= int((d or {}).get("iTotalDisplayRecords") or 0):
            return rows


def row_values(company_id: int, r: dict, deleted: bool) -> tuple:
    approved = bool(r.get("IsApproved")) and not deleted
    return (company_id, int(r["DailyBillId"]), int(r.get("CustomerHeaderId") or 0) or None, r.get("CustomerId"),
            r.get("UserName"), r.get("CustomerName"), r.get("MobileNumber"), r.get("Description"),
            int(r["EmpHeaderId"]) if r.get("EmpHeaderId") not in (None, "", 0) else None,
            (r.get("ReceivedBy") or "").strip() or None, (r.get("CreatedBy") or "").strip() or None,
            parse_created(r.get("CreationTime")), approved, (r.get("ApprovedBy") or "").strip() or None,
            parse_updated(r.get("UpdatedDate")), _num(r.get("MonthlyBill")), _num(r.get("ReceiveAmount")),
            _num(r.get("DiscountAmount")), _num(r.get("VatAmount")), _num(r.get("BalanceDue")),
            (r.get("PaymentMethod") or "").strip() or None, deleted or bool(r.get("IsDeleted")))


def sync_company(t, frm: date, to: date) -> dict:
    api = tenants.billing(t)
    live = fetch(api, "", frm, to)
    deleted = fetch(api, "deleted", frm, to)
    rows = [row_values(t.company_id, r, False) for r in live] + [row_values(t.company_id, r, True) for r in deleted]
    rows = [r for r in rows if r[11] is not None]  # without a time the row can't be filtered; billing always sends one
    with db.conn() as c, c.cursor() as cur:
        cur.executemany(
            """INSERT INTO billing_daily_bills (company_id, daily_bill_id, customer_header_id, customer_id, username,
                   customer_name, mobile, description, emp_header_id, received_by, created_by, collected_at, is_approved,
                   approved_by, billing_updated_at, monthly_bill, receive_amount, discount_amount, vat_amount, balance_due,
                   payment_method, is_deleted, synced_at)
               VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, now())
               ON CONFLICT (company_id, daily_bill_id) DO UPDATE SET customer_header_id = EXCLUDED.customer_header_id,
                   customer_id = EXCLUDED.customer_id, username = EXCLUDED.username, customer_name = EXCLUDED.customer_name,
                   mobile = EXCLUDED.mobile, description = EXCLUDED.description, emp_header_id = EXCLUDED.emp_header_id,
                   received_by = EXCLUDED.received_by, created_by = EXCLUDED.created_by, collected_at = EXCLUDED.collected_at,
                   is_approved = EXCLUDED.is_approved, approved_by = EXCLUDED.approved_by,
                   billing_updated_at = EXCLUDED.billing_updated_at, monthly_bill = EXCLUDED.monthly_bill,
                   receive_amount = EXCLUDED.receive_amount, discount_amount = EXCLUDED.discount_amount,
                   vat_amount = EXCLUDED.vat_amount, balance_due = EXCLUDED.balance_due,
                   payment_method = EXCLUDED.payment_method, is_deleted = EXCLUDED.is_deleted, synced_at = now()""", rows)
    # rows of the range that billing no longer lists at all (hard-removed) are marked deleted;
    # an empty answer is not trusted for that (a billing hiccup must not wipe a month)
    receipts = refresh_receipts(t, api)
    if not live:
        return {"from": str(frm), "to": str(to), "rows": 0, "deleted": len(deleted), "gone": 0, "receipts": receipts}
    seen = [r[1] for r in rows]
    gone = db.execute(
        """WITH u AS (UPDATE billing_daily_bills SET is_deleted = true, is_approved = false, synced_at = now()
                      WHERE company_id = %s AND collected_at >= %s AND collected_at < %s AND NOT is_deleted
                        AND NOT (daily_bill_id = ANY(%s)) RETURNING 1)
           SELECT count(*) AS n FROM u""",
        (t.company_id, _utc(datetime.combine(frm, datetime.min.time())),
         _utc(datetime.combine(to + timedelta(days=1), datetime.min.time())), seen))
    return {"from": str(frm), "to": str(to), "rows": len(live), "deleted": len(deleted), "gone": gone["n"],
            "receipts": receipts}


def refresh_receipts(t, api, limit: int = 30) -> int:
    """Bills approved in the last two days that the customer's received history (billing_customer_payments) doesn't
    have yet: read those customers' history again, which also pairs them and fills the receipt number."""
    from engine import customer_history
    rows = db.all_rows(
        """SELECT DISTINCT d.customer_header_id AS h FROM billing_daily_bills d
           WHERE d.company_id = %s AND d.is_approved AND NOT d.is_deleted AND d.customer_header_id IS NOT NULL
             AND d.billing_updated_at > now() - interval '2 days'
             AND NOT EXISTS (SELECT 1 FROM billing_customer_payments p WHERE p.company_id = d.company_id
                             AND p.daily_bill_id = d.daily_bill_id)
           LIMIT %s""", (t.company_id, limit))
    for r in rows:
        try:
            customer_history.refresh(t, r["h"], ["payments"], api)
        except Exception:
            log.warning("receipts of customer %s", r["h"], exc_info=True)
    return len(rows)


def date_range(days: int | None, month: str | None, frm: str | None, to: str | None) -> tuple[date, date]:
    today = datetime.now(DHAKA).date()
    if frm or to:
        f = datetime.strptime(frm, "%d-%m-%Y").date() if frm else today
        return f, (datetime.strptime(to, "%d-%m-%Y").date() if to else today)
    if month:
        first = datetime.strptime(month + "-01", "%Y-%m-%d").date()
        last = (first.replace(day=28) + timedelta(days=4)).replace(day=1) - timedelta(days=1)
        return first, min(last, today)
    return today - timedelta(days=(days or 7) - 1), today


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    logging.getLogger("httpx").setLevel(logging.WARNING)
    p = argparse.ArgumentParser()
    p.add_argument("--days", type=int, default=7)
    p.add_argument("--month", help="YYYY-MM, the whole month (up to today)")
    p.add_argument("--from", dest="frm", help="dd-mm-yyyy")
    p.add_argument("--to", help="dd-mm-yyyy")
    p.add_argument("--company", type=int)
    a = p.parse_args()
    frm, to = date_range(a.days, a.month, a.frm, a.to)
    for t in companies(a.company):
        try:
            log.info("company %s: %s", t.company_id, sync_company(t, frm, to))
        except Exception:
            log.exception("daily bill sync failed for company %s", t.company_id)


if __name__ == "__main__":
    main()
