"""In-house billing, phase 5 (plan 2.4খ, 2.7): changes to an existing customer made on the Desk while billing is still
the main system. Each is written to billing through its customer edit form (without "Mikrotik Impact", so billing
leaves the router alone) and then the Desk sets the router's PPP secret itself and reads it back:

  package   new PackageId / Speed (MikroTik profile) / monthly bill; the secret gets the new profile (a line that is
            off keeps its block profile and gets the new one when it is turned on)
  left      Status Left, bill and Expire Date 0; the secret is removed and the session dropped
  back      a Left customer comes back (Active or another status); the secret is made again with billing's password
  password  a new PPPoE password in billing and on the secret
  remove    only the router: remove a Left customer's secret that is still there (the secret check page)

Guard: the same switch as new connections (billing_settings.connection_control; 'test' = only usernames that start
with the test prefix). The PPPoE password goes to billing and the router and nowhere else (not desk_customer_changes,
the activity log or the engine log).
"""
import logging
import time

from psycopg.types.json import Jsonb

from engine import activity, db
from engine import desk_connection as dn
from engine import desk_customer as dc
from engine.desk_line import block_profile, is_block, read_secret, sessions
from engine.mikrotik import RouterOS

log = logging.getLogger("zyro-desk-change")
KINDS = ("package", "left", "back", "password", "remove")
LEFT = "left"


class Refused(Exception):
    """Nothing was changed (switch off, not a test username, customer not found, bad input)."""


def customer(company_id: int, header_id: int) -> dict | None:
    return db.one("""SELECT header_id, customer_id, username, name, status, is_left, server, speed, package, package_id,
                            monthly_bill, bill_day FROM billing_customers
                     WHERE company_id = %s AND header_id = %s AND gone_at IS NULL""", (company_id, int(header_id)))


def _connect(r: dict):
    return RouterOS(r["host"], r["api_port"], r["username"], db.decrypt(r["password"]), timeout=15)


def billing_changes(kind: str, p: dict) -> dict:
    """The edit form fields to post for a change (empty for 'remove', which is the router only)."""
    if kind == "package":
        return {"PackageId": str(p["package_id"]), "Speed": str(p["profile"]),
                "MonthlyBillAmount": str(dc.bill_for(p.get("status_name") or "Active", p["monthly_bill"]))}
    if kind == "left":
        return {"Status": str(p["status_id"]), "MonthlyBillAmount": "0", "BillingLastDate": "0"}
    if kind == "back":
        status = p.get("status_name") or "Active"
        out = {"Status": str(p["status_id"]), "MonthlyBillAmount": str(dc.bill_for(status, p.get("monthly_bill") or 0)),
               "BillingLastDate": str(dc.expire_for(status, p.get("expire_day") or 0))}
        if p.get("package_id"):
            out.update(PackageId=str(p["package_id"]), Speed=str(p["profile"]))
        return out
    if kind == "password":
        return {"Password": str(p["password"])}
    return {}


def validate(kind: str, p: dict) -> list[str]:
    need = {"package": ("package_id", "profile", "monthly_bill"), "left": ("status_id",),
            "back": ("status_id",), "password": ("password",), "remove": ()}[kind]
    out = [f"{k}: লাগবে" for k in need if str(p.get(k) if p.get(k) is not None else "").strip() == ""]
    if kind == "left" and (p.get("status_name") or "").strip().lower() != LEFT:
        out.append("status: Left হতে হবে")
    if kind == "back":
        if (p.get("status_name") or "").strip().lower() == LEFT:
            out.append("status: Left ছাড়া অন্য কিছু")
        if not dc.no_bill(p.get("status_name")):
            for k in ("monthly_bill", "expire_day"):
                if str(p.get(k) or "").strip() == "":
                    out.append(f"{k}: লাগবে")
        if p.get("package_id") and not p.get("profile"):
            out.append("profile: লাগবে")
    if kind == "password" and not (6 <= len(str(p.get("password") or "")) <= 40):
        out.append("password: ৬-৪০ অক্ষর")
    if kind == "package" and str(p.get("monthly_bill")).strip() != "":
        try:
            float(p["monthly_bill"])
        except (TypeError, ValueError):
            out.append("monthly_bill: সংখ্যা")
    if kind == "back" and str(p.get("expire_day") or "").strip() and not dc.no_bill(p.get("status_name")):
        if not str(p["expire_day"]).isdigit() or not 1 <= int(p["expire_day"]) <= dc.EXPIRE_MAX:
            out.append(f"expire_day: 1-{dc.EXPIRE_MAX}")
    return out


