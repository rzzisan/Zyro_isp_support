"""In-house billing, phase 0: fill the Desk's own ledger from the billing software's copies (read-only).

Source: the Desk's copies of billing that other jobs keep fresh (no billing request is made here):
  billing_options (kind packages)  -> desk_packages
  billing_customers                -> desk_customers         (no PPPoE password is copied)
  billing_customer_bills           -> desk_bills             (last `history_months` months, default 12)
  billing_customer_payments        -> desk_payments          (approved receipts; canceled kept as canceled)
  everything older                 -> one 'opening' ledger row per customer, dated the day before the window
then desk_ledger_entries (bill +, payment -, discount -) and desk_customer_balances (sum of the ledger), compared with
billing's own due. Rows copied from billing carry source 'ispdigital' and are refreshed on every run while billing is
the master (billing_mode ispdigital/shadow); the Desk's own rows are never touched. With billing_mode 'desk' the
import refuses to run.

  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.desk_billing --import [--company ID]
  ... --partitions [--months 3]       make the coming months' partitions (nightly)
  ... --verify-log --company ID       check the activity log's hash chain
"""
import argparse
import json
import logging

from psycopg.types.json import Jsonb

from engine import activity, db

log = logging.getLogger("zyro-desk-billing")
PARTITIONED = ("activity_logs", "desk_bills", "desk_payments", "desk_ledger_entries")
LOCK = 717172


def settings(company_id: int) -> dict:
    row = db.one("SELECT billing_mode, history_months FROM billing_settings WHERE company_id = %s", (company_id,))
    return row or {"billing_mode": "ispdigital", "history_months": 12}


def ensure_partitions(months: int = 3) -> dict:
    """This month and the next `months`; a month already sitting in the default partition is reported, not forced."""
    out = {}
    for t in PARTITIONED:
        try:
            out[t] = db.one("""SELECT desk_month_partitions(%s, date_trunc('month', now())::date,
                                   (date_trunc('month', now()) + make_interval(months => %s + 1))::date) AS n""",
                            (t, months))["n"]
        except Exception as e:
            log.exception("partitions for %s", t)
            out[t] = f"error: {e}"[:200]
    return out


