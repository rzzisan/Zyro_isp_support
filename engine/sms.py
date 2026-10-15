"""Desk SMS: sends the queue (sms_messages) through each company's SMS gateway (Khudebarta), reads the balance and
delivery reports. The panel only writes the queue (App\\Support\\Sms::queue) and wakes us (/internal/{c}/sms/kick).

Gateway call, the same way BSOL sends: form POST to the send URL with apikey, secretkey, callerID, toUser
(8801XXXXXXXXX), messageContent; one number per request. Answer {"Status":"0","Text":"ACCEPTD","Message_ID":"..."}
= accepted; other Status = refused. Non-JSON answers: 2xx without error/failed/invalid/unauthorized = accepted (BSOL).

Safety: nothing goes out while the company's "SMS পাঠানো চালু" (sms_settings.enabled) is off. A message caught in
'sending' when a run dies becomes 'unknown' and is never sent again (no double SMS). 10 failures in a row with the
same kind of error (wrong keys, no balance) switch sending off and keep the rest in the queue.
Keys are never logged; logs carry message ids only.

Balance: the gateway's web portal API (https://portal.khudebarta.com:9494/api): POST /auth/login {username, password}
-> idToken (JWT, ~1.5 h), refreshToken, userID (= providerID); GET /util/get-current-balance?providerID= with
"Authorization: Bearer idToken"; on expiry GET /auth/refresh-access-token?oldToken&refreshToken -> {left, right}.
Tokens are kept encrypted in sms_settings; a failed login pauses new logins for 10 minutes.

Run every minute by zyro-sms-send.timer (send + balance every 5 min + delivery reports):
  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.sms [--company ID] [--status-only]
"""
import argparse
import base64
import fcntl
import json
import logging
import os
import re
import threading
import time
from concurrent.futures import ThreadPoolExecutor

import httpx

from engine import db

log = logging.getLogger("zyro-sms")
LOCK_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "data")
BATCH = 20               # claimed at a time
WORKERS = 4              # parallel requests to the gateway
MAX_FAIL_STREAK = 10
BALANCE_EVERY = 300      # seconds
ERROR_WORDS = re.compile(r"error|failed|invalid|unauthori[sz]ed|rejectd|rejected", re.I)
DELIVERED = {"DELIVRD", "DELIVERED"}
UNDELIVERED = {"UNDELIV", "UNDELIVERED", "REJECTD", "REJECTED", "EXPIRED", "FAILED", "DELETED"}


class GatewayError(RuntimeError):
    pass


def settings(company_id: int) -> dict | None:
    s = db.one("SELECT * FROM sms_settings WHERE company_id = %s", (company_id,))
    if not s:
        return None
    s = dict(s)
    s["api_key"] = db.decrypt(s["api_key"]) if s["api_key"] else None
    s["secret_key"] = db.decrypt(s["secret_key"]) if s["secret_key"] else None
    for k in ("portal_password", "portal_token", "portal_refresh_token"):
        s[k] = db.decrypt(s[k]) if s.get(k) else None
    return s


def configured(s: dict | None) -> bool:
    return bool(s and s.get("endpoint_url") and s.get("api_key") and s.get("secret_key") and s.get("caller_id"))


def _client(s: dict) -> httpx.Client:
    return httpx.Client(timeout=20, verify=bool(s.get("verify_tls", True)), follow_redirects=True)


def _short(text: str, keys: tuple = ()) -> str:
    """A gateway answer safe to store/show: keys blanked, at most 300 characters."""
    for k in keys:
        if k:
            text = text.replace(k, "***")
    return re.sub(r"\s+", " ", text).strip()[:300]


def _json(text: str):
    try:
        return json.loads(text)
    except ValueError:
        return None


