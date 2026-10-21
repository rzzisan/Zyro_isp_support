"""Hotline voice bot. Asterisk registers each company's hotline extension (Desk â†’ à¦¸à§‡à¦Ÿà¦¿à¦‚à¦¸ â†’ à¦¹à¦Ÿà¦²à¦¾à¦‡à¦¨ (SIP)) and asks
GET /mode whether to take a call. When the bot is on for this caller ("test" = only the test numbers, "live" = all) the
extension rings for the set seconds (the agents' phones ring at the same time) and then answers; the call audio comes
here over AudioSocket and goes to Gemini Live, which listens and speaks Bangla. The caller's customer data (bill,
due, line) is fetched while it rings. Both sides are recorded (MP3 in the panel's storage) with the transcript in
call_logs, shown on the panel's à¦•à¦² à¦²à¦— page.

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
calls: dict[str, dict] = {}   # AudioSocket UUID -> {company_id, caller, settings, prep (future)}

VOICE_RULES = (
    "\n\nà¦à¦–à¦¨ à¦¤à§à¦®à¦¿ à¦«à§‹à¦¨ à¦•à¦²à§‡ à¦•à¦¥à¦¾ à¦¬à¦²à¦›, WhatsApp-à¦ à¦¨à¦¾à¥¤ à¦¨à¦¿à¦¯à¦¼à¦®:\n"
    "- à¦¶à§à¦§à§ à¦¬à¦¾à¦‚à¦²à¦¾à¦¯à¦¼, à¦›à§‹à¦Ÿ à¦›à§‹à¦Ÿ à¦¬à¦¾à¦•à§à¦¯à§‡, à¦¸à§à¦¬à¦¾à¦­à¦¾à¦¬à¦¿à¦• à¦­à¦¦à§à¦° à¦—à¦²à¦¾à¦¯à¦¼ à¦•à¦¥à¦¾ à¦¬à¦²à§‹à¥¤ à¦à¦•à¦¬à¦¾à¦°à§‡ à¦¦à§à¦‡-à¦¤à¦¿à¦¨ à¦¬à¦¾à¦•à§à¦¯à§‡à¦° à¦¬à§‡à¦¶à¦¿ à¦¨à¦¾à¥¤\n"
    "- à¦²à¦¿à¦‚à¦•, à¦®à¦¾à¦°à§à¦•à¦¡à¦¾à¦‰à¦¨, à¦‡à¦®à§‹à¦œà¦¿, à¦¤à¦¾à¦²à¦¿à¦•à¦¾ à¦šà¦¿à¦¹à§à¦¨ à¦¬à¦²à¦¬à§‡ à¦¨à¦¾à¥¤ à¦Ÿà¦¾à¦•à¦¾à¦° à¦…à¦™à§à¦• à¦“ à¦¤à¦¾à¦°à¦¿à¦– à¦®à§à¦–à§‡ à¦¬à¦²à¦¾à¦° à¦®à¦¤à§‹ à¦•à¦°à§‡ à¦¬à¦²à§‹à¥¤\n"
    "- à¦¨à¦¿à¦šà§‡à¦° à¦•à¦¾à¦¸à§à¦Ÿà¦®à¦¾à¦° à¦¤à¦¥à§à¦¯ à¦à¦‡ à¦•à¦²à¦¾à¦°à§‡à¦° à¦®à§‹à¦¬à¦¾à¦‡à¦² à¦¨à¦®à§à¦¬à¦° à¦®à¦¿à¦²à¦¿à¦¯à¦¼à§‡ à¦ªà¦¾à¦“à¦¯à¦¼à¦¾; à¦à¦° à¦¬à¦¾à¦‡à¦°à§‡ à¦•à¦¿à¦›à§ à¦¬à¦¾à¦¨à¦¿à¦¯à¦¼à§‡ à¦¬à¦²à¦¬à§‡ à¦¨à¦¾à¥¤\n"
    "- à¦à¦•à¦‡ à¦¨à¦®à§à¦¬à¦°à§‡ à¦•à¦¯à¦¼à§‡à¦•à¦Ÿà¦¾ à¦¸à¦‚à¦¯à§‹à¦— à¦¥à¦¾à¦•à¦²à§‡ à¦•à§‹à¦¨à¦Ÿà¦¾à¦° à¦•à¦¥à¦¾ à¦œà¦¿à¦œà§à¦žà§‡à¦¸ à¦•à¦°à¦›à§‡ à¦†à¦—à§‡ à¦œà§‡à¦¨à§‡ à¦¨à¦¾à¦“à¥¤\n"
    "- à¦¸à¦®à¦¾à¦§à¦¾à¦¨ à¦¨à¦¾ à¦¹à¦²à§‡ à¦¬à¦¾ à¦•à¦¾à¦¸à§à¦Ÿà¦®à¦¾à¦° à¦®à¦¾à¦¨à§à¦·à§‡à¦° à¦¸à¦¾à¦¥à§‡ à¦•à¦¥à¦¾ à¦¬à¦²à¦¤à§‡ à¦šà¦¾à¦‡à¦²à§‡ request_callback à¦¦à¦¿à¦¯à¦¼à§‡ à¦•à¦¾à¦°à¦£ à¦²à¦¿à¦–à§‡ à¦°à¦¾à¦–à§‹ à¦à¦¬à¦‚ à¦¬à¦²à§‹ "
    "à¦†à¦®à¦¾à¦¦à§‡à¦° à¦ªà§à¦°à¦¤à¦¿à¦¨à¦¿à¦§à¦¿ à¦¶à¦¿à¦—à¦—à¦¿à¦°à¦‡ à¦•à¦² à¦¬à§à¦¯à¦¾à¦• à¦•à¦°à¦¬à§‡à¦¨à¥¤\n"
    "- à¦•à¦¥à¦¾ à¦¶à§‡à¦· à¦¹à¦²à§‡ à¦¬à¦¿à¦¦à¦¾à¦¯à¦¼ à¦œà¦¾à¦¨à¦¿à¦¯à¦¼à§‡ end_call à¦¡à¦¾à¦•à§‹à¥¤"
)
TOOLS = [{"functionDeclarations": [
    {"name": "request_callback", "description": "Record that a human agent must call this customer back, with the reason.",
     "parameters": {"type": "OBJECT", "properties": {"reason": {"type": "STRING", "description": "short reason in Bangla"}},
                    "required": ["reason"]}},
    {"name": "end_call", "description": "Hang up after saying goodbye, when the conversation is over."},
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


def prepare(company_id: int, caller: str) -> dict:
    """System prompt and voice, built while the call rings (billing lookups take a few seconds)."""
    from engine import agent, customers
    from engine.ispdigital import diagnose
    t = tenant_for(company_id)
    company = db.one("select name from companies where id = %s", (company_id,)) or {"name": ""}
    prompt = (agent.system_prompt(t) if t else f"à¦¤à§à¦®à¦¿ {company['name']} (à¦à¦•à¦Ÿà¦¿ ISP)-à¦à¦° à¦•à¦¾à¦¸à§à¦Ÿà¦®à¦¾à¦° à¦¸à¦¾à¦ªà§‹à¦°à§à¦Ÿà¥¤") + VOICE_RULES
    number = normalize(caller)
    info = "à¦•à¦²à¦¾à¦°à§‡à¦° à¦¨à¦®à§à¦¬à¦° à¦†à¦®à¦¾à¦¦à§‡à¦° à¦•à§‹à¦¨à§‹ à¦•à¦¾à¦¸à§à¦Ÿà¦®à¦¾à¦°à§‡à¦° à¦¸à¦¾à¦¥à§‡ à¦®à§‡à¦²à§‡à¦¨à¦¿; à¦¦à¦°à¦•à¦¾à¦° à¦¹à¦²à§‡ à¦•à¦¾à¦¸à§à¦Ÿà¦®à¦¾à¦° à¦†à¦‡à¦¡à¦¿ à¦œà¦¿à¦œà§à¦žà§‡à¦¸ à¦•à¦°à§‹à¥¤"
    if t and number:
        try:
            from engine import tenant as tenants
            api = tenants.billing(t)
            row = customers.by_whatsapp(t, api, number)
            if row:
                ctx = customers.add_online(t, diagnose(api, customers.fresh(api, row)), row)
                from engine.ticket_actions import open_tickets
                ctx["open_tickets"] = [{"no": x["complain_id"], "category": x["category"], "state": x["state"]}
                                       for x in open_tickets(t, row.get("CustomerId"))]
                info = "à¦à¦‡ à¦•à¦²à¦¾à¦°à§‡à¦° à¦¤à¦¥à§à¦¯ (JSON):\n" + json.dumps(safe_view(ctx), ensure_ascii=False)
        except Exception:
            log.exception("company %s: customer lookup for %s failed", company_id, caller)
    others = db.all_rows("select customer_id, name, zone, due, is_left from billing_customers"
                         " where company_id = %s and mobile_normalized = %s order by is_left, header_id desc limit 5",
                         (company_id, number)) if number else []
    if len(others) > 1:
        info += "\nà¦à¦‡ à¦¨à¦®à§à¦¬à¦°à§‡ à¦†à¦°à¦“ à¦¸à¦‚à¦¯à§‹à¦—: " + json.dumps(others, ensure_ascii=False, default=str)
    voice = ((t.bot.get("voice_reply_voice") if t else None) or "Kore")
    return {"prompt": prompt + "\n\n" + info, "voice": voice, "tenant": t}


# --- GET /mode (asked by the Asterisk dialplan on every incoming call) -------------------------
def decide(ep: str, caller: str) -> str:
    if not ep.startswith("c") or not ep[1:].isdigit():
        return "ring"
    s = db.one("select * from sip_settings where company_id = %s and enabled", (int(ep[1:]),))
    if not s or s["bot_mode"] not in ("test", "live"):
        return "ring"
    number = normalize(caller) or caller
    if s["bot_mode"] == "test":
        tests = {normalize(x) or x.strip() for x in (s.get("bot_test_numbers") or "").replace("\n", ",").split(",") if x.strip()}
        if number not in tests:
            return "ring"
    uid = str(uuid.uuid4())
    calls[uid] = {"company_id": s["company_id"], "caller": caller, "settings": s, "at": time.time(),
                  "prep": pool.submit(prepare, s["company_id"], caller)}
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
                if fc.get("name") == "request_callback":
                    self.callback = ((fc.get("args") or {}).get("reason") or "à¦•à¦² à¦¬à§à¦¯à¦¾à¦• à¦šà§‡à¦¯à¦¼à§‡à¦›à§‡à¦¨")[:300]
                elif fc.get("name") == "end_call":
                    self.hangup_after_drain = True
                await ws.send(json.dumps({"toolResponse": {"functionResponses": [
                    {"id": fc.get("id"), "name": fc.get("name"), "response": {"ok": True}}]}}))
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
                "generationConfig": {"responseModalities": ["AUDIO"], "speechConfig": {
                    "voiceConfig": {"prebuiltVoiceConfig": {"voiceName": prep["voice"]}}}},
                "systemInstruction": {"parts": [{"text": prep["prompt"]}]},
                "tools": TOOLS,
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
    row = db.one("select id from call_logs where company_id = %s and caller = %s and started_at > now() - interval '15 minutes'"
                 " order by started_at desc limit 1", (company_id, caller[:80]))
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
                    bot_seconds = %s, recording_path = %s, transcript = %s, callback_reason = %s, bot_error = %s,
                    updated_at = now() where id = %s""",
               (int(time.time() - call.started), rel, json.dumps(call.turns, ensure_ascii=False), call.callback,
                (error or "")[:300] or None, row["id"]))
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
            "(à¦•à¦² à¦§à¦°à¦¾ à¦¹à¦¯à¦¼à§‡à¦›à§‡à¥¤ à¦ªà§à¦°à¦¥à¦®à§‡ à¦•à§‹à¦®à§à¦ªà¦¾à¦¨à¦¿à¦° à¦¨à¦¾à¦® à¦¬à¦²à§‡ à¦¶à§à¦­à§‡à¦šà§à¦›à¦¾ à¦œà¦¾à¦¨à¦¾à¦“, à¦œà¦¾à¦¨à¦¾à¦“ à¦¯à§‡ à¦•à¦²à¦Ÿà¦¿ à¦°à§‡à¦•à¦°à§à¦¡ à¦•à¦°à¦¾ à¦¹à¦šà§à¦›à§‡, à¦¤à¦¾à¦°à¦ªà¦° à¦œà¦¿à¦œà§à¦žà§‡à¦¸ à¦•à¦°à§‹ à¦•à§€à¦­à¦¾à¦¬à§‡ à¦¸à¦¾à¦¹à¦¾à¦¯à§à¦¯ à¦•à¦°à¦¤à§‡ à¦ªà¦¾à¦°à§‹à¥¤)"}]}],
            "turnComplete": True}}))
        # Asterisk usually just closes the socket on hangup (no 0x00 frame): whichever side stops first ends the call
        tasks = [asyncio.create_task(c) for c in (call.from_caller(ws), call.to_caller(), call.from_gemini(ws),
                                                    call.done.wait())]
        finished, _ = await asyncio.wait(tasks, timeout=MAX_CALL, return_when=asyncio.FIRST_COMPLETED)
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


async def housekeeping() -> None:
    while True:
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
