"""In-house billing, phase 2 (বন্ধের তালিকা ছায়া): whose line the Desk would turn off today for an unpaid bill, next
to whom billing (its "Disable Unpaid Customer" job or staff) actually turned off. Read-only: the Desk turns nothing off,
writes nothing to billing or MikroTik; the list lives in desk_disable_shadow.

  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.desk_billing --disable-shadow   (hourly timer)
  ... --disable-list [--day 2026-11-06] [--replace] [--company ID]    make one day's list by hand (and compare)

The list (plan 2.2 #6, #10, #11), made once a day at the company's disable hour (Bangladesh time), from the morning
copy of the customers (desk_customers, 07:15) and the Desk's own ledger balance (desk_customer_balances) minus the
receipts billing approved since that copy (billing_daily_bills, every 10 minutes):
  - only Active, not Left, line on in the morning copy, with an Expire Date (day of the month);
  - a month's bill is owed from its deadline on: that month's Expire Date (the last day in a short month) plus the
    setting's days (same_day 0, next_day 1, after_days N); a bill whose deadline is still ahead isn't owed yet;
  - owed > disable_min_due  ->  'disable' (due_today when the deadline is today, overdue when it passed earlier and
    the line is still on), unless kept: VIP, an Extended Date (billing's EffectiveTo or the Desk's own) not passed yet,
    the line was turned on in the last partial_redisable_days days, or a payment came in the last month and what is
    left is at most partial_redisable_percent of the monthly bill.
The compare reads billing's enable/disable log (billing_customer_line_logs, copied every night): a 'disable' matches
a disable on the same day (or the next, hint billing_next_day); billing turning off someone the Desk kept or didn't
list is 'billing_only'. A customer whose log hasn't been copied since the day is 'pending'.
"""
import calendar
import datetime as dt
import logging
import re
from decimal import Decimal

from psycopg.types.json import Jsonb

from engine import activity, db

log = logging.getLogger("zyro-desk-disable")
BD = dt.timedelta(hours=6)
COMPARE_DAYS = 8
DEFAULTS = {"billing_mode": "ispdigital", "disable_when": "next_day", "disable_after_days": 1, "disable_hour": 11,
            "partial_redisable_percent": 40, "partial_redisable_days": 3, "disable_min_due": 0}


def bd_now() -> dt.datetime:
    """Bangladesh wall clock, naive."""
    return dt.datetime.now(dt.timezone.utc).replace(tzinfo=None) + BD


def day_start_utc(day: dt.date) -> dt.datetime:
    """00:00 of a Bangladesh date as a naive UTC timestamp (billing's logs are stored that way)."""
    return dt.datetime.combine(day, dt.time()) - BD


def settings(company_id: int) -> dict:
    row = db.one("""SELECT billing_mode, disable_when, disable_after_days, disable_hour, partial_redisable_percent,
                           partial_redisable_days, disable_min_due FROM billing_settings WHERE company_id = %s""",
                 (company_id,))
    return {**DEFAULTS, **{k: v for k, v in (row or {}).items() if v is not None}}


def grace_days(s: dict) -> int:
    return {"same_day": 0, "next_day": 1}.get(s.get("disable_when"), int(s.get("disable_after_days") or 1))


def expire_in(month: dt.date, expire_day: int) -> dt.date:
    """The Expire Date in that month; a day the month hasn't (31 in a 30-day month) is its last day."""
    last = calendar.monthrange(month.year, month.month)[1]
    return month.replace(day=min(max(int(expire_day), 1), last))


def months_back(day: dt.date, n: int) -> dt.date:
    y, m = day.year, day.month - n
    while m < 1:
        y, m = y - 1, m + 12
    return dt.date(y, m, 1)


_MONTHS = {m.lower(): i for i, m in enumerate(calendar.month_abbr) if m}


