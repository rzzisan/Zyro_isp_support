"""Everything billing's customer details page shows (/customer/details?id={header}), tab by tab, copied into the
desk's own tables. Read-only on billing. See docs/customer-profile-plan.md.

Sections and their billing source:
  details   details page HTML: Service / Network & Product / Personal tabs -> billing_customers columns + details jsonb
  bills     /BillTransaction/AjaxCusomerTransactionData/{h} (slow, ~2 s)       -> billing_customer_bills
  payments  /Customer/AjaxReceivedHistory/{h} (approved receipts only)        -> billing_customer_payments
  complains /Customer/AjaxCompainHistory?id=                                  -> billing_tickets (only missing ones)
  remarks   /Customer/AjaxRemarksHistory?id=                                  -> billing_customer_remarks
  sms       /Customer/AjaxMessagesHistory?id= (last 12 months kept)           -> billing_customer_sms
  changes   /Customer/AjaxCustomerChangeLog?customerHeadId=                   -> billing_customer_change_logs
  line      /Customer/AjaxCustomerEnableDisableLog?cusId=                     -> billing_customer_line_logs
  invoices  /Customer/AjaxServiceAndProductInvoices?customerHeadId=           -> billing_customer_invoices

PASSWORDS: the change and enable/disable logs (and the details page) carry the PPPoE password in plain text. It is
dropped while parsing (`clean`) and never stored or logged; for the change log only "it changed" is kept, as a boolean.

  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.customer_history --customer 15
  ... --nightly [--stop-at 7]   fast sections for everyone, details/bills where needed, then the backfill until 07:00
  ... --sms [--days 2]          SMS of the last days from billing's company-wide SMS report
"""
import argparse
import fcntl
import hashlib
import html
import json
import logging
import os
import re
import threading
import time
from datetime import date, datetime, timedelta, timezone
from zoneinfo import ZoneInfo

from engine import db, tenant as tenants
from engine.ticket_sync import PRIORITY, companies

log = logging.getLogger("zyro-customer-history")
DHAKA = ZoneInfo("Asia/Dhaka")
SECRET = re.compile(r"pass|pwd|secret|token", re.I)
FAST = ["payments", "complains", "remarks", "sms", "changes", "line", "invoices"]
ALL = ["details", "bills", *FAST]
SMS_MONTHS = 12
LOCK = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "data", "customer-history.lock")
PAUSE = 0.05  # between billing requests in the long runs


# --- parsing helpers ---------------------------------------------------------------------------------------------
def clean(row: dict) -> dict:
    """The row without any password-like field (and with nested rows cleaned too)."""
    out = {}
    for k, v in (row or {}).items():
        if SECRET.search(k):
            continue
        out[k] = [clean(x) if isinstance(x, dict) else x for x in v] if isinstance(v, list) else v
    return out


def _local(s, *fmts) -> datetime | None:
    s = re.sub(r"\s+", " ", str(s or "")).strip()
    for f in fmts:
        try:
            return datetime.strptime(s, f)
        except ValueError:
            continue
    return None


def utc(s, *fmts) -> datetime | None:
    """Billing's Bangladesh-time text -> naive UTC."""
    d = _local(s, *fmts)
    return d.replace(tzinfo=DHAKA).astimezone(timezone.utc).replace(tzinfo=None) if d else None


def day(s, *fmts) -> date | None:
    d = _local(s, *fmts)
    return d.date() if d else None


def num(v) -> float:
    s = str(v if v is not None else "").replace(",", "").replace("৳", "").strip()
    neg = s.startswith("(") and s.endswith(")")
    m = re.search(r"-?\d+(\.\d+)?", s)
    n = float(m.group(0)) if m else 0.0
    return -n if neg else n


def txt(v) -> str | None:
    s = re.sub(r"\s+", " ", str(v if v is not None else "")).strip()
    return s or None


def row_hash(*parts) -> str:
    return hashlib.sha1(json.dumps(parts, ensure_ascii=False, default=str, sort_keys=True).encode()).hexdigest()


def with_occurrence(items: list[tuple[str, tuple]]) -> list[tuple]:
    """(base hash, values) -> values + final hash; identical rows get #2, #3 ... so none is lost."""
    seen: dict[str, int] = {}
    out = []
    for base, values in items:
        seen[base] = seen.get(base, 0) + 1
        out.append((*values, base if seen[base] == 1 else row_hash(base, seen[base])))
    return out


def rows_of(d) -> list[dict]:
    if isinstance(d, list):
        return d
    return (d or {}).get("data") or (d or {}).get("aaData") or []


