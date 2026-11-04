"""bKash merchant webhook (IPN) for each company: https://desk.zyrotechbd.com/pay/bkash/webhook/{company slug}.

bKash sends Instant Payment Notifications through Amazon SNS (developer.bka.sh/page/instant-payment-notification.md):
bKash technical support subscribes our URL to the wallet's topic, SNS posts a SubscriptionConfirmation once (we open its
SubscribeURL to confirm), then one Notification per completed payment. bKash allows only one webhook per wallet, so once
the URL moves here from the billing software the Desk is the only one that hears about these payments.

Every message is checked before anything is done with it:
  * the Amazon SNS signature (SigningCertURL must be an https sns.<region>.amazonaws.com .pem; SignatureVersion 1 =
    SHA1, 2 = SHA256, over the fields SNS signs);
  * the TopicArn must be the company's confirmed topic. The first subscription is confirmed by itself while the
    company's switch is on and no topic is set yet; any other topic waits for the owner (bkash_webhooks.pending_*).
Every message is kept in bkash_webhook_messages. A payment becomes a billing_online_payments row (source
'desk_webhook', one per TrxID), matched to a customer by the reference (customer ID, at least 4 digits with leading
zeros) or else by the paying number (one customer only). Unmatched ones alert owners/admins.

settle_in_billing on: a matched payment is put into the billing software as a bKash bill with the TrxID, approved at
once, with the chosen member's billing login (engine/bills.collect), and a disabled line is turned on when the payment
covers expire_min_paid_pct of the due. Anything that fails stays "failed" on the online payments page for staff.
"""
import base64
import json
import logging
import re
import threading
import time
from datetime import datetime, timezone
from urllib.parse import urlparse
from zoneinfo import ZoneInfo

import httpx
from cryptography import x509
from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import padding

from engine import db

log = logging.getLogger("zyro-bkash-webhook")
DHAKA = ZoneInfo("Asia/Dhaka")
CERT_HOST = re.compile(r"^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$")
# the fields SNS signs, in this order (docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html)
SIGNED = {"Notification": ("Message", "MessageId", "Subject", "Timestamp", "TopicArn", "Type"),
          "SubscriptionConfirmation": ("Message", "MessageId", "SubscribeURL", "Timestamp", "Token", "TopicArn", "Type"),
          "UnsubscribeConfirmation": ("Message", "MessageId", "SubscribeURL", "Timestamp", "Token", "TopicArn", "Type")}
_certs: dict[str, tuple[float, x509.Certificate]] = {}
_settle_lock = threading.Lock()


class Rejected(ValueError):
    pass


# --- Amazon SNS signature --------------------------------------------------------------------
def sns_url_ok(url: str | None) -> bool:
    """An https URL on Amazon SNS itself (sns.<region>.amazonaws.com), nothing else."""
    try:
        u = urlparse(url or "")
    except ValueError:
        return False
    return u.scheme == "https" and bool(CERT_HOST.match(u.hostname or "")) and u.port in (None, 443)


def cert(url: str) -> x509.Certificate:
    if not sns_url_ok(url) or not urlparse(url).path.endswith(".pem"):
        raise Rejected("SigningCertURL is not an Amazon SNS certificate")
    hit = _certs.get(url)
    if hit and time.time() - hit[0] < 24 * 3600:
        return hit[1]
    r = httpx.get(url, timeout=10, follow_redirects=False)
    r.raise_for_status()
    c = x509.load_pem_x509_certificate(r.content)
    _certs[url] = (time.time(), c)
    return c


def string_to_sign(m: dict) -> str:
    fields = SIGNED.get(m.get("Type") or "")
    if not fields:
        raise Rejected(f"unknown message type {m.get('Type')!r}")
    out = []
    for k in fields:
        v = m.get(k)
        if v is None:
            if k == "Subject":
                continue  # signed only when present
            raise Rejected(f"{k} missing")
        if not isinstance(v, str):
            raise Rejected(f"{k} is not a string")  # SNS signs the text as sent; an object can't be checked
        out.append(f"{k}\n{v}\n")
    return "".join(out)