def parse_send(status_code: int, body: str) -> dict:
    """Gateway answer -> {ok, status, text, message_id}."""
    j = _json(body)
    if isinstance(j, list) and j:
        j = j[0]
    if isinstance(j, dict):
        st = str(j.get("Status", j.get("status", "")))
        txt = str(j.get("Text") or j.get("StatusDescription") or j.get("message") or "")
        mid = str(j.get("Message_ID") or j.get("message_id") or j.get("MessageId") or "").split(",")[0] or None
        ok = 200 <= status_code < 300 and st == "0"
        return {"ok": ok, "status": st or None, "text": txt or None, "message_id": mid}
    ok = 200 <= status_code < 300 and not ERROR_WORDS.search(body or "")
    return {"ok": ok, "status": str(status_code), "text": (body or "")[:200] or None, "message_id": None}


def send_one(s: dict, mobile: str, text: str, client: httpx.Client | None = None) -> dict:
    data = {"apikey": s["api_key"], "secretkey": s["secret_key"], "callerID": s["caller_id"], "toUser": mobile,
            "messageContent": text}
    c = client or _client(s)
    try:
        r = c.post(s["endpoint_url"], data=data)
    except httpx.HTTPError as e:
        return {"ok": False, "status": None, "text": None, "message_id": None,
                "error": _short(f"{type(e).__name__}: {e}", (s["api_key"], s["secret_key"]))}
    finally:
        if client is None:
            c.close()
    res = parse_send(r.status_code, r.text)
    if not res["ok"]:
        res["error"] = _short(f"HTTP {r.status_code}: {r.text}", (s["api_key"], s["secret_key"]))
    return res


# --- balance -------------------------------------------------------------------------------------------------------
LOGIN_PAUSE = 600        # seconds without a new portal login after a failed one (don't lock the account)


def portal_base(s: dict) -> str:
    """https://portal.khudebarta.com:9494/api from .../api/auth/login."""
    return re.sub(r"/auth/[^/]*$", "", s["portal_login_url"].rstrip("/"))


def portal_balance_url(s: dict, provider_id: str | None) -> str | None:
    url = s.get("balance_url")
    if not url or ("{provider_id}" in url and not provider_id):
        return None
    return url.replace("{provider_id}", str(provider_id or ""))


def _number(v) -> float | None:
    if isinstance(v, bool):
        return None
    if isinstance(v, (int, float)):
        return float(v)
    if isinstance(v, str) and re.fullmatch(r"\s*-?[\d,]+(?:\.\d+)?\s*(?:BDT|TK|৳)?\s*", v, re.I):
        return float(re.sub(r"[^\d.\-]", "", v))
    return None


def _find_balance(j):
    """balance / credit / amount field anywhere in a JSON answer."""
    if isinstance(j, dict):
        for k, v in j.items():
            if re.search(r"balance|credit|amount", str(k), re.I) and _number(v) is not None:
                return _number(v)
        for v in j.values():
            found = _find_balance(v)
            if found is not None:
                return found
    elif isinstance(j, list):
        for v in j:
            found = _find_balance(v)
            if found is not None:
                return found
    return None


def parse_balance(body: str) -> float | None:
    j = _json(body)
    if j is not None:
        if _number(j) is not None:
            return _number(j)
        found = _find_balance(j)
        if found is not None or isinstance(j, (dict, list)):
            return found
    if body and not ERROR_WORDS.search(body):
        m = re.search(r"-?\d[\d,]*(?:\.\d+)?", body)
        if m:
            return float(m.group(0).replace(",", ""))
    return None


def _jwt_exp(token: str) -> float:
    """Unix time a minute before the token runs out (30 minutes when it can't be read)."""
    try:
        part = token.split(".")[1]
        exp = json.loads(base64.urlsafe_b64decode(part + "=" * (-len(part) % 4))).get("exp")
        return float(exp) - 60 if exp else time.time() + 1800
    except (ValueError, IndexError, AttributeError):
        return time.time() + 1800


def _save_tokens(company_id: int, token: str | None, refresh: str | None, provider_id: str | None = None) -> None:
    db.execute(
        """UPDATE sms_settings SET portal_token = %s, portal_refresh_token = %s,
               portal_token_expires_at = CASE WHEN %s::text IS NULL THEN NULL ELSE to_timestamp(%s) AT TIME ZONE 'UTC' END,
               portal_login_failed_at = CASE WHEN %s::text IS NULL THEN now() ELSE NULL END,
               provider_id = coalesce(provider_id, %s), updated_at = now() WHERE company_id = %s""",
        (db.encrypt(token) if token else None, db.encrypt(refresh) if refresh else None, token,
         _jwt_exp(token) if token else 0, token, provider_id, company_id))


