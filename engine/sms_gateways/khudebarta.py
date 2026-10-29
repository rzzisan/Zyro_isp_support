"""Khudebarta "SMS HTTP API Published" v5.4.0 (docs/billing-sms-service.md):
- send: POST http://<host>:3775/sendtext, Content-Type application/json, {apikey, secretkey, callerID,
  toUser (880XXXXXXXXXX, several comma separated = bulk), messageContent} -> {"Status":"0","StatusDescription":"",
  "Text":"ACCEPTD","Message_ID":"31771702[,31771704]"}; any other Status = refused (ACCOUNT_ERRORS / NUMBER_ERRORS).
- delivery: GET /getstatus?apikey&secretkey&messageid -> Status 0 DELIVRD, 4 SENT, 2 PENDING, 1 REJECTD.
- balance: POST /api/v3/balance {apikey, secretkey} -> {"statusInfo": {"statusCode": "0", "availablebalance": "797.88"}}.
- validate: POST /validateclient {apikey, secretkey} -> {clientName, clientBalance, status SUCCESS}.
"""
import re
import time
from urllib.parse import urlsplit, urlunsplit

import httpx

from engine.sms_gateways.common import GatewayError, as_json, http_failure, number, secrets, short

NEEDS_SECRET = True
BULK = 50
FIRST_STATUS_AFTER = 60       # seconds after sending before the first delivery query
STATUS_EVERY = 600
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
BULK_LIMIT = "-65"   # Bulk Limit Exceeded: engine/sms.py retries in smaller groups


def same_host(g: dict, path: str) -> str:
    """http://host:3775/<path> from the send URL (balance and delivery live next to /sendtext)."""
    u = urlsplit(g["endpoint_url"])
    return urlunsplit((u.scheme, u.netloc, path, "", ""))


def parse_send(status_code: int, body: str) -> dict:
    """Gateway answer -> {ok, account, status, text, description, message_ids (one per number, in order)}."""
    j = as_json(body)
    if isinstance(j, list) and j:
        j = j[0]
    if isinstance(j, dict):
        st = str(j.get("Status", j.get("status", ""))).strip()
        desc = str(j.get("StatusDescription") or "").strip() or ACCOUNT_ERRORS.get(st) or NUMBER_ERRORS.get(st) or ""
        txt = str(j.get("Text") or "").strip()
        ids = [i.strip() for i in str(j.get("Message_ID") or "").split(",") if i.strip() and i.strip() != "-1"]
        ok = 200 <= status_code < 300 and st == "0"
        return {"ok": ok, "account": not ok and st in ACCOUNT_ERRORS, "status": st or None, "text": txt or None,
                "description": desc or None, "message_ids": ids}
    ok = 200 <= status_code < 300 and not ERROR_WORDS.search(body or "")
    return {"ok": ok, "account": False, "status": str(status_code), "text": (body or "")[:200] or None,
            "description": None, "message_ids": []}


def send(g: dict, mobiles: list[str], text: str, client: httpx.Client) -> dict:
    """One /sendtext call (JSON POST) for one or several numbers getting the same text."""
    body = {"apikey": g["api_key"], "secretkey": g["secret_key"], "callerID": g["caller_id"],
            "toUser": ",".join(mobiles), "messageContent": text}
    try:
        r = client.post(g["endpoint_url"], json=body)
    except httpx.HTTPError as e:
        return http_failure(e, g)
    res = parse_send(r.status_code, r.text)
    if not res["ok"]:
        res["error"] = short(f"{res['status']} {res['description'] or res['text'] or ''}".strip()
                             if res["status"] and res["status"] != str(r.status_code) else f"HTTP {r.status_code}: {r.text}",
                             secrets(g))
    return res


def balance(g: dict, client: httpx.Client) -> dict:
    err = None
    try:
        r = client.post(same_host(g, "/api/v3/balance"), json={"apikey": g["api_key"], "secretkey": g["secret_key"],
                                                                "clienttransid": f"desk-{int(time.time())}"})
        j = as_json(r.text)
        info = j.get("statusInfo") if isinstance(j, dict) else None
        if isinstance(info, dict) and str(info.get("statusCode")) == "0":
            value = number(info.get("availablebalance"))
            if value is not None:
                return {"balance": value, "sms": None}
        code = str((info or {}).get("statusCode") or "")
        err = f"statusCode {code} {(info or {}).get('errordescription') or ''}".strip() if code else f"HTTP {r.status_code}: {r.text}"
    except httpx.HTTPError as e:
        err = f"{type(e).__name__}: {e}"
    raise GatewayError(short(err or "no answer", secrets(g)))


def validate(g: dict, client: httpx.Client) -> dict:
    try:
        r = client.post(same_host(g, "/validateclient"), json={"apikey": g["api_key"], "secretkey": g["secret_key"]})
    except httpx.HTTPError as e:
        raise GatewayError(short(f"গেটওয়েতে সংযোগ হয়নি: {type(e).__name__}: {e}", secrets(g)))
    j = as_json(r.text)
    if not (isinstance(j, dict) and str(j.get("status")).upper() == "SUCCESS"):
        raise GatewayError(short(f"গেটওয়ে key গ্রহণ করেনি (HTTP {r.status_code}: {r.text})", secrets(g)))
    return {"client": j.get("clientName"), "balance": number(j.get("clientBalance"))}


def status(g: dict, message_id: str, client: httpx.Client) -> dict | None:
    """0 DELIVRD -> delivered, 1 REJECTD -> failed, 4 SENT / 2 PENDING -> asked again later."""
    try:
        resp = client.get(g.get("status_url") or same_host(g, "/getstatus"),
                          params={"apikey": g["api_key"], "secretkey": g["secret_key"], "messageid": message_id})
    except httpx.HTTPError:
        return None
    j = as_json(resp.text)
    j = j[0] if isinstance(j, list) and j else j
    if not isinstance(j, dict):
        return None
    code, text = str(j.get("Status", "")).strip(), str(j.get("Text") or "").upper().strip()
    if code == "114":       # answered per SMS ("114 REJECTD", e.g. for every SMS of the day before): no more about it
        return {"state": None, "text": f"{code} {text}".strip(), "at": None, "final": True, "stop": False}
    if code in ("101", "108", "109"):             # the query itself was refused (doc: delivery query codes)
        return {"state": None, "text": f"{code} {text}".strip(), "at": None, "final": False, "stop": True}
    state = "delivered" if code == "0" or text in DELIVERED else "failed" if code == "1" or text in UNDELIVERED else None
    ms = number(j.get("Delivery Time"))
    return {"state": state, "text": text or code, "at": ms / 1000 if ms and ms > 0 else None, "final": False, "stop": False}
