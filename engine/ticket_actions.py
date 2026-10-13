"""Bot ticket actions: a customer's open tickets, solving them (customer says it's fixed, or a technician asks),
and the follow-up question before WhatsApp's 24-hour window closes.

Follow-up, run every 10 minutes by zyro-ticket-followup.timer:
  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.ticket_actions [--company ID] [--dry]
"""
import argparse
import logging
import os

from engine import db, tenant as tenants
from engine.tenant import Tenant

log = logging.getLogger("zyro-engine")
OPEN = ("pending", "processing")
FOLLOWUP_MODEL = "ticket followup"


def open_tickets(t: Tenant, customer_id: str | None) -> list[dict]:
    if not customer_id:
        return []
    return db.all_rows(
        """SELECT complain_id, category, state, opened_at FROM billing_tickets
           WHERE company_id = %s AND ltrim(customer_id, '0') = ltrim(%s, '0') AND state = ANY(%s)
           ORDER BY opened_at DESC NULLS LAST""", (t.company_id, str(customer_id), list(OPEN)))


def solve_for_customer(t: Tenant, customer: dict, remark: str) -> tuple[bool, str]:
    """Solve the customer's open tickets, like the desk's Solve: only while the line is online on our MikroTik."""
    tickets = open_tickets(t, customer.get("CustomerId"))
    if not tickets:
        return False, "এই কাস্টমারের কোনো খোলা টিকিট নেই।"
    nos = ", ".join(x["complain_id"] for x in tickets)
    from engine.ppp_sync import online_now
    try:
        mk = online_now(t.company_id, customer.get("UserName") or "", customer.get("Server"))
    except Exception:
        mk = None
    if mk is None:
        return False, f"টিকিট {nos} সমাধান করা হয়নি: MikroTik থেকে উত্তর আসেনি, লাইন অনলাইন কিনা যাচাই করা গেল না।"
    if not mk.get("online"):
        return False, f"টিকিট {nos} সমাধান করা হয়নি: কাস্টমারের লাইন এখন অফলাইন।"
    if os.environ.get("ENGINE_DRY_RUN", "1") == "1":
        return False, f"(টেস্ট মোড: টিকিট {nos} আসলে সমাধান করা হয়নি)"
    api = tenants.billing(t)
    done, failed = [], []
    for x in tickets:
        try:
            api.solve_ticket(int(x["complain_id"]), remark[:500])
            done.append(x["complain_id"])
        except Exception:
            log.exception("solve ticket %s failed", x["complain_id"])
            failed.append(x["complain_id"])
    if done:
        db.execute("""UPDATE billing_tickets SET state = 'solved', solved_at = now(), updated_at = now()
                      WHERE company_id = %s AND complain_id = ANY(%s)""", (t.company_id, done))
    if failed:
        return bool(done), (f"✅ টিকিট {', '.join(done)} সমাধান করা হয়েছে। " if done else "") + \
            f"টিকিট {', '.join(failed)} বিলিংয়ে সমাধান করা যায়নি।"
    return True, f"✅ টিকিট {', '.join(done)} সমাধান করা হয়েছে।"


def followup_candidates(t: Tenant) -> list[dict]:
    """Chats with an open ticket whose last customer message is 20-23.5 hours old (the window closes at 24),
    not yet asked since that message, no one from our side talking in the last hour, ticket at least 2 hours old."""
    return db.all_rows(
        """WITH last_in AS (
               SELECT contact_id, max(created_at) AS at FROM wa_messages
               WHERE company_id = %(c)s AND direction = 'in' GROUP BY contact_id)
           SELECT wc.*, li.at AS last_in_at FROM wa_contacts wc JOIN last_in li ON li.contact_id = wc.id
           WHERE wc.company_id = %(c)s AND wc.customer_id IS NOT NULL
             AND NOT wc.bot_paused AND COALESCE(wc.bot_paused_until <= now(), true)
             AND li.at BETWEEN now() - interval '23 hours 30 minutes' AND now() - interval '20 hours'
             AND wc.wa_number NOT IN (SELECT wa_number FROM technicians WHERE company_id = %(c)s)
             AND EXISTS (SELECT 1 FROM billing_tickets bt WHERE bt.company_id = %(c)s AND bt.state IN ('pending', 'processing')
                           AND ltrim(bt.customer_id, '0') = ltrim(wc.customer_id, '0')
                           AND bt.opened_at < now() - interval '2 hours')
             AND NOT EXISTS (SELECT 1 FROM wa_drafts d WHERE d.contact_id = wc.id AND d.model = %(m)s AND d.created_at > li.at)
             AND NOT EXISTS (SELECT 1 FROM wa_messages o WHERE o.contact_id = wc.id AND o.direction = 'out'
                               AND o.created_at > now() - interval '1 hour')""",
        {"c": t.company_id, "m": FOLLOWUP_MODEL})


def followup_company(t: Tenant, dry: bool = False) -> int:
    from engine.main import deliver
    api = tenants.billing(t)
    sent = 0
    for c in followup_candidates(t):
        tickets = open_tickets(t, c["customer_id"])
        username = db.one("""SELECT username FROM billing_customers WHERE company_id = %s
                             AND ltrim(customer_id, '0') = ltrim(%s, '0') AND gone_at IS NULL""",
                          (t.company_id, c["customer_id"]))
        try:  # the copy can be up to 5 minutes old: ask billing whether it's still open
            still = {str(x.get("ComplainId")) for x in api.open_tickets_for((username or {}).get("username") or "")}
        except Exception:
            still = {x["complain_id"] for x in tickets}
        tickets = [x for x in tickets if x["complain_id"] in still]
        if not tickets:
            continue
        nos = ", ".join(x["complain_id"] for x in tickets)
        text = (f"জি, আপনার লাইনের যে সমস্যার অভিযোগ দিয়েছিলেন (নম্বর {nos}), সেটা কি সমাধান হয়েছে? "
                "লাইন এখন ঠিকমতো চলছে কিনা একটু জানাবেন।")
        if dry:
            log.info("would ask contact %s about %s", c["id"], nos)
            continue
        deliver(t, c, None, text, f"টিকিট {nos}: সমাধান হয়েছে কিনা জানতে চাওয়া হলো", provider="flow", model=FOLLOWUP_MODEL)
        sent += 1
    return sent


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    logging.getLogger("httpx").setLevel(logging.WARNING)
    p = argparse.ArgumentParser()
    p.add_argument("--company", type=int)
    p.add_argument("--dry", action="store_true")
    a = p.parse_args()
    from engine.ticket_sync import companies
    for t in companies(a.company):
        if not t.active or (t.bot.get("bot_mode") or "shadow") != "live":
            continue
        try:
            log.info("company %s: %s follow-ups", t.company_id, followup_company(t, a.dry))
        except Exception:
            log.exception("ticket follow-up failed for company %s", t.company_id)


if __name__ == "__main__":
    main()
