"""Copy support tickets from each company's billing software into billing_tickets.

Open tickets: the whole Daily Complain list (pending + processing). Solved tickets: Support History
for the last --days days. Run every few minutes by zyro-ticket-sync.timer; --days 90 for a backfill.
  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.ticket_sync [--days 3] [--company ID]
"""
import argparse
import html
import json
import logging
import re
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo

from engine import db, tenant as tenants

log = logging.getLogger("zyro-ticket-sync")
DHAKA = ZoneInfo("Asia/Dhaka")
PRIORITY = {1: "low", 2: "medium", 3: "high"}
PAGE = 500


def parse_time(s: str | None) -> datetime | None:
    """'10/04/2026 06:43:34 PM' (Bangladesh time) -> naive UTC."""
    if not s:
        return None
    try:
        local = datetime.strptime(s.strip(), "%m/%d/%Y %I:%M:%S %p").replace(tzinfo=DHAKA)
    except ValueError:
        return None
    return local.astimezone(timezone.utc).replace(tzinfo=None)


def fetch_all(api, path: str, params: dict) -> list[dict]:
    rows, start = [], 0
    while True:
        d = api._get(path, {"draw": 1, "start": start, "length": PAGE, "search[value]": "", "search[regex]": "false", **params})
        batch = d.get("aaData") or []
        rows += batch
        start += PAGE
        if len(batch) < PAGE or start >= int(d.get("iTotalDisplayRecords") or 0):
            return rows


def names(v) -> str | None:
    if isinstance(v, list):
        return ", ".join(str(x) for x in v if x) or None
    return (str(v).strip() or None) if v else None


def upsert(company_id: int, r: dict, state: str) -> None:
    solved = state == "solved"
    note = " / ".join(x for x in (r.get("Comment"), r.get("Remarks"), r.get("SolveOrRejectedRemarks")) if x) or None
    db.execute(
        """INSERT INTO billing_tickets (company_id, complain_id, customer_id, customer_header_id, username, customer_name,
               mobile, zone, subzone, box, category, priority, state, assigned_to, solved_by, created_by, note, opened_at,
               solved_at, raw, synced_at, created_at, updated_at)
           VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s, now(), now(), now())
           ON CONFLICT (company_id, complain_id) DO UPDATE SET
             customer_id = EXCLUDED.customer_id, customer_header_id = EXCLUDED.customer_header_id,
             username = EXCLUDED.username, customer_name = EXCLUDED.customer_name, mobile = EXCLUDED.mobile,
             zone = EXCLUDED.zone, subzone = EXCLUDED.subzone, box = EXCLUDED.box, category = EXCLUDED.category,
             priority = EXCLUDED.priority, state = EXCLUDED.state,
             assigned_to = COALESCE(EXCLUDED.assigned_to, billing_tickets.assigned_to),
             solved_by = EXCLUDED.solved_by, created_by = EXCLUDED.created_by, note = EXCLUDED.note,
             opened_at = COALESCE(EXCLUDED.opened_at, billing_tickets.opened_at), solved_at = EXCLUDED.solved_at,
             raw = EXCLUDED.raw, synced_at = now(), updated_at = now()""",
        (company_id, str(r.get("ComplainId")), r.get("CustomerId"), r.get("CustomerHeaderId"), r.get("UserName"),
         r.get("CustomerName"), r.get("MobileNumber"), r.get("Zone"), r.get("SubZone"), r.get("Box") or None,
         r.get("Problem"), PRIORITY.get(r.get("ProblemPriorityId")), state,
         None if solved else names(r.get("SolvedBy")), names(r.get("SolvedBy")) if solved else None,
         r.get("CreatedBy"), note, parse_time(r.get("CreationDate")), parse_time(r.get("SolvedTime")) if solved else None,
         json.dumps(r, ensure_ascii=False)),
    )


def html_text(s: str | None) -> str:
    """'<p>line one</p><p>two&nbsp;</p>' -> 'line one\ntwo'."""
    s = re.sub(r"(?i)<br\s*/?>|</p>|</div>|</li>", "\n", s or "")
    s = html.unescape(re.sub(r"<[^>]+>", "", s)).replace("\xa0", " ")
    return "\n".join(x.strip() for x in s.splitlines() if x.strip())


