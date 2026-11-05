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
  ... --shadow                        phase 1: on the 1st make this month's shadow bills, then compare the last months
  ... --shadow-bills --period 2026-12 [--replace]   make one month's shadow bills by hand (and compare)
  ... --disable-shadow                phase 2, hourly: at the disable hour today's "would turn off" list, then compare
  ... --disable-list [--day 2026-11-06] [--replace]  make one day's list by hand (engine/desk_disable.py)

Phase 1 (ছায়া হিসাব), still read-only: every copy also notes which customers' due doesn't match billing, why and since
when (desk_customer_balances.diff / mismatch_reason / mismatch_since). On the 1st the Desk makes its own monthly bills
into desk_shadow_bills (never into the ledger or billing while billing is the master) and every night compares them with
billing's bill for the same month as the history copy brings them.
"""
import argparse
import datetime as dt
import json
import logging
from decimal import Decimal

from psycopg.types.json import Jsonb

from engine import activity, db

log = logging.getLogger("zyro-desk-billing")
PARTITIONED = ("activity_logs", "desk_bills", "desk_payments", "desk_ledger_entries")
LOCK = 717172


def settings(company_id: int) -> dict:
    row = db.one("""SELECT billing_mode, history_months, coalesce(match_exclude_codes, '[]'::jsonb) AS exclude
                    FROM billing_settings WHERE company_id = %s""", (company_id,))
    return row or {"billing_mode": "ispdigital", "history_months": 12, "exclude": []}


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
            last_payment_on, billing_due, has_history, excluded, updated_at)
        SELECT c.id, c.company_id, coalesce(l.bal, 0), l.last_bill, l.last_pay,
               -- billing's own figure: due minus the separate advance column (an empty due is 0: paid up). Only for
               -- Inactive/Free/Personal does billing's list leave due/payable empty for real: their latest bill row
               CASE WHEN b.status IN ('Inactive', 'Free', 'Personal') AND b.payable IS NULL THEN
                    coalesce((SELECT x.due FROM billing_customer_bills x WHERE x.company_id = c.company_id
                                AND x.customer_header_id = c.header_id AND x.removed_at IS NULL
                              ORDER BY x.period_year DESC NULLS LAST, x.period_month DESC NULLS LAST,
                                       x.bill_master_id DESC LIMIT 1), 0)
                    ELSE coalesce(b.due, 0) - coalesce(b.advance, 0) END,
               EXISTS (SELECT 1 FROM billing_customer_fetches f WHERE f.company_id = c.company_id
                       AND f.customer_header_id = c.header_id AND f.section = 'bills' AND f.fetched_at IS NOT NULL),
               coalesce(c.customer_code = ANY(%(exclude)s::text[]), false), now()
        FROM desk_customers c
        LEFT JOIN billing_customers b ON b.company_id = c.company_id AND b.header_id = c.header_id
        LEFT JOIN (SELECT customer_ref, sum(amount) AS bal, max(entry_date) FILTER (WHERE kind = 'bill') AS last_bill,
                          max(entry_date) FILTER (WHERE kind = 'payment') AS last_pay
                   FROM desk_ledger_entries WHERE company_id = %(c)s GROUP BY customer_ref) l ON l.customer_ref = c.id
        WHERE c.company_id = %(c)s
        ON CONFLICT (customer_ref) DO UPDATE SET balance = EXCLUDED.balance, last_bill_period = EXCLUDED.last_bill_period,
            last_payment_on = EXCLUDED.last_payment_on, billing_due = EXCLUDED.billing_due,
            has_history = EXCLUDED.has_history, excluded = EXCLUDED.excluded, updated_at = now()"""),

    # receipts billing approved in the last 3 days that the nightly history copy hasn't brought yet
    ("recent_receipts", """CREATE TEMP TABLE _recent ON COMMIT DROP AS
        SELECT c.id AS customer_ref, sum(d.receive_amount + d.discount_amount) AS amt
        FROM billing_daily_bills d JOIN desk_customers c ON c.company_id = d.company_id AND c.header_id = d.customer_header_id
        WHERE d.company_id = %(c)s AND d.is_approved AND NOT d.is_deleted AND d.collected_at > now() - interval '3 days'
          AND NOT EXISTS (SELECT 1 FROM billing_customer_payments p WHERE p.company_id = d.company_id
                          AND p.daily_bill_id = d.daily_bill_id)
        GROUP BY 1"""),
    # phase 1: what doesn't match, a likely reason, and since which morning
    ("mismatch", """UPDATE desk_customer_balances x SET
            diff = CASE WHEN z.off THEN x.balance - coalesce(x.billing_due, 0) END,
            recent_receipts = z.recent,
            mismatch_reason = CASE WHEN NOT z.off THEN NULL
                WHEN z.recent IS NOT NULL AND abs(x.balance - z.recent - coalesce(x.billing_due, 0)) < 1 THEN 'recent_receipt'
                WHEN z.is_left THEN 'left'
                WHEN coalesce(z.status, '') <> 'Active' THEN 'not_active'
                WHEN coalesce(z.advance, 0) > 0 THEN 'advance'
                ELSE 'other' END,
            mismatch_since = CASE WHEN z.off THEN coalesce(x.mismatch_since, (now() AT TIME ZONE 'Asia/Dhaka')::date) END
        FROM (SELECT y.customer_ref, abs(y.balance - coalesce(y.billing_due, 0)) >= 1 AS off, r.amt AS recent,
                     c.is_left, coalesce(b.status, c.status) AS status, b.advance
              FROM desk_customer_balances y JOIN desk_customers c ON c.id = y.customer_ref
              LEFT JOIN billing_customers b ON b.company_id = c.company_id AND b.header_id = c.header_id
              LEFT JOIN _recent r ON r.customer_ref = y.customer_ref
              WHERE y.company_id = %(c)s) z
        WHERE x.customer_ref = z.customer_ref"""),
]

