"""Desk SMS: sends the queue (sms_messages) through each company's SMS gateway (Khudebarta), reads the balance and
delivery reports. The panel only writes the queue (App\\Support\\Sms::queue) and wakes us (/internal/{c}/sms/kick).

Khudebarta "SMS HTTP API Published" v5.4.0 (docs/billing-sms-service.md):
- send: POST http://<host>:3775/sendtext, Content-Type application/json, {apikey, secretkey, callerID,
  toUser (880XXXXXXXXXX, several comma separated = bulk), messageContent} -> {"Status":"0","StatusDescription":"",
  "Text":"ACCEPTD","Message_ID":"31771702[,31771704]"}; any other Status = refused (table ACCOUNT_ERRORS / NUMBER_ERRORS).
  The same text to many numbers goes in one request (BULK numbers); a text with the customer's own details goes alone.
- delivery: GET /getstatus?apikey&secretkey&messageid -> Status 0 DELIVRD, 4 SENT, 2 PENDING, 1 REJECTD.
- balance: POST /api/v3/balance {apikey, secretkey} -> {"statusInfo": {"statusCode": "0", "availablebalance": "797.88"}}.

Safety: nothing goes out while the company's "SMS পাঠানো চালু" (sms_settings.enabled) is off. A message caught in
'sending' when a run dies becomes 'unknown' and is never sent again (no double SMS). An account error (wrong keys,
no balance, IP not allowed, sender ID blocked ...) switches sending off at once and keeps the rest in the queue;
10 other failures of the same kind in a row do the same. Keys are never logged; logs carry message ids only.

Run every minute by zyro-sms-send.timer (send + balance every 5 min + delivery reports):
  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.sms [--company ID] [--status-only]
"""
import argparse
import fcntl
import json
import logging
import os
import re
import threading
import time
from concurrent.futures import ThreadPoolExecutor
from urllib.parse import urlsplit, urlunsplit

import httpx

from engine import db

log = logging.getLogger("zyro-sms")
LOCK_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "data")
BATCH = 100              # claimed at a time
BULK = 50                # numbers per request when they get the same text
WORKERS = 4              # parallel requests to the gateway
MAX_FAIL_STREAK = 10
BALANCE_EVERY = 300      # seconds
ERROR_WORDS = re.compile(r"error|failed|invalid|unauthori[sz]ed|rejectd|rejected", re.I)
DELIVERED = {"DELIVRD", "DELIVERED"}
UNDELIVERED = {"UNDELIV", "UNDELIVERED", "REJECTD", "REJECTED", "EXPIRED", "FAILED", "DELETED"}

# submit Status codes (API doc "Code descriptions"); account-level ones stop the whole queue
ACCOUNT_ERRORS = {
    "-1": "Org Client Not Found", "-4": "Insufficient Balance", "-5": "Org Rate Not Found", "-6": "Org Blocked",
    "-7": "Invalid Sender ID", "-36": "Invalid Request Type", "-42": "Authorization Failed",
    "-52": "License Limit Exceeded", "-54": "Sender ID Empty", "-59": "IP Not Allowed", "-60": "Invalid Hash value",
    "-61": "Invalid parameter", "-64": "Sender ID Block", "-66": "Invalid API Key", "-67": "Invalid Secret Key",
}
NUMBER_ERRORS = {
    "-3": "Invalid Dest No.", "-44": "ContactNo Blocked", "-45": "ContactNo Blocked by Admin", "-46": "Dipping Failed",
    "-47": "Content not Whitelisted", "-48": "URL Blocked", "-49": "Content is Blocked", "-55": "Destination ID Empty",
    "-56": "Message Content Empty", "-58": "HLR Request Failed", "-62": "Internal Server Error",
    "-63": "Invalid Transaction ID", "-65": "Bulk Limit Exceeded", "-68": "Duplicate Transaction ID",
}


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
    return httpx.Client(timeout=30, verify=bool(s.get("verify_tls", True)), follow_redirects=True)