def fetch_description(api, complain_id: str) -> str:
    """The description typed when the ticket was opened = its first staff-to-staff conversation entry."""
    rows = api._get("/ComplainDiscussion/GetConversationInfoByTicketId",
                    {"id": complain_id, "forWhom": "EmployeeToEmployee"}) or []
    rows = sorted((r for r in rows if not r.get("ParentConversationId")), key=lambda r: int(r.get("ConversationId") or 0))
    return html_text(rows[0].get("Comments")) if rows else ""


def fill_descriptions(api, company_id: int) -> int:
    """Refresh the description of every open ticket ('' when it has none); staff edit it in the billing software."""
    n = 0
    for r in db.all_rows("""SELECT complain_id, description FROM billing_tickets WHERE company_id = %s
                            AND state IN ('pending', 'processing')""", (company_id,)):
        try:
            d = fetch_description(api, r["complain_id"])
        except Exception:
            log.warning("description fetch failed for ticket %s", r["complain_id"], exc_info=True)
            continue
        if d == r["description"]:
            continue
        db.execute("UPDATE billing_tickets SET description = %s WHERE company_id = %s AND complain_id = %s",
                   (d, company_id, r["complain_id"]))
        n += 1
    return n


def sync_company(t, days: int) -> dict:
    api = tenants.billing(t)
    open_rows = fetch_all(api, "/ClientSupport/AjaxDailyComplainList", {})
    processing = {str(r.get("ComplainId")) for r in fetch_all(api, "/ClientSupport/AjaxDailyComplainList",
                                                                {"customQueryString": "processing"})}
    before = {r["complain_id"]: r["assigned_to"] for r in db.all_rows(
        "SELECT complain_id, assigned_to FROM billing_tickets WHERE company_id = %s AND state IN ('pending', 'processing')",
        (t.company_id,))}
    for r in open_rows:
        upsert(t.company_id, r, "processing" if str(r.get("ComplainId")) in processing else "pending")
    described = fill_descriptions(api, t.company_id)
    # staff changed (new ticket with staff, assigned, re-assigned): tell the linked technicians on WhatsApp
    reassigned = [str(r.get("ComplainId")) for r in open_rows
                  if names(r.get("SolvedBy")) and names(r.get("SolvedBy")) != before.get(str(r.get("ComplainId")))]
    try:
        from engine.ticket_notify import notify
        notified = notify(t, api, reassigned)
    except Exception:
        log.exception("technician ticket messages failed for company %s", t.company_id)
        notified = 0

    today = datetime.now(DHAKA).date()
    solved = fetch_all(api, "/ClientSupport/AjaxMonthlyComplainList", {
        "fromDateString": (today - timedelta(days=days)).strftime("%d-%m-%Y"), "toDateString": today.strftime("%d-%m-%Y"),
        "solvedBy": "", "problemCategory": "", "zone": "", "orderBy": "", "permissionId": "1"})
    for r in solved:
        upsert(t.company_id, r, "solved")

    # open here but gone from the billing open list and not seen as solved: closed some other way
    open_ids = [str(r.get("ComplainId")) for r in open_rows]
    gone = db.execute(
        """WITH u AS (UPDATE billing_tickets SET state = 'closed', synced_at = now(), updated_at = now()
                      WHERE company_id = %s AND state IN ('pending', 'processing') AND NOT (complain_id = ANY(%s))
                      RETURNING 1)
           SELECT count(*) AS n FROM u""", (t.company_id, open_ids))
    return {"open": len(open_rows), "processing": len(processing), "solved": len(solved), "closed": gone["n"], "described": described,
            "reassigned": len(reassigned), "notified": notified}


def companies(only: int | None) -> list:
    rows = db.all_rows(
        """SELECT DISTINCT w.phone_number_id FROM wa_accounts w JOIN billing_connections b ON b.company_id = w.company_id
           JOIN companies c ON c.id = w.company_id WHERE c.status IN ('trial', 'active')"""
        + (" AND w.company_id = %s" if only else ""), (only,) if only else ())
    seen, out = set(), []
    for r in rows:
        t = tenants.by_phone_number_id(r["phone_number_id"])
        if t and t.company_id not in seen and t.billing:
            seen.add(t.company_id)
            out.append(t)
    return out


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    logging.getLogger("httpx").setLevel(logging.WARNING)
    p = argparse.ArgumentParser()
    p.add_argument("--days", type=int, default=3)
    p.add_argument("--company", type=int)
    a = p.parse_args()
    for t in companies(a.company):
        try:
            log.info("company %s: %s", t.company_id, sync_company(t, a.days))
        except Exception:
            log.exception("ticket sync failed for company %s", t.company_id)


if __name__ == "__main__":
    main()