def dt_params(**extra) -> dict:
    return {"draw": 1, "start": 0, "length": 5000, "search[value]": "", "search[regex]": "false", **extra}


# --- details page --------------------------------------------------------------------------------------------------
def _text(s: str) -> str:
    return re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", s))).strip()


def parse_details(page: str) -> dict[str, str]:
    """{label: value} of the Service, Network & Product and Personal tabs. Password rows are skipped."""
    out: dict[str, str] = {}
    for pane in ("serviceDetails", "networksAndProductsInfo", "personalDetails"):
        i = page.find(f'id="{pane}"')
        if i < 0:
            continue
        j = page.find('role="tabpanel"', i + 10)
        for chunk in page[i: j if j > 0 else None].split('<div class="row">')[1:]:
            chunk = chunk.split("</td>")[0]
            m = re.search(r'<div class="col-sm-\d+">\s*:\s*</div>', chunk)
            if not m:
                continue
            label = _text(chunk[:m.start()])
            if label and not SECRET.search(label):
                out.setdefault(label, _text(chunk[m.end():]))
    m = re.search(r'id="chkMikrotikStatus"([^>]*)>', page)
    if m:
        out["_mikrotik_enabled"] = "1" if re.search(r"\bchecked\b", m.group(1)) else "0"
    m = re.search(r"Creation Date:</span>\s*<span>(.*?)</span>", page, re.S)
    if m:
        out["_created_on"] = _text(m.group(1))
    return out


DATE_FMTS = ("%d %b %Y", "%d-%b-%Y", "%d %B %Y", "%d/%m/%Y", "%d-%m-%Y", "%Y-%m-%d")


def save_details(company_id: int, h: int, f: dict[str, str]) -> None:
    g = lambda k: txt(f.get(k))  # noqa: E731
    login = g("Last Log in")
    details = {k: v for k, v in {
        "facebook": g("Facebook"), "linkedin": g("Linkedin"),
        "cable_meters": num(f.get("Cable Requirement in Metre")) if g("Cable Requirement in Metre") else None,
        "core_count": g("Number of Core"), "vendor": None if g("Vendor") in (None, "N/A") else g("Vendor"),
        "device_purchase_date": g("Purchase Date"), "billing_created_on": g("_created_on"),
        "mikrotik_enabled": {"1": True, "0": False}.get(f.get("_mikrotik_enabled") or ""),
        "last_logout": g("Last Log Out"), "client_type": g("Client Type"),
        "installation": {k2: v2 for k2, v2 in {
            "charged_on": g("Charge Created On"), "fee": num(f.get("Installation Fee")) if g("Installation Fee") else None,
            "paid": num(f.get("Paid Amount")) if g("Paid Amount") else None,
            "due": num(f.get("Due Amount")) if g("Due Amount") else None,
            "method": g("Payment Method"), "paid_on": g("Payment Date"), "received_by": g("Received By"),
        }.items() if v2 is not None} or None,
    }.items() if v not in (None, "", {})}
    db.execute(
        """UPDATE billing_customers SET mikrotik_profile = %s, billing_start_month = %s, reference_by = %s, setup_by = %s,
               last_login_at = %s, gender = %s, birth_date = %s, father_name = %s, mother_name = %s, occupation = %s,
               phone = %s, upazila = %s, present_address = %s, permanent_address = %s, form_no = %s,
               latitude = %s, longitude = %s, fiber_code = %s, device_mac = %s,
               email = COALESCE(%s, email), nid = COALESCE(%s, nid), details = %s, details_fetched_at = now()
           WHERE company_id = %s AND header_id = %s""",
        (g("Profile"), day(g("Billing Start Month"), *DATE_FMTS), g("Reference By"), g("Connection Setup By"),
         utc(login, "%d-%b-%Y %I:%M %p") if login else None, g("Gender"), day(g("Date of Birth"), *DATE_FMTS),
         g("Father Name"), g("Mother Name"), g("Occupation"), g("Phone Number"), g("Upazila"), g("Present Address"),
         g("Permanent Address"), g("Registration/SignUp Form No."), g("Latitude"), g("Longitude"), g("Fiber Code"),
         g("Device Mac/ Serial No"), g("Email"), g("NID"), json.dumps(details, ensure_ascii=False), company_id, h))


# --- sections ------------------------------------------------------------------------------------------------------
def fetch_details(api, company_id: int, h: int) -> int:
    f = parse_details(api.page("/customer/details", {"id": h}))
    if not f:
        raise RuntimeError("details page had no fields")
    save_details(company_id, h, f)
    return len(f)


