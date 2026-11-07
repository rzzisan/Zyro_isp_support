"""engine/desk_change.py (billing phase 5: package change, Left / back, password, secret check). The DB tests need a
throwaway PostgreSQL with the panel's migrations (skipped without one); billing and the router are fakes:
  ENGINE_DATABASE_URL=postgresql://postgres@127.0.0.1:5544/zyro_test PYTHONPATH=. .venv/bin/python -m unittest engine.tests.test_desk_change
"""
import os
import secrets
import unittest

from engine import desk_change as ch

URL = os.environ.get("ENGINE_DATABASE_URL", "")
PW = secrets.token_urlsafe(9)          # made up per run: the tests check it is never stored
OLD_PW = secrets.token_urlsafe(9)


class FakeBilling:
    def __init__(self, fail=None, values=None):
        self.fail = fail
        self.posts = []
        self.values = {"Status": "1", "PackageId": "3737", "Speed": "P-5_525", "MonthlyBillAmount": "1260",
                       "BillingLastDate": "9", "Password": OLD_PW, **(values or {})}

    def change_customer(self, header_id, replace, expect=None):
        self.posts.append((header_id, replace, expect))
        if self.fail:
            raise self.fail
        return dict(self.values)


class FakeRouter:
    """A router with PPP secrets {name: {profile, disabled, password}} and sessions."""

    def __init__(self, secrets_=None, profiles=("default", "P-5_525", "P-10_840", "BLOCKED-PROFILE")):
        self.secrets = {k: dict(v) for k, v in (secrets_ or {}).items()}
        self.profiles = profiles
        self.active = {k: True for k in self.secrets}
        self.calls = []

    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False

    def talk(self, command, attrs=None, query=None, raw=False):
        attrs = attrs or {}
        self.calls.append((command, {k: v for k, v in attrs.items() if k != "password"}))
        if command == "/ppp/secret/print":
            rows = [{".id": n, "name": n, "profile": s["profile"], "disabled": "true" if s["disabled"] else "false"}
                    for n, s in self.secrets.items() if not query or query.get("name") == n]
            return rows
        if command == "/ppp/profile/print":
            return [{"name": p} for p in self.profiles]
        if command == "/ppp/active/print":
            n = (query or {}).get("name")
            return [{".id": "a-" + n, "name": n, "uptime": "1h"}] if self.active.get(n) else []
        if command == "/ppp/active/remove":
            self.active[attrs[".id"][2:]] = False
            return []
        if command == "/ppp/secret/set":
            s = self.secrets[attrs[".id"]]
            for k in ("profile", "password"):
                if k in attrs:
                    s[k] = attrs[k]
            if "disabled" in attrs:
                s["disabled"] = attrs["disabled"] == "yes"
            return []
        if command == "/ppp/secret/remove":
            del self.secrets[attrs[".id"]]
            return []
        if command == "/ppp/secret/add":
            self.secrets[attrs["name"]] = {"profile": attrs["profile"], "disabled": False, "password": attrs["password"]}
            return []
        raise AssertionError(command)


class RuleTest(unittest.TestCase):
    def test_billing_fields(self):
        self.assertEqual(ch.billing_changes("package", {"package_id": 3736, "profile": "P-10_840", "monthly_bill": "840"}),
                         {"PackageId": "3736", "Speed": "P-10_840", "MonthlyBillAmount": "840"})
        self.assertEqual(ch.billing_changes("left", {"status_id": "26"}),
                         {"Status": "26", "MonthlyBillAmount": "0", "BillingLastDate": "0"})
        self.assertEqual(ch.billing_changes("back", {"status_id": "4", "status_name": "Free", "monthly_bill": 500,
                                                     "expire_day": 9})["MonthlyBillAmount"], "0")
        self.assertEqual(ch.billing_changes("password", {"password": PW}), {"Password": PW})
        self.assertEqual(ch.billing_changes("remove", {}), {})

    def test_validate(self):
        self.assertEqual(ch.validate("package", {"package_id": "1", "profile": "x", "monthly_bill": "5"}), [])
        self.assertIn("profile: লাগবে", ch.validate("package", {"package_id": "1", "monthly_bill": "5"}))
        self.assertTrue(ch.validate("left", {"status_id": "1", "status_name": "Active"}))
        self.assertEqual(ch.validate("left", {"status_id": "26", "status_name": "Left"}), [])
        self.assertTrue(ch.validate("back", {"status_id": "1", "status_name": "Active"}))   # bill + expire needed
        self.assertEqual(ch.validate("back", {"status_id": "1", "status_name": "Active", "monthly_bill": 1, "expire_day": 9}), [])
        self.assertTrue(ch.validate("password", {"password": "abc"}))

    def test_router_package_keeps_block(self):
        r = FakeRouter({"u1": {"profile": "BLOCKED-PROFILE", "disabled": False}})
        out = ch.router_step(r, "package", "u1", {"profile": "P-10_840"}, None)
        self.assertEqual(r.secrets["u1"]["profile"], "BLOCKED-PROFILE")
        self.assertIn("চালু হলে", out["note"])
        self.assertIsNone(ch.secret_problem("package", out["secret"], {"profile": "P-10_840"}))

    def test_router_package_sets_profile_and_drops_session(self):
        r = FakeRouter({"u1": {"profile": "P-5_525", "disabled": False}})
        out = ch.router_step(r, "package", "u1", {"profile": "P-10_840"}, None)
        self.assertEqual(out["secret"]["profile"], "P-10_840")
        self.assertFalse(r.active["u1"])

    def test_router_left_and_back(self):
        r = FakeRouter({"u1": {"profile": "P-5_525", "disabled": False}})
        ch.router_step(r, "left", "u1", {}, None)
        self.assertNotIn("u1", r.secrets)
        out = ch.router_step(r, "back", "u1", {"profile": "P-5_525"}, OLD_PW)
        self.assertEqual(r.secrets["u1"]["password"], OLD_PW)
        self.assertEqual(out["secret"]["profile"], "P-5_525")
        self.assertNotIn(OLD_PW, str(r.calls))

    def test_router_password(self):
        r = FakeRouter({"u1": {"profile": "P-5_525", "disabled": False}})
        ch.router_step(r, "password", "u1", {}, PW)
        self.assertEqual(r.secrets["u1"]["password"], PW)
        self.assertFalse(r.active["u1"])


