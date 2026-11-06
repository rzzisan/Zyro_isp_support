"""In-house billing, phase 3 (MikroTik সরাসরি): the Desk turns a customer's line off/on on the MikroTik itself, then
reads the router back to check it worked (plan 2.2 #8), retrying the setting's number of times.

  disable     the secret's profile -> the router's block profile (mikrotik_routers.block_profile, else the one profile
              named like BLOCKED-PROFILE), then every active session of the user is removed (they reconnect blocked).
              The profile it had is kept in desk_customers.previous_profile.
  enable      back to the profile it had (previous_profile, else the last Desk disable's, else billing's Speed when it
              is not a block profile), then the sessions are removed (they reconnect on the real profile).
  deactivate  (Inactive) the secret is disabled and the sessions removed;  activate: the secret is enabled again.

Verify after each try: the secret's profile/disabled flag read again, and none of the removed sessions still up
(a new session after the kill is expected and noted). Every action is a desk_line_actions row + an activity log line;
a failure pushes the owners/admins' browsers.

Guard (billing_settings.line_control): 'off' refuses everything; 'test' allows only the customers listed in
line_test_codes (customer ID or PPPoE username). Billing is still the main system before the cutover, so only test and
pilot accounts are switched by the Desk. The PPPoE password is never read (secrets are printed with a .proplist).

  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.desk_line --company 1 --customer 0001 disable
"""
import argparse
import logging
import time

from engine import activity, db
from engine.mikrotik import RouterOS, RouterOSError
from engine.ppp_sync import BLOCK_NAME

log = logging.getLogger("zyro-desk-line")
ACTIONS = ("disable", "enable", "deactivate", "activate")
REASONS = ("manual", "test", "unpaid", "paid", "partial", "bot", "extend", "status")
WAIT_AFTER_KILL = 2.0     # seconds before reading the router back
WAIT_BEFORE_RETRY = 3.0


class Refused(Exception):
    """The action may not run (setting off, not a test account, unknown customer, already running)."""


def settings(company_id: int) -> dict:
    row = db.one("SELECT line_control, line_test_codes, mikrotik_retries, billing_mode FROM billing_settings WHERE company_id = %s",
                 (company_id,)) or {}
    return {"line_control": row.get("line_control") or "off", "line_test_codes": list(row.get("line_test_codes") or []),
            "mikrotik_retries": 3 if row.get("mikrotik_retries") is None else int(row["mikrotik_retries"]),
            "billing_mode": row.get("billing_mode") or "ispdigital"}


def allowed(s: dict, customer: dict) -> str | None:
    """Why the Desk may not switch this customer's line now (None = allowed)."""
    if s["line_control"] == "off":
        return "বিলিং নিয়মে 'Desk থেকে MikroTik-এ লাইন বন্ধ/চালু' বন্ধ আছে"
    if s["line_control"] == "test":
        listed = {str(x).strip().lower() for x in s["line_test_codes"] if str(x).strip()}
        if not ({str(customer.get("customer_code") or "").lower(), str(customer.get("username") or "").lower()} & listed):
            return "এই কাস্টমার পরীক্ষার তালিকায় নেই (বিলিং নিয়ম → পরীক্ষার অ্যাকাউন্ট)"
    elif s["line_control"] != "all":
        return f"অজানা সেটিং {s['line_control']}"
    elif s["billing_mode"] != "desk":
        return "হস্তান্তরের আগে শুধু পরীক্ষার অ্যাকাউন্টে চালানো যায়"
    return None


def find_customer(company_id: int, code: str | None = None, username: str | None = None, header_id: int | None = None) -> dict | None:
    where, val = (("c.header_id = %s", header_id) if header_id else ("c.customer_code = %s", code) if code
                  else ("lower(c.username) = lower(%s)", username))
    return db.one(f"""SELECT c.id, c.header_id, c.customer_code, c.username, c.name, c.status, c.router_id, c.previous_profile,
                             p.mikrotik_profile AS package_profile, b.server, b.speed
                      FROM desk_customers c LEFT JOIN desk_packages p ON p.id = c.package_ref
                      LEFT JOIN billing_customers b ON b.company_id = c.company_id AND b.header_id = c.header_id
                      WHERE c.company_id = %s AND c.gone_at IS NULL AND {where} ORDER BY c.id DESC LIMIT 1""",
                  (company_id, val))


