"""The cheaper phone bot ("লেখা-ভিত্তিক", plan /mnt/project-files/voice-bot/voice-cascade-plan.md, Zisan 2026-10-28).

Gemini Live bills the whole call again on every turn (all audio so far, the prompt, the tools) and has no cache: a
78 s call cost $0.059, $0.035 of it re-heard audio. Here each piece is paid once:
  caller audio → our own speech detection (RMS) → one utterance → Gemini (generateContent) writes it in Bangla
  → Claude Haiku answers with the rules in Anthropic's prompt cache and only the FAQ / instructions this question
    needs → Gemini TTS reads each sentence → the caller.
Same tools, call log, recording and guards as the Live bot (engine/voice_bot.py Call); per company on the হটলাইন (SIP)
page: sip_settings.bot_engine = cascade, cascade_scope test (only bot_test_numbers) | all. Keys per job (panel AI key →
কোন কাজে কোন key): call_stt, call_llm, call_tts.
"""
import asyncio
import audioop
import base64
import hashlib
import io
import json
import logging
import os
import re
import time
import wave
from collections import deque

import anthropic
import httpx
import websockets

from engine import agent, db
from engine import voice_bot as vb

log = logging.getLogger("voice_cascade")

STT_MODELS = list(agent.GEMINI_VOICE_MODELS)
# 2.5-flash thinks by default: ~4.7 s per utterance on call 604; writing down words needs no thinking
STT_EXTRA = {**agent.GEMINI_VOICE_EXTRA, "gemini-2.5-flash": {"thinkingConfig": {"thinkingBudget": 0}}}
LLM_MODEL = "claude-haiku-5-5"
TTS_MODELS = ["gemini-3.8-flash-lite-tts", agent.GEMINI_TTS_MODEL]   # fastest first (Zisan's key, 2026-10-28)
SPEECH_RMS = vb.SILENCE_RMS or 200
MIN_SPEECH = 10      # 200 ms of voice before it counts as speech (a click or a cough doesn't)
END_SILENCE = 35     # 700 ms of quiet ends what the caller said
MAX_UTTERANCE = 750  # 15 s: a long sentence is cut there and answered
ECHO_TAIL = 0.4      # after the bot's last sound the line is listened to again
PREROLL = 10
HISTORY = 12         # messages kept for Claude (about 6 exchanges)
SENTENCE_END = re.compile(r"(?<=[।?!])\s+")
SHORT_LINE = 40      # sentences up to this long (no digits) are kept as audio and reused: "জি, বলুন।" isn't made twice
ACK = "জি,"          # played as soon as the caller's words are written, so the wait for the answer isn't silent

STT_PROMPT = (
    "এটা বাংলাদেশের একটা ইন্টারনেট সার্ভিস প্রোভাইডারের (ISP) হটলাইনে কাস্টমারের ফোন কলের একটা অংশ। কাস্টমার বাংলায় "
    "কথা বলেন, প্রায়ই আঞ্চলিক টানে (সিলেট, চট্টগ্রাম, নোয়াখালী, ময়মনসিংহ ইত্যাদি)। যা বলেছেন হুবহু শুধু বাংলা হরফে লেখো; "
    "হিন্দি, উর্দু বা অন্য ভাষা বা হরফ কখনো না। router, net, slow, ID, line-এর মতো ইংরেজি শব্দ যেভাবে বলা হয়েছে সেভাবে "
    "রাখো। মুখে বলা সংখ্যা (কাস্টমার ID, মোবাইল নম্বর, টাকা) অঙ্কে লেখো, যেমন \"ডাবল জিরো ফাইভ টু\" → 0052। "
    "প্রায়ই আসা শব্দ: অনু (ONU), রাউটার, লাইন, নেট, স্লো, বিল, বকেয়া, লাল বাতি, লস, বিকাশ, নগদ, রকেট, কাস্টমার আইডি, "
    "পেমেন্ট, টেকনিশিয়ান, তার, ফাইবার, লাইন চালু, লাইন বন্ধ। শুধু কাস্টমারের কথাটা লেখো, কোনো ব্যাখ্যা না। কিছুই বোঝা "
    "না গেলে বা শুধু শব্দ/নীরবতা হলে খালি উত্তর দাও।"
)
STT_STRICT = STT_PROMPT + (" আগেরবার লেখা অন্য ভাষার হরফে এসেছিল, সেটা ভুল: কাস্টমার বাংলাতেই বলছেন। এবার অবশ্যই শুধু "
                           "বাংলা হরফে লেখো।")
