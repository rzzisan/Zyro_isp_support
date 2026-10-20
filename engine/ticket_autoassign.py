"""Auto-assign a new ticket to the staff who last worked in the customer's area.

area_staff (kept in the desk, never emptied) holds who worked tickets in which zone / sub zone / box: refresh() folds
every synced ticket into it (solved_by of solved tickets, assigned_to of open ones). A new ticket with no staff goes to
the one who last worked the customer's box, else the same sub zone, else the same zone; a row pinned in the desk wins
in its area. Names are mapped to billing employees (billing_options kind 'employees'); employees inactive in billing or
on the company's exclude list are skipped (Desk technicians' on/off is about WhatsApp, not work, so it is not used). Staff picked by hand always stay: callers only ask here
when none was given. Switch: bot_settings.auto_assign.

    python -m engine.ticket_autoassign <company_id> <customer_id>   # shows whom a new ticket would go to
"""
import logging
import sys

from engine import db

log = logging.getLogger("zyro-ticket-autoassign")

LEVELS = (("box", ("zone", "subzone", "box")), ("subzone", ("zone", "subzone")), ("zone", ("zone",)))
LEVEL_BN = {"box": "বক্স", "subzone": "সাব-জোন", "zone": "জোন"}
_SPACE = r"regexp_replace(btrim(COALESCE({}, '')), '\s+', ' ', 'g')"


def _norm(s) -> str:
    return " ".join(str(s or "").split()).lower()


def enabled(t) -> bool:
    return bool((t.bot or {}).get("auto_assign", True))


def refresh(company_id: int) -> int:
    """Fold the synced tickets into area_staff (counts and last time only grow; rows are never removed)."""
    z, sz, b = (_SPACE.format(c) for c in ("zone", "subzone", "box"))
    row = db.execute(
        rf"""WITH w AS (
              SELECT {z} AS zone, {sz} AS subzone, {b} AS box, t.complain_id,
                     COALESCE(t.solved_at, t.opened_at, t.created_at) AS at,
                     -- "Maruf (10-07-26)" -> "Maruf"
                     regexp_replace(btrim(regexp_replace(n, '\s*\([0-9./-]+\)\s*$', '')), '\s+', ' ', 'g') AS name
              FROM billing_tickets t,
                   unnest(string_to_array(CASE WHEN t.state = 'solved' THEN t.solved_by ELSE t.assigned_to END, ',')) n
              WHERE t.company_id = %s),
            a AS (
              SELECT zone, subzone, box, name, count(DISTINCT complain_id) AS tickets, max(at) AS last_at,
                     (array_agg(complain_id ORDER BY at DESC NULLS LAST))[1] AS last_id
              FROM w WHERE zone <> '' AND name <> '' GROUP BY zone, subzone, box, name),
            u AS (
              INSERT INTO area_staff (company_id, zone, subzone, box, employee_name, tickets, last_complain_id,
                                      last_worked_at, created_at, updated_at)
              SELECT %s, zone, subzone, box, name, tickets, last_id, last_at, now(), now() FROM a
              ON CONFLICT (company_id, zone, subzone, box, employee_name) DO UPDATE SET
                tickets = GREATEST(area_staff.tickets, EXCLUDED.tickets),
                last_complain_id = CASE WHEN area_staff.last_worked_at IS NULL
                    OR EXCLUDED.last_worked_at >= area_staff.last_worked_at
                  THEN EXCLUDED.last_complain_id ELSE area_staff.last_complain_id END,
                last_worked_at = GREATEST(area_staff.last_worked_at, EXCLUDED.last_worked_at),
                updated_at = now()
              WHERE area_staff.tickets IS DISTINCT FROM GREATEST(area_staff.tickets, EXCLUDED.tickets)
                 OR area_staff.last_worked_at IS DISTINCT FROM GREATEST(area_staff.last_worked_at, EXCLUDED.last_worked_at)
              RETURNING 1)
            SELECT count(*) AS n FROM u""", (company_id, company_id))
    return row["n"] if row else 0


