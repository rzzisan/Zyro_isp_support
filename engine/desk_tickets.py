"""Support tickets kept in the Desk itself, for companies whose বিলিং সংযোগ page says new tickets open in the Desk
(billing_connections.tickets_in_billing off).

DeskTickets has the same ticket calls as ISPDigital (create_ticket, open_tickets_for, assign_ticket, update_ticket,
solve_ticket, ticket_solvers, ticket_form, support_options), on billing_tickets rows with source 'desk', so the bots,
auto-assign and the technician messages work the same on either. A ticket stays where it was opened: for_ticket()
gives billing for an old billing ticket and the Desk for a Desk one, so the switch can be flipped either way.

Desk numbers carry on in their own range, from the next 100000 above billing's highest number (billing at 57301 ->
the first Desk ticket is 100001), so they never meet billing's numbers. Every Desk action is kept in ticket_events.
"""
import json
import logging
from zoneinfo import ZoneInfo

from engine import db

log = logging.getLogger("zyro-desk-tickets")
DHAKA = ZoneInfo("Asia/Dhaka")
PRIORITY = {1: "low", 2: "medium", 3: "high"}
OPEN = ("pending", "processing")


def log_event(company_id: int, complain_id, kind: str, user_id: int | None = None, actor: str | None = None,
              detail: dict | None = None) -> None:
    try:
        db.execute("""INSERT INTO ticket_events (company_id, complain_id, kind, user_id, actor, detail, created_at)
                      VALUES (%s, %s, %s, %s, %s, %s, now())""",
                   (company_id, str(complain_id), kind, user_id or None, actor,
                    json.dumps(detail, ensure_ascii=False) if detail else None))
    except Exception:
        log.warning("ticket %s: could not keep the %s event", complain_id, kind, exc_info=True)


def desk_made(company_id: int, kind: str, ids) -> list[str]:
    """Which of these list ids were added in the Desk (billing_options.desk): billing doesn't know them."""
    ids = [str(i) for i in (ids if isinstance(ids, (list, tuple, set)) else [ids]) if i not in (None, "")]
    if not ids:
        return []
    return [r["option_id"] for r in db.all_rows(
        """SELECT option_id FROM billing_options WHERE company_id = %s AND kind = %s AND desk AND option_id = ANY(%s)""",
        (company_id, kind, ids))]


def member_name(company_id: int, user_id: int | None) -> str | None:
    if not user_id:
        return None
    row = db.one("""SELECT u.name FROM users u JOIN company_user cu ON cu.user_id = u.id
                    WHERE cu.company_id = %s AND u.id = %s""", (company_id, int(user_id)))
    return row["name"] if row else None


def source(company_id: int, complain_id) -> str | None:
    row = db.one("SELECT source FROM billing_tickets WHERE company_id = %s AND complain_id = %s",
                 (company_id, str(complain_id)))
    return row["source"] if row else None


def for_new(t, user_id: int = 0, actor: str | None = None, billing=None):
    """Where a new ticket opens: the Desk, or billing (billing() gives that client; default the member's own login)."""
    if t.desk_tickets:
        return DeskTickets(t, user_id, actor)
    if billing:
        return billing()
    from engine import tenant as tenants
    return tenants.billing_for_user(t, int(user_id or 0))


def for_ticket(t, complain_id, user_id: int = 0, actor: str | None = None, billing=None):
    """Where this ticket lives: a Desk ticket is always worked in the Desk, any other in billing."""
    if source(t.company_id, complain_id) == "desk":
        return DeskTickets(t, user_id, actor)
    if billing:
        return billing()
    from engine import tenant as tenants
    return tenants.billing_for_user(t, int(user_id or 0))


def next_number(company_id: int) -> str:
    row = db.execute(
        """INSERT INTO ticket_numbers (company_id, last_no, created_at, updated_at)
           SELECT %s, (COALESCE(max(CASE WHEN complain_id ~ '^[0-9]{1,15}$' THEN complain_id::bigint END), 0) / 100000 + 1)
                      * 100000 + 1, now(), now()
           FROM billing_tickets WHERE company_id = %s
           ON CONFLICT (company_id) DO UPDATE SET last_no = ticket_numbers.last_no + 1, updated_at = now()
           RETURNING last_no""", (company_id, company_id))
    return str(row["last_no"])