def fetch_bills(api, company_id: int, h: int) -> int:
    rows = [clean(r) for r in rows_of(api._get(f"/BillTransaction/AjaxCusomerTransactionData/{h}", dt_params()))]
    values = [(company_id, h, int(r["BillMasterId"]), r.get("BillPeriodId"), r.get("BillPeriodYear"), r.get("BillPeriodMonth"),
               txt(r.get("MonthName")), day(r.get("CreationDate"), "%d %B %Y", "%d %b %Y"), txt(r.get("Package")),
               txt(r.get("Speed")), num(r.get("BillAmount")), num(r.get("Discount")), num(r.get("PaidAmount")),
               num(r.get("Due")), txt(r.get("Status")), txt(r.get("ProcessedBy")), txt(r.get("Remark")),
               bool(r.get("IsEditable")), json.dumps(r.get("childs") or [], ensure_ascii=False))
              for r in rows if r.get("BillMasterId")]
    with db.conn() as c, c.cursor() as cur:
        cur.executemany(
            """INSERT INTO billing_customer_bills (company_id, customer_header_id, bill_master_id, bill_period_id,
                   period_year, period_month, month_name, created_on, package, speed, bill_amount, discount, paid, due,
                   status, processed_by, remark, is_editable, versions, synced_at)
               VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s, now())
               ON CONFLICT (company_id, bill_master_id) DO UPDATE SET customer_header_id = EXCLUDED.customer_header_id,
                   bill_period_id = EXCLUDED.bill_period_id, period_year = EXCLUDED.period_year,
                   period_month = EXCLUDED.period_month, month_name = EXCLUDED.month_name, created_on = EXCLUDED.created_on,
                   package = EXCLUDED.package, speed = EXCLUDED.speed, bill_amount = EXCLUDED.bill_amount,
                   discount = EXCLUDED.discount, paid = EXCLUDED.paid, due = EXCLUDED.due, status = EXCLUDED.status,
                   processed_by = EXCLUDED.processed_by, remark = EXCLUDED.remark, is_editable = EXCLUDED.is_editable,
                   versions = EXCLUDED.versions, synced_at = now(), removed_at = NULL""", values)
        if values:  # an empty answer is not trusted to remove anything
            cur.execute("""UPDATE billing_customer_bills SET removed_at = now() WHERE company_id = %s
                           AND customer_header_id = %s AND removed_at IS NULL AND NOT (bill_master_id = ANY(%s))""",
                        (company_id, h, [v[2] for v in values]))
    return len(values)


def fetch_payments(api, company_id: int, h: int) -> int:
    rows = [clean(r) for r in rows_of(api._get(f"/Customer/AjaxReceivedHistory/{h}", dt_params()))]
    values = [(company_id, h, int(r["BillHeaderId"]), day(r.get("BillReceivedDate"), "%d %b %Y"),
               day(r.get("PaymentDate"), "%d %b %Y"), utc(r.get("UpdatedDate"), "%d/%m/%Y %H:%M:%S"),
               txt(r.get("BillMonth")), txt(r.get("Package")), num(r.get("PayabaleBill")), num(r.get("PaidAmount")),
               num(r.get("Discount")), num(r.get("BalanceDue")), txt(r.get("PaymentMethodName")),
               txt(r.get("PaymentMethodShortName")), bool(r.get("IsRealPayment")), bool(r.get("IsBillReceiveCanceled")),
               txt(r.get("ReceivedBy")), txt(r.get("CreatedBy")), txt(r.get("UpdatedBy")), txt(r.get("Remarks")),
               txt(r.get("ReceiptOrTransactionNo")), txt(r.get("MoneyReceiptNo")))
              for r in rows if r.get("BillHeaderId")]
    with db.conn() as c, c.cursor() as cur:
        cur.executemany(
            """INSERT INTO billing_customer_payments (company_id, customer_header_id, bill_header_id, received_on,
                   entered_on, approved_at, bill_month, package, payable, paid, discount, balance_due, method, method_short,
                   is_online, is_canceled, received_by, created_by, approved_by, remarks, trx_no, money_receipt_no, synced_at)
               VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s, now())
               ON CONFLICT (company_id, bill_header_id) DO UPDATE SET customer_header_id = EXCLUDED.customer_header_id,
                   received_on = EXCLUDED.received_on, entered_on = EXCLUDED.entered_on, approved_at = EXCLUDED.approved_at,
                   bill_month = EXCLUDED.bill_month, package = EXCLUDED.package, payable = EXCLUDED.payable,
                   paid = EXCLUDED.paid, discount = EXCLUDED.discount, balance_due = EXCLUDED.balance_due,
                   method = EXCLUDED.method, method_short = EXCLUDED.method_short, is_online = EXCLUDED.is_online,
                   is_canceled = EXCLUDED.is_canceled, received_by = EXCLUDED.received_by, created_by = EXCLUDED.created_by,
                   approved_by = EXCLUDED.approved_by, remarks = EXCLUDED.remarks, trx_no = EXCLUDED.trx_no,
                   money_receipt_no = EXCLUDED.money_receipt_no, synced_at = now(), removed_at = NULL""", values)
        if values:
            cur.execute("""UPDATE billing_customer_payments SET removed_at = now() WHERE company_id = %s
                           AND customer_header_id = %s AND removed_at IS NULL AND NOT (bill_header_id = ANY(%s))""",
                        (company_id, h, [v[2] for v in values]))
    link_daily_bills(company_id, h)
    return len(values)