def same_host(s: dict, path: str) -> str:
    """http://host:3775/<path> from the send URL (balance and delivery live next to /sendtext)."""
    u = urlsplit(s["endpoint_url"])
    return urlunsplit((u.scheme, u.netloc, path, "", ""))


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
    """Gateway answer -> {ok, status, text, description, message_ids (one per number, in order)}."""
    j = _json(body)
    if isinstance(j, list) and j:
        j = j[0]
    if isinstance(j, dict):
        st = str(j.get("Status", j.get("status", ""))).strip()
        desc = str(j.get("StatusDescription") or "").strip() or ACCOUNT_ERRORS.get(st) or NUMBER_ERRORS.get(st) or ""
        txt = str(j.get("Text") or "").strip()
        ids = [i.strip() for i in str(j.get("Message_ID") or "").split(",") if i.strip() and i.strip() != "-1"]
        ok = 200 <= status_code < 300 and st == "0"
        return {"ok": ok, "status": st or None, "text": txt or None, "description": desc or None, "message_ids": ids}
    ok = 200 <= status_code < 300 and not ERROR_WORDS.search(body or "")
    return {"ok": ok, "status": str(status_code), "text": (body or "")[:200] or None, "description": None,
            "message_ids": []}


def send_request(s: dict, mobiles: list[str], text: str, client: httpx.Client | None = None) -> dict:
    """One /sendtext call (JSON POST) for one or several numbers getting the same text."""
    body = {"apikey": s["api_key"], "secretkey": s["secret_key"], "callerID": s["caller_id"],
            "toUser": ",".join(mobiles), "messageContent": text}
    c = client or _client(s)
    try:
        r = c.post(s["endpoint_url"], json=body)
    except httpx.HTTPError as e:
        return {"ok": False, "status": None, "text": None, "description": None, "message_ids": [],
                "error": _short(f"{type(e).__name__}: {e}", (s["api_key"], s["secret_key"]))}
    finally:
        if client is None:
            c.close()
    res = parse_send(r.status_code, r.text)
    if not res["ok"]:
        res["error"] = _short(f"{res['status']} {res['description'] or res['text'] or ''}".strip()
                              if res["status"] and res["status"] != str(r.status_code) else f"HTTP {r.status_code}: {r.text}",
                              (s["api_key"], s["secret_key"]))
    return res


def send_one(s: dict, mobile: str, text: str, client: httpx.Client | None = None) -> dict:
    res = send_request(s, [mobile], text, client)
    res["message_id"] = res["message_ids"][0] if res["message_ids"] else None
    return res


# --- balance / validate --------------------------------------------------------------------------------------------
def _number(v) -> float | None:
    try:
        return float(str(v).replace(",", "").strip())
    except (TypeError, ValueError):
        return None


def check_balance(company_id: int) -> dict:
    """POST /api/v3/balance {apikey, secretkey} -> statusInfo.availablebalance; saved in sms_settings."""
    s = settings(company_id)
    if not configured(s):
        raise GatewayError("SMS গেটওয়ে সেট করা হয়নি")
    value, err = None, None
    try:
        with _client(s) as c:
            r = c.post(same_host(s, "/api/v3/balance"), json={"apikey": s["api_key"], "secretkey": s["secret_key"],
                                                               "clienttransid": f"desk-{int(time.time())}"})
        info = (_json(r.text) or {}).get("statusInfo") if isinstance(_json(r.text), dict) else None
        if isinstance(info, dict) and str(info.get("statusCode")) == "0":
            value = _number(info.get("availablebalance"))
        if value is None:
            code = str((info or {}).get("statusCode") or "")
            err = f"statusCode {code} {(info or {}).get('errordescription') or ''}".strip() if code else f"HTTP {r.status_code}: {r.text}"
    except httpx.HTTPError as e:
        err = f"{type(e).__name__}: {e}"
    if value is None:
        err = _short(err or "no answer", (s["api_key"], s["secret_key"]))
        db.execute("""UPDATE sms_settings SET balance_checked_at = now(), last_check_ok = false, last_error = %s,
                      updated_at = now() WHERE company_id = %s""", (err, company_id))
        raise GatewayError(f"ব্যালান্স পাওয়া যায়নি: {err}")
    db.execute("""UPDATE sms_settings SET balance = %s, balance_checked_at = now(), last_check_ok = true, last_error = NULL,
                  updated_at = now() WHERE company_id = %s""", (value, company_id))
    return {"balance": value}