def parse_date(v) -> dt.date | None:
    """Billing's dates come in many shapes: 2026-11-20T00:00:00, 20-11-2026, 20/11/2026, 20-Nov-2026, 20 Nov 2026,
    Nov-20-2026, Nov-20-26 (EffectiveTo), /Date(1763596800000)/. Before 1900 (01-Jan-0001) is empty; 31-12-9999 and
    Dec-31-99 are 'always' (9999-12-31)."""
    s = str(v or "").strip()
    if not s:
        return None
    try:
        if m := re.search(r"/Date\((-?\d+)", s):
            d = dt.datetime.fromtimestamp(int(m.group(1)) / 1000, dt.timezone.utc).date()
        elif m := re.match(r"(\d{4})-(\d{1,2})-(\d{1,2})", s):
            d = dt.date(int(m.group(1)), int(m.group(2)), int(m.group(3)))
        elif m := re.match(r"(\d{1,2})[-/ .](\d{1,2})[-/ .](\d{4})", s):
            d = dt.date(int(m.group(3)), int(m.group(2)), int(m.group(1)))
        elif m := re.match(r"(\d{1,2})[-/ ]([A-Za-z]{3})[A-Za-z]*[-/ ,]+(\d{4})", s):
            d = dt.date(int(m.group(3)), _MONTHS[m.group(2).lower()], int(m.group(1)))
        elif m := re.match(r"([A-Za-z]{3})[A-Za-z]*[-/ ](\d{1,2})[-/ ,]+(\d{4}|\d{2})\b", s):
            y = int(m.group(3))
            if len(m.group(3)) == 2:       # billing's EffectiveTo: Nov-06-26; Dec-31-99 = no end
                y = 9999 if y == 99 else 2000 + y
            d = dt.date(y, _MONTHS[m.group(1).lower()], int(m.group(2)))
        else:
            return None
    except (ValueError, KeyError, OverflowError, OSError):
        return None
    return d if d.year >= 1900 else None


def effective_to(extra) -> dt.date | None:
    """Billing's Extended Date (EffectiveTo) from the copied customer row, if it has one."""
    if not isinstance(extra, dict):
        return None
    for k, v in extra.items():
        if k.replace("_", "").lower() == "effectiveto":
            return parse_date(v)
    return None


def _f(v) -> float:
    return float(v) if isinstance(v, (Decimal, int, float)) else 0.0


def decide(day: dt.date, r: dict, s: dict) -> dict | None:
    """The Desk's decision for one customer on one day, or None when there's nothing past its deadline.
    r: status, is_left, is_vip, line_state, expire_day, monthly_bill, joined_on, balance, balance_from, bills
    ({month start: amount} of the last 3 months as the ledger has them), extended_until, last_enable, last_payment."""
    if r.get("is_left") or (r.get("status") or "") != "Active" or r.get("line_state") != "on" or not r.get("expire_day"):
        return None
    start = r.get("billing_start")              # billing's "billing start month": no bill before it (new connection)
    if start and start > day:
        return None
    g = grace_days(s)
    balance = _f(r.get("balance"))
    bills = dict(r.get("bills") or {})
    ps = day.replace(day=1)
    # the ledger may not have this month's bill yet (billing's bills of the month are copied over the first nights):
    # the Desk would have made it on the 1st for a customer who was Active and joined before the month
    if (r.get("balance_from") == "desk" and ps not in bills and _f(r.get("monthly_bill")) > 0
            and (r.get("joined_on") is None or r["joined_on"] < ps) and not (start and start > ps)):
        bills[ps] = _f(r["monthly_bill"])
        balance += bills[ps]
    not_yet, deadline, expire_on = 0.0, None, None
    for n in range(0, 4):
        month = months_back(day, n)
        e = expire_in(month, r["expire_day"])
        dl = e + dt.timedelta(days=g)
        if dl > day:
            not_yet += _f(bills.get(month))
        elif deadline is None:
            deadline, expire_on = dl, e
    due = round(balance - not_yet, 2)
    if deadline is None or due <= _f(s.get("disable_min_due")):
        return None
    out = {"due": due, "balance": round(balance, 2), "expire_on": expire_on, "deadline": deadline,
           "decision": "disable", "reason": "due_today" if deadline == day else "overdue"}
    ext = max([d for d in (r.get("extended_until"), r.get("effective_to")) if d] or [None], key=lambda d: d or dt.date.min)
    out["extended_until"] = ext
    days = int(s.get("partial_redisable_days") or 0)
    pct = _f(s.get("partial_redisable_percent"))
    if r.get("is_vip"):
        out.update(decision="keep", reason="vip")
    elif ext and ext >= day:
        out.update(decision="keep", reason="extended")
    elif r.get("last_enable") and (day - r["last_enable"]).days < days:
        out.update(decision="keep", reason="recently_enabled")
    elif (r.get("last_payment") and (day - r["last_payment"]).days <= 31
          and due <= _f(r.get("monthly_bill")) * pct / 100):
        out.update(decision="keep", reason="small_remainder")
    return out


