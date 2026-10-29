"""Desk SMS: sends the queue (sms_messages) through each company's SMS gateways (sms_gateways: Khudebarta, Bulk SMS
Dhaka; adapters in engine/sms_gateways), reads balances and delivery reports. The panel only writes the queue
(App\\Support\\Sms::queue) and wakes us (/internal/{c}/sms/kick).

Main and fallback: SMS go through the company's main gateway (sms_settings.primary_gateway_id). When it refuses for an
account reason (keys, balance, IP whitelist, sender ID; nothing went out) it gets failed_at and the rest of the queue
goes through the fallback gateway (fallback_gateway_id), if there is one. Any other failure, a timeout included, stays
with that SMS and is never sent again through the other gateway, so nobody gets the same SMS twice. A failed gateway is
used again only after someone clears it in the panel (saving the gateway, "আবার চালু", or switching sending back on).

Safety: nothing goes out while the company's "SMS পাঠানো চালু" (sms_settings.enabled) is off. A message caught in
'sending' when a run dies becomes 'unknown' and is never sent again (no double SMS). When no gateway is left, sending
is switched off and the rest stays in the queue; 10 other failures of the same kind in a row do the same. Keys are never
logged; logs carry message ids only.

Run every minute by zyro-sms-send.timer (send + balance every 5 min + delivery reports):
  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.sms [--company ID] [--status-only]
"""
import argparse
import fcntl
import logging
import os
import re
import threading
import time
from concurrent.futures import ThreadPoolExecutor

from engine import db
from engine import sms_gateways as gw
from engine.sms_gateways.common import GatewayError, number

__all__ = ["GatewayError", "run", "kick", "send_now", "check_balance", "validate_client"]

log = logging.getLogger("zyro-sms")
LOCK_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "data")
BATCH = 100              # claimed at a time
WORKERS = 4              # parallel requests to the gateway
MAX_FAIL_STREAK = 10
MAX_UNREACHABLE = 3      # batches in a row the gateway could not be reached before the fallback takes over
UNREACHABLE_WAIT = 5     # seconds between those tries
BALANCE_EVERY = 300      # seconds


def settings(company_id: int) -> dict | None:
    s = db.one("SELECT * FROM sms_settings WHERE company_id = %s", (company_id,))
    return dict(s) if s else None


def _decrypted(g) -> dict | None:
    if not g:
        return None
    g = dict(g)
    for k in ("api_key", "secret_key"):
        g[k] = db.decrypt(g[k]) if g.get(k) else None
    return g


def gateway(company_id: int, gateway_id: int | None) -> dict | None:
    if not gateway_id:
        return None
    return _decrypted(db.one("SELECT * FROM sms_gateways WHERE id = %s AND company_id = %s", (gateway_id, company_id)))


def gateways(company_id: int) -> list[dict]:
    return [_decrypted(g) for g in db.all_rows("SELECT * FROM sms_gateways WHERE company_id = %s ORDER BY id", (company_id,))]


def usable(g: dict | None) -> bool:
    return gw.configured(g) and g.get("failed_at") is None


def active(company_id: int, s: dict | None = None) -> dict | None:
    """The gateway SMS go through now: the main one, else the fallback; None when neither can be used."""
    s = s or settings(company_id)
    if not s:
        return None
    for key in ("primary_gateway_id", "fallback_gateway_id"):
        g = gateway(company_id, s.get(key))
        if usable(g):
            return g
    return None


def chosen(company_id: int, gateway_id: int | None = None) -> dict:
    """The gateway a settings-page button means: the one given, else the one in use, else the main one."""
    s = settings(company_id) or {}
    g = gateway(company_id, gateway_id) if gateway_id else (active(company_id, s) or gateway(company_id, s.get("primary_gateway_id")))
    if not gw.configured(g):
        raise GatewayError("SMS গেটওয়ে সেট করা হয়নি")
    return g


def configured(s: dict | None) -> bool:
    """Some gateway of the company is set up (used by the timer to skip companies without SMS)."""
    return bool(s) and any(gw.configured(gateway(s["company_id"], s.get(k))) for k in ("primary_gateway_id", "fallback_gateway_id"))