def portal_login(company_id: int, s: dict, c: httpx.Client) -> tuple[str, str | None]:
    """POST {base}/auth/login {"username", "password"} -> idToken, refreshToken, userID (= providerID).
    A failed login pauses new logins for 10 minutes; tokens are kept encrypted in sms_settings, never logged."""
    if not (s.get("portal_login_url") and s.get("portal_username") and s.get("portal_password")):
        raise GatewayError("পোর্টাল লগইন (ইউজারনেম/পাসওয়ার্ড) দেওয়া নেই")
    paused = db.one("""SELECT 1 FROM sms_settings WHERE company_id = %s
                       AND portal_login_failed_at > now() - make_interval(secs => %s)""", (company_id, LOGIN_PAUSE))
    if paused:
        raise GatewayError("পোর্টাল লগইন একটু আগে ব্যর্থ হয়েছে; ১০ মিনিট পরে আবার চেষ্টা হবে (বা লগইন তথ্য বদলে সেভ করুন)")
    try:
        r = c.post(s["portal_login_url"], json={"username": s["portal_username"], "password": s["portal_password"]})
    except httpx.HTTPError as e:
        raise GatewayError(f"পোর্টালে সংযোগ হয়নি ({type(e).__name__})")
    j = _json(r.text) if 200 <= r.status_code < 300 else None
    j = j if isinstance(j, dict) else {}
    if j.get("otpGenerationType") or j.get("passwordExpiredAt"):
        _save_tokens(company_id, None, None)
        raise GatewayError("পোর্টাল লগইনে OTP/পাসওয়ার্ড আপডেট লাগছে; portal.khudebarta.com-এ একবার হাতে লগইন করে ঠিক করুন")
    token = j.get("idToken")
    if not token:
        _save_tokens(company_id, None, None)
        raise GatewayError(f"পোর্টালে লগইন হয়নি (HTTP {r.status_code}); ইউজারনেম/পাসওয়ার্ড দেখুন")
    provider = str(j.get("userID") or "") or None
    _save_tokens(company_id, token, j.get("refreshToken"), provider)
    return token, provider


def portal_refresh(company_id: int, s: dict, c: httpx.Client) -> str | None:
    """GET {base}/auth/refresh-access-token?oldToken&refreshToken -> {left: idToken, right: refreshToken}."""
    if not (s.get("portal_token") and s.get("portal_refresh_token")):
        return None
    try:
        r = c.get(portal_base(s) + "/auth/refresh-access-token",
                  params={"oldToken": s["portal_token"], "refreshToken": s["portal_refresh_token"]})
    except httpx.HTTPError:
        return None
    j = _json(r.text) if 200 <= r.status_code < 300 else None
    if isinstance(j, dict) and j.get("left"):
        _save_tokens(company_id, j["left"], j.get("right") or s["portal_refresh_token"])
        return j["left"]
    return None


def billing_balance(company_id: int) -> float | None:
    """Fallback: the billing software's SMS Gateway page shows the same Khudebarta account's balance
    (`var smsBalance = 1496.25;`). Only that number is read; the page also holds the gateway login and is never kept.
    The portal's own login refuses every non-browser client ("Forbidden from Charset Filter", 2026-10-15)."""
    try:
        from engine import tenant as tenants
        from engine.ticket_sync import companies
        ts = companies(company_id)
        if not ts:
            return None
        page = tenants.billing(ts[0]).page("/companysettings/smsgateway")
    except Exception as e:
        log.info("company %s: billing sms balance not read (%s)", company_id, type(e).__name__)
        return None
    m = re.search(r"var\s+smsBalance\s*=\s*(-?[\d.]+)\s*;", page)
    return float(m.group(1)) if m else None