# --- the list ---------------------------------------------------------------------------------------------------------
CANDIDATES = """
WITH recent AS (   -- receipts billing approved that the ledger doesn't have yet
    SELECT d.customer_header_id AS h, sum(d.receive_amount + d.discount_amount) AS amt,
           max(d.collected_at) AS last_at
    FROM billing_daily_bills d
    WHERE d.company_id = %(c)s AND d.is_approved AND NOT d.is_deleted AND d.collected_at > %(at)s - interval '3 days'
      AND d.collected_at <= %(at)s
      AND NOT EXISTS (SELECT 1 FROM billing_customer_payments p
                      JOIN desk_payments dp ON dp.company_id = p.company_id AND dp.source = 'ispdigital'
                           AND dp.ext_id = p.bill_header_id
                      WHERE p.company_id = d.company_id AND p.daily_bill_id = d.daily_bill_id)
    GROUP BY 1),
bills AS (
    SELECT customer_ref, jsonb_object_agg(period_start::text, amount) AS m
    FROM desk_bills WHERE company_id = %(c)s AND period_start >= %(from)s AND status <> 'void' GROUP BY 1),
pays AS (
    SELECT customer_ref, max(paid_on) AS last_on FROM desk_payments
    WHERE company_id = %(c)s AND paid_on >= %(from)s AND status = 'approved' GROUP BY 1),
lines AS (
    SELECT customer_header_id AS h, max(changed_at) AS last_enable FROM billing_customer_line_logs
    WHERE company_id = %(c)s AND action = 'enable' AND changed_at > %(at)s - interval '40 days' GROUP BY 1)
SELECT c.id, c.customer_code, c.status, c.is_left, c.is_vip, c.line_state, c.expire_day, c.monthly_bill, c.joined_on,
       c.extended_until, b.extra, b.billing_start_month AS billing_start,
       CASE WHEN x.has_history THEN x.balance - coalesce(r.amt, 0) ELSE coalesce(x.billing_due, b.due) END AS balance,
       CASE WHEN x.has_history THEN 'desk' ELSE 'billing' END AS balance_from,
       bl.m AS bills, greatest(p.last_on, (r.last_at + interval '6 hours')::date) AS last_payment,
       (l.last_enable + interval '6 hours')::date AS last_enable
FROM desk_customers c
LEFT JOIN billing_customers b ON b.company_id = c.company_id AND b.header_id = c.header_id
LEFT JOIN desk_customer_balances x ON x.customer_ref = c.id
LEFT JOIN recent r ON r.h = c.header_id
LEFT JOIN bills bl ON bl.customer_ref = c.id
LEFT JOIN pays p ON p.customer_ref = c.id
LEFT JOIN lines l ON l.h = c.header_id
WHERE c.company_id = %(c)s AND c.gone_at IS NULL AND NOT c.is_left AND c.status = 'Active' AND c.line_state = 'on'
"""

INSERT = """INSERT INTO desk_disable_shadow (company_id, day, customer_ref, run_id, decision, reason, due, balance,
        balance_from, monthly_bill, expire_on, deadline, extended_until, made_at)
    VALUES (%(c)s, %(day)s, %(id)s, %(run)s, %(decision)s, %(reason)s, %(due)s, %(balance)s, %(balance_from)s,
        %(monthly_bill)s, %(expire_on)s, %(deadline)s, %(extended_until)s, now())
    ON CONFLICT (company_id, day, customer_ref) DO NOTHING"""


def _rows(company_id: int, day: dt.date, at: dt.datetime) -> list[dict]:
    rows = db.all_rows(CANDIDATES, {"c": company_id, "at": at, "from": months_back(day, 3)})
    for r in rows:
        r["bills"] = {dt.date.fromisoformat(k): v for k, v in (r.get("bills") or {}).items()}
        r["effective_to"] = effective_to(r.pop("extra", None))
    return rows


def made(company_id: int, day: dt.date) -> bool:
    return bool(db.one("""SELECT 1 FROM desk_disable_shadow WHERE company_id = %s AND day = %s
                          AND decision IN ('disable', 'keep') LIMIT 1""", (company_id, day)))