@unittest.skipUnless(URL, "set ENGINE_DATABASE_URL to a throwaway database with the panel's migrations")
class RunTest(unittest.TestCase):
    def setUp(self):
        from engine import db
        self.db = db
        for t in ("desk_customer_changes", "mikrotik_routers", "billing_customers"):
            db.execute(f"DELETE FROM {t}")
        company = db.one("SELECT id FROM companies ORDER BY id LIMIT 1")
        self.company = company["id"] if company else db.execute(
            "INSERT INTO companies (name, slug) VALUES ('T', 't') RETURNING id")["id"]
        db.execute("""INSERT INTO billing_settings (company_id, connection_control, connection_test_prefix)
                      VALUES (%s, 'test', 'zyrotest') ON CONFLICT (company_id) DO UPDATE
                      SET connection_control = 'test', connection_test_prefix = 'zyrotest'""", (self.company,))
        db.execute("""INSERT INTO mikrotik_routers (company_id, identity, host, api_port, username, password, enabled, billing_server)
                      VALUES (%s, 'CLN-3', '10.0.0.3', 8728, 'api', 'x', true, 'CLN_3')""", (self.company,))
        for hid, code, user, left in ((16063, "6061", "zyrotest5.phase5", False), (16056, "6054", "zyrotest01", True),
                                      (500, "100", "bp.rahim", False)):
            db.execute("""INSERT INTO billing_customers (company_id, header_id, customer_id, username, name, status, is_left,
                              server, speed, monthly_bill, created_at, updated_at)
                          VALUES (%s,%s,%s,%s,'T',%s,%s,'CLN-3','P-5_525',1260, now(), now())""",
                       (self.company, hid, code, user, "Left" if left else "Active", left))
        self.router = FakeRouter({"zyrotest5.phase5": {"profile": "P-5_525", "disabled": False},
                                  "zyrotest01": {"profile": "P-5_525", "disabled": False},
                                  "stray": {"profile": "default", "disabled": False}})

    def run_it(self, kind, hid, params, api=None):
        return ch.run(self.company, kind, hid, params, api=api or FakeBilling(), connect=lambda r: self.router,
                      sleep=lambda s: None)

    def test_package_done(self):
        api = FakeBilling()
        row = self.run_it("package", 16063, {"package_id": "3736", "profile": "P-10_840", "monthly_bill": "840"}, api)
        self.assertEqual(row["status"], "done")
        self.assertEqual(api.posts[0][1]["Speed"], "P-10_840")
        self.assertEqual(self.router.secrets["zyrotest5.phase5"]["profile"], "P-10_840")

    def test_password_never_stored(self):
        row = self.run_it("password", 16063, {"password": PW})
        self.assertEqual(row["status"], "done")
        dump = str(self.db.all_rows("SELECT * FROM desk_customer_changes")) + str(
            self.db.all_rows("SELECT changes, note FROM activity_logs WHERE subject_type = 'desk_customer_change'"))
        self.assertNotIn(PW, dump)
        self.assertNotIn(OLD_PW, dump)

    def test_left_then_back(self):
        row = self.run_it("left", 16063, {"status_id": "26", "status_name": "Left"})
        self.assertEqual(row["status"], "done")
        self.assertNotIn("zyrotest5.phase5", self.router.secrets)
        del self.router.secrets["zyrotest01"]                      # a Left customer without a secret
        row = self.run_it("back", 16056, {"status_id": "1", "status_name": "Active", "monthly_bill": "1260", "expire_day": "9"})
        self.assertEqual(row["status"], "done")
        self.assertEqual(self.router.secrets["zyrotest01"]["password"], OLD_PW)

    def test_refusals_change_nothing(self):
        api = FakeBilling()
        with self.assertRaises(ch.Refused):
            self.run_it("package", 500, {"package_id": "1", "profile": "x", "monthly_bill": "1"}, api)   # not a test username
        with self.assertRaises(ch.Refused):
            self.run_it("back", 16063, {"status_id": "1", "status_name": "Active", "monthly_bill": 1, "expire_day": 9}, api)
        with self.assertRaises(ch.Refused):
            self.run_it("remove", 16063, {}, api)                                    # not Left
        self.assertEqual(api.posts, [])

    def test_billing_refused_leaves_router(self):
        row = self.run_it("left", 16063, {"status_id": "26", "status_name": "Left"}, FakeBilling(fail=RuntimeError("HTTP 200")))
        self.assertEqual(row["status"], "failed")
        self.assertIn("zyrotest5.phase5", self.router.secrets)

    def test_audit(self):
        out = ch.audit(self.company, connect=lambda r: self.router)
        self.assertEqual([r["username"] for r in out["left_present"]], ["zyrotest01"])
        self.assertEqual([r["username"] for r in out["missing"]], ["bp.rahim"])
        self.assertEqual([r["username"] for r in out["extra"]], ["stray"])
        row = self.run_it("remove", 16056, {})
        self.assertEqual(row["status"], "done")
        self.assertNotIn("zyrotest01", self.router.secrets)


if __name__ == "__main__":
    unittest.main()
