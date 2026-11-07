"""In-house billing, phase 5 (নতুন সংযোগ): a new connection made on the Desk is sent to billing (POST /Customer/Add)
while billing is still the main system (plan 2.4খ "হস্তান্তরের আগে"). Billing makes the PPP secret on the router
itself; the Desk then reads the router back (name, profile, disabled; never the password) to check it.

Guard (billing_settings.connection_control): 'off' sends nothing; 'test' only PPPoE usernames that start with
connection_test_prefix (e.g. "zyrotest"); 'all' every new connection. The default is off.

The PPPoE password is posted to billing once and nowhere else: desk_connections.form, the activity log and the
engine log never hold it. A send is never retried by itself (billing has no idempotency key): when the answer is
lost, billing's username check tells whether the customer was made, and the row says "unsure" when it can't.

  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m unittest engine.tests.test_desk_connection
"""
import logging
import re
import secrets
import string
import time

from psycopg.errors import UniqueViolation
from psycopg.types.json import Jsonb

from engine import activity, db
from engine import desk_customer as dc
from engine.mikrotik import RouterOS

log = logging.getLogger("zyro-desk-connection")
CONTROL = ("off", "test", "all")
SECRET_CHECKS = 3          # router reads after billing saved (billing adds the secret during the save)
WAIT_BETWEEN_CHECKS = 2.0


class Refused(Exception):
    """Nothing was sent to billing (switch off, not a test username, form errors, username taken)."""


def settings(company_id: int) -> dict:
    row = db.one("SELECT connection_control, connection_test_prefix FROM billing_settings WHERE company_id = %s",
                 (company_id,)) or {}
    return {"control": row.get("connection_control") or "off",
            "prefix": (row.get("connection_test_prefix") or "").strip()}


def allowed(s: dict, username: str) -> str | None:
    """Why this new connection may not be sent to billing now (None = allowed)."""
    if s["control"] == "off":
        return "বিলিং নিয়মে 'Desk থেকে নতুন সংযোগ' বন্ধ আছে"
    if s["control"] == "test":
        if not s["prefix"]:
            return "পরীক্ষার মোড, কিন্তু পরীক্ষার username-এর শুরু (prefix) দেওয়া নেই"
        if not username.lower().startswith(s["prefix"].lower()):
            return f"পরীক্ষার মোডে শুধু '{s['prefix']}' দিয়ে শুরু হওয়া username পাঠানো যায়"
        return None
    if s["control"] != "all":
        return f"অজানা সেটিং {s['control']}"
    return None


def new_password(length: int = 10) -> str:
    """A PPPoE password without look-alike characters (0/O, 1/l/I)."""
    alphabet = "".join(c for c in string.ascii_letters + string.digits if c not in "0O1lI")
    return "".join(secrets.choice(alphabet) for _ in range(length))


def _norm(name: str | None) -> str:
    return re.sub(r"[^a-z0-9]", "", (name or "").lower())


def router_for(company_id: int, server_name: str | None) -> dict | None:
    """The Desk's router for billing's Server (CLN-3 = billing_server CLN_3 or name CLN-3)."""
    want = _norm(server_name)
    if not want:
        return None
    for r in db.all_rows("SELECT * FROM mikrotik_routers WHERE company_id = %s AND enabled ORDER BY id", (company_id,)):
        if want in (_norm(r.get("billing_server")), _norm(r.get("name"))):
            return r
    return None


def check_secret(router: dict | None, username: str, connect=None, sleep=time.sleep) -> dict:
    """Read the new secret back (no password): {"secret": {...} | None, "error": str | None}."""
    if not router:
        return {"secret": None, "error": "এই Server-এর রাউটার Desk-এ যোগ করা নেই, secret যাচাই হয়নি"}
    connect = connect or (lambda r: RouterOS(r["host"], r["api_port"], r["username"], db.decrypt(r["password"]), timeout=15))
    from engine.desk_line import read_secret
    last_error = None
    for attempt in range(SECRET_CHECKS):
        try:
            with connect(router) as api:
                sec = read_secret(api, username)
            if sec:
                return {"secret": sec, "error": None}
            last_error = None
        except Exception as e:  # router unreachable: say so, the customer is made in billing anyway
            last_error = f"রাউটারে যাওয়া যায়নি: {e}"[:300]
        if attempt < SECRET_CHECKS - 1:
            sleep(WAIT_BETWEEN_CHECKS)
    return {"secret": None, "error": last_error}