def verify(m: dict) -> None:
    """Raises Rejected unless Amazon SNS signed this message."""
    version = str(m.get("SignatureVersion") or "")
    algo = {"1": hashes.SHA1(), "2": hashes.SHA256()}.get(version)
    if not algo:
        raise Rejected(f"SignatureVersion {version!r} not supported")
    try:
        sig = base64.b64decode(m.get("Signature") or "", validate=True)
    except ValueError:
        raise Rejected("Signature is not base64")
    try:
        cert(m.get("SigningCertURL") or "").public_key().verify(sig, string_to_sign(m).encode(), padding.PKCS1v15(), algo)
    except InvalidSignature:
        raise Rejected("bad signature")


# --- the endpoint ------------------------------------------------------------------------------
def settings(company_id: int) -> dict | None:
    return db.one("SELECT * FROM bkash_webhooks WHERE company_id = %s", (company_id,))


def company_by_slug(slug: str) -> dict | None:
    return db.one("SELECT id, slug FROM companies WHERE slug = %s", (slug,))


def _keep(company_id: int, m: dict, verified: bool, result: str, error: str | None = None,
          payment_id: int | None = None) -> int | None:
    row = db.execute(
        """INSERT INTO bkash_webhook_messages (company_id, sns_message_id, type, topic_arn, verified, result, error, body,
               payment_id, received_at)
           VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, now())
           ON CONFLICT (company_id, sns_message_id) DO UPDATE SET verified = EXCLUDED.verified, result = EXCLUDED.result,
               error = EXCLUDED.error, payment_id = coalesce(EXCLUDED.payment_id, bkash_webhook_messages.payment_id),
               received_at = now()
           RETURNING id""",
        (company_id, _txt(m.get("MessageId")), _txt(m.get("Type")), _txt(m.get("TopicArn")), verified, result,
         error, json.dumps(m, ensure_ascii=False), payment_id))
    return row and row["id"]


def handle(slug: str, message_type: str | None, raw: bytes) -> tuple[int, str]:
    """One POST from SNS: (HTTP status, short text). 200 for anything handled (also duplicates and switched-off
    companies, so SNS doesn't keep retrying); 403 for a bad signature or topic; 404 for an unknown company."""
    co = company_by_slug(slug)
    if not co:
        return 404, "unknown"
    try:
        m = json.loads(raw.decode("utf-8"))
        if not isinstance(m, dict):
            raise ValueError
    except (ValueError, UnicodeDecodeError):
        return 400, "not json"
    kind = m.get("Type") or message_type
    if message_type and m.get("Type") and message_type != m.get("Type"):
        _keep(co["id"], m, False, "rejected", "message type header and body disagree")
        return 400, "type mismatch"
    try:
        verify(m)
    except Rejected as e:
        _keep(co["id"], m, False, "rejected", str(e))
        log.warning("bkash webhook %s: rejected %s: %s", slug, kind, e)
        return 403, "rejected"
    except Exception as e:  # the certificate could not be fetched: SNS will retry
        log.exception("bkash webhook %s: verify failed", slug)
        _keep(co["id"], m, False, "failed", f"verify: {str(e)[:300]}")
        return 503, "try again"
    s = settings(co["id"]) or {}
    topic = m.get("TopicArn")
    if kind == "SubscriptionConfirmation":
        return _subscription(co, s, m)
    if kind == "UnsubscribeConfirmation":
        _keep(co["id"], m, True, "ignored", "unsubscribe confirmation (nothing to do)")
        return 200, "ok"
    if kind != "Notification":
        _keep(co["id"], m, True, "ignored", f"type {kind}")
        return 200, "ok"
    if not s.get("topic_arn") or topic != s["topic_arn"]:
        _keep(co["id"], m, True, "rejected", "topic is not this company's confirmed bKash topic")
        return 403, "rejected"
    db.execute("UPDATE bkash_webhooks SET last_message_at = now(), updated_at = now() WHERE company_id = %s", (co["id"],))
    if not s.get("enabled"):
        _keep(co["id"], m, True, "ignored", "switched off in Desk")
        return 200, "ok"
    try:
        payment_id, result, error = record(co["id"], s, m)
    except Exception as e:
        log.exception("bkash webhook %s: record failed", slug)
        _keep(co["id"], m, True, "failed", str(e)[:500])
        return 500, "error"  # SNS retries; the TrxID keeps a retry from counting twice
    _keep(co["id"], m, True, result, error, payment_id)
    if result == "recorded" and payment_id:
        threading.Thread(target=after_record, args=(co["id"], payment_id), daemon=True).start()
    return 200, "ok"