CALL_STYLE = ("\n\nউত্তরের ধরন: এটা ফোন কল, তোমার লেখা উত্তর কণ্ঠে পড়ে শোনানো হবে। খুব ছোট উত্তর, সাধারণত এক-দুই বাক্য, "
              "কথ্য বাংলায়। লিস্ট, চিহ্ন, ইমোজি, লিংক না। কলারের কথা \"[বোঝা যায়নি]\" হলে অনুমান করবে না, অভিযোগও খুলবে না: "
              "বলো \"জি, একটু আরেকবার বলবেন?\"।")
UNCLEAR = "[বোঝা যায়নি]"
# streaming speech-to-text (Zisan 2026-10-28, after call 610: sending each finished utterance to generateContent took
# 3-4 s, and our loudness-only end detection lost a sentence): the caller's audio goes to the Live transcription model
# as it is spoken, words come back while they talk. If it can't be opened, the call falls back to transcribe().
TRANSCRIBE_MODEL = "gemini-3.5-transcribe-live"
VOCAB = ["ONU", "অনু", "router", "রাউটার", "লাইন", "নেট", "স্লো", "বিল", "বকেয়া", "লাল বাতি", "লস", "বিকাশ", "নগদ",
         "রকেট", "কাস্টমার আইডি", "পেমেন্ট", "টেকনিশিয়ান", "ফাইবার", "লাইন চালু", "লাইন বন্ধ", "পাসওয়ার্ড", "ওয়াইফাই"]
STREAM_END_SILENCE = 25   # 500 ms of quiet: tell the transcriber the caller stopped, so it writes the final words now


def greeting(prep: dict, company: str) -> str:
    if prep.get("staff"):
        return "জি ভাই, বলুন।"
    return f"আসসালামু আলাইকুম, {company}-এ আপনাকে স্বাগতম। কলটি রেকর্ড করা হচ্ছে। বলুন, কীভাবে সাহায্য করতে পারি?"


