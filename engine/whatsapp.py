"""WhatsApp Cloud API calls on behalf of one company (its own token and number)."""
import httpx

from engine.tenant import Tenant

GRAPH = "https://graph.facebook.com/v26.0"


def _check(r: httpx.Response) -> dict:
    data = r.json() if r.content else {}
    if r.status_code >= 400 or "error" in data:
        err = data.get("error", {})
        raise RuntimeError(f"Graph API {r.status_code}: {err.get('message') or r.text[:300]}")
    return data


def send_text(t: Tenant, to: str, body: str) -> dict:
    r = httpx.post(f"{GRAPH}/{t.phone_number_id}/messages",
                   json={"messaging_product": "whatsapp", "to": to, "type": "text", "text": {"body": body}},
                   headers={"Authorization": f"Bearer {t.wa_token}"}, timeout=30)
    return _check(r)


def send_audio(t: Tenant, to: str, data: bytes, mime: str = "audio/ogg") -> dict:
    """Upload an audio file to the company's number and send it (OGG/Opus shows as a voice message)."""
    up = _check(httpx.post(f"{GRAPH}/{t.phone_number_id}/media", headers={"Authorization": f"Bearer {t.wa_token}"},
                           data={"messaging_product": "whatsapp", "type": mime},
                           files={"file": ("reply.ogg", data, mime)}, timeout=60))
    r = httpx.post(f"{GRAPH}/{t.phone_number_id}/messages",
                   json={"messaging_product": "whatsapp", "to": to, "type": "audio", "audio": {"id": up["id"]}},
                   headers={"Authorization": f"Bearer {t.wa_token}"}, timeout=30)
    return {**_check(r), "media_id": up["id"]}


def download_media(t: Tenant, media_id: str) -> tuple[bytes, str]:
    meta = _check(httpx.get(f"{GRAPH}/{media_id}", headers={"Authorization": f"Bearer {t.wa_token}"}, timeout=30))
    r = httpx.get(meta["url"], headers={"Authorization": f"Bearer {t.wa_token}"}, timeout=60, follow_redirects=True)
    r.raise_for_status()
    return r.content, meta.get("mime_type") or r.headers.get("content-type", "application/octet-stream")