def _subscription(co: dict, s: dict, m: dict) -> tuple[int, str]:
    url, topic = m.get("SubscribeURL"), m.get("TopicArn")
    if not sns_url_ok(url):
        _keep(co["id"], m, True, "rejected", "SubscribeURL is not Amazon SNS")
        return 403, "rejected"
    if s.get("enabled") and (not s.get("topic_arn") or s["topic_arn"] == topic):
        try:
            confirm_url(url)
        except Exception as e:
            log.exception("bkash webhook %s: confirm failed", co["slug"])
            _keep(co["id"], m, True, "failed", f"confirm: {str(e)[:300]}")
            _set_pending(co["id"], topic, url)
            return 200, "ok"
        _set_confirmed(co["id"], topic)
        _keep(co["id"], m, True, "confirmed")
        _alert_admins(co["id"], "bKash webhook চালু হয়েছে", f"Topic: {topic}", "bkash-confirmed")
        return 200, "ok"
    _set_pending(co["id"], topic, url)
    _keep(co["id"], m, True, "pending", "waiting for the owner" if s.get("enabled") else "switched off in Desk")
    _alert_admins(co["id"], "bKash webhook: নিশ্চিত করা বাকি",
                  "bKash নতুন subscription পাঠিয়েছে। bKash Webhook সেটিং পেজ থেকে নিশ্চিত করুন।", "bkash-pending")
    return 200, "ok"


def confirm_url(url: str) -> None:
    if not sns_url_ok(url):
        raise Rejected("SubscribeURL is not Amazon SNS")
    r = httpx.get(url, timeout=15, follow_redirects=False)
    r.raise_for_status()
    if "ConfirmSubscriptionResult" not in r.text:
        raise RuntimeError("SNS did not confirm the subscription")


def _set_pending(company_id: int, topic: str | None, url: str) -> None:
    db.execute("""INSERT INTO bkash_webhooks (company_id, pending_topic_arn, pending_subscribe_url, pending_at, created_at, updated_at)
                  VALUES (%s, %s, %s, now(), now(), now())
                  ON CONFLICT (company_id) DO UPDATE SET pending_topic_arn = EXCLUDED.pending_topic_arn,
                      pending_subscribe_url = EXCLUDED.pending_subscribe_url, pending_at = now(), updated_at = now()""",
               (company_id, topic, url))


def _set_confirmed(company_id: int, topic: str | None) -> None:
    db.execute("""UPDATE bkash_webhooks SET topic_arn = %s, confirmed_at = now(), pending_topic_arn = NULL,
                      pending_subscribe_url = NULL, pending_at = NULL, updated_at = now() WHERE company_id = %s""",
               (topic, company_id))


def confirm_pending(company_id: int) -> dict:
    """The owner's "নিশ্চিত করুন" on the settings page: confirm the waiting subscription; its topic becomes the one."""
    s = settings(company_id) or {}
    if not s.get("pending_subscribe_url"):
        raise Rejected("নিশ্চিত করার মতো কোনো subscription নেই")
    confirm_url(s["pending_subscribe_url"])
    _set_confirmed(company_id, s["pending_topic_arn"])
    return {"topic_arn": s["pending_topic_arn"]}


# --- the payment ---------------------------------------------------------------------------------
def _txt(v) -> str | None:
    s = str(v).strip() if v is not None else ""
    return s or None


def _num(v) -> float:
    try:
        return float(str(v if v is not None else "").replace(",", "").strip() or 0)
    except ValueError:
        return 0.0


