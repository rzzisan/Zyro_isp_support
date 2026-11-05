"""engine/desk_disable.py (billing phase 2, the shadow turn-off list).
The decision rules run without a database:
  PYTHONPATH=. .venv/bin/python -m unittest engine.tests.test_desk_disable
The list + compare need a throwaway PostgreSQL with the panel's migrations (skipped without one):
  ENGINE_DATABASE_URL=postgresql://postgres@127.0.0.1:5544/zyro_test PYTHONPATH=. .venv/bin/python -m unittest engine.tests.test_desk_disable
"""
import datetime as dt
import os
import unittest

from engine import desk_disable as dd

URL = os.environ.get("ENGINE_DATABASE_URL", "")
S = dict(dd.DEFAULTS)          # next_day, 11:00, 40% / 3 days, min due 0
D = dt.date


def cust(**kw):
    base = {"status": "Active", "is_left": False, "is_vip": False, "line_state": "on", "expire_day": 5,
            "monthly_bill": 500, "joined_on": D(2025, 1, 1), "balance": 500, "balance_from": "desk",
            "bills": {D(2026, 11, 1): 500}, "extended_until": None, "effective_to": None, "last_enable": None,
            "last_payment": None}
    base.update(kw)
    return base


class DecideTest(unittest.TestCase):
    def test_deadline_is_the_day_after_expire(self):
        # Expire 5 Nov, next_day: on the 5th the November bill isn't owed yet, on the 6th it is
        self.assertIsNone(dd.decide(D(2026, 11, 5), cust(), S))
        d = dd.decide(D(2026, 11, 6), cust(), S)
        self.assertEqual((d["decision"], d["reason"], d["due"]), ("disable", "due_today", 500))
        self.assertEqual((d["expire_on"], d["deadline"]), (D(2026, 11, 5), D(2026, 11, 6)))
        self.assertEqual(dd.decide(D(2026, 11, 9), cust(), S)["reason"], "overdue")

    def test_same_day_and_after_days(self):
        self.assertEqual(dd.decide(D(2026, 11, 5), cust(), {**S, "disable_when": "same_day"})["reason"], "due_today")
        after = {**S, "disable_when": "after_days", "disable_after_days": 3}
        self.assertIsNone(dd.decide(D(2026, 11, 7), cust(), after))
        self.assertEqual(dd.decide(D(2026, 11, 8), cust(), after)["reason"], "due_today")

    def test_this_months_bill_isnt_owed_before_its_deadline(self):
        # expire 25: on 6 Nov only October's unpaid bill counts
        c = cust(expire_day=25, balance=1000, bills={D(2026, 10, 1): 500, D(2026, 11, 1): 500})
        d = dd.decide(D(2026, 11, 6), c, S)
        self.assertEqual((d["reason"], d["due"], d["deadline"]), ("overdue", 500, D(2026, 10, 26)))
        self.assertIsNone(dd.decide(D(2026, 11, 6), cust(expire_day=25), S))   # only November's, not due yet

    def test_short_month_uses_its_last_day(self):
        d = dd.decide(D(2026, 12, 1), cust(expire_day=31, balance=500, bills={D(2026, 11, 1): 500, D(2026, 12, 1): 0}), S)
        self.assertEqual((d["expire_on"], d["deadline"], d["reason"]), (D(2026, 11, 30), D(2026, 12, 1), "due_today"))

    def test_missing_bill_of_the_month_is_assumed(self):
        # the ledger hasn't November's bill yet: the Desk would have made it on the 1st
        d = dd.decide(D(2026, 11, 6), cust(balance=0, bills={}), S)
        self.assertEqual((d["due"], d["balance"]), (500, 500))
        self.assertIsNone(dd.decide(D(2026, 11, 6), cust(balance=0, bills={}, joined_on=D(2026, 11, 2)), S))
        self.assertIsNone(dd.decide(D(2026, 11, 6), cust(balance=0, bills={}, balance_from="billing"), S))

    def test_not_candidates(self):
        for kw in ({"status": "Inactive"}, {"is_left": True}, {"line_state": "off"}, {"expire_day": None}, {"balance": 0},
                   {"balance": -100}):
            self.assertIsNone(dd.decide(D(2026, 11, 6), cust(**kw), S), kw)
        self.assertIsNone(dd.decide(D(2026, 11, 6), cust(balance=20), {**S, "disable_min_due": 20}))

    def test_kept(self):
        day = D(2026, 11, 6)
        self.assertEqual(dd.decide(day, cust(is_vip=True), S)["reason"], "vip")
        self.assertEqual(dd.decide(day, cust(effective_to=D(2026, 11, 10)), S)["reason"], "extended")
        self.assertEqual(dd.decide(day, cust(extended_until=D(2026, 11, 6)), S)["reason"], "extended")
        self.assertEqual(dd.decide(day, cust(effective_to=D(2026, 11, 5)), S)["decision"], "disable")
        self.assertEqual(dd.decide(day, cust(last_enable=D(2026, 11, 4)), S)["reason"], "recently_enabled")
        self.assertEqual(dd.decide(day, cust(last_enable=D(2026, 11, 3)), S)["decision"], "disable")
        # paid 300 of 500 lately: 200 left = 40% of the bill -> kept; 201 -> off
        self.assertEqual(dd.decide(day, cust(balance=200, last_payment=D(2026, 11, 2)), S)["reason"], "small_remainder")
        self.assertEqual(dd.decide(day, cust(balance=201, last_payment=D(2026, 11, 2)), S)["decision"], "disable")
        self.assertEqual(dd.decide(day, cust(balance=200), S)["decision"], "disable")

    def test_parse_date(self):
        for s, want in [("2026-11-20T00:00:00", D(2026, 11, 20)), ("20-11-2026", D(2026, 11, 20)),
                        ("20/11/2026", D(2026, 11, 20)), ("20-Nov-2026", D(2026, 11, 20)), ("20 Nov 2026", D(2026, 11, 20)),
                        ("Nov-20-2026", D(2026, 11, 20)), ("31-12-9999", D(9999, 12, 31)), ("01-Jan-0001", None),
                        ("", None), (None, None), ("garbage", None), ("/Date(1763596800000)/", D(2025, 11, 20))]:
            self.assertEqual(dd.parse_date(s), want, s)
        self.assertEqual(dd.effective_to({"EffectiveTo": "20-Nov-2026", "X": 1}), D(2026, 11, 20))
        self.assertIsNone(dd.effective_to({"X": 1}))
        self.assertIsNone(dd.effective_to(None))


