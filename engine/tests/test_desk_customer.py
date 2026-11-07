"""engine/desk_customer.py (billing phase 5). No database or router needed:
  PYTHONPATH=. python3 -m unittest engine.tests.test_desk_customer
"""
import datetime as dt
import unittest

from engine import desk_customer as dc


def form(**kw):
    base = {"name": "Rahim Uddin", "mobile": "+8801777858289", "zone": "1", "router": "CLN_3", "connection_type": "fiber",
            "package": "20 Mbps", "monthly_bill": "520", "customer_type": "home", "status": "active",
            "username": "bp.rahim1", "password": "x", "joined": "2026-11-07", "bill_start": "12/2026", "expire_day": "9"}
    base.update(kw)
    return base


class FormTest(unittest.TestCase):
    def test_valid_form_has_no_errors(self):
        self.assertEqual(dc.validate_new(form()), [])

    def test_missing_required_fields_are_listed(self):
        errors = dc.validate_new(form(username="", zone=" "))
        self.assertIn("username: লাগবে", errors)
        self.assertIn("zone: লাগবে", errors)

    def test_mobile_length(self):
        self.assertTrue(dc.mobile_ok("01777858289"))           # 11
        self.assertTrue(dc.mobile_ok("+8801777858289"))        # 13 with +88
        self.assertFalse(dc.mobile_ok("017778582"))            # 9
        self.assertFalse(dc.mobile_ok("+88017778582890123"))   # too long
        self.assertTrue(any(e.startswith("mobile") for e in dc.validate_new(form(mobile="123"))))

    def test_expire_day_range(self):
        self.assertTrue(any(e.startswith("expire_day") for e in dc.validate_new(form(expire_day="29"))))
        self.assertTrue(any(e.startswith("expire_day") for e in dc.validate_new(form(expire_day="0"))))
        self.assertEqual(dc.validate_new(form(expire_day="28")), [])


class DefaultsTest(unittest.TestCase):
    def test_expire_default_is_day_plus_two_capped(self):
        self.assertEqual(dc.default_expire_day(dt.date(2026, 11, 7)), 9)
        self.assertEqual(dc.default_expire_day(dt.date(2026, 11, 27)), 28)
        self.assertEqual(dc.default_expire_day(dt.date(2026, 11, 30)), 28)

    def test_bill_start_is_next_month(self):
        self.assertEqual(dc.default_bill_start(dt.date(2026, 11, 7)), "12/2026")
        self.assertEqual(dc.default_bill_start(dt.date(2026, 12, 31)), "01/2027")

    def test_username_suggestion(self):
        self.assertEqual(dc.username_suggestion("bp.", "Rahim Uddin", 1234), "bp.rahim1234")
        self.assertEqual(dc.username_suggestion(None, "মোঃ রহিম", 7), "7")  # Bangla name gives no latin letters
        self.assertEqual(dc.username_suggestion("", "ABDUL KARIM", 5), "abdul5")

    def test_no_bill_statuses(self):
        for status in ("Personal", "free", "LEFT"):
            self.assertEqual(dc.bill_for(status, 520), 0)
            self.assertEqual(dc.expire_for(status, 9), 0)
        self.assertEqual(dc.bill_for("Active", 520), 520)
        self.assertEqual(dc.expire_for("Active", 9), 9)
        self.assertEqual(dc.bill_for("Inactive", None), 0)


class BillingFormTest(unittest.TestCase):
    def test_active_payload(self):
        out = dc.billing_form(form(profile="P-5_525"))
        self.assertEqual(out["MobileNumber"], "8801777858289")
        self.assertEqual(out["Speed"], "P-5_525")
        self.assertEqual(out["MonthlyBill"], 520)
        self.assertEqual(out["BillingLastDate"], 9)
        self.assertEqual(out["JoiningDate"], "12/2026")
        self.assertEqual(out["Protocol"], "PPPoE")

    def test_free_has_zero_bill_and_expire(self):
        out = dc.billing_form(form(status="Free", profile="P-5_525"))
        self.assertEqual(out["MonthlyBill"], 0)
        self.assertEqual(out["BillingLastDate"], 0)


class SecretTest(unittest.TestCase):
    def test_no_secret_active_creates(self):
        self.assertEqual(dc.secret_actions(None, {"status": "active", "profile": "20M"}), ["create"])

    def test_no_secret_inactive_creates_disabled(self):
        self.assertEqual(dc.secret_actions(None, {"status": "inactive", "profile": "20M"}), ["create", "disable"])

    def test_left_removes_existing_secret_only(self):
        self.assertEqual(dc.secret_actions({"profile": "20M", "disabled": False}, {"status": "left"}), ["remove"])
        self.assertEqual(dc.secret_actions(None, {"status": "left"}), [])

    def test_package_change_sets_profile(self):
        self.assertEqual(dc.secret_actions({"profile": "10M", "disabled": False},
                                           {"status": "active", "profile": "20M"}), ["set_profile:20M"])

    def test_already_right_is_noop(self):
        self.assertEqual(dc.secret_actions({"profile": "20M", "disabled": False},
                                           {"status": "active", "profile": "20M"}), [])

    def test_disabled_secret_comes_back_when_active(self):
        self.assertEqual(dc.secret_actions({"profile": "20M", "disabled": True},
                                           {"status": "active", "profile": "20M"}), ["enable"])

    def test_inactive_disables_and_keeps_profile(self):
        self.assertEqual(dc.secret_actions({"profile": "20M", "disabled": False},
                                           {"status": "inactive", "profile": "20M"}), ["disable"])


if __name__ == "__main__":
    unittest.main()
