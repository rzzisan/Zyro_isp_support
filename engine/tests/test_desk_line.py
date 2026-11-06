"""engine/desk_line.py (billing phase 3, the Desk switches lines on MikroTik). A fake router, no network:
  PYTHONPATH=. .venv/bin/python -m unittest engine.tests.test_desk_line
run() with a throwaway PostgreSQL that has the panel's migrations (skipped without one):
  ENGINE_DATABASE_URL=postgresql://postgres@127.0.0.1:5544/zyro_test PYTHONPATH=. .venv/bin/python -m unittest engine.tests.test_desk_line
"""
import os
import unittest

from engine import desk_line as dl
from engine.mikrotik import RouterOSError

URL = os.environ.get("ENGINE_DATABASE_URL", "")


class FakeRouter:
    """Secrets, profiles and active sessions; a removed session redials at once unless the secret is disabled."""

    def __init__(self, profile="15Mbps", disabled=False, online=True, stuck=0, ignore_set=0, profiles=None, deny=False):
        self.secret = {".id": "*1", "name": "u1", "profile": profile, "disabled": "true" if disabled else "false",
                       "password": "never-read"}
        self.profiles = profiles or ["default", "15Mbps", "BLOCKED-PROFILE"]
        self.active = [{".id": "*a1", "name": "u1", "uptime": "3h"}] if online else []
        self.stuck, self.ignore_set, self.deny, self.n, self.calls = stuck, ignore_set, deny, 1, []

    def __enter__(self):
        return self

    def __exit__(self, *a):
        pass

    def talk(self, cmd, attrs=None, query=None):
        attrs = attrs or {}
        self.calls.append((cmd, dict(attrs)))
        if cmd == "/ppp/profile/print":
            return [{"name": p} for p in self.profiles]
        if cmd == "/ppp/secret/print":
            if query and query.get("name") != self.secret["name"]:
                return []
            keep = attrs.get(".proplist", "").split(",")
            return [{k: v for k, v in self.secret.items() if k in keep}]
        if cmd == "/ppp/secret/set":
            if self.deny:
                raise RouterOSError("not enough permissions (9)")
            if self.ignore_set:
                self.ignore_set -= 1
                return []
            for k in ("profile", "disabled"):
                if k in attrs:
                    self.secret[k] = {"yes": "true", "no": "false"}.get(attrs[k], attrs[k])
            return []
        if cmd == "/ppp/active/print":
            return [dict(a) for a in self.active if not query or a["name"] == query.get("name")]
        if cmd == "/ppp/active/remove":
            if self.stuck:
                self.stuck -= 1
                return []
            self.active = [a for a in self.active if a[".id"] != attrs[".id"]]
            if self.secret["disabled"] == "false":     # the CPE dials in again
                self.n += 1
                self.active.append({".id": f"*a{self.n}", "name": "u1", "uptime": "1s"})
            return []
        raise AssertionError(cmd)


NOSLEEP = lambda s: None  # noqa: E731


class ApplyTest(unittest.TestCase):
    def test_disable_moves_to_block_profile_and_restarts_the_session(self):
        r = FakeRouter()
        out = dl.apply(r, "disable", "u1", "BLOCKED-PROFILE", 3, sleep=NOSLEEP)
        self.assertTrue(out["ok"])
        self.assertEqual((out["attempts"], out["killed"], out["profile_after"], out["session_after"]),
                         (1, 1, "BLOCKED-PROFILE", "1s"))
        # the password is never asked for
        self.assertTrue(all("password" not in a.get(".proplist", "") for c, a in r.calls if c == "/ppp/secret/print"))

    def test_enable_offline_customer(self):
        r = FakeRouter(profile="BLOCKED-PROFILE", online=False)
        out = dl.apply(r, "enable", "u1", "15Mbps", 3, sleep=NOSLEEP)
        self.assertTrue(out["ok"])
        self.assertEqual((out["killed"], out["session_after"]), (0, None))

    def test_retry_when_the_router_did_not_take_it(self):
        r = FakeRouter(ignore_set=2)
        out = dl.apply(r, "disable", "u1", "BLOCKED-PROFILE", 3, sleep=NOSLEEP)
        self.assertTrue(out["ok"])
        self.assertEqual(out["attempts"], 3)

    def test_fails_after_the_retries(self):
        r = FakeRouter(stuck=10)
        out = dl.apply(r, "disable", "u1", "BLOCKED-PROFILE", 2, sleep=NOSLEEP)
        self.assertFalse(out["ok"])
        self.assertEqual((out["attempts"], out["error"]), (3, "আগের সেশন এখনো চালু"))

    def test_read_only_api_user_stops_at_once(self):
        out = dl.apply(FakeRouter(deny=True), "disable", "u1", "BLOCKED-PROFILE", 3, sleep=NOSLEEP)
        self.assertFalse(out["ok"])
        self.assertEqual((out["attempts"], out["error"]), (1, "রাউটারের API ইউজারের লেখার অনুমতি নেই"))

    def test_deactivate_and_activate(self):
        r = FakeRouter()
        out = dl.apply(r, "deactivate", "u1", None, 3, sleep=NOSLEEP)
        self.assertTrue(out["ok"])
        self.assertEqual((out["disabled_after"], out["session_after"]), (True, None))
        out = dl.apply(r, "activate", "u1", None, 3, sleep=NOSLEEP)
        self.assertTrue(out["ok"])
        self.assertIs(out["disabled_after"], False)


