"""Bill collection from the panel: the billing software's Daily Bill Collection -> Receive Bill form.

A collection stays pending until staff approve it in the billing software, and the customer's due only
drops after that approval. So before posting we refuse while the customer still has a pending
collection; otherwise the same bill could be taken twice. Every attempt is logged in bill_collections.
"""
import re
import threading

from engine import db
from engine.ispdigital import BillingRefused, ISPDigital, LoginError, money

_locks: dict[tuple[int, int], threading.Lock] = {}
_locks_guard = threading.Lock()


class BillError(RuntimeError):
    pass


def _lock(company_id: int, header_id: int) -> threading.Lock:
    with _locks_guard:
        return _locks.setdefault((company_id, header_id), threading.Lock())


def _pending(api: ISPDigital, header_id: int, username: str) -> list[dict]:
    if not username:
        raise BillError("কাস্টমারের username পাওয়া যায়নি")
    return [{"id": r.get("DailyBillId"), "date": r.get("CreationTime"), "amount": r.get("ReceiveAmount"),
             "discount": r.get("DiscountAmount"), "method": r.get("PaymentMethod"), "received_by": r.get("ReceivedBy"),
             "created_by": r.get("CreatedBy")}
            for r in api.pending_bills(username) if int(r.get("CustomerHeaderId") or 0) == int(header_id)]


def form_info(api: ISPDigital, header_id: int) -> dict:
    """What the panel's form needs: amounts, choices, defaults and any pending collection."""
    f = api.bill_form(header_id)
    methods = f["methods"]
    cash = next((k for k, v in methods.items() if v.strip().lower() == "cash"), None)
    return {
        "username": f["username"], "status": f["status"], "monthly": f["monthly"], "payable": f["payable"],
        "period_id": f["period_id"], "bill_month": f["values"].get("BillMonthName"),
        "received_from": f["values"].get("ReceivedFrom"),
        "received_by": f["received_by"],
        "received_by_default": f["current_employee_id"] if f["current_employee_id"] in f["received_by"] else None,
        "methods": methods, "method_default": cash,
        "pending": _pending(api, header_id, f["username"]),
    }


def validate(payable: float, paid: float, discount: float, method_id: str, remarks: str, trx_no: str) -> None:
    """Same rules as the billing form's ValidateDailyBillForm, plus no over-payment."""
    if paid < 0 or discount < 0:
        raise BillError("টাকা বা ডিসকাউন্ট মাইনাস হতে পারে না")
    if paid == 0 and discount == 0:
        raise BillError("টাকা বা ডিসকাউন্ট অন্তত একটা দিন")
    if paid > 0 and not method_id:
        raise BillError("পেমেন্টের মাধ্যম বাছাই করুন")
    if paid + discount > payable + 0.001:
        raise BillError(f"বকেয়া {money(payable)} টাকা, এর বেশি নেওয়া যাবে না")
    if (abs(paid + discount - payable) > 0.001 or discount > 0) and not remarks.strip():
        raise BillError("আংশিক টাকা বা ডিসকাউন্ট হলে নোট লিখতে হবে")
    if trx_no and not re.fullmatch(r"\S+", trx_no):
        raise BillError("Transaction নম্বরে ফাঁকা জায়গা রাখা যাবে না")