def customer_code(api, username: str, header_id: int) -> str | None:
    """Billing's Client Code of the new customer (the header search shows "user (CustomerId)")."""
    try:
        for h in api.quick_search(username):
            if str(h.get("id")) == str(header_id):
                m = re.match(r"^\s*(.*?)\s*\(([^()]*)\)\s*$", str(h.get("text") or ""))
                return m.group(2).strip() if m else None
    except Exception:
        log.exception("customer code lookup failed for header %s", header_id)
    return None


def _update(row_id: int, **cols) -> dict:
    sets = ", ".join(f"{k} = %s" for k in cols)
    return db.execute(f"UPDATE desk_connections SET {sets}, finished_at = now() WHERE id = %s RETURNING *",
                      (*cols.values(), row_id))


def run(company_id: int, form: dict, *, api, user_id: int | None = None, actor: str = "Desk", source: str = "panel",
        connect=None, sync=None, sleep=time.sleep) -> dict:
    """Send one new connection to billing and check the router. `api` is the member's billing client; `sync`
    (optional) copies the new customer into the Desk's list. Returns the desk_connections row."""
    form = {**form, "username": str(form.get("username") or "").strip()}
    username = form["username"]
    reason = allowed(settings(company_id), username)
    if reason:
        raise Refused(reason)
    errors = dc.validate_new(form)
    if errors:
        raise Refused("ফর্মে ভুল: " + "; ".join(errors))
    busy = db.one("""SELECT id FROM desk_connections WHERE company_id = %s AND lower(username) = lower(%s)
                     AND status IN ('sending', 'done', 'check', 'unsure') LIMIT 1""", (company_id, username))
    if busy:
        raise Refused("এই username দিয়ে Desk থেকে আগেই সংযোগ পাঠানো হয়েছে")
    if api.username_taken(username):
        raise Refused("এই PPPoE username বিলিং-এ আগে থেকেই আছে")

    keep = dc.safe_copy(form)
    try:
            row = db.execute("""INSERT INTO desk_connections (company_id, status, name, mobile, username, server_name, profile,
                            package_name, monthly_bill, status_name, form, user_id, actor, source)
                        VALUES (%s, 'sending', %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s) RETURNING *""",
                     (company_id, form.get("name"), dc.local_mobile(form.get("mobile")), username, form.get("server_name"),
                      form.get("profile"), form.get("package_name"), dc.bill_for(form.get("status_name"), form.get("monthly_bill")),
                      form.get("status_name"), Jsonb(keep), user_id, actor, source))
    except UniqueViolation:  # a second click while the first is still sending
        raise Refused("এই username দিয়ে Desk থেকে আগেই সংযোগ পাঠানো হয়েছে")
    try:
        header_id = api.create_customer(dc.billing_form(form))
    except Exception as e:
        log.warning("new connection %s: billing send failed: %s", row["id"], type(e).__name__)
        try:
            made = api.username_taken(username)
        except Exception:
            made = None
        if made is False:
            row = _update(row["id"], status="failed", error=str(e)[:500])
        else:  # the customer may exist: never send again on our own
            row = _update(row["id"], status="unsure",
                          error=("বিলিং-এ তৈরি হয়েছে কিনা নিশ্চিত নয় (username এখন বিলিং-এ আছে), বিলিং-এ দেখে নিন: " if made
                                 else "বিলিং-এর উত্তর পাওয়া যায়নি, বিলিং-এ দেখে নিন: ") + str(e)[:400])
        activity.record(company_id, "connection.failed", actor=actor, source=source, user_id=user_id,
                        subject_type="desk_connection", subject_id=row["id"], note=row["error"],
                        changes={"username": username, "status": row["status"]})
        return row

    code = customer_code(api, username, header_id)
    check = check_secret(router_for(company_id, form.get("server_name")), username, connect=connect, sleep=sleep)
    sec = check["secret"]
    problem = check["error"] or dc.secret_matches(sec, form)
    row = _update(row["id"], status="check" if problem else "done", header_id=header_id, customer_code=code,
                  secret_profile=(sec or {}).get("profile"), secret_disabled=(sec or {}).get("disabled"),
                  error=problem)
    activity.record(company_id, "connection.created", actor=actor, source=source, user_id=user_id,
                    subject_type="desk_connection", subject_id=row["id"], customer_code=code,
                    changes={k: keep.get(k) for k in ("name", "username", "server_name", "package_name", "profile",
                                                       "status_name", "monthly_bill", "expire_day", "bill_start")},
                    note=problem)
    if sync:
        try:
            sync(username)
        except Exception:
            log.exception("new connection %s: copying the customer into the Desk failed", row["id"])
    return row