STATS = """
SELECT (SELECT ws FROM _w)::text AS window_start,
  (SELECT count(*) FROM desk_customer_balances WHERE company_id = %(c)s AND excluded) AS excluded,
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
  (SELECT count(*) FROM desk_customer_balances WHERE company_id = %(c)s AND has_history AND NOT excluded) AS with_history,
  (SELECT count(*) FROM desk_customer_balances WHERE company_id = %(c)s AND has_history AND NOT excluded
     AND abs(balance - coalesce(billing_due, 0)) < 1) AS matched,
  (SELECT count(*) FROM desk_customer_balances x JOIN desk_customers c ON c.id = x.customer_ref
     JOIN (SELECT customer_header_id AS h, sum(receive_amount + discount_amount) AS amt FROM billing_daily_bills d
           WHERE d.company_id = %(c)s AND d.is_approved AND NOT d.is_deleted AND d.collected_at > now() - interval '3 days'
             AND NOT EXISTS (SELECT 1 FROM billing_customer_payments p WHERE p.company_id = d.company_id
                             AND p.daily_bill_id = d.daily_bill_id)
           GROUP BY 1) t ON t.h = c.header_id
     WHERE x.company_id = %(c)s AND x.has_history AND NOT x.excluded AND abs(x.balance - coalesce(x.billing_due, 0)) >= 1
       AND abs(x.balance - t.amt - coalesce(x.billing_due, 0)) < 1) AS matched_after_todays_receipts,
  (SELECT coalesce(sum(balance) FILTER (WHERE balance > 0), 0) FROM desk_customer_balances
     WHERE company_id = %(c)s AND has_history AND NOT excluded) AS desk_due,
  (SELECT coalesce(sum(billing_due) FILTER (WHERE billing_due > 0), 0) FROM desk_customer_balances
     WHERE company_id = %(c)s AND has_history AND NOT excluded) AS billing_due,
  (SELECT coalesce(jsonb_object_agg(r, n), '{}'::jsonb) FROM (SELECT mismatch_reason AS r, count(*) AS n
     FROM desk_customer_balances WHERE company_id = %(c)s AND has_history AND NOT excluded AND diff IS NOT NULL
     GROUP BY 1) z) AS mismatch_reasons
"""


def _num(v):
    return float(v) if isinstance(v, Decimal) else v


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
                    p = {"c": company_id, "months": int(s["history_months"] or 12),
                         "exclude": [str(x).strip() for x in (s.get("exclude") or []) if str(x).strip()]}
                    rows = {}
                    for name, sql in STEPS:
                        cur = c.execute(sql, p)
                        rows[name] = cur.rowcount
                    stats = {k: _num(v) for k, v in c.execute(STATS, p).fetchone().items()}
                    stats["rows"] = rows
                    stats["match_percent"] = round(100 * stats["matched"] / stats["with_history"], 2) if stats["with_history"] else None
                    # receipts billing approved that the nightly history copy hasn't brought yet: they match tomorrow
                    stats["match_percent_with_receipts"] = round(
                        100 * (stats["matched"] + stats["matched_after_todays_receipts"]) / stats["with_history"], 2) if stats["with_history"] else None
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


