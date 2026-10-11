"""Puts messages lost before 2026-10-11 into the desk: Meta sent them without a phone number (only a business-scoped
user ID), the engine could not save them. Reads them back from wa_events; no bot reply is sent.

python -m engine.backfill_bsuid --days 14 [--dry-run]
"""
import argparse
import json
import logging
from datetime import datetime, timezone

from engine import db, tenant as tenants
from engine.main import MEDIA_TYPES, contact_for, save_message, sender_of

log = logging.getLogger("backfill_bsuid")


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    p = argparse.ArgumentParser()
    p.add_argument("--days", type=int, default=14)
    p.add_argument("--dry-run", action="store_true")
    a = p.parse_args()
    events = db.all_rows(
        """SELECT payload FROM wa_events WHERE company_id IS NOT NULL AND payload->>'field' = 'messages'
             AND received_at > now() - make_interval(days => %s) AND payload::text LIKE '%%from_user_id%%'
           ORDER BY id""", (a.days,))
    saved, contacts = 0, set()
    for e in events:
        payload = e["payload"] if isinstance(e["payload"], dict) else json.loads(e["payload"])
        value = payload.get("value") or {}
        t = tenants.by_phone_number_id((value.get("metadata") or {}).get("phone_number_id") or "")
        if not t:
            continue
        for m in value.get("messages", []):
            if m.get("from"):
                continue  # had a number: saved at the time
            phone, uid, name, username = sender_of(m, value, False)
            if not uid or db.one("SELECT 1 FROM wa_messages WHERE wa_message_id = %s", (m.get("id"),)):
                continue
            if a.dry_run:
                saved += 1
                contacts.add(uid)
                continue
            c = contact_for(t, phone, name, uid, username)
            mtype = m.get("type")
            media = m.get(mtype) if mtype in MEDIA_TYPES else None
            body = (m.get("text") or {}).get("body") or (media or {}).get("caption") \
                or ((m.get("button") or {}).get("text") if mtype == "button" else None)
            at = datetime.fromtimestamp(int(m.get("timestamp") or 0), timezone.utc).replace(tzinfo=None) \
                if m.get("timestamp") else None
            if save_message(t, c["id"], m.get("id"), "in", "customer", mtype, body, media, at=at):
                saved += 1
                contacts.add(c["id"])
    if not a.dry_run:
        for cid in contacts:  # contact_for stamped now(); the chat list sorts by the real last message
            db.execute("""UPDATE wa_contacts SET last_message_at = (SELECT max(created_at) FROM wa_messages
                            WHERE contact_id = %s) WHERE id = %s""", (cid, cid))
    log.info("%s %d messages for %d chats", "would save" if a.dry_run else "saved", saved, len(contacts))


if __name__ == "__main__":
    main()
