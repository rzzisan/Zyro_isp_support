"""Replay real webhook events captured by the old bot into the engine (dry run), one by one."""
import hashlib
import hmac
import json
import os
import sqlite3
import sys
import time

import httpx

PNID = "1309537728917134"            # Century Link official number
LIMIT = int(sys.argv[1]) if len(sys.argv) > 1 else 10
secret = os.environ["META_APP_SECRET"].encode()

old = sqlite3.connect("data/messages.db")
rows = old.execute("SELECT payload FROM events ORDER BY id").fetchall()
picked = []
for (p,) in rows:
    d = json.loads(p)
    for e in d.get("entry", []):
        for ch in e.get("changes", []):
            v = ch.get("value", {})
            if ch.get("field") == "messages" and v.get("metadata", {}).get("phone_number_id") == PNID and v.get("messages"):
                picked.append(p)
picked = picked[-LIMIT:]
print("replaying", len(picked), "events")
for p in picked:
    raw = p.encode()
    sig = "sha256=" + hmac.new(secret, raw, hashlib.sha256).hexdigest()
    r = httpx.post("http://127.0.0.1:8994/webhook", content=raw,
                   headers={"x-hub-signature-256": sig, "content-type": "application/json"}, timeout=30)
    m = json.loads(p)["entry"][0]["changes"][0]["value"]["messages"][0]
    print(r.status_code, m.get("from"), m.get("type"), ((m.get("text") or {}).get("body") or "")[:60])
    time.sleep(9)
