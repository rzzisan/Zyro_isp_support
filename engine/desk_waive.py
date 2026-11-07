"""In-house billing, phase 4c (plan 2.2 #2, বিল মাফ): a month in which the customer's line stayed off the whole time
and no money came in has its bill waived to 0 (the setting waive_off_month, Zisan 5 Nov 2026: only that month, a
month with a partial payment and older arrears stay; a month the line was on at any time, by hand, by the bot or by
an extension, stays too).

While billing is the master the Desk only lists whom it would waive (desk_waivers status 'shadow'); with billing_mode
'desk' the waive goes into the ledger ('waive' row, the bill marked waived, status 'applied'). A month whose
enable/disable log hasn't been copied from billing since the month ended is 'pending' and looked at again.

Line off the whole month = no 'enable' in billing's log during the month, and off on its first day: the last log
before the month is a 'disable' (or, with no log before, the next one is an 'enable' after the month, or there is
none and the line is off now).

  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.desk_billing --waive --period 2026-11
"""
import datetime as dt
import logging

from engine import activity, db

log = logging.getLogger("zyro-desk-waive")
BD = dt.timedelta(hours=6)

CANDIDATES = """
SELECT b.id AS bill_ref, b.customer_ref, b.amount, c.line_state, c.customer_code,
       (SELECT l.action FROM billing_customer_line_logs l WHERE l.company_id = c.company_id
          AND l.customer_header_id = c.header_id AND l.changed_at < %(p0)s ORDER BY l.changed_at DESC LIMIT 1) AS before,
       (SELECT max(l.changed_at) FROM billing_customer_line_logs l WHERE l.company_id = c.company_id
          AND l.customer_header_id = c.header_id AND l.changed_at < %(p0)s AND l.action = 'disable') AS off_at,
       EXISTS (SELECT 1 FROM billing_customer_line_logs l WHERE l.company_id = c.company_id
          AND l.customer_header_id = c.header_id AND l.changed_at >= %(p0)s AND l.changed_at < %(p1)s
          AND l.action = 'enable') AS enabled_in,
       (SELECT l.action FROM billing_customer_line_logs l WHERE l.company_id = c.company_id
          AND l.customer_header_id = c.header_id AND l.changed_at >= %(p0)s ORDER BY l.changed_at LIMIT 1) AS first_after,
       EXISTS (SELECT 1 FROM desk_payments p WHERE p.company_id = c.company_id AND p.customer_ref = c.id
          AND p.status = 'approved' AND p.paid_on >= %(ps)s AND p.paid_on < %(ne)s AND p.amount + p.discount > 0) AS paid_in,
       (SELECT f.fetched_at FROM billing_customer_fetches f WHERE f.company_id = c.company_id
          AND f.customer_header_id = c.header_id AND f.section = 'line') AS fetched_at
FROM desk_bills b JOIN desk_customers c ON c.id = b.customer_ref
WHERE b.company_id = %(c)s AND b.period_start = %(ps)s AND b.amount > 0 AND b.status NOT IN ('void', 'waived')
"""


def next_month(ps: dt.date) -> dt.date:
    return dt.date(ps.year + (ps.month == 12), ps.month % 12 + 1, 1)


def off_all_month(r: dict) -> bool:
    if r["paid_in"] or r["enabled_in"]:
        return False
    if r["before"]:
        return r["before"] == "disable"
    return r["first_after"] == "enable" or (r["first_after"] is None and r["line_state"] == "off")


def settings(company_id: int) -> dict:
    row = db.one("SELECT billing_mode, waive_off_month FROM billing_settings WHERE company_id = %s", (company_id,)) or {}
    return {"billing_mode": row.get("billing_mode") or "ispdigital",
            "waive_off_month": True if row.get("waive_off_month") is None else bool(row["waive_off_month"])}