def link_daily_bills(company_id: int, h: int) -> None:
    """Pair receipts with the desk's copy of the Daily Bill Collection (different ids in billing): same receipt number,
    else same customer, day, amount, discount and receiver. Fills the daily bill's receipt number when it had none."""
    db.execute(
        """UPDATE billing_customer_payments p SET daily_bill_id = d.daily_bill_id
           FROM billing_daily_bills d
           WHERE p.company_id = %s AND p.customer_header_id = %s AND p.daily_bill_id IS NULL
             AND d.company_id = p.company_id AND d.customer_header_id = p.customer_header_id
             AND d.money_receipt_no IS NOT NULL AND d.money_receipt_no = p.money_receipt_no""", (company_id, h))
    for p in db.all_rows(
            """SELECT id, received_on, paid, discount, received_by, money_receipt_no FROM billing_customer_payments
               WHERE company_id = %s AND customer_header_id = %s AND daily_bill_id IS NULL AND NOT is_canceled
                 AND removed_at IS NULL ORDER BY bill_header_id""", (company_id, h)):
        d = db.one(
            """SELECT id, daily_bill_id, money_receipt_no FROM billing_daily_bills d
               WHERE company_id = %s AND customer_header_id = %s AND NOT is_deleted AND is_approved
                 AND ((collected_at AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Dhaka')::date = %s
                 AND abs(receive_amount - %s) < 0.001 AND abs(discount_amount - %s) < 0.001
                 AND (%s::text IS NULL OR coalesce(received_by, '') = %s::text)
                 AND NOT EXISTS (SELECT 1 FROM billing_customer_payments q WHERE q.company_id = d.company_id
                                 AND q.daily_bill_id = d.daily_bill_id)
               ORDER BY collected_at LIMIT 1""",
            (company_id, h, p["received_on"], p["paid"], p["discount"], p["received_by"], p["received_by"]))
        if d:
            db.execute("UPDATE billing_customer_payments SET daily_bill_id = %s WHERE id = %s", (d["daily_bill_id"], p["id"]))
            if not d["money_receipt_no"] and p["money_receipt_no"]:
                db.execute("UPDATE billing_daily_bills SET money_receipt_no = %s WHERE id = %s",
                           (p["money_receipt_no"], d["id"]))


def fetch_complains(api, company_id: int, h: int) -> int:
    rows = [clean(r) for r in rows_of(api._get("/Customer/AjaxCompainHistory", dt_params(id=h)))]
    c = db.one("""SELECT customer_id, username, name, mobile, zone, subzone, box FROM billing_customers
                  WHERE company_id = %s AND header_id = %s""", (company_id, h)) or {}
    values = []
    for r in rows:
        if not r.get("ComplainId"):
            continue
        solved = r.get("Status") == 1
        solvers = r.get("SolvedBy")
        values.append((company_id, str(r["ComplainId"]), c.get("customer_id"), h, c.get("username"), c.get("name"),
                       txt(r.get("ComplainPhoneNumber")) or c.get("mobile"), c.get("zone"), c.get("subzone"), c.get("box"),
                       txt(r.get("Problem")), PRIORITY.get(int(r.get("ProblemPriorityId") or 2), "medium"),
                       "solved" if solved else "pending",
                       ", ".join(x for x in solvers if x) if isinstance(solvers, list) else txt(solvers),
                       txt(r.get("CreatedBy")), txt(r.get("Comment")),
                       utc(r.get("CreatedOn"), "%d %b %Y %I:%M:%S %p", "%d %b %Y %I:%M %p"),
                       utc(r.get("SolvedOn"), "%d %b %Y %I:%M %p", "%d %b %Y %I:%M:%S %p") if solved else None,
                       json.dumps(r, ensure_ascii=False, default=str)))
    with db.conn() as conn, conn.cursor() as cur:
        # the ticket sync owns rows it has seen (they carry more); only tickets it never saw are added
        cur.executemany(
            """INSERT INTO billing_tickets (company_id, complain_id, customer_id, customer_header_id, username,
                   customer_name, mobile, zone, subzone, box, category, priority, state, solved_by, created_by, note,
                   opened_at, solved_at, raw, source, synced_at, created_at, updated_at)
               VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s, 'history', now(), now(), now())
               ON CONFLICT (company_id, complain_id) DO NOTHING""", values)
    return len(values)


