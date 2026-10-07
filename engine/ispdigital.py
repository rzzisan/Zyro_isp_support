"""ISP Digital client (one instance per company; credentials come from the panel).

No official API: logs in with a staff user like the browser does, then
calls the JSON endpoints the web pages use. Never returns PPPoE/portal
passwords to callers.
"""
import html
import re
import threading
from urllib.parse import urlencode

import httpx

SECRET_FIELDS = {"Password", "LoginPassword"}


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
    def __init__(self, base_url: str, username: str, password: str):
        self.base_url = base_url.rstrip("/")
        self._username = username
        self._password = password
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


    # --- write: support tickets -----------------------------------------------------
    def ticket_form(self) -> tuple[str, dict[str, str]]:
        """Anti-forgery token of the New Ticket form + {category name: id}."""
        with self._lock:
            if not self._logged_in:
                self.login()
            h = self._client.get("/ClientSupport/DailyComplainList").text
        i = h.find('id="frmAdminSupportTicket"')
        m = re.search(r'name="__RequestVerificationToken"[^>]*value="([^"]+)"', h[i:i + 5000]) if i >= 0 else None
        if not m:
            self._logged_in = False
            raise LoginError("ticket form not available")
        sel = re.search(r'<select[^>]*id="ProblemCategoryId".*?</select>', h, re.S)
        cats = {html.unescape(name).strip(): cid for cid, name in
                re.findall(r'<option value="(\d+)"[^>]*>([^<]+)</option>', sel.group(0))} if sel else {}
        return m.group(1), cats

    def create_ticket(self, header_id: int, category_id: str, priority_id: int, mobile: str,
                      comment: str, send_sms: bool = False) -> str:
        """Opens a client support ticket like the admin 'Open New Ticket' form. Returns the panel's message."""
        token, _ = self.ticket_form()
        data = {
            "__RequestVerificationToken": token,
            "TicketNumber": "0", "TicketConversationId": "0",
            "CustomerHeaderId": str(header_id),
            "ProblemCategoryId": str(category_id),
            "ProblemPriorityId": str(priority_id),
            "ComplainedMobileNumber": mobile,
            "RemarksOrComment": comment[:1900],
            "IsSendSMSToClient": "true" if send_sms else "false",
        }
        with self._lock:
            r = self._client.post("/ClientSupport/DailyComplainList", data=data,
                                  files={"Attachments": ("", b"", "application/octet-stream")},
                                  headers={"X-Requested-With": "XMLHttpRequest"})
        r.raise_for_status()
        res = r.json()
        if res.get("errMSG"):
            raise RuntimeError(res["errMSG"])
        return res.get("sucMSG") or "ok"

    def _post(self, path: str, data) -> httpx.Response:
        with self._lock:
            if not self._logged_in:
                self.login()
            # repeated keys (empIds=3&empIds=9) need a pre-encoded body; httpx data= only takes a dict
            body = urlencode(data) if isinstance(data, list) else urlencode(data, doseq=True)
            r = self._client.post(path, content=body, headers={
                "X-Requested-With": "XMLHttpRequest", "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"})
        if r.status_code in (301, 302):
            self._logged_in = False
            raise LoginError(f"session not accepted for {path}")
        r.raise_for_status()
        return r

    def support_options(self) -> dict:
        """Ticket categories, priorities, departments and employees offered by the support page."""
        with self._lock:
            if not self._logged_in:
                self.login()
            h = self._client.get("/ClientSupport/DailyComplainList").text

        def opts(select_id: str) -> dict[str, str]:
            m = re.search(r'<select[^>]*id="%s".*?</select>' % select_id, h, re.S)
            return {cid: html.unescape(name).strip() for cid, name in
                    re.findall(r'<option value="(\d+)"[^>]*>([^<]+)</option>', m.group(0))} if m else {}

        return {"categories": opts("ProblemCategoryId"), "priorities": opts("ProblemPriorityId"),
                "departments": opts("EmpDepartmentId"), "employees": opts("AssignEmpHeadId")}

    def ticket_solvers(self, complain_id: int) -> dict:
        return self._get(f"/ClientSupport/GetEmployeeDeptAndEmpByComplainId/{int(complain_id)}")

    def assign_ticket(self, complain_id: int, employee_ids: list[int], dept_id: int | None = None,
                      sms_employees: bool = False):
        """Assign / reassign technicians like the support page's Assign button (optionally SMS them)."""
        data = [("id", str(int(complain_id))), ("deptId", str(dept_id or ""))] + [("empIds", str(int(e))) for e in employee_ids]
        r = self._post("/ClientSupport/AddSolver", data)
        if r.text.lstrip().startswith("<"):
            self._logged_in = False
            raise LoginError("billing session expired, try again")
        try:
            res = r.json()
        except ValueError:
            res = None
        if sms_employees and res:
            self._post("/sms/SendAsync", jquery_params({"vmSms": res}))
        return res

    def open_tickets_for(self, username: str) -> list[dict]:
        rows = self.open_tickets(username)
        # the daily complain list holds unresolved tickets: Status 0 = pending, 1 = processing
        return [t for t in rows if (t.get("UserName") or "").lower() == username.lower()
                and t.get("Status") in (0, 1)]


def jquery_params(obj, prefix: str = "") -> list[tuple[str, str]]:
    """Form encoding the way jQuery's $.post serialises nested objects (a[b][0][c]=v)."""
    out: list[tuple[str, str]] = []
    items = obj.items() if isinstance(obj, dict) else enumerate(obj) if isinstance(obj, list) else None
    if items is None:
        return [(prefix, "" if obj is None else str(obj).lower() if isinstance(obj, bool) else str(obj))]
    for k, v in items:
        out += jquery_params(v, f"{prefix}[{k}]" if prefix else str(k))
    return out


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


ID_HINT = re.compile(r"(\bid\b|আইডি|আই ডি|customer|কাস্টমার|গ্রাহক|user)", re.I)


def find_customer_by_text(api: ISPDigital, text: str, allow_bare_id: bool = True) -> dict | None:
    """The customer typed an identifier: mobile (01XXXXXXXXX / +880...), customer ID (e.g. 0071) or PPPoE username.
    allow_bare_id=False: a short number counts as a customer ID only if the text says so ("id 71")
    or is just the number, so "520 taka diyechi" isn't read as customer 520."""
    text = text or ""
    # mobile numbers
    for m in re.findall(r"(?:\+?88)?01[3-9]\d{8}", re.sub(r"[\s-]", "", text)):
        mobile = normalize_bd_mobile(m)
        rows = [r for r in api.search_customers(mobile) if normalize_bd_mobile(r.get("MobileNumber", "")) == mobile]
        if len(rows) == 1:
            return rows[0]
    # customer IDs (short numbers, keep leading zeros)
    bare_ok = allow_bare_id or bool(ID_HINT.search(text)) or text.strip().isdigit()
    for cid in (re.findall(r"(?<!\d)\d{2,6}(?!\d)", text) if bare_ok else []):
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
    try:
        payments = [{
            "date": p.get("PaymentDate"), "month": p.get("BillMonth"), "paid": p.get("PaidAmount"),
            "discount": p.get("Discount"), "method": p.get("PaymentMethodName"),
        } for p in api.payments(hid, limit=6)]
    except Exception:
        payments = None
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
        "payments": payments,
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