def check_balance(company_id: int) -> dict:
    """Balance from the Khudebarta web portal: GET {balance_url} with Bearer idToken (portal login, refreshed or
    logged in again on 401). The result is saved in sms_settings (balance, checked time, error)."""
    s = settings(company_id)
    if not configured(s):
        raise GatewayError("SMS গেটওয়ে সেট করা হয়নি")
    secrets = (s["api_key"], s["secret_key"], s.get("portal_password"), s.get("portal_token"), s.get("portal_refresh_token"))
    value, last = None, "no answer"
    with _client(s) as c:
        try:
            token, provider = s.get("portal_token"), s.get("provider_id")
            fresh = bool(token) and s.get("portal_token_expires_at") is not None and db.one(
                "SELECT 1 FROM sms_settings WHERE company_id = %s AND portal_token_expires_at > now()", (company_id,))
            if not fresh:
                token = portal_refresh(company_id, s, c) if token else None
                if not token:
                    token, login_provider = portal_login(company_id, s, c)
                    provider = provider or login_provider
            url = portal_balance_url(s, provider)
            if not url:
                raise GatewayError("ব্যালান্স URL বা Provider ID নেই")
            for attempt in (1, 2, 3):
                r = c.get(url, headers={"Authorization": f"Bearer {token}"})
                if r.status_code != 401 or attempt == 3:
                    break
                # expired: renew once, then a fresh login once
                s = settings(company_id)
                token = (portal_refresh(company_id, s, c) if attempt == 1 else None) or portal_login(company_id, s, c)[0]
            if 200 <= r.status_code < 300:
                value = parse_balance(r.text)
            last = f"পোর্টাল HTTP {r.status_code}: {r.text}"
        except GatewayError as e:
            last = str(e)
        except httpx.HTTPError as e:
            last = f"পোর্টালে সংযোগ হয়নি ({type(e).__name__})"
    if value is None:
        value = billing_balance(company_id)
    if value is None:
        err = _short(last, secrets)
        db.execute("""UPDATE sms_settings SET balance_checked_at = now(), last_check_ok = false, last_error = %s,
                      updated_at = now() WHERE company_id = %s""", (err, company_id))
        raise GatewayError(f"ব্যালান্স পাওয়া যায়নি: {err}")
    db.execute("""UPDATE sms_settings SET balance = %s, balance_checked_at = now(), last_check_ok = true, last_error = NULL,
                  updated_at = now() WHERE company_id = %s""", (value, company_id))
    return {"balance": value}