def fetch_remarks(api, company_id: int, h: int) -> int:
    rows = [clean(r) for r in rows_of(api._get("/Customer/AjaxRemarksHistory", dt_params(id=h)))]
    items = []
    for r in rows:
        v = (company_id, h, txt(r.get("RemarksType")), txt(r.get("Comment")),
             utc(r.get("CreatedOn"), "%d %B %Y %I:%M %p", "%d %b %Y %I:%M %p"), txt(r.get("CreatedBy")))
        items.append((row_hash(*v[2:]), v))
    with db.conn() as c, c.cursor() as cur:
        cur.executemany(
            """INSERT INTO billing_customer_remarks (company_id, customer_header_id, remarks_type, comment, created_at,
                   created_by, row_hash) VALUES (%s,%s,%s,%s,%s,%s,%s)
               ON CONFLICT (company_id, customer_header_id, row_hash) DO NOTHING""", with_occurrence(items))
    return len(items)


def sms_cutoff() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None) - timedelta(days=SMS_MONTHS * 31)


def fetch_sms(api, company_id: int, h: int) -> int:
    """Newest first; stops paging once rows are older than the kept 12 months."""
    cutoff, values, start = sms_cutoff(), [], 0
    while True:
        d = api._get("/Customer/AjaxMessagesHistory", {**dt_params(id=h), "start": start, "length": 500})
        batch = rows_of(d)
        old = False
        for r in batch:
            sent = utc(r.get("date"), "%d %B %Y", "%d %b %Y")
            if sent and sent < cutoff:
                old = True
                continue
            if r.get("SMSLogId"):
                values.append((company_id, h, int(r["SMSLogId"]), txt(r.get("smsType")), txt(r.get("smsTypeName")),
                               r.get("smsText"), sent, txt(r.get("status")), txt(r.get("sentBy"))))
        start += 500
        if old or len(batch) < 500 or start >= int((d or {}).get("iTotalDisplayRecords") or 0):
            break
    save_sms(values, keep_time=True)
    return len(values)


def save_sms(values: list[tuple], keep_time: bool) -> None:
    """keep_time: the per-customer list only knows the day, so an exact time already saved is kept."""
    with db.conn() as c, c.cursor() as cur:
        cur.executemany(
            f"""INSERT INTO billing_customer_sms (company_id, customer_header_id, sms_log_id, sms_type, type_name, text,
                   sent_at, status, sent_by) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)
               ON CONFLICT (company_id, sms_log_id) DO UPDATE SET status = EXCLUDED.status,
                   sent_by = COALESCE(EXCLUDED.sent_by, billing_customer_sms.sent_by)
                   {"" if keep_time else ", sent_at = EXCLUDED.sent_at"}""", values)


CHANGE_SKIP = {"ChangedBy", "ChangedDate", "Remarks", "EToRemarks"}


