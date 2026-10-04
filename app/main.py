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
                    conn.execute(
                        "INSERT OR IGNORE INTO messages VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        (m.get("id"), now, phone_number_id, m.get("from"),
                         names.get(m.get("from")), m.get("type"), body, echo),
                    )
                    if not echo:
                        handle_message(m, names.get(m.get("from")))
    conn.close()
    return {"ok": True}


def handle_message(message: dict, contact_name: str | None) -> None:
    """Phase 2: identify customer, check billing/ONU/PPPoE, reply via Cloud API."""
    log.info("message from %s (%s): type=%s", message.get("from"), contact_name, message.get("type"))
