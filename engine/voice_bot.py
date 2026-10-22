"""Hotline voice bot. Asterisk registers each company's hotline extension (Desk → সেটিংস → হটলাইন (SIP)) and asks
GET /mode whether to take a call. When the bot is on for this caller ("test" = only the test numbers, "live" = all) the
extension rings for the set seconds (the agents' phones ring at the same time) and then answers; the call audio comes
here over AudioSocket and goes to Gemini Live, which listens and speaks Bangla. The caller's customer data (bill,
due, line) is fetched while it rings. Both sides are recorded (MP3 in the panel's storage) with the transcript in
call_logs, shown on the panel's কল লগ page.

AudioSocket: frames of [type:1][length:2 BE][payload]; 0x01 = call UUID, 0x10 = 8 kHz 16-bit mono PCM, 0x00 = hangup.
Gemini Live takes 16 kHz PCM in and sends 24 kHz PCM out.
"""
import asyncio
import audioop
import base64
import json
import logging
import os
import socket
import subprocess
import time
import uuid
import wave
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone
from urllib.parse import parse_qs, urlparse

import websockets

from engine import db
from engine.sip_calls import normalize

log = logging.getLogger("voice_bot")

STATE_DIR = os.environ.get("VOICE_DIR", "/var/lib/zyro-voice")
REC_DIR = os.environ.get("VOICE_REC_DIR", "/var/www/zyro_isp_support/panel/storage/app/call-recordings")
MODE_PORT, AUDIO_PORT = 8997, 9093
LIVE_URL = "wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContent"
DEFAULT_MODEL = "gemini-3.1-flash-live-preview"
MAX_CALL = 600          # seconds
FRAME = 320             # 20 ms of 8 kHz 16-bit audio
PJSIP_FILE = os.path.join(STATE_DIR, "pjsip_zyro.conf")

pool = ThreadPoolExecutor(max_workers=8)

# played by us the moment the bot calls a slow tool (billing lookups take several seconds)
FILLERS = {
    "lookup_customer": "একটু অপেক্ষা করুন, আমি আপনার লাইনটা চেক করছি।",
    "create_complaint": "একটু অপেক্ষা করুন, আপনার অভিযোগটা লিখে নিচ্ছি।",
}
fillers: dict[str, bytes] = {}   # tool name -> 8 kHz PCM
calls: dict[str, dict] = {}   # AudioSocket UUID -> {company_id, caller, settings, prep (future)}