# --- the import, one company, one transaction ------------------------------------------------------------------------
STEPS = [
    ("window", """CREATE TEMP TABLE _w ON COMMIT DROP AS SELECT
        (date_trunc('month', now() AT TIME ZONE 'Asia/Dhaka') - make_interval(months => %(months)s - 1))::date AS ws"""),

    ("packages", """INSERT INTO desk_packages (company_id, ext_id, name, mbps, price, active, source, created_at, updated_at)
        SELECT company_id, option_id, name,
               substring(coalesce(extra->>'mbps', '') from '([0-9]+(\\.[0-9]+)?)')::numeric,
               substring(coalesce(extra->>'price', '') from '([0-9]+(\\.[0-9]+)?)')::numeric,
               active, 'ispdigital', now(), now()
        FROM billing_options WHERE company_id = %(c)s AND kind = 'packages'
        ON CONFLICT (company_id, ext_id) DO UPDATE SET name = EXCLUDED.name, mbps = EXCLUDED.mbps,
            price = EXCLUDED.price, active = EXCLUDED.active, updated_at = now()
        WHERE desk_packages.source = 'ispdigital'"""),
    # a package a customer has but billing's package list doesn't (by name)
    ("packages_from_customers", """INSERT INTO desk_packages (company_id, ext_id, name, active, source, created_at, updated_at)
        SELECT DISTINCT ON (b.package) %(c)s, 'name:' || b.package, b.package, false, 'ispdigital', now(), now()
        FROM billing_customers b WHERE b.company_id = %(c)s AND coalesce(b.package, '') <> ''
          AND NOT EXISTS (SELECT 1 FROM desk_packages p WHERE p.company_id = %(c)s AND p.name = b.package)
        ON CONFLICT (company_id, ext_id) DO NOTHING"""),

    ("customers", """INSERT INTO desk_customers (company_id, header_id, customer_code, username, name, mobile, package_ref,
            package_name, monthly_bill, status, client_type, is_vip, expire_day, line_state, zone, joined_on, is_left,
            gone_at, source, created_at, updated_at)
        SELECT b.company_id, b.header_id, b.customer_id, b.username, b.name, b.mobile,
               (SELECT p.id FROM desk_packages p WHERE p.company_id = b.company_id AND p.name = b.package
                ORDER BY p.active DESC, p.id LIMIT 1),
               b.package, coalesce(b.monthly_bill, 0), b.status, b.customer_type, b.is_vip, b.bill_day,
               CASE WHEN b.disabled THEN 'off' ELSE 'on' END, b.zone, b.joined_on, b.is_left,
               b.gone_at AT TIME ZONE 'UTC', 'ispdigital', now(), now()
        FROM billing_customers b WHERE b.company_id = %(c)s
        ON CONFLICT (company_id, header_id) DO UPDATE SET customer_code = EXCLUDED.customer_code,
            username = EXCLUDED.username, name = EXCLUDED.name, mobile = EXCLUDED.mobile,
            package_ref = EXCLUDED.package_ref, package_name = EXCLUDED.package_name,
            monthly_bill = EXCLUDED.monthly_bill, status = EXCLUDED.status, client_type = EXCLUDED.client_type,
            is_vip = EXCLUDED.is_vip, expire_day = EXCLUDED.expire_day, line_state = EXCLUDED.line_state,
            zone = EXCLUDED.zone, joined_on = EXCLUDED.joined_on, is_left = EXCLUDED.is_left,
            gone_at = EXCLUDED.gone_at, updated_at = now()
        WHERE desk_customers.source = 'ispdigital'"""),

    # the imported ledger is rebuilt from the copies below (the Desk's own rows stay)
    ("ledger_clear", "DELETE FROM desk_ledger_entries WHERE company_id = %(c)s AND source = 'ispdigital'"),

    # bills of the window; billing keeps one bill per month, a second one for the same month is counted, not copied
    ("bills_src", """CREATE TEMP TABLE _bills ON COMMIT DROP AS
        SELECT DISTINCT ON (c.id, make_date(b.period_year, b.period_month, 1))
               c.id AS customer_ref, make_date(b.period_year, b.period_month, 1) AS ps, b.bill_master_id, b.bill_amount,
               b.paid, b.due, b.status, b.package
        FROM billing_customer_bills b
        JOIN desk_customers c ON c.company_id = b.company_id AND c.header_id = b.customer_header_id
        WHERE b.company_id = %(c)s AND b.removed_at IS NULL AND b.period_year > 2000 AND b.period_month BETWEEN 1 AND 12
          AND make_date(b.period_year, b.period_month, 1) >= (SELECT ws FROM _w)
        ORDER BY c.id, make_date(b.period_year, b.period_month, 1), b.bill_master_id DESC"""),
    ("bills_gone", """DELETE FROM desk_bills d WHERE d.company_id = %(c)s AND d.source = 'ispdigital'
        AND NOT EXISTS (SELECT 1 FROM _bills s WHERE s.bill_master_id = d.ext_id AND s.ps = d.period_start)"""),
    ("bills", """INSERT INTO desk_bills (company_id, customer_ref, period_start, period_end, amount, package_ref,
            package_name, status, source, ext_id, ext_status, created_at, updated_at)
        SELECT %(c)s, s.customer_ref, s.ps, (s.ps + interval '1 month - 1 day')::date, s.bill_amount,
               (SELECT p.id FROM desk_packages p WHERE p.company_id = %(c)s AND p.name = s.package
                ORDER BY p.active DESC, p.id LIMIT 1),
               s.package,
               CASE WHEN s.bill_amount <= 0 THEN 'waived' WHEN s.due <= 0 THEN 'paid' WHEN s.paid > 0 THEN 'partial'
                    ELSE 'open' END,
               'ispdigital', s.bill_master_id, s.status, now(), now()
        FROM _bills s
        ON CONFLICT (company_id, source, ext_id, period_start) DO UPDATE SET customer_ref = EXCLUDED.customer_ref,
            amount = EXCLUDED.amount, package_ref = EXCLUDED.package_ref, package_name = EXCLUDED.package_name,
            status = EXCLUDED.status, ext_status = EXCLUDED.ext_status, updated_at = now()"""),

    ("payments_src", """CREATE TEMP TABLE _pays ON COMMIT DROP AS
        SELECT c.id AS customer_ref, coalesce(p.received_on, p.entered_on) AS paid_on, p.*
        FROM billing_customer_payments p
        JOIN desk_customers c ON c.company_id = p.company_id AND c.header_id = p.customer_header_id
        WHERE p.company_id = %(c)s AND p.removed_at IS NULL
          AND coalesce(p.received_on, p.entered_on) >= (SELECT ws FROM _w)"""),
    ("payments_gone", """DELETE FROM desk_payments d WHERE d.company_id = %(c)s AND d.source = 'ispdigital'
        AND NOT EXISTS (SELECT 1 FROM _pays s WHERE s.bill_header_id = d.ext_id AND s.paid_on = d.paid_on)"""),
    ("payments", """INSERT INTO desk_payments (company_id, customer_ref, paid_on, amount, discount, method, gateway, trx_id,
            receipt_no, received_by, status, is_online, source, ext_id, note, created_at, updated_at)
        SELECT %(c)s, s.customer_ref, s.paid_on, s.paid, s.discount, s.method,
               CASE WHEN s.is_online THEN lower(coalesce(s.method_short, s.method)) END, s.trx_no, s.money_receipt_no,
               s.received_by, CASE WHEN s.is_canceled THEN 'canceled' ELSE 'approved' END, s.is_online, 'ispdigital',
               s.bill_header_id, s.remarks, now(), now()
        FROM _pays s
        ON CONFLICT (company_id, source, ext_id, paid_on) DO UPDATE SET customer_ref = EXCLUDED.customer_ref,
            amount = EXCLUDED.amount, discount = EXCLUDED.discount, method = EXCLUDED.method,
            gateway = EXCLUDED.gateway, trx_id = EXCLUDED.trx_id, receipt_no = EXCLUDED.receipt_no,
            received_by = EXCLUDED.received_by, status = EXCLUDED.status, is_online = EXCLUDED.is_online,
            note = EXCLUDED.note, updated_at = now()"""),

    # everything before the window, as one row per customer (all of billing's bills minus approved receipts)
    ("ledger_opening", """INSERT INTO desk_ledger_entries (company_id, customer_ref, entry_date, kind, amount, source,
            source_ref, note)
        SELECT %(c)s, c.id, (SELECT ws FROM _w) - 1, 'opening', o.amt, 'ispdigital', 'opening:' || c.id,
               'বিলিং-এর আগের হিসাব'
        FROM (SELECT h, sum(amt) AS amt FROM (
                SELECT customer_header_id AS h, bill_amount AS amt FROM billing_customer_bills
                WHERE company_id = %(c)s AND removed_at IS NULL AND period_year > 2000 AND period_month BETWEEN 1 AND 12
                  AND make_date(period_year, period_month, 1) < (SELECT ws FROM _w)
                UNION ALL
                SELECT customer_header_id, -(paid + discount) FROM billing_customer_payments
                WHERE company_id = %(c)s AND removed_at IS NULL AND NOT is_canceled
                  AND coalesce(received_on, entered_on) < (SELECT ws FROM _w)) x GROUP BY h) o
        JOIN desk_customers c ON c.company_id = %(c)s AND c.header_id = o.h
        WHERE o.amt <> 0"""),
    ("ledger_bills", """INSERT INTO desk_ledger_entries (company_id, customer_ref, entry_date, kind, amount, bill_ref,
            source, source_ref)
        SELECT company_id, customer_ref, period_start, 'bill', amount, id, 'ispdigital', 'bill:' || ext_id
        FROM desk_bills WHERE company_id = %(c)s AND source = 'ispdigital' AND amount <> 0"""),
    ("ledger_payments", """INSERT INTO desk_ledger_entries (company_id, customer_ref, entry_date, kind, amount, payment_ref,
            source, source_ref)
        SELECT company_id, customer_ref, paid_on, k.kind, -k.amt, id, 'ispdigital', k.pre || ext_id
        FROM desk_payments, LATERAL (VALUES ('payment', amount, 'pay:'), ('discount', discount, 'disc:')) k(kind, amt, pre)
        WHERE company_id = %(c)s AND source = 'ispdigital' AND status = 'approved' AND k.amt <> 0"""),

    ("balances", """INSERT INTO desk_customer_balances (customer_ref, company_id, balance, last_bill_period,
            last_payment_on, billing_due, has_history, updated_at)
        SELECT c.id, c.company_id, coalesce(l.bal, 0), l.last_bill, l.last_pay, b.due,
               EXISTS (SELECT 1 FROM billing_customer_fetches f WHERE f.company_id = c.company_id
                       AND f.customer_header_id = c.header_id AND f.section = 'bills' AND f.fetched_at IS NOT NULL),
               now()
        FROM desk_customers c
        LEFT JOIN billing_customers b ON b.company_id = c.company_id AND b.header_id = c.header_id
        LEFT JOIN (SELECT customer_ref, sum(amount) AS bal, max(entry_date) FILTER (WHERE kind = 'bill') AS last_bill,
                          max(entry_date) FILTER (WHERE kind = 'payment') AS last_pay
                   FROM desk_ledger_entries WHERE company_id = %(c)s GROUP BY customer_ref) l ON l.customer_ref = c.id
        WHERE c.company_id = %(c)s
        ON CONFLICT (customer_ref) DO UPDATE SET balance = EXCLUDED.balance, last_bill_period = EXCLUDED.last_bill_period,
            last_payment_on = EXCLUDED.last_payment_on, billing_due = EXCLUDED.billing_due,
            has_history = EXCLUDED.has_history, updated_at = now()"""),
]

