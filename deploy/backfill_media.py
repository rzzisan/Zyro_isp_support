"""One-off: copy media ids from stored webhook events into messages (for messages received before media support)."""
import json

from app import store

with store.connect() as conn:
    n = 0
    for (payload,) in conn.execute("SELECT payload FROM events").fetchall():
        for e in json.loads(payload).get("entry", []):
            for ch in e.get("changes", []):
                v = ch.get("value", {})
                for m in v.get("messages", []) + v.get("message_echoes", []):
                    t = m.get("type")
                    if t in ("audio", "image", "video", "document", "sticker") and m.get(t, {}).get("id"):
                        cur = conn.execute(
                            "UPDATE messages SET media_id = ?, media_mime = ? WHERE wa_message_id = ? AND media_id IS NULL",
                            (m[t]["id"], m[t].get("mime_type"), m.get("id")))
                        n += cur.rowcount
    print("backfilled", n)
