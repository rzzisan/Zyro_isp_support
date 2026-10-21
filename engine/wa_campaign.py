"""Desk WhatsApp template sends ("WhatsApp পাঠান"): sends the queue (wa_campaign_messages) with each company's own
token and number, and keeps each message's status from Meta's status webhooks. The panel only writes the queue
(App\\Support\\WaTemplates::queue) and wakes us (/internal/{c}/wa-campaigns/kick).

Safety: nothing goes out with ENGINE_DRY_RUN=1. A message caught in 'sending' when a run dies becomes 'unknown' and is
never sent again (no double message). An account or template error (token, locked account, payment, template paused
or deleted, spam limit ...) pauses that campaign with the reason and keeps the rest queued; staff resume it in the
panel. 10 failures of the same kind in a row pause it too. Meta's rate limits put the batch back and wait.
The sent message is copied into the customer's chat in the inbox (sender 'campaign') when the chat exists, otherwise
when the customer first writes (attach()), so the inbox isn't flooded with chats nobody answered.

Run every minute by zyro-wa-campaign.timer (the panel's kick is the fast path):
  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.wa_campaign [--company ID]
"""
import argparse
import fcntl
import logging
import os
import re
import threading
import time
from concurrent.futures import ThreadPoolExecutor

import httpx

from engine import db
from engine import tenant as tenants
from engine.whatsapp import GRAPH

log = logging.getLogger("zyro-wa-campaign")
DRY_RUN = os.environ.get("ENGINE_DRY_RUN", "1") == "1"
LOCK_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "data")
BATCH = 40               # claimed at a time
WORKERS = 4              # parallel requests to Meta (Cloud API allows 80 a second)
MAX_FAIL_STREAK = 10
RATE_WAIT = 30           # seconds to wait after a throughput limit

# Cloud API error codes (developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes)
PAUSE = {  # nothing more can go with this account / template: the campaign waits for staff
    0: "AuthException", 3: "API method permission", 10: "Permission denied", 190: "Access token expired or invalid",
    200: "Permission", 368: "Temporarily blocked for policy violations", 131031: "Business account locked",
    131042: "Payment problem on the WhatsApp Business account", 131045: "Number not registered",
    131048: "Spam rate limit hit (number quality)", 131057: "Account in maintenance mode",
    132000: "Template parameter count mismatch", 132001: "Template does not exist", 132005: "Template text too long",
    132007: "Template format character policy violated", 132012: "Template parameter format mismatch",
    132015: "Template is paused", 132016: "Template is disabled", 133010: "Phone number not registered",
}
WAIT = {4, 80007, 130429}  # throughput / rate limits: put back and wait