def fetch_changes(api, company_id: int, h: int) -> int:
    rows = rows_of(api._get("/Customer/AjaxCustomerChangeLog", dt_params(customerHeadId=h, fromDate="", toDate="")))
    items, prev, prev_pw = [], None, None
    for raw in reversed(rows):  # billing lists newest first; compare each change with the one before it
        pw = raw.get("Password")  # held only in this loop, to know whether it changed; never stored
        snap = clean(raw)
        changed = None if prev is None else {
            k: [prev.get(k), v] for k, v in snap.items() if k not in CHANGE_SKIP and prev.get(k) != v}
        pw_changed = prev is not None and pw != prev_pw
        v = (company_id, h, utc(snap.get("ChangedDate"), "%d %b %Y %I:%M %p"), txt(snap.get("ChangedBy")),
             txt(snap.get("Remarks")), json.dumps(snap, ensure_ascii=False, default=str),
             json.dumps(changed, ensure_ascii=False, default=str) if changed is not None else None, pw_changed)
        items.append((row_hash(snap), v))
        prev, prev_pw = snap, pw
    prev_pw = pw = None
    with db.conn() as c, c.cursor() as cur:
        cur.executemany(
            """INSERT INTO billing_customer_change_logs (company_id, customer_header_id, changed_at, changed_by, remarks,
                   snapshot, changed, password_changed, row_hash) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)
               ON CONFLICT (company_id, customer_header_id, row_hash) DO UPDATE SET changed = EXCLUDED.changed,
                   password_changed = EXCLUDED.password_changed""", with_occurrence(items))
    return len(items)


def fetch_line(api, company_id: int, h: int) -> int:
    rows = [clean(r) for r in rows_of(api._get("/Customer/AjaxCustomerEnableDisableLog",
                                               dt_params(cusId=h, fromDate="", toDate="")))]
    items = []
    for r in reversed(rows):
        status = (r.get("MikrotikStatus") or "").lower()
        v = (company_id, h, utc(r.get("ChangedDate"), "%d %b %Y %I:%M %p"), txt(r.get("ChangedBy")),
             "enable" if status == "enable" else "disable" if status == "disable" else txt(status), txt(r.get("Remarks")))
        items.append((row_hash(*v[2:]), v))
    with db.conn() as c, c.cursor() as cur:
        cur.executemany(
            """INSERT INTO billing_customer_line_logs (company_id, customer_header_id, changed_at, changed_by, action,
                   remarks, row_hash) VALUES (%s,%s,%s,%s,%s,%s,%s)
               ON CONFLICT (company_id, customer_header_id, row_hash) DO NOTHING""", with_occurrence(items))
    return len(items)


def fetch_invoices(api, company_id: int, h: int) -> int:
    rows = [clean(r) for r in rows_of(api._get("/Customer/AjaxServiceAndProductInvoices", dt_params(customerHeadId=h)))]
    values = [(company_id, h, int(r["InvoiceId"]), txt(r.get("InvoiceNo")), txt(r.get("InvoiceType")),
               num(r.get("TotalAmount")), num(r.get("PaidAmount")), num(r.get("DiscountAmount")), num(r.get("DueAmount")),
               day(r.get("PaymentDueDate"), "%d %m %Y", "%d %b %Y"), txt(r.get("SpecialNote")))
              for r in rows if r.get("InvoiceId")]
    with db.conn() as c, c.cursor() as cur:
        cur.executemany(
            """INSERT INTO billing_customer_invoices (company_id, customer_header_id, invoice_id, invoice_no, invoice_type,
                   total, paid, discount, due, due_date, special_note, synced_at)
               VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s, now())
               ON CONFLICT (company_id, invoice_id) DO UPDATE SET customer_header_id = EXCLUDED.customer_header_id,
                   invoice_no = EXCLUDED.invoice_no, invoice_type = EXCLUDED.invoice_type, total = EXCLUDED.total,
                   paid = EXCLUDED.paid, discount = EXCLUDED.discount, due = EXCLUDED.due, due_date = EXCLUDED.due_date,
                   special_note = EXCLUDED.special_note, synced_at = now()""", values)
    return len(values)


FETCH = {"details": fetch_details, "bills": fetch_bills, "payments": fetch_payments, "complains": fetch_complains,
         "remarks": fetch_remarks, "sms": fetch_sms, "changes": fetch_changes, "line": fetch_line,
         "invoices": fetch_invoices}


def _mark(company_id: int, h: int, section: str, rows: int, error: str | None) -> None:
    db.execute(
        """INSERT INTO billing_customer_fetches (company_id, customer_header_id, section, fetched_at, rows, error)
           VALUES (%s, %s, %s, CASE WHEN %s::text IS NULL THEN now() END, %s, %s)
           ON CONFLICT (company_id, customer_header_id, section) DO UPDATE SET
             fetched_at = CASE WHEN EXCLUDED.error IS NULL THEN now() ELSE billing_customer_fetches.fetched_at END,
             rows = CASE WHEN EXCLUDED.error IS NULL THEN EXCLUDED.rows ELSE billing_customer_fetches.rows END,
             error = EXCLUDED.error""", (company_id, h, section, error, rows, error))


