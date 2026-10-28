"""Billing's Expire Date (BillingLastDate, a day of the month) moved to the day the customer paid, after the line was
off for a while and a (nearly) full bill turned it back on. Zisan's rule (2026-10-28):

  * the line was disabled for at least expire_min_off_hours (24) and then enabled again;
  * a receipt between the disable day and the day after the enable paid at least expire_min_paid_pct (85) percent of
    its payable (paid + discount, any source: Desk, billing's Daily Bill, online gateway);
  * the new day is that receipt's Bangladesh date, at most expire_max_day (28).
  * Only for the one-time bulk list: Home customers get 5 days more when they paid on or before the 20th, up to the
    20th (paid on the 21st or later: the pay day).

bulk: the "মেয়াদ বদল" page builds the list (preview; the owner ticks customers off = skipped) and runs it (queued ->
done/failed). auto: the hourly timer (zyro-expire-date.timer, only while billing_connections.expire_auto is on) looks
at customers paid in the last three days, re-reads their line log and receipts from billing, and sets the day.

Writing goes through the customer edit form (ISPDigital.set_expire_day): every other field is sent back as billing
showed it, "Mikrotik Impact" off. After each write the change log is read back; anything but ExpireDate changing stops
the whole run. Each row keeps the day it replaced (rollback).
  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.expire_dates --preview [--company ID]
  ... --auto    the timer: auto changes for every company with the switch on
"""
import argparse
import logging
import threading
from datetime import date, datetime, timedelta, timezone
from zoneinfo import ZoneInfo

from engine import customer_history, db, tenant as tenants
from engine.ticket_sync import companies

log = logging.getLogger("zyro-expire-dates")
DHAKA = ZoneInfo("Asia/Dhaka")
BULK_DAYS = 35            # bulk list: lines that came back on in the last 35 days
AUTO_DAYS = 3             # timer: payments of the last three days (cash receipts appear once approved in billing)
HOME_PLUS, HOME_CAP = 5, 20
# a change log row may carry these besides ExpireDate without anything real having changed
WRITE_OK = {"ExpireDate", "BillingLastDate"}
# billing keeps its stored Speed at the block profile when an online payment turns the line back on, while the edit
# form (and MikroTik) has the real profile; any save, by staff or by us, writes the real one back. Zisan OK'd that
# one change (2026-10-28); any other Speed change still stops the run.
BLOCK_PROFILES = {"blocked-profile", "duedisabled"}


class StopRun(RuntimeError):
    """Something other than the Expire Date changed in billing: nothing more is written until a person looks."""


def settings(company_id: int) -> dict:
    r = db.one("""SELECT expire_auto, expire_min_off_hours, expire_min_paid_pct, expire_max_day
                  FROM billing_connections WHERE company_id = %s""", (company_id,)) or {}
    return {"auto": bool(r.get("expire_auto")), "off_hours": int(r.get("expire_min_off_hours") or 24),
            "pct": int(r.get("expire_min_paid_pct") or 85), "max_day": int(r.get("expire_max_day") or 28)}


def bd_date(utc_naive: datetime) -> date:
    return utc_naive.replace(tzinfo=timezone.utc).astimezone(DHAKA).date()


def episodes(logs: list[dict], min_hours: int) -> list[tuple[datetime, datetime, str | None]]:
    """(off, on, enable remark) for every disable -> enable that lasted at least min_hours, oldest first.
    The first disable after the line was on counts (a second disable while already off doesn't restart it)."""
    out, off = [], None
    for r in sorted((r for r in logs if r["changed_at"]), key=lambda r: r["changed_at"]):
        if r["action"] == "disable":
            off = off or r["changed_at"]
        elif r["action"] == "enable":
            if off and (r["changed_at"] - off).total_seconds() >= min_hours * 3600:
                out.append((off, r["changed_at"], r.get("remarks")))
            off = None
    return out