class GuardTest(unittest.TestCase):
    S = {"line_control": "test", "line_test_codes": ["0001", "Rubel_CL"], "mikrotik_retries": 3, "billing_mode": "ispdigital"}

    def test_only_test_accounts(self):
        self.assertIsNone(dl.allowed(self.S, {"customer_code": "0001", "username": "x"}))
        self.assertIsNone(dl.allowed(self.S, {"customer_code": "0420", "username": "rubel_cl"}))
        self.assertIsNotNone(dl.allowed(self.S, {"customer_code": "0420", "username": "other"}))
        self.assertIsNotNone(dl.allowed({**self.S, "line_control": "off"}, {"customer_code": "0001"}))
        self.assertIsNotNone(dl.allowed({**self.S, "line_control": "all"}, {"customer_code": "0420"}))
        self.assertIsNone(dl.allowed({**self.S, "line_control": "all", "billing_mode": "desk"}, {"customer_code": "0420"}))

    def test_block_profile(self):
        self.assertEqual(dl.block_profile(FakeRouter(), {}), "BLOCKED-PROFILE")
        self.assertEqual(dl.block_profile(FakeRouter(profiles=["a", "DueDisabled"]), {}), "DueDisabled")
        with self.assertRaises(RouterOSError):
            dl.block_profile(FakeRouter(profiles=["a", "b"]), {})
        with self.assertRaises(RouterOSError):
            dl.block_profile(FakeRouter(), {"block_profile": "NOPE"})


@unittest.skipUnless(URL, "needs ENGINE_DATABASE_URL")
class RunTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        from engine import db
        cls.db = db
        with db.conn() as c:
            for t in ("desk_line_actions", "desk_customers", "billing_settings", "mikrotik_routers", "billing_customers"):
                c.execute(f"DELETE FROM {t}")
            c.execute("INSERT INTO companies (id, name, slug, status) VALUES (1, 'CL', 'cl', 'active') ON CONFLICT (id) DO NOTHING")
            c.execute("""INSERT INTO mikrotik_routers (id, company_id, host, api_port, username, password, identity, billing_server,
                         enabled, created_at, updated_at) VALUES (9, 1, '10.0.0.1', 8728, 'api', 'x', 'CLN_4', 'CLN_4', true, now(), now())""")
            c.execute("""INSERT INTO desk_customers (id, company_id, header_id, customer_code, username, name, status, monthly_bill,
                         created_at, updated_at) VALUES (51, 1, 501, '0001', 'u1', 'Zisan', 'Active', 500, now(), now()),
                         (52, 1, 502, '0420', 'u2', 'Other', 'Active', 500, now(), now())""")
            c.execute("""INSERT INTO billing_settings (company_id, line_control, line_test_codes, mikrotik_retries, created_at, updated_at)
                         VALUES (1, 'test', '["0001"]', 3, now(), now())""")

    def test_disable_then_enable_gives_the_old_profile_back(self):
        r = FakeRouter()
        row = dl.run(1, "disable", code="0001", sleep=NOSLEEP, connect=lambda x: r)
        self.assertEqual((row["status"], row["profile_before"], row["profile_after"], row["router_name"]),
                         ("done", "15Mbps", "BLOCKED-PROFILE", "CLN_4"))
        c = self.db.one("SELECT previous_profile, router_id FROM desk_customers WHERE id = 51")
        self.assertEqual((c["previous_profile"], c["router_id"]), ("15Mbps", 9))
        # turning it off again keeps 15Mbps as the profile to give back
        dl.run(1, "disable", code="0001", sleep=NOSLEEP, connect=lambda x: r)
        self.assertEqual(self.db.one("SELECT previous_profile FROM desk_customers WHERE id = 51")["previous_profile"], "15Mbps")
        row = dl.run(1, "enable", username="U1", sleep=NOSLEEP, connect=lambda x: r)
        self.assertEqual((row["status"], row["profile_after"]), ("done", "15Mbps"))
        self.assertEqual(r.secret["profile"], "15Mbps")
        self.assertTrue(self.db.one("SELECT 1 FROM activity_logs WHERE action = 'line.enable' AND customer_code = '0001'"))

    def test_refused_for_a_customer_not_in_the_test_list(self):
        with self.assertRaises(dl.Refused):
            dl.run(1, "disable", code="0420", sleep=NOSLEEP, connect=lambda x: FakeRouter())
        self.assertIsNone(self.db.one("SELECT 1 FROM desk_line_actions WHERE customer_code = '0420'"))

    def test_unreachable_router_is_a_failure(self):
        def down(x):
            raise OSError("timed out")
        row = dl.run(1, "disable", code="0001", sleep=NOSLEEP, connect=down)
        self.assertEqual(row["status"], "failed")
        self.assertIn("CLN_4", row["error"])


if __name__ == "__main__":
    unittest.main()
