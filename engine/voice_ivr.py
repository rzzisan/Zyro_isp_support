"""Voice reminder calls (Desk → ভয়েস রিমাইন্ডার), run inside the voice bot process (engine/voice_bot.py).

The panel queues a call in voice_reminder_calls with everything it needs (App\\Support\\VoiceCalls::package): the flow
(steps, keys, what each key does), every message's audio made ahead by Gemini TTS (engine/voice_tts.py), the SMS texts,
and the customer. No AI runs on the call; only {reconnect_message} is read out when a reconnect happens.

  place: start() claims a queued row, copies the audio where Asterisk can read it, writes a call_logs row
         (channel reminder, direction out) and runs `channel originate Local/<uid>@zyro-remind-dial extension
         <uid>@zyro-remind`. zyro-remind-dial asks GET /remind-dial for the hotline endpoint and number and Dials it
         (DIALSTATUS back to GET /remind-dialed); when the customer answers, zyro-remind hands the call to FastAGI.
  run:   agi_server() plays the steps and reads the keys (deploy/asterisk/extensions.conf, port 4574).
  staff: "স্টাফের সাথে কথা" rings Desk like a hotline call (voice_bot.calls + notify_staff; the softphone dials
         take-<uid> and the voice bot's /take puts the agent channel in AstDB zyrotake/<uid>) while the customer hears
         the hold messages; nobody in max_wait seconds → voice message, call-back request or goodbye.
"""
import asyncio
import hashlib
import json
import logging
import os
import re
import shutil
import subprocess
import threading
import time
import uuid

from engine import db

log = logging.getLogger("voice_ivr")

AGI_PORT = 4574
AUDIO_DIR = os.environ.get("VOICE_AUDIO_DIR", "/var/www/zyro_isp_support/panel/storage/app/voice-audio")
PRIVATE_DIR = os.environ.get("PANEL_PRIVATE_DIR", "/var/www/zyro_isp_support/panel/storage/app/private")
MAX_PER_COMPANY = int(os.environ.get("VOICE_REMIND_MAX_CALLS", "1"))   # reminder calls at once per company (hotline lines)
RING_SECONDS = 28        # under the 30 s `channel originate` waits for an answer
CALL_TIME = re.compile(r"\{(reconnect_message|reconnect_reason|reconnect_until)\}")

active: dict[str, dict] = {}     # uid -> {row_id, company_id, number, package, log_id, sounds, ...}
_lock = threading.Lock()


_vb = None


def bind(module) -> None:
    """The running voice bot module: it runs as __main__ (python -m engine.voice_bot), and its `calls` and `lock`
    must be the very ones /take and /bot use, not a second import's."""
    global _vb
    _vb = module


def vb():
    if _vb is None:
        from engine import voice_bot
        return voice_bot
    return _vb


def sound_dir(company_id: int) -> str:
    d = os.path.join(vb().STATE_DIR, "ivr", str(int(company_id)))
    os.makedirs(d, exist_ok=True)
    os.chmod(os.path.dirname(d), 0o755)
    os.chmod(d, 0o755)
    return d


def _publish(src: str, dst: str) -> str:
    """A WAV where Asterisk (another user) can read it; returns the path without extension (STREAM FILE)."""
    if not os.path.exists(dst):
        shutil.copyfile(src, dst + ".tmp")
        os.chmod(dst + ".tmp", 0o644)
        os.replace(dst + ".tmp", dst)
    return dst[:-4]


