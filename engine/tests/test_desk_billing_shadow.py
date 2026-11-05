"""engine/desk_billing.py phase 1 against a real PostgreSQL with the panel's migrations (skipped without one):
  ENGINE_DATABASE_URL=postgresql://postgres@127.0.0.1:5544/zyro_test PYTHONPATH=. .venv/bin/python -m unittest engine.tests.test_desk_billing_shadow
The database must be a throwaway one: the test empties the tables it uses.
"""
import datetime as dt
import os
import unittest

URL = os.environ.get("ENGINE_DATABASE_URL", "")


@unittest.skipUnless("test" in URL, "needs a throwaway PostgreSQL (ENGINE_DATABASE_URL with 'test' in it)")
class ShadowBillingTest(unittest.TestCase):
    C = 1

    @classmethod
    def setUpClass(cls):
        from engine import db
        cls.db = db
        today = (dt.datetime.now(dt.timezone.utc) + dt.timedelta(hours=6)).date()
        cls.ps = today.replace(day=1)
        with db.conn() as c:
            for t in ("desk_shadow_bills", "desk_customer_balances", "desk_bills", "desk_payments", "desk_customers",
                      "desk_packages", "desk_billing_runs", "billing_settings", "billing_customer_bills",
                      "billing_customer_payments", "billing_customer_fetches", "billing_daily_bills", "billing_customers"):
                c.execute(f"DELETE FROM {t}")
            c.execute("DELETE FROM desk_ledger_entries WHERE source = 'ispdigital'")
            c.execute("""INSERT INTO companies (id, name, slug, status) VALUES (1, 'CL', 'cl', 'active')
                         ON CONFLICT (id) DO NOTHING""")
            y, m = cls.ps.year, cls.ps.month
            # code, header, status, joined, monthly, package, billing bill (amount, package) or None, billing due, advance,
            # bills fetched (None = never, 'old' = before the 1st, 'new' = after)
            rows = [
                ("0101", 101, "Active", "2025-01-10", 500, "10M", (500, "10M"), 0, 0, "new"),      # paid today (receipt)
                ("0102", 102, "Active", str(cls.ps + dt.timedelta(days=1)), 500, "10M", None, 0, 0, "new"),  # joined this month
                ("0103", 103, "Inactive", "2025-01-10", 500, "10M", (0, "10M"), 0, 0, "new"),
                ("0104", 104, "Active", "2025-01-10", 600, "12M", (700, "15M"), 650, 0, "new"),   # package changed
                ("0105", 105, "Active", "2025-01-10", 500, "10M", None, 0, 0, "old"),             # billing not copied yet
                ("0106", 106, "Active", "2025-01-10", 500, "10M", None, 0, 0, "new"),             # billing made none
            ]
            for code, h, status, joined, mb, pkg, bill, due, adv, fetched in rows:
                c.execute("""INSERT INTO billing_customers (company_id, header_id, customer_id, name, status, joined_on,
                                 monthly_bill, package, due, advance, payable)
                             VALUES (1, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""",
                          (h, code, "C" + code, status, joined, mb, pkg, due, adv, due))
                if bill:
                    c.execute("""INSERT INTO billing_customer_bills (company_id, customer_header_id, bill_master_id,
                                     period_year, period_month, bill_amount, due, package)
                                 VALUES (1, %s, %s, %s, %s, %s, %s, %s)""", (h, h * 10, y, m, bill[0], bill[0], bill[1]))
                at = (cls.ps - dt.timedelta(days=2)) if fetched == "old" else today
                c.execute("""INSERT INTO billing_customer_fetches (company_id, customer_header_id, section, fetched_at)
                             VALUES (1, %s, 'bills', %s)""", (h, at))
            # 0101 paid 500 at the counter just now: billing's list says due 0, the history copy hasn't the receipt yet
            c.execute("""INSERT INTO billing_daily_bills (company_id, daily_bill_id, customer_header_id, collected_at,
                             is_approved, receive_amount) VALUES (1, 9001, 101, now() - interval '2 hours', true, 500)""")

    def test_due_mismatch_reasons_and_shadow_bills(self):
        from engine import desk_billing as d
        st = d.import_company(self.C)
        self.assertEqual(st["customers"], 6)
        bal = {r["customer_code"]: r for r in self.db.all_rows(
            """SELECT c.customer_code, x.balance, x.diff, x.mismatch_reason, x.mismatch_since FROM desk_customer_balances x
               JOIN desk_customers c ON c.id = x.customer_ref""")}
        self.assertEqual(bal["0101"]["mismatch_reason"], "recent_receipt")
        self.assertEqual(float(bal["0101"]["diff"]), 500)
        self.assertEqual(bal["0104"]["mismatch_reason"], "other")
        self.assertEqual(float(bal["0104"]["diff"]), 50)
        self.assertIsNotNone(bal["0104"]["mismatch_since"])
        self.assertIsNone(bal["0103"]["diff"])
        self.assertIsNone(bal["0103"]["mismatch_reason"])
        self.assertEqual(st["mismatch_reasons"], {"recent_receipt": 1, "other": 1})

        out = d.make_bills(self.C, self.ps)
        self.assertTrue(out["made"])
        sb = {r["customer_code"]: r for r in self.db.all_rows(
            """SELECT c.customer_code, s.* FROM desk_shadow_bills s JOIN desk_customers c ON c.id = s.customer_ref""")}
        self.assertEqual((sb["0101"]["desk_reason"], float(sb["0101"]["desk_amount"]), sb["0101"]["result"]), ("billed", 500, "match"))
        self.assertEqual((sb["0102"]["desk_reason"], sb["0102"]["result"]), ("joined_this_month", "match"))
        self.assertEqual((sb["0103"]["desk_reason"], sb["0103"]["result"]), ("not_active", "match"))
        self.assertEqual((sb["0104"]["result"], sb["0104"]["hint"]), ("amount_diff", "package_changed"))
        self.assertEqual(sb["0105"]["result"], "pending")
        self.assertEqual(sb["0106"]["result"], "desk_only")
        self.assertEqual(out["desk_billed"], 4)
        self.assertEqual(out["matched"], 3)
        self.assertEqual(out["pending"], 1)
        self.assertEqual(out["match_percent"], 60.0)

        # made once a month: a second call only compares; billing's bill for 0105 arrives and matches
        with self.db.conn() as c:
            c.execute("""INSERT INTO billing_customer_bills (company_id, customer_header_id, bill_master_id, period_year,
                             period_month, bill_amount, due, package) VALUES (1, 105, 1050, %s, %s, 500, 500, '10M')""",
                      (self.ps.year, self.ps.month))
        again = d.make_bills(self.C, self.ps)
        self.assertFalse(again["made"])
        self.assertEqual(again["rows"], 6)
        self.assertEqual(again["matched"], 4)
        self.assertEqual(again["pending"], 0)
        # nothing of this goes into the ledger
        self.assertEqual(self.db.one("SELECT count(*) AS n FROM desk_ledger_entries WHERE source <> 'ispdigital'")["n"], 0)


if __name__ == "__main__":
    unittest.main()
