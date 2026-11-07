"""engine/desk_customer.py (billing phase 5). No database or router needed:
  PYTHONPATH=. python3 -m unittest engine.tests.test_desk_customer
"""
import datetime as dt
import secrets
import unittest

from engine import desk_customer as dc


def form(**kw):
    # the values sent for billing's test customer 16063 (2026-11-07); a random password per run
    base = {"name": "Zyro Phase5 Test", "mobile": "01777858289", "server_id": "4", "zone_id": "84",
            "connection_type_id": "76", "package_id": "3737", "profile": "P-5_525", "monthly_bill": "1260",
            "customer_type_id": "5", "status_id": "1", "status_name": "Active", "username": "zyrotest5.phase5",
            "password": secrets.token_urlsafe(8), "joined": "2026-11-07", "bill_start": "12/2026", "expire_day": "9",
            "remarks": "Zyro Phase5 TEST - safe to delete"}
    base.update(kw)
    return base


class FormTest(unittest.TestCase):
    def test_valid_form_has_no_errors(self):
        self.assertEqual(dc.validate_new(form()), [])

    def test_missing_required_fields_are_listed(self):
        errors = dc.validate_new(form(username="", zone_id=" "))
        self.assertIn("username: লাগবে", errors)
        self.assertIn("zone_id: লাগবে", errors)

    def test_free_needs_no_bill_or_expire(self):
        self.assertEqual(dc.validate_new(form(status_name="Free", status_id="4", monthly_bill="", expire_day="")), [])
        self.assertIn("monthly_bill: লাগবে", dc.validate_new(form(monthly_bill="")))

    def test_mobile_length(self):
        self.assertTrue(dc.mobile_ok("01777858289"))           # 11
        self.assertTrue(dc.mobile_ok("+8801777858289"))        # 13 with +88
        self.assertFalse(dc.mobile_ok("017778582"))            # 9
        self.assertFalse(dc.mobile_ok("+88017778582890123"))   # too long
        self.assertTrue(any(e.startswith("mobile") for e in dc.validate_new(form(mobile="123"))))
        self.assertEqual(dc.local_mobile("+8801777858289"), "01777858289")
        self.assertEqual(dc.local_mobile("01777858289"), "01777858289")

    def test_expire_day_range(self):
        self.assertTrue(any(e.startswith("expire_day") for e in dc.validate_new(form(expire_day="29"))))
        self.assertTrue(any(e.startswith("expire_day") for e in dc.validate_new(form(expire_day="0"))))
        self.assertEqual(dc.validate_new(form(expire_day="28")), [])

    def test_username_and_bill_start_format(self):
        self.assertTrue(any(e.startswith("username") for e in dc.validate_new(form(username="রহিম"))))
        self.assertTrue(any(e.startswith("username") for e in dc.validate_new(form(username="a b"))))
        self.assertTrue(any(e.startswith("bill_start") for e in dc.validate_new(form(bill_start="2026-12"))))
        self.assertTrue(any(e.startswith("monthly_bill") for e in dc.validate_new(form(monthly_bill="abc"))))


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
        self.assertEqual(dc.bill_for("Active", "1260"), 1260)
        self.assertEqual(dc.expire_for("Active", "9"), 9)


class BillingFormTest(unittest.TestCase):
    def test_matches_the_16063_post(self):
        out = dc.billing_form(form())
        self.assertEqual(out["ServerId"], "4")
        self.assertEqual(out["ProtocolId"], "30")
        self.assertEqual(out["ZoneId"], "84")
        self.assertEqual(out["ConnectionTypeId"], "76")
        self.assertEqual(out["PackageId"], "3737")
        self.assertEqual(out["Speed"], "P-5_525")
        self.assertEqual(out["MonthlyBillAmount"], "1260")
        self.assertEqual(out["CustomerTypeId"], "5")
        self.assertEqual(out["Status"], "1")
        self.assertEqual(out["MobileNumber"], "01777858289")
        self.assertEqual(out["SecondaryJoiningDate"], "07/11/2026")
        self.assertEqual(out["JoiningDate"], "12/2026")
        self.assertEqual(out["BillingLastDate"], "9")
        self.assertEqual(out["IsVIPClient"], "false")
        self.assertEqual(out["IsSmsSendable"], "false")

    def test_free_has_zero_bill_and_expire(self):
        out = dc.billing_form(form(status_name="Free", status_id="4"))
        self.assertEqual(out["MonthlyBillAmount"], "0")
        self.assertEqual(out["BillingLastDate"], "0")

    def test_safe_copy_drops_password(self):
        self.assertNotIn("password", dc.safe_copy(form()))
        self.assertEqual(dc.safe_copy(form())["username"], "zyrotest5.phase5")


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

    def test_secret_check_after_billing_saved(self):
        self.assertIsNone(dc.secret_matches({"profile": "P-5_525", "disabled": False}, form()))
        self.assertIn("তৈরি হয়নি", dc.secret_matches(None, form()))
        self.assertIn("profile", dc.secret_matches({"profile": "default", "disabled": False}, form()))
        self.assertIn("বন্ধ", dc.secret_matches({"profile": "P-5_525", "disabled": True}, form()))
        self.assertIsNone(dc.secret_matches({"profile": "P-5_525", "disabled": True},
                                            form(status_name="Inactive", status_id="2")))


if __name__ == "__main__":
    unittest.main()