def sound(company_id: int, a: dict) -> str | None:
    """One message of a package as a playable file (path without .wav), or None (left for the call: text)."""
    try:
        if a.get("file"):
            name = a["file"].split("/")[-1]
            if not re.fullmatch(r"[0-9a-f]{20}", name):
                return None
            return _publish(os.path.join(AUDIO_DIR, f"{int(company_id)}/{name}.wav"), os.path.join(sound_dir(company_id), f"{name}.wav"))
        if a.get("upload"):
            rel = a["upload"]
            src = os.path.join(PRIVATE_DIR, rel)
            if ".." in rel or not os.path.exists(src):
                return None
            h = hashlib.sha1(f"{rel}|{os.path.getmtime(src)}".encode()).hexdigest()[:20]
            dst = os.path.join(sound_dir(company_id), f"u{h}.wav")
            if not os.path.exists(dst):
                r = subprocess.run(["ffmpeg", "-loglevel", "error", "-y", "-i", src, "-ar", "8000", "-ac", "1", "-c:a", "pcm_s16le",
                                    "-f", "wav", dst + ".tmp"], capture_output=True, timeout=120)
                if r.returncode != 0:
                    log.error("upload %s: %s", rel, r.stderr[:200])
                    return None
                os.chmod(dst + ".tmp", 0o644)
                os.replace(dst + ".tmp", dst)
            return dst[:-4]
    except Exception:
        log.exception("sound %s", a)
    return None


def spoken(company_id: int, text: str, voice: str = "Kore", speed: float = 1.0) -> str | None:
    """A text read now (a reconnect result, a message the package had as text)."""
    from engine import voice_tts
    text = CALL_TIME.sub("", text).strip()
    if not text:
        return None
    try:
        made = voice_tts.make(company_id, text, voice, speed)
        return sound(company_id, {"file": made["file"]})
    except Exception:
        log.exception("tts during a reminder call")
        return None


# --- placing the calls --------------------------------------------------------------------------------------------
def busy(company_id: int) -> int:
    return sum(1 for c in active.values() if c["company_id"] == company_id)


def start(row_id: int) -> str:
    """Place one queued call now unless the company already has its calls running. "ok" | "busy" | "gone" | error."""
    with _lock:
        row = db.one("select company_id from voice_reminder_calls where id = %s and status = 'queued'", (row_id,))
        if not row:
            return "gone"
        if busy(row["company_id"]) >= MAX_PER_COMPANY:
            return "busy"
        row = db.one("update voice_reminder_calls set status = 'dialing', started_at = now(), updated_at = now()"
                     " where id = %s and status = 'queued' returning *", (row_id,))
        if not row:
            return "gone"
        uid = uuid.uuid4().hex
        active[uid] = {"row_id": row_id, "company_id": row["company_id"], "number": row["number"], "kind": row["kind"],
                       "package": json.loads(row["package"] or "{}"), "at": time.time(), "keys": [], "result": None,
                       "actions": []}
    c = active[uid]
    try:
        if row["kind"] != "test" and db.one("select 1 as x from voice_optouts where company_id = %s and number = %s",
                                            (c["company_id"], c["number"])):
            return finish(uid, "failed", error="এই নম্বর আর কল চায় না (opt-out)")
        if not db.one("select 1 as x from sip_settings where company_id = %s and enabled and password is not null", (c["company_id"],)):
            return finish(uid, "failed", error="হটলাইন সেটিং বন্ধ")
        c["sounds"] = {str(k): sound(c["company_id"], a) for k, a in (c["package"].get("audio") or {}).items()}
        log_row = db.execute("insert into call_logs (company_id, call_ref, caller, caller_normalized, status, started_at,"
                             " channel, direction, answered_by, actions, created_at, updated_at)"
                             " values (%s, %s, %s, %s, 'ivr', now(), 'reminder', 'out', null, '[]', now(), now()) returning id",
                             (c["company_id"], "rem-" + uid, "0" + c["number"][3:], c["number"]))
        c["log_id"] = log_row["id"]
        db.execute("update voice_reminder_calls set call_log_id = %s where id = %s", (c["log_id"], row_id))
        r = subprocess.run(["asterisk", "-rx", f"channel originate Local/{uid}@zyro-remind-dial extension {uid}@zyro-remind"],
                           capture_output=True, text=True, timeout=20)
        if r.returncode != 0 or "error" in (r.stdout + r.stderr).lower():
            return finish(uid, "failed", error=(r.stdout + r.stderr).strip()[:300] or "originate failed")
    except Exception as e:
        log.exception("reminder call %s", row_id)
        return finish(uid, "failed", error=str(e)[:300])
    log.info("reminder call %s to %s placed (%s)", row_id, c["number"], uid)
    return "ok"