VOICE_RULES = (
    "\n\nএখন তুমি ফোন কলে কথা বলছ, WhatsApp-এ না। নিয়ম:\n"
    "- শুধু বাংলায়, ছোট ছোট বাক্যে, স্বাভাবিক ভদ্র গলায় কথা বলো। একবারে দুই-তিন বাক্যের বেশি না।\n"
    "- লিংক, মার্কডাউন, ইমোজি, তালিকা চিহ্ন বলবে না। টাকার অঙ্ক ও তারিখ মুখে বলার মতো করে বলো।\n"
    "- নিচের কাস্টমার তথ্য এই কলারের মোবাইল নম্বর মিলিয়ে পাওয়া; এর বাইরে কিছু বানিয়ে বলবে না।\n"
    "- কাস্টমার বাংলাদেশি, বাংলায় (কখনো আঞ্চলিক টানে) কথা বলেন; তাঁর কথা বাংলা হিসেবেই বুঝবে, হিন্দি না।\n"
    "- একই নম্বরে কয়েকটা সংযোগ থাকলে কোনটার কথা জিজ্ঞেস করছে আগে জেনে নাও।\n"
    "- কাস্টমার অন্য কোনো কাস্টমার আইডি বা মোবাইল নম্বর বললে আগে \"একটু দেখছি\" বলে lookup_customer ডাকো, তারপর "
    "সেই ফলাফল থেকেই বলো। আইডি বা তথ্য কখনো অনুমান করবে না, নিজের কাছে থাকা অন্য সংযোগের তথ্য বলবে না।\n"
    "- লাইন বন্ধ বা নেট নেই বললে আগে লাইনের অবস্থা দেখো (pppoe, mikrotik, onu: অনলাইন/অফলাইন, LOS, অপটিক্যাল পাওয়ার)। "
    "বকেয়া থাকলেও বিলের শেষ তারিখ (bill_day) পার না হলে বা লাইন বিলের কারণে বন্ধ (disabled/ব্লক) না দেখালে বিলকে কারণ বলবে না।\n"
    "- অভিযোগ নম্বর, আইডি, টাকার অঙ্ক বা কোনো তথ্য টুলের ফল আসার আগে কখনো বলবে না, অনুমানও করবে না।\n"
    "- লাইন বা অন্য সমস্যার অভিযোগ লিখে রাখতে বললে বা সমাধান না হলে create_complaint দিয়ে বিলিংয়ে টিকিট খোলো, "
    "তারপর অভিযোগ নম্বর জানাও।\n"
    "- কাস্টমার মানুষের সাথে কথা বলতে চাইলে request_callback (flag wants_person) দিয়ে কারণ লিখে রাখো এবং বলো "
    "আমাদের একজন স্টাফ শিগগিরই যোগাযোগ করবেন, তারপর end_call।\n"
    "- কল ছোট রাখো: কাজের কথা শেষ হলেই বিদায়। একই কথা বারবার বলবে না।\n"
    "- কাস্টমার ইন্টারনেট, লাইন, বিল বা সংযোগের বাইরের কথা বললে একবার ভদ্রভাবে বিষয়ে ফিরিয়ে আনো। আবারও অপ্রাসঙ্গিক বা "
    "আবোল-তাবোল কথা বললে আর এগোবে না: বলো \"আমাদের একজন স্টাফ আপনার সাথে যোগাযোগ করবেন, ধন্যবাদ\", "
    "request_callback (flag off_topic) দাও, তারপর end_call।\n"
    "- সমাধান করতে না পারলে request_callback (flag unsolved) দিয়ে একই কথা বলে end_call।\n"
    "- কথা শেষ হলে বিদায় জানিয়ে end_call ডাকো।"
)
STAFF_RULES = (
    "তুমি {company}-এর অফিস সাপোর্ট ডেস্ক, ফোনে কথা বলছ কোম্পানির স্টাফ/টেকনিশিয়ান {tech}-এর সাথে (তিনি কাস্টমার নন)। "
    "WhatsApp-এ স্টাফরা যা পান, ফোনেও তাই দেবে:\n"
    "- কথ্য বাংলায়, খুব সংক্ষেপে, শুধু যা জানতে চেয়েছেন। সম্বোধন \"জি ভাই\"। ভূমিকা বা লম্বা বিদায় নয়।\n"
    "- যেকোনো কাস্টমারের তথ্য দেওয়া যাবে: নাম, ID, PPPoE ID, লাইন অনলাইন কিনা, uptime, IP, MAC, ONU অবস্থা ও "
    "অপটিক্যাল পাওয়ার, OLT, জোন/বক্স, বিল ও বকেয়া, খোলা টিকিট। পাসওয়ার্ড কখনো না। আগে \"একটু দেখছি\" বলে "
    "lookup_customer ডাকো; ID, মোবাইল বা PPPoE ID দিয়ে খোঁজা যায়। ফলাফলের বাইরে কিছু বানাবে না।\n"
    "- \"mikrotik\" এই মুহূর্তের রাউটারের অবস্থা; অনলাইন কিনা বলতে এটাই আগে দেখবে। blocked মানে বিলিং থেকে ব্লক।\n"
    "- টিকিট খুলতে বললে create_complaint (ক্যাটাগরিসহ), সমাধান করতে বললে solve_ticket, লাইন চালু/বন্ধ করতে বললে "
    "switch_line। স্পষ্ট করে না বললে এগুলো ডাকবে না। ফল এলে তবেই জানাবে, আগে নয়।\n"
    "- কাজ শেষ হলে \"ঠিক আছে ভাই\" বলে end_call।\n"
    "- টাকার অঙ্ক, ID, নম্বর মুখে বলার মতো করে স্পষ্ট বলো।"
)
FLAGS = {"off_topic", "wants_person", "unsolved", "time_up"}
CATEGORIES = ["Speed Slow", "Line Off", "Line Not Stable", "অনু লাল বাতি", "অনু বাতি জলে না(ONU Power OFF)", "অনু চার্জার নষ্ট",
              "ফাইবার তার ছিড়া", "Optical Power Low", "রাউটার নষ্ট", "রাউটার কনফিগার", "Password change",
              "Cable problem/Change", "Others Support"]
WRAP_UP = ("(কলের সময় প্রায় শেষ। এখনই এক বাক্যে বলো \"আমাদের একজন স্টাফ আপনার সাথে যোগাযোগ করবেন, ধন্যবাদ\", "
           "দরকার হলে request_callback (flag time_up) দাও, তারপর end_call।)")
