"""Zyro ISP Support — WhatsApp Cloud API webhook.

Phase 1: verify the webhook with Meta and store every incoming event.
The AI reply logic plugs in later (see handle_message).
"""
import hashlib
import hmac
import json
import logging
import os
import sqlite3
import threading
from datetime import datetime, timezone
from pathlib import Path

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import PlainTextResponse

BASE_DIR = Path(__file__).resolve().parent.parent
DATA_DIR = BASE_DIR / "data"
DB_PATH = DATA_DIR / "messages.db"

VERIFY_TOKEN = os.environ.get("WA_VERIFY_TOKEN", "")
APP_SECRET = os.environ.get("META_APP_SECRET", "")

log = logging.getLogger("zyro-support")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

app = FastAPI(docs_url=None, redoc_url=None, openapi_url=None)


def db() -> sqlite3.Connection:
    DATA_DIR.mkdir(exist_ok=True)
    conn = sqlite3.connect(DB_PATH)
    conn.execute(
        """CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            received_at TEXT NOT NULL,
            payload TEXT NOT NULL
        )"""
    )
    conn.execute(
        """CREATE TABLE IF NOT EXISTS messages (
            wa_message_id TEXT PRIMARY KEY,
            received_at TEXT NOT NULL,
            phone_number_id TEXT,
            from_number TEXT,
            contact_name TEXT,
            msg_type TEXT,
            body TEXT,
            echo INTEGER NOT NULL DEFAULT 0
        )"""
    )
    conn.execute(
        """CREATE TABLE IF NOT EXISTS drafts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            wa_message_id TEXT,
            created_at TEXT NOT NULL,
            from_number TEXT,
            context TEXT,
            draft TEXT,
            mode TEXT
        )"""
    )
    return conn


@app.get("/health")
def health():
    return {"ok": True}


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
    now = datetime.now(timezone.utc).isoformat()
    conn = db()
    to_handle = []
    with conn:
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
    conn.close()
    # billing lookup + Claude take seconds; answer Meta immediately
    for m, name in to_handle:
        threading.Thread(target=handle_message, args=(m, name), daemon=True).start()
    return {"ok": True}


BOT_MODE = os.environ.get("BOT_MODE", "shadow")  # shadow = only store drafts, never send

_billing = None


def billing():
    global _billing
    if _billing is None:
        from app.ispdigital import ISPDigital
        _billing = ISPDigital()
    return _billing


def recent_history(conn: sqlite3.Connection, wa_number: str, limit: int = 10) -> list[dict]:
    rows = conn.execute(
        """SELECT echo, body FROM messages WHERE from_number = ? AND msg_type = 'text' AND body IS NOT NULL
           ORDER BY received_at DESC LIMIT ?""",
        (wa_number, limit),
    ).fetchall()
    history = [{"role": "assistant" if echo else "user", "content": body} for echo, body in reversed(rows)]
    # Claude needs alternating turns starting with user; merge consecutive same-role messages
    merged: list[dict] = []
    for h in history:
        if merged and merged[-1]["role"] == h["role"]:
            merged[-1]["content"] += "\n" + h["content"]
        else:
            merged.append(dict(h))
    while merged and merged[0]["role"] != "user":
        merged.pop(0)
    return merged


def handle_message(message: dict, contact_name: str | None) -> None:
    """Identify customer, check billing/ONU/PPPoE, draft a reply (shadow mode stores it only)."""
    wa = message.get("from")
    log.info("message from %s (%s): type=%s", wa, contact_name, message.get("type"))
    if message.get("type") != "text":
        return
    try:
        from app.agent import draft_reply
        from app.ispdigital import diagnose, find_customer_by_whatsapp

        customer = find_customer_by_whatsapp(billing(), wa)
        context = diagnose(billing(), customer) if customer else None
        conn = db()
        history = recent_history(conn, wa)
        if not history or history[-1]["role"] != "user":
            history.append({"role": "user", "content": (message.get("text") or {}).get("body", "")})
        draft = draft_reply(history, context)
        with conn:
            conn.execute(
                "INSERT INTO drafts (wa_message_id, created_at, from_number, context, draft, mode) VALUES (?, ?, ?, ?, ?, ?)",
                (message.get("id"), datetime.now(timezone.utc).isoformat(), wa,
                 json.dumps(context, ensure_ascii=False) if context else None, draft, BOT_MODE),
            )
        conn.close()
    except Exception:
        log.exception("draft failed for %s", message.get("id"))