def dial_plan(uid: str) -> str:
    """GET /remind-dial: "ok:c<company>:0<number>:<ring seconds>" for zyro-remind-dial."""
    c = active.get(uid)
    if not c:
        return "no"
    return f"ok:c{c['company_id']}:0{c['number'][3:]}:{RING_SECONDS}"


def dialed(uid: str, status: str) -> str:
    """GET /remind-dialed: the Dial ended. Not answered → the call is over (ANSWER is finished by the AGI side)."""
    c = active.get(uid)
    if not c:
        return "ok"
    if status != "ANSWER" and not c.get("answered"):
        finish(uid, {"NOANSWER": "no_answer", "BUSY": "busy"}.get(status, "failed"), dial_status=status)
    return "ok"


def ended(uid: str) -> str:
    """GET /remind-ended: hangup handler of the answered leg. A call staff took is closed by the voice bot's own
    ended() too (talk seconds, recording)."""
    c = active.get(uid)
    if c and c.get("answered") and not c.get("done"):
        finish(uid, "done")
    info = vb().calls.get(uid) or {}
    if info.get("state") == "taken":
        vb().ended(uid, "")
    elif info.get("state") in ("waiting", "missed"):   # hung up while Desk was ringing
        vb().calls.pop(uid, None)
        vb().notify_staff(info["company_id"], {"type": "call_taken", "uid": uid, "by": ""})
    return "ok"


def act(c: dict, kind: str, text: str, ok: bool = True) -> None:
    c["actions"].append({"at": time.strftime("%H:%M:%S"), "kind": kind, "text": text, "ok": ok})


def finish(uid: str, status: str, error: str | None = None, dial_status: str | None = None) -> str:
    c = active.get(uid)
    if not c or c.get("done"):
        return status
    c["done"] = True
    result = c.get("result") or ("listened" if status == "done" else None)
    secs = int(time.time() - c["answered_at"]) if c.get("answered_at") else None
    db.execute("update voice_reminder_calls set status = %s, dial_status = coalesce(%s, dial_status), result = %s, keys = %s,"
               " seconds = %s, error = %s, ended_at = now(), updated_at = now() where id = %s",
               (status, dial_status, result, " ".join(c["keys"]) or None, secs, error, c["row_id"]))
    if c.get("log_id"):
        outcome = result if status == "done" else ("no_answer" if status in ("no_answer", "busy") else None)
        text = {"no_answer": "ধরেননি", "busy": "ব্যস্ত", "failed": f"কল যায়নি{': ' + error if error else ''}"}.get(status)
        staff = vb().calls.get(uid, {}).get("state") == "taken"
        db.execute("update call_logs set status = %s, ended_at = now(), bot_seconds = %s, outcome = %s, outcome_text = %s,"
                   " actions = %s, updated_at = now() where id = %s",
                   ("answered" if staff else "ended", None if staff else secs, outcome,
                    (text or (c["package"].get("flow") or {}).get("name") or "")[:300], json.dumps(c["actions"], ensure_ascii=False),
                    c["log_id"]))
    active.pop(uid, None)
    log.info("reminder call %s: %s %s", c["row_id"], status, result or "")
    return status


def run_queue() -> None:
    """Every few seconds: the calls whose time has come (test calls now; the scheduler's later)."""
    for r in db.all_rows("select id from voice_reminder_calls where status = 'queued' and coalesce(scheduled_at, now()) <= now()"
                         " order by scheduled_at nulls first, id limit 20"):
        start(r["id"])
    # a call Asterisk never came back about (originate refused silently): give up after 2 minutes unanswered
    for uid, c in list(active.items()):
        if not c.get("answered") and not c.get("done") and time.time() - c["at"] > 120:
            finish(uid, "failed", error="Asterisk থেকে কোনো সাড়া আসেনি")
        elif time.time() - c["at"] > 4 * 3600:
            active.pop(uid, None)