TOOLS = [{"functionDeclarations": [
    {"name": "lookup_customer", "description": "Find a line by customer ID or mobile number: bill, due, line status, open tickets.",
     "parameters": {"type": "OBJECT", "properties": {"id_or_mobile": {"type": "STRING", "description": "digits only"}},
                    "required": ["id_or_mobile"]}},
    {"name": "create_complaint", "description": "Open a complaint ticket in the billing software for a customer's line.",
     "parameters": {"type": "OBJECT", "properties": {
         "customer_id": {"type": "STRING", "description": "the line's customer ID"},
         "problem": {"type": "STRING", "description": "the problem in short Bangla"},
         "category": {"type": "STRING", "enum": CATEGORIES}},
         "required": ["customer_id", "problem"]}},
    {"name": "request_callback", "description": "Record that a staff member must contact this customer, with the reason; flags the call in the call log.",
     "parameters": {"type": "OBJECT", "properties": {
         "reason": {"type": "STRING", "description": "short reason in Bangla"},
         "flag": {"type": "STRING", "enum": sorted(FLAGS), "description": "why the bot stops here"}},
         "required": ["reason", "flag"]}},
    {"name": "end_call", "description": "Hang up after saying goodbye, when the conversation is over."},
]}]
# staff (the panel's technicians, by their number) also get what the WhatsApp staff desk does
STAFF_TOOLS = [{"functionDeclarations": [f for f in TOOLS[0]["functionDeclarations"] if f["name"] != "request_callback"] + [
    {"name": "solve_ticket", "description": "Solve the customer's open tickets (only while the line is online).",
     "parameters": {"type": "OBJECT", "properties": {
         "customer_id": {"type": "STRING"}, "remark": {"type": "STRING", "description": "what was done, short Bangla"}},
         "required": ["customer_id"]}},
    {"name": "switch_line", "description": "Turn the customer's line on (enable) or off (disable) in billing, if this staff member may.",
     "parameters": {"type": "OBJECT", "properties": {
         "customer_id": {"type": "STRING"}, "action": {"type": "STRING", "enum": ["enable", "disable"]}},
         "required": ["customer_id", "action"]}},
]}]


# --- settings and Asterisk config ------------------------------------------------------------
def settings_rows() -> list[dict]:
    return db.all_rows("select * from sip_settings where enabled and bot_mode in ('test', 'live')"
                       " and server is not null and username is not null and password is not null order by company_id")


def pjsip_config(rows: list[dict]) -> str:
    out = []
    for r in rows:
        pw = db.decrypt(r["password"])
        if not pw:
            continue
        c, host, port = f"c{r['company_id']}", r["server"], r["port"]
        try:
            ip = socket.gethostbyname(host)
        except OSError:
            ip = host
        user = r["username"]
        out.append(f"""
[{c}-auth]
type=auth
auth_type=userpass
username={r['auth_user'] or user}
password={pw}

[{c}]
type=aor
contact=sip:{host}:{port}

[{c}]
type=endpoint
transport=transport-udp
context=zyro-in
disallow=all
allow=alaw,ulaw
outbound_auth={c}-auth
aors={c}
from_user={user}
from_domain={host}
direct_media=no
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes
identify_by=ip

[{c}-identify]
type=identify
endpoint={c}
match={ip}

[{c}-reg]
type=registration
transport=transport-udp
outbound_auth={c}-auth
server_uri=sip:{host}:{port};transport={r['transport']}
client_uri=sip:{user}@{host}:{port}
contact_user={user}
retry_interval=60
forbidden_retry_interval=300
expiration=300
line=yes
endpoint={c}
""")
    return "".join(out)


def sync_asterisk() -> None:
    text = pjsip_config(settings_rows())
    try:
        old = open(PJSIP_FILE).read()
    except OSError:
        old = None
    if text == old:
        return
    fd = os.open(PJSIP_FILE, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o640)
    with os.fdopen(fd, "w") as f:
        f.write(text)
    r = subprocess.run(["asterisk", "-rx", "pjsip reload"], capture_output=True, text=True, timeout=20)
    log.info("asterisk pjsip reloaded: %s", (r.stdout or r.stderr).strip()[:200])


# --- what the bot knows about the caller -------------------------------------------------------
def tenant_for(company_id: int):
    from engine import tenant as tenants
    row = db.one("select phone_number_id from wa_accounts where company_id = %s order by id limit 1", (company_id,))
    return tenants.by_phone_number_id(row["phone_number_id"]) if row else None


def safe_view(context: dict) -> dict:
    """Like the WhatsApp bot's verified view: the caller's own number matched, but never IPs or MACs."""
    ctx = json.loads(json.dumps(context, ensure_ascii=False, default=str))
    (ctx.get("customer") or {}).pop("registered_mobile", None)
    (ctx.get("pppoe") or {}).pop("ip", None)
    for k in ("address", "caller_id"):
        (ctx.get("mikrotik") or {}).pop(k, None)
    ctx["today"] = (datetime.now(timezone.utc) + timedelta(hours=6)).strftime("%Y-%m-%d")
    return ctx


class _NoBillingOnu:
    """The billing API without its ONU page, which often takes 15+ s; a caller waits on the line meanwhile.
    add_online() puts our own OLT's ONU status in instead."""

    def __init__(self, api):
        self._api = api

    def __getattr__(self, name):
        return getattr(self._api, name)

    def onu_info(self, mac):
        return None