# --- balance / validate --------------------------------------------------------------------------------------------
def _balance_saved(g: dict, value: float | None, sms: int | None) -> None:
    db.execute("""UPDATE sms_gateways SET balance = coalesce(%s, balance), balance_sms = %s, balance_checked_at = now(),
                  last_check_ok = true, last_error = CASE WHEN failed_at IS NULL THEN NULL ELSE last_error END,
                  updated_at = now() WHERE id = %s""", (value, sms, g["id"]))


def check_balance(company_id: int, gateway_id: int | None = None) -> dict:
    """The gateway's balance, saved in sms_gateways; raises GatewayError with the gateway's answer."""
    g = chosen(company_id, gateway_id)
    try:
        with gw.client(g) as c:
            got = gw.adapter(g).balance(g, c)
    except GatewayError as e:
        db.execute("""UPDATE sms_gateways SET balance_checked_at = now(), last_check_ok = false, last_error = %s,
                      updated_at = now() WHERE id = %s""", (str(e)[:500], g["id"]))
        raise GatewayError(f"ব্যালান্স পাওয়া যায়নি: {e}")
    _balance_saved(g, got["balance"], got.get("sms"))
    return {"balance": got["balance"], "sms": got.get("sms"), "gateway": g["id"]}


def validate_client(company_id: int, gateway_id: int | None = None) -> dict:
    """Are the keys accepted? No SMS is sent."""
    g = chosen(company_id, gateway_id)
    with gw.client(g) as c:
        r = gw.adapter(g).validate(g, c)
    if r.get("balance") is not None:
        _balance_saved(g, r["balance"], r.get("sms"))
    return {"client": r.get("client"), "balance": r.get("balance"), "sms": r.get("sms"), "gateway": g["id"]}