def make_list(company_id: int, day: dt.date | None = None, replace: bool = False, user_id: int | None = None,
              source: str = "timer") -> dict:
    """One day's list (once a day unless `replace`), then the compare. Never writes outside desk_disable_shadow."""
    s = settings(company_id)
    if s["billing_mode"] == "desk":
        raise RuntimeError("Desk is the billing master: lines are turned off by the real job, not the shadow")
    day = day or bd_now().date()
    if not made(company_id, day) or replace:
        end_extensions(company_id, day)
    if made(company_id, day) and not replace:
        return {"made": False, **compare(company_id, day)}
    run = db.one("""INSERT INTO desk_billing_runs (company_id, kind, status, user_id, started_at)
                    VALUES (%s, 'disable_shadow', 'running', %s, now()) RETURNING id""", (company_id, user_id))["id"]
    try:
        at = dt.datetime.now(dt.timezone.utc).replace(tzinfo=None)
        out = []
        for r in _rows(company_id, day, at):
            d = decide(day, r, s)
            if d:
                out.append({"c": company_id, "day": day, "id": r["id"], "run": run, "monthly_bill": r["monthly_bill"],
                            "balance_from": r["balance_from"], **d})
        with db.conn() as c:
            with c.transaction():
                if replace:
                    c.execute("DELETE FROM desk_disable_shadow WHERE company_id = %s AND day = %s", (company_id, day))
                with c.cursor() as cur:
                    cur.executemany(INSERT, out)
                n = sum(1 for o in out if o["decision"] == "disable")
                # plan 2.2 #6 safety: more than 3x the average of the last 7 lists would stop the real job
                avg = c.execute("""SELECT avg(n) AS a FROM (SELECT day, count(*) AS n FROM desk_disable_shadow
                                     WHERE company_id = %s AND decision = 'disable' AND day >= %s AND day < %s
                                     GROUP BY day) z""", (company_id, day - dt.timedelta(days=7), day)).fetchone()["a"]
                stats = {"day": str(day), "disable": n, "keep": len(out) - n,
                         "due_today": sum(1 for o in out if o["reason"] == "due_today"),
                         "avg_7_days": round(float(avg), 1) if avg else None,
                         "would_halt": bool(avg and n > 3 * float(avg))}
                activity.record(company_id, "billing.disable_shadow", actor="system" if not user_id else "user",
                                source=source, user_id=user_id, subject_type="desk_billing_run", subject_id=run,
                                note=f"বন্ধের তালিকা (ছায়া) {day}: {n} জন বন্ধ হত, {len(out) - n} জন বকেয়া থাকলেও চালু থাকত",
                                conn=c)
    except Exception as e:
        db.execute("UPDATE desk_billing_runs SET status = 'failed', error = %s, finished_at = now() WHERE id = %s",
                   (str(e)[:1000], run))
        raise
    db.execute("UPDATE desk_billing_runs SET status = 'done', stats = %s, finished_at = now() WHERE id = %s",
               (Jsonb(stats), run))
    return {"made": True, **stats, **compare(company_id, day)}