def paid_pct(p: dict) -> int | None:
    payable = float(p["payable"] or 0)
    if payable <= 0:
        return None
    return int((float(p["paid"] or 0) + float(p["discount"] or 0)) * 100 // payable)


def deciding_payment(payments: list[dict], off: datetime, on: datetime, pct: int) -> dict | None:
    """The last receipt from the disable day to the day after the enable that paid at least pct percent."""
    first, last = bd_date(off), bd_date(on) + timedelta(days=1)
    full = [p for p in payments if p["received_on"] and first <= p["received_on"] <= last
            and (paid_pct(p) or 0) >= pct]
    return max(full, key=lambda p: (p["received_on"], p["id"])) if full else None


def new_day(paid_on: date, customer_type: str | None, max_day: int, home_rule: bool) -> tuple[int, str]:
    d = paid_on.day
    if home_rule and (customer_type or "").strip().lower().startswith("home") and d <= HOME_CAP:
        return min(d + HOME_PLUS, HOME_CAP), "home_cap20" if d + HOME_PLUS > HOME_CAP else "home_plus5"
    return (max_day, "cap_max") if d > max_day else (d, "pay_day")


def evaluate(c: dict, logs: list[dict], payments: list[dict], s: dict, on_after: datetime,
             home_rule: bool) -> dict | None:
    """The change for one customer from their latest qualifying episode whose enable is after on_after, or None."""
    for off, on, remark in reversed(episodes(logs, s["off_hours"])):
        if on < on_after:
            return None
        p = deciding_payment(payments, off, on, s["pct"])
        if not p:
            continue
        day, rule = new_day(p["received_on"], c["customer_type"], s["max_day"], home_rule)
        return {"customer_header_id": c["header_id"], "customer_id": c["customer_id"], "name": c["name"],
                "customer_type": c["customer_type"], "rule": rule, "old_day": c["bill_day"], "new_day": day,
                "off_at": off, "on_at": on, "off_hours": int((on - off).total_seconds() // 3600),
                "on_remark": (remark or "")[:250] or None, "paid_on": p["received_on"],
                "paid": float(p["paid"] or 0) + float(p["discount"] or 0), "payable": float(p["payable"] or 0),
                "paid_pct": paid_pct(p), "method": p["method"], "is_online": bool(p["is_online"])}
    return None


ELIGIBLE = """company_id = %s AND NOT is_left AND gone_at IS NULL AND NOT is_vip AND status = 'Active'"""


def _load(company_id: int, header_ids: list[int] | None, since: datetime) -> tuple[list, dict, dict]:
    only = " AND header_id = ANY(%s)" if header_ids is not None else ""
    args = (company_id, header_ids) if header_ids is not None else (company_id,)
    customers = db.all_rows(f"""SELECT header_id, customer_id, name, customer_type, bill_day FROM billing_customers
                                WHERE {ELIGIBLE}{only}""", args)
    ids = [c["header_id"] for c in customers]
    logs, pays = {}, {}
    for r in db.all_rows("""SELECT customer_header_id, changed_at, action, remarks FROM billing_customer_line_logs
                            WHERE company_id = %s AND customer_header_id = ANY(%s) AND changed_at >= %s""",
                         (company_id, ids, since)):
        logs.setdefault(r["customer_header_id"], []).append(r)
    for r in db.all_rows("""SELECT id, customer_header_id, received_on, payable, paid, discount, method, is_online
                            FROM billing_customer_payments WHERE company_id = %s AND customer_header_id = ANY(%s)
                              AND received_on >= %s AND NOT is_canceled AND removed_at IS NULL AND paid > 0""",
                         (company_id, ids, since.date())):
        pays.setdefault(r["customer_header_id"], []).append(r)
    return customers, logs, pays


COLS = ("customer_header_id", "customer_id", "name", "customer_type", "rule", "old_day", "new_day", "off_at", "on_at",
        "off_hours", "on_remark", "paid_on", "paid", "payable", "paid_pct", "method", "is_online")


def _insert(cur, company_id: int, kind: str, status: str, ch: dict) -> None:
    cur.execute(f"""INSERT INTO expire_date_changes (company_id, kind, status, {", ".join(COLS)}, created_at, updated_at)
                    VALUES (%s, %s, %s, {", ".join(["%s"] * len(COLS))}, now(), now())""",
                (company_id, kind, status, *[ch[k] for k in COLS]))


# --- bulk: the one-time list ---------------------------------------------------------------------------------------
def preview(company_id: int) -> dict:
    """Build the bulk list again from the desk's copy of billing. Customers the owner ticked off stay ticked off;
    rows already written (done/failed/rolled back) are kept as they are."""
    s = settings(company_id)
    now = datetime.now(timezone.utc).replace(tzinfo=None)
    on_after = now - timedelta(days=BULK_DAYS)
    customers, logs, pays = _load(company_id, None, on_after - timedelta(days=60))
    changes = []
    for c in customers:
        ch = evaluate(c, logs.get(c["header_id"], []), pays.get(c["header_id"], []), s, on_after, home_rule=True)
        if ch and ch["new_day"] != c["bill_day"]:
            changes.append(ch)
    _recover(company_id)
    with db.conn() as conn, conn.transaction(), conn.cursor() as cur:
        if conn.execute("""SELECT 1 FROM expire_date_changes WHERE company_id = %s AND kind = 'bulk'
                           AND status IN ('queued', 'running') LIMIT 1""", (company_id,)).fetchone():
            raise RuntimeError("চালানো চলছে, শেষ হলে আবার তালিকা বানান")
        skipped = {r["customer_header_id"] for r in conn.execute(
            """SELECT customer_header_id FROM expire_date_changes WHERE company_id = %s AND kind = 'bulk'
               AND status = 'skipped'""", (company_id,)).fetchall()}
        handled = {r["customer_header_id"] for r in conn.execute(
            """SELECT customer_header_id FROM expire_date_changes WHERE company_id = %s AND kind = 'bulk'
               AND status IN ('done', 'failed', 'rolled_back')""", (company_id,)).fetchall()}
        cur.execute("DELETE FROM expire_date_changes WHERE company_id = %s AND kind = 'bulk' AND status IN ('preview', 'skipped')",
                    (company_id,))
        for ch in changes:
            if ch["customer_header_id"] not in handled:
                _insert(cur, company_id, "bulk", "skipped" if ch["customer_header_id"] in skipped else "preview", ch)
    n = len([c for c in changes if c["customer_header_id"] not in handled])
    log.info("company %s: bulk list %s customers (%s already written)", company_id, n, len(changes) - n)
    return {"rows": n, "already_done": len(changes) - n}


# --- writing -------------------------------------------------------------------------------------------------------
_running: set[int] = set()
_running_lock = threading.Lock()


def _speed_fix_only(change, posted: str | None) -> bool:
    try:
        old, new = change
    except (TypeError, ValueError):
        return False
    return (str(old or "").strip().lower() in BLOCK_PROFILES and bool(posted) and str(new or "").strip() == posted
            and posted.strip().lower() not in BLOCK_PROFILES)


def _verify(api, company_id: int, h: int, since: datetime, day: int, posted_speed: str | None = None) -> list[str]:
    """Re-read the change log and the line log: any change after `since` other than ExpireDate, or the line being
    switched on or off, stops the run. Returns notes on the one allowed extra change (a stale block-profile Speed)."""
    notes: list[str] = []
    customer_history.fetch_changes(api, company_id, h)
    customer_history.fetch_line(api, company_id, h)
    last = db.one("""SELECT snapshot->>'ExpireDate' AS day FROM billing_customer_change_logs WHERE company_id = %s
                     AND customer_header_id = %s ORDER BY changed_at DESC NULLS LAST, id DESC LIMIT 1""", (company_id, h))
    if not last or str(last["day"] or "").strip() != str(int(day)):
        raise StopRun(f"বিলিং-এ Expire Date {day} হয়নি (এখন {last and last['day']})")
    line = db.one("""SELECT action, remarks FROM billing_customer_line_logs WHERE company_id = %s
                     AND customer_header_id = %s AND changed_at >= %s ORDER BY changed_at DESC LIMIT 1""",
                  (company_id, h, since - timedelta(minutes=2)))
    if line:
        raise StopRun(f"লাইনে বদল হয়েছে: {line['action']} ({(line['remarks'] or '')[:80]})")
    for r in db.all_rows("""SELECT changed FROM billing_customer_change_logs WHERE company_id = %s
                            AND customer_header_id = %s AND changed_at >= %s AND changed IS NOT NULL""",
                         (company_id, h, since - timedelta(minutes=2))):
        changed = r["changed"] or {}
        other = set(changed) - WRITE_OK
        if "Speed" in other and _speed_fix_only(changed["Speed"], posted_speed):
            other.discard("Speed")
            notes.append(f"Speed-এর লেখা ঠিক হলো: {changed['Speed'][0]} → {changed['Speed'][1]}")
        if other:
            raise StopRun("Expire Date ছাড়াও বদলেছে: " + ", ".join(sorted(other)))
    return notes


def write_one(t, api, row: dict, user_id: int | None) -> None:
    """Set one customer's day in billing and mark the row done (or failed). StopRun is passed on."""
    h = row["customer_header_id"]
    db.execute("UPDATE expire_date_changes SET status = 'running', error = NULL, updated_at = now() WHERE id = %s", (row["id"],))
    started = datetime.now(timezone.utc).replace(tzinfo=None)
    try:
        res = api.set_expire_day(h, row["new_day"], expect=_expected(t.company_id, h))
        notes = _verify(api, t.company_id, h, started, row["new_day"], res["speed"])
    except StopRun as e:
        db.execute("""UPDATE expire_date_changes SET status = 'failed', error = %s, updated_at = now() WHERE id = %s""",
                   (str(e)[:500], row["id"]))
        raise
    except Exception as e:
        log.warning("expire date %s/%s: %s", t.company_id, h, e)
        db.execute("""UPDATE expire_date_changes SET status = 'failed', error = %s, updated_at = now() WHERE id = %s""",
                   (str(e)[:500], row["id"]))
        return
    # already that day in billing (e.g. an earlier try saved it before stopping): keep the day it replaced
    before = res["before"] if res["saved"] else None
    db.execute("""UPDATE expire_date_changes SET status = 'done', old_day = COALESCE(%s, old_day), applied_by = %s,
                  applied_at = now(), error = %s, updated_at = now() WHERE id = %s""",
               (before, user_id, "; ".join(notes) or None, row["id"]))
    db.execute("UPDATE billing_customers SET bill_day = %s, updated_at = now() WHERE company_id = %s AND header_id = %s",
               (row["new_day"], t.company_id, h))


def _expected(company_id: int, h: int) -> dict:
    """What the desk's copy says the edit form must show; a mismatch means the form isn't what we think it is."""
    r = db.one("""SELECT username, zone, subzone, box, package, monthly_bill FROM billing_customers
                  WHERE company_id = %s AND header_id = %s""", (company_id, h)) or {}
    return {k: v for k, v in r.items() if v not in (None, "")}


def _rollback_one(t, api, row: dict) -> None:
    h = row["customer_header_id"]
    started = datetime.now(timezone.utc).replace(tzinfo=None)
    res = api.set_expire_day(h, row["old_day"], expect=_expected(t.company_id, h))
    _verify(api, t.company_id, h, started, row["old_day"], res["speed"])
    db.execute("""UPDATE expire_date_changes SET status = 'rolled_back', rolled_back_at = now(), updated_at = now()
                  WHERE id = %s""", (row["id"],))
    db.execute("UPDATE billing_customers SET bill_day = %s, updated_at = now() WHERE company_id = %s AND header_id = %s",
               (row["old_day"], t.company_id, h))


def _run(t, ids: list[int] | None, user_id: int | None, rollback: bool = False) -> None:
    api = tenants.billing(t)
    if rollback:
        where, args = "id = ANY(%s) AND status = 'done' AND old_day IS NOT NULL", [ids]
    elif ids:
        where, args = "id = ANY(%s) AND status IN ('preview', 'skipped', 'failed')", [ids]
    else:
        where, args = "kind = 'bulk' AND status = 'queued'", []
    rows = db.all_rows(f"""SELECT id, customer_header_id, new_day, old_day FROM expire_date_changes
                           WHERE company_id = %s AND {where} ORDER BY id""", (t.company_id, *args))
    for row in rows:
        try:
            if rollback:
                _rollback_one(t, api, row)
            else:
                write_one(t, api, row, user_id)
        except StopRun as e:
            log.error("company %s: expire date run stopped at customer %s: %s", t.company_id, row["customer_header_id"], e)
            db.execute("""UPDATE expire_date_changes SET error = %s, updated_at = now() WHERE id = %s""",
                       (str(e)[:500], row["id"]))
            db.execute("""UPDATE expire_date_changes SET status = 'preview', updated_at = now()
                          WHERE company_id = %s AND kind = 'bulk' AND status = 'queued'""", (t.company_id,))
            return
        except Exception as e:
            log.warning("expire date %s/%s: %s", t.company_id, row["customer_header_id"], e)
            db.execute("UPDATE expire_date_changes SET error = %s, updated_at = now() WHERE id = %s", (str(e)[:500], row["id"]))


def _recover(company_id: int) -> None:
    """Bulk rows left queued/running by an engine restart: running ones are unknown (failed, see the change log), queued
    ones go back to the list."""
    if is_running(company_id):
        return
    db.execute("""UPDATE expire_date_changes SET status = 'failed', error = 'Engine থেমে গিয়েছিল: বিলিং-এ দেখে নিন',
                  updated_at = now() WHERE company_id = %s AND kind = 'bulk' AND status = 'running'""", (company_id,))
    db.execute("""UPDATE expire_date_changes SET status = 'preview', updated_at = now()
                  WHERE company_id = %s AND kind = 'bulk' AND status = 'queued'""", (company_id,))


def start(t, mode: str, ids: list[int] | None = None, user_id: int | None = None) -> bool:
    """mode run: the ticked bulk rows (or ids); one: just these ids; rollback: put the old day back on these ids.
    Runs in a background thread, one at a time per company."""
    _recover(t.company_id)
    with _running_lock:
        if t.company_id in _running:
            return False
        _running.add(t.company_id)
    if mode == "run":
        db.execute("""UPDATE expire_date_changes SET status = 'queued', updated_at = now()
                      WHERE company_id = %s AND kind = 'bulk' AND status = 'preview'""", (t.company_id,))
        ids = None

    def go():
        try:
            _run(t, ids, user_id, rollback=mode == "rollback")
        except Exception:
            log.exception("expire date run failed for company %s", t.company_id)
            db.execute("""UPDATE expire_date_changes SET status = 'preview', updated_at = now()
                          WHERE company_id = %s AND kind = 'bulk' AND status = 'queued'""", (t.company_id,))
        finally:
            with _running_lock:
                _running.discard(t.company_id)

    threading.Thread(target=go, daemon=True).start()
    return True


def is_running(company_id: int) -> bool:
    with _running_lock:
        return company_id in _running


# --- auto: after each payment --------------------------------------------------------------------------------------
def auto(t) -> dict:
    """Customers paid in the last three days (online, billing's Daily Bill or the Desk): re-read their line log and
    receipts once billing has them, and set the day when the rule matches. Each payment day is written once."""
    s = settings(t.company_id)
    if not s["auto"]:
        return {"off": True}
    if db.one("""SELECT 1 FROM expire_date_changes WHERE company_id = %s AND kind = 'bulk'
                 AND status IN ('queued', 'running') AND updated_at > now() - interval '6 hours' LIMIT 1""", (t.company_id,)):
        return {"bulk_running": True}
    paid = db.all_rows(
        """WITH p AS (
               SELECT customer_header_id AS h, max(collected_at) AS at FROM billing_daily_bills
                WHERE company_id = %(c)s AND NOT is_deleted AND collected_at > now() - make_interval(days => %(d)s)
                  AND customer_header_id IS NOT NULL GROUP BY 1
               UNION ALL
               SELECT customer_header_id, max(paid_at) FROM billing_online_payments
                WHERE company_id = %(c)s AND customer_header_id IS NOT NULL
                  AND paid_at > now() - make_interval(days => %(d)s) GROUP BY 1)
           SELECT p.h, max(p.at) AS at, max(f.fetched_at) AS fetched FROM p
             LEFT JOIN billing_customer_fetches f ON f.company_id = %(c)s AND f.customer_header_id = p.h AND f.section = 'line'
            GROUP BY p.h""", {"c": t.company_id, "d": AUTO_DAYS})
    now = datetime.now(timezone.utc).replace(tzinfo=None)
    api, written, checked = tenants.billing(t), 0, 0
    for r in paid:
        # read billing again: right after the payment, then every 3 hours while it is new (the line may come on
        # later, and a cash receipt is listed only once someone approves it in billing)
        f = r["fetched"]
        if f and f >= r["at"] and not (f < r["at"] + timedelta(days=AUTO_DAYS) and f < now - timedelta(hours=3)):
            continue
        checked += 1
        customer_history.refresh(t, r["h"], ["line", "payments"], api)
        customers, logs, pays = _load(t.company_id, [r["h"]], now - timedelta(days=60))
        if not customers:
            continue
        c = customers[0]
        ch = evaluate(c, logs.get(c["header_id"], []), pays.get(c["header_id"], []), s,
                      now - timedelta(days=AUTO_DAYS + 1), home_rule=False)
        if not ch or ch["new_day"] == c["bill_day"] or db.one(
                """SELECT 1 FROM expire_date_changes WHERE company_id = %s AND customer_header_id = %s AND kind = 'auto'
                   AND paid_on = %s""", (t.company_id, c["header_id"], ch["paid_on"])):
            continue
        with db.conn() as conn, conn.cursor() as cur:
            _insert(cur, t.company_id, "auto", "queued", ch)
            cur.execute("SELECT max(id) AS id FROM expire_date_changes WHERE company_id = %s AND customer_header_id = %s",
                        (t.company_id, c["header_id"]))
            row_id = cur.fetchone()["id"]
        try:
            write_one(t, api, {"id": row_id, "customer_header_id": c["header_id"], "new_day": ch["new_day"]}, None)
            written += 1
        except StopRun as e:
            log.error("company %s: auto expire date stopped: %s", t.company_id, e)
            db.execute("UPDATE billing_connections SET expire_auto = false, updated_at = now() WHERE company_id = %s",
                       (t.company_id,))
            return {"stopped": str(e), "written": written}
    return {"paid": len(paid), "checked": checked, "written": written}


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    logging.getLogger("httpx").setLevel(logging.WARNING)
    p = argparse.ArgumentParser()
    p.add_argument("--company", type=int)
    g = p.add_mutually_exclusive_group(required=True)
    g.add_argument("--preview", action="store_true", help="build the bulk list (writes nothing to billing)")
    g.add_argument("--auto", action="store_true", help="the timer: set the day after new payments")
    a = p.parse_args()
    for t in companies(a.company):
        try:
            log.info("company %s: %s", t.company_id, preview(t.company_id) if a.preview else auto(t))
        except Exception:
            log.exception("expire dates failed for company %s", t.company_id)


if __name__ == "__main__":
    main()
