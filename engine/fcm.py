"""Phone push (Firebase Cloud Messaging) for new WhatsApp messages, so Desk app users get them with the app closed.

The app stores each signed-in phone's FCM token in app_push_tokens; this sends to every phone of a company member who
may open the inbox. The Firebase service-account key (never in git) is FCM_SERVICE_ACCOUNT, else
/etc/zyro-desk/firebase-service-account.json (readable by the engine's user only); without it nothing is sent.
Messages are data-only, so the app draws the notification itself and a tap opens that chat.
"""
import base64
import json
import logging
import os
import threading
import time

import httpx
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding

from engine import db

log = logging.getLogger("zyro-engine")
SCOPE = "https://www.googleapis.com/auth/firebase.messaging"
_lock = threading.Lock()
_account: dict | None = None
_access: tuple[str, float] | None = None  # token, expiry (epoch seconds)


def _b64(b: bytes) -> str:
    return base64.urlsafe_b64encode(b).rstrip(b"=").decode()


def account() -> dict | None:
    """The service-account JSON, or None when push is not set up."""
    global _account
    if _account is None:
        path = os.environ.get("FCM_SERVICE_ACCOUNT") or "/etc/zyro-desk/firebase-service-account.json"
        if not os.path.exists(path):
            return None
        with open(path) as f:
            _account = json.load(f)
    return _account


def _access_token(acc: dict) -> str:
    """An OAuth token for FCM from a self-signed JWT (RS256), kept until a few minutes before it expires."""
    global _access
    with _lock:
        if _access and _access[1] > time.time() + 300:
            return _access[0]
        now = int(time.time())
        head = _b64(json.dumps({"alg": "RS256", "typ": "JWT"}).encode())
        claims = _b64(json.dumps({"iss": acc["client_email"], "scope": SCOPE, "aud": acc["token_uri"],
                                  "iat": now, "exp": now + 3600}).encode())
        key = serialization.load_pem_private_key(acc["private_key"].encode(), password=None)
        sig = key.sign(f"{head}.{claims}".encode(), padding.PKCS1v15(), hashes.SHA256())
        r = httpx.post(acc["token_uri"], timeout=15, data={
            "grant_type": "urn:ietf:params:oauth:grant-type:jwt-bearer", "assertion": f"{head}.{claims}.{_b64(sig)}"})
        r.raise_for_status()
        body = r.json()
        _access = (body["access_token"], now + int(body.get("expires_in", 3600)))
        return _access[0]


def send(company_id: int, data: dict[str, str]) -> None:
    """Push [data] to the phones of the company's members who may open the inbox (in the background)."""
    threading.Thread(target=_send_all, args=(company_id, data), daemon=True).start()


def _send_all(company_id: int, data: dict[str, str]) -> None:
    try:
        acc = account()
        if not acc:
            return
        rows = db.all_rows(
            """SELECT p.id, p.token FROM app_push_tokens p
               JOIN company_user cu ON cu.company_id = %s AND cu.user_id = p.user_id
               WHERE cu.role = 'owner' OR cu.permissions IS NULL OR cu.permissions ? 'inbox'""",
            (company_id,))
        if not rows:
            return
        url = f"https://fcm.googleapis.com/v1/projects/{acc['project_id']}/messages:send"
        headers = {"Authorization": f"Bearer {_access_token(acc)}"}
        with httpx.Client(timeout=10) as client:
            for row in rows:
                msg = {"message": {"token": row["token"], "data": data,
                                   "android": {"priority": "high", "ttl": "3600s"}}}
                r = client.post(url, json=msg, headers=headers)
                if r.status_code == 404 or (r.status_code == 400 and "registration token" in r.text.lower()):
                    db.execute("DELETE FROM app_push_tokens WHERE id = %s", (row["id"],))  # app removed or token rotated
                elif r.status_code >= 400:
                    log.warning("fcm push to token %s failed: %s %s", row["id"], r.status_code, r.text[:200])
    except Exception:
        log.exception("fcm push failed for company %s", company_id)