def router_step(api, kind: str, username: str, p: dict, password: str | None) -> dict:
    """Set the secret for the change; returns {"note": what was done, "secret": read back (no password) | None}."""
    sec = read_secret(api, username)
    note = ""
    if kind == "package":
        if not sec:
            return {"note": "রাউটারে secret নেই", "secret": None, "error": "রাউটারে এই PPPoE secret নেই"}
        if sec["profile"] == p["profile"]:
            note = "profile আগেই ঠিক"
        elif is_block(sec["profile"]):
            note = "লাইন বন্ধ (block profile); চালু হলে নতুন profile পাবে"
        else:
            api.talk("/ppp/secret/set", {".id": sec["id"], "profile": p["profile"]})
            _drop_sessions(api, username)
            note = f"profile {sec['profile']} → {p['profile']}"
    elif kind in ("left", "remove"):
        if sec:
            api.talk("/ppp/secret/remove", {".id": sec["id"]})
            _drop_sessions(api, username)
            note = "secret মুছে ফেলা হয়েছে"
        else:
            note = "secret আগেই ছিল না"
    elif kind == "back":
        profile = p.get("profile") or p.get("current_profile")
        if not sec:
            if not password or not profile:
                return {"note": "", "secret": None, "error": "secret বানানো যায়নি: বিলিং-এ পাসওয়ার্ড বা profile নেই"}
            api.talk("/ppp/secret/add", {"name": username, "password": password, "profile": profile, "service": "pppoe"})
            note = f"secret তৈরি ({profile})"
        else:
            changes = {}
            if sec["disabled"]:
                changes["disabled"] = "no"
            if profile and sec["profile"] != profile and not is_block(sec["profile"]):
                changes["profile"] = profile
            if changes:
                api.talk("/ppp/secret/set", {".id": sec["id"], **changes})
            note = "secret আগেই ছিল" + (", ঠিক করা হয়েছে" if changes else "")
    elif kind == "password":
        if not sec:
            return {"note": "", "secret": None, "error": "রাউটারে এই PPPoE secret নেই"}
        api.talk("/ppp/secret/set", {".id": sec["id"], "password": password})
        _drop_sessions(api, username)
        note = "পাসওয়ার্ড বদলানো হয়েছে, পুরনো সেশন কাটা হয়েছে"
    return {"note": note, "secret": read_secret(api, username), "error": None}


def _drop_sessions(api, username: str) -> None:
    for s in sessions(api, username):
        try:
            api.talk("/ppp/active/remove", {".id": s["id"]})
        except Exception as e:  # gone on its own between print and remove
            if "no such item" not in str(e).lower():
                raise


def secret_problem(kind: str, sec: dict | None, p: dict) -> str | None:
    """Why the secret read back is not what the change wanted (None = right)."""
    if kind in ("left", "remove"):
        return "secret এখনো আছে" if sec else None
    if not sec:
        return "secret পাওয়া যায়নি"
    if kind == "package" and sec["profile"] != p["profile"] and not is_block(sec["profile"]):
        return f"profile {sec['profile']}, হওয়ার কথা {p['profile']}"
    if kind == "back" and sec["disabled"]:
        return "secret বন্ধ (disabled)"
    return None


def _row(company_id, kind, c, p, user_id, actor, source) -> dict:
    return db.execute("""INSERT INTO desk_customer_changes (company_id, kind, header_id, customer_code, username, name,
                             detail, status, user_id, actor, source, created_at)
                         VALUES (%s,%s,%s,%s,%s,%s,%s,'sending',%s,%s,%s, now()) RETURNING *""",
                      (company_id, kind, c["header_id"], c.get("customer_id"), c["username"], c.get("name"),
                       Jsonb(dc.safe_copy(p)), user_id, actor, source))


def _update(row_id: int, **cols) -> dict:
    sets = ", ".join(f"{k} = %s" for k in cols)
    return db.execute(f"UPDATE desk_customer_changes SET {sets}, finished_at = now() WHERE id = %s RETURNING *",
                      (*cols.values(), row_id))