# --- phase 1: the Desk's own monthly bills, in the shadow -------------------------------------------------------------
# Billing's rule (plan 1.2): on the 1st every customer who is Active that morning gets the full monthly_bill, no
# prorate; a customer who joined this month gets nothing (first bill next 1st); Inactive/Free/Personal/Left get 0.
SHADOW_MAKE = """INSERT INTO desk_shadow_bills (company_id, period_start, customer_ref, run_id, desk_amount, desk_reason,
        customer_status, package_name, monthly_bill, created_at)
    SELECT c.company_id, %(ps)s, c.id, %(run)s, CASE WHEN r.reason = 'billed' THEN c.monthly_bill ELSE 0 END, r.reason,
           c.status, c.package_name, c.monthly_bill, now()
    FROM desk_customers c,
         LATERAL (SELECT CASE WHEN c.is_left OR c.status = 'Left' THEN 'left'
                              WHEN coalesce(c.status, '') <> 'Active' THEN 'not_active'
                              WHEN c.joined_on >= %(ps)s THEN 'joined_this_month'
                              WHEN coalesce(c.monthly_bill, 0) <= 0 THEN 'zero_bill'
                              ELSE 'billed' END AS reason) r
    WHERE c.company_id = %(c)s AND (c.gone_at IS NULL OR c.gone_at >= %(ps)s)
    ON CONFLICT (company_id, period_start, customer_ref) DO NOTHING"""

SHADOW_COMPARE = [
    # billing's bill of that month (the latest BillMasterId if there are two)
    ("billing_bills", """CREATE TEMP TABLE _bb ON COMMIT DROP AS
        SELECT DISTINCT ON (c.id) c.id AS customer_ref, b.bill_amount, b.bill_master_id, b.package,
               coalesce(jsonb_typeof(b.versions) = 'array' AND jsonb_array_length(b.versions) > 0, false) AS edited
        FROM billing_customer_bills b
        JOIN desk_customers c ON c.company_id = b.company_id AND c.header_id = b.customer_header_id
        WHERE b.company_id = %(c)s AND b.removed_at IS NULL
          AND b.period_year = extract(year FROM %(ps)s::date) AND b.period_month = extract(month FROM %(ps)s::date)
        ORDER BY c.id, b.bill_master_id DESC"""),
    # a customer billing billed whom the Desk didn't know when it made the month's bills
    ("billing_only", """INSERT INTO desk_shadow_bills (company_id, period_start, customer_ref, desk_reason, created_at)
        SELECT %(c)s, %(ps)s, bb.customer_ref, 'not_in_desk', now() FROM _bb bb
        ON CONFLICT (company_id, period_start, customer_ref) DO NOTHING"""),
    ("compare", """UPDATE desk_shadow_bills s SET billing_amount = z.bill_amount, billing_ext_id = z.bill_master_id,
            billing_package = z.package, compared_at = now(),
            result = CASE
                WHEN z.bill_master_id IS NULL AND coalesce(s.desk_amount, 0) = 0 THEN 'match'
                WHEN z.bill_master_id IS NULL AND (z.fetched_at IS NULL OR z.fetched_at < %(ps)s::timestamp - interval '6 hours')
                     THEN 'pending'
                WHEN z.bill_master_id IS NULL THEN 'desk_only'
                WHEN s.desk_amount IS NULL THEN CASE WHEN z.bill_amount = 0 THEN 'match' ELSE 'billing_only' END
                WHEN abs(s.desk_amount - z.bill_amount) < 1 THEN 'match'
                ELSE 'amount_diff' END,
            hint = CASE
                WHEN s.desk_amount IS NULL THEN NULL
                WHEN z.bill_master_id IS NOT NULL AND s.desk_amount IS NOT NULL AND abs(s.desk_amount - z.bill_amount) < 1 THEN NULL
                WHEN z.bill_master_id IS NULL AND coalesce(s.desk_amount, 0) = 0 THEN NULL
                WHEN z.edited THEN 'edited_in_billing'
                WHEN z.package IS NOT NULL AND s.package_name IS NOT NULL
                     AND lower(trim(z.package)) <> lower(trim(s.package_name)) THEN 'package_changed'
                WHEN s.customer_status IS DISTINCT FROM z.cur_status THEN 'status_changed'
                WHEN s.monthly_bill IS DISTINCT FROM z.cur_bill THEN 'price_changed'
                END
        FROM (SELECT s2.id, bb.bill_amount, bb.bill_master_id, bb.package, bb.edited, f.fetched_at,
                     c.status AS cur_status, c.monthly_bill AS cur_bill
              FROM desk_shadow_bills s2 JOIN desk_customers c ON c.id = s2.customer_ref
              LEFT JOIN _bb bb ON bb.customer_ref = s2.customer_ref
              LEFT JOIN billing_customer_fetches f ON f.company_id = c.company_id AND f.customer_header_id = c.header_id
                   AND f.section = 'bills'
              WHERE s2.company_id = %(c)s AND s2.period_start = %(ps)s) z
        WHERE s.id = z.id"""),
]