STATS = """
SELECT (SELECT ws FROM _w)::text AS window_start,
  (SELECT count(*) FROM desk_customers WHERE company_id = %(c)s) AS customers,
  (SELECT count(*) FROM desk_customers WHERE company_id = %(c)s AND status = 'Active' AND NOT is_left) AS active,
  (SELECT count(*) FROM desk_packages WHERE company_id = %(c)s) AS packages,
  (SELECT count(*) FROM desk_bills WHERE company_id = %(c)s AND source = 'ispdigital') AS bills,
  (SELECT coalesce(sum(amount), 0) FROM desk_bills WHERE company_id = %(c)s AND source = 'ispdigital') AS bills_total,
  (SELECT count(*) FROM desk_payments WHERE company_id = %(c)s AND source = 'ispdigital') AS payments,
  (SELECT coalesce(sum(amount), 0) FROM desk_payments WHERE company_id = %(c)s AND source = 'ispdigital'
     AND status = 'approved') AS payments_total,
  (SELECT count(*) FROM desk_ledger_entries WHERE company_id = %(c)s) AS ledger_rows,
  (SELECT count(*) FROM billing_customer_bills b WHERE b.company_id = %(c)s AND b.removed_at IS NULL
     AND b.period_year > 2000 AND b.period_month BETWEEN 1 AND 12
     AND make_date(b.period_year, b.period_month, 1) >= (SELECT ws FROM _w)) - (SELECT count(*) FROM _bills) AS bills_same_month,
  (SELECT count(*) FROM billing_customer_bills WHERE company_id = %(c)s AND removed_at IS NULL
     AND NOT (coalesce(period_year, 0) > 2000 AND period_month BETWEEN 1 AND 12)) AS bills_without_month,
  (SELECT count(*) FROM desk_customer_balances WHERE company_id = %(c)s AND has_history) AS with_history,
  (SELECT count(*) FROM desk_customer_balances WHERE company_id = %(c)s AND has_history
     AND abs(balance - coalesce(billing_due, 0)) < 1) AS matched,
  (SELECT coalesce(sum(balance) FILTER (WHERE balance > 0), 0) FROM desk_customer_balances
     WHERE company_id = %(c)s AND has_history) AS desk_due,
  (SELECT coalesce(sum(billing_due) FILTER (WHERE billing_due > 0), 0) FROM desk_customer_balances
     WHERE company_id = %(c)s AND has_history) AS billing_due
"""