# --- the call itself (FastAGI) ------------------------------------------------------------------------------------
class Hangup(Exception):
    pass


class Agi:
    def __init__(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter):
        self.r, self.w = reader, writer

    async def env(self) -> dict:
        out = {}
        while True:
            line = (await asyncio.wait_for(self.r.readline(), 10)).decode(errors="replace").strip()
            if not line:
                return out
            k, _, v = line.partition(":")
            out[k.strip()] = v.strip()

    async def cmd(self, line: str) -> tuple[int, str]:
        self.w.write((line + "\n").encode())
        await self.w.drain()
        while True:
            raw = await self.r.readline()
            if not raw:
                raise Hangup()
            resp = raw.decode(errors="replace").strip()
            if resp == "HANGUP" or not resp:
                continue
            break
        m = re.match(r"(\d{3}) result=(-?\d+)(.*)", resp)
        if not m or m.group(1) != "200":   # 511: the channel is gone
            raise Hangup()
        res = int(m.group(2))
        if res == -1 and not line.startswith("DATABASE"):
            raise Hangup()
        return res, m.group(3).strip()

    async def play(self, files: list[str], digits: str = "") -> str:
        """Plays the files in order; a key in `digits` stops it and is returned."""
        for f in files:
            if not f:
                continue
            res, _ = await self.cmd(f'STREAM FILE "{f}" "{digits}"')
            if res > 0:
                return chr(res)
        return ""

    async def digit(self, ms: int) -> str:
        res, _ = await self.cmd(f"WAIT FOR DIGIT {ms}")
        return chr(res) if res > 0 else ""

    async def exec(self, app: str, args: str = "") -> int:
        res, _ = await self.cmd(f'EXEC {app} "{args}"' if args else f"EXEC {app}")
        return res