def _area(company_id: int, header_id=None, customer: dict | None = None) -> dict:
    row = db.one("SELECT zone, subzone, box FROM billing_customers WHERE company_id = %s AND header_id = %s",
                 (company_id, int(header_id))) if header_id else None
    c = customer or {}
    area = {"zone": (row or {}).get("zone") or c.get("ZoneName") or c.get("Zone"),
            "subzone": (row or {}).get("subzone") or c.get("SubZoneName") or c.get("SubZone"),
            "box": (row or {}).get("box") or c.get("BoxName") or c.get("Box")}
    return {k: _norm(v) for k, v in area.items()}


def _employees(company_id: int) -> dict[str, str]:
    """billing employee name (normalised) -> id: active in billing and not on the company's "never auto-assign" list
    (bot_settings.auto_assign_exclude, e.g. the owner or a shared support login)."""
    bot = db.one("SELECT auto_assign_exclude FROM bot_settings WHERE company_id = %s", (company_id,)) or {}
    off = {str(x) for x in (bot.get("auto_assign_exclude") or [])}
    return {_norm(r["name"]): str(r["option_id"]) for r in db.all_rows(
        "SELECT option_id, name FROM billing_options WHERE company_id = %s AND kind = 'employees' AND active",
        (company_id,)) if str(r["option_id"]) not in off}


def pick(company_id: int, header_id=None, customer: dict | None = None) -> dict | None:
    """{'employees': [ids], 'names': [...], 'level': 'box'|'subzone'|'zone', 'from_ticket': complain_id} or None."""
    area = _area(company_id, header_id, customer)
    if not area["zone"]:
        return None
    staff = _employees(company_id)
    if not staff:
        return None
    for level, keys in LEVELS:
        if not all(area[k] for k in keys):
            continue
        where = " AND ".join(f"lower({k}) = %s" for k in keys)
        rows = [r for r in db.all_rows(
            f"""SELECT employee_name, pinned, last_complain_id, last_worked_at FROM area_staff
                WHERE company_id = %s AND {where} ORDER BY pinned DESC, last_worked_at DESC NULLS LAST""",
            (company_id, *[area[k] for k in keys])) if _norm(r["employee_name"]) in staff]
        if not rows:
            continue
        top = rows[0]
        # pinned: everyone pinned here; otherwise the people on that last ticket (a pair often goes together)
        team = [r for r in rows if r["pinned"]] if top["pinned"] else \
            [r for r in rows if not r["pinned"] and r["last_complain_id"] == top["last_complain_id"]]
        names = list(dict.fromkeys(r["employee_name"] for r in team))
        return {"employees": list(dict.fromkeys(staff[_norm(n)] for n in names)), "names": names, "level": level,
                "pinned": bool(top["pinned"]), "from_ticket": top["last_complain_id"]}
    return None


def assign(t, api, complain_id, header_id=None, customer: dict | None = None) -> dict | None:
    """Assign an open ticket that has no staff yet; returns the pick (None when nothing fitted or it is switched off)."""
    if not complain_id or not enabled(t):
        return None
    p = pick(t.company_id, header_id, customer)
    if not p:
        return None
    api.assign_ticket(int(complain_id), [int(e) for e in p["employees"]])
    log.info("ticket %s auto-assigned to %s (%s%s, last ticket %s)", complain_id, p["names"], p["level"],
             " pinned" if p["pinned"] else "", p["from_ticket"])
    return p


def label(p: dict | None) -> str:
    return f"{', '.join(p['names'])} (একই {LEVEL_BN[p['level']]})" if p else ""


def main() -> None:
    company_id, customer_id = int(sys.argv[1]), sys.argv[2]
    c = db.one("""SELECT header_id, customer_id, name, zone, subzone, box FROM billing_customers
                  WHERE company_id = %s AND ltrim(customer_id, '0') = ltrim(%s, '0')""", (company_id, customer_id))
    if not c:
        sys.exit("customer not found")
    print(dict(c))
    print("area_staff rows changed:", refresh(company_id))
    print(pick(company_id, c["header_id"]))


if __name__ == "__main__":
    main()