# --- the compare -------------------------------------------------------------------------------------------------------
COMPARE = [
    # billing's first disable from the day's 00:00 to the end of the next day (staff often do it the morning after)
    ("disables", """CREATE TEMP TABLE _dis ON COMMIT DROP AS
        SELECT DISTINCT ON (c.id) c.id AS customer_ref, l.changed_at, l.changed_by
        FROM billing_customer_line_logs l
        JOIN desk_customers c ON c.company_id = l.company_id AND c.header_id = l.customer_header_id
        WHERE l.company_id = %(c)s AND l.action = 'disable' AND l.changed_at >= %(d0)s AND l.changed_at < %(d2)s
        ORDER BY c.id, l.changed_at"""),
    # billing turned off someone the Desk didn't list that day (nor the day before: a late match of yesterday's list)
    ("billing_only", """INSERT INTO desk_disable_shadow (company_id, day, customer_ref, decision, reason, monthly_bill)
        SELECT %(c)s, %(day)s, d.customer_ref, 'none',
               CASE WHEN c.is_left OR c.status = 'Left' THEN 'left'
                    WHEN coalesce(c.status, '') <> 'Active' THEN 'not_active'
                    WHEN c.is_vip THEN 'vip'
                    WHEN c.line_state <> 'on' THEN 'line_off'
                    ELSE 'not_due' END, c.monthly_bill
        FROM _dis d JOIN desk_customers c ON c.id = d.customer_ref
        WHERE d.changed_at < %(d1)s
          AND NOT EXISTS (SELECT 1 FROM desk_disable_shadow s WHERE s.company_id = %(c)s AND s.day = %(day)s
                          AND s.customer_ref = d.customer_ref)
          AND NOT EXISTS (SELECT 1 FROM desk_disable_shadow s WHERE s.company_id = %(c)s AND s.day = %(day)s - 1
                          AND s.customer_ref = d.customer_ref AND s.decision = 'disable')
        ON CONFLICT (company_id, day, customer_ref) DO NOTHING"""),
    ("compare", """UPDATE desk_disable_shadow s SET billing_disabled_at = z.changed_at, billing_disabled_by = z.changed_by,
            compared_at = now(),
            result = CASE
                WHEN s.decision = 'none' THEN 'billing_only'
                WHEN s.decision = 'disable' AND z.changed_at IS NOT NULL THEN 'match'
                WHEN s.decision = 'keep' AND z.changed_at < %(d1)s THEN 'billing_only'
                WHEN z.fetched_at IS NULL OR z.fetched_at < %(d1)s THEN 'pending'
                WHEN s.decision = 'disable' THEN 'desk_only'
                ELSE 'match' END,
            hint = CASE
                WHEN s.decision = 'none' THEN s.reason
                WHEN s.decision = 'disable' AND z.changed_at >= %(d1)s THEN 'billing_next_day'
                WHEN s.decision = 'disable' AND z.changed_at IS NOT NULL THEN NULL
                WHEN s.decision = 'keep' AND z.changed_at < %(d1)s THEN s.reason
                WHEN s.decision = 'disable' AND z.paid_later THEN 'paid_later'
                WHEN s.decision = 'disable' AND z.extended THEN 'extended_in_billing'
                END
        FROM (SELECT s2.id, d.changed_at, d.changed_by, f.fetched_at,
                     EXISTS (SELECT 1 FROM billing_daily_bills b WHERE b.company_id = c.company_id
                             AND b.customer_header_id = c.header_id AND b.is_approved AND NOT b.is_deleted
                             AND b.collected_at >= coalesce(s2.made_at AT TIME ZONE 'UTC', %(d0)s)
                             AND b.collected_at < %(d2)s) AS paid_later,
                     EXISTS (SELECT 1 FROM billing_customer_remarks r WHERE r.company_id = c.company_id
                             AND r.customer_header_id = c.header_id AND r.remarks_type ILIKE '%%effective%%'
                             AND r.created_at >= %(d0)s - interval '1 day' AND r.created_at < %(d2)s) AS extended
              FROM desk_disable_shadow s2 JOIN desk_customers c ON c.id = s2.customer_ref
              LEFT JOIN _dis d ON d.customer_ref = s2.customer_ref
              LEFT JOIN billing_customer_fetches f ON f.company_id = c.company_id AND f.customer_header_id = c.header_id
                   AND f.section = 'line'
              WHERE s2.company_id = %(c)s AND s2.day = %(day)s) z
        WHERE s.id = z.id"""),
]

STATS = """SELECT %(day)s::text AS day,
    count(*) FILTER (WHERE decision = 'disable') AS desk_disable,
    count(*) FILTER (WHERE decision = 'keep') AS desk_keep,
    count(*) FILTER (WHERE billing_disabled_at IS NOT NULL OR decision = 'none') AS billing_disabled,
    count(*) FILTER (WHERE decision = 'disable' AND result = 'match') AS matched,
    count(*) FILTER (WHERE result = 'desk_only') AS desk_only,
    count(*) FILTER (WHERE result = 'billing_only') AS billing_only,
    count(*) FILTER (WHERE result = 'pending') AS pending,
    (SELECT coalesce(jsonb_object_agg(k, n), '{}'::jsonb) FROM (SELECT result || coalesce(':' || hint, '') AS k, count(*) AS n
       FROM desk_disable_shadow WHERE company_id = %(c)s AND day = %(day)s AND result IN ('desk_only', 'billing_only')
       GROUP BY 1) z) AS mismatch_kinds
  FROM desk_disable_shadow WHERE company_id = %(c)s AND day = %(day)s"""


