"""engine/desk_billing.py with the Desk's own payments (billing phase 4b) against a throwaway PostgreSQL with the panel's
migrations (skipped without one):
  ENGINE_DATABASE_URL=postgresql://postgres@127.0.0.1:5544/zyro_test PYTHONPATH=. .venv/bin/python -m unittest engine.tests.test_desk_ledger_import
A bill taken on the Desk is a Desk payment with billing's Daily Bill id: the nightly copy must not count billing's receipt
of the same money again; a Desk payment billing deleted is canceled, a pending one billing approved is approved.
"""
import datetime as dt
import os
import unittest

URL = os.environ.get("ENGINE_DATABASE_URL", "")


@unittest.skipUnless("test" in URL, "needs a throwaway PostgreSQL (ENGINE_DATABASE_URL with 'test' in it)")
class DeskLedgerImportTest(unittest.TestCase):
    C = 1

    @classmethod
    def setUpClass(cls):
        from engine import db
        cls.db = db
        today = (dt.datetime.now(dt.timezone.utc) + dt.timedelta(hours=6)).date()
        ps = today.replace(day=1)
        with db.conn() as c:
            for t in ("desk_extensions", "desk_disable_shadow", "desk_shadow_bills", "desk_customer_balances", "desk_bills",
                      "desk_payments", "desk_customers", "desk_packages", "desk_billing_runs", "billing_settings",
                      "billing_customer_bills", "billing_customer_payments", "billing_customer_fetches",
                      "billing_daily_bills", "billing_customers"):
                c.execute(f"DELETE FROM {t}")
            c.execute("TRUNCATE desk_ledger_entries")
            c.execute("""INSERT INTO companies (id, name, slug, status) VALUES (1, 'CL', 'cl', 'active')
                         ON CONFLICT (id) DO NOTHING""")
            for h, code in ((301, "0301"), (302, "0302"), (303, "0303")):
                c.execute("""INSERT INTO billing_customers (company_id, header_id, customer_id, name, status, joined_on,
                                 monthly_bill, due, payable) VALUES (1, %s, %s, %s, 'Active', '2025-01-01', 500, 0, 0)""",
                          (h, code, "C" + code))
                c.execute("""INSERT INTO billing_customer_bills (company_id, customer_header_id, bill_master_id,
                                 period_year, period_month, bill_amount, due) VALUES (1, %s, %s, %s, %s, 500, 0)""",
                          (h, h * 10, ps.year, ps.month))
                c.execute("""INSERT INTO billing_customer_fetches (company_id, customer_header_id, section, fetched_at)
                             VALUES (1, %s, 'bills', now())""", (h,))
            # billing's daily bills: 9301 approved (and its receipt copied), 9302 approved (receipt not copied yet), 9303 deleted
            for dbid, h, ok, gone in ((9301, 301, True, False), (9302, 302, True, False), (9303, 303, False, True)):
                c.execute("""INSERT INTO billing_daily_bills (company_id, daily_bill_id, customer_header_id, collected_at,
                                 is_approved, is_deleted, receive_amount) VALUES (1, %s, %s, now() - interval '1 hour', %s, %s, 500)""",
                          (dbid, h, ok, gone))
            c.execute("""INSERT INTO billing_customer_payments (company_id, customer_header_id, bill_header_id, received_on,
                             paid, discount, daily_bill_id) VALUES (1, 301, 77001, %s, 500, 0, 9301)""", (today,))
        from engine import desk_billing as d
        cls.d = d
        d.import_company(cls.C)
        refs = {r["customer_code"]: r["id"] for r in db.all_rows("SELECT id, customer_code FROM desk_customers")}
        cls.refs = refs
        # the Desk took the three bills itself: 0301 and 0303 approved at once (ledger rows), 0302 pending
        with db.conn() as c:
            for code, dbid, status in (("0301", 9301, "approved"), ("0302", 9302, "pending"), ("0303", 9303, "approved")):
                pid = c.execute("""INSERT INTO desk_payments (company_id, customer_ref, paid_on, amount, discount, status, source,
                                       daily_bill_id) VALUES (1, %s, %s, 500, 0, %s, 'desk', %s) RETURNING id""",
                                (refs[code], today, status, dbid)).fetchone()["id"]
                if status == "approved":
                    c.execute("""INSERT INTO desk_ledger_entries (company_id, customer_ref, entry_date, kind, amount, payment_ref,
                                     source, source_ref) VALUES (1, %s, %s, 'payment', -500, %s, 'desk', %s)""",
                              (refs[code], today, pid, f"dpay:{pid}"))

    @classmethod
    def tearDownClass(cls):
        with cls.db.conn() as c:
            c.execute("TRUNCATE desk_ledger_entries")
            c.execute("DELETE FROM desk_payments")

    def test_no_double_count_and_billing_changes_follow(self):
        self.d.import_company(self.C)
        bal = {r["customer_code"]: float(r["balance"]) for r in self.db.all_rows(
            """SELECT c.customer_code, x.balance FROM desk_customer_balances x JOIN desk_customers c ON c.id = x.customer_ref""")}
        # 0301: bill 500 - the Desk's 500 (billing's receipt of the same money is left out) = 0, not -500
        # 0302: pending -> approved by billing -> 0;  0303: billing deleted it -> canceled, the bill is owed again
        self.assertEqual(bal, {"0301": 0.0, "0302": 0.0, "0303": 500.0})
        st = {r["customer_code"]: r["status"] for r in self.db.all_rows(
            """SELECT c.customer_code, p.status FROM desk_payments p JOIN desk_customers c ON c.id = p.customer_ref
               WHERE p.source = 'desk'""")}
        self.assertEqual(st, {"0301": "approved", "0302": "approved", "0303": "canceled"})
        # billing's copy of 0301's receipt is still kept (to show), only not in the ledger
        self.assertEqual(self.db.one("""SELECT count(*) AS n FROM desk_payments WHERE source = 'ispdigital'
                                        AND daily_bill_id = 9301""")["n"], 1)
        # a second run changes nothing (no second reverse or approve row)
        self.d.import_company(self.C)
        self.assertEqual(self.db.one("SELECT count(*) AS n FROM desk_ledger_entries WHERE source = 'desk'")["n"], 4)


if __name__ == "__main__":
    unittest.main()
