"""Who is online right now: every 2 minutes copy each MikroTik's /ppp/active list into ppp_sessions.

A router that can't be reached keeps its last list (seen_at shows how old it is); the panel treats a session
as online only when seen in the last few minutes.
  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.ppp_sync
"""
import logging

from engine import db
from engine.mikrotik import RouterOS

log = logging.getLogger("zyro-ppp-sync")


def sync_router(r: dict) -> int:
    with RouterOS(r["host"], r["api_port"], r["username"], db.decrypt(r["password"]), timeout=15) as api:
        rows = api.talk("/ppp/active/print", {".proplist": "name,address,caller-id,uptime"})
    with db.conn() as c:
        with c.transaction():
            c.execute("DELETE FROM ppp_sessions WHERE router_id = %s", (r["id"],))
            with c.cursor() as cur:
                cur.executemany(
                    """INSERT INTO ppp_sessions (company_id, router_id, username, address, caller_id, uptime, seen_at)
                       VALUES (%s, %s, %s, %s, %s, %s, now())
                       ON CONFLICT (company_id, username) DO UPDATE SET router_id = EXCLUDED.router_id,
                         address = EXCLUDED.address, caller_id = EXCLUDED.caller_id, uptime = EXCLUDED.uptime, seen_at = now()""",
                    [(r["company_id"], r["id"], x.get("name"), x.get("address"), x.get("caller-id"), x.get("uptime"))
                     for x in rows if x.get("name")])
            c.execute("""UPDATE mikrotik_routers SET ppp_active = %s, last_checked_at = now(), last_check_ok = true,
                         updated_at = now() WHERE id = %s""", (len(rows), r["id"]))
    return len(rows)


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    for r in db.all_rows("SELECT * FROM mikrotik_routers WHERE enabled ORDER BY company_id, id"):
        try:
            log.info("router %s (%s): %s online", r["id"], r["identity"], sync_router(r))
        except Exception as e:
            log.warning("router %s (%s) failed: %s", r["id"], r["identity"], e)
            db.execute("""UPDATE mikrotik_routers SET last_checked_at = now(), last_check_ok = false, last_check_message = %s,
                          updated_at = now() WHERE id = %s""", (str(e)[:250], r["id"]))


def online_now(company_id: int, username: str, server: str | None) -> dict | None:
    """Ask the customer's own router right now (falls back to every router of the company).
    Returns {"online": bool, "router": ..., "uptime": ..., "address": ..., "caller_id": ...} or None if no router answered."""
    if not username:
        return None
    routers = db.all_rows("SELECT * FROM mikrotik_routers WHERE company_id = %s AND enabled ORDER BY (billing_server = %s) DESC, id",
                          (company_id, server or ""))
    answered = None
    for r in routers:
        try:
            with RouterOS(r["host"], r["api_port"], r["username"], db.decrypt(r["password"]), timeout=6) as api:
                rows = api.talk("/ppp/active/print", query={"name": username})
        except Exception as e:
            log.warning("router %s not reachable: %s", r["identity"], e)
            continue
        answered = {"online": False, "router": r["identity"]}
        if rows:
            x = rows[0]
            return {"online": True, "router": r["identity"], "uptime": x.get("uptime"), "address": x.get("address"),
                    "caller_id": x.get("caller-id")}
        if r["billing_server"] and r["billing_server"] == server:
            return answered  # its own router says offline
    return answered


if __name__ == "__main__":
    main()
