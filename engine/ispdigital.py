"""ISP Digital client (one instance per company; credentials come from the panel).

No official API: logs in with a staff user like the browser does, then
calls the JSON endpoints the web pages use. Never returns PPPoE/portal
passwords to callers.
"""
import fcntl
import hashlib
import html
import json
import logging
import os
import re
import threading
import time
from datetime import datetime, timedelta, timezone
from html.parser import HTMLParser
from urllib.parse import urlencode

import httpx

log = logging.getLogger("zyro-engine")

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


BD_TZ = timezone(timedelta(hours=6))

# Billing keeps one session per staff login: a new login logs the previous session out. The engine and every sync
# timer (separate processes) used to log in on their own and kept kicking each other out, so almost every Desk step
# paid for a fresh login (1.5-2 s). The session cookies are now kept on disk (owner-only) and shared.
SESSION_DIR = os.environ.get("BILLING_SESSION_DIR") or os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "data", "billing-sessions")


class LoginError(RuntimeError):
    pass


class BillingRefused(RuntimeError):
    """The billing software answered with its own error message."""


class ISPDigital:
    def __init__(self, base_url: str, username: str, password: str):
        self.base_url = base_url.rstrip("/")
        self._username = username
        self._password = password
        self._client = httpx.Client(base_url=self.base_url, timeout=20, follow_redirects=False,
                                    headers=browser_headers(self.base_url))
        self._lock = threading.Lock()
        self._logged_in = False
        self._session_mtime = 0.0
        self._load_session()

    # --- session -------------------------------------------------------------
    def _session_path(self) -> str:
        key = hashlib.sha256(f"{self.base_url}|{self._username}".encode()).hexdigest()[:24]
        return os.path.join(SESSION_DIR, key + ".json")

    def _load_session(self) -> bool:
        """Use the cookies another process saved for this login, if newer than what this client has."""
        path = self._session_path()
        try:
            mtime = os.path.getmtime(path)
            if mtime <= self._session_mtime:
                return False
            with open(path) as f:
                cookies = json.load(f)
        except (OSError, ValueError):
            return False
        self._client.cookies.clear()
        for c in cookies:
            self._client.cookies.set(c["name"], c["value"], domain=c.get("domain") or "", path=c.get("path") or "/")
        self._session_mtime = mtime
        self._logged_in = True
        return True

    def _save_session(self) -> None:
        try:
            os.makedirs(SESSION_DIR, mode=0o700, exist_ok=True)
            path = self._session_path()
            tmp = f"{path}.{os.getpid()}.tmp"
            fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
            with os.fdopen(fd, "w") as f:
                json.dump([{"name": c.name, "value": c.value, "domain": c.domain, "path": c.path}
                           for c in self._client.cookies.jar], f)
            os.replace(tmp, path)
            self._session_mtime = os.path.getmtime(path)
        except OSError:
            log.warning("could not save the billing session", exc_info=True)

    def login(self) -> None:
        """Get a working session: a newer one saved by another process if there is one, else a real login
        (one process at a time per login, so they don't log each other out)."""
        if self._load_session():
            return
        os.makedirs(SESSION_DIR, mode=0o700, exist_ok=True)
        with open(self._session_path() + ".lock", "w") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            if self._load_session():  # someone logged in while we waited
                return
            self._real_login()
            self._save_session()

    def _real_login(self) -> None:
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

    def page(self, path: str, params: dict | None = None) -> str:
        """An HTML page of the logged-in panel (e.g. a customer's details page), same session handling as _get."""
        with self._lock:
            for attempt in (1, 2):
                if not self._logged_in:
                    self.login()
                r = self._client.get(path, params=params)
                if r.status_code in (301, 302) or "Account/Login" in str(r.url):
                    self._logged_in = False
                    if attempt == 2:
                        raise LoginError(f"session not accepted for {path}")
                    continue
                r.raise_for_status()
                return r.text

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

    def quick_search(self, term: str, with_left: bool = True) -> list[dict]:
        """The billing header search box: [{"id": CustomerHeaderId, "text": "pppoe.user (CustomerId)"}].
        Matches any part of ID, PPPoE user, name or mobile. The header box itself skips Left customers."""
        path = "/Customer/GetAllCustomerUserName" if with_left else "/Customer/GetAllCustomerUserNameWithoutLeft"
        data = self._get(path, {"term": term[:30], "permissionId": 1, "isComeFrom": "CustomerSearch"})
        return data if isinstance(data, list) else []

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
    def ticket_form(self, fresh: bool = False) -> tuple[str, dict[str, str]]:
        """Anti-forgery token of the New Ticket form + {category name: id}; kept until the session changes."""
        cached = getattr(self, "_form", None)
        if not fresh and cached and cached[0] == self._session_mtime and self._logged_in:
            return cached[1], cached[2]
        # the shared session may have been logged out (someone signed in with the same billing user elsewhere):
        # the page then comes without the form, so log in again once before giving up
        for attempt in (1, 2):
            h = self.page("/ClientSupport/DailyComplainList")
            i = h.find('id="frmAdminSupportTicket"')
            m = re.search(r'name="__RequestVerificationToken"[^>]*value="([^"]+)"', h[i:i + 5000]) if i >= 0 else None
            if m:
                break
            self._logged_in = False
            log.warning("ticket form missing (attempt %s), logging in again", attempt)
        else:
            raise LoginError("বিলিংয়ের টিকিট ফর্ম পাওয়া যায়নি (বিলিং লগইন বাতিল হয়েছে)। একটু পরে আবার চেষ্টা করুন।")
        sel = re.search(r'<select[^>]*id="ProblemCategoryId".*?</select>', h, re.S)
        cats = {html.unescape(name).strip(): cid for cid, name in
                re.findall(r'<option value="(\d+)"[^>]*>([^<]+)</option>', sel.group(0))} if sel else {}
        self._form = (self._session_mtime, m.group(1), cats)
        return m.group(1), cats

    def create_ticket(self, header_id: int, category_id: str, priority_id: int, mobile: str,
                      comment: str, send_sms: bool = False) -> str:
        """Opens a client support ticket like the admin 'Open New Ticket' form. Returns the panel's message."""
        return self._submit_ticket(0, 0, header_id, category_id, priority_id, mobile, comment, send_sms)

    def update_ticket(self, complain_id: int, header_id: int, category_id: str, priority_id: int, mobile: str,
                      comment: str) -> str:
        """Edits an open ticket like the support page's Edit button: same form, with the ticket number and the id of
        its first staff-to-staff conversation (that entry holds the description)."""
        rows = self._get("/ComplainDiscussion/GetConversationInfoByTicketId",
                         {"id": int(complain_id), "forWhom": "EmployeeToEmployee"}) or []
        firsts = sorted(int(r.get("ConversationId") or 0) for r in rows if not r.get("ParentConversationId"))
        conv = firsts[0] if firsts else 0
        if not conv:
            raise RuntimeError("বিলিংয়ে এই টিকিটের বিবরণ পাওয়া যায়নি, তাই এডিট করা গেল না")
        body = "".join(f"<p>{html.escape(line)}</p>" for line in comment.splitlines() if line.strip()) or html.escape(comment)
        return self._submit_ticket(complain_id, conv, header_id, category_id, priority_id, mobile, body, False)

    def _submit_ticket(self, complain_id: int, conversation_id: int, header_id: int, category_id: str,
                       priority_id: int, mobile: str, comment: str, send_sms: bool) -> str:
        for attempt in (1, 2):
            res = self._post_ticket_form(self.ticket_form(fresh=attempt == 2)[0], complain_id, conversation_id, header_id,
                                         category_id, priority_id, mobile, comment, send_sms)
            if res is not None:
                break
            log.warning("ticket form post refused (attempt %s), fetching a fresh form", attempt)
        else:
            raise RuntimeError("বিলিং টিকিটটা নেয়নি, একটু পরে আবার চেষ্টা করুন")
        if res.get("errMSG"):
            raise RuntimeError(res["errMSG"])
        return res.get("sucMSG") or "ok"

    def _post_ticket_form(self, token: str, complain_id: int, conversation_id: int, header_id: int, category_id: str,
                          priority_id: int, mobile: str, comment: str, send_sms: bool) -> dict | None:
        data = {
            "__RequestVerificationToken": token,
            "TicketNumber": str(int(complain_id)), "TicketConversationId": str(int(conversation_id)),
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
        if r.status_code in (301, 302):
            self._logged_in = False
            return None
        try:
            res = r.json() if r.status_code == 200 else None
        except ValueError:
            res = None
        return res if isinstance(res, dict) else None

    def _post(self, path: str, data, timeout: float | None = None) -> httpx.Response:
        with self._lock:
            if not self._logged_in:
                self.login()
            # repeated keys (empIds=3&empIds=9) need a pre-encoded body; httpx data= only takes a dict
            body = urlencode(data) if isinstance(data, list) else urlencode(data, doseq=True)
            r = self._client.post(path, content=body, timeout=timeout or self._client.timeout, headers={
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

    def solve_ticket(self, complain_id: int, remark: str = "") -> None:
        """Mark a ticket solved like the support page's Solve button (no customer SMS)."""
        r = self._post("/ClientSupport/Solved/", jquery_params({"complainSolved": {
            "ComplainId": int(complain_id), "IsSmsSendable": False, "Remark": remark or ""}}))
        body = r.text.strip().strip('"').lower()
        if body.startswith("<"):
            self._logged_in = False
            raise LoginError("billing session expired, try again")
        if body != "true":
            raise RuntimeError(f"billing did not solve the ticket ({r.text.strip()[:100]})")

    def enable_customer(self, header_id: int) -> str:
        """Turn a (bill-)disabled line back on, like Billing -> select -> Enable. Returns the panel's answer."""
        r = self._post("/Billing/EnableSelectedClients", [("cusHeadIds", str(int(header_id)))])
        body = r.text.strip().strip('"')
        if body.isdigit() and int(body) >= 400:
            raise RuntimeError(f"billing refused to enable (HTTP {body})")
        if body.lstrip().startswith("<"):
            self._logged_in = False
            raise LoginError("billing session expired, try again")
        return body

    def disable_customer(self, header_id: int) -> str:
        """Turn a line off, like Billing -> select -> Disable."""
        r = self._post("/Billing/DisableSelectedClients", [("cusHeadIds", str(int(header_id)))])
        body = r.text.strip().strip('"')
        if body.isdigit() and int(body) >= 400:
            raise RuntimeError(f"billing refused to disable (HTTP {body})")
        if body.lstrip().startswith("<"):
            self._logged_in = False
            raise LoginError("billing session expired, try again")
        return body

    # --- write: bill collection (Daily Bill Collection -> Receive Bill) -------------------
    def bill_form(self, header_id: int) -> dict:
        """The Receive Bill form for one customer, as the billing page loads it into its modal."""
        with self._lock:
            for attempt in (1, 2):
                if not self._logged_in:
                    self.login()
                r = self._client.get("/DailyAccountClosing/BillReceivePartial", params={"id": int(header_id)},
                                     headers={"X-Requested-With": "XMLHttpRequest"})
                form = parse_bill_form(r.text) if r.status_code == 200 else None
                if form:
                    return form
                self._logged_in = False
                if attempt == 2:
                    raise LoginError("bill receive form not available")

    def pending_bills(self, username: str, days: int = 31) -> list[dict]:
        """Collections not approved yet (Daily Bill Collection, status pending) matching a username."""
        today = datetime.now(BD_TZ).date()
        cols = ["CreationTime", "CustomerHeaderId", "CustomerId", "UserName", "CustomerName", "MobileNumber",
                "Description", "EmpHeaderId", "MonthlyBill", "ReceiveAmount", "VatAmount", "DiscountAmount",
                "BalanceDue", "PaymentMethod", "ReceivedBy", "ApprovedBy", "CreatedBy"]
        params = {"draw": 1, "start": 0, "length": 50, "search[value]": username, "search[regex]": "false",
                  "id": 0, "fromDateString": (today - timedelta(days=days)).strftime("%d-%m-%Y"),
                  "toDateString": today.strftime("%d-%m-%Y"), "EmpHeaderId": "", "ApprovedById": "",
                  "CreatedBy": "", "transStatus": "pending", "orderBy": "", "paymentMethod": "", "permissionId": 1}
        for i, c in enumerate(cols):
            params.update({f"columns[{i}][data]": c, f"columns[{i}][name]": "", f"columns[{i}][searchable]": "true",
                           f"columns[{i}][orderable]": "false", f"columns[{i}][search][value]": "",
                           f"columns[{i}][search][regex]": "false"})
        data = self._get("/DailyAccountClosing/AjaxDailyBillQuery", params)
        return [r for r in (data or {}).get("data", []) if not r.get("IsApproved") and not r.get("IsDeleted")]

    def receive_bill(self, form: dict, paid: float, discount: float, method_id: str, received_by_id: str,
                     remarks: str = "", trx_no: str = "", send_sms: bool = False, next_billing_date: bool = False,
                     received_from: str | None = None, bill_month: str | None = None) -> str:
        """Submit a filled Receive Bill form (from bill_form) like the modal's Submit. Returns the panel's message.
        The collection stays pending until a staff member approves it in the billing software."""
        data = receive_payload(form, paid, discount, method_id, received_by_id, remarks, trx_no, send_sms,
                               next_billing_date, received_from, bill_month)
        # saving a bill (and its SMS) can take billing well over the usual 20 s
        r = self._post(form["action"], data, timeout=90)
        if r.text.lstrip().startswith("<"):
            self._logged_in = False
            raise LoginError("billing session expired, the bill was not saved")
        res = r.json()
        if res.get("errorMSG"):
            raise BillingRefused(res["errorMSG"])
        return res.get("successMSG") or "ok"

    def open_tickets_for(self, username: str) -> list[dict]:
        rows = self.open_tickets(username)
        # the daily complain list holds unresolved tickets: Status 0 = pending, 1 = processing
        return [t for t in rows if (t.get("UserName") or "").lower() == username.lower()
                and t.get("Status") in (0, 1)]

    # --- write: Expire Date (customer edit form) ----------------------------------------
    def edit_form(self, header_id: int) -> dict:
        """The customer edit form (/customer/edit) as the browser would post it after the page's JS ran."""
        page = self.page("/customer/edit", {"id": int(header_id), "isComeFrom": ""})
        form = parse_edit_form(page)
        if not form or form["values"].get("CustomerHeaderId") != str(int(header_id)):
            raise RuntimeError("billing's customer edit form was not as expected")
        return form

    def set_expire_day(self, header_id: int, day: int, expect: dict | None = None) -> int | None:
        """Change only the customer's Expire Date (BillingLastDate, 1-31) and return the day it had.
        Everything else goes back exactly as billing showed it; "Mikrotik Impact" is never sent (with it, saving an
        Active customer whose line is off turns the line on). Refuses when the form doesn't match `expect`
        (the desk's copy: username, monthly_bill, subzone, box) or a JS-set value (Speed, SubZone, Box) is missing."""
        if not 1 <= int(day) <= 31:
            raise ValueError("day must be 1-31")
        form = self.edit_form(header_id)
        problems = edit_form_problems(form, expect or {})
        if problems:
            raise BillingRefused("ফর্ম মেলেনি: " + ", ".join(problems))
        before = form["values"].get("BillingLastDate")
        if before == str(int(day)):
            return int(before)
        data: dict[str, list[str]] = {}
        for n, v in form["fields"]:
            data.setdefault(n, []).append(str(int(day)) if n == "BillingLastDate" else v)
        files = [(n, ("", b"", "application/octet-stream")) for n in form["files"]]
        with self._lock:
            r = self._client.post(form["action"], data=data, files=files, timeout=60, headers={
                "Referer": f"{self.base_url}/customer/edit?id={int(header_id)}&isComeFrom="})
        location = r.headers.get("location", "")
        if "Account/Login" in location:
            self._logged_in = False
            raise LoginError("billing session expired, the expire date was not saved")
        if r.status_code not in (301, 302, 303):
            raise BillingRefused(f"billing did not save the customer (HTTP {r.status_code})")
        return int(before) if before and before.isdigit() else None


def receive_payload(form: dict, paid: float, discount: float, method_id: str, received_by_id: str, remarks: str = "",
                    trx_no: str = "", send_sms: bool = False, next_billing_date: bool = False,
                    received_from: str | None = None, bill_month: str | None = None) -> list[tuple[str, str]]:
    """Form fields of a filled Receive Bill form, as the modal's Submit posts them."""
    payable = form["payable"]
    values = {
        "ReceivedFrom": form["values"].get("ReceivedFrom", "") if received_from is None else received_from,
        "ReceivedById": str(received_by_id), "PaymentMethodId": str(method_id),
        "BillMonthName": bill_month or form["values"].get("BillMonthName", ""),
        "Discount": money(discount), "PaidAmount": money(paid), "ReceiptOrTransactionNo": trx_no,
        "BalanceDue": f"{payable - paid - discount:.2f}", "Remarks": remarks,
    }
    checked = {"SetNextBillingDate": next_billing_date, "isSMSSendable": send_sms}
    data: list[tuple[str, str]] = []
    for kind, name, value in form["fields"]:
        if kind == "checkbox":
            if checked.get(name, False):
                data.append((name, value or "true"))
        else:
            data.append((name, values.get(name, value)))
    # The Discount box comes disabled in the HTML and the billing page's own script enables it, so the parser
    # (which skips disabled inputs, like a browser) never sees it. Without it billing saved every discount as 0.
    if not any(name == "Discount" for _, name in data):
        data.append(("Discount", money(discount)))
    return data


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
URL_RE = re.compile(r"(?:https?|ftp)://\S+|\bwww\.\S+", re.I)
# IPs, MACs, times, amounts like 172.19.178.178 / 10:30 / 1.5: their digits are never a customer ID or mobile
DOTTED_RE = re.compile(r"\d+(?:[.:]\d+)+|\b[0-9a-f]{2}(?:[:-][0-9a-f]{2}){5}\b", re.I)


def without_urls(text: str) -> str:
    return URL_RE.sub(" ", text or "")


def number_text(text: str) -> str:
    """The text with links, IPs, MACs and dotted numbers removed: what is left may hold a mobile or customer ID."""
    return DOTTED_RE.sub(" ", without_urls(text))


def find_customer_by_text(api: ISPDigital, text: str, allow_bare_id: bool = True) -> dict | None:
    """The customer typed an identifier: mobile (01XXXXXXXXX / +880...), customer ID (e.g. 0071) or PPPoE username.
    allow_bare_id=False: a short number counts as a customer ID only if the text says so ("id 71")
    or is just the number, so "520 taka diyechi" isn't read as customer 520."""
    text = without_urls(text)
    nums = number_text(text)
    # mobile numbers
    for m in re.findall(r"(?:\+?88)?01[3-9]\d{8}", re.sub(r"[\s-]", "", nums)):
        mobile = normalize_bd_mobile(m)
        rows = [r for r in api.search_customers(mobile) if normalize_bd_mobile(r.get("MobileNumber", "")) == mobile]
        if len(rows) == 1:
            return rows[0]
    # customer IDs (short numbers, keep leading zeros)
    bare_ok = allow_bare_id or bool(ID_HINT.search(text)) or text.strip().isdigit()
    for cid in (re.findall(r"(?<!\d)\d{2,6}(?!\d)", nums) if bare_ok else []):
        rows = [r for r in api.search_customers(cid, limit=50)
                if (r.get("CustomerId") or "").lstrip("0") == cid.lstrip("0")]
        if len(rows) == 1:
            return rows[0]
    # PPPoE usernames like pp.rajib.computer / bp.apon
    for uname in re.findall(r"\b[a-zA-Z][\w-]*\.[\w.-]+\b", text):
        rows = [r for r in api.search_customers(uname, limit=10) if (r.get("UserName") or "").lower() == uname.lower()]
        if len(rows) == 1:
            return rows[0]
    return None


def diagnose(api: ISPDigital, customer: dict, live: dict | None = None, onu: dict | None = None,
             ask_billing: bool = True) -> dict:
    """Collect everything the bot needs, in the order: bill -> ONU -> PPPoE.
    ask_billing=False: billing's live page and ONU page are not asked; live/onu are whatever the caller already has
    (customers.live_context fills them from our own MikroTik/OLT first)."""
    hid = customer["CustomerHeaderId"]
    # the billing software is sometimes slow (timeouts): answer with what we have instead of not answering
    if live is None and ask_billing:
        try:
            live = api.live_status(hid)
        except Exception as e:
            log.warning("billing live status failed for %s: %s", hid, e)
    live = live or {}
    if onu is None and ask_billing:
        try:
            onu = api.onu_info(live.get("calledid"))
        except Exception as e:
            log.warning("billing ONU info failed for %s: %s", hid, e)
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
        "bill": {  # without billing's live page: the same values from the customer row and the payments
            "monthly": live.get("monthlyBill") or customer.get("MonthlyBill"),
            "payable": customer.get("PayabaleBill"),
            "paid": customer.get("PaidAmount"),
            "due": customer.get("BalanceDue"),
            "payment_status": live.get("paymentStatus") or customer.get("PaymentStatus") or due_status(customer),
            "last_paid_amount": live.get("lastPaidAmount") or (payments[0].get("paid") if payments else None),
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


def due_status(customer: dict) -> str | None:
    """Billing's live page words ("Due (520.00 ৳)" / "Paid") from the customer row's due, when that page isn't asked."""
    due = customer.get("BalanceDue")
    if due in (None, ""):
        return None
    d = _num(due)
    return f"Due ({d:.2f} ৳)" if d > 0 else "Paid"


def money(v: float) -> str:
    return str(int(v)) if float(v).is_integer() else f"{v:.2f}"


def _num(v) -> float:
    try:
        return float(str(v or "0").replace(",", ""))
    except ValueError:
        return 0.0


class _FormParser(HTMLParser):
    """Inputs of the Receive Bill form in page order, the way jQuery would serialize them."""

    def __init__(self, form_id: str):
        super().__init__(convert_charrefs=True)
        self.form_id, self.depth, self.action = form_id, 0, None
        self.fields: list[list] = []        # [kind, name, value]
        self.ids: dict[str, str] = {}       # id -> value (also unnamed inputs, e.g. CurrentEmployeeID)
        self.options: dict[str, dict[str, str]] = {}
        self._select = self._option = None
        self._text = ""

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "form":
            if self.depth:
                self.depth += 1
            elif a.get("id") == self.form_id:
                self.depth, self.action = 1, html.unescape(a.get("action") or "")
            return
        if not self.depth or "disabled" in a:
            return
        name, value = a.get("name"), a.get("value") or ""
        if tag == "input":
            kind = (a.get("type") or "text").lower()
            if a.get("id"):
                self.ids[a["id"]] = value
            if not name or kind in ("submit", "button", "file", "reset"):
                return
            if kind == "radio" and "checked" not in a:
                return
            self.fields.append(["checkbox" if kind == "checkbox" else "value", name, value])
        elif tag == "select":
            self._select = name
            if name:
                self.options[name] = {}
                self.fields.append(["value", name, ""])
        elif tag == "option" and self._select:
            self._option, self._text = (a.get("value"), "selected" in a), ""
        elif tag == "textarea" and name:
            self.fields.append(["value", name, ""])
            self._select, self._option = None, ("__textarea__", False)
            self._text = ""

    def handle_data(self, data):
        if self._option:
            self._text += data

    def handle_endtag(self, tag):
        if tag == "form" and self.depth:
            self.depth -= 1
        elif tag == "option" and self._option and self._select:
            val, sel = self._option
            text = self._text.strip()
            val = text if val is None else val
            self.options[self._select][val] = text
            if sel:
                self.fields[-1][2] = val
            self._option = None
        elif tag == "textarea" and self._option and self._option[0] == "__textarea__":
            self.fields[-1][2] = self._text
            self._option = None
        elif tag == "select":
            self._select = None


def parse_bill_form(page: str) -> dict | None:
    p = _FormParser("DailyBillReceiveMainForm")
    p.feed(page)
    names = {f[1] for f in p.fields}
    if not p.action or "CustomerHeaderId" not in names or "PaidAmount" not in names:
        return None
    values = {}
    for kind, name, value in p.fields:
        if kind == "value":
            values.setdefault(name, value)
    return {
        "action": p.action, "fields": [tuple(f) for f in p.fields], "values": values,
        "payable": _num(values.get("PayabaleBill")), "monthly": _num(values.get("MonthlyBill")),
        "period_id": values.get("FromBillPeriodId"), "status": values.get("BillingStatus"),
        "username": p.ids.get("UserName"), "current_employee_id": p.ids.get("CurrentEmployeeID"),
        "received_by": {k: v for k, v in p.options.get("ReceivedById", {}).items() if k},
        "methods": {k: v for k, v in p.options.get("PaymentMethodId", {}).items() if k},
        "has_tokens": "FormSubmissionToken" in names and "__RequestVerificationToken" in names,
    }


class _EditFormParser(HTMLParser):
    """Every named control of one form, as a browser submits it: checked checkboxes/radios only, each selected option
    of a (multi) select, textarea text. File inputs are listed apart (sent as empty parts)."""

    def __init__(self, form_id: str):
        super().__init__(convert_charrefs=True)
        self.form_id, self.depth, self.action = form_id, 0, None
        self.controls: list[dict] = []
        self.files: list[str] = []
        self._select = self._option = self._area = None
        self._text = ""

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "form":
            if self.depth:
                self.depth += 1
            elif a.get("id") == self.form_id:
                self.depth, self.action = 1, html.unescape(a.get("action") or "")
            return
        if not self.depth:
            return
        name = a.get("name")
        if tag == "input" and name and "disabled" not in a:
            kind = (a.get("type") or "text").lower()
            if kind == "file":
                self.files.append(name)
            elif kind in ("checkbox", "radio"):
                if "checked" in a:
                    self.controls.append({"name": name, "values": [a.get("value") or "on"], "kind": kind})
            elif kind not in ("submit", "button", "reset", "image"):
                self.controls.append({"name": name, "values": [a.get("value") or ""], "kind": kind})
        elif tag == "select" and name and "disabled" not in a:
            self._select = {"name": name, "values": [], "kind": "select", "multiple": "multiple" in a, "options": []}
            self.controls.append(self._select)
        elif tag == "option" and self._select is not None:
            self._option, self._text = (a.get("value"), "selected" in a), ""
        elif tag == "textarea" and name and "disabled" not in a:
            self._area, self._text = {"name": name, "values": [""], "kind": "textarea"}, ""
            self.controls.append(self._area)

    def handle_data(self, data):
        if self._option is not None or self._area is not None:
            self._text += data

    def handle_endtag(self, tag):
        if tag == "form" and self.depth:
            self.depth -= 1
        elif tag == "option" and self._option is not None and self._select is not None:
            val, sel = self._option
            val = self._text.strip() if val is None else val
            self._select["options"].append(val)
            if sel:
                self._select["values"].append(val)
            self._option = None
        elif tag == "select" and self._select is not None:
            s = self._select
            if not s["multiple"] and not s["values"] and s["options"]:
                s["values"] = [s["options"][0]]   # a browser posts the first option when none is selected
            self._select = None
        elif tag == "textarea" and self._area is not None:
            self._area["values"] = [self._text.removeprefix("\r").removeprefix("\n")]
            self._area = None


NEVER_POST = {"IsMikrotikChanged"}


def _js_var(page: str, name: str) -> str | None:
    m = re.search(r"\bvar\s+" + re.escape(name) + r"\s*=\s*'([^']*)'", page)
    return m.group(1) if m else None


def parse_edit_form(page: str) -> dict | None:
    """frmClientEdit with the values the page's JS sets on load: Speed ($('#Speed').val('...')), SubZoneId/BoxId
    (var subzoneid / boxid) and ConnectedByIds (var connectedByIds = [..])."""
    p = _EditFormParser("frmClientEdit")
    p.feed(page)
    names = {c["name"] for c in p.controls}
    if not p.action or not {"CustomerHeaderId", "BillingLastDate", "UserName", "__RequestVerificationToken"} <= names:
        return None
    js = {}
    m = re.search(r"\$\(\s*['\"]#Speed['\"]\s*\)\.val\(\s*'([^']*)'\s*\)", page)
    js["Speed"] = m.group(1) if m else None
    js["SubZoneId"], js["BoxId"] = _js_var(page, "subzoneid"), _js_var(page, "boxid")
    m = re.search(r"\bvar\s+connectedByIds\s*=\s*\[([^\]]*)\]", page)
    js["ConnectedByIds"] = [x.strip().strip("'\"") for x in m.group(1).split(",")] if m else None
    fields, values = [], {}
    for c in p.controls:
        n = c["name"]
        if n in NEVER_POST:
            continue
        vals = c["values"]
        if n in ("Speed", "SubZoneId", "BoxId") and js.get(n) is not None:
            vals = [js[n]]
        elif n == "ConnectedByIds" and js["ConnectedByIds"] is not None:
            vals = [v for v in js["ConnectedByIds"] if v and v != "0"]
        for v in vals:
            fields.append((n, v))
        values.setdefault(n, vals[0] if vals else "")
    return {"action": p.action, "fields": fields, "values": values, "files": p.files, "js": js,
            "options": {c["name"]: c.get("options", []) for c in p.controls if c["kind"] == "select"}}


def edit_form_problems(form: dict, expect: dict) -> list[str]:
    """Why posting this form could change more than the Expire Date (empty list = safe to post)."""
    v, js, out = form["values"], form["js"], []
    if js.get("Speed") is None or not v.get("Speed"):
        out.append("Speed/প্রোফাইল পাওয়া যায়নি")
    elif js["Speed"] not in form["options"].get("Speed", []):
        out.append("Speed তালিকায় নেই")
    for f, key in (("SubZoneId", "subzone"), ("BoxId", "box")):
        if js.get(f) is None:
            out.append(f"{f} পাওয়া যায়নি")
        elif expect.get(key) and js[f] in ("", "0"):
            out.append(f"{f} খালি, অথচ Desk-এ {expect[key]}")
    if js.get("ConnectedByIds") is None and "ConnectedByIds" in v:
        out.append("ConnectedByIds পাওয়া যায়নি")
    if expect.get("username") and (v.get("UserName") or "").strip().lower() != str(expect["username"]).strip().lower():
        out.append("UserName মেলেনি")
    if expect.get("monthly_bill") is not None:
        try:
            if abs(float((v.get("MonthlyBillAmount") or "0").replace(",", "")) - float(expect["monthly_bill"])) > 0.5:
                out.append("মাসিক বিল মেলেনি")
        except ValueError:
            out.append("মাসিক বিল পড়া যায়নি")
    if not v.get("Password"):
        out.append("Password ঘর খালি")
    if not form["files"]:
        out.append("ফর্ম অচেনা (ফাইল ঘর নেই)")
    return out
