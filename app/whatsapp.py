"""WhatsApp Cloud API calls for onboarding (Embedded Signup, Coexistence) and status."""
import os

import httpx

from app import store

GRAPH = "https://graph.facebook.com/v26.0"
APP_ID = os.environ.get("META_APP_ID", "1900768904642203")
CONFIG_ID = os.environ.get("META_ES_CONFIG_ID", "905103919350510")


def _check(r: httpx.Response) -> dict:
    data = r.json() if r.content else {}
    if r.status_code >= 400 or "error" in data:
        err = data.get("error", {})
        raise RuntimeError(f"Graph API {r.status_code}: {err.get('message') or r.text[:300]}")
    return data


def exchange_code(code: str) -> str:
    """Embedded Signup code -> business integration token."""
    r = httpx.get(f"{GRAPH}/oauth/access_token", params={
        "client_id": APP_ID,
        "client_secret": os.environ.get("META_APP_SECRET", ""),
        "code": code,
    }, timeout=30)
    return _check(r)["access_token"]


def token() -> str:
    t = store.get_setting("wa_access_token")
    if not t:
        raise RuntimeError("WhatsApp এখনো সংযুক্ত হয়নি")
    return t


def subscribe_app(waba_id: str, access_token: str) -> dict:
    return _check(httpx.post(f"{GRAPH}/{waba_id}/subscribed_apps",
                             headers={"Authorization": f"Bearer {access_token}"}, timeout=30))


def phone_numbers(waba_id: str, access_token: str) -> list[dict]:
    r = httpx.get(f"{GRAPH}/{waba_id}/phone_numbers",
                  params={"fields": "id,display_phone_number,verified_name,quality_rating,platform_type,is_on_biz_app"},
                  headers={"Authorization": f"Bearer {access_token}"}, timeout=30)
    return _check(r).get("data", [])


def waba_numbers() -> list[dict]:
    return phone_numbers(store.get_setting("wa_waba_id"), token())


def phone_status(phone_number_id: str) -> dict:
    r = httpx.get(f"{GRAPH}/{phone_number_id}",
                  params={"fields": "display_phone_number,verified_name,quality_rating,platform_type,is_on_biz_app,status"},
                  headers={"Authorization": f"Bearer {token()}"}, timeout=30)
    return _check(r)


def sync(phone_number_id: str, sync_type: str) -> dict:
    """sync_type: smb_app_state_sync (contacts) or history (past chats). Must run within 24h of onboarding."""
    r = httpx.post(f"{GRAPH}/{phone_number_id}/smb_app_data",
                   json={"messaging_product": "whatsapp", "sync_type": sync_type},
                   headers={"Authorization": f"Bearer {token()}"}, timeout=30)
    return _check(r)


def send_text(to: str, body: str, phone_number_id: str | None = None) -> dict:
    """Free-form text (works inside the 24h customer-service window). Sends from the number the
    customer wrote to when given, else the default number."""
    r = httpx.post(f"{GRAPH}/{phone_number_id or store.get_setting('wa_phone_number_id')}/messages",
                   json={"messaging_product": "whatsapp", "to": to, "type": "text", "text": {"body": body}},
                   headers={"Authorization": f"Bearer {token()}"}, timeout=30)
    return _check(r)


def download_media(media_id: str) -> tuple[bytes, str]:
    """Media sent by a customer (voice note, image). Returns (bytes, mime type)."""
    meta = _check(httpx.get(f"{GRAPH}/{media_id}", headers={"Authorization": f"Bearer {token()}"}, timeout=30))
    r = httpx.get(meta["url"], headers={"Authorization": f"Bearer {token()}"}, timeout=60, follow_redirects=True)
    r.raise_for_status()
    return r.content, meta.get("mime_type") or r.headers.get("content-type", "application/octet-stream")


def send_template(to: str, name: str, language: str) -> dict:
    r = httpx.post(f"{GRAPH}/{store.get_setting('wa_phone_number_id')}/messages",
                   json={"messaging_product": "whatsapp", "to": to, "type": "template",
                         "template": {"name": name, "language": {"code": language}}},
                   headers={"Authorization": f"Bearer {token()}"}, timeout=30)
    return _check(r)


def create_template(name: str, language: str, category: str, body: str) -> dict:
    r = httpx.post(f"{GRAPH}/{store.get_setting('wa_waba_id')}/message_templates",
                   json={"name": name, "language": language, "category": category,
                         "components": [{"type": "BODY", "text": body}]},
                   headers={"Authorization": f"Bearer {token()}"}, timeout=30)
    return _check(r)


def list_templates() -> list[dict]:
    r = httpx.get(f"{GRAPH}/{store.get_setting('wa_waba_id')}/message_templates",
                  params={"fields": "name,language,status,category", "limit": 50},
                  headers={"Authorization": f"Bearer {token()}"}, timeout=30)
    return _check(r).get("data", [])


# --- adding a number that was removed from the WhatsApp Business app -------------------------
def add_phone_number(cc: str, phone_number: str, verified_name: str) -> dict:
    r = httpx.post(f"{GRAPH}/{store.get_setting('wa_waba_id')}/phone_numbers",
                   data={"cc": cc, "phone_number": phone_number, "verified_name": verified_name},
                   headers={"Authorization": f"Bearer {token()}"}, timeout=30)
    return _check(r)


def request_code(phone_number_id: str, method: str = "SMS") -> dict:
    r = httpx.post(f"{GRAPH}/{phone_number_id}/request_code",
                   data={"code_method": method, "language": "en_US"},
                   headers={"Authorization": f"Bearer {token()}"}, timeout=30)
    return _check(r)


def verify_code(phone_number_id: str, code: str) -> dict:
    r = httpx.post(f"{GRAPH}/{phone_number_id}/verify_code", data={"code": code},
                   headers={"Authorization": f"Bearer {token()}"}, timeout=30)
    return _check(r)


def register(phone_number_id: str, pin: str) -> dict:
    r = httpx.post(f"{GRAPH}/{phone_number_id}/register",
                   json={"messaging_product": "whatsapp", "pin": pin},
                   headers={"Authorization": f"Bearer {token()}"}, timeout=30)
    return _check(r)


def complete_onboarding(code: str, waba_id: str, phone_number_id: str | None) -> dict:
    access_token = exchange_code(code)
    store.set_setting("wa_access_token", access_token)
    store.set_setting("wa_waba_id", waba_id)
    subscribe_app(waba_id, access_token)
    numbers = phone_numbers(waba_id, access_token)
    if not phone_number_id and numbers:
        phone_number_id = numbers[0]["id"]
    if phone_number_id:
        store.set_setting("wa_phone_number_id", phone_number_id)
    store.set_setting("wa_onboarded_at", store.now())
    result = {"waba_id": waba_id, "phone_number_id": phone_number_id, "numbers": numbers, "sync": {}}
    # Coexistence: contacts first, then history (24h window)
    if phone_number_id:
        for kind in ("smb_app_state_sync", "history"):
            try:
                result["sync"][kind] = sync(phone_number_id, kind)
            except Exception as e:
                result["sync"][kind] = {"error": str(e)}
    store.set_setting("wa_last_onboarding", str(result)[:2000])
    return result