def is_block(name: str | None, block: str | None = None) -> bool:
    return bool(name) and (name == block or bool(BLOCK_NAME.search(name)))


def block_profile(api: RouterOS, router: dict) -> str:
    """The router's block profile: its setting, else the one profile whose name looks like a block profile."""
    names = [p.get("name") for p in api.talk("/ppp/profile/print", {".proplist": "name"}) if p.get("name")]
    want = (router.get("block_profile") or "").strip()
    if want:
        if want not in names:
            raise RouterOSError(f"ব্লক প্রোফাইল '{want}' রাউটারে নেই")
        return want
    blocks = [n for n in names if BLOCK_NAME.search(n)]
    if "BLOCKED-PROFILE" in blocks:
        return "BLOCKED-PROFILE"
    if len(blocks) != 1:
        raise RouterOSError("রাউটারের ব্লক প্রোফাইল বাছা নেই (MikroTik সেটিং-এ বাছুন)")
    return blocks[0]


def read_secret(api: RouterOS, username: str) -> dict | None:
    """The secret without its password: .id, name, profile, disabled (bool)."""
    rows = api.talk("/ppp/secret/print", {".proplist": ".id,name,profile,disabled"}, query={"name": username})
    if not rows:
        return None
    s = rows[0]
    return {"id": s.get(".id"), "profile": s.get("profile"), "disabled": str(s.get("disabled")).lower() in ("true", "yes")}


def sessions(api: RouterOS, username: str) -> list[dict]:
    return [{"id": a.get(".id"), "uptime": a.get("uptime"), "address": a.get("address")}
            for a in api.talk("/ppp/active/print", {".proplist": ".id,name,uptime,address"}, query={"name": username})]


def routers_for(company_id: int, customer: dict) -> list[dict]:
    """The customer's own router first (router_id, billing's Server), then the company's other routers."""
    return db.all_rows("""SELECT * FROM mikrotik_routers WHERE company_id = %s AND enabled
                          ORDER BY (id = %s) DESC, (billing_server = %s) DESC, id""",
                       (company_id, customer.get("router_id") or 0, customer.get("server") or ""))


def enable_profile(company_id: int, customer: dict, block: str) -> str | None:
    """The profile to give back: the one kept at the Desk's disable, else billing's Speed, else the package's."""
    last = db.one("""SELECT profile_before FROM desk_line_actions WHERE company_id = %s AND customer_ref = %s
                     AND action = 'disable' AND status = 'done' AND profile_before IS NOT NULL ORDER BY id DESC LIMIT 1""",
                  (company_id, customer["id"]))
    for p in (customer.get("previous_profile"), (last or {}).get("profile_before"), customer.get("speed"),
              customer.get("package_profile")):
        if p and not is_block(p, block):
            return p
    return None