def stale(company_id: int, h: int, sections: list[str], max_age: dict[str, int]) -> list[str]:
    """Sections never fetched or older than their max age (seconds)."""
    have = {r["section"]: r["age"] for r in db.all_rows(
        """SELECT section, extract(epoch FROM now() - fetched_at) AS age FROM billing_customer_fetches
           WHERE company_id = %s AND customer_header_id = %s AND fetched_at IS NOT NULL""", (company_id, h))}
    return [s for s in sections if s not in have or have[s] > max_age.get(s, 0)]


def refresh(t, h: int, sections: list[str] | None = None, api=None) -> dict:
    """Fetch the given sections (default: all) of one customer. One failing section doesn't stop the others."""
    api = api or tenants.billing(t)
    out = {}
    for s in sections or ALL:
        try:
            n = FETCH[s](api, t.company_id, h)
            _mark(t.company_id, h, s, n, None)
            out[s] = n
        except Exception as e:
            from engine.ispdigital import LoginError
            if isinstance(e, LoginError):
                raise
            log.warning("customer %s %s: %s", h, s, e)
            _mark(t.company_id, h, s, 0, str(e)[:300])
            out[s] = f"error: {e}"[:120]
    return out


# --- page opened in the desk ---------------------------------------------------------------------------------------
PAGE_MAX_AGE = {"details": 86400, "bills": 600, **{s: 600 for s in FAST}}
_running: set[tuple[int, int]] = set()
_running_lock = threading.Lock()


def refresh_in_background(t, h: int, max_age: dict[str, int] | None = None) -> dict:
    """The customer page was opened: re-read whatever is older than max_age in a thread. Returns what is happening."""
    key = (t.company_id, h)
    with _running_lock:
        if key in _running:
            return {"running": True, "started": False}
        todo = stale(t.company_id, h, ALL, max_age or PAGE_MAX_AGE)
        if not todo:
            return {"running": False, "started": False}
        _running.add(key)

    def run():
        try:
            log.info("page refresh %s/%s %s: %s", t.company_id, h, todo, refresh(t, h, todo))
        except Exception:
            log.exception("page refresh %s/%s failed", t.company_id, h)
        finally:
            with _running_lock:
                _running.discard(key)

    threading.Thread(target=run, daemon=True).start()
    return {"running": True, "started": True, "sections": todo}


def is_running(company_id: int, h: int) -> bool:
    with _running_lock:
        return (company_id, h) in _running


# --- company-wide SMS ----------------------------------------------------------------------------------------------
def sync_sms(t, days: int = 2) -> dict:
    """SMS of the last days from billing's SMS report (one list for everyone), with exact send times."""
    api = tenants.billing(t)
    today = datetime.now(DHAKA).date()
    frm = today - timedelta(days=days - 1)
    known = {r["header_id"] for r in db.all_rows("SELECT header_id FROM billing_customers WHERE company_id = %s",
                                                (t.company_id,))}
    values, start = [], 0
    while True:
        d = api._get("/Report/AjaxTextMessages", {**dt_params(fromDate=frm.strftime("%d-%m-%Y"),
                                                              toDate=today.strftime("%d-%m-%Y"), toSendType="",
                                                              toSendId="", sendById="", msgTemType="", msgStatus=""),
                                                  "start": start, "length": 500})
        batch = rows_of(d)
        for r in batch:
            uid = int(r.get("UserId") or 0)
            if str(r.get("UserType")) != "2" or uid not in known or not r.get("SMSLogId"):
                continue  # only customers' SMS (employees / resellers have other user types)
            values.append((t.company_id, uid, int(r["SMSLogId"]), txt(r.get("SMSType")), txt(r.get("SMSTypeName")),
                           r.get("SMSText"), utc(r.get("SendingDate"), "%d %b %Y %I:%M:%S %p"), txt(r.get("Status")),
                           txt(r.get("SendBy")) or txt(r.get("EmployeeName"))))
        start += 500
        if len(batch) < 500 or start >= int((d or {}).get("iTotalDisplayRecords") or 0):
            break
    save_sms(values, keep_time=False)
    return {"from": str(frm), "to": str(today), "saved": len(values)}


# --- nightly -------------------------------------------------------------------------------------------------------
def _customers(company_id: int) -> list[int]:
    """Current customers first, then Left ones; never those billing no longer has."""
    return [r["header_id"] for r in db.all_rows(
        """SELECT header_id FROM billing_customers WHERE company_id = %s AND gone_at IS NULL
           ORDER BY is_left, header_id""", (company_id,))]


