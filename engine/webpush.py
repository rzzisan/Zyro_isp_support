"""Browser push (Web Push, VAPID) for new WhatsApp messages, so panel users get them with the panel tab closed.

The panel stores each browser's subscription in push_subscriptions (bell in the top bar); this sends to every
subscribed member of the company who may open the inbox. The VAPID key pair lives in web_push_keys and is made
here on first use (private key encrypted like the panel's secrets).
"""
import base64
import json
import logging
import os
import threading

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import ec

from engine import db

log = logging.getLogger("zyro-engine")
SUBJECT = os.environ.get("VAPID_SUBJECT", "mailto:support@zyrotechbd.com")
MEDIA = {"image": "📷 ছবি", "audio": "🎤 ভয়েস", "video": "🎬 ভিডিও", "document": "📄 ফাইল", "sticker": "স্টিকার",
         "location": "📍 লোকেশন"}
_keys: tuple[str, str] | None = None
_lock = threading.Lock()


def _b64(b: bytes) -> str:
    return base64.urlsafe_b64encode(b).rstrip(b"=").decode()


def keys() -> tuple[str, str]:
    """(public key for the browser, private key) — both base64url, made once."""
    global _keys
    with _lock:
        if _keys:
            return _keys
        row = db.one("SELECT public_key, private_key FROM web_push_keys ORDER BY id LIMIT 1")
        if not row:
            k = ec.generate_private_key(ec.SECP256R1())
            pub = _b64(k.public_key().public_bytes(serialization.Encoding.X962, serialization.PublicFormat.UncompressedPoint))
            priv = _b64(k.private_numbers().private_value.to_bytes(32, "big"))
            db.execute("INSERT INTO web_push_keys (public_key, private_key, created_at) VALUES (%s, %s, now())",
                       (pub, db.encrypt(priv)))
            row = db.one("SELECT public_key, private_key FROM web_push_keys ORDER BY id LIMIT 1")
        _keys = (row["public_key"], db.decrypt(row["private_key"]))
        return _keys


def _display(wa: str) -> str:
    return "0" + wa[3:] if wa.startswith("880") else wa


def new_message(company_id: int, contact: dict, mtype: str | None, body: str | None, staff: bool) -> None:
    """Push one incoming message to the company's subscribed browsers (in the background)."""
    threading.Thread(target=_send_all, args=(company_id, contact, mtype, body, staff), daemon=True).start()


def _send_all(company_id: int, contact: dict, mtype: str | None, body: str | None, staff: bool) -> None:
    try:
        if (db.one("SELECT notify_muted FROM wa_contacts WHERE id = %s", (contact["id"],)) or {}).get("notify_muted"):
            return  # muted in the inbox: no push for this number
        subs = db.all_rows(
            """SELECT s.id, s.endpoint, s.p256dh, s.auth, c.slug FROM push_subscriptions s
               JOIN companies c ON c.id = s.company_id
               JOIN company_user cu ON cu.company_id = s.company_id AND cu.user_id = s.user_id
               WHERE s.company_id = %s
                 AND (cu.role = 'owner' OR cu.permissions IS NULL OR cu.permissions ? 'inbox')""",
            (company_id,))
        if not subs:
            return
        from pywebpush import WebPushException, webpush
        _, priv = keys()
        text = (body or "").strip() or MEDIA.get(mtype or "", "নতুন মেসেজ")
        payload = json.dumps({
            "title": ("[স্টাফ] " if staff else "") + (contact.get("name") or _display(contact["wa_number"])),
            "body": text[:140],
            "tag": f"wa-{contact['id']}",
            "url": f"/app/{subs[0]['slug']}/inbox/{contact['id']}",
        }, ensure_ascii=False)
        for s in subs:
            try:
                webpush({"endpoint": s["endpoint"], "keys": {"p256dh": s["p256dh"], "auth": s["auth"]}}, payload,
                        vapid_private_key=priv, vapid_claims={"sub": SUBJECT}, ttl=3600,
                        headers={"Urgency": "high"}, timeout=10)
            except WebPushException as e:
                status = getattr(e.response, "status_code", None)
                if status in (404, 410):  # the browser dropped this subscription
                    db.execute("DELETE FROM push_subscriptions WHERE id = %s", (s["id"],))
                else:
                    log.warning("web push to subscription %s failed: %s", s["id"], str(e)[:200])
    except Exception:
        log.exception("web push failed for company %s", company_id)