def payload(m: dict) -> dict:
    """The payment inside the notification: Message is JSON text (bKash's own example shows it as an object)."""
    msg = m.get("Message")
    if isinstance(msg, str):
        try:
            msg = json.loads(msg)
        except ValueError:
            raise Rejected("Message is not JSON")
    if not isinstance(msg, dict):
        raise Rejected("Message is not a payment")
    return msg


def paid_at(v) -> datetime:
    """'20180419122246' (yyyyMMddHHmmss, Bangladesh time) -> naive UTC; now when missing."""
    try:
        local = datetime.strptime(str(v or "").strip(), "%Y%m%d%H%M%S").replace(tzinfo=DHAKA)
        return local.astimezone(timezone.utc).replace(tzinfo=None)
    except ValueError:
        return datetime.now(timezone.utc).replace(tzinfo=None)


def same_wallet(a: str | None, b: str | None) -> bool:
    da, db_ = re.sub(r"\D", "", a or ""), re.sub(r"\D", "", b or "")
    return bool(da) and bool(db_) and da[-10:] == db_[-10:]


def mobile_of(msisdn: str | None) -> str | None:
    """'8801700000001' / '01700000001' -> '8801700000001' (billing_customers.mobile_normalized)."""
    d = re.sub(r"\D", "", msisdn or "")
    if d.startswith("880") and len(d) == 13:
        return d
    if d.startswith("01") and len(d) == 11:
        return "88" + d
    return None


def find_customer(company_id: int, reference: str | None, payer: str | None) -> tuple[dict | None, str | None]:
    """(customer, how): the customer ID in the reference first (exactly, a short one padded to 4 digits), then the paying
    number when exactly one current customer has it."""
    from engine.online_payment_sync import code_of
    code = code_of(reference)
    if code:
        c = db.one("""SELECT header_id, customer_id FROM billing_customers WHERE company_id = %s AND customer_id = %s
                      ORDER BY (status = 'Left'), header_id DESC LIMIT 1""", (company_id, code))
        if c:
            return c, "code"
    mobile = mobile_of(payer)
    if mobile:
        rows = db.all_rows("""SELECT header_id, customer_id FROM billing_customers
                              WHERE company_id = %s AND mobile_normalized = %s AND coalesce(status, '') <> 'Left'
                              LIMIT 2""", (company_id, mobile))
        if len(rows) == 1:
            return rows[0], "mobile"
    return None, None


def record(company_id: int, s: dict, m: dict) -> tuple[int | None, str, str | None]:
    """(payment id, result, note) for one verified notification of the company's topic."""
    p = payload(m)
    trx = _txt(p.get("trxID") or p.get("trxId"))
    if not trx:
        return None, "rejected", "no trxID"
    status = _txt(p.get("transactionStatus"))
    if status and status.lower() != "completed":
        return None, "ignored", f"transactionStatus {status}"
    merchant = _txt(p.get("creditShortCode"))
    if s.get("merchant_number") and not same_wallet(s["merchant_number"], merchant):
        return None, "rejected", f"payment to another wallet ({merchant})"
    dup = db.one("SELECT id FROM billing_online_payments WHERE company_id = %s AND source = 'desk_webhook' AND trx_id = %s",
                 (company_id, trx))
    if dup:
        return dup["id"], "duplicate", None
    reference, payer = _txt(p.get("transactionReference")), _txt(p.get("debitMSISDN"))
    from engine.online_payment_sync import code_of
    cust, how = find_customer(company_id, reference, payer)
    seq = time.time_ns() // 1000  # billing_row_id only has to be unique per company and source here
    row = db.execute(
        """INSERT INTO billing_online_payments (company_id, source, billing_row_id, gateway, type_for, trx_id, amount,
               currency, payer, merchant, reference, customer_code, match_code, customer_header_id, customer_id, matched_by,
               status_id, status, trx_status, trx_type, paid_at, billing_created_at, note, settle_state, synced_at)
           VALUES (%s, 'desk_webhook', %s, 'bKash', 'BKASH_WEBHOOK', %s, %s, %s, %s, %s, %s, NULL, %s, %s, %s, %s,
                   NULL, %s, %s, %s, %s, now(), %s, %s, now())
           ON CONFLICT (company_id, trx_id) WHERE source = 'desk_webhook' DO NOTHING RETURNING id""",
        (company_id, seq, trx, _num(p.get("amount")), _txt(p.get("currency")) or "BDT", payer, merchant, reference,
         code_of(reference), cust and cust["header_id"], cust and cust["customer_id"], how,
         "Desk-এ এসেছে" if cust else "কাস্টমার চেনা যায়নি", status, _txt(p.get("transactionType")),
         paid_at(p.get("dateTime")), _txt(p.get("merchantInvoiceNumber")),
         ("pending" if cust else None) if s.get("settle_in_billing") else "off"))
    if not row:  # the same TrxID came in at the same moment
        dup = db.one("SELECT id FROM billing_online_payments WHERE company_id = %s AND source = 'desk_webhook' AND trx_id = %s",
                     (company_id, trx))
        return dup and dup["id"], "duplicate", None
    return row["id"], "recorded", None if cust else "unmatched"


