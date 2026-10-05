"""SQLite storage: messages, drafts, admin users, settings and encrypted AI keys."""
import base64
import hashlib
import hmac
import os
import secrets
import sqlite3
from datetime import datetime, timezone
from pathlib import Path

from cryptography.fernet import Fernet

BASE_DIR = Path(__file__).resolve().parent.parent
DATA_DIR = BASE_DIR / "data"
DB_PATH = DATA_DIR / "messages.db"

SCHEMA = """
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    received_at TEXT NOT NULL,
    payload TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS messages (
    wa_message_id TEXT PRIMARY KEY,
    received_at TEXT NOT NULL,
    phone_number_id TEXT,
    from_number TEXT,
    contact_name TEXT,
    msg_type TEXT,
    body TEXT,
    echo INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS drafts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    wa_message_id TEXT,
    created_at TEXT NOT NULL,
    from_number TEXT,
    context TEXT,
    draft TEXT,
    mode TEXT,
    provider TEXT,
    model TEXT,
    error TEXT
);
CREATE TABLE IF NOT EXISTS admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT
);
CREATE TABLE IF NOT EXISTS ai_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider TEXT NOT NULL,
    label TEXT,
    api_key_enc TEXT NOT NULL,
    model TEXT,
    created_at TEXT NOT NULL,
    rate_limited_until TEXT
);
"""

# settings keys whose values are stored encrypted
SECRET_SETTINGS = {"billing_password"}


def now() -> str:
    return datetime.now(timezone.utc).isoformat()


def connect() -> sqlite3.Connection:
    DATA_DIR.mkdir(exist_ok=True)
    conn = sqlite3.connect(DB_PATH, timeout=10)
    conn.row_factory = sqlite3.Row
    conn.executescript(SCHEMA)
    # columns added after the first deploy
    cols = {r["name"] for r in conn.execute("PRAGMA table_info(drafts)")}
    for col in ("provider", "model", "error"):
        if col not in cols:
            conn.execute(f"ALTER TABLE drafts ADD COLUMN {col} TEXT")
    return conn


# --- encryption ------------------------------------------------------------------
def _fernet() -> Fernet:
    key = os.environ.get("DATA_ENCRYPTION_KEY", "")
    if not key:
        raise RuntimeError("DATA_ENCRYPTION_KEY not set")
    return Fernet(key.encode())


def encrypt(value: str) -> str:
    return _fernet().encrypt(value.encode()).decode()


def decrypt(token: str) -> str:
    return _fernet().decrypt(token.encode()).decode()


def mask(value: str) -> str:
    return "••••" + value[-4:] if len(value) > 8 else "••••"


# --- settings --------------------------------------------------------------------
def get_setting(key: str, default: str | None = None) -> str | None:
    with connect() as conn:
        row = conn.execute("SELECT value FROM settings WHERE key = ?", (key,)).fetchone()
    if not row or row["value"] is None:
        return default
    return decrypt(row["value"]) if key in SECRET_SETTINGS else row["value"]


def set_setting(key: str, value: str | None) -> None:
    stored = encrypt(value) if (key in SECRET_SETTINGS and value) else value
    with connect() as conn:
        conn.execute(
            "INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            (key, stored),
        )


# --- admin passwords (stdlib scrypt) -----------------------------------------------
def hash_password(password: str) -> str:
    salt = secrets.token_bytes(16)
    digest = hashlib.scrypt(password.encode(), salt=salt, n=2**14, r=8, p=1)
    return "scrypt$" + base64.b64encode(salt).decode() + "$" + base64.b64encode(digest).decode()


def verify_password(password: str, stored: str) -> bool:
    try:
        _, salt_b64, digest_b64 = stored.split("$")
        digest = hashlib.scrypt(password.encode(), salt=base64.b64decode(salt_b64), n=2**14, r=8, p=1)
        return hmac.compare_digest(digest, base64.b64decode(digest_b64))
    except Exception:
        return False


def admin_count() -> int:
    with connect() as conn:
        return conn.execute("SELECT COUNT(*) FROM admin_users").fetchone()[0]