SHADOW_STATS = """SELECT %(ps)s::text AS period,
    (SELECT min(created_at AT TIME ZONE 'Asia/Dhaka')::text FROM desk_shadow_bills
       WHERE company_id = %(c)s AND period_start = %(ps)s AND desk_amount IS NOT NULL) AS made_at,
    count(*) AS rows,
    count(*) FILTER (WHERE desk_reason = 'billed') AS desk_billed,
    coalesce(sum(desk_amount), 0) AS desk_total,
    count(*) FILTER (WHERE coalesce(billing_amount, 0) > 0) AS billing_billed,
    coalesce(sum(billing_amount), 0) AS billing_total,
    count(*) FILTER (WHERE result = 'match') AS matched,
    count(*) FILTER (WHERE result = 'pending') AS pending,
    count(*) FILTER (WHERE result NOT IN ('match', 'pending')) AS mismatched,
    (SELECT coalesce(jsonb_object_agg(k, n), '{}'::jsonb) FROM (SELECT result || coalesce(':' || hint, '') AS k, count(*) AS n
       FROM desk_shadow_bills WHERE company_id = %(c)s AND period_start = %(ps)s AND result NOT IN ('match', 'pending')
       GROUP BY 1) z) AS mismatch_kinds
  FROM desk_shadow_bills WHERE company_id = %(c)s AND period_start = %(ps)s"""


def month_start(period: str | dt.date | None = None) -> dt.date:
    """'2026-12' / a date / None (this month, Bangladesh time) -> the 1st of that month."""
    if period is None:
        return (dt.datetime.now(dt.timezone.utc) + dt.timedelta(hours=6)).date().replace(day=1)
    if isinstance(period, dt.date):
        return period.replace(day=1)
    y, m = str(period).strip()[:7].split("-")
    return dt.date(int(y), int(m), 1)


def _shadow_stats(c, company_id: int, ps: dt.date) -> dict:
    st = {k: _num(v) for k, v in c.execute(SHADOW_STATS, {"c": company_id, "ps": ps}).fetchone().items()}
    done = (st["rows"] or 0) - (st["pending"] or 0)
    st["match_percent"] = round(100 * st["matched"] / done, 2) if done else None
    return st


def compare_bills(company_id: int, period=None, conn=None) -> dict:
    """Billing's bills of the month next to the Desk's shadow bills (re-run every night as billing's bills arrive)."""
    ps = month_start(period)
    p = {"c": company_id, "ps": ps}

    def work(c):
        for _name, sql in SHADOW_COMPARE:
            c.execute(sql, p)
        return _shadow_stats(c, company_id, ps)

    if conn is not None:
        return work(conn)
    with db.conn() as c:
        with c.transaction():
            return work(c)