def after_record(company_id: int, payment_id: int) -> None:
    p = db.one("SELECT * FROM billing_online_payments WHERE id = %s", (payment_id,))
    if not p:
        return
    if not p["customer_header_id"]:
        amount = f"{float(p['amount']):g}"
        _alert_admins(company_id, f"bKash পেমেন্ট চেনা যায়নি: {amount} ৳",
                      f"রেফারেন্স: {p['reference'] or '—'} · অনলাইন পেমেন্ট পেজে কাস্টমার বেছে দিন",
                      f"bkash-{p['trx_id']}", "online-payments")
        db.execute("UPDATE billing_online_payments SET alerted_at = now() WHERE id = %s", (payment_id,))
        return
    if p["settle_state"] == "pending":
        settle(company_id, payment_id)


# --- into the billing software ---------------------------------------------------------------------------
def _tenant(company_id: int):
    from engine import tenant as tenants
    row = db.one("SELECT phone_number_id FROM wa_accounts WHERE company_id = %s ORDER BY id LIMIT 1", (company_id,))
    t = tenants.by_phone_number_id(row["phone_number_id"]) if row else None
    if not t or not t.billing:
        raise RuntimeError("এই কোম্পানির বিলিং সংযোগ নেই")
    return t


def settle(company_id: int, payment_id: int, by: str = "bKash webhook", user_id: int | None = None) -> dict:
    """Put one matched Desk webhook payment into billing as an approved bKash bill (once; a failed one can be retried
    from the online payments page, then with that member's own billing login). Returns {"state", "error"}."""
    with _settle_lock:
        p = db.execute("""UPDATE billing_online_payments SET settle_state = 'working', settle_error = NULL
                          WHERE id = %s AND company_id = %s AND source = 'desk_webhook' AND customer_header_id IS NOT NULL
                            AND coalesce(settle_state, 'pending') IN ('pending', 'failed', 'off')
                          RETURNING *""", (payment_id, company_id))
    if not p:
        return {"state": "skipped", "error": "আগেই জমা হয়েছে বা জমা হচ্ছে"}
    s = settings(company_id) or {}
    state, error, collection_id = "failed", None, None
    try:
        from engine import bills, tenant as tenants
        t = _tenant(company_id)
        user_id = int(user_id or s.get("settle_user_id") or 0)
        if not user_id:
            raise RuntimeError("bKash Webhook সেটিং-এ কার বিলিং লগইন দিয়ে জমা হবে তা বাছাই করা নেই")
        api = tenants.billing_for_user(t, user_id, owner_fallback=True)
        header_id = int(p["customer_header_id"])
        f = bills.form_info(api, header_id)
        method = next((k for k, v in f["methods"].items() if "bkash" in v.lower().replace(" ", "")), None)
        if not method:
            raise RuntimeError("বিলিং-এ bKash পেমেন্ট মাধ্যম পাওয়া যায়নি")
        received_by = f["received_by_default"] or next(iter(f["received_by"]), None)
        amount = float(p["amount"])
        res = bills.collect(company_id, user_id, api, header_id, {
            "paid": amount, "discount": 0, "method_id": method, "received_by_id": received_by, "approve": True,
            "trx_no": p["trx_id"], "remarks": f"bKash webhook {p['trx_id']} ({p['payer'] or ''})".strip(),
            "next_billing_date": False})
        collection_id = res.get("collection_id")
        state = "settled"
        if s.get("enable_line", True):
            threading.Thread(target=_line_on, args=(company_id, user_id, api, header_id, amount, f["payable"]),
                             daemon=True).start()
    except Exception as e:
        log.warning("bkash payment %s: settle failed: %s", payment_id, e)
        error = str(e)[:500]
    db.execute("""UPDATE billing_online_payments SET settle_state = %s, settle_error = %s, bill_collection_id = %s,
                      settled_at = CASE WHEN %s = 'settled' THEN now() END, settled_by = %s,
                      status = CASE WHEN %s = 'settled' THEN 'বিলিং-এ জমা হয়েছে' ELSE status END
                  WHERE id = %s""", (state, error, collection_id, state, by, state, payment_id))
    if state == "failed":
        _alert_admins(company_id, f"bKash পেমেন্ট বিলিং-এ জমা হয়নি: {float(p['amount']):g} ৳",
                      f"ID {p['customer_id']}: {error}", f"bkash-settle-{payment_id}", "online-payments")
    return {"state": state, "error": error}


