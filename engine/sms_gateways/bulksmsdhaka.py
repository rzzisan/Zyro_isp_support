"""Bulk SMS Dhaka (bulksmsdhaka.net, dashboard → Developers):
- send: GET/POST {base}/sendtext {apikey, callerID, number (01XXXXXXXXX), message}; we POST JSON (UTF-8, so Bangla
  and & $ @ need no URL encoding and the key never sits in a URL).
- delivery: GET {base}/getstatus?apikey&messageid; 1016 = id not valid OR already asked once, so it is asked late
  (FIRST_STATUS_AFTER) and a 1016/1017 answer is final for that SMS.
- balance: GET {base}/getBalance?apikey (the dashboard shows taka and "Available SMS").
- Only the API key, no secret. The account has an IP whitelist (dashboard → Setting → Ip White List); a server not on
  it gets HTTP 403 {"status":"error","message":"Access Denied. Your IP (x) is not whitelisted for API access."};
  a wrong key gets HTTP 401 {"error":"Unauthenticated or Invalid API Key."} (both seen 2026-10-29).
Result codes (Developers page): 1000 request successful, 1001 sent, 1002 pending, 1003 failed; account: 1018 no key,
1015 authorization failed, 1013 balance validity over, 2001 balance insufficient, 1011 sender ID has no gateway,
1010 account disabled, 1009 account not verified, 1008 IP not whitelisted; per SMS: 1014 their server error,
1006 content validation failed, 1005 spam, 1012 masking SMS must be Bengali; delivery query: 1017 no id, 1016.
The doc shows no successful answer, so the parser looks for the code, text and message id under the usual names and
keeps the raw answer (keys blanked) in the log when it can't tell.
"""
import logging
import re
import threading
import time

import httpx

from engine.sms_gateways.common import GatewayError, as_json, http_failure, number, secrets, short

NEEDS_SECRET = False
BULK = 1                      # one number per request until a comma list is confirmed to work
WORKERS = 1                   # they answer HTTP 429 to a burst (4 parallel requests did, 2026-10-29)
MIN_GAP = 0.4                 # seconds between two requests
THROTTLE_WAIT = 15            # after a 429
FIRST_STATUS_AFTER = 900      # seconds after sending before the (possibly only) delivery query
STATUS_EVERY = 1800

OK_CODES = {"1000": "Request successful", "1001": "Sent", "1002": "Pending"}
ACCOUNT_ERRORS = {
    "1018": "API key missing", "1015": "Authorization failed", "1013": "Balance validity not available",
    "2001": "Balance insufficient", "1011": "Sender ID has no gateway", "1010": "Account disabled",
    "1009": "Account not verified", "1008": "IP not whitelisted",
}
NUMBER_ERRORS = {
    "1003": "Failed", "1014": "Internal server error", "1006": "SMS content validation failed", "1005": "Spam detected",
    "1012": "Masking SMS must be sent in Bengali",
}
QUERY_FINAL = {"1016": "Message id not valid or already queried", "1017": "Message id missing"}
ACCOUNT_WORDS = re.compile(r"whitelist|unauthenticated|invalid api ?key|insufficient|account (is )?(disabled|not verified)", re.I)
ERROR_WORDS = re.compile(r"error|fail|invalid|denied|unauthori[sz]ed|unauthenticated|reject", re.I)

CODE_KEYS = ("response_code", "code", "status_code", "statusCode", "error_code", "status")
TEXT_KEYS = ("message", "success_message", "error_message", "error", "msg", "description", "status")
ID_KEYS = ("message_id", "messageid", "messageId", "msg_id", "sms_id", "id")

# the request URLs of balance/status carry the key: never let httpx log them
logging.getLogger("httpx").setLevel(logging.WARNING)


_pace_lock = threading.Lock()
_last = [0.0]


def _pace() -> None:
    with _pace_lock:
        wait = _last[0] + MIN_GAP - time.monotonic()
        if wait > 0:
            time.sleep(wait)
        _last[0] = time.monotonic()


def base(g: dict) -> str:
    return g["endpoint_url"].rstrip("/")


def local(mobile: str) -> str:
    """8801XXXXXXXXX (how the Desk keeps numbers) -> 01XXXXXXXXX (the API's example)."""
    return mobile[2:] if mobile.startswith("880") else mobile


def _parts(j) -> list[dict]:
    """The answer and its `data` (dict or list of dicts), where these panels put the details."""
    if isinstance(j, list):
        return [x for x in j if isinstance(x, dict)][:1]
    if not isinstance(j, dict):
        return []
    out = [j]
    d = j.get("data")
    if isinstance(d, dict):
        out.append(d)
    elif isinstance(d, list) and d and isinstance(d[0], dict):
        out.append(d[0])
    return out


def _first(parts: list[dict], keys: tuple):
    for p in parts:
        for k in keys:
            if p.get(k) not in (None, ""):
                return p[k]
    return None


def code_of(j) -> str | None:
    """The 4-digit result code, wherever it is."""
    for p in _parts(j):
        for k in CODE_KEYS:
            v = str(p.get(k, "")).strip()
            if re.fullmatch(r"\d{4}", v):
                return v
    return None


def text_of(j, raw: str = "") -> str:
    v = _first(_parts(j), TEXT_KEYS)
    return str(v).strip() if v is not None else (raw or "").strip()[:200]


