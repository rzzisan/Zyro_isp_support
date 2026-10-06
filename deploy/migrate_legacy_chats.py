"""Copy Century Link's conversations from the old bot (SQLite) into the engine's PostgreSQL tables.

Safe to run again: messages are keyed by WhatsApp message id. Usage:
  set -a; . engine/.env; set +a
  PYTHONPATH=. .venv/bin/python deploy/migrate_legacy_chats.py century-link-network 1309537728917134 [--reset]
--reset first removes the company's engine-side chats (e.g. dry-run replay test data).
"""
import json
import sqlite3
import sys
from datetime import datetime

from engine import db

slug, pnid = sys.argv[1], sys.argv[2]
reset = "--reset" in sys.argv

company = db.one("SELECT id FROM companies WHERE slug = %s", (slug,))
account = db.one("SELECT id FROM wa_accounts WHERE phone_number_id = %s AND company_id = %s", (pnid, company["id"]))
cid, aid = company["id"], account["id"]

if reset:
    for t in ("wa_drafts", "wa_messages", "wa_contacts", "wa_events"):
        db.execute(f"DELETE FROM {t} WHERE company_id = %s", (cid,))

old = sqlite3.connect("data/messages.db")
old.row_factory = sqlite3.Row
numbers = [r[0] for r in old.execute("SELECT DISTINCT from_number FROM messages WHERE phone_number_id = ?", (pnid,))]
start = old.execute("SELECT min(received_at) FROM messages WHERE phone_number_id = ?", (pnid,)).fetchone()[0]
campaign = [r[0] for r in old.execute("SELECT DISTINCT from_number FROM messages WHERE phone_number_id LIKE 'campaign:%'")]
settings = dict(old.execute("SELECT key, value FROM settings").fetchall())


def ts(s: str) -> datetime:
    return datetime.fromisoformat(s)


contacts = 0
messages = 0
for wa in sorted(set(numbers) | set(campaign)):
    rows = old.execute(
        """SELECT * FROM messages WHERE from_number = ? AND (
               phone_number_id = ? OR phone_number_id LIKE 'campaign:%' OR phone_number_id LIKE 'dashboard:%'
               OR (phone_number_id IS NULL AND echo = 1 AND received_at >= ?))
           ORDER BY received_at""", (wa, pnid, start)).fetchall()
    if not rows:
        continue
    name = next((r["contact_name"] for r in reversed(rows) if r["contact_name"]), None)
    state = json.loads(settings[f"state:{wa}"]) if settings.get(f"state:{wa}") else None
    pause = settings.get(f"pause:{wa}")
    c = db.execute(
        """INSERT INTO wa_contacts (company_id, wa_number, name, customer_id, ident_state, bot_paused, bot_paused_until,
                                    last_message_at, created_at, updated_at)
           VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, now())
           ON CONFLICT (company_id, wa_number) DO UPDATE SET
             name = COALESCE(wa_contacts.name, EXCLUDED.name),
             customer_id = COALESCE(wa_contacts.customer_id, EXCLUDED.customer_id),
             ident_state = COALESCE(wa_contacts.ident_state, EXCLUDED.ident_state),
             last_message_at = GREATEST(wa_contacts.last_message_at, EXCLUDED.last_message_at)
           RETURNING id""",
        (cid, wa, name, (state or {}).get("customer_id") if (state or {}).get("stage") == "ok" else None,
         json.dumps(state, ensure_ascii=False) if state else None, pause == "on",
         ts(pause) if pause and pause != "on" else None, ts(rows[-1]["received_at"]), ts(rows[0]["received_at"])),
    )
    contacts += 1
    for r in rows:
        p = r["phone_number_id"] or ""
        sender = "customer" if not r["echo"] else ("campaign" if p.startswith("campaign:") else
                                                   "staff" if p.startswith("dashboard:") else "bot")
        done = db.execute(
            """INSERT INTO wa_messages (company_id, contact_id, wa_account_id, wa_message_id, direction, sender, type, body,
                                        media_id, media_mime, status, created_at)
               VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
               ON CONFLICT (wa_message_id) DO NOTHING RETURNING id""",
            (cid, c["id"], aid, r["wa_message_id"], "out" if r["echo"] else "in", sender, r["msg_type"] or "text",
             r["body"], r["media_id"], r["media_mime"], "sent" if r["echo"] else None, ts(r["received_at"])),
        )
        messages += 1 if done else 0

print(f"company {slug}: {contacts} contacts, {messages} new messages")