def line_context(t, api, row: dict, staff: bool = False) -> dict:
    """The WhatsApp bot's view of one line: bill, due, MikroTik/ONU status, open tickets.
    Staff see everything (PPPoE IP, MAC) like on the WhatsApp staff desk."""
    from engine import customers
    from engine.ispdigital import diagnose
    from engine.ticket_actions import open_tickets
    ctx = customers.add_online(t, diagnose(_NoBillingOnu(api), customers.fresh(api, row)), row)
    ctx["open_tickets"] = [{"no": x["complain_id"], "category": x["category"], "state": x["state"]}
                           for x in open_tickets(t, row.get("CustomerId"))]
    if staff:
        ctx = json.loads(json.dumps(ctx, ensure_ascii=False, default=str))
        ctx["customer"]["zone"] = ctx["customer"].get("zone") or row.get("ZoneName")
        return ctx
    return safe_view(ctx)


def find_line(t, api, query: str) -> dict | None:
    """A line by customer ID, mobile or (staff) PPPoE ID."""
    from engine import customers
    q = "".join(ch for ch in str(query) if ch.isdigit())
    number = normalize(q)
    if number:
        return customers.by_whatsapp(t, api, number)
    if q and q == str(query).strip():
        return customers.by_id(t, api, q)
    return customers.by_text(t, api, str(query).strip(), allow_bare_id=True)


def lookup_customer(t, id_or_mobile: str, staff: bool = False) -> dict:
    """Tool: any line by customer ID or mobile (Zisan 2026-10-11: whoever gives an ID or number gets full support)."""
    from engine import tenant as tenants
    if not t:
        return {"error": "billing not connected"}
    api = tenants.billing(t)
    row = find_line(t, api, id_or_mobile)
    if not row:
        return {"found": False, "note": "এই আইডি বা নম্বরে কোনো সংযোগ পাওয়া যায়নি; আবার আইডিটা বলতে বলো"}
    return {"found": True, **line_context(t, api, row, staff)}


def staff_action(t, tech: dict, name: str, args: dict) -> dict:
    """Tools only staff have, done exactly as the WhatsApp staff desk does them (same checks and logs)."""
    from engine import tenant as tenants
    api = tenants.billing(t)
    row = find_line(t, api, args.get("customer_id", ""))
    if not row:
        return {"ok": False, "note": "এই আইডিতে সংযোগ পাওয়া যায়নি"}
    if name == "solve_ticket":
        from engine.ticket_actions import solve_for_customer
        ok, msg = solve_for_customer(t, row, args.get("remark") or f"ফোনে {tech['name']}")
        return {"ok": ok, "result": msg}
    from engine.technician import switch_line
    action = "enable" if args.get("action") == "enable" else "disable"
    return {"result": switch_line(t, tech, row, f"[ফোন কল] {action} {args.get('customer_id')}", action)}


def create_complaint(t, caller: str, customer_id: str, problem: str, category: str | None = None) -> dict:
    """Tool: a ticket in the billing software, like the WhatsApp bot's (one open ticket per line)."""
    from engine import customers
    from engine import tenant as tenants
    from engine.ispdigital import normalize_bd_mobile
    if not t:
        return {"error": "billing not connected"}
    if not t.bot.get("auto_ticket", True):
        return {"ok": False, "note": "বট দিয়ে টিকিট খোলা বন্ধ; request_callback দাও"}
    api = tenants.billing(t)
    row = find_line(t, api, customer_id)
    if not row:
        return {"ok": False, "note": "এই আইডিতে সংযোগ পাওয়া যায়নি"}
    existing = api.open_tickets_for(row.get("UserName") or "")
    if existing:
        return {"ok": True, "already_open": True, "ticket_no": existing[0].get("ComplainId")}
    _, cats = api.ticket_form()
    cid = (cats.get(category) if category else None) \
        or next((v for k, v in cats.items() if category and category.lower() in k.lower()), None) \
        or next((v for k, v in cats.items() if any(w in k.lower() for w in ("line off", "internet", "line"))), None) \
        or cats.get("Others Support") or next(iter(cats.values()))
    mobile = normalize_bd_mobile(row.get("MobileNumber") or "") or normalize(caller) or caller
    api.create_ticket(row["CustomerHeaderId"], cid, 2, mobile, f"[ফোন বট] {problem}\nকলার: {caller}")
    created = api.open_tickets_for(row.get("UserName") or "")
    no = created[0].get("ComplainId") if created else None
    try:
        from engine.ticket_autoassign import assign
        assign(t, api, no, row.get("CustomerHeaderId"), row, category_id=cid)
    except Exception:
        log.exception("ticket %s auto-assign failed", no)
    return {"ok": True, "ticket_no": no}