def parse_send(status_code: int, body: str) -> dict:
    """Gateway answer -> {ok, account, status, text, description, message_ids}."""
    j = as_json(body)
    code = code_of(j)
    text = text_of(j, body)
    mid = _first(_parts(j), ID_KEYS)
    ids = [str(mid).strip()] if mid not in (None, "") and not isinstance(mid, (dict, list)) else []
    desc = OK_CODES.get(code) or ACCOUNT_ERRORS.get(code) or NUMBER_ERRORS.get(code) if code else None
    status = code or str(status_code)
    if code in ACCOUNT_ERRORS or status_code in (401, 403) or (code is None and ACCOUNT_WORDS.search(text)):
        return {"ok": False, "account": True, "status": status, "text": text or None, "description": desc, "message_ids": []}
    if code in OK_CODES:
        ok = 200 <= status_code < 300
    elif code is not None:
        ok = False
    else:
        flag = str(_first(_parts(j), ("status", "success")) or "").strip().lower()
        ok = 200 <= status_code < 300 and (flag in ("success", "ok", "true", "1", "sent")
                                           or (flag not in ("error", "failed", "false", "0") and not ERROR_WORDS.search(text)
                                               and not (isinstance(j, dict) and j.get("error"))))
    return {"ok": ok, "account": False, "status": status, "text": text or None, "description": desc,
            "message_ids": ids if ok else []}


def send(g: dict, mobiles: list[str], text: str, client: httpx.Client) -> dict:
    body = {"apikey": g["api_key"], "callerID": g["caller_id"], "number": ",".join(local(m) for m in mobiles),
            "message": text}
    _pace()
    try:
        r = client.post(f"{base(g)}/sendtext", json=body)
    except httpx.HTTPError as e:
        return http_failure(e, g)
    if r.status_code == 429:      # nothing was taken; engine/sms.py keeps it queued and slows down
        return {"ok": False, "account": False, "throttled": True, "status": "429", "text": "Too Many Requests",
                "description": None, "message_ids": [], "error": "HTTP 429 Too Many Requests"}
    res = parse_send(r.status_code, r.text)
    if not res["ok"]:
        res["error"] = short(f"{res['status']} {res['description'] or ''} {res['text'] or ''}".strip()
                             if res["status"] != str(r.status_code) else f"HTTP {r.status_code}: {res['text'] or r.text}", secrets(g))
    return res


def parse_balance(body: str) -> dict | None:
    j = as_json(body)
    if isinstance(j, (int, float, str)) and number(j) is not None:
        return {"balance": number(j), "sms": None}
    parts = _parts(j)
    value = number(_first(parts, ("balance", "Balance", "current_balance", "credit", "amount", "taka")))
    sms = number(_first(parts, ("available_sms", "availableSms", "sms", "sms_balance", "total_sms", "Available SMS")))
    if value is None:
        return None
    return {"balance": value, "sms": int(sms) if sms is not None else None}


def balance(g: dict, client: httpx.Client) -> dict:
    try:
        r = client.get(f"{base(g)}/getBalance", params={"apikey": g["api_key"]})
    except httpx.HTTPError as e:
        raise GatewayError(short(f"{type(e).__name__}: {e}", secrets(g)))
    got = parse_balance(r.text) if 200 <= r.status_code < 300 else None
    if got is None:
        j = as_json(r.text)
        code = code_of(j)
        why = f"{code} {ACCOUNT_ERRORS.get(code) or NUMBER_ERRORS.get(code) or ''} " if code else ""
        raise GatewayError(short(f"HTTP {r.status_code}: {why}{text_of(j, r.text)}", secrets(g)))
    return got


def validate(g: dict, client: httpx.Client) -> dict:
    """No separate endpoint: a balance answer means the key, IP whitelist and account are fine."""
    got = balance(g, client)
    return {"client": None, "balance": got["balance"], "sms": got["sms"]}


DELIVERED_WORDS = re.compile(r"^(deliver(ed)?|delivrd)$", re.I)
FAILED_WORDS = re.compile(r"undeliv|reject|fail|expired|blocked|invalid", re.I)


def status(g: dict, message_id: str, client: httpx.Client) -> dict | None:
    try:
        r = client.get(f"{base(g)}/getstatus", params={"apikey": g["api_key"], "messageid": message_id})
    except httpx.HTTPError:
        return None
    j = as_json(r.text)
    if j is None:
        return None
    code = code_of(j)
    parts = _parts(j)
    text = str(_first(parts, ("dlr_status", "delivery_status", "deliveryStatus", "sms_status", "status", "message")) or "").strip()
    if r.status_code in (401, 403) or code in ACCOUNT_ERRORS:
        return {"state": None, "text": short(f"{code or r.status_code} {text}", secrets(g)), "at": None, "final": False, "stop": True}
    if code in QUERY_FINAL:
        return {"state": None, "text": f"{code} {QUERY_FINAL[code]}", "at": None, "final": True, "stop": False}
    if DELIVERED_WORDS.match(text):
        state = "delivered"
    elif code == "1003" or FAILED_WORDS.search(text):
        state = "failed"
    else:
        state = None
    return {"state": state, "text": short(text or code or "", secrets(g))[:60], "at": None, "final": False, "stop": False}