def _dhaka(at) -> str | None:
    """naive UTC -> '10/24/2026 06:43:34 PM' (billing's own format, as the bots already read it)."""
    if not at:
        return None
    return at.replace(tzinfo=ZoneInfo("UTC")).astimezone(DHAKA).strftime("%m/%d/%Y %I:%M:%S %p")


class DeskTickets:
    """The ticket calls of ISPDigital, on the Desk's own tables."""

    def __init__(self, t, user_id: int | None = 0, actor: str | None = None):
        self.t = t
        self.company_id = t.company_id
        self.user_id = int(user_id or 0) or None
        self.actor = actor or member_name(self.company_id, self.user_id)
        self.created_id: str | None = None

    # --- lists -----------------------------------------------------------------------
    def _options(self, kind: str) -> dict[str, str]:
        return {str(r["option_id"]): r["name"] for r in db.all_rows(
            """SELECT option_id, name FROM billing_options WHERE company_id = %s AND kind = %s AND active
               ORDER BY name""", (self.company_id, kind))}

    def support_options(self) -> dict:
        return {k: self._options(k) for k in ("categories", "priorities", "departments", "employees")}

    def ticket_form(self, fresh: bool = False) -> tuple[None, dict[str, str]]:
        return None, {name: cid for cid, name in self._options("categories").items()}

    # --- read --------------------------------------------------------------------------
    def _row(self, complain_id) -> dict:
        row = db.one("SELECT * FROM billing_tickets WHERE company_id = %s AND complain_id = %s",
                     (self.company_id, str(complain_id)))
        if not row:
            raise RuntimeError("টিকিট পাওয়া যায়নি")
        return row

    def open_tickets_for(self, username: str) -> list[dict]:
        """Open tickets of this PPPoE user (Desk ones and the billing ones copied here), newest first, in billing's
        field names."""
        if not username:
            return []
        return [{"ComplainId": r["complain_id"], "UserName": r["username"], "Problem": r["category"],
                 "Status": 1 if r["state"] == "processing" else 0, "SolvedBy": r["assigned_to"],
                 "CreatedOn": _dhaka(r["opened_at"]), "CustomerHeaderId": r["customer_header_id"]}
                for r in db.all_rows(
                    """SELECT complain_id, username, category, state, assigned_to, opened_at, customer_header_id
                       FROM billing_tickets WHERE company_id = %s AND lower(username) = lower(%s) AND state = ANY(%s)
                       ORDER BY opened_at DESC NULLS LAST, id DESC""", (self.company_id, username, list(OPEN)))]

    def ticket_solvers(self, complain_id) -> dict:
        raw = self._row(complain_id).get("raw") or {}
        return {"EmployeeIds": [int(e) if str(e).isdigit() else e for e in raw.get("EmployeeIds") or []],
                "DepartmentId": raw.get("DepartmentId")}

    # --- write -------------------------------------------------------------------------
    def create_ticket(self, header_id: int, category_id: str, priority_id: int, mobile: str,
                      comment: str, send_sms: bool = False) -> str:
        """send_sms is billing's own customer SMS; the Desk sends its SMS itself, as with billing tickets."""
        c = db.one("""SELECT header_id, customer_id, name, username, mobile, zone, subzone, box FROM billing_customers
                      WHERE company_id = %s AND header_id = %s""", (self.company_id, int(header_id)))
        if not c:
            raise RuntimeError("কাস্টমার পাওয়া যায়নি")
        cats = self._options("categories")
        if str(category_id) not in cats:
            raise RuntimeError("এই সমস্যার ধরন তালিকায় নেই")
        prio = int(priority_id or 2)
        no = next_number(self.company_id)
        raw = {"ComplainId": int(no), "CustomerHeaderId": c["header_id"], "CustomerId": c["customer_id"],
               "UserName": c["username"], "ProblemCategoryId": int(category_id), "ProblemPriorityId": prio,
               "ComplainNumber": mobile or c["mobile"], "EmployeeIds": [], "DepartmentId": None}
        db.execute(
            """INSERT INTO billing_tickets (company_id, complain_id, customer_id, customer_header_id, username, customer_name,
                   mobile, zone, subzone, box, category, priority, state, created_by, description, opened_at, raw, source,
                   synced_at, created_at, updated_at)
               VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'pending',%s,%s, now(), %s, 'desk', now(), now(), now())""",
            (self.company_id, no, c["customer_id"], c["header_id"], c["username"], c["name"], c["mobile"], c["zone"],
             c["subzone"], c["box"], cats[str(category_id)], PRIORITY.get(prio, "medium"), self.actor,
             (comment or "").strip()[:1900], json.dumps(raw, ensure_ascii=False)))
        log_event(self.company_id, no, "open", self.user_id, self.actor,
                  {"category": cats[str(category_id)], "priority": PRIORITY.get(prio), "mobile": mobile})
        self.created_id = no
        log.info("company %s: desk ticket %s opened for %s by %s", self.company_id, no, c["customer_id"], self.actor)
        return f"টিকিট #{no} খোলা হয়েছে"

    def update_ticket(self, complain_id, header_id: int, category_id: str, priority_id: int, mobile: str,
                      comment: str) -> str:
        row = self._row(complain_id)
        if row["state"] not in OPEN:
            raise RuntimeError("সমাধান হওয়া টিকিট এডিট করা যায় না")
        cats = self._options("categories")
        prio = int(priority_id or 2)
        db.execute("""UPDATE billing_tickets SET category = COALESCE(%s, category), priority = %s, description = %s,
                        raw = COALESCE(raw, '{}'::jsonb) || %s::jsonb, updated_at = now()
                      WHERE company_id = %s AND complain_id = %s""",
                   (cats.get(str(category_id)), PRIORITY.get(prio, "medium"), (comment or "").strip()[:1900],
                    json.dumps({"ProblemCategoryId": int(category_id), "ProblemPriorityId": prio, "ComplainNumber": mobile}),
                    self.company_id, str(complain_id)))
        log_event(self.company_id, complain_id, "edit", self.user_id, self.actor,
                  {"category": cats.get(str(category_id)), "priority": PRIORITY.get(prio), "mobile": mobile})
        return "টিকিট এডিট হয়েছে"

    def assign_ticket(self, complain_id, employee_ids: list, dept_id=None, sms_employees: bool = False) -> dict:
        """sms_employees is billing's own staff SMS: Desk technicians get WhatsApp (engine/ticket_notify.py)."""
        row = self._row(complain_id)
        if row["state"] not in OPEN:
            raise RuntimeError("টিকিটটা আগেই সমাধান হয়েছে")
        emps = self._options("employees")
        ids = [str(e) for e in employee_ids or []]
        names = [emps.get(e, e) for e in ids]
        before = [str(e) for e in (row.get("raw") or {}).get("EmployeeIds") or []]
        db.execute("""UPDATE billing_tickets SET assigned_to = %s, state = %s,
                        raw = COALESCE(raw, '{}'::jsonb) || %s::jsonb, updated_at = now()
                      WHERE company_id = %s AND complain_id = %s""",
                   (", ".join(names) or None, "processing" if ids else "pending",
                    json.dumps({"EmployeeIds": [int(e) if e.isdigit() else e for e in ids],
                                "DepartmentId": int(dept_id) if str(dept_id or "").isdigit() else None}),
                    self.company_id, str(complain_id)))
        log_event(self.company_id, complain_id, "assign", self.user_id, self.actor, {"employees": names})
        if set(ids) - set(before):
            try:  # the newly assigned technicians, as the sync does for billing tickets
                from engine.ticket_notify import notify
                notify(self.t, self, [str(complain_id)])
            except Exception:
                log.exception("ticket %s: technician messages failed", complain_id)
        return {"EmployeeIds": ids}

    def solve_ticket(self, complain_id, remark: str = "") -> None:
        row = self._row(complain_id)
        if row["state"] not in OPEN:
            raise RuntimeError("টিকিটটা আগেই সমাধান হয়েছে")
        remark = (remark or "").strip()[:500]
        db.execute("""UPDATE billing_tickets SET state = 'solved', solved_at = now(),
                        solved_by = COALESCE(NULLIF(assigned_to, ''), %s), note = NULLIF(%s, ''),
                        raw = COALESCE(raw, '{}'::jsonb) || %s::jsonb, updated_at = now()
                      WHERE company_id = %s AND complain_id = %s""",
                   (self.actor, remark, json.dumps({"SolveOrRejectedRemarks": remark}, ensure_ascii=False),
                    self.company_id, str(complain_id)))
        log_event(self.company_id, complain_id, "solve", self.user_id, self.actor, {"remark": remark} if remark else None)