# --- sending -------------------------------------------------------------------------------------------------------
def _lock(company_id: int):
    os.makedirs(LOCK_DIR, exist_ok=True)
    f = open(os.path.join(LOCK_DIR, f"sms-send-{company_id}.lock"), "w")
    try:
        fcntl.flock(f, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        f.close()
        return None
    return f


def _claim(company_id: int) -> list[dict]:
    return db.all_rows(
        """UPDATE sms_messages SET status = 'sending', updated_at = now()
           WHERE id IN (SELECT m.id FROM sms_messages m
                        LEFT JOIN sms_campaigns c ON c.id = m.campaign_id
                        WHERE m.company_id = %s AND m.status = 'queued' AND coalesce(c.status, 'queued') <> 'cancelled'
                        ORDER BY m.id LIMIT %s FOR UPDATE OF m SKIP LOCKED)
           RETURNING id, campaign_id, mobile, text, parts""", (company_id, BATCH))


def _save(m: dict, res: dict, g: dict, message_id: str | None = None) -> None:
    """The gateway's answer for one SMS; a sent one also gets its cost (parts x the gateway's rate)."""
    text = res.get("text") or ""
    if res.get("description") and res["description"] not in text:
        text = f"{text} {res['description']}".strip()
    rate = number(g.get("rate"))
    cost = round((m.get("parts") or 1) * rate, 4) if res["ok"] and rate is not None else None
    db.execute(
        """UPDATE sms_messages SET status = %s, gateway_status = %s, gateway_text = %s, message_id = %s, error = %s,
               gateway_id = %s, provider = %s, cost = %s,
               sent_at = CASE WHEN %s THEN now() ELSE sent_at END, updated_at = now() WHERE id = %s""",
        ("sent" if res["ok"] else "failed", res.get("status"), text[:250] or None, message_id,
         None if res["ok"] else res.get("error"), g["id"], g["provider"], cost, res["ok"], m["id"]))


def _requeue(ids: list[int]) -> None:
    db.execute("UPDATE sms_messages SET status = 'queued', updated_at = now() WHERE id = ANY(%s) AND status = 'sending'", (ids,))


def refresh_campaigns(company_id: int) -> None:
    db.execute(
        """UPDATE sms_campaigns c SET sent = x.sent, failed = x.failed,
               status = CASE WHEN c.status = 'cancelled' THEN 'cancelled'
                             WHEN x.open = 0 THEN 'done'
                             WHEN x.sent + x.failed > 0 THEN 'sending' ELSE c.status END,
               started_at = CASE WHEN x.sent + x.failed > 0 THEN coalesce(c.started_at, now()) ELSE c.started_at END,
               finished_at = CASE WHEN x.open = 0 THEN coalesce(c.finished_at, now()) ELSE NULL END,
               updated_at = now()
           FROM (SELECT campaign_id,
                        count(*) FILTER (WHERE status IN ('sent', 'delivered')) AS sent,
                        count(*) FILTER (WHERE status IN ('failed', 'unknown')) AS failed,
                        count(*) FILTER (WHERE status IN ('queued', 'sending')) AS open
                 FROM sms_messages WHERE company_id = %s AND campaign_id IS NOT NULL GROUP BY campaign_id) x
           WHERE c.id = x.campaign_id AND c.company_id = %s AND c.status IN ('queued', 'sending')""",
        (company_id, company_id))


def _jobs(batch: list[dict], bulk: int) -> list[list[dict]]:
    """Messages with the same text go together (up to `bulk` numbers per request); the rest one by one."""
    groups: dict[str, list[dict]] = {}
    for m in batch:
        groups.setdefault(m["text"], []).append(m)
    return [g[i:i + bulk] for g in groups.values() for i in range(0, len(g), bulk)]


def _switch_off(company_id: int, reason: str) -> None:
    db.execute("UPDATE sms_settings SET enabled = false, last_error = %s, updated_at = now() WHERE company_id = %s",
               (reason[:500], company_id))
    log.error("company %s: sms sending switched off", company_id)


def _gateway_failed(g: dict, reason: str) -> None:
    """Refused for an account reason: not used again until cleared in the panel."""
    db.execute("""UPDATE sms_gateways SET failed_at = now(), last_check_ok = false, last_error = %s, updated_at = now()
                  WHERE id = %s""", (reason[:500], g["id"]))
    log.error("company %s: sms gateway %s (%s) stopped", g["company_id"], g["id"], g["provider"])


def send_queue(company_id: int) -> dict:
    """Sends everything queued for one company (one runner per company at a time)."""
    lock = _lock(company_id)
    if not lock:
        return {"running": True}
    stats = {"sent": 0, "failed": 0, "requests": 0, "stopped": None, "fallback": None}
    bulk: dict[int, int] = {}
    unreachable: dict[int, int] = {}
    try:
        # cut off by a restart: never resend, the gateway may have taken it
        db.execute("""UPDATE sms_messages SET status = 'unknown', error = 'পাঠানোর সময় থেমে গিয়েছিল; আবার পাঠানো হয়নি',
                      updated_at = now() WHERE company_id = %s AND status = 'sending' AND updated_at < now() - interval '10 minutes'""",
                   (company_id,))
        streak, kind = 0, None
        while not stats["stopped"]:
            s = settings(company_id)
            if not (s and s["enabled"]):
                stats["stopped"] = "disabled"
                break
            g = active(company_id, s)
            if not g:
                stats["stopped"] = "no gateway"
                if configured(s):          # every gateway is stopped: say so where the owner looks
                    _switch_off(company_id, "কোনো SMS গেটওয়ে চালু নেই (সব গেটওয়ে থেমে আছে) — পাঠানো বন্ধ, বাকি SMS অপেক্ষায় আছে")
                break
            ad = gw.adapter(g)
            batch = _claim(company_id)
            if not batch:
                break
            size = bulk.setdefault(g["id"], ad.BULK)
            jobs = _jobs(batch, size)
            with gw.client(g) as client, ThreadPoolExecutor(WORKERS) as pool:
                results = list(pool.map(lambda job: (job, ad.send(g, [m["mobile"] for m in job], job[0]["text"], client)), jobs))
            stats["requests"] += len(results)
            back: list[int] = []
            refused, down = None, 0
            for job, res in results:
                st = res.get("status")
                if res["ok"]:
                    ids = res["message_ids"] if len(res["message_ids"]) == len(job) else [None] * len(job)
                    for m, mid in zip(job, ids):
                        _save(m, res, g, mid)
                    stats["sent"] += len(job)
                    streak, kind = 0, None
                elif st and st == getattr(ad, "BULK_LIMIT", None) and len(job) > 1:   # smaller groups next time
                    back += [m["id"] for m in job]
                    bulk[g["id"]] = max(1, len(job) // 2)
                elif res.get("account"):                      # nothing went out; keep them queued
                    back += [m["id"] for m in job]
                    refused = refused or res
                elif res.get("unreachable"):                  # never reached the gateway; keep them queued
                    back += [m["id"] for m in job]
                    down += 1
                else:
                    for m in job:
                        _save(m, res, g)
                    stats["failed"] += len(job)
                    k = f"{g['id']}:{st}:{re.sub(r'[0-9]+', '#', (res.get('error') or '')[:60])}"
                    streak, kind = (streak + 1 if k == kind else 1), k
                    log.warning("sms %s failed: %s", [m["id"] for m in job], st)
            if back:
                _requeue(back)
            if down and down == len(results):
                unreachable[g["id"]] = unreachable.get(g["id"], 0) + 1
                if unreachable[g["id"]] >= MAX_UNREACHABLE:
                    other = next((x for x in (gateway(company_id, s.get(k)) for k in ("primary_gateway_id", "fallback_gateway_id"))
                                  if usable(x) and x["id"] != g["id"]), None)
                    if other:
                        refused = results[-1][1]
                    else:                                # no other gateway: wait for the next run, nothing is lost
                        stats["stopped"] = "unreachable"
                else:
                    time.sleep(UNREACHABLE_WAIT)
            elif not down:
                unreachable.pop(g["id"], None)
            if refused:
                name = g.get("label") or g["provider"]
                _gateway_failed(g, f"{name}: {refused.get('error') or refused.get('status')} — এই গেটওয়ে থামানো হয়েছে")
                nxt = active(company_id)
                if nxt:
                    stats["fallback"] = nxt["id"]
                    log.warning("company %s: sms now through fallback gateway %s", company_id, nxt["id"])
                    streak, kind = 0, None
                else:
                    stats["stopped"] = f"account {refused.get('status')}"
                    _switch_off(company_id, f"গেটওয়ে {name}: {refused.get('error') or refused.get('status')} — পাঠানো বন্ধ করা হয়েছে, বাকি SMS অপেক্ষায় আছে")
            refresh_campaigns(company_id)
            if streak >= MAX_FAIL_STREAK and not stats["stopped"]:
                stats["stopped"] = "failures"
                _switch_off(company_id, f"পরপর {streak} বার ব্যর্থ, পাঠানো বন্ধ করা হয়েছে: {(results[-1][1].get('error') or '')[:200]}")
        refresh_campaigns(company_id)
    finally:
        fcntl.flock(lock, fcntl.LOCK_UN)
        lock.close()
    if stats["sent"] or stats["failed"]:
        log.info("company %s: sms %s", company_id, stats)
    return stats


def send_now(company_id: int, message_id: int, gateway_id: int | None = None) -> dict:
    """The settings page's test SMS: this one queued message goes now through the gateway given (else the one in
    use), even while sending is switched off or that gateway is stopped."""
    g = chosen(company_id, gateway_id)
    m = db.one("""UPDATE sms_messages SET status = 'sending', updated_at = now()
                  WHERE id = %s AND company_id = %s AND status = 'queued' RETURNING id, mobile, text, parts""",
               (message_id, company_id))
    if not m:
        raise GatewayError("SMS-টা আর অপেক্ষায় নেই")
    with gw.client(g) as c:
        res = gw.adapter(g).send(g, [m["mobile"]], m["text"], c)
    mid = res["message_ids"][0] if res["message_ids"] else None
    _save(m, res, g, mid)
    refresh_campaigns(company_id)
    return {"ok": res["ok"], "status": res.get("status"), "text": res.get("text"),
            "description": res.get("description"), "error": res.get("error"), "message_id": mid,
            "gateway": g["id"], "provider": g["provider"]}


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
            log.exception("sms send failed for company %s", company_id)
        finally:
            with _running_lock:
                _running.discard(company_id)

    threading.Thread(target=run, daemon=True, name=f"sms-{company_id}").start()
    return True


# --- delivery reports ----------------------------------------------------------------------------------------------
def check_status(company_id: int, limit: int = 300) -> dict:
    """Asks each gateway about the SMS it took in the last 48 hours, at that gateway's pace (FIRST_STATUS_AFTER,
    then every STATUS_EVERY seconds) until delivered / failed / final."""
    s = settings(company_id) or {}
    done = 0
    for g in gateways(company_id):
        if not gw.configured(g):
            continue
        ad = gw.adapter(g)
        rows = db.all_rows(
            """SELECT id, message_id FROM sms_messages WHERE company_id = %s AND status = 'sent' AND message_id IS NOT NULL
                   AND NOT status_final AND (gateway_id = %s OR (gateway_id IS NULL AND %s))
                   AND sent_at > now() - interval '48 hours' AND sent_at < now() - make_interval(secs => %s)
                   AND (status_checked_at IS NULL OR status_checked_at < now() - make_interval(secs => %s))
               ORDER BY sent_at LIMIT %s""",
            (company_id, g["id"], g["id"] == s.get("primary_gateway_id"), ad.FIRST_STATUS_AFTER, ad.STATUS_EVERY, limit))
        if not rows:
            continue
        with gw.client(g) as c:
            for r in rows:
                st = ad.status(g, r["message_id"], c)
                if st is None:
                    continue
                if st["stop"]:
                    log.info("company %s: delivery query refused by gateway %s (%s)", company_id, g["id"], st["text"])
                    break
                state, text = st["state"], st["text"] or ""
                db.execute(
                    """UPDATE sms_messages SET status = coalesce(%s, status), gateway_text = coalesce(nullif(%s, ''), gateway_text),
                           delivered_at = CASE WHEN %s = 'delivered'
                               THEN coalesce(to_timestamp(%s) AT TIME ZONE 'UTC', now()) ELSE delivered_at END,
                           error = CASE WHEN %s = 'failed' THEN 'গেটওয়ে: পৌঁছায়নি (' || %s || ')' ELSE error END,
                           status_final = status_final OR %s, status_checked_at = now(), updated_at = now() WHERE id = %s""",
                    (state, text, state, st["at"], state, text, st["final"], r["id"]))
                done += 1
    if done:
        refresh_campaigns(company_id)
    return {"checked": done}


# --- timer ---------------------------------------------------------------------------------------------------------
def companies(company_id: int | None = None) -> list[int]:
    rows = db.all_rows("SELECT company_id FROM sms_settings" + (" WHERE company_id = %s" if company_id else ""),
                       (company_id,) if company_id else ())
    return [r["company_id"] for r in rows]


def balance_due(company_id: int) -> list[int]:
    """Gateways whose balance is older than BALANCE_EVERY (stopped ones too, so the page shows when they work again)."""
    return [r["id"] for r in db.all_rows(
        """SELECT id FROM sms_gateways WHERE company_id = %s
               AND (balance_checked_at IS NULL OR balance_checked_at < now() - make_interval(secs => %s)) ORDER BY id""",
        (company_id, BALANCE_EVERY))]


def run(company_id: int | None = None, status_only: bool = False) -> None:
    for cid in companies(company_id):
        try:
            if not configured(settings(cid)):
                continue
            if not status_only:
                send_queue(cid)
                for gid in balance_due(cid):
                    try:
                        check_balance(cid, gid)
                    except GatewayError as e:
                        log.info("company %s gateway %s: %s", cid, gid, e)
            check_status(cid)
        except Exception:
            log.exception("sms run failed for company %s", cid)


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    logging.getLogger("httpx").setLevel(logging.WARNING)
    p = argparse.ArgumentParser()
    p.add_argument("--company", type=int)
    p.add_argument("--status-only", action="store_true")
    a = p.parse_args()
    run(a.company, a.status_only)


if __name__ == "__main__":
    main()