def apply(api: RouterOS, action: str, username: str, target: str | None, retries: int, sleep=time.sleep) -> dict:
    """Set the secret, remove the sessions, read back; again up to `retries` more times. Returns what was seen."""
    out = {"attempts": 0, "killed": 0, "ok": False, "error": None}
    for attempt in range(retries + 1):
        out["attempts"] = attempt + 1
        try:
            sec = read_secret(api, username)
            if not sec:
                out["error"] = "রাউটারে এই PPPoE secret নেই"
                return out
            if action in ("disable", "enable") and sec["profile"] != target:
                api.talk("/ppp/secret/set", {".id": sec["id"], "profile": target})
            elif action == "deactivate" and not sec["disabled"]:
                api.talk("/ppp/secret/set", {".id": sec["id"], "disabled": "yes"})
            elif action == "activate" and sec["disabled"]:
                api.talk("/ppp/secret/set", {".id": sec["id"], "disabled": "no"})
            killed = []
            if action != "activate":     # an enabled secret has no session to restart; the customer dials in
                for s in sessions(api, username):
                    try:
                        api.talk("/ppp/active/remove", {".id": s["id"]})
                        killed.append(s["id"])
                    except RouterOSError as e:   # gone on its own between print and remove
                        if "no such item" not in str(e).lower():
                            raise
            out["killed"] += len(killed)
            sleep(WAIT_AFTER_KILL)
            after = read_secret(api, username) or {}
            now = sessions(api, username)
            out.update(profile_after=after.get("profile"), disabled_after=after.get("disabled"),
                       session_after=(now[0]["uptime"] if now else None))
            right = (after.get("profile") == target if action in ("disable", "enable")
                     else after.get("disabled") is (action == "deactivate"))
            still = [s for s in now if s["id"] in killed]
            if right and not still and not (action == "deactivate" and now):
                out["ok"], out["error"] = True, None
                return out
            out["error"] = ("প্রোফাইল বদলায়নি" if not right else "আগের সেশন এখনো চালু")
        except RouterOSError as e:
            msg = str(e)
            out["error"] = "রাউটারের API ইউজারের লেখার অনুমতি নেই" if "permission" in msg.lower() else msg[:300]
            if "permission" in msg.lower():
                return out
        if attempt < retries:
            sleep(WAIT_BEFORE_RETRY)
    return out


def run(company_id: int, action: str, *, code: str | None = None, username: str | None = None, header_id: int | None = None,
        reason: str = "manual", note: str | None = None, user_id: int | None = None, actor: str = "Desk",
        source: str = "panel", sleep=time.sleep, connect=None) -> dict:
    """Switch one line and check it. Raises Refused when it may not run; otherwise returns the desk_line_actions row."""
    if action not in ACTIONS:
        raise Refused("অজানা কাজ")
    reason = reason if reason in REASONS else "manual"
    c = find_customer(company_id, code=code, username=username, header_id=header_id)
    if not c or not c.get("username"):
        raise Refused("কাস্টমার বা তার PPPoE ID পাওয়া যায়নি")
    s = settings(company_id)
    why = allowed(s, c)
    if why:
        raise Refused(why)
    if db.one("""SELECT 1 FROM desk_line_actions WHERE company_id = %s AND customer_ref = %s AND status = 'running'
                 AND created_at > now() - interval '3 minutes'""", (company_id, c["id"])):
        raise Refused("এই কাস্টমারের আগের কাজ এখনো চলছে")
    row = db.execute("""INSERT INTO desk_line_actions (company_id, customer_ref, customer_code, username, action, reason, note,
                            user_id, actor, source, status, attempts, created_at)
                        VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'running',0,now()) RETURNING id""",
                     (company_id, c["id"], c["customer_code"], c["username"], action, reason, (note or None) and note[:500],
                      user_id, (actor or "Desk")[:100], source[:10]))
    aid = row["id"]
    res = {"status": "failed", "error": None}
    router = before = None
    connect = connect or (lambda r: RouterOS(r["host"], r["api_port"], r["username"], db.decrypt(r["password"]), timeout=15))
    try:
        for r in routers_for(company_id, c):
            try:
                api = connect(r)
            except Exception as e:
                log.warning("router %s not reachable: %s", r.get("identity"), e)
                res["error"] = f"রাউটার {r.get('identity') or r['host']} পাওয়া যায়নি"
                continue
            with api:
                sec = read_secret(api, c["username"])
                if not sec:
                    continue
                router, before = r, sec
                was = sessions(api, c["username"])
                target = None
                if action in ("disable", "enable"):
                    block = block_profile(api, r)
                    target = block if action == "disable" else enable_profile(company_id, c, block)
                    if not target:
                        res["error"] = "আগের প্রোফাইল জানা নেই, প্যাকেজের প্রোফাইলও নেই"
                        break
                db.execute("""UPDATE desk_line_actions SET router_id = %s, router_name = %s, profile_before = %s,
                              disabled_before = %s, session_before = %s, profile_target = %s WHERE id = %s""",
                           (r["id"], r.get("identity") or r["host"], sec["profile"], sec["disabled"],
                            was[0]["uptime"] if was else None, target, aid))
                out = apply(api, action, c["username"], target, s["mikrotik_retries"], sleep=sleep)
                res = {"status": "done" if out["ok"] else "failed", "error": out["error"], **out}
            break
        else:
            res["error"] = res["error"] or "কোনো রাউটারে এই PPPoE secret পাওয়া যায়নি"
    except Exception as e:
        log.exception("desk line %s for %s", action, c["customer_code"])
        res = {"status": "failed", "error": str(e)[:300]}

    db.execute("""UPDATE desk_line_actions SET status = %s, attempts = %s, sessions_killed = %s, profile_after = %s,
                  disabled_after = %s, session_after = %s, error = %s, finished_at = now() WHERE id = %s""",
               (res["status"], res.get("attempts") or 0, res.get("killed") or 0, res.get("profile_after"),
                res.get("disabled_after"), res.get("session_after"), res.get("error"), aid))
    if res["status"] == "done" and router:
        keep = before["profile"] if action == "disable" and not is_block(before["profile"], res.get("profile_after")) else None
        db.execute("""UPDATE desk_customers SET router_id = %s, previous_profile = COALESCE(%s, previous_profile), updated_at = now()
                      WHERE id = %s""", (router["id"], keep, c["id"]))
    activity.record(company_id, f"line.{action}", actor=actor or "Desk", source=source, user_id=user_id,
                    subject_type="desk_line_action", subject_id=aid, customer_code=c["customer_code"],
                    changes={"profile": [before and before["profile"], res.get("profile_after")]} if before else None,
                    note=f"{reason}: {res['status']}" + (f" ({res['error']})" if res.get("error") else ""))
    if res["status"] == "failed":
        alert(company_id, c, action, res.get("error"))
    return db.one("SELECT * FROM desk_line_actions WHERE id = %s", (aid,))