def collect(company_id: int, user_id: int, api: ISPDigital, header_id: int, b: dict) -> dict:
    paid, discount = float(b.get("paid") or 0), float(b.get("discount") or 0)
    method_id, received_by = str(b.get("method_id") or ""), str(b.get("received_by_id") or "")
    remarks, trx_no = (b.get("remarks") or "").strip(), (b.get("trx_no") or "").strip()
    cust = db.one("SELECT customer_id, name FROM billing_customers WHERE company_id = %s AND header_id = %s",
                  (company_id, header_id)) or {}
    log = {"company_id": company_id, "user_id": user_id or None, "customer_header_id": header_id,
           "customer_id": cust.get("customer_id"), "customer_name": cust.get("name"), "paid": paid, "discount": discount,
           "trx_no": trx_no or None, "remarks": remarks or None, "send_sms": bool(b.get("sms", True)),
           "next_billing_date": bool(b.get("next_billing_date"))}
    lock = _lock(company_id, header_id)
    if not lock.acquire(blocking=False):
        raise BillError("এই কাস্টমারের বিল এই মুহূর্তে আরেকজন নিচ্ছেন")
    try:
        f = api.bill_form(header_id)
        log.update(username=f["username"], bill_period_id=f["period_id"], payable=f["payable"],
                   payment_method=f["methods"].get(method_id), received_by=f["received_by"].get(received_by),
                   bill_month=b.get("bill_month") or f["values"].get("BillMonthName"))
        try:
            if not f["has_tokens"]:
                raise BillError("বিলিং ফর্মে নিরাপত্তা টোকেন পাওয়া যায়নি, বিল নেওয়া হয়নি")
            expected = b.get("expected_payable")
            if expected is not None and abs(float(expected) - f["payable"]) > 0.001:
                raise BillError(f"বিলিং-এ বকেয়া বদলে এখন {money(f['payable'])} টাকা। ফর্মটা আবার খুলুন।")
            if received_by not in f["received_by"]:
                raise BillError("কে টাকা নিলেন (Received By) বাছাই করুন")
            if method_id and method_id not in f["methods"]:
                raise BillError("এই পেমেন্টের মাধ্যম বিলিং-এ নেই")
            validate(f["payable"], paid, discount, method_id, remarks, trx_no)
            before = _pending(api, header_id, f["username"])
            if before:
                p = before[0]
                raise BillError(f"এই কাস্টমারের {p['amount']} টাকার একটা বিল ({p['date']}, {p['created_by']}) এখনো approve "
                                "হয়নি। approve না হওয়া পর্যন্ত বকেয়া কমে না, তাই আবার নিলে বিল ডাবল হবে।")
        except BillError as e:
            _save(log, "blocked", str(e))
            raise
        try:
            message = api.receive_bill(f, paid, discount, method_id, received_by, remarks=remarks, trx_no=trx_no,
                                       send_sms=log["send_sms"], next_billing_date=log["next_billing_date"],
                                       bill_month=log["bill_month"])
        except LoginError as e:
            _save(log, "failed", str(e)[:500])
            raise BillError("বিলিং-এর লগইন সেশন বাতিল হয়েছিল, বিল জমা হয়নি। আবার চেষ্টা করুন।")
        except BillingRefused as e:
            _save(log, "failed", str(e)[:500])
            raise BillError(f"বিলিং বিলটা নেয়নি: {e}")
        except Exception as e:
            _save(log, "failed", str(e)[:500])
            raise BillError("বিলিং-এর উত্তর বোঝা যায়নি। বিল জমা হয়েছে কি না Daily Bill Collection-এ দেখে নিন, "
                            "আবার জমা দেওয়ার আগে।")
        # find the new pending row so the panel can show its billing id
        try:
            after = _pending(api, header_id, f["username"])
            daily_bill_id = after[0]["id"] if after else None
        except Exception:
            daily_bill_id = None
        _save(log, "saved", message, daily_bill_id)
        return {"message": message, "daily_bill_id": daily_bill_id, "payable": f["payable"],
                "balance": round(f["payable"] - paid - discount, 2)}
    finally:
        lock.release()


def _save(log: dict, result: str, message: str, daily_bill_id=None) -> None:
    row = {**log, "result": result, "message": message, "daily_bill_id": daily_bill_id}
    cols = ", ".join(row)
    db.execute(f"INSERT INTO bill_collections ({cols}, created_at) VALUES ({', '.join(['%s'] * len(row))}, now())",
               tuple(row.values()))