# --- sending -------------------------------------------------------------------------------------------------------
def _lock(company_id: int):
    os.makedirs(LOCK_DIR, exist_ok=True)
    f = open(os.path.join(LOCK_DIR, f"sms-send-{company_id}.lock"), "w")
    try:
        fcntl.flock(f, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        f.close()
        return None
    return f


def _claim(company_id: int) -> list[dict]:
    return db.all_rows(
        """UPDATE sms_messages SET status = 'sending', updated_at = now()
           WHERE id IN (SELECT m.id FROM sms_messages m
                        LEFT JOIN sms_campaigns c ON c.id = m.campaign_id
                        WHERE m.company_id = %s AND m.status = 'queued' AND coalesce(c.status, 'queued') <> 'cancelled'
                        ORDER BY m.id LIMIT %s FOR UPDATE OF m SKIP LOCKED)
           RETURNING id, campaign_id, mobile, text""", (company_id, BATCH))


def _save(msg_id: int, res: dict) -> None:
    db.execute(
        """UPDATE sms_messages SET status = %s, gateway_status = %s, gateway_text = %s, message_id = %s, error = %s,
               sent_at = CASE WHEN %s THEN now() ELSE sent_at END, updated_at = now() WHERE id = %s""",
        ("sent" if res["ok"] else "failed", res.get("status"), (res.get("text") or "")[:250] or None,
         res.get("message_id"), None if res["ok"] else res.get("error"), res["ok"], msg_id))


def refresh_campaigns(company_id: int) -> None:
    db.execute(
        """UPDATE sms_campaigns c SET sent = x.sent, failed = x.failed,
               status = CASE WHEN c.status = 'cancelled' THEN 'cancelled'
                             WHEN x.open = 0 THEN 'done'
                             WHEN x.sent + x.failed > 0 THEN 'sending' ELSE c.status END,
               started_at = CASE WHEN x.sent + x.failed > 0 THEN coalesce(c.started_at, now()) ELSE c.started_at END,
               finished_at = CASE WHEN x.open = 0 THEN coalesce(c.finished_at, now()) ELSE NULL END,
               updated_at = now()
           FROM (SELECT campaign_id,
                        count(*) FILTER (WHERE status IN ('sent', 'delivered')) AS sent,
                        count(*) FILTER (WHERE status IN ('failed', 'unknown')) AS failed,
                        count(*) FILTER (WHERE status IN ('queued', 'sending')) AS open
                 FROM sms_messages WHERE company_id = %s AND campaign_id IS NOT NULL GROUP BY campaign_id) x
           WHERE c.id = x.campaign_id AND c.company_id = %s AND c.status IN ('queued', 'sending')""",
        (company_id, company_id))


def _error_kind(res: dict) -> str:
    return str(res.get("status")) + ":" + re.sub(r"\d+", "#", (res.get("error") or "")[:60])


def send_queue(company_id: int) -> dict:
    """Sends everything queued for one company (one runner per company at a time)."""
    lock = _lock(company_id)
    if not lock:
        return {"running": True}
    stats = {"sent": 0, "failed": 0, "stopped": None}
    try:
        # cut off by a restart: never resend, the gateway may have taken it
        db.execute("""UPDATE sms_messages SET status = 'unknown', error = 'পাঠানোর সময় থেমে গিয়েছিল; আবার পাঠানো হয়নি',
                      updated_at = now() WHERE company_id = %s AND status = 'sending' AND updated_at < now() - interval '10 minutes'""",
                   (company_id,))
        streak, kind = 0, None
        while True:
            s = settings(company_id)
            if not (configured(s) and s["enabled"]):
                stats["stopped"] = "disabled"
                break
            batch = _claim(company_id)
            if not batch:
                break
            with _client(s) as client, ThreadPoolExecutor(WORKERS) as pool:
                results = list(pool.map(lambda m: (m, send_one(s, m["mobile"], m["text"], client)), batch))
            for m, res in results:
                _save(m["id"], res)
                if res["ok"]:
                    stats["sent"] += 1
                    streak, kind = 0, None
                else:
                    stats["failed"] += 1
                    k = _error_kind(res)
                    streak = streak + 1 if k == kind else 1
                    kind = k
                    log.warning("sms %s failed: %s", m["id"], res.get("status"))
            refresh_campaigns(company_id)
            if streak >= MAX_FAIL_STREAK:
                err = f"পরপর {streak}টি SMS ব্যর্থ, পাঠানো বন্ধ করা হয়েছে: {(results[-1][1].get('error') or '')[:200]}"
                db.execute("UPDATE sms_settings SET enabled = false, last_error = %s, updated_at = now() WHERE company_id = %s",
                           (err, company_id))
                stats["stopped"] = "failures"
                log.error("company %s: sms sending switched off after %s failures in a row", company_id, streak)
                break
        refresh_campaigns(company_id)
    finally:
        fcntl.flock(lock, fcntl.LOCK_UN)
        lock.close()
    if stats["sent"] or stats["failed"]:
        log.info("company %s: sms %s", company_id, stats)
    return stats


def send_now(company_id: int, message_id: int) -> dict:
    """The settings page's test SMS: this one queued message goes now, even while sending is switched off."""
    s = settings(company_id)
    if not configured(s):
        raise GatewayError("SMS গেটওয়ে সেট করা হয়নি")
    m = db.one("""UPDATE sms_messages SET status = 'sending', updated_at = now()
                  WHERE id = %s AND company_id = %s AND status = 'queued' RETURNING id, mobile, text""", (message_id, company_id))
    if not m:
        raise GatewayError("SMS-টা আর অপেক্ষায় নেই")
    res = send_one(s, m["mobile"], m["text"])
    _save(m["id"], res)
    refresh_campaigns(company_id)
    return {"ok": res["ok"], "status": res.get("status"), "text": res.get("text"), "error": res.get("error")}


_running: set[int] = set()
_running_lock = threading.Lock()


def kick(company_id: int) -> bool:
    """Start sending in a background thread of the engine (the panel calls this right after queueing)."""
    with _running_lock:
        if company_id in _running:
            return False
        _running.add(company_id)

    def run():
        try:
            send_queue(company_id)
        except Exception:
            log.exception("sms send failed for company %s", company_id)
        finally:
            with _running_lock:
                _running.discard(company_id)

    threading.Thread(target=run, daemon=True, name=f"sms-{company_id}").start()
    return True


# --- delivery reports ----------------------------------------------------------------------------------------------
def check_status(company_id: int, limit: int = 200) -> dict:
    """Delivery state of SMS the gateway took in the last 2 days (only when a delivery report URL is set)."""
    s = settings(company_id)
    if not configured(s) or not s.get("status_url"):
        return {"checked": 0}
    rows = db.all_rows(
        """SELECT id, message_id FROM sms_messages WHERE company_id = %s AND status = 'sent' AND message_id IS NOT NULL
               AND sent_at > now() - interval '2 days' AND sent_at < now() - interval '1 minute'
               AND (status_checked_at IS NULL OR status_checked_at < now() - interval '10 minutes')
           ORDER BY sent_at LIMIT %s""", (company_id, limit))
    done = 0
    with _client(s) as c:
        for r in rows:
            try:
                resp = c.get(s["status_url"], params={"apikey": s["api_key"], "secretkey": s["secret_key"],
                                                      "messageid": r["message_id"]})
                j = _json(resp.text)
                j = j[0] if isinstance(j, list) and j else j
                text = str((j or {}).get("Text") or "").upper() if isinstance(j, dict) else ""
            except httpx.HTTPError:
                continue
            state = "delivered" if text in DELIVERED else "failed" if text in UNDELIVERED else None
            db.execute(
                """UPDATE sms_messages SET status = coalesce(%s, status), gateway_text = coalesce(nullif(%s, ''), gateway_text),
                       delivered_at = CASE WHEN %s = 'delivered' THEN now() ELSE delivered_at END,
                       error = CASE WHEN %s = 'failed' THEN 'গেটওয়ে: পৌঁছায়নি (' || %s || ')' ELSE error END,
                       status_checked_at = now(), updated_at = now() WHERE id = %s""",
                (state, text, state, state, text, r["id"]))
            done += 1
    if done:
        refresh_campaigns(company_id)
    return {"checked": done}


# --- timer ---------------------------------------------------------------------------------------------------------
def companies(company_id: int | None = None) -> list[int]:
    rows = db.all_rows("SELECT company_id FROM sms_settings" + (" WHERE company_id = %s" if company_id else ""),
                       (company_id,) if company_id else ())
    return [r["company_id"] for r in rows]


def run(company_id: int | None = None, status_only: bool = False) -> None:
    for cid in companies(company_id):
        try:
            s = settings(cid)
            if not configured(s):
                continue
            if not status_only:
                send_queue(cid)
                due = db.one("""SELECT 1 FROM sms_settings WHERE company_id = %s AND (balance_checked_at IS NULL
                                OR balance_checked_at < now() - make_interval(secs => %s))""", (cid, BALANCE_EVERY))
                if due:
                    try:
                        check_balance(cid)
                    except GatewayError as e:
                        log.info("company %s: %s", cid, e)
            check_status(cid)
        except Exception:
            log.exception("sms run failed for company %s", cid)


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    p = argparse.ArgumentParser()
    p.add_argument("--company", type=int)
    p.add_argument("--status-only", action="store_true")
    a = p.parse_args()
    run(a.company, a.status_only)


if __name__ == "__main__":
    main()
