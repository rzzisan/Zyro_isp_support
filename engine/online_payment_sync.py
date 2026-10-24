"""Copy the online payments of each company's billing software into billing_online_payments.

Billing keeps two lists on the Daily Bill page (Daily Bill Collection shows neither): "Webhook Payments" (bKash/Nagad
merchant webhook: the customer sends money to the merchant number with the customer code as reference) and "Paybill
Payments" (bKash PayBill with the customer code). Billing settles a payment by itself when the reference is a customer
code ("Automatically Settled"); otherwise it waits as "Payment Requested" until staff settle it by hand in billing.
Desk only reads; settling stays in billing.

Rows are keyed by billing's NotificationId / InfoId. The customer is found from the code (PayBill CustomerCode, or a
webhook reference that is a customer ID). New "Payment Requested" rows push a browser alert to owners/admins once.
The customer's received history (billing_customer_payments) has no transaction id; trx_no is filled from these rows
(same customer and amount, received within a day).

Run every 3 minutes by zyro-online-payment-sync.timer for the last --days days; backfill with --from dd-mm-yyyy.
  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.online_payment_sync [--days 2] [--from 01-01-2020] [--company ID]
"""
import argparse
import json
import logging
import re
import threading
from datetime import date, datetime, timedelta, timezone
from zoneinfo import ZoneInfo

from engine import db, tenant as tenants
from engine.ticket_sync import companies

log = logging.getLogger("zyro-online-payment-sync")
DHAKA = ZoneInfo("Asia/Dhaka")
PAGE = 2000
SOURCES = {"webhook": "/DailyAccountClosing/WebhookPaymentNotifications",
           "paybill": "/DailyAccountClosing/PaybillPaymentTransactions"}
# billing's TypeFor prefix -> gateway name as billing writes it in the payment method list
GATEWAYS = {"BKASH": "bKash", "NAGAD": "Nagad", "ROCKET": "Rocket", "SSL_COMMERZ": "SSLCommerz",
            "FOSTER_PAYMENT": "Foster Payments", "WALLETMIX": "Walletmix", "SHURJOPAY": "shurjoPay"}
REQUESTED = 1  # StatusId "Payment Requested": arrived, not settled to a customer yet


def _utc(local: datetime) -> datetime:
    return local.replace(tzinfo=DHAKA).astimezone(timezone.utc).replace(tzinfo=None)


def parse_stamp(s) -> datetime | None:
    """'20261024222043' (yyyyMMddHHmmss, Bangladesh time) -> naive UTC."""
    try:
        return _utc(datetime.strptime(str(s or "").strip(), "%Y%m%d%H%M%S"))
    except ValueError:
        return None


def parse_billing(s) -> datetime | None:
    """'24-Oct-26 10:20:43 PM' (Bangladesh time) -> naive UTC."""
    try:
        return _utc(datetime.strptime(str(s or "").strip(), "%d-%b-%y %I:%M:%S %p"))
    except ValueError:
        return None


def parse_iso(s) -> datetime | None:
    """'2026-10-24T16:20:43.348Z' (UTC) -> naive UTC."""
    try:
        return datetime.fromisoformat(str(s or "").strip().replace("Z", "+00:00")).astimezone(timezone.utc).replace(tzinfo=None)
    except ValueError:
        return None


def _num(v) -> float:
    try:
        return float(str(v if v is not None else "").replace(",", "").strip() or 0)
    except ValueError:
        return 0.0


def _txt(v) -> str | None:
    s = str(v).strip() if v is not None else ""
    return s or None


def gateway(type_for: str | None) -> str | None:
    t = (type_for or "").upper()
    for prefix, name in GATEWAYS.items():
        if t.startswith(prefix):
            return name
    return _txt(type_for)


def code_of(ref: str | None) -> str | None:
    """A reference that is a customer ID ('0904', ' 904 ') -> '904' (no leading zeros), else None."""
    s = (ref or "").strip()
    if not re.fullmatch(r"\d{1,8}", s):
        return None
    return s.lstrip("0") or "0"


def fetch(api, source: str, frm: date, to: date) -> list[dict]:
    """Every row of one list for a date range (billing filters on the row's CreatedOn day)."""
    params = {"fromDateString": frm.strftime("%d-%m-%Y"), "toDateString": to.strftime("%d-%m-%Y"), "statusId": "",
              "transTypeId": "", "permissionId": 1, "ClientsOf": "", "macResellerId": 0, "orderBy": ""}
    rows, start = [], 0
    while True:
        d = api._get(SOURCES[source], {"draw": 1, "start": start, "length": PAGE, **params}) or {}
        batch = d.get("aaData") or d.get("data") or []
        rows += batch
        start += PAGE
        if len(batch) < PAGE or start >= int(d.get("iTotalDisplayRecords") or 0):
            return rows