def run(company_id: int, kind: str, header_id: int, params: dict, *, api, user_id: int | None = None,
        actor: str = "Desk", source: str = "panel", connect=None, sync=None, sleep=time.sleep) -> dict:
    """Make one change. Raises Refused when nothing was changed; else returns the desk_customer_changes row:
    done (billing and router right), check (billing saved, router not right or not reachable), failed (billing
    refused; the router is not touched)."""
    if kind not in KINDS:
        raise Refused(f"অজানা কাজ {kind}")
    c = customer(company_id, header_id)
    if not c or not c.get("username"):
        raise Refused("কাস্টমার Desk-এর তালিকায় নেই")
    why = dn.allowed(dn.settings(company_id), c["username"])
    if why:
        raise Refused(why)
    p = {k: (v.strip() if isinstance(v, str) else v) for k, v in (params or {}).items()}
    errors = validate(kind, p)
    if errors:
        raise Refused("ফর্মে ভুল: " + "; ".join(errors))
    if kind == "back" and not c.get("is_left"):
        raise Refused("কাস্টমার Left নয়")
    if kind == "left" and c.get("is_left"):
        raise Refused("কাস্টমার আগেই Left")
    if kind == "remove" and not c.get("is_left"):
        raise Refused("শুধু Left কাস্টমারের secret মোছা যায়")
    if kind == "password":
        p["password"] = str(p["password"])

    row = _row(company_id, kind, c, p, user_id, actor, source)
    password = p.get("password")
    replace = billing_changes(kind, p)
    if replace:
        try:
            before = api.change_customer(c["header_id"], replace, {"username": c["username"]})
        except Exception as e:  # billing refused or did not answer: the router is left alone
            log.warning("billing change %s for %s failed: %s", kind, c["header_id"], e)
            return _update(row["id"], status="failed", error=str(e)[:500])
        if kind == "back":
            password = before.get("Password")       # billing's own; only for making the secret again
            p.setdefault("current_profile", before.get("Speed"))
        before = {k: before.get(k) for k in ("Status", "PackageId", "Speed", "MonthlyBillAmount", "BillingLastDate")}
    else:
        before = {}

    router = dn.router_for(company_id, c.get("server"))
    secret, error, note = None, None, ""
    if not router:
        error = "এই Server-এর রাউটার Desk-এ যোগ করা নেই, রাউটারে কিছু করা হয়নি"
    else:
        try:
            with (connect or _connect)(router) as r:
                out = router_step(r, kind, c["username"], p, password)
            secret, error, note = out["secret"], out["error"], out["note"]
            if not error:
                error = secret_problem(kind, secret, p)
        except Exception as e:
            error = f"রাউটারে যাওয়া যায়নি: {e}"[:300]
    password = None
    status = "check" if error else "done"
    row = _update(row["id"], status=status, error=error, note=note[:300] or None, before=Jsonb(before),
                  secret_profile=(secret or {}).get("profile"), secret_disabled=(secret or {}).get("disabled"))
    activity.record(company_id, f"customer.{kind}", actor=actor, source=source, user_id=user_id,
                    subject_type="desk_customer_change", subject_id=row["id"], customer_code=c.get("customer_id"),
                    changes={"before": before, "after": {k: v for k, v in replace.items() if k != "Password"}},
                    note=f"{c['username']}: {status}" + (f" ({error})" if error else ""))
    if sync and kind != "remove":
        try:
            sync(c["username"])
        except Exception:
            log.exception("sync after %s failed for %s", kind, c["username"])
    return row


# --- secret check: the routers' PPP secrets against billing's customer list --------------------------------------
def audit(company_id: int, connect=None) -> dict:
    """Compare every enabled router's PPP secrets (name, profile, disabled; never the password) with billing's
    customers. Returns {"routers": [{id, identity, ok, error, secrets}], "missing", "extra", "left_present",
    "profile_mismatch"} — lists of small dicts."""
    customers = {(r["username"] or "").lower(): r for r in db.all_rows(
        """SELECT header_id, customer_id, username, name, status, is_left, server, speed FROM billing_customers
           WHERE company_id = %s AND gone_at IS NULL AND username IS NOT NULL AND username <> ''""", (company_id,))}
    routers, seen = [], {}
    for rt in db.all_rows("SELECT * FROM mikrotik_routers WHERE company_id = %s AND enabled ORDER BY id", (company_id,)):
        entry = {"id": rt["id"], "identity": rt.get("identity") or rt["host"], "ok": False, "error": None, "secrets": 0}
        try:
            with (connect or _connect)(rt) as api:
                try:
                    block = block_profile(api, rt)
                except Exception:
                    block = None
                rows = api.talk("/ppp/secret/print", {".proplist": "name,profile,disabled"})
            for s in rows:
                name = (s.get("name") or "").strip()
                if name:
                    seen.setdefault(name.lower(), []).append({"router": entry["identity"], "router_id": rt["id"],
                        "name": name, "profile": s.get("profile"), "block": block,
                        "disabled": str(s.get("disabled")).lower() in ("true", "yes")})
            entry.update(ok=True, secrets=len(rows))
        except Exception as e:
            entry["error"] = str(e)[:200]
        routers.append(entry)
    reached = {r["id"] for r in routers if r["ok"]}
    out = {"routers": routers, "missing": [], "extra": [], "left_present": [], "profile_mismatch": []}
    if not reached:
        return out
    for key, c in customers.items():
        secs = seen.get(key, [])
        info = {"header_id": c["header_id"], "code": c["customer_id"], "username": c["username"], "name": c["name"],
                "status": c["status"], "server": c["server"], "speed": c["speed"]}
        if c["is_left"] or (c["status"] or "").lower() == LEFT:
            if secs:
                out["left_present"].append({**info, "router": secs[0]["router"], "profile": secs[0]["profile"]})
            continue
        if not secs:
            home = dn.router_for(company_id, c["server"])
            if home and home["id"] in reached:      # only where its own router was read
                out["missing"].append(info)
            continue
        s = secs[0]
        if c["speed"] and s["profile"] != c["speed"] and not is_block(s["profile"], s["block"]):
            out["profile_mismatch"].append({**info, "router": s["router"], "profile": s["profile"]})
    for key, secs in seen.items():
        if key not in customers:
            for s in secs:
                out["extra"].append({"username": s["name"], "router": s["router"], "profile": s["profile"],
                                     "disabled": s["disabled"]})
    return out