@unittest.skipUnless("test" in URL, "needs a throwaway PostgreSQL (ENGINE_DATABASE_URL with 'test' in it)")
class ListAndCompareTest(unittest.TestCase):
    C = 1

    @classmethod
    def setUpClass(cls):
        from engine import db
        cls.db = db
        cls.day = dd.bd_now().date()
        cls.ps = cls.day.replace(day=1)
        e = cls.day - dt.timedelta(days=1)     # expired yesterday -> deadline today (next_day)
        with db.conn() as c:
            for t in ("desk_disable_shadow", "desk_customer_balances", "desk_bills", "desk_payments", "desk_customers",
                      "desk_billing_runs", "billing_settings", "billing_customer_line_logs", "billing_customer_fetches",
                      "billing_customer_payments", "billing_daily_bills", "billing_customer_remarks", "billing_customers"):
                c.execute(f"DELETE FROM {t}")
            c.execute("""INSERT INTO companies (id, name, slug, status) VALUES (1, 'CL', 'cl', 'active')
                         ON CONFLICT (id) DO NOTHING""")
            # code, header, expire day, balance, vip, billing disables (hours after today's 00:00 BD), paid today
            rows = [
                ("0201", 201, e.day, 500, False, 10.5, 0),     # both: billing turned it off at 10:30
                ("0202", 202, e.day, 500, False, None, 0),     # only the Desk
                ("0203", 203, e.day, 500, False, None, 500),   # paid at the counter this morning: not listed
                ("0204", 204, e.day, 500, True, 11, 0),        # VIP kept, billing turned off anyway
                ("0205", 205, e.day, 0, False, 12, 0),         # nothing due, billing turned off: billing only
                ("0206", 206, e.day, 500, False, 34, 0),       # billing turned it off the next morning
            ]
            midnight = dd.day_start_utc(cls.day)
            for code, h, ed, bal, vip, off_h, paid in rows:
                c.execute("""INSERT INTO billing_customers (company_id, header_id, customer_id, name, status, is_vip,
                                 bill_day, monthly_bill) VALUES (1, %s, %s, %s, 'Active', %s, %s, 500)""",
                          (h, code, "C" + code, vip, ed))
                ref = c.execute("""INSERT INTO desk_customers (company_id, header_id, customer_code, name, status, is_vip,
                                       expire_day, monthly_bill, line_state, joined_on, source)
                                   VALUES (1, %s, %s, %s, 'Active', %s, %s, 500, 'on', '2025-01-01', 'ispdigital')
                                   RETURNING id""", (h, code, "C" + code, vip, ed)).fetchone()["id"]
                c.execute("""INSERT INTO desk_customer_balances (customer_ref, company_id, balance, has_history)
                             VALUES (%s, 1, %s, true)""", (ref, bal))
                c.execute("""INSERT INTO desk_bills (company_id, customer_ref, period_start, period_end, amount, source)
                             VALUES (1, %s, %s, %s, 500, 'ispdigital')""", (ref, cls.ps, cls.ps + dt.timedelta(days=27)))
                if paid:
                    c.execute("""INSERT INTO billing_daily_bills (company_id, daily_bill_id, customer_header_id, collected_at,
                                     is_approved, receive_amount) VALUES (1, %s, %s, now() - interval '5 minutes', true, %s)""",
                              (9000 + h, h, paid))
                if off_h is not None:
                    c.execute("""INSERT INTO billing_customer_line_logs (company_id, customer_header_id, changed_at, changed_by,
                                     action, row_hash) VALUES (1, %s, %s, 'arju11', 'disable', %s)""",
                              (h, midnight + dt.timedelta(hours=off_h), f"h{h}"))
                c.execute("""INSERT INTO billing_customer_fetches (company_id, customer_header_id, section, fetched_at)
                             VALUES (1, %s, 'line', %s)""", (h, midnight + dt.timedelta(hours=40)))

    def test_list_and_compare(self):
        out = dd.make_list(self.C, self.day)
        self.assertTrue(out["made"])
        r = {x["customer_code"]: x for x in self.db.all_rows(
            """SELECT c.customer_code, s.* FROM desk_disable_shadow s JOIN desk_customers c ON c.id = s.customer_ref""")}
        self.assertEqual((r["0201"]["decision"], r["0201"]["reason"], r["0201"]["result"]), ("disable", "due_today", "match"))
        self.assertEqual(r["0201"]["billing_disabled_by"], "arju11")
        self.assertEqual((r["0202"]["decision"], r["0202"]["result"]), ("disable", "desk_only"))
        self.assertNotIn("0203", r)
        self.assertEqual((r["0204"]["decision"], r["0204"]["result"], r["0204"]["hint"]), ("keep", "billing_only", "vip"))
        self.assertEqual((r["0205"]["decision"], r["0205"]["result"], r["0205"]["hint"]), ("none", "billing_only", "not_due"))
        self.assertEqual((r["0206"]["result"], r["0206"]["hint"]), ("match", "billing_next_day"))
        self.assertEqual((out["desk_disable"], out["matched"], out["desk_only"], out["billing_only"]), (3, 2, 1, 2))
        # once a day: a second call only compares; no billing table was written
        self.assertFalse(dd.make_list(self.C, self.day)["made"])
        self.assertEqual(self.db.one("SELECT count(*) AS n FROM billing_customer_line_logs")["n"], 4)
        self.assertEqual(self.db.one("""SELECT count(*) AS n FROM activity_logs WHERE action = 'billing.disable_shadow'""")["n"], 1)