def alert(company_id: int, c: dict, action: str, error: str | None) -> None:
    """Owners and admins see it in their browser: a paid customer may still be without internet."""
    try:
        from engine import webpush
        users = [r["user_id"] for r in db.all_rows(
            "SELECT user_id FROM company_user WHERE company_id = %s AND role IN ('owner', 'admin')", (company_id,))]
        slug = (db.one("SELECT slug FROM companies WHERE id = %s", (company_id,)) or {}).get("slug")
        word = {"disable": "বন্ধ", "enable": "চালু", "deactivate": "Inactive", "activate": "Active"}[action]
        if users:
            webpush.send_users(company_id, users, {"title": f"MikroTik-এ লাইন {word} হয়নি: {c['customer_code']}",
                                                   "body": f"{c.get('name') or ''} ({c['username']}): {error or 'যাচাই মেলেনি'}",
                                                   "tag": f"line-{c['id']}",
                                                   "url": f"/app/{slug}/billing-line-actions" if slug else ""})
    except Exception:
        log.exception("line alert for company %s", company_id)


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    p = argparse.ArgumentParser(description="Turn one customer's line off/on on the MikroTik and check it")
    p.add_argument("action", choices=ACTIONS)
    p.add_argument("--company", type=int, required=True)
    p.add_argument("--customer", help="customer ID (e.g. 0001)")
    p.add_argument("--username", help="PPPoE username")
    p.add_argument("--note")
    a = p.parse_args()
    try:
        r = run(a.company, a.action, code=a.customer, username=a.username, reason="test", note=a.note, actor="CLI",
                source="cli")
    except Refused as e:
        raise SystemExit(f"refused: {e}")
    print({k: r[k] for k in ("id", "status", "router_name", "profile_before", "profile_target", "profile_after",
                             "attempts", "sessions_killed", "session_after", "error")})


if __name__ == "__main__":
    main()