def _lock(company_id: int):
    os.makedirs(LOCK_DIR, exist_ok=True)
    f = open(os.path.join(LOCK_DIR, f"wa-campaign-{company_id}.lock"), "w")
    try:
        fcntl.flock(f, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        f.close()
        return None
    return f


def tenant_for(wa_account_id: int | None, company_id: int):
    row = db.one("SELECT phone_number_id FROM wa_accounts WHERE company_id = %s ORDER BY (id = %s) DESC, id LIMIT 1",
                 (company_id, wa_account_id or 0))
    return tenants.by_phone_number_id(row["phone_number_id"]) if row else None


def _claim(company_id: int) -> list[dict]:
    return db.all_rows(
        """UPDATE wa_campaign_messages SET status = 'sending', updated_at = now()
           WHERE id IN (SELECT m.id FROM wa_campaign_messages m JOIN wa_campaigns c ON c.id = m.campaign_id
                        WHERE m.company_id = %s AND m.status = 'queued' AND c.status IN ('queued', 'sending')
                        ORDER BY m.id LIMIT %s FOR UPDATE OF m SKIP LOCKED)
           RETURNING id, campaign_id, wa_number, params, text""", (company_id, BATCH))


def send_template(client: httpx.Client, t, to: str, name: str, lang: str, params: list[str]) -> dict:
    """One template message -> {ok, id} or {ok: False, code, error}."""
    tpl = {"name": name, "language": {"code": lang}}
    if params:
        tpl["components"] = [{"type": "body", "parameters": [{"type": "text", "text": p} for p in params]}]
    try:
        r = client.post(f"{GRAPH}/{t.phone_number_id}/messages", headers={"Authorization": f"Bearer {t.wa_token}"},
                        json={"messaging_product": "whatsapp", "to": to, "type": "template", "template": tpl})
    except httpx.HTTPError as e:
        return {"ok": False, "code": None, "error": f"{type(e).__name__}: {str(e)[:200]}"}
    try:
        data = r.json() if r.content else {}
    except ValueError:
        data = {}
    if r.status_code < 400 and "error" not in data and data.get("messages"):
        return {"ok": True, "id": data["messages"][0].get("id")}
    err = data.get("error") or {}
    detail = (err.get("error_data") or {}).get("details") or err.get("message") or r.text[:200]
    return {"ok": False, "code": err.get("code"), "error": str(detail)[:400].replace(t.wa_token, "***")}


def _to_chat(company_id: int, wa_account_id: int | None, msg_id: int) -> None:
    """Copy a sent message into the customer's existing chat (without moving the chat to the top)."""
    db.execute(
        """WITH m AS (SELECT w.id, w.wa_message_id, w.text, w.status, w.sent_at, c.id AS contact_id
                      FROM wa_campaign_messages w JOIN wa_contacts c ON c.company_id = w.company_id AND c.wa_number = w.wa_number
                      WHERE w.id = %s AND w.wa_message_id IS NOT NULL AND w.chat_message_id IS NULL),
                ins AS (INSERT INTO wa_messages (company_id, contact_id, wa_account_id, wa_message_id, direction, sender,
                                                 type, body, status, created_at)
                        SELECT %s, m.contact_id, %s, m.wa_message_id, 'out', 'campaign', 'template', m.text, m.status,
                               coalesce(m.sent_at, now()) FROM m
                        ON CONFLICT (wa_message_id) DO NOTHING RETURNING id, wa_message_id)
           UPDATE wa_campaign_messages w SET chat_message_id = ins.id FROM ins WHERE w.wa_message_id = ins.wa_message_id""",
        (msg_id, company_id, wa_account_id))


def attach(company_id: int, contact: dict) -> None:
    """A customer wrote: their chat gets the campaign messages of the last 30 days it doesn't have yet."""
    try:
        db.execute(
            """WITH m AS (SELECT w.wa_message_id, w.text, w.status, w.sent_at, c.wa_account_id
                          FROM wa_campaign_messages w JOIN wa_campaigns c ON c.id = w.campaign_id
                          WHERE w.company_id = %s AND w.wa_number = %s AND w.chat_message_id IS NULL
                            AND w.wa_message_id IS NOT NULL AND w.sent_at > now() - interval '30 days'),
                    ins AS (INSERT INTO wa_messages (company_id, contact_id, wa_account_id, wa_message_id, direction, sender,
                                                     type, body, status, created_at)
                            SELECT %s, %s, m.wa_account_id, m.wa_message_id, 'out', 'campaign', 'template', m.text,
                                   m.status, m.sent_at FROM m
                            ON CONFLICT (wa_message_id) DO NOTHING RETURNING id, wa_message_id)
               UPDATE wa_campaign_messages w SET chat_message_id = ins.id FROM ins WHERE w.wa_message_id = ins.wa_message_id""",
            (company_id, contact["wa_number"], company_id, contact["id"]))
    except Exception:
        log.warning("could not attach campaign messages to chat %s", contact.get("id"), exc_info=True)


def _requeue(ids: list[int]) -> None:
    db.execute("UPDATE wa_campaign_messages SET status = 'queued', updated_at = now() WHERE id = ANY(%s) AND status = 'sending'",
               (ids,))


def _pause(campaign_id: int, reason: str) -> None:
    db.execute("""UPDATE wa_campaigns SET status = 'paused', error = %s, updated_at = now()
                  WHERE id = %s AND status IN ('queued', 'sending')""", (reason[:500], campaign_id))
    log.error("wa campaign %s paused: %s", campaign_id, reason[:200])


def refresh(company_id: int, campaign_id: int | None = None) -> None:
    db.execute(
        """UPDATE wa_campaigns c SET sent = x.sent, delivered = x.delivered, read = x.read, failed = x.failed,
               status = CASE WHEN c.status IN ('cancelled', 'paused') THEN c.status
                             WHEN x.open = 0 THEN 'done'
                             WHEN x.sent + x.failed > 0 THEN 'sending' ELSE c.status END,
               started_at = CASE WHEN x.sent + x.failed > 0 THEN coalesce(c.started_at, now()) ELSE c.started_at END,
               finished_at = CASE WHEN x.open = 0 AND c.status NOT IN ('cancelled', 'paused') THEN coalesce(c.finished_at, now())
                                  ELSE c.finished_at END,
               updated_at = now()
           FROM (SELECT campaign_id,
                        count(*) FILTER (WHERE status IN ('sent', 'delivered', 'read')) AS sent,
                        count(*) FILTER (WHERE status IN ('delivered', 'read')) AS delivered,
                        count(*) FILTER (WHERE status = 'read') AS read,
                        count(*) FILTER (WHERE status IN ('failed', 'unknown')) AS failed,
                        count(*) FILTER (WHERE status IN ('queued', 'sending')) AS open
                 FROM wa_campaign_messages WHERE company_id = %s AND (%s::bigint IS NULL OR campaign_id = %s)
                 GROUP BY campaign_id) x
           WHERE c.id = x.campaign_id AND c.company_id = %s""",
        (company_id, campaign_id, campaign_id, company_id))


def send_queue(company_id: int) -> dict:
    """Sends everything queued for one company (one runner per company at a time)."""
    if DRY_RUN:
        return {"stopped": "dry_run"}
    lock = _lock(company_id)
    if not lock:
        return {"running": True}
    stats = {"sent": 0, "failed": 0, "paused": []}
    try:
        # cut off by a restart: never resend, Meta may have taken it
        db.execute("""UPDATE wa_campaign_messages SET status = 'unknown', error = 'পাঠানোর সময় থেমে গিয়েছিল; আবার পাঠানো হয়নি',
                      updated_at = now() WHERE company_id = %s AND status = 'sending' AND updated_at < now() - interval '10 minutes'""",
                   (company_id,))
        streak: dict[int, tuple[int, str | None]] = {}
        campaigns: dict[int, dict] = {}
        with httpx.Client(timeout=30) as client:
            while True:
                batch = _claim(company_id)
                if not batch:
                    break
                for cid in {m["campaign_id"] for m in batch} - campaigns.keys():
                    campaigns[cid] = db.one("SELECT id, wa_account_id, template_name, template_language FROM wa_campaigns WHERE id = %s", (cid,))
                tenants_by = {cid: tenant_for(c["wa_account_id"], company_id) for cid, c in campaigns.items()}
                back: list[int] = []
                paused: set[int] = set()
                wait = False

                def one(m):
                    c, t = campaigns[m["campaign_id"]], tenants_by[m["campaign_id"]]
                    if not t:
                        return m, {"ok": False, "code": "no_number", "error": "কোম্পানির WhatsApp নম্বর পাওয়া যায়নি"}
                    return m, send_template(client, t, m["wa_number"], c["template_name"], c["template_language"],
                                            list(m["params"] or []))

                with ThreadPoolExecutor(WORKERS) as pool:
                    results = list(pool.map(one, batch))
                for m, res in results:
                    cid = m["campaign_id"]
                    if cid in paused:
                        back.append(m["id"])
                        continue
                    if res["ok"]:
                        db.execute("""UPDATE wa_campaign_messages SET status = 'sent', wa_message_id = %s, error = NULL,
                                      error_code = NULL, sent_at = now(), updated_at = now() WHERE id = %s""", (res["id"], m["id"]))
                        _to_chat(company_id, campaigns[cid]["wa_account_id"], m["id"])
                        stats["sent"] += 1
                        streak[cid] = (0, None)
                        continue
                    code = res.get("code")
                    if code in WAIT:
                        back.append(m["id"])
                        wait = True
                    elif code in PAUSE or code == "no_number":
                        back.append(m["id"])
                        paused.add(cid)
                        stats["paused"].append(cid)
                        _pause(cid, f"Meta: #{code} {PAUSE.get(code, '')} — {res.get('error') or ''}".strip(" —"))
                    else:
                        db.execute("""UPDATE wa_campaign_messages SET status = 'failed', error_code = %s, error = %s,
                                      updated_at = now() WHERE id = %s""", (str(code) if code is not None else None, res.get("error"), m["id"]))
                        stats["failed"] += 1
                        kind = f"{code}:{re.sub(r'[0-9]+', '#', (res.get('error') or '')[:60])}"
                        n, last = streak.get(cid, (0, None))
                        n = n + 1 if kind == last else 1
                        streak[cid] = (n, kind)
                        if n >= MAX_FAIL_STREAK:
                            paused.add(cid)
                            _pause(cid, f"পরপর {n} বার একই কারণে ব্যর্থ: #{code} {res.get('error') or ''}")
                if back:
                    _requeue(back)
                refresh(company_id)
                if wait:
                    time.sleep(RATE_WAIT)
        refresh(company_id)
    finally:
        fcntl.flock(lock, fcntl.LOCK_UN)
        lock.close()
    if stats["sent"] or stats["failed"] or stats["paused"]:
        log.info("company %s: wa campaign %s", company_id, stats)
    return stats


_ORDER = {"sent": 1, "delivered": 2, "read": 3}


def on_status(company_id: int, s: dict) -> None:
    """A status webhook (sent / delivered / read / failed) for a message we sent; older statuses never win."""
    st, wid = s.get("status"), s.get("id")
    if st not in ("sent", "delivered", "read", "failed") or not wid:
        return
    err = (s.get("errors") or [{}])[0]
    detail = (err.get("error_data") or {}).get("details") or err.get("message") or err.get("title")
    try:
        ts = float(s.get("timestamp") or time.time())
    except ValueError:
        ts = time.time()
    row = db.execute(
        """UPDATE wa_campaign_messages SET
               status = CASE WHEN %(st)s = 'failed' THEN 'failed'
                             WHEN status IN ('failed', 'read') THEN status
                             WHEN status = 'delivered' AND %(st)s = 'sent' THEN status
                             ELSE %(st)s END,
               delivered_at = CASE WHEN %(st)s IN ('delivered', 'read')
                                   THEN coalesce(delivered_at, to_timestamp(%(ts)s) AT TIME ZONE 'UTC') ELSE delivered_at END,
               read_at = CASE WHEN %(st)s = 'read' THEN coalesce(read_at, to_timestamp(%(ts)s) AT TIME ZONE 'UTC') ELSE read_at END,
               error_code = CASE WHEN %(st)s = 'failed' THEN %(code)s ELSE error_code END,
               error = CASE WHEN %(st)s = 'failed' THEN %(err)s ELSE error END,
               updated_at = now()
           WHERE company_id = %(c)s AND wa_message_id = %(w)s RETURNING campaign_id""",
        {"st": st, "ts": ts, "code": str(err["code"]) if err.get("code") is not None else None,
         "err": (str(detail)[:400] if detail else None) if st == "failed" else None, "c": company_id, "w": wid})
    if row:
        refresh(company_id, row["campaign_id"])


_running: set[int] = set()
_running_lock = threading.Lock()


def kick(company_id: int) -> bool:
    """Start sending in a background thread of the engine (the panel calls this right after queueing)."""
    with _running_lock:
        if company_id in _running:
            return False
        _running.add(company_id)

    def run():
        try:
            send_queue(company_id)
        except Exception:
            log.exception("wa campaign send failed for company %s", company_id)
        finally:
            with _running_lock:
                _running.discard(company_id)

    threading.Thread(target=run, daemon=True, name=f"wa-campaign-{company_id}").start()
    return True


def run(company_id: int | None = None) -> None:
    rows = db.all_rows(
        """SELECT DISTINCT company_id FROM wa_campaign_messages WHERE status IN ('queued', 'sending')"""
        + (" AND company_id = %s" if company_id else ""), (company_id,) if company_id else ())
    for r in rows:
        try:
            send_queue(r["company_id"])
        except Exception:
            log.exception("wa campaign run failed for company %s", r["company_id"])


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    p = argparse.ArgumentParser()
    p.add_argument("--company", type=int)
    a = p.parse_args()
    run(a.company)


if __name__ == "__main__":
    main()