def _past(stop: datetime | None) -> bool:
    return bool(stop) and datetime.now(DHAKA) >= stop


def nightly(t, stop_at: int | None = None) -> dict:
    """1) fast sections for every customer already copied once (a few minutes per thousand);
    2) details + bills for those whose change log got something new or whose bills predate this month;
    3) the first full copy (backfill) for the rest, current customers first, until stop_at (Bangladesh hour)."""
    api = tenants.billing(t)
    now = datetime.now(DHAKA)
    stop = None
    if stop_at is not None:
        stop = now.replace(hour=stop_at, minute=0, second=0, microsecond=0)
        if stop <= now:
            stop += timedelta(days=1)
    month_start = utc(now.strftime("01 %b %Y") + " 06:00 AM", "%d %b %Y %I:%M %p")
    stats = {"fast": 0, "details": 0, "bills": 0, "backfill": 0, "errors": 0, "stopped": False}
    done = {r["customer_header_id"]: r for r in db.all_rows(
        """SELECT customer_header_id, count(*) FILTER (WHERE fetched_at IS NOT NULL) AS n,
                  max(fetched_at) FILTER (WHERE section = 'bills') AS bills_at,
                  max(fetched_at) FILTER (WHERE section = 'changes') AS changes_at
           FROM billing_customer_fetches WHERE company_id = %s GROUP BY 1""", (t.company_id,))}
    order = _customers(t.company_id)
    copied = [h for h in order if (done.get(h) or {}).get("n", 0) >= len(ALL)]
    fresh = [h for h in order if h not in set(copied)]

    def count(res):
        stats["errors"] += sum(1 for v in res.values() if isinstance(v, str))

    for h in copied:
        if _past(stop):
            stats["stopped"] = True
            break
        before = db.one("""SELECT max(id) AS m FROM billing_customer_change_logs WHERE company_id = %s
                           AND customer_header_id = %s""", (t.company_id, h))["m"]
        count(refresh(t, h, FAST, api))
        stats["fast"] += 1
        after = db.one("""SELECT max(id) AS m FROM billing_customer_change_logs WHERE company_id = %s
                          AND customer_header_id = %s""", (t.company_id, h))["m"]
        extra = []
        if after != before:
            extra += ["details", "bills"]
        elif (done[h]["bills_at"] or datetime.min) < month_start:
            extra += ["bills"]
        if extra:
            count(refresh(t, h, extra, api))
            stats["details"] += "details" in extra
            stats["bills"] += 1
        time.sleep(PAUSE)
    for h in fresh:
        if _past(stop):
            stats["stopped"] = True
            break
        count(refresh(t, h, stale(t.company_id, h, ALL, {s: 10 ** 9 for s in ALL}), api))  # only never-fetched parts
        stats["backfill"] += 1
        time.sleep(PAUSE)
    stats["sms_pruned"] = (db.execute(
        """WITH d AS (DELETE FROM billing_customer_sms WHERE company_id = %s AND sent_at < %s RETURNING 1)
           SELECT count(*) AS n FROM d""", (t.company_id, sms_cutoff())) or {}).get("n")
    stats["left_to_backfill"] = max(0, len(fresh) - stats["backfill"])
    return stats


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    logging.getLogger("httpx").setLevel(logging.WARNING)
    p = argparse.ArgumentParser()
    p.add_argument("--customer", type=int, help="CustomerHeaderId: all sections of one customer")
    p.add_argument("--section", action="append", choices=ALL)
    p.add_argument("--nightly", action="store_true")
    p.add_argument("--stop-at", type=int, help="Bangladesh hour to stop the nightly run at (e.g. 7)")
    p.add_argument("--sms", action="store_true", help="company-wide SMS of the last --days days")
    p.add_argument("--days", type=int, default=2)
    p.add_argument("--company", type=int)
    a = p.parse_args()
    for t in companies(a.company):
        try:
            if a.customer:
                log.info("customer %s: %s", a.customer, refresh(t, a.customer, a.section))
            if a.sms:
                log.info("company %s sms: %s", t.company_id, sync_sms(t, a.days))
            if a.nightly:
                os.makedirs(os.path.dirname(LOCK), exist_ok=True)
                with open(LOCK, "w") as lock:
                    try:
                        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    except BlockingIOError:
                        log.info("another nightly run is going on; skipping")
                        return
                    log.info("company %s nightly: %s", t.company_id, nightly(t, a.stop_at))
        except Exception:
            log.exception("customer history failed for company %s", t.company_id)


if __name__ == "__main__":
    main()