def make(company_id: int, period: dt.date, user_id: int | None = None, source: str = "timer") -> dict:
    """Whom the Desk waives for one ended month (again on every call until nothing is pending)."""
    ps = period.replace(day=1)
    ne = next_month(ps)
    today = (dt.datetime.now(dt.timezone.utc) + BD).date()
    if today < ne:
        raise ValueError("মাস এখনো শেষ হয়নি")
    s = settings(company_id)
    if not s["waive_off_month"]:
        n = db.execute("""WITH d AS (DELETE FROM desk_waivers WHERE company_id = %s AND period_start = %s
                          AND status <> 'applied' RETURNING 1) SELECT count(*) AS n FROM d""", (company_id, ps))
        return {"period": str(ps), "off": True, "removed": n["n"] if n else 0}
    apply = s["billing_mode"] == "desk"
    p0 = dt.datetime.combine(ps, dt.time()) - BD
    p1 = dt.datetime.combine(ne, dt.time()) - BD
    rows = db.all_rows(CANDIDATES, {"c": company_id, "ps": ps, "ne": ne, "p0": p0, "p1": p1})
    keep, out = [], {"period": str(ps), "shadow": 0, "pending": 0, "applied": 0, "amount": 0.0}
    with db.conn() as c:
        with c.transaction():
            for r in rows:
                if not off_all_month(r):
                    continue
                keep.append(r["customer_ref"])
                pending = r["fetched_at"] is None or r["fetched_at"] < p1
                status = "pending" if pending else ("applied" if apply else "shadow")
                since = (r["off_at"] + BD).strftime("%d %b %Y") if r["off_at"] else None
                row = c.execute("""INSERT INTO desk_waivers (company_id, period_start, customer_ref, bill_ref, amount, status,
                                       off_since, created_at)
                                   VALUES (%s, %s, %s, %s, %s, %s, %s, now())
                                   ON CONFLICT (company_id, period_start, customer_ref) DO UPDATE SET
                                       bill_ref = EXCLUDED.bill_ref, amount = EXCLUDED.amount, status = EXCLUDED.status,
                                       off_since = EXCLUDED.off_since
                                   WHERE desk_waivers.status <> 'applied'
                                   RETURNING id, status""",
                                (company_id, ps, r["customer_ref"], r["bill_ref"], r["amount"], status, since)).fetchone()
                if row and status == "applied":
                    _apply(c, company_id, r, row["id"], today, user_id)
                out[status] += 1
                out["amount"] += float(r["amount"])
            if keep:
                c.execute("""DELETE FROM desk_waivers WHERE company_id = %s AND period_start = %s AND status <> 'applied'
                             AND NOT (customer_ref = ANY(%s))""", (company_id, ps, keep))
            else:
                c.execute("DELETE FROM desk_waivers WHERE company_id = %s AND period_start = %s AND status <> 'applied'",
                          (company_id, ps))
    out["amount"] = round(out["amount"], 2)
    activity.record(company_id, "billing.waive", actor="user" if user_id else "system", source=source, user_id=user_id,
                    note=f"বিল মাফ {ps:%b %Y}: {out['shadow'] + out['applied']} জন ({out['amount']:.0f} টাকা)"
                         + (" খাতায়" if apply else " হত (ছায়া)") + (f", {out['pending']} জনের লগ আসা বাকি" if out["pending"] else ""))
    return out


def _apply(c, company_id: int, r: dict, waiver_id: int, today: dt.date, user_id: int | None) -> None:
    """The Desk is the master: the bill goes to 0 in the ledger and the balance with it (one row, never twice)."""
    done = c.execute("""INSERT INTO desk_ledger_entries (company_id, customer_ref, entry_date, kind, amount, bill_ref, source,
                            source_ref, note, user_id)
                        VALUES (%s, %s, %s, 'waive', %s, %s, 'desk', %s, 'লাইন পুরো মাস বন্ধ, কোনো টাকা আসেনি', %s)
                        ON CONFLICT DO NOTHING RETURNING id""",
                     (company_id, r["customer_ref"], today, -float(r["amount"]), r["bill_ref"], f"waive:{r['bill_ref']}",
                      user_id)).fetchone()
    if not done:
        return
    c.execute("UPDATE desk_bills SET status = 'waived', updated_at = now() WHERE id = %s", (r["bill_ref"],))
    c.execute("""UPDATE desk_customer_balances SET balance = balance - %s, updated_at = now() WHERE customer_ref = %s""",
              (float(r["amount"]), r["customer_ref"]))
    c.execute("UPDATE desk_waivers SET applied_at = now() WHERE id = %s", (waiver_id,))


def nightly(company_id: int) -> dict:
    """The last month, from the 1st until nothing is pending any more (billing's logs come over the first nights)."""
    today = (dt.datetime.now(dt.timezone.utc) + BD).date()
    prev = (today.replace(day=1) - dt.timedelta(days=1)).replace(day=1)
    if today.day > 10 and not db.one("""SELECT 1 FROM desk_waivers WHERE company_id = %s AND period_start = %s
                                        AND status = 'pending' LIMIT 1""", (company_id, prev)):
        return {}
    return make(company_id, prev)