def row_values(company_id: int, source: str, r: dict) -> tuple | None:
    if source == "webhook":
        rid, trx, payer, ref, code = r.get("NotificationId"), r.get("TrxId"), r.get("FromWallet"), r.get("TrxRef"), None
        merchant, trx_status, trx_type = r.get("CreditShortCode"), r.get("TrxStatus"), r.get("TransType")
        paid = parse_stamp(r.get("TransTimeStamp")) or parse_iso(r.get("NotiTimeStamp")) or parse_billing(r.get("CreatedOn"))
        updated = parse_billing(r.get("UpdatedDate"))
    else:
        rid, trx, payer, ref, code = r.get("InfoId"), r.get("TrxID"), r.get("DebitMSISDN"), r.get("TransactionReference"), r.get("CustomerCode")
        merchant, trx_status, trx_type = r.get("CreditShortCode"), r.get("TransactionStatus"), r.get("TransactionType")
        paid = parse_stamp(r.get("TimeStamp")) or parse_billing(r.get("CreatedOn"))
        updated = parse_billing(r.get("UpdatedOn") or r.get("UpdateDate"))
    if not rid or not paid:
        return None
    return (company_id, source, int(rid), gateway(r.get("TypeFor")), _txt(r.get("TypeFor")), _txt(trx), _num(r.get("Amount")),
            _txt(r.get("Currency")), _txt(payer), _txt(merchant), _txt(ref), _txt(code), code_of(code) or code_of(ref),
            int(r["StatusId"]) if r.get("StatusId") not in (None, "") else None, _txt(r.get("Status")), _txt(trx_status),
            _txt(trx_type), paid, parse_billing(r.get("CreatedOn")), updated, _txt(r.get("UpdateBy")),
            _txt(r.get("RemarksOrNote")))


UPSERT = """INSERT INTO billing_online_payments (company_id, source, billing_row_id, gateway, type_for, trx_id, amount,
                currency, payer, merchant, reference, customer_code, match_code, status_id, status, trx_status, trx_type,
                paid_at, billing_created_at, billing_updated_at, updated_by, note, synced_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, now())
            ON CONFLICT (company_id, source, billing_row_id) DO UPDATE SET gateway = EXCLUDED.gateway,
                type_for = EXCLUDED.type_for, trx_id = EXCLUDED.trx_id, amount = EXCLUDED.amount,
                currency = EXCLUDED.currency, payer = EXCLUDED.payer, merchant = EXCLUDED.merchant,
                reference = EXCLUDED.reference, customer_code = EXCLUDED.customer_code, match_code = EXCLUDED.match_code,
                status_id = EXCLUDED.status_id, status = EXCLUDED.status, trx_status = EXCLUDED.trx_status,
                trx_type = EXCLUDED.trx_type, paid_at = EXCLUDED.paid_at, billing_created_at = EXCLUDED.billing_created_at,
                billing_updated_at = EXCLUDED.billing_updated_at, updated_by = EXCLUDED.updated_by, note = EXCLUDED.note,
                synced_at = now()"""


def match_customers(company_id: int) -> int:
    """Rows with a customer-ID reference get the customer (current lines first, then Left ones)."""
    r = db.execute(
        """WITH m AS (
               SELECT DISTINCT ON (p.id) p.id, c.header_id, c.customer_id FROM billing_online_payments p
               JOIN billing_customers c ON c.company_id = p.company_id
                    AND nullif(ltrim(c.customer_id, '0'), '') = p.match_code
               WHERE p.company_id = %s AND p.match_code IS NOT NULL AND p.customer_header_id IS NULL
               ORDER BY p.id, (c.status = 'Left'), c.header_id DESC)
           , u AS (UPDATE billing_online_payments p SET customer_header_id = m.header_id, customer_id = m.customer_id
                   FROM m WHERE p.id = m.id RETURNING 1)
           SELECT count(*) AS n FROM u""", (company_id,))
    return r["n"] if r else 0


def fill_trx_numbers(company_id: int, days: int = 3) -> int:
    """Gateway receipts in the customer's received history get the transaction id of the online payment with the
    same customer and amount received the same day (Bangladesh time) or the day after; each one used once."""
    r = db.execute(
        """WITH cand AS (
               SELECT cp.id AS cp_id, op.id AS op_id, op.trx_id,
                      row_number() OVER (PARTITION BY cp.id ORDER BY abs(cp.received_on - (op.paid_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Dhaka')::date), op.paid_at) AS rn_cp,
                      row_number() OVER (PARTITION BY op.id ORDER BY cp.received_on, cp.id) AS rn_op
               FROM billing_customer_payments cp
               JOIN billing_online_payments op ON op.company_id = cp.company_id
                    AND op.customer_header_id = cp.customer_header_id AND op.amount = cp.paid
                    AND lower(op.gateway) = lower(cp.method) AND op.trx_id IS NOT NULL
                    AND cp.received_on BETWEEN (op.paid_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Dhaka')::date
                                           AND (op.paid_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Dhaka')::date + 1
               WHERE cp.company_id = %s AND cp.is_online AND NOT cp.is_canceled AND coalesce(cp.trx_no, '') = ''
                 AND cp.received_on >= current_date - %s
                 AND NOT EXISTS (SELECT 1 FROM billing_customer_payments x WHERE x.company_id = cp.company_id
                                 AND x.trx_no = op.trx_id))
           , u AS (UPDATE billing_customer_payments cp SET trx_no = cand.trx_id FROM cand
                   WHERE cp.id = cand.cp_id AND cand.rn_cp = 1 AND cand.rn_op = 1 RETURNING 1)
           SELECT count(*) AS n FROM u""", (company_id, days))
    return r["n"] if r else 0