def compare(company_id: int, day: dt.date) -> dict:
    """The day's list next to billing's enable/disable log as copied so far (re-run every hour and night)."""
    d0 = day_start_utc(day)
    p = {"c": company_id, "day": day, "d0": d0, "d1": d0 + dt.timedelta(days=1), "d2": d0 + dt.timedelta(days=2)}
    with db.conn() as c:
        with c.transaction():
            for _name, sql in COMPARE:
                c.execute(sql, p)
            st = {k: (float(v) if isinstance(v, Decimal) else v) for k, v in c.execute(STATS, p).fetchone().items()}
    done = (st["desk_disable"] or 0) + (st["billing_only"] or 0) - (st["pending"] or 0)
    st["match_percent"] = round(100 * st["matched"] / done, 2) if done > 0 else None
    return st


# --- সময় বাড়ানো (phase 4a, plan 2.2 #11) ---------------------------------------------------------------------------------
END_EXTENSIONS = [
    # the date passed: the line was kept on through `until`, from the next day the normal rules apply again
    ("ended", """UPDATE desk_extensions SET status = 'ended', ended_at = now(), end_note = 'তারিখ পার'
        WHERE company_id = %(c)s AND status = 'active' AND until < %(today)s"""),
    # nothing due any more, as a copy made after the extension shows it (the full bill came)
    ("paid", """UPDATE desk_extensions e SET status = 'paid', ended_at = now(), end_note = 'পুরো বিল দিয়েছেন'
        FROM desk_customer_balances b
        WHERE e.company_id = %(c)s AND e.status = 'active' AND b.customer_ref = e.customer_ref
          AND b.updated_at > e.created_at
          AND (CASE WHEN b.has_history THEN b.balance ELSE coalesce(b.billing_due, b.balance) END) <= 0"""),
    # desk_customers.extended_until = the active extension's date (or none), what the list reads
    ("sync", """UPDATE desk_customers c SET extended_until = a.until, updated_at = now()
        FROM (SELECT c2.id, (SELECT e.until FROM desk_extensions e WHERE e.company_id = c2.company_id
                             AND e.customer_ref = c2.id AND e.status = 'active' ORDER BY e.id DESC LIMIT 1) AS until
              FROM desk_customers c2 WHERE c2.company_id = %(c)s
                AND (c2.extended_until IS NOT NULL OR EXISTS (SELECT 1 FROM desk_extensions e WHERE e.company_id = c2.company_id
                     AND e.customer_ref = c2.id AND e.status = 'active'))) a
        WHERE c.id = a.id AND c.extended_until IS DISTINCT FROM a.until"""),
]


def end_extensions(company_id: int, today: dt.date | None = None) -> dict:
    """End the extensions whose date passed or whose customer paid up, and keep desk_customers.extended_until in step."""
    today = today or bd_now().date()
    out = {}
    with db.conn() as c:
        with c.transaction():
            for name, sql in END_EXTENSIONS:
                out[name] = c.execute(sql, {"c": company_id, "today": today}).rowcount
    if out.get("ended") or out.get("paid"):
        activity.record(company_id, "billing.extend_end", actor="system", source="timer",
                        note=f"সময় বাড়ানো শেষ: তারিখ পার {out['ended']} জন, পুরো বিল দিয়েছেন {out['paid']} জন")
    return out


def hourly(company_id: int, now: dt.datetime | None = None) -> dict:
    """The hourly timer: end passed extensions; at (or after) the company's disable hour make today's list once; then
    compare the last days."""
    now = now or bd_now()
    s = settings(company_id)
    out = {"extensions": end_extensions(company_id, now.date())}
    if s["billing_mode"] == "desk":
        return out
    today = now.date()
    if now.hour >= int(s["disable_hour"]) and not made(company_id, today):
        out["made"] = make_list(company_id, today)
    for r in db.all_rows("""SELECT DISTINCT day FROM desk_disable_shadow WHERE company_id = %s AND day >= %s
                            AND decision IN ('disable', 'keep') ORDER BY 1""",
                         (company_id, today - dt.timedelta(days=COMPARE_DAYS))):
        out[str(r["day"])] = compare(company_id, r["day"])
    return out
