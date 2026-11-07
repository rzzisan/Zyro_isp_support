"""engine/desk_waive.py (billing phase 4c, বিল মাফ). The rule runs without a database:
  PYTHONPATH=. .venv/bin/python -m unittest engine.tests.test_desk_waive
The list needs a throwaway PostgreSQL with the panel's migrations (skipped without one):
  ENGINE_DATABASE_URL=postgresql://postgres@127.0.0.1:5544/zyro_test PYTHONPATH=. .venv/bin/python -m unittest engine.tests.test_desk_waive
"""
import datetime as dt
import os
import unittest

from engine import desk_waive as dw

URL = os.environ.get("ENGINE_DATABASE_URL", "")


def r(**kw):
    base = {"paid_in": False, "enabled_in": False, "before": "disable", "first_after": None, "line_state": "off"}
    base.update(kw)
    return base


class RuleTest(unittest.TestCase):
    def test_off_all_month(self):
        self.assertTrue(dw.off_all_month(r()))
        self.assertFalse(dw.off_all_month(r(paid_in=True)))                 # a partial payment keeps the bill
        self.assertFalse(dw.off_all_month(r(enabled_in=True)))              # on at any time in the month
        self.assertFalse(dw.off_all_month(r(before="enable")))              # on on the 1st
        # no log before the month: off if the next log is an enable, or none and the line is off now
        self.assertTrue(dw.off_all_month(r(before=None, first_after="enable")))
        self.assertFalse(dw.off_all_month(r(before=None, first_after="disable")))
        self.assertTrue(dw.off_all_month(r(before=None)))
        self.assertFalse(dw.off_all_month(r(before=None, line_state="on")))


@unittest.skipUnless("test" in URL, "needs a throwaway PostgreSQL (ENGINE_DATABASE_URL with 'test' in it)")
class WaiveTest(unittest.TestCase):
    C = 1
    PS = dt.date(2026, 10, 1)

    def setUp(self):
        from engine import db
        self.db = db
        with db.conn() as c:
            for t in ("desk_waivers", "desk_customer_balances", "desk_bills", "desk_payments", "desk_customers",
                      "billing_settings", "billing_customer_line_logs", "billing_customer_fetches"):
                c.execute(f"DELETE FROM {t}")
            c.execute("TRUNCATE desk_ledger_entries")
            c.execute("""INSERT INTO companies (id, name, slug, status) VALUES (1, 'CL', 'cl', 'active')
                         ON CONFLICT (id) DO NOTHING""")
            c.execute("INSERT INTO billing_settings (company_id) VALUES (1)")
            self.refs = {}
            # code: (line_state, logs [(utc time, action)], fetched after the month?)
            people = {
                "0401": ("off", [("2026-09-20 05:00", "disable")], True),                         # waived
                "0402": ("on", [("2026-09-20 05:00", "disable"), ("2026-10-15 05:00", "enable")], True),
                "0403": ("off", [("2026-09-20 05:00", "disable")], True),                         # paid 200 in Oct
                "0404": ("off", [], True),                                                        # no log, off now
                "0405": ("on", [], True),
                "0406": ("off", [("2026-09-20 05:00", "disable")], False),                        # log not fresh
                "0407": ("off", [("2026-09-30 19:00", "disable")], True),   # 1 Oct 01:00 Dhaka: on on the 1st
            }
            for i, (code, (state, logs, fresh)) in enumerate(people.items()):
                h = 400 + i
                ref = c.execute("""INSERT INTO desk_customers (company_id, header_id, customer_code, name, status,
                                       monthly_bill, line_state) VALUES (1, %s, %s, %s, 'Active', 500, %s) RETURNING id""",
                                (h, code, "C" + code, state)).fetchone()["id"]
                self.refs[code] = ref
                c.execute("""INSERT INTO desk_customer_balances (customer_ref, company_id, balance, has_history)
                             VALUES (%s, 1, 500, true)""", (ref,))
                c.execute("""INSERT INTO desk_bills (company_id, customer_ref, period_start, period_end, amount, status,
                                 source, ext_id) VALUES (1, %s, '2026-10-01', '2026-10-31', 500, 'open', 'ispdigital', %s)""",
                          (ref, h * 10))
                for at, action in logs:
                    c.execute("""INSERT INTO billing_customer_line_logs (company_id, customer_header_id, changed_at, action,
                                     row_hash) VALUES (1, %s, %s, %s, md5(%s))""", (h, at, action, at + action))
                c.execute("""INSERT INTO billing_customer_fetches (company_id, customer_header_id, section, fetched_at)
                             VALUES (1, %s, 'line', %s)""", (h, "2026-11-01 20:00" if fresh else "2026-10-31 10:00"))
            c.execute("""INSERT INTO desk_payments (company_id, customer_ref, paid_on, amount, discount, status, source, ext_id)
                         VALUES (1, %s, '2026-10-12', 200, 0, 'approved', 'ispdigital', 1)""", (self.refs["0403"],))

    def waivers(self):
        return {r["customer_ref"]: r["status"] for r in self.db.all_rows(
            "SELECT customer_ref, status FROM desk_waivers WHERE company_id = 1 AND period_start = %s", (self.PS,))}

    def test_shadow_then_applied_once(self):
        out = dw.make(self.C, self.PS)
        self.assertEqual((out["shadow"], out["pending"], out["applied"]), (2, 1, 0))
        f = self.refs
        self.assertEqual(self.waivers(), {f["0401"]: "shadow", f["0404"]: "shadow", f["0406"]: "pending"})
        self.assertEqual(self.db.one("SELECT count(*) AS n FROM desk_ledger_entries")["n"], 0)   # billing is the master
        off = self.db.one("SELECT off_since FROM desk_waivers WHERE customer_ref = %s", (f["0401"],))["off_since"]
        self.assertEqual(off, "20 Sep 2026")

        # the Desk is the master: into the ledger, the bill waived, the balance down; never twice
        self.db.execute("UPDATE billing_settings SET billing_mode = 'desk' WHERE company_id = 1")
        out = dw.make(self.C, self.PS)
        self.assertEqual(out["applied"], 2)
        dw.make(self.C, self.PS)
        rows = self.db.all_rows("SELECT customer_ref, kind, amount FROM desk_ledger_entries ORDER BY customer_ref")
        self.assertEqual([(x["customer_ref"], x["kind"], float(x["amount"])) for x in rows],
                         [(f["0401"], "waive", -500.0), (f["0404"], "waive", -500.0)])
        bal = self.db.one("SELECT balance FROM desk_customer_balances WHERE customer_ref = %s", (f["0401"],))["balance"]
        self.assertEqual(float(bal), 0.0)
        self.assertEqual(self.db.one("SELECT status FROM desk_bills WHERE customer_ref = %s", (f["0401"],))["status"], "waived")

        # the setting off: the not-applied rows go, the applied stay
        self.db.execute("UPDATE billing_settings SET waive_off_month = false WHERE company_id = 1")
        dw.make(self.C, self.PS)
        self.assertEqual(self.waivers(), {f["0401"]: "applied", f["0404"]: "applied"})

    def test_month_not_ended(self):
        today = (dt.datetime.now(dt.timezone.utc) + dw.BD).date()
        with self.assertRaises(ValueError):
            dw.make(self.C, today)


if __name__ == "__main__":
    unittest.main()