def sync_company(t, frm: date, to: date, alert: bool = True) -> dict:
    api = tenants.billing(t)
    out = {"from": str(frm), "to": str(to)}
    for source in SOURCES:
        rows = [v for v in (row_values(t.company_id, source, r) for r in fetch(api, source, frm, to)) if v]
        if rows:
            with db.conn() as c, c.cursor() as cur:
                cur.executemany(UPSERT, rows)
        out[source] = len(rows)
    out["matched"] = match_customers(t.company_id)
    out["trx_filled"] = fill_trx_numbers(t.company_id, max(3, (to - frm).days + 2))
    out["alerts"] = alert_requested(t.company_id, push=alert)
    return out


def alert_requested(company_id: int, push: bool = True) -> int:
    """New "Payment Requested" rows of the last day: one browser push per row to owners/admins. A backfill
    (push=False) only marks them, so old ones never alert later."""
    rows = db.all_rows(
        """UPDATE billing_online_payments SET alerted_at = now()
           WHERE company_id = %s AND status_id = %s AND alerted_at IS NULL
             AND (paid_at > now() - interval '1 day' OR NOT %s)
           RETURNING id, source, billing_row_id, gateway, amount, reference""", (company_id, REQUESTED, push))
    if rows and push:
        threading.Thread(target=_push, args=(company_id, rows), daemon=True).start()
    return len(rows)


def _push(company_id: int, rows: list[dict]) -> None:
    try:
        from engine import webpush
        subs = db.all_rows(
            """SELECT s.id, s.endpoint, s.p256dh, s.auth, c.slug FROM push_subscriptions s
               JOIN companies c ON c.id = s.company_id
               JOIN company_user cu ON cu.company_id = s.company_id AND cu.user_id = s.user_id
               WHERE s.company_id = %s AND cu.role IN ('owner', 'admin')""", (company_id,))
        if not subs:
            return
        from pywebpush import WebPushException, webpush as send
        _, priv = webpush.keys()
        for r in rows:
            amount = f"{float(r['amount']):g}"
            payload = json.dumps({
                "title": f"{r['gateway'] or ''} পেমেন্ট সেটেল হয়নি: {amount} ৳".strip(),
                "body": f"রেফারেন্স: {r['reference'] or '—'} · বিলিং-এ হাতে সেটেল করতে হবে",
                "tag": f"pay-{r['source']}-{r['billing_row_id']}",
                "url": f"/app/{subs[0]['slug']}/online-payments?tableFilters[status][value]=1",
            }, ensure_ascii=False)
            for s in subs:
                try:
                    send({"endpoint": s["endpoint"], "keys": {"p256dh": s["p256dh"], "auth": s["auth"]}}, payload,
                         vapid_private_key=priv, vapid_claims={"sub": webpush.SUBJECT}, ttl=6 * 3600, timeout=10)
                except WebPushException as e:
                    if getattr(e.response, "status_code", None) in (404, 410):
                        db.execute("DELETE FROM push_subscriptions WHERE id = %s", (s["id"],))
                    else:
                        log.warning("payment push to subscription %s failed: %s", s["id"], str(e)[:200])
    except Exception:
        log.exception("payment push failed for company %s", company_id)


def date_range(days: int | None, frm: str | None, to: str | None) -> tuple[date, date]:
    today = datetime.now(DHAKA).date()
    if frm or to:
        f = datetime.strptime(frm, "%d-%m-%Y").date() if frm else today
        return f, (datetime.strptime(to, "%d-%m-%Y").date() if to else today)
    return today - timedelta(days=(days or 2) - 1), today


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    logging.getLogger("httpx").setLevel(logging.WARNING)
    p = argparse.ArgumentParser()
    p.add_argument("--days", type=int, default=2)
    p.add_argument("--from", dest="frm", help="dd-mm-yyyy")
    p.add_argument("--to", help="dd-mm-yyyy")
    p.add_argument("--company", type=int)
    p.add_argument("--no-alert", action="store_true", help="backfill: don't push alerts")
    a = p.parse_args()
    frm, to = date_range(a.days, a.frm, a.to)
    for t in companies(a.company):
        try:
            log.info("company %s: %s", t.company_id, sync_company(t, frm, to, alert=not a.no_alert))
        except Exception:
            log.exception("online payment sync failed for company %s", t.company_id)


if __name__ == "__main__":
    main()
