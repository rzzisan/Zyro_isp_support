"""Read-only client for ISP Digital (centurylink.ispdigital.cloud).

No official API: logs in with a staff user like the browser does, then
calls the JSON endpoints the web pages use. Never returns PPPoE/portal
passwords to callers.
"""
import os
import re
import threading

import httpx

from app import store

DEFAULT_BASE_URL = "https://centurylink.ispdigital.cloud"

SECRET_FIELDS = {"Password", "LoginPassword"}


def config() -> tuple[str, str, str]:
    """Credentials from the admin dashboard, falling back to .env."""
    base = store.get_setting("billing_base_url") or os.environ.get("ISPDIGITAL_BASE_URL", DEFAULT_BASE_URL)
    user = store.get_setting("billing_username") or os.environ.get("ISPDIGITAL_USERNAME", "")
    password = store.get_setting("billing_password") or os.environ.get("ISPDIGITAL_PASSWORD", "")
    return base.rstrip("/"), user, password


def browser_headers(base_url: str) -> dict:
    # The panel logs a session straight out unless the requests look like the browser's
    # (Referer/Origin/Accept + the empty VmAuthTracer fields of the login form).
    return {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36",
        "Accept": "text/html,application/json;q=0.9,*/*;q=0.8",
        "Referer": f"{base_url}/Account/Login",
        "Origin": base_url,
    }
TRACER_FIELDS = ["IPAddress", "CountryName", "Region", "CityName", "PostalCode",
                 "Latitude", "Longitude", "TimeZone", "Organization"]


class LoginError(RuntimeError):
    pass


class ISPDigital:
    def __init__(self, base_url: str | None = None, username: str | None = None, password: str | None = None):
        cfg_base, cfg_user, cfg_pass = config()
        self.base_url = (base_url or cfg_base).rstrip("/")
        self._username = username if username is not None else cfg_user
        self._password = password if password is not None else cfg_pass
        self._client = httpx.Client(base_url=self.base_url, timeout=20, follow_redirects=False,
                                    headers=browser_headers(self.base_url))
        self._lock = threading.Lock()
        self._logged_in = False

    # --- session -------------------------------------------------------------
    def login(self) -> None:
        if not self._username or not self._password:
            raise LoginError("billing username / password not set")
        USERNAME, PASSWORD = self._username, self._password
        page = self._client.get("/Account/Login")
        m = re.search(r'name="__RequestVerificationToken"[^>]*value="([^"]+)"', page.text)
        if not m:
            raise LoginError("anti-forgery token not found on login page")
        form = {
            "__RequestVerificationToken": m.group(1),
            "Username": USERNAME,
            "Password": PASSWORD,
            "RememberMe": "false",
        }
        form.update({f"VmAuthTracer.{k}": "" for k in TRACER_FIELDS})
        r = self._client.post("/Account/LoginChecker", data=form)
        if r.status_code != 302 or "Dashboard" not in r.headers.get("location", ""):
            raise LoginError(f"login failed (HTTP {r.status_code})")
        # open the dashboard once like the browser does, so the session is accepted
        d = self._client.get("/EmployeeDashboard/Index")
        if d.status_code != 200:
            raise LoginError(f"dashboard rejected session (HTTP {d.status_code})")
        self._logged_in = True

    def _get(self, path: str, params: dict | None = None):
        with self._lock:
            for attempt in (1, 2):
                if not self._logged_in:
                    self.login()
                r = self._client.get(path, params=params, headers={"X-Requested-With": "XMLHttpRequest"})
                # expired session -> redirect to login page
                if r.status_code in (301, 302) or "Account/Login" in str(r.url) or r.text.lstrip().startswith("<"):
                    self._logged_in = False
                    if attempt == 2:
                        raise LoginError(f"session not accepted for {path}")
                    continue
                r.raise_for_status()
                return r.json()

    @staticmethod
    def _clean(row: dict) -> dict:
        return {k: v for k, v in row.items() if k not in SECRET_FIELDS}

    # --- lookups ---------------------------------------------------------------
    def search_customers(self, query: str, limit: int = 5) -> list[dict]:
        """Billing list search: matches mobile (full or last 8), customer id, PPPoE username."""
        data = self._get(
            "/Billing/AjaxCustomerBillList",
            {"draw": 1, "start": 0, "length": limit, "search[value]": query, "search[regex]": "false"},
        )
        return [self._clean(r) for r in data.get("aaData", [])]

    def live_status(self, header_id: int) -> dict:
        """Live connection + bill status (same data as the New Ticket form)."""
        data = self._get(f"/ClientSupport/GetCustomerOtherData/{header_id}")
        return {k: v for k, v in data.items() if not k.startswith("Ticket")}

    def onu_info(self, mac: str) -> dict | None:
        if not mac:
            return None
        data = self._get("/customer/GetOLTInfo", {"macAddress": mac})
        info = (data or {}).get("Data")
        if not info:
            return None
        info.pop("PreviousSnapshot", None)
        return info

    def payments(self, header_id: int, limit: int = 3) -> list[dict]:
        data = self._get(f"/Customer/AjaxReceivedHistory/{header_id}", {"draw": 1, "start": 0, "length": limit})
        return data.get("data", [])

    def open_tickets(self, username: str) -> list[dict]:
        data = self._get(
            "/ClientSupport/AjaxDailyComplainList",
            {"draw": 1, "start": 0, "length": 10, "search[value]": username, "search[regex]": "false"},
        )
        return data.get("aaData", [])