def make_bills(company_id: int, period=None, replace: bool = False, user_id: int | None = None,
               source: str = "timer") -> dict:
    """The Desk's own bills for one month into desk_shadow_bills, from the customers as they are now; then compare.
    Made once per month (a second call only compares) unless `replace`. Never touches the ledger or billing."""
    s = settings(company_id)
    if s["billing_mode"] == "desk":
        raise RuntimeError("Desk is the billing master: real bills are made by the bill run, not the shadow")
    gen = db.one("SELECT bill_generation FROM billing_settings WHERE company_id = %s", (company_id,))
    if gen and gen["bill_generation"] != "month_start":
        raise RuntimeError("Expire Date-এ বিল তৈরি পরের ধাপে আসবে; ছায়া বিল এখন শুধু মাসের ১ তারিখের নিয়মে")
    ps = month_start(period)
    exists = db.one("""SELECT 1 FROM desk_shadow_bills WHERE company_id = %s AND period_start = %s
                       AND desk_amount IS NOT NULL LIMIT 1""", (company_id, ps))
    if exists and not replace:
        return {"made": False, **compare_bills(company_id, ps)}
    run = db.one("""INSERT INTO desk_billing_runs (company_id, kind, status, user_id, started_at)
                    VALUES (%s, 'shadow_bills', 'running', %s, now()) RETURNING id""", (company_id, user_id))["id"]
    try:
        with db.conn() as c:
            with c.transaction():
                if replace:
                    c.execute("DELETE FROM desk_shadow_bills WHERE company_id = %s AND period_start = %s", (company_id, ps))
                made = c.execute(SHADOW_MAKE, {"c": company_id, "ps": ps, "run": run}).rowcount
                stats = {"made": True, "made_rows": made, **compare_bills(company_id, ps, conn=c)}
                activity.record(company_id, "billing.shadow_bills", actor="system" if not user_id else "user",
                                source=source, user_id=user_id, subject_type="desk_billing_run", subject_id=run,
                                note=f"Desk-এর ছায়া বিল {ps:%Y-%m}: {stats['desk_billed']} জন, {stats['desk_total']:.0f} টাকা; "
                                     f"বিলিং-এর সাথে মিল {stats['match_percent']}%", conn=c)
    except Exception as e:
        db.execute("UPDATE desk_billing_runs SET status = 'failed', error = %s, finished_at = now() WHERE id = %s",
                   (str(e)[:1000], run))
        raise
    db.execute("UPDATE desk_billing_runs SET status = 'done', stats = %s, finished_at = now() WHERE id = %s",
               (Jsonb(stats), run))
    return stats


def shadow_nightly(company_id: int) -> dict:
    """After the copy: on the 1st-3rd make this month's shadow bills if not made yet (the 2nd/3rd only catch a failed
    1st), then compare every month made in the last 3 months (billing changes its bills during the month)."""
    out = {}
    today = (dt.datetime.now(dt.timezone.utc) + dt.timedelta(hours=6)).date()
    if today.day <= 3:
        out["made"] = make_bills(company_id, today)
    for r in db.all_rows("""SELECT DISTINCT period_start FROM desk_shadow_bills WHERE company_id = %s
                            AND period_start >= %s ORDER BY 1""", (company_id, month_start(today) - dt.timedelta(days=62))):
        out[str(r["period_start"])] = compare_bills(company_id, r["period_start"])
    return out


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
    ap.add_argument("--shadow", action="store_true", help="phase 1 nightly: shadow bills on the 1st + compare")
    ap.add_argument("--shadow-bills", action="store_true", help="make one month's shadow bills (with --period)")
    ap.add_argument("--period", help="YYYY-MM, default this month")
    ap.add_argument("--replace", action="store_true", help="with --shadow-bills / --disable-list: make it again")
    ap.add_argument("--disable-shadow", action="store_true", help="phase 2 hourly: today's disable list + compare")
    ap.add_argument("--disable-list", action="store_true", help="make one day's disable list (with --day)")
    ap.add_argument("--day", help="YYYY-MM-DD (Bangladesh), default today")
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
    if a.shadow or a.shadow_bills:
        for cid in [a.company] if a.company else companies():
            if settings(cid)["billing_mode"] == "desk":
                continue
            try:
                res = (make_bills(cid, a.period, replace=a.replace, source="console") if a.shadow_bills
                       else shadow_nightly(cid))
                log.info("shadow bills company %s: %s", cid, json.dumps(res, default=str))
            except Exception:
                log.exception("shadow bills failed for company %s", cid)
    if a.disable_shadow or a.disable_list or a.shadow:
        # after the nightly copy the enable/disable logs are fresh: compare the last days' lists again
        from engine import desk_disable
        for cid in [a.company] if a.company else companies():
            if settings(cid)["billing_mode"] == "desk":
                continue
            try:
                res = (desk_disable.make_list(cid, dt.date.fromisoformat(a.day) if a.day else None, replace=a.replace,
                                              source="console") if a.disable_list else desk_disable.hourly(cid))
                log.info("disable shadow company %s: %s", cid, json.dumps(res, default=str))
            except Exception:
                log.exception("disable shadow failed for company %s", cid)
    if a.verify_log:
        print(json.dumps(activity.verify(a.company), default=str))


if __name__ == "__main__":
    main()
