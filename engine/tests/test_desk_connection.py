"""engine/desk_connection.py (billing phase 5, নতুন সংযোগ to billing). Needs a throwaway PostgreSQL with the panel's
migrations (skipped without one); billing and the router are fakes, nothing leaves the machine:
  ENGINE_DATABASE_URL=postgresql://postgres@127.0.0.1:5544/zyro_test PYTHONPATH=. .venv/bin/python -m unittest engine.tests.test_desk_connection
"""
import os
import unittest

from engine import desk_connection as dn

URL = os.environ.get("ENGINE_DATABASE_URL", "")


def form(**kw):
    base = {"name": "Zyro Phase5 Test", "mobile": "+8801777858289", "server_id": "4", "server_name": "CLN-3",
            "zone_id": "84", "connection_type_id": "76", "package_id": "3737", "package_name": "20 Mbps_1260",
            "profile": "P-5_525", "monthly_bill": "1260", "customer_type_id": "5", "status_id": "1",
            "status_name": "Active", "username": "zyrotest5.t1", "password": "Secret-Pw-123", "joined": "2026-11-07",
            "bill_start": "12/2026", "expire_day": "9"}
    base.update(kw)
    return base


class FakeBilling:
    def __init__(self, taken=(), fail=None, made_on_fail=False):
        self.taken = {t.lower() for t in taken}
        self.fail = fail
        self.made_on_fail = made_on_fail
        self.posts = []

    def username_taken(self, username):
        return username.lower() in self.taken

    def create_customer(self, fields):
        self.posts.append(fields)
        if self.fail:
            if self.made_on_fail:
                self.taken.add(fields["UserName"].lower())
            raise self.fail
        self.taken.add(fields["UserName"].lower())
        return 16100

    def quick_search(self, term):
        return [{"id": 16100, "text": f"{term} (6070)"}]


class FakeRouter:
    def __init__(self, secret):
        self.secret = secret

    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False

    def talk(self, command, attrs=None, query=None, raw=False):
        return [self.secret] if self.secret else []


class RuleTest(unittest.TestCase):
    def test_guard(self):
        self.assertIn("বন্ধ", dn.allowed({"control": "off", "prefix": "zyrotest"}, "zyrotest5.a"))
        self.assertIsNone(dn.allowed({"control": "test", "prefix": "zyrotest"}, "ZyroTest5.a"))
        self.assertIn("পরীক্ষার", dn.allowed({"control": "test", "prefix": "zyrotest"}, "bp.rahim12"))
        self.assertIn("prefix", dn.allowed({"control": "test", "prefix": ""}, "zyrotest5.a"))
        self.assertIsNone(dn.allowed({"control": "all", "prefix": ""}, "bp.rahim12"))

    def test_password_has_no_lookalikes(self):
        for _ in range(50):
            p = dn.new_password()
            self.assertEqual(len(p), 10)
            self.assertFalse(set(p) & set("0O1lI"))


@unittest.skipUnless(URL, "set ENGINE_DATABASE_URL to a throwaway database with the panel's migrations")
class RunTest(unittest.TestCase):
    def setUp(self):
        from engine import db
        self.db = db
        db.execute("DELETE FROM desk_connections")
        db.execute("DELETE FROM mikrotik_routers")
        company = db.one("SELECT id FROM companies ORDER BY id LIMIT 1")
        self.company = company["id"] if company else db.execute(
            "INSERT INTO companies (name, slug) VALUES ('T', 't') RETURNING id")["id"]
        db.execute("""INSERT INTO billing_settings (company_id, connection_control, connection_test_prefix)
                      VALUES (%s, 'test', 'zyrotest') ON CONFLICT (company_id) DO UPDATE
                      SET connection_control = 'test', connection_test_prefix = 'zyrotest'""", (self.company,))
        db.execute("""INSERT INTO mikrotik_routers (company_id, identity, host, api_port, username, password, enabled, billing_server)
                      VALUES (%s, 'CLN-3', '10.0.0.3', 8728, 'api', 'x', true, 'CLN_3')""", (self.company,))

    def run_it(self, api, secret=None, **kw):
        return dn.run(self.company, form(**kw), api=api, connect=lambda r: FakeRouter(secret), sleep=lambda s: None)

    def test_done_and_password_never_kept(self):
        api = FakeBilling()
        row = self.run_it(api, {".id": "*1", "name": "zyrotest5.t1", "profile": "P-5_525", "disabled": "false"})
        self.assertEqual(row["status"], "done")
        self.assertEqual(row["header_id"], 16100)
        self.assertEqual(row["customer_code"], "6070")
        self.assertEqual(row["secret_profile"], "P-5_525")
        self.assertFalse(row["secret_disabled"])
        self.assertEqual(api.posts[0]["Password"], "Secret-Pw-123")
        self.assertEqual(api.posts[0]["MobileNumber"], "01777858289")
        dump = str(self.db.all_rows("SELECT * FROM desk_connections")) + str(
            self.db.all_rows("SELECT changes, note FROM activity_logs WHERE subject_type = 'desk_connection'"))
        self.assertNotIn("Secret-Pw-123", dump)

    def test_secret_missing_is_check(self):
        row = self.run_it(FakeBilling(), None)
        self.assertEqual(row["status"], "check")
        self.assertIn("secret", row["error"])

    def test_refusals_send_nothing(self):
        api = FakeBilling(taken=["zyrotest5.t1"])
        with self.assertRaises(dn.Refused):
            self.run_it(api)                                     # username taken in billing
        with self.assertRaises(dn.Refused):
            self.run_it(FakeBilling(), username="bp.rahim12")   # not a test username
        with self.assertRaises(dn.Refused):
            self.run_it(FakeBilling(), mobile="123")            # form error
        self.assertEqual(api.posts, [])
        self.db.execute("UPDATE billing_settings SET connection_control = 'off' WHERE company_id = %s", (self.company,))
        with self.assertRaises(dn.Refused):
            self.run_it(FakeBilling())

    def test_second_send_of_same_username_refused(self):
        self.run_it(FakeBilling(), {"profile": "P-5_525", "disabled": "false"})
        with self.assertRaises(dn.Refused):
            self.run_it(FakeBilling(), {"profile": "P-5_525", "disabled": "false"})

    def test_lost_answer_is_unsure_not_retried(self):
        api = FakeBilling(fail=TimeoutError("read timeout"), made_on_fail=True)
        row = self.run_it(api)
        self.assertEqual(row["status"], "unsure")
        self.assertEqual(len(api.posts), 1)

    def test_refused_by_billing_is_failed_and_can_be_sent_again(self):
        row = self.run_it(FakeBilling(fail=RuntimeError("HTTP 200")))
        self.assertEqual(row["status"], "failed")
        row = self.run_it(FakeBilling(), {"profile": "P-5_525", "disabled": "false"})
        self.assertEqual(row["status"], "done")


if __name__ == "__main__":
    unittest.main()