def validate_client(company_id: int) -> dict:
    """POST /validateclient {apikey, secretkey} -> {clientName, clientBalance, status SUCCESS}; no SMS is sent."""
    s = settings(company_id)
    if not configured(s):
        raise GatewayError("SMS গেটওয়ে সেট করা হয়নি")
    try:
        with _client(s) as c:
            r = c.post(same_host(s, "/validateclient"), json={"apikey": s["api_key"], "secretkey": s["secret_key"]})
    except httpx.HTTPError as e:
        raise GatewayError(_short(f"গেটওয়েতে সংযোগ হয়নি: {type(e).__name__}: {e}", (s["api_key"], s["secret_key"])))
    j = _json(r.text)
    if not (isinstance(j, dict) and str(j.get("status")).upper() == "SUCCESS"):
        raise GatewayError(_short(f"গেটওয়ে key গ্রহণ করেনি (HTTP {r.status_code}: {r.text})", (s["api_key"], s["secret_key"])))
    balance = _number(j.get("clientBalance"))
    if balance is not None:
        db.execute("""UPDATE sms_settings SET balance = %s, balance_checked_at = now(), last_check_ok = true,
                      last_error = NULL, updated_at = now() WHERE company_id = %s""", (balance, company_id))
    return {"client": j.get("clientName"), "balance": balance}


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


def _save(msg_id: int, res: dict, message_id: str | None = None) -> None:
    text = res.get("text") or ""
    if res.get("description") and res["description"] not in text:
        text = f"{text} {res['description']}".strip()
    db.execute(
        """UPDATE sms_messages SET status = %s, gateway_status = %s, gateway_text = %s, message_id = %s, error = %s,
               sent_at = CASE WHEN %s THEN now() ELSE sent_at END, updated_at = now() WHERE id = %s""",
        ("sent" if res["ok"] else "failed", res.get("status"), text[:250] or None, message_id,
         None if res["ok"] else res.get("error"), res["ok"], msg_id))


def _requeue(ids: list[int]) -> None:
    db.execute("UPDATE sms_messages SET status = 'queued', updated_at = now() WHERE id = ANY(%s) AND status = 'sending'", (ids,))


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


def _jobs(batch: list[dict], bulk: int) -> list[list[dict]]:
    """Messages with the same text go together (up to `bulk` numbers per request); the rest one by one."""
    groups: dict[str, list[dict]] = {}
    for m in batch:
        groups.setdefault(m["text"], []).append(m)
    return [g[i:i + bulk] for g in groups.values() for i in range(0, len(g), bulk)]


def _switch_off(company_id: int, reason: str) -> None:
    db.execute("UPDATE sms_settings SET enabled = false, last_error = %s, updated_at = now() WHERE company_id = %s",
               (reason[:500], company_id))
    log.error("company %s: sms sending switched off", company_id)