def prepare(company_id: int, caller: str, staff: dict | None = None) -> dict:
    """System prompt and voice, built while the call rings (billing lookups take a few seconds)."""
    from engine import agent, customers
    t = tenant_for(company_id)
    company = db.one("select name from companies where id = %s", (company_id,)) or {"name": ""}
    voice = ((t.bot.get("voice_reply_voice") if t else None) or "Kore")
    if staff:
        block = agent.knowledge(t) if t else ""
        prompt = STAFF_RULES.replace("{company}", company["name"]).replace("{tech}", staff["name"])
        return {"prompt": prompt + ("\n\n" + block if block else ""), "voice": voice, "tenant": t, "staff": staff}
    # the WhatsApp rules without the photo rule (no photos on a call)
    prompt = (agent.system_prompt(t).replace(agent.IMAGE_RULE, "") if t
              else f"তুমি {company['name']} (একটি ISP)-এর কাস্টমার সাপোর্ট।") + VOICE_RULES
    number = normalize(caller)
    info = "কলারের নম্বর আমাদের কোনো কাস্টমারের সাথে মেলেনি; কাস্টমার আইডি জিজ্ঞেস করে lookup_customer দিয়ে খোঁজো।"
    if t and number:
        try:
            from engine import tenant as tenants
            api = tenants.billing(t)
            row = customers.by_whatsapp(t, api, number)
            if row:
                info = "কলারের নম্বরে মেলা সংযোগের তথ্য (JSON):\n" + json.dumps(line_context(t, api, row), ensure_ascii=False)
        except Exception:
            log.exception("company %s: customer lookup for %s failed", company_id, caller)
    others = db.all_rows("select customer_id, name, zone, due, is_left from billing_customers"
                         " where company_id = %s and mobile_normalized = %s order by is_left, header_id desc limit 5",
                         (company_id, number)) if number else []
    if len(others) > 1:
        info += "\nএই নম্বরে আরও সংযোগ: " + json.dumps(others, ensure_ascii=False, default=str)
    return {"prompt": prompt + "\n\n" + info, "voice": voice, "tenant": t, "staff": None}


# --- GET /mode (asked by the Asterisk dialplan on every incoming call) -------------------------
def decide(ep: str, caller: str) -> str:
    if not ep.startswith("c") or not ep[1:].isdigit():
        return "ring"
    s = db.one("select * from sip_settings where company_id = %s and enabled", (int(ep[1:]),))
    if not s or s["bot_mode"] not in ("test", "live"):
        return "ring"
    number = normalize(caller) or caller

    def numbers(text):
        return {normalize(x) or x.strip() for x in (text or "").replace("\n", ",").split(",") if x.strip()}
    if number in numbers(s.get("bot_blocked_numbers")):
        return "ring"   # blocked: the bot never takes these callers, the agents' phones ring as usual
    if s["bot_mode"] == "test" and number not in numbers(s.get("bot_test_numbers")):
        return "ring"
    # the company's technicians (panel → টেকনিশিয়ান, by their WhatsApp number) get the staff desk, as on WhatsApp
    staff = db.one("select id, name, wa_number, can_switch_lines from technicians where company_id = %s and wa_number = %s and active",
                   (s["company_id"], number))
    uid = str(uuid.uuid4())
    calls[uid] = {"company_id": s["company_id"], "caller": caller, "settings": s, "at": time.time(), "staff": staff,
                  "prep": pool.submit(prepare, s["company_id"], caller, staff)}
    return f"bot:{uid}:{int(s.get('bot_delay') or 20)}"