def _num(v):
    return float(v) if v is not None and not isinstance(v, (int, str)) else v


def import_company(company_id: int, user_id: int | None = None, source: str = "timer") -> dict:
    """The whole copy in one transaction (a failure leaves the last good copy). One run per company at a time."""
    s = settings(company_id)
    if s["billing_mode"] == "desk":
        raise RuntimeError("Desk is the billing master now: the import from billing is off")
    run = db.one("""INSERT INTO desk_billing_runs (company_id, kind, status, user_id, started_at)
                    VALUES (%s, 'import', 'running', %s, now()) RETURNING id""", (company_id, user_id))["id"]
    try:
        with db.conn() as c:
            if not c.execute("SELECT pg_try_advisory_lock(%s, %s) AS ok", (LOCK, company_id)).fetchone()["ok"]:
                raise RuntimeError("আগের ইমপোর্ট এখনো চলছে")
            try:
                with c.transaction():
                    c.execute("SET LOCAL statement_timeout = '15min'")
                    p = {"c": company_id, "months": int(s["history_months"] or 12)}
                    rows = {}
                    for name, sql in STEPS:
                        cur = c.execute(sql, p)
                        rows[name] = cur.rowcount
                    stats = {k: _num(v) for k, v in c.execute(STATS, p).fetchone().items()}
                    stats["rows"] = rows
                    stats["match_percent"] = round(100 * stats["matched"] / stats["with_history"], 2) if stats["with_history"] else None
                    activity.record(company_id, "billing.import", actor="system" if not user_id else "user",
                                    source=source, user_id=user_id, subject_type="desk_billing_run", subject_id=run,
                                    note=f"বিলিং থেকে কপি: {stats['customers']} কাস্টমার, {stats['bills']} বিল, "
                                         f"{stats['payments']} পেমেন্ট; মিল {stats['match_percent']}%", conn=c)
            finally:
                c.execute("SELECT pg_advisory_unlock(%s, %s)", (LOCK, company_id))
    except Exception as e:
        db.execute("UPDATE desk_billing_runs SET status = 'failed', error = %s, finished_at = now() WHERE id = %s",
                   (str(e)[:1000], run))
        raise
    db.execute("UPDATE desk_billing_runs SET status = 'done', stats = %s, finished_at = now() WHERE id = %s",
               (Jsonb(stats), run))
    return stats


def companies() -> list[int]:
    return [r["company_id"] for r in db.all_rows(
        """SELECT DISTINCT b.company_id FROM billing_customers b JOIN companies c ON c.id = b.company_id
           WHERE c.status IN ('trial', 'active') ORDER BY 1""")]


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    ap = argparse.ArgumentParser()
    ap.add_argument("--import", dest="do_import", action="store_true")
    ap.add_argument("--partitions", action="store_true")
    ap.add_argument("--months", type=int, default=3)
    ap.add_argument("--verify-log", action="store_true")
    ap.add_argument("--company", type=int)
    a = ap.parse_args()
    if a.partitions:
        log.info("partitions: %s", ensure_partitions(a.months))
    if a.do_import:
        for cid in [a.company] if a.company else companies():
            if settings(cid)["billing_mode"] == "desk":
                continue
            try:
                log.info("import company %s: %s", cid, json.dumps(import_company(cid), default=str))
            except Exception:
                log.exception("import failed for company %s", cid)
    if a.verify_log:
        print(json.dumps(activity.verify(a.company), default=str))


if __name__ == "__main__":
    main()