def send_queue(company_id: int) -> dict:
    """Sends everything queued for one company (one runner per company at a time)."""
    lock = _lock(company_id)
    if not lock:
        return {"running": True}
    stats = {"sent": 0, "failed": 0, "requests": 0, "stopped": None}
    bulk = BULK
    try:
        # cut off by a restart: never resend, the gateway may have taken it
        db.execute("""UPDATE sms_messages SET status = 'unknown', error = 'পাঠানোর সময় থেমে গিয়েছিল; আবার পাঠানো হয়নি',
                      updated_at = now() WHERE company_id = %s AND status = 'sending' AND updated_at < now() - interval '10 minutes'""",
                   (company_id,))
        streak, kind = 0, None
        while not stats["stopped"]:
            s = settings(company_id)
            if not (configured(s) and s["enabled"]):
                stats["stopped"] = "disabled"
                break
            batch = _claim(company_id)
            if not batch:
                break
            jobs = _jobs(batch, bulk)
            with _client(s) as client, ThreadPoolExecutor(WORKERS) as pool:
                results = list(pool.map(lambda job: (job, send_request(s, [m["mobile"] for m in job], job[0]["text"], client)), jobs))
            stats["requests"] += len(results)
            back: list[int] = []
            for job, res in results:
                st = res.get("status")
                if res["ok"]:
                    ids = res["message_ids"] if len(res["message_ids"]) == len(job) else [None] * len(job)
                    for m, mid in zip(job, ids):
                        _save(m["id"], res, mid)
                    stats["sent"] += len(job)
                    streak, kind = 0, None
                elif st == "-65" and len(job) > 1:          # Bulk Limit Exceeded: smaller groups next time
                    back += [m["id"] for m in job]
                    bulk = max(1, len(job) // 2)
                elif st in ACCOUNT_ERRORS:                    # nothing went out; keep them queued, stop sending
                    back += [m["id"] for m in job]
                    stats["stopped"] = f"account {st}"
                    _switch_off(company_id, f"গেটওয়ে: {st} {ACCOUNT_ERRORS[st]} — পাঠানো বন্ধ করা হয়েছে, বাকি SMS অপেক্ষায় আছে")
                else:
                    for m in job:
                        _save(m["id"], res)
                    stats["failed"] += len(job)
                    k = f"{st}:{re.sub(r'[0-9]+', '#', (res.get('error') or '')[:60])}"
                    streak, kind = (streak + 1 if k == kind else 1), k
                    log.warning("sms %s failed: %s", [m["id"] for m in job], st)
            if back:
                _requeue(back)
            refresh_campaigns(company_id)
            if streak >= MAX_FAIL_STREAK and not stats["stopped"]:
                stats["stopped"] = "failures"
                _switch_off(company_id, f"পরপর {streak} বার ব্যর্থ, পাঠানো বন্ধ করা হয়েছে: {(results[-1][1].get('error') or '')[:200]}")
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
    _save(m["id"], res, res.get("message_id"))
    refresh_campaigns(company_id)
    return {"ok": res["ok"], "status": res.get("status"), "text": res.get("text"),
            "description": res.get("description"), "error": res.get("error"), "message_id": res.get("message_id")}


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
def status_url(s: dict) -> str:
    return s.get("status_url") or same_host(s, "/getstatus")


def check_status(company_id: int, limit: int = 300) -> dict:
    """GET /getstatus?apikey&secretkey&messageid for SMS the gateway took in the last 48 hours:
    0 DELIVRD -> delivered, 1 REJECTD -> failed, 4 SENT / 2 PENDING -> asked again later (every 10 minutes)."""
    s = settings(company_id)
    if not configured(s):
        return {"checked": 0}
    rows = db.all_rows(
        """SELECT id, message_id FROM sms_messages WHERE company_id = %s AND status = 'sent' AND message_id IS NOT NULL
               AND sent_at > now() - interval '48 hours' AND sent_at < now() - interval '1 minute'
               AND (status_checked_at IS NULL OR status_checked_at < now() - interval '10 minutes')
           ORDER BY sent_at LIMIT %s""", (company_id, limit))
    done = 0
    url = status_url(s)
    with _client(s) as c:
        for r in rows:
            try:
                resp = c.get(url, params={"apikey": s["api_key"], "secretkey": s["secret_key"], "messageid": r["message_id"]})
            except httpx.HTTPError:
                continue
            j = _json(resp.text)
            j = j[0] if isinstance(j, list) and j else j
            if not isinstance(j, dict):
                continue
            code, text = str(j.get("Status", "")).strip(), str(j.get("Text") or "").upper().strip()
            if code in ("101", "108", "109", "114"):      # the query itself was refused (doc: delivery query codes)
                log.info("company %s: delivery query refused (%s %s)", company_id, code, text)
                break
            state = "delivered" if code == "0" or text in DELIVERED else "failed" if code == "1" or text in UNDELIVERED else None
            ms = _number(j.get("Delivery Time"))
            at = ms / 1000 if ms and ms > 0 else None
            db.execute(
                """UPDATE sms_messages SET status = coalesce(%s, status), gateway_text = coalesce(nullif(%s, ''), gateway_text),
                       delivered_at = CASE WHEN %s = 'delivered'
                           THEN coalesce(to_timestamp(%s) AT TIME ZONE 'UTC', now()) ELSE delivered_at END,
                       error = CASE WHEN %s = 'failed' THEN 'গেটওয়ে: পৌঁছায়নি (' || %s || ')' ELSE error END,
                       status_checked_at = now(), updated_at = now() WHERE id = %s""",
                (state, text, state, at, state, text or code, r["id"]))
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