async def mode_server(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
    try:
        line = (await asyncio.wait_for(reader.readline(), 5)).decode(errors="replace")
        while (await reader.readline()) not in (b"\r\n", b"\n", b""):
            pass
        q = parse_qs(urlparse(line.split(" ")[1] if " " in line else "/").query)
        ep, caller = q.get("ep", [""])[0], q.get("caller", [""])[0]
        loop = asyncio.get_running_loop()
        try:
            body = await loop.run_in_executor(pool, decide, ep, caller)
        except Exception:
            log.exception("mode")
            body = "ring"
        log.info("mode %s %s -> %s", ep, caller, body.split(":")[0])
        writer.write(f"HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: {len(body)}\r\nConnection: close\r\n\r\n{body}".encode())
        await writer.drain()
    finally:
        writer.close()


# --- one answered call -------------------------------------------------------------------------
class Call:
    def __init__(self, uid: str, info: dict, reader, writer):
        self.uid, self.info, self.reader, self.writer = uid, info, reader, writer
        self.out = bytearray()            # bot audio waiting to be played (8 kHz)
        self.caller_track = bytearray()   # what the caller said, 8 kHz, from the answer on
        self.bot_track = bytearray()      # what we played, same clock
        self.turns: list[dict] = []
        self.callback: str | None = None
        self.flag: str | None = None
        self.hangup_after_drain = False
        self.done = asyncio.Event()
        self.up_state = self.down_state = None
        self.usage = {"input": 0, "output": 0}
        self.started = time.time()

    def say(self, who: str, text: str) -> None:
        if not text:
            return
        if self.turns and self.turns[-1]["who"] == who:
            self.turns[-1]["text"] += text
        else:
            self.turns.append({"who": who, "text": text, "at": round(time.time() - self.started, 1)})

    async def send_frame(self, kind: int, payload: bytes = b"") -> None:
        self.writer.write(bytes([kind]) + len(payload).to_bytes(2, "big") + payload)
        await self.writer.drain()

    async def from_caller(self, ws) -> None:
        while not self.done.is_set():
            head = await self.reader.readexactly(3)
            n = int.from_bytes(head[1:], "big")
            payload = await self.reader.readexactly(n) if n else b""
            if head[0] == 0x00 or head[0] == 0xFF:
                break
            if head[0] != 0x10:
                continue
            self.caller_track += payload
            pcm16, self.up_state = audioop.ratecv(payload, 2, 1, 8000, 16000, self.up_state)
            await ws.send(json.dumps({"realtimeInput": {"audio": {"data": base64.b64encode(pcm16).decode(),
                                                                  "mimeType": "audio/pcm;rate=16000"}}}))
        self.done.set()

    async def to_caller(self) -> None:
        """Every 20 ms one frame to Asterisk (silence when the bot is quiet), the same frame onto the recording."""
        nxt = time.monotonic()
        while not self.done.is_set():
            frame = bytes(self.out[:FRAME])
            del self.out[:FRAME]
            if len(frame) < FRAME:
                if self.hangup_after_drain:
                    await asyncio.sleep(0.4)
                    await self.send_frame(0x00)
                    self.done.set()
                    return
                frame = frame + b"\x00" * (FRAME - len(frame))
            self.bot_track += frame
            await self.send_frame(0x10, frame)
            nxt += 0.02
            await asyncio.sleep(max(0, nxt - time.monotonic()))

    async def from_gemini(self, ws) -> None:
        async for raw in ws:
            m = json.loads(raw)
            sc = m.get("serverContent") or {}
            for p in (sc.get("modelTurn") or {}).get("parts", []):
                data = (p.get("inlineData") or {}).get("data")
                if data:
                    pcm8, self.down_state = audioop.ratecv(base64.b64decode(data), 2, 1, 24000, 8000, self.down_state)
                    self.out += pcm8
            if sc.get("interrupted"):
                self.out.clear()           # the caller started talking: stop at once
            self.say("caller", (sc.get("inputTranscription") or {}).get("text", ""))
            self.say("bot", (sc.get("outputTranscription") or {}).get("text", ""))
            u = m.get("usageMetadata")
            if u:
                self.usage["input"] += int(u.get("promptTokenCount") or 0)
                self.usage["output"] += int(u.get("responseTokenCount") or u.get("candidatesTokenCount") or 0)
            for fc in (m.get("toolCall") or {}).get("functionCalls", []):
                name, args, result = fc.get("name"), fc.get("args") or {}, {"ok": True}
                t = (self.info.get("prep_result") or {}).get("tenant")
                staff = self.info.get("staff")
                loop = asyncio.get_running_loop()
                if name in FILLERS and fillers.get(name):
                    self.out += fillers[name]   # the caller hears "একটু অপেক্ষা করুন..." at once, not silence
                try:
                    if name == "request_callback":
                        self.callback = (args.get("reason") or "কল ব্যাক চেয়েছেন")[:300]
                        self.flag = args.get("flag") if args.get("flag") in FLAGS else "unsolved"
                    elif name == "end_call":
                        self.hangup_after_drain = True
                    elif name == "lookup_customer":
                        result = await loop.run_in_executor(pool, lookup_customer, t, args.get("id_or_mobile", ""),
                                                            bool(staff))
                    elif name == "create_complaint":
                        result = await loop.run_in_executor(pool, create_complaint, t, self.info["caller"],
                                                            args.get("customer_id", ""), args.get("problem", ""),
                                                            args.get("category"))
                    elif name in ("solve_ticket", "switch_line") and staff:
                        result = await loop.run_in_executor(pool, staff_action, t, staff, name, args)
                        if result.get("ticket_no"):
                            self.say("bot", f" [টিকিট #{result['ticket_no']}]")
                except Exception as e:
                    log.exception("tool %s", name)
                    result = {"error": str(e)[:200]}
                log.info("call %s tool %s -> %s", self.uid, name, str(result)[:120])
                await ws.send(json.dumps({"toolResponse": {"functionResponses": [
                    {"id": fc.get("id"), "name": name, "response": json.loads(json.dumps(result, default=str))}]}},
                    ensure_ascii=False))
        self.done.set()


async def open_live(company_id: int, model: str, prep: dict):
    """A Gemini Live session on the first of the company's Gemini keys that accepts it."""
    keys = db.all_rows("select id, api_key from ai_keys where company_id = %s and provider = 'gemini'"
                       " and (rate_limited_until is null or rate_limited_until <= now()) order by id", (company_id,))
    last = None
    for k in keys:
        try:
            ws = await websockets.connect(LIVE_URL, additional_headers={"x-goog-api-key": db.decrypt(k["api_key"])},
                                          max_size=None, open_timeout=8)
            await ws.send(json.dumps({"setup": {
                "model": f"models/{model}",
                # languageCode: the caller's speech is heard as Bangla (without it some turns came out as Hindi)
                "generationConfig": {"responseModalities": ["AUDIO"], "speechConfig": {"languageCode": "bn-IN",
                    "voiceConfig": {"prebuiltVoiceConfig": {"voiceName": prep["voice"]}}}},
                "systemInstruction": {"parts": [{"text": prep["prompt"]}]},
                "tools": STAFF_TOOLS if prep.get("staff") else TOOLS,
                "inputAudioTranscription": {}, "outputAudioTranscription": {},
            }}))
            first = json.loads(await asyncio.wait_for(ws.recv(), 8))
            if "setupComplete" in first:
                return ws, k["id"]
            last = str(first)[:200]
            await ws.close()
        except Exception as e:
            last = f"{type(e).__name__}: {e}"
            log.warning("company %s gemini key %s: %s", company_id, k["id"], last)
    raise RuntimeError(f"no Gemini key opened a Live session: {last}")


def save_call(call: Call, company_id: int, caller: str, model: str, key_id: int | None, error: str | None) -> None:
    """MP3 of both sides, transcript and the outcome on the call_logs row (the one the popup made, or a new one)."""
    number = normalize(caller)
    # the popup's row for this very call: same caller, started when /mode was asked (not just the caller's latest call)
    rang = (calls.get(call.uid) or {}).get("at") or call.started
    row = db.one("select id from call_logs where company_id = %s and caller = %s and not bot_answered"
                 " and abs(extract(epoch from started_at) - %s) < 90"
                 " order by abs(extract(epoch from started_at) - %s) limit 1", (company_id, caller[:80], rang, rang))
    if not row:
        row = db.execute("insert into call_logs (company_id, call_ref, caller, caller_normalized, status, started_at, created_at, updated_at)"
                         " values (%s, %s, %s, %s, 'ended', now() - make_interval(secs => %s), now(), now()) returning id",
                         (company_id, "bot-" + call.uid, caller[:80], number, time.time() - call.started))
    rel = None
    n = max(len(call.caller_track), len(call.bot_track))
    if n > FRAME * 25:   # more than half a second
        a = bytes(call.caller_track) + b"\x00" * (n - len(call.caller_track))
        b = bytes(call.bot_track) + b"\x00" * (n - len(call.bot_track))
        mixed = audioop.add(a, b, 2)
        os.makedirs(os.path.join(REC_DIR, str(company_id)), exist_ok=True)
        rel = f"{company_id}/{row['id']}.mp3"
        wav = os.path.join(STATE_DIR, f"{call.uid}.wav")
        with wave.open(wav, "wb") as w:
            w.setnchannels(1)
            w.setsampwidth(2)
            w.setframerate(8000)
            w.writeframes(mixed)
        r = subprocess.run(["ffmpeg", "-loglevel", "error", "-y", "-i", wav, "-ac", "1", "-b:a", "32k",
                            os.path.join(REC_DIR, rel)], capture_output=True, text=True, timeout=120)
        os.unlink(wav)
        if r.returncode != 0:
            log.error("ffmpeg: %s", r.stderr[:300])
            rel = None
    db.execute("""update call_logs set status = 'ended', ended_at = coalesce(ended_at, now()), bot_answered = true,
                    bot_seconds = %s, recording_path = %s, transcript = %s, callback_reason = %s, bot_flag = %s,
                    bot_error = %s, updated_at = now() where id = %s""",
               (int(time.time() - call.started), rel, json.dumps(call.turns, ensure_ascii=False), call.callback,
                call.flag, (error or "")[:300] or None, row["id"]))
    try:
        from engine import agent
        info = calls.get(call.uid) or {}
        t = (info.get("prep_result") or {}).get("tenant")
        if t:
            agent.record_usage(t, key_id, "gemini", model, "voice_call", None, call.usage, error=error)
    except Exception:
        log.exception("usage")


async def audio_server(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
    uid, ws, error, key_id, call, info = None, None, None, None, None, {}
    try:
        head = await asyncio.wait_for(reader.readexactly(3), 5)
        payload = await reader.readexactly(int.from_bytes(head[1:], "big"))
        if head[0] != 0x01:
            return
        uid = str(uuid.UUID(bytes=payload))
        info = calls.get(uid)
        if not info:
            log.warning("audiosocket: unknown call %s", uid)
            return
        loop = asyncio.get_running_loop()
        prep = await loop.run_in_executor(None, info["prep"].result, 15)
        info["prep_result"] = prep
        model = info["settings"].get("bot_model") or DEFAULT_MODEL
        info["model"] = model
        call = Call(uid, info, reader, writer)
        ws, key_id = await open_live(info["company_id"], model, prep)
        await ws.send(json.dumps({"clientContent": {"turns": [{"role": "user", "parts": [{"text":
            ("(কল ধরা হয়েছে। শুধু বলো \"জি ভাই, বলুন\"।)" if prep.get("staff") else
             "(কল ধরা হয়েছে। প্রথমে কোম্পানির নাম বলে শুভেচ্ছা জানাও, জানাও যে কলটি রেকর্ড করা হচ্ছে, তারপর জিজ্ঞেস করো কীভাবে সাহায্য করতে পারো।)")}]}],
            "turnComplete": True}}))
        # Asterisk usually just closes the socket on hangup (no 0x00 frame): whichever side stops first ends the call
        tasks = [asyncio.create_task(c) for c in (call.from_caller(ws), call.to_caller(), call.from_gemini(ws),
                                                    call.done.wait())]
        limit = min(int(info["settings"].get("bot_max_seconds") or 240), MAX_CALL)
        finished, _ = await asyncio.wait(tasks, timeout=max(limit - 25, 10), return_when=asyncio.FIRST_COMPLETED)
        if not finished:
            # time is nearly up: the bot says a staff member will call and hangs up itself; cut it 45 s later at most
            call.flag = call.flag or "time_up"
            await ws.send(json.dumps({"clientContent": {"turns": [{"role": "user", "parts": [{"text": WRAP_UP}]}],
                                      "turnComplete": True}}, ensure_ascii=False))
            finished, _ = await asyncio.wait(tasks, timeout=45, return_when=asyncio.FIRST_COMPLETED)
            if not finished:
                error = "max call length"
        call.done.set()
        for t in tasks:
            t.cancel()
        log.info("call %s from %s: %ss with the bot", uid, info["caller"], int(time.time() - call.started))
    except (asyncio.IncompleteReadError, ConnectionError):
        pass
    except Exception as e:
        error = f"{type(e).__name__}: {e}"
        log.exception("call %s", uid)
    finally:
        if ws:
            try:
                await ws.close()
            except Exception:
                pass
        try:
            writer.close()
        except Exception:
            pass
        if uid and info:
            if call is None:
                call = Call(uid, info, reader, writer)
            await asyncio.get_running_loop().run_in_executor(
                pool, save_call, call, info["company_id"], info["caller"], info.get("model", DEFAULT_MODEL), key_id, error)
            calls.pop(uid, None)


def load_fillers() -> None:
    """The filler lines as 8 kHz PCM, made once with Gemini TTS (same voice as the bot) and kept in STATE_DIR."""
    import hashlib
    from engine import agent
    for name, text in FILLERS.items():
        if fillers.get(name):
            continue
        path = os.path.join(STATE_DIR, f"filler-{hashlib.sha1(text.encode()).hexdigest()[:10]}.pcm")
        if not os.path.exists(path):
            row = db.one("select company_id from sip_settings where bot_mode in ('test', 'live') order by company_id limit 1")
            t = tenant_for(row["company_id"]) if row else None
            ogg = agent.speak(t, text) if t else None
            if not ogg:
                continue
            pcm = subprocess.run(["ffmpeg", "-loglevel", "error", "-i", "pipe:0", "-f", "s16le", "-ac", "1", "-ar", "8000", "pipe:1"],
                                 input=ogg, capture_output=True, timeout=60).stdout
            if not pcm:
                continue
            with open(path, "wb") as f:
                f.write(pcm)
        fillers[name] = open(path, "rb").read()
        log.info("filler %s: %.1fs", name, len(fillers[name]) / 16000)


async def housekeeping() -> None:
    while True:
        try:
            await asyncio.get_running_loop().run_in_executor(pool, load_fillers)
        except Exception:
            log.exception("fillers")
        try:
            await asyncio.get_running_loop().run_in_executor(pool, sync_asterisk)
        except Exception:
            log.exception("asterisk sync")
        for uid, c in list(calls.items()):
            if time.time() - c["at"] > MAX_CALL + 300:
                calls.pop(uid, None)
        await asyncio.sleep(30)


async def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(name)s: %(message)s")
    os.makedirs(STATE_DIR, exist_ok=True)
    a = await asyncio.start_server(mode_server, "127.0.0.1", MODE_PORT)
    b = await asyncio.start_server(audio_server, "127.0.0.1", AUDIO_PORT)
    log.info("voice bot: /mode on %s, AudioSocket on %s", MODE_PORT, AUDIO_PORT)
    async with a, b:
        await housekeeping()


if __name__ == "__main__":
    asyncio.run(main())
