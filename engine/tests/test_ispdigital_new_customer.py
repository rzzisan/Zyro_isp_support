"""engine/ispdigital.py new customer form helpers (billing phase 5), against a canned page; no network:
  PYTHONPATH=. python3 -m unittest engine.tests.test_ispdigital_new_customer
"""
import unittest

import httpx

from engine import ispdigital as isp

PAGE = """<form action="/Customer/Add" method="post">
<input name="__RequestVerificationToken" type="hidden" value="rv&amp;1" />
<input type="hidden" name="FormSubmissionToken" value="fst-9" />
<select id="ServerId" name="ServerId" class="x"><option value="">--Select--</option>
<option value="1">CLNBD</option><option value="4">CLN-3</option><option value="5">CLN_4</option></select>
<select name="Status"><option value="1" selected="selected">Active</option><option value="26">Left</option></select>
<select name="ZoneId"><option value="84">Zisan</option><option value="68">&#2480;&#2495;&#2475;&#2494;&#2468;</option></select>
</form>"""


class ParseTest(unittest.TestCase):
    def test_tokens(self):
        self.assertEqual(isp.hidden_value(PAGE, "__RequestVerificationToken"), "rv&1")
        self.assertEqual(isp.hidden_value(PAGE, "FormSubmissionToken"), "fst-9")
        self.assertIsNone(isp.hidden_value(PAGE, "Nope"))

    def test_selects(self):
        out = isp.parse_selects(PAGE, ("ServerId", "Status", "ZoneId", "PackageId"))
        self.assertEqual(out["ServerId"], [{"id": "1", "name": "CLNBD"}, {"id": "4", "name": "CLN-3"}, {"id": "5", "name": "CLN_4"}])
        self.assertEqual(out["Status"][1], {"id": "26", "name": "Left"})
        self.assertEqual(out["ZoneId"][1]["name"], "রিফাত")
        self.assertEqual(out["PackageId"], [])


class CreateTest(unittest.TestCase):
    def client(self, handler):
        api = isp.ISPDigital.__new__(isp.ISPDigital)
        api.base_url = "https://billing.test"
        api._client = httpx.Client(base_url=api.base_url, transport=httpx.MockTransport(handler), follow_redirects=False)
        import threading
        api._lock = threading.Lock()
        api._logged_in = True
        api.login = lambda: None
        return api

    def test_header_id_from_redirect_and_fields_posted(self):
        seen = {}

        def handler(request):
            if request.method == "GET":
                return httpx.Response(200, text=PAGE)
            seen["body"] = request.content.decode()
            return httpx.Response(302, headers={"location": "/Billing/ConnectionCharge?queryCustomerId=16100"})
        api = self.client(handler)
        self.assertEqual(api.create_customer({"UserName": "zyrotest5.t3", "Password": "pw"}), 16100)
        self.assertIn('name="FormSubmissionToken"', seen["body"])
        self.assertIn("fst-9", seen["body"])
        self.assertIn('name="ModulePermissionId"', seen["body"])
        self.assertIn('name="NationalIdFile"', seen["body"])

    def test_no_redirect_is_refused(self):
        def handler(request):
            return httpx.Response(200, text=PAGE)
        with self.assertRaises(isp.BillingRefused):
            self.client(handler).create_customer({"UserName": "x"})

    def test_login_redirect(self):
        def handler(request):
            if request.method == "GET":
                return httpx.Response(200, text=PAGE)
            return httpx.Response(302, headers={"location": "/Account/Login?ReturnUrl=x"})
        with self.assertRaises(isp.LoginError):
            self.client(handler).create_customer({"UserName": "x"})

    def test_profiles_and_username_check(self):
        def handler(request):
            if "GetProfilesDropDownById" in str(request.url):
                return httpx.Response(200, json="<option value='default'>default</option><option value='P-5_525'>P-5_525</option>")
            if "CheckUserName" in str(request.url):
                return httpx.Response(200, json="taken" in str(request.url))
            return httpx.Response(200, json="1260")
        api = self.client(handler)
        self.assertEqual(api.server_profiles(4), ["default", "P-5_525"])
        self.assertTrue(api.username_taken("taken.user"))
        self.assertFalse(api.username_taken("free.user"))
        self.assertEqual(api.package_bill(3737), 1260)


if __name__ == "__main__":
    unittest.main()
