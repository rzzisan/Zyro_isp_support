"""Meta-side switches for WhatsApp calls on a company's number (Calling API over SIP into our Asterisk).

    python -m engine.wa_calling show <company_id>                  calling settings as Meta has them
    python -m engine.wa_calling enable <company_id> [--show-icon]  calling on, SIP to SIP_HOST:SIP_PORT; the call icon stays
                                                                   hidden unless --show-icon (callers then need a call button)
    python -m engine.wa_calling disable <company_id>               calling off
    python -m engine.wa_calling button <company_id> <to>           send a "call us" button (inside the 24 h window)

Which calls the bot takes is set in Desk (WhatsApp নম্বর → WhatsApp কল); this only changes what Meta does.
"""
import json
import os
import sys

import httpx

from engine import db
from engine import tenant as tenants
from engine.whatsapp import GRAPH, _check, recipient

SIP_HOST = os.environ.get("WA_SIP_HOST", "desk.zyrotechbd.com")
# 15061, not 5061: SIP ports are blocked upstream of bsol (deploy/asterisk/pjsip.conf)
SIP_PORT = int(os.environ.get("WA_SIP_PORT", "15061"))


def tenant(company_id: int):
    row = db.one("select phone_number_id from wa_accounts where company_id = %s order by id limit 1", (company_id,))
    t = tenants.by_phone_number_id(row["phone_number_id"]) if row else None
    if not t:
        sys.exit(f"company {company_id}: no WhatsApp number")
    return t


def show(t) -> dict:
    r = httpx.get(f"{GRAPH}/{t.phone_number_id}/settings", headers={"Authorization": f"Bearer {t.wa_token}"}, timeout=30)
    data = _check(r)
    return {"calling": data.get("calling")}


def set_calling(t, on: bool, show_icon: bool = False) -> dict:
    calling = {"status": "ENABLED", "call_icon_visibility": "DEFAULT" if show_icon else "DISABLE_ALL",
               "srtp_key_exchange_protocol": "DTLS",
               # webhook_delivery: call events (call_created, terminate, errors) also come to the webhook (wa_events)
               "sip": {"status": "ENABLED", "webhook_delivery": "ENABLED",
                       "servers": [{"hostname": SIP_HOST, "port": SIP_PORT}]}} if on \
        else {"status": "DISABLED"}
    r = httpx.post(f"{GRAPH}/{t.phone_number_id}/settings", json={"calling": calling},
                   headers={"Authorization": f"Bearer {t.wa_token}"}, timeout=30)
    return _check(r)


def call_button(t, to: str, text: str = "আমাদের সাথে WhatsApp-এ কথা বলতে নিচের বোতামে চাপ দিন।") -> dict:
    r = httpx.post(f"{GRAPH}/{t.phone_number_id}/messages", json={
        "messaging_product": "whatsapp", **recipient(to), "type": "interactive",
        "interactive": {"type": "voice_call", "body": {"text": text},
                        "action": {"name": "voice_call", "parameters": {"display_text": "কল করুন", "ttl_minutes": 1440}}}},
        headers={"Authorization": f"Bearer {t.wa_token}"}, timeout=30)
    return _check(r)


def main() -> None:
    args = sys.argv[1:]
    if len(args) < 2 or args[0] not in ("show", "enable", "disable", "button"):
        sys.exit(__doc__)
    t = tenant(int(args[1]))
    if args[0] == "show":
        out = show(t)
    elif args[0] == "enable":
        out = set_calling(t, True, "--show-icon" in args)
    elif args[0] == "disable":
        out = set_calling(t, False)
    else:
        if len(args) < 3:
            sys.exit(__doc__)
        out = call_button(t, args[2])
    print(json.dumps(out, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