def normalize_bd_mobile(wa_number: str) -> str:
    """WhatsApp gives 8801XXXXXXXXX; billing stores 01XXXXXXXXX."""
    digits = re.sub(r"\D", "", wa_number or "")
    if digits.startswith("880"):
        digits = "0" + digits[3:]
    return digits


def find_customer_by_whatsapp(api: ISPDigital, wa_number: str) -> dict | None:
    mobile = normalize_bd_mobile(wa_number)
    if len(mobile) != 11:
        return None
    rows = [r for r in api.search_customers(mobile) if normalize_bd_mobile(r.get("MobileNumber", "")) == mobile]
    return rows[0] if len(rows) == 1 else None


def find_customer_by_text(api: ISPDigital, text: str) -> dict | None:
    """The customer typed an identifier: mobile (01XXXXXXXXX / +880...), customer ID (e.g. 0071) or PPPoE username."""
    text = text or ""
    # mobile numbers
    for m in re.findall(r"(?:\+?88)?01[3-9]\d{8}", re.sub(r"[\s-]", "", text)):
        mobile = normalize_bd_mobile(m)
        rows = [r for r in api.search_customers(mobile) if normalize_bd_mobile(r.get("MobileNumber", "")) == mobile]
        if len(rows) == 1:
            return rows[0]
    # customer IDs (short numbers, keep leading zeros)
    for cid in re.findall(r"(?<!\d)\d{2,6}(?!\d)", text):
        rows = [r for r in api.search_customers(cid, limit=10)
                if (r.get("CustomerId") or "").lstrip("0") == cid.lstrip("0")]
        if len(rows) == 1:
            return rows[0]
    # PPPoE usernames like pp.rajib.computer / bp.apon
    for uname in re.findall(r"\b[a-zA-Z][\w-]*\.[\w.-]+\b", text):
        rows = [r for r in api.search_customers(uname, limit=10) if (r.get("UserName") or "").lower() == uname.lower()]
        if len(rows) == 1:
            return rows[0]
    return None


def diagnose(api: ISPDigital, customer: dict) -> dict:
    """Collect everything the bot needs, in the order: bill -> ONU -> PPPoE."""
    hid = customer["CustomerHeaderId"]
    live = api.live_status(hid)
    onu = api.onu_info(live.get("calledid"))
    return {
        "customer": {
            "id": customer.get("CustomerId"),
            "name": customer.get("CustomerName"),
            "registered_mobile": customer.get("MobileNumber"),
            "username": customer.get("UserName"),
            "package": customer.get("Package"),
            "zone": customer.get("ZoneName"),
            "status": customer.get("Status"),
            "disabled": customer.get("Disabled"),
            "bill_day": customer.get("BillingLastDate"),
        },
        "bill": {
            "monthly": live.get("monthlyBill"),
            "payable": customer.get("PayabaleBill"),
            "paid": customer.get("PaidAmount"),
            "due": customer.get("BalanceDue"),
            "payment_status": live.get("paymentStatus"),
            "last_paid_amount": live.get("lastPaidAmount"),
        },
        "pppoe": {
            "connectivity": live.get("connectivity"),
            "uptime": live.get("uptime"),
            "last_logout": live.get("logout"),
            "ip": live.get("address"),
            "downloaded": live.get("downloadedData"),
        },
        "onu": None if not onu else {
            "olt": onu.get("OLTName"),
            "status": onu.get("OnuStatus"),
            "optical_power_dbm": onu.get("OpticalPower"),
            "last_deregister": onu.get("LastDeregisterTime"),
            "deregister_reason": onu.get("DeregisterReason"),
        },
    }
