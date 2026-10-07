"""PostgreSQL access + Laravel-compatible decryption (the panel encrypts secrets with APP_KEY)."""
import base64
import hashlib
import hmac
import json
import os
from contextlib import contextmanager

import psycopg
from psycopg.rows import dict_row
from cryptography.hazmat.primitives import padding
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes

DSN = os.environ.get("ENGINE_DATABASE_URL", "")
_APP_KEY = os.environ.get("LARAVEL_APP_KEY", "")


@contextmanager
def conn():
    # the panel (Laravel, timezone UTC) stores naive UTC timestamps; pin the session to UTC so
    # now() comparisons in SQL line up. Compare times in SQL, never naive vs aware in Python.
    c = psycopg.connect(DSN, row_factory=dict_row, autocommit=True, options="-c TimeZone=UTC")
    try:
        yield c
    finally:
        c.close()


def one(sql: str, params=()) -> dict | None:
    with conn() as c:
        return c.execute(sql, params).fetchone()


def all_rows(sql: str, params=()) -> list[dict]:
    with conn() as c:
        return c.execute(sql, params).fetchall()


def execute(sql: str, params=()) -> dict | None:
    with conn() as c:
        cur = c.execute(sql, params)
        return cur.fetchone() if cur.description else None


def _key() -> bytes:
    k = _APP_KEY
    return base64.b64decode(k[7:]) if k.startswith("base64:") else k.encode()


def encrypt(plain: str) -> str:
    """Laravel's Crypt::encryptString / 'encrypted' cast format, so the panel can read it."""
    key = _key()
    iv = os.urandom(16)
    pad = padding.PKCS7(128).padder()
    data = pad.update(plain.encode()) + pad.finalize()
    enc = Cipher(algorithms.AES(key), modes.CBC(iv)).encryptor()
    value = base64.b64encode(enc.update(data) + enc.finalize()).decode()
    iv_b64 = base64.b64encode(iv).decode()
    mac = hmac.new(key, (iv_b64 + value).encode(), hashlib.sha256).hexdigest()
    return base64.b64encode(json.dumps({"iv": iv_b64, "value": value, "mac": mac, "tag": ""}, separators=(",", ":")).encode()).decode()


def decrypt(payload: str | None) -> str | None:
    """Inverse of Laravel's Crypt::encryptString (AES-256-CBC + HMAC-SHA256)."""
    if not payload:
        return None
    data = json.loads(base64.b64decode(payload))
    key = _key()
    mac = hmac.new(key, (data["iv"] + data["value"]).encode(), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(mac, data["mac"]):
        raise ValueError("invalid MAC (APP_KEY mismatch?)")
    iv = base64.b64decode(data["iv"])
    dec = Cipher(algorithms.AES(key), modes.CBC(iv)).decryptor()
    padded = dec.update(base64.b64decode(data["value"])) + dec.finalize()
    unpad = padding.PKCS7(128).unpadder()
    return (unpad.update(padded) + unpad.finalize()).decode()