def _line_on(company_id: int, user_id: int, api, header_id: int, paid: float, payable: float) -> None:
    """After the approval: a line still off in billing goes on when this payment covers enough of the due."""
    from engine import bills, expire_dates
    th = bills._approving.get((company_id, header_id))
    if th:
        th.join(timeout=120)
    time.sleep(5)
    try:
        c = db.one("SELECT customer_id, name, username, due FROM billing_customers WHERE company_id = %s AND header_id = %s",
                   (company_id, header_id)) or {}
        from engine.ispdigital import find_customer_by_text
        now = find_customer_by_text(api, c.get("customer_id") or "", allow_bare_id=True)
        if not now or int(now.get("CustomerHeaderId") or 0) != header_id or not now.get("Disabled"):
            return
        pct = expire_dates.settings(company_id)["pct"]
        if payable > 0 and paid * 100 < payable * pct:
            log.info("bkash payment for %s: %s of %s is below %s%%, line stays off", header_id, paid, payable, pct)
            return
        result, error = "enabled", None
        try:
            api.enable_customer(header_id)
            db.execute("UPDATE billing_customers SET disabled = false, updated_at = now() WHERE company_id = %s AND header_id = %s",
                       (company_id, header_id))
        except Exception as e:
            result, error = "failed", str(e)[:500]
        db.execute(
            """INSERT INTO line_enables (company_id, technician_name, customer_id, customer_header_id, customer_name, username,
                   due, request, action, result, error, created_at)
               VALUES (%s, 'bKash webhook', %s, %s, %s, %s, %s, 'bKash পেমেন্টের পর', 'enable', %s, %s, now())""",
            (company_id, c.get("customer_id"), header_id, c.get("name"), c.get("username"),
             None if c.get("due") is None else str(c["due"]), result, error))
    except Exception:
        log.exception("line on after bkash payment for %s", header_id)


def _alert_admins(company_id: int, title: str, body: str, tag: str, page: str = "bkash-webhook-settings") -> None:
    try:
        from engine import webpush
        users = [r["user_id"] for r in db.all_rows(
            "SELECT user_id FROM company_user WHERE company_id = %s AND role IN ('owner', 'admin')", (company_id,))]
        slug = (db.one("SELECT slug FROM companies WHERE id = %s", (company_id,)) or {}).get("slug")
        if users:
            webpush.send_users(company_id, users, {"title": title, "body": body, "tag": tag,
                                                   "url": f"/app/{slug}/{page}" if slug else ""})
    except Exception:
        log.exception("bkash alert for company %s", company_id)