# --- the three AI steps (run in the thread pool) ---------------------------------------------------
def wav(pcm8: bytes) -> bytes:
    buf = io.BytesIO()
    with wave.open(buf, "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(8000)
        w.writeframes(pcm8)
    return buf.getvalue()


def transcribe(t, pcm8: bytes, strict: bool = False) -> str:
    """The caller's words in Bangla script ("" when nothing was understood); every call_stt key, then the next model."""
    body = {"contents": [{"parts": [{"text": STT_STRICT if strict else STT_PROMPT},
                                    {"inline_data": {"mime_type": "audio/wav", "data": base64.b64encode(wav(pcm8)).decode()}}]}],
            "generationConfig": {"temperature": 0}}
    for row in agent._keys(t, "gemini", usable_only=True, job="call_stt"):
        for model in STT_MODELS:
            try:
                r = httpx.post(f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent",
                               headers={"x-goog-api-key": db.decrypt(row["api_key"])}, timeout=20,
                               json={**body, "generationConfig": {**body["generationConfig"],
                                                                  **STT_EXTRA.get(model, {})}})
            except httpx.TransportError as e:
                agent.record_usage(t, row["id"], "gemini", model, "call_stt", None, error=f"{type(e).__name__}: {e}")
                continue
            if r.status_code == 404:
                continue
            if r.status_code != 200:
                agent.record_usage(t, row["id"], "gemini", model, "call_stt", None, error=f"HTTP {r.status_code}: {r.text[:200]}")
                break
            data = r.json()
            u = data.get("usageMetadata") or {}
            agent.record_usage(t, row["id"], "gemini", model, "call_stt", None,
                               {"input": u.get("promptTokenCount") or 0, "output": u.get("candidatesTokenCount") or 0})
            parts = ((data.get("candidates") or [{}])[0].get("content") or {}).get("parts") or []
            return "".join(p.get("text", "") for p in parts).strip()
    raise RuntimeError("no Gemini key could transcribe")


def speak(t, text: str, voice: str, chunk=None) -> bytes:
    """One sentence read by Gemini TTS, as 8 kHz 16-bit PCM for the call. Streamed: each piece goes to chunk() as it
    arrives (the first after ~1.3 s on 3.8-flash-lite-tts; the whole sentence took 4.3 s on 2.5-flash-preview-tts)."""
    for row in agent._keys(t, "gemini", usable_only=True, job="call_tts"):
        for model in TTS_MODELS:
            # 3.x reads the style note aloud too (call 604: a 36-letter line came back as 10.5 s of audio)
            body = {"contents": [{"parts": [{"text": text if model.startswith("gemini-3") else agent.TTS_STYLE + text}]}],
                    "generationConfig": {"responseModalities": ["AUDIO"], "speechConfig": {
                        "voiceConfig": {"prebuiltVoiceConfig": {"voiceName": voice}}}}}
            got, state, odd, usage = bytearray(), None, b"", {}
            try:
                with httpx.stream("POST", f"https://generativelanguage.googleapis.com/v1beta/models/{model}:streamGenerateContent",
                                  params={"alt": "sse"}, headers={"x-goog-api-key": db.decrypt(row["api_key"])},
                                  json=body, timeout=30) as r:
                    if r.status_code != 200:
                        r.read()
                        if r.status_code != 404:
                            agent.record_usage(t, row["id"], "gemini", model, "call_tts", None,
                                               error=f"HTTP {r.status_code}: {r.text[:200]}")
                        continue
                    for line in r.iter_lines():
                        if not line.startswith("data:"):
                            continue
                        data = json.loads(line[5:])
                        usage = data.get("usageMetadata") or usage
                        for part in ((data.get("candidates") or [{}])[0].get("content") or {}).get("parts") or []:
                            inline = part.get("inlineData") or {}
                            if not inline.get("data"):
                                continue
                            rate = re.search(r"rate=(\d+)", inline.get("mimeType") or "")
                            raw = odd + base64.b64decode(inline["data"])
                            cut = len(raw) // 2 * 2
                            raw, odd = raw[:cut], raw[cut:]
                            pcm, state = audioop.ratecv(raw, 2, 1, int(rate.group(1)) if rate else 24000, 8000, state)
                            got += pcm
                            if chunk:
                                chunk(pcm)
            except httpx.TransportError as e:
                agent.record_usage(t, row["id"], "gemini", model, "call_tts", None, error=f"{type(e).__name__}: {e}")
                if not got:
                    continue
            agent.record_usage(t, row["id"], "gemini", model, "call_tts", None,
                               {"input": usage.get("promptTokenCount") or 0, "output": usage.get("candidatesTokenCount") or 0})
            if got:
                return bytes(got)
    raise RuntimeError("no Gemini key could speak")


def spoken(t, text: str, voice: str, chunk=None) -> bytes:
    """A fixed or short line (greeting, wrap-up, "জি, বলুন।") made once and kept in the state dir."""
    key = "|".join([TTS_MODELS[0], voice, text])   # a new model makes the lines again
    path = os.path.join(vb.STATE_DIR, f"line-{hashlib.sha1(key.encode()).hexdigest()[:12]}.pcm")
    if os.path.exists(path):
        pcm = open(path, "rb").read()
        if chunk:
            chunk(pcm)
        return pcm
    pcm = speak(t, text, voice, chunk)
    with open(path, "wb") as f:
        f.write(pcm)
    return pcm


def claude_tools(staff: bool) -> list[dict]:
    """The Live bot's tools in Anthropic's format."""
    def schema(p):
        if not p:
            return {"type": "object", "properties": {}}
        out = {"type": p.get("type", "OBJECT").lower()}
        if "properties" in p:
            out["properties"] = {k: schema(v) for k, v in p["properties"].items()}
        for k in ("required", "enum", "description"):
            if k in p:
                out[k] = p[k]
        return out
    decls = (vb.STAFF_TOOLS if staff else vb.TOOLS)[0]["functionDeclarations"]
    return [{"name": d["name"], "description": d.get("description", ""), "input_schema": schema(d.get("parameters"))}
            for d in decls]


def think(t, system: list[dict], messages: list[dict], tools: list[dict], heard=None):
    """One Claude reply (the rules and the caller's data come from the prompt cache after the first turn); streamed,
    each finished sentence goes to heard() at once so its voice is made while Claude still writes the next."""
    last = None
    for row in agent._keys(t, "claude", usable_only=True, job="call_llm"):
        client = anthropic.Anthropic(api_key=db.decrypt(row["api_key"]), max_retries=1)
        sent = False
        try:
            # Haiku 5.5 thinks by default (adaptive): on call 604 its 400 tokens went to thinking and nothing was said
            with client.messages.stream(model=LLM_MODEL, max_tokens=800, system=system, messages=messages,
                                        tools=tools, thinking={"type": "disabled"}) as stream:
                buf = ""
                for ev in stream:
                    if heard and ev.type == "content_block_delta" and getattr(ev.delta, "type", "") == "text_delta":
                        buf += ev.delta.text
                        *done, buf = SENTENCE_END.split(buf)
                        for x in done:
                            heard(x)
                            sent = True
                    elif heard and ev.type == "content_block_stop" and buf.strip():
                        heard(buf)
                        buf, sent = "", True
                r = stream.get_final_message()
            if r.stop_reason not in ("end_turn", "tool_use"):
                log.warning("claude stopped: %s, blocks %s", r.stop_reason, [b.type for b in r.content])
        except (anthropic.APIStatusError, anthropic.APIConnectionError) as e:
            last = e
            agent.record_usage(t, row["id"], "claude", LLM_MODEL, "call_llm", None, error=str(e)[:300])
            if sent:
                raise   # half the answer is already on its way to the caller: no second answer from another key
            continue
        u = r.usage
        cached = int(getattr(u, "cache_read_input_tokens", 0) or 0)
        agent.record_usage(t, row["id"], "claude", LLM_MODEL, "call_llm", None, {
            "input": int(u.input_tokens or 0) + cached + int(getattr(u, "cache_creation_input_tokens", 0) or 0),
            "output": int(u.output_tokens or 0), "cached": cached})
        return r
    raise RuntimeError(f"no Claude key answered: {last}")


def relevant(t, query: str) -> str:
    """Only the company instructions and FAQ that share words with what the caller said (not all of them every turn)."""
    if not t:
        return ""
    terms = agent._terms(query)
    out = []
    extra = (t.bot.get("extra_prompt") or "").strip()
    if extra:
        paras = [p.strip() for p in re.split(r"\n\s*\n", extra) if p.strip()]
        if len(extra) <= 600:
            out += paras
        else:
            scored = sorted(((agent._score(terms, {"question": p, "answer": ""}), i) for i, p in enumerate(paras)), reverse=True)
            out += [paras[i] for s, i in scored[:3] if s > 0]
    rows = agent.faqs(t)
    scored = sorted(((agent._score(terms, r), i) for i, r in enumerate(rows)), key=lambda x: (-x[0], x[1]))
    out += [f"প্রশ্ন: {rows[i]['question']}\nউত্তর: {rows[i]['answer']}" for s, i in scored[:agent.FAQ_TOP] if s > 0]
    return ("কোম্পানির নির্দেশনা/FAQ থেকে এই প্রশ্নের সাথে মেলে এমন অংশ (এখানকার তথ্য সঠিক, হুবহু মানবে):\n"
            + "\n\n".join(out)) if out else ""


async def open_transcriber(company_id: int):
    """A streaming transcription session on the company's Gemini keys for call_stt, in the picked order."""
    keys = db.all_rows("select id, api_key from ai_keys where company_id = %s and provider = 'gemini'"
                       " and (rate_limited_until is null or rate_limited_until <= now()) order by id", (company_id,))
    keys = agent.in_job_order(company_id, "call_stt", keys)
    last = "no Gemini key"
    for k in keys:
        try:
            ws = await websockets.connect(vb.LIVE_URL, additional_headers={"x-goog-api-key": db.decrypt(k["api_key"])},
                                          max_size=None, open_timeout=8)
            await ws.send(json.dumps({"setup": {
                "model": f"models/{TRANSCRIBE_MODEL}",
                "generationConfig": {"responseModalities": ["TEXT"]},
                "inputAudioTranscription": {"languageCodes": ["bn-BD"], "customVocabulary": VOCAB},
            }}))
            first = json.loads(await asyncio.wait_for(ws.recv(), 8))
            if "setupComplete" in first:
                return Transcriber(ws, k["id"])
            last = str(first)[:200]
            await ws.close()
        except Exception as e:
            last = f"{type(e).__name__}: {e}"
    raise RuntimeError(f"no transcription session: {last}")


class Transcriber:
    """The caller's side of the line, streamed (16 kHz, 100 ms pieces); finished words collect in .pending."""

    def __init__(self, ws, key_id: int):
        self.ws, self.key_id = ws, key_id
        self.alive, self.state, self.buf = True, None, bytearray()
        self.pending: list[str] = []
        self.last_final = 0.0
        self.seconds = 0.0
        self.usage = {"input": 0, "output": 0}

    async def send(self, msg: dict) -> None:
        if not self.alive:
            return
        try:
            await self.ws.send(json.dumps(msg))
        except Exception as e:
            log.warning("transcriber send: %s", e)
            self.alive = False

    async def feed(self, pcm8: bytes) -> None:
        pcm16, self.state = audioop.ratecv(pcm8, 2, 1, 8000, 16000, self.state)
        self.buf += pcm16
        self.seconds += len(pcm8) / 16000
        if len(self.buf) >= 3200:
            await self.flush()

    async def flush(self) -> None:
        if self.buf:
            data, self.buf = bytes(self.buf), bytearray()
            await self.send({"realtimeInput": {"audio": {"data": base64.b64encode(data).decode(),
                                                         "mimeType": "audio/pcm;rate=16000"}}})

    async def stopped(self) -> None:
        """The caller went quiet: the final words now, not after the server's own silence wait."""
        await self.flush()
        await self.send({"realtimeInput": {"audioStreamEnd": True}})

    async def read(self) -> None:
        try:
            async for raw in self.ws:
                msg = json.loads(raw)
                text = (((msg.get("serverContent") or {}).get("inputTranscription")) or {}).get("text") or ""
                if text.strip():
                    self.pending.append(text.strip())
                    self.last_final = time.monotonic()
                u = msg.get("usageMetadata") or {}
                self.usage["input"] += int(u.get("promptTokenCount") or 0)
                self.usage["output"] += int(u.get("responseTokenCount") or u.get("candidatesTokenCount") or 0)
        except Exception as e:
            log.warning("transcriber: %s", e)
        finally:
            self.alive = False

    async def close(self, t) -> None:
        self.alive = False
        try:
            await self.ws.close()
        except Exception:
            pass
        agent.record_usage(t, self.key_id, "gemini", TRANSCRIBE_MODEL, "call_stt", None, self.usage)


# --- one call ----------------------------------------------------------------------------------------
class CascadeCall(vb.Call):
    cascade = True

    def __init__(self, uid, info, reader, writer):
        super().__init__(uid, info, reader, writer)
        prep = info.get("prep_result") or {}
        self.t = prep.get("tenant")
        self.voice = prep.get("voice") or "Kore"
        staff = bool(prep.get("staff"))
        rules = (prep["prompt"] if staff else prep.get("rules") or prep["prompt"]) + CALL_STYLE
        self.system = [{"type": "text", "text": rules, "cache_control": {"type": "ephemeral"}}]
        if not staff and prep.get("info"):
            self.system.append({"type": "text", "text": prep["info"], "cache_control": {"type": "ephemeral"}})
        self.tools = claude_tools(staff)
        self.messages: list[dict] = []
        self.utterances: asyncio.Queue = asyncio.Queue()
        self.reply: asyncio.Task | None = None
        self.unclear = 0
        self.stt: Transcriber | None = None
        self.speaking = False
        self.waiting: tuple[bytes, float] | None = None   # an utterance whose streamed words haven't come yet
        self.ack = b""       # ACK's audio, made while the greeting plays
        self.acked = False

    async def in_pool(self, fn, *args):
        return await asyncio.get_running_loop().run_in_executor(vb.pool, fn, *args)

    async def listen(self) -> None:
        """The caller's audio → utterances. Half duplex: while the bot talks (and a moment after) the line is not
        listened to, since its own voice comes back as echo; on call 606 echo/noise of 100-1000 RMS was taken for the
        caller and cut every reply after 0.3 s. What counts as voice follows the line's own noise level."""
        voiced = quiet = 0
        floor = last_out = 0.0
        speech, held = bytearray(), deque(maxlen=PREROLL)
        while not self.done.is_set():
            head = await self.reader.readexactly(3)
            n = int.from_bytes(head[1:], "big")
            payload = await self.reader.readexactly(n) if n else b""
            if head[0] in (0x00, 0xFF):
                break
            if head[0] != 0x10:
                continue
            self.caller_track += payload
            now = time.monotonic()
            if self.out:
                last_out = now
            streaming = self.stt and self.stt.alive
            if now - last_out < ECHO_TAIL and not speech:
                held.clear()
                voiced = 0
                if streaming:
                    await self.stt.feed(b"\x00" * len(payload))   # the bot's own echo is not sent to be written down
                continue
            if streaming:
                await self.stt.feed(payload)
            level = audioop.rms(payload, 2)
            loud = level >= min(max(SPEECH_RMS, floor * 2), 1500)
            if not speech:
                if not loud:
                    floor = level if not floor else floor * 0.98 + level * 0.02
                held.append(payload)
                voiced = voiced + 1 if loud else 0
                if voiced >= MIN_SPEECH:
                    speech += b"".join(held)
                    held.clear()
                    quiet = 0
                    self.speaking = True
                continue
            speech += payload
            quiet = 0 if loud else quiet + 1
            if streaming and quiet >= STREAM_END_SILENCE:
                await self.stt.stopped()   # its words arrive in self.stt.pending; gather() hands them on
                self.waiting = (bytes(speech), time.monotonic())
                speech, voiced, self.speaking = bytearray(), 0, False
            elif quiet >= END_SILENCE or len(speech) >= MAX_UTTERANCE * vb.FRAME:
                await self.utterances.put(bytes(speech))
                speech, voiced, self.speaking = bytearray(), 0, False
        self.done.set()

    async def gather(self) -> None:
        """Streamed words → one utterance once the caller is quiet and nothing more came for a moment."""
        while not self.done.is_set():
            await asyncio.sleep(0.1)
            stt = self.stt
            if stt and stt.pending and not self.speaking and time.monotonic() - stt.last_final >= 0.25:
                text = " ".join(stt.pending)
                stt.pending.clear()
                self.waiting = None
                await self.utterances.put(text)
            elif self.waiting and time.monotonic() - self.waiting[1] > 2.5 and not self.speaking:
                log.info("call %s: no streamed words for an utterance, written down the old way", self.uid)
                pcm, self.waiting = self.waiting[0], None
                await self.utterances.put(pcm)

    async def converse(self) -> None:
        while not self.done.is_set():
            pcm = await self.utterances.get()
            self.reply = asyncio.create_task(self.answer(pcm))
            try:
                await self.reply
            except asyncio.CancelledError:
                if self.done.is_set():
                    raise
            except Exception:
                log.exception("call %s: turn failed", self.uid)

    async def hear(self, pcm: bytes) -> str:
        """The utterance in Bangla; another script (Live's Hindi problem) gets one stricter try, then counts as unclear."""
        text = await self.in_pool(transcribe, self.t, pcm, False)
        if text and vb.FOREIGN.search(text):
            log.info("call %s: transcript in another script, again: %s", self.uid, text[:80])
            text = await self.in_pool(transcribe, self.t, pcm, True)
            if text and vb.FOREIGN.search(text):
                self.act("unclear", "কলারের কথা দুবারই অন্য ভাষার হরফে এসেছে, আবার বলতে বলা হলো", ok=False)
                return UNCLEAR
        return text

    async def answer(self, heard) -> None:
        self.acked = False
        if isinstance(heard, str):   # streamed: already words; another script can't be asked again here
            text = heard.strip()
            if text and vb.FOREIGN.search(text):
                log.info("call %s: streamed transcript in another script: %s", self.uid, text[:80])
                self.act("unclear", "কলারের কথা অন্য ভাষার হরফে এসেছে, আবার বলতে বলা হলো", ok=False)
                text = UNCLEAR
        else:
            text = (await self.hear(heard)).strip()
        if not text:
            return   # noise, a breath: nothing to answer
        if text == UNCLEAR:
            self.unclear += 1
            self.say("caller", UNCLEAR)
            if self.unclear >= 2:
                await self.run_tool("request_callback", {"reason": "কলারের কথা বোঝা যায়নি", "flag": "unsolved"})
                await self.utter("দুঃখিত, আপনার কথা ঠিকমতো বুঝতে পারছি না। আমাদের একজন স্টাফ শিগগিরই আপনাকে ফোন করবেন, ধন্যবাদ।")
                await self.run_tool("end_call", {})
                return
            text = UNCLEAR
        else:
            self.say("caller", text)
            self.known.update(vb.numbers_in(text, 1))
            if self.ack and not self.out:
                self.out += self.ack
                self.acked = True
        query = " ".join(x["text"] for x in self.turns[-3:] if x["who"] == "caller")
        extra = await self.in_pool(relevant, self.t, query)
        self.tidy()
        self.add_user((extra + "\n\nকলার বললেন: " if extra else "") + text)
        await self.respond()

    def tidy(self) -> None:
        """After the caller talked over a reply: no tool call left without its result."""
        while self.messages and self.messages[-1]["role"] == "assistant" and any(
                b.get("type") == "tool_use" for b in self.messages[-1]["content"]):
            self.messages.pop()

    def add_user(self, text: str) -> None:
        last = self.messages[-1] if self.messages else None
        if last and last["role"] == "user" and isinstance(last["content"], str):
            last["content"] += "\n" + text   # roles must alternate
        else:
            self.messages.append({"role": "user", "content": text})

    async def respond(self, retry_note: str | None = None) -> None:
        if retry_note:
            self.add_user(retry_note)
        loop = asyncio.get_running_loop()
        for _ in range(4):   # a reply may need a tool or two first
            speech = Speech(self, check=not retry_note)
            try:
                r = await self.in_pool(think, self.t, self.system, self.messages, self.tools,
                                       lambda x: loop.call_soon_threadsafe(speech.add, x))
                await asyncio.sleep(0)   # the last sentence handed over from the worker thread lands first
                await speech.finish()
            finally:
                speech.stop()
            self.messages.append({"role": "assistant", "content": [b.model_dump(exclude_none=True) for b in r.content]})
            if speech.bad:
                self.messages.pop()
                self.corrections += 1
                self.act("corrected", "উত্তরে যাচাই হয়নি এমন অঙ্ক ছিল, বলার আগেই আবার লেখানো হলো", ok=False)
                return await self.respond("(সিস্টেম: তোমার উত্তরে এমন অঙ্ক ছিল যা কাস্টমার তথ্যে বা টুলের ফলে নেই। "
                                          "অঙ্ক অনুমান করবে না; দরকার হলে lookup_customer বা create_complaint ডেকে ফল থেকে বলো।)")
            uses = [b for b in r.content if b.type == "tool_use"]
            if not uses:
                break
            results = []
            for b in uses:
                res = await self.run_tool(b.name, dict(b.input or {}))
                results.append({"type": "tool_result", "tool_use_id": b.id, "content": vb.brief(res)})
            self.messages.append({"role": "user", "content": results})
            if self.hangup_after_drain:
                break
        while len(self.messages) > HISTORY or (self.messages and not isinstance(self.messages[0]["content"], str)):
            self.messages.pop(0)

    def made_up(self, said: str) -> bool:
        """A money or ticket number that is in no data, tool result or the caller's words (as check_numbers on Live)."""
        if self.corrections >= 2 or not vb.FACTS.search(said):
            return False
        return any(not any(x in k for k in self.known) for x in vb.numbers_in(said))

    def voice_of(self, sentence: str) -> asyncio.Queue:
        """A sentence's audio as it is made (pieces, then None); short lines without numbers come from the kept audio."""
        loop, q = asyncio.get_running_loop(), asyncio.Queue()
        short = len(sentence) <= SHORT_LINE and not re.search(r"[0-9০-৯]", sentence)
        job = loop.run_in_executor(vb.pool, spoken if short else speak, self.t, sentence, self.voice,
                                   lambda pcm: loop.call_soon_threadsafe(q.put_nowait, pcm))

        def done(f):
            if not f.cancelled() and f.exception():
                log.warning("call %s: no voice for a sentence: %s", self.uid, f.exception())
            q.put_nowait(None)
        job.add_done_callback(done)
        return q

    async def utter(self, text: str) -> None:
        speech = Speech(self, check=False)
        try:
            for sentence in SENTENCE_END.split(text):
                speech.add(sentence)
            await speech.finish()
        finally:
            speech.stop()


def clean(text: str) -> str:
    return vb.SPOKEN_TOOL.sub("", re.sub(r"[*_#`•]", "", text)).strip()


class Speech:
    """One reply read aloud: every sentence's voice starts being made as soon as the sentence is written (side by
    side, not one after another), and they play in order. A sentence with a made-up number stops the reply there."""

    def __init__(self, call: CascadeCall, check: bool):
        self.call, self.check, self.bad = call, check, False
        self.queue: asyncio.Queue = asyncio.Queue()
        self.player: asyncio.Task | None = None
        self.first = True

    def add(self, sentence: str) -> None:
        sentence = clean(sentence)
        if self.first and self.call.acked:
            sentence = re.sub(r"^জি(?:\s*[,।!]\s*|\s+)", "", sentence)   # "জি," was already said
        if not sentence or self.bad:
            return
        if self.check and self.call.made_up(sentence):
            self.bad = True
            return
        self.first = False
        self.queue.put_nowait((sentence, self.call.voice_of(sentence)))
        if not self.player:
            self.player = asyncio.create_task(self.play())

    async def play(self) -> None:
        while (item := await self.queue.get()) is not None:
            sentence, voice = item
            while (pcm := await voice.get()) is not None:
                self.call.out += pcm
            self.call.say("bot", sentence + " ")

    async def finish(self) -> None:
        if self.player:
            self.queue.put_nowait(None)
            await self.player

    def stop(self) -> None:
        if self.player and not self.player.done():
            self.player.cancel()


async def run(uid: str, info: dict, reader, writer) -> None:
    """One call answered by the cascade; saved like a Live call (vb.save_call)."""
    call, error = None, None
    try:
        prep = info["prep_result"]
        call = CascadeCall(uid, info, reader, writer)
        company = (prep.get("tenant").name if prep.get("tenant") else "") or ""
        call.act("engine", "লেখা-ভিত্তিক বট (শোনা → Claude → কণ্ঠ)")
        loop = asyncio.get_running_loop()
        opening = asyncio.create_task(open_transcriber(info["company_id"]))
        player = asyncio.create_task(call.to_caller())   # the greeting plays while it is still being made
        await call.in_pool(spoken, call.t, greeting(prep, company), call.voice,
                           lambda pcm: loop.call_soon_threadsafe(call.out.extend, pcm))
        call.say("bot", greeting(prep, company))
        try:
            call.stt = await opening
        except Exception as e:
            log.warning("call %s: no streaming transcription, utterances go to transcribe(): %s", uid, e)
        tasks = [asyncio.create_task(c) for c in (call.listen(), call.converse(), call.done.wait())] + [player]
        if call.stt:
            tasks += [asyncio.create_task(call.stt.read()), asyncio.create_task(call.gather())]

        async def load_ack():
            try:
                call.ack = await call.in_pool(spoken, call.t, ACK, call.voice)
            except Exception as e:
                log.warning("call %s: no ack voice: %s", uid, e)
        asyncio.create_task(load_ack())
        limit = min(int(info["settings"].get("bot_max_seconds") or vb.DEFAULT_MAX_SECONDS), vb.MAX_CALL)
        finished, _ = await asyncio.wait(tasks, timeout=max(limit - 20, 10), return_when=asyncio.FIRST_COMPLETED)
        if not finished:
            call.flag = call.flag or "time_up"
            call.ended_by = "time_up"
            if call.reply and not call.reply.done():
                call.reply.cancel()
            call.out.clear()
            await call.run_tool("request_callback", {"reason": "কলের সময় শেষ", "flag": "time_up"})
            call.out += await call.in_pool(spoken, call.t, "আমাদের একজন স্টাফ আপনার সাথে যোগাযোগ করবেন, ধন্যবাদ।", call.voice)
            call.hangup_after_drain = True
            finished, _ = await asyncio.wait(tasks, timeout=20, return_when=asyncio.FIRST_COMPLETED)
        call.done.set()
        for x in tasks:
            x.cancel()
        log.info("cascade call %s from %s: %ss", uid, info["caller"], int(time.time() - call.started))
    except (asyncio.IncompleteReadError, ConnectionError):
        pass
    except Exception as e:
        error = f"{type(e).__name__}: {e}"
        log.exception("cascade call %s", uid)
    finally:
        if call is not None and call.stt:
            await call.stt.close(call.t)
        try:
            writer.write(b"\x00\x00\x00")
            await asyncio.wait_for(writer.drain(), 2)
        except Exception:
            pass
        try:
            writer.close()
        except Exception:
            pass
        if call is None:
            call = vb.Call(uid, info, reader, writer)
        await asyncio.get_running_loop().run_in_executor(vb.pool, vb.save_call, call, info["company_id"], info["caller"],
                                                         "cascade", None, error)
        vb.calls.pop(uid, None)