async def agi_server(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
    agi, uid = Agi(reader, writer), None
    try:
        env = await agi.env()
        m = re.search(r"uid=([0-9a-f]{32})", env.get("agi_network_script", "") + env.get("agi_request", ""))
        uid = m.group(1) if m else None
        c = active.get(uid or "")
        if not c:
            await agi.cmd("HANGUP")
            return
        c.update(answered=True, answered_at=time.time())
        loop = asyncio.get_running_loop()
        await loop.run_in_executor(vb().pool, lambda: (
            db.execute("update voice_reminder_calls set status = 'answered', answered_at = now(), updated_at = now() where id = %s", (c["row_id"],)),
            db.execute("update call_logs set status = 'answered', updated_at = now() where id = %s", (c["log_id"],))))
        await run_flow(agi, uid, c)
    except (Hangup, ConnectionError, asyncio.TimeoutError):
        if uid in active and not active[uid].get("result"):
            active[uid]["result"] = "hung_up" if active[uid].get("keys") else None
    except Exception:
        log.exception("reminder call %s", uid)
    finally:
        try:
            writer.close()
        except Exception:
            pass


def files(c: dict, ids) -> list[str]:
    out = []
    for i in ids if isinstance(ids, list) else [ids]:
        if not i:
            continue
        f = c["sounds"].get(str(i))
        if f is None:
            a = (c["package"].get("audio") or {}).get(str(i)) or {}
            if a.get("text"):
                f = spoken(c["company_id"], a["text"].replace("{reconnect_message}", c.get("reconnect_message", "")),
                           a.get("voice") or "Kore", float(a.get("speed") or 1))
        if f:
            out.append(f)
    return out


async def say(agi: Agi, c: dict, ids, digits: str = "") -> str:
    loop = asyncio.get_running_loop()
    fs = await loop.run_in_executor(vb().pool, files, c, ids)
    return await agi.play(fs, digits)


async def run_flow(agi: Agi, uid: str, c: dict) -> None:
    flow = c["package"].get("flow") or {}
    steps = [s for s in flow.get("steps") or [] if s.get("name")]
    by_name = {s["name"]: s for s in steps}
    loop = asyncio.get_running_loop()
    step, misses = (steps[0] if steps else None), 0
    goodbye = True
    while step:
        keys = {str(k.get("digit")): k for k in step.get("keys") or [] if k.get("digit") is not None}
        digits = "".join(keys)
        d = await say(agi, c, step.get("templates") or [], digits)
        if not d and digits:
            d = await agi.digit(int(step.get("wait") or 7) * 1000)
        if not d:
            misses += 1
            if not digits or misses > int(step.get("repeats") or 0):
                break
            await say(agi, c, step.get("no_input_template_id"))
            continue
        c["keys"].append(d)
        k = keys.get(d)
        if not k:
            await say(agi, c, step.get("invalid_template_id"))
            continue
        misses = 0
        action = k.get("action")
        act(c, "key", f"{d} চেপেছেন: {action}")
        nxt = by_name.get(k.get("next") or "")
        if action == "replay":
            continue
        if action == "goto":
            step = nxt
            continue
        if action == "sms":
            ok = await loop.run_in_executor(vb().pool, send_sms, c, k.get("sms_template_id"))
            c["result"] = c.get("result") or ("sms" if ok else None)   # a reconnect earlier in the call counts more
            if ok:
                await say(agi, c, k.get("say"))
            else:
                await agi.play([await loop.run_in_executor(vb().pool, spoken, c["company_id"], "দুঃখিত, এই মুহূর্তে SMS পাঠানো যাচ্ছে না।")])
            step = nxt
            continue
        if action == "reconnect":
            res = await loop.run_in_executor(vb().pool, reconnect, c)
            c["reconnect_message"] = res.get("say") or "এই মুহূর্তে লাইন চালু করা গেল না।"
            ok = bool(res.get("ok"))
            c["result"] = "reconnected" if ok else "reconnect_failed"
            await say(agi, c, k.get("say") if ok else k.get("say_fail"))
            step = nxt if ok else by_name.get(k.get("next_fail") or "")
            continue
        if action == "ticket":
            res = await loop.run_in_executor(vb().pool, ticket, c, k.get("problem") or "রিমাইন্ডার কল থেকে কাস্টমার যোগাযোগ চেয়েছেন")
            c["result"] = "ticket" if res.get("ok") else c.get("result")
            await say(agi, c, k.get("say"))
            step = nxt
            continue
        if action == "optout":
            await loop.run_in_executor(vb().pool, optout, c)
            c["result"] = "optout"
            await say(agi, c, k.get("say"))
            goodbye = False
            break
        if action == "end":
            await say(agi, c, k.get("say"))
            break
        if action == "staff":
            await staff(agi, uid, c, flow.get("hold") or {})
            return
        break
    if goodbye:
        await say(agi, c, flow.get("goodbye_template_id"))
    await agi.cmd("HANGUP")


# --- what the keys do ---------------------------------------------------------------------------------------------
def tenant(c: dict):
    return vb().tenant_for(c["company_id"])


def send_sms(c: dict, sms_id) -> bool:
    from engine import sms
    s = (c["package"].get("sms") or {}).get(str(sms_id))
    st = sms.settings(c["company_id"])
    if not s or not st or not st.get("enabled") or not sms.active(c["company_id"], st):
        act(c, "sms", "SMS পাঠানো বন্ধ বা গেটওয়ে নেই, SMS যায়নি", False)
        return False
    cust = c["package"].get("customer") or {}
    db.execute("insert into sms_messages (company_id, customer_header_id, customer_id, name, mobile, text, parts, unicode, status,"
               " created_at, updated_at) values (%s, %s, %s, %s, %s, %s, %s, %s, 'queued', now(), now())",
               (c["company_id"], cust.get("header_id"), cust.get("customer_id"), cust.get("name"), c["number"], s["text"],
                int(s.get("parts") or 1), bool(s.get("unicode"))))
    sms.kick(c["company_id"])
    act(c, "sms", "পেমেন্টের SMS পাঠানো হলো")
    return True


def reconnect(c: dict) -> dict:
    cust = c["package"].get("customer") or {}
    if not cust.get("customer_id"):
        res = {"ok": False, "say": "আপনার সংযোগটা খুঁজে পাওয়া যায়নি। আমাদের সাথে কথা বলতে ২ চাপুন।"}
    else:
        try:
            res = vb().reconnect_line(tenant(c), c["number"], str(cust["customer_id"]), "hotline")
        except Exception as e:
            log.exception("reminder reconnect")
            res = {"ok": False, "say": "এই মুহূর্তে লাইন চালু করা গেল না। আমাদের অফিসের একজন দেখে যোগাযোগ করবেন।", "error": str(e)}
    act(c, "reconnect", res.get("say") or res.get("note") or res.get("error") or "", bool(res.get("ok")))
    return res


def ticket(c: dict, problem: str) -> dict:
    cust = c["package"].get("customer") or {}
    if not cust.get("customer_id"):
        act(c, "ticket", "কাস্টমার জানা নেই, টিকেট খোলা হয়নি", False)
        return {"ok": False}
    try:
        res = vb().create_complaint(tenant(c), c["number"], str(cust["customer_id"]), f"[রিমাইন্ডার কল] {problem}")
    except Exception as e:
        log.exception("reminder ticket")
        res = {"ok": False, "error": str(e)}
    act(c, "ticket", f"টিকেট {res.get('ticket_no') or ''}".strip() if res.get("ok") else "টিকেট খোলা যায়নি", bool(res.get("ok")))
    return res


def optout(c: dict) -> None:
    cust = c["package"].get("customer") or {}
    db.execute("insert into voice_optouts (company_id, number, customer_header_id, source, created_at, updated_at)"
               " values (%s, %s, %s, 'call', now(), now()) on conflict (company_id, number) do nothing",
               (c["company_id"], c["number"], cust.get("header_id")))
    act(c, "optout", "আর রিমাইন্ডার কল চান না")


# --- talking to staff ---------------------------------------------------------------------------------------------
async def staff(agi: Agi, uid: str, c: dict, hold: dict) -> None:
    v = vb()
    loop = asyncio.get_running_loop()
    label = c["package"].get("label") or ""
    v.calls[uid] = {"company_id": c["company_id"], "caller": c["number"], "at": time.time(), "channel": "hotline",
                    "state": "waiting", "row_id": c["log_id"]}
    await loop.run_in_executor(v.pool, lambda: (
        db.execute("update call_logs set status = 'ringing', updated_at = now() where id = %s", (c["log_id"],)),
        v.notify_staff(c["company_id"], {"type": "call", "uid": uid, "caller": v.display_number(c["number"]),
                                         "name": (label + " · " if label else "") + "রিমাইন্ডার কল", "channel": "hotline"},
                       caller=c["number"])))
    act(c, "staff", "স্টাফের সাথে কথা বলতে চেয়েছেন")
    c["result"] = "staff"
    await say(agi, c, hold.get("start_template_id"))
    every, max_wait = max(10, int(hold.get("every") or 25)), max(20, int(hold.get("max_wait") or 90))
    sound_on = {"ring": ("EXEC", "Playtones", "ring"), "music": ("MUSIC", None, None)}.get(hold.get("sound") or "ring")
    began, last_msg, agent = time.time(), time.time(), None

    async def on():
        if sound_on and sound_on[0] == "EXEC":
            await agi.exec("Playtones", "ring")
        elif sound_on:
            await agi.cmd("SET MUSIC on default")

    async def off():
        if sound_on and sound_on[0] == "EXEC":
            await agi.exec("StopPlaytones")
        elif sound_on:
            await agi.cmd("SET MUSIC off")

    await on()
    while time.time() - began < max_wait:
        res, data = await agi.cmd(f"DATABASE GET zyrotake {uid}")
        if res == 1:
            agent = data.strip().strip("()")
            await agi.cmd(f"DATABASE DEL zyrotake {uid}")
            break
        if hold.get("repeat_template_id") and time.time() - last_msg >= every:
            await off()
            await say(agi, c, hold.get("repeat_template_id"))
            await on()
            last_msg = time.time()
        await agi.exec("Wait", "0.5")
    await off()
    if agent:
        act(c, "staff", "স্টাফ ধরেছেন")
        await agi.exec("MixMonitor", f"{v.REC_SPOOL}/{uid}.wav")
        await agi.exec("Bridge", f"{agent},x")
        return
    # nobody took it: stop the ringing in Desk
    with v.lock:
        info = v.calls.get(uid) or {}
        late = info.get("state") == "taken"
        if not late:
            info["state"] = "missed"
    if late:   # taken this very moment: give the bridge a second
        await agi.exec("Wait", "2")
        res, data = await agi.cmd(f"DATABASE GET zyrotake {uid}")
        if res == 1:
            await agi.cmd(f"DATABASE DEL zyrotake {uid}")
            await agi.exec("MixMonitor", f"{v.REC_SPOOL}/{uid}.wav")
            await agi.exec("Bridge", f"{data.strip().strip('()')},x")
            return
    v.calls.pop(uid, None)
    await loop.run_in_executor(v.pool, lambda: (
        db.execute("update call_logs set status = 'answered', updated_at = now() where id = %s", (c["log_id"],)),
        v.notify_staff(c["company_id"], {"type": "call_taken", "uid": uid, "by": ""})))
    after = hold.get("after") or "voicemail"
    if after == "voicemail":
        await say(agi, c, hold.get("voicemail_template_id"))
        path = f"{v.REC_SPOOL}/vm-{uid}"
        await agi.cmd(f'RECORD FILE "{path}" wav "#" 90000 0 BEEP s=6')
        await loop.run_in_executor(v.pool, voicemail, c, path + ".wav")
        c["result"] = "voicemail"
    elif after == "callback":
        await say(agi, c, hold.get("callback_template_id"))
        await loop.run_in_executor(v.pool, callback, c)
        c["result"] = "callback"
    else:
        await say(agi, c, (c["package"].get("flow") or {}).get("goodbye_template_id"))
    await agi.cmd("HANGUP")


def voicemail(c: dict, wav: str) -> None:
    v = vb()
    try:
        if os.path.exists(wav) and os.path.getsize(wav) > 8000:
            rel = f"{c['company_id']}/{c['log_id']}.mp3"
            os.makedirs(os.path.join(v.REC_DIR, str(c["company_id"])), exist_ok=True)
            r = subprocess.run(["ffmpeg", "-loglevel", "error", "-y", "-i", wav, "-ac", "1", "-b:a", "32k",
                                os.path.join(v.REC_DIR, rel)], capture_output=True, text=True, timeout=120)
            if r.returncode == 0:
                db.execute("update call_logs set recording_path = %s, callback_reason = %s, updated_at = now() where id = %s",
                           (rel, "রিমাইন্ডার কলে ভয়েস মেসেজ রেখেছেন", c["log_id"]))
                act(c, "voicemail", "ভয়েস মেসেজ রেখেছেন")
                return
        callback(c, "কেউ ধরেননি, ভয়েস মেসেজও রাখেননি")
    finally:
        try:
            os.unlink(wav)
        except OSError:
            pass


def callback(c: dict, reason: str = "রিমাইন্ডার কলে স্টাফ চেয়েছিলেন, কেউ ধরেননি; কল ব্যাক চান") -> None:
    db.execute("update call_logs set callback_reason = %s, updated_at = now() where id = %s", (reason, c["log_id"]))
    act(c, "callback", reason)


async def queue_loop() -> None:
    while True:
        try:
            await asyncio.get_running_loop().run_in_executor(vb().pool, run_queue)
        except Exception:
            log.exception("reminder queue")
        await asyncio.sleep(5)
