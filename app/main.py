"""Zyro ISP Support — WhatsApp Cloud API webhook + admin dashboard."""
import hashlib
import hmac
import html
import json
import logging
import os
import threading

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import HTMLResponse, PlainTextResponse, RedirectResponse
from starlette.middleware.sessions import SessionMiddleware

from app import store

VERIFY_TOKEN = os.environ.get("WA_VERIFY_TOKEN", "")
APP_SECRET = os.environ.get("META_APP_SECRET", "")

log = logging.getLogger("zyro-support")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

app = FastAPI(docs_url=None, redoc_url=None, openapi_url=None)
app.add_middleware(
    SessionMiddleware,
    secret_key=os.environ.get("SESSION_SECRET", ""),
    session_cookie="zyro_admin",
    max_age=12 * 3600,
    same_site="strict",
    https_only=True,
)

from app.admin import router as admin_router  # noqa: E402

app.include_router(admin_router)


@app.get("/")
def root():
    return RedirectResponse("/admin", status_code=302)


@app.get("/health")
def health():
    return {"ok": True}


@app.get("/meta/callback")
def meta_callback(request: Request):
    """Redirect target of the Meta-hosted Embedded Signup. The number is connected on Meta's side;
    we only note that the flow finished (the code itself is not needed for our own WABA)."""
    q = request.query_params
    log.info("embedded signup callback: code=%s error=%s", "yes" if q.get("code") else "no", q.get("error"))
    ok = not q.get("error")
    text = "WhatsApp সংযোগ সম্পন্ন হয়েছে। এই পেজ বন্ধ করে দিতে পারেন।" if ok else "সংযোগ সম্পন্ন হয়নি: " + q.get("error_description", q.get("error", ""))
    return HTMLResponse(f'<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><body style="font-family:sans-serif;padding:40px;text-align:center"><h2>{html.escape(text)}</h2></body>')


@app.get("/webhook")
def verify(request: Request):
    q = request.query_params
    if (
        q.get("hub.mode") == "subscribe"
        and VERIFY_TOKEN
        and hmac.compare_digest(q.get("hub.verify_token", ""), VERIFY_TOKEN)
    ):
        return PlainTextResponse(q.get("hub.challenge", ""))
    raise HTTPException(status_code=403)


def signature_ok(raw: bytes, header: str) -> bool:
    if not APP_SECRET or not header.startswith("sha256="):
        return False
    expected = hmac.new(APP_SECRET.encode(), raw, hashlib.sha256).hexdigest()
    return hmac.compare_digest(header[7:], expected)


@app.post("/webhook")
async def receive(request: Request):
    raw = await request.body()
    if not signature_ok(raw, request.headers.get("x-hub-signature-256", "")):
        log.warning("rejected webhook: bad or missing signature")
        raise HTTPException(status_code=403)

    payload = json.loads(raw)
    now = store.now()
    to_handle = []
    with store.connect() as conn:
        conn.execute("INSERT INTO events (received_at, payload) VALUES (?, ?)", (now, raw.decode()))
        for entry in payload.get("entry", []):
            for change in entry.get("changes", []):
                field = change.get("field")
                value = change.get("value", {})
                # "messages" = customer -> us; "smb_message_echoes" = sent from the Business app (Coexistence)
                if field not in ("messages", "smb_message_echoes"):
                    continue
                echo = int(field == "smb_message_echoes")
                phone_number_id = value.get("metadata", {}).get("phone_number_id")
                names = {c.get("wa_id"): c.get("profile", {}).get("name") for c in value.get("contacts", [])}
                for m in value.get("messages", []) + value.get("message_echoes", []):
                    body = (m.get("text") or {}).get("body")
                    # from_number column = the customer's number (echoes go business -> customer)
                    customer = m.get("to") if echo else m.get("from")
                    cur = conn.execute(
                        "INSERT OR IGNORE INTO messages VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        (m.get("id"), now, phone_number_id, customer,
                         names.get(customer), m.get("type"), body, echo),
                    )
                    if not echo and cur.rowcount:  # skip Meta retries of the same message
                        to_handle.append((m, names.get(customer)))
    # billing lookup + AI take seconds; answer Meta immediately
    for m, name in to_handle:
        threading.Thread(target=handle_message, args=(m, name), daemon=True).start()
    return {"ok": True}


_billing = None
_billing_lock = threading.Lock()


def billing():
    global _billing
    with _billing_lock:
        if _billing is None:
            from app.ispdigital import ISPDigital
            _billing = ISPDigital()
        return _billing


def reset_billing() -> None:
    """Called after the admin changes billing credentials."""
    global _billing
    with _billing_lock:
        _billing = None


def recent_history(conn, wa_number: str, limit: int = 12) -> list[dict]:
    rows = conn.execute(
        """SELECT echo, body FROM messages WHERE from_number = ? AND msg_type = 'text' AND body IS NOT NULL
           ORDER BY received_at DESC LIMIT ?""",
        (wa_number, limit),
    ).fetchall()
    history = [{"role": "assistant" if r["echo"] else "user", "content": r["body"]} for r in reversed(rows)]
    # the AI needs alternating turns starting with user; merge consecutive same-role messages
    merged: list[dict] = []
    for h in history:
        if merged and merged[-1]["role"] == h["role"]:
            merged[-1]["content"] += "\n" + h["content"]
        else:
            merged.append(dict(h))
    while merged and merged[0]["role"] != "user":
        merged.pop(0)
    return merged


def should_send(mode: str, wa: str) -> bool:
    """live = reply automatically, but only to allow-listed numbers when a list is set."""
    if mode != "live":
        return False
    allow = [n.strip() for n in (store.get_setting("live_allowlist") or "").replace("\n", ",").split(",") if n.strip()]
    if not allow:
        return True
    from app.ispdigital import normalize_bd_mobile
    return normalize_bd_mobile(wa) in {normalize_bd_mobile(n) for n in allow}


def handle_message(message: dict, contact_name: str | None) -> None:
    """Identify customer, check billing/ONU/PPPoE, draft a reply (shadow mode stores it only)."""
    wa = message.get("from")
    log.info("message from %s (%s): type=%s", wa, contact_name, message.get("type"))
    mode = store.get_setting("bot_mode", "shadow")
    if mode == "off" or message.get("type") != "text":
        return
    context = draft = error = provider = model = None
    try:
        from app.agent import draft_reply
        from app.ispdigital import diagnose, find_customer_by_whatsapp

        customer = find_customer_by_whatsapp(billing(), wa)
        context = diagnose(billing(), customer) if customer else None
        with store.connect() as conn:
            history = recent_history(conn, wa)
        if not history or history[-1]["role"] != "user":
            history.append({"role": "user", "content": (message.get("text") or {}).get("body", "")})
        draft, provider, model = draft_reply(history, context)
        if draft and should_send(mode, wa):
            from app import whatsapp
            res = whatsapp.send_text(wa, draft)
            mode = "sent"
            with store.connect() as conn:
                # keep our reply in the history so the next turn has context
                conn.execute("INSERT OR IGNORE INTO messages VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                             (res.get("messages", [{}])[0].get("id"), store.now(), None, wa, None, "text", draft, 1))
    except Exception as e:
        log.exception("draft failed for %s", message.get("id"))
        error = str(e)[:500]
    with store.connect() as conn:
        conn.execute(
            """INSERT INTO drafts (wa_message_id, created_at, from_number, context, draft, mode, provider, model, error)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (message.get("id"), store.now(), wa, json.dumps(context, ensure_ascii=False) if context else None,
             draft, mode, provider, model, error),
        )
