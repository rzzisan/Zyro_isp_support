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

from engine import agent, db
from engine import voice_bot as vb

log = logging.getLogger("voice_cascade")

STT_MODELS = list(agent.GEMINI_VOICE_MODELS)
LLM_MODEL = "claude-haiku-5-5"
TTS_MODELS = ["gemini-3.8-flash-lite-tts", agent.GEMINI_TTS_MODEL]   # fastest first (Zisan's key, 2026-10-28)
SPEECH_RMS = vb.SILENCE_RMS or 200
MIN_SPEECH = 10      # 200 ms of voice before it counts as speech (a click or a cough doesn't)
END_SILENCE = 35     # 700 ms of quiet ends what the caller said
MAX_UTTERANCE = 750  # 15 s: a long sentence is cut there and answered
BARGE_IN = 15        # 300 ms of the caller's voice while the bot talks stops the bot
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
                                                                  **agent.GEMINI_VOICE_EXTRA.get(model, {})}})
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
    body = {"contents": [{"parts": [{"text": agent.TTS_STYLE + text}]}],
            "generationConfig": {"responseModalities": ["AUDIO"], "speechConfig": {
                "voiceConfig": {"prebuiltVoiceConfig": {"voiceName": voice}}}}}
    for row in agent._keys(t, "gemini", usable_only=True, job="call_tts"):
        for model in TTS_MODELS:
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


def spoken(t, text: str, voice: str) -> bytes:
    """A fixed line (greeting, wrap-up) made once and kept in the state dir."""
    path = os.path.join(vb.STATE_DIR, f"line-{hashlib.sha1((voice + text).encode()).hexdigest()[:12]}.pcm")
    if os.path.exists(path):
        return open(path, "rb").read()
    pcm = speak(t, text, voice)
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
            with client.messages.stream(model=LLM_MODEL, max_tokens=400, system=system, messages=messages,
                                        tools=tools) as stream:
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
        except (anthropic.AuthenticationError, anthropic.PermissionDeniedError, anthropic.RateLimitError,
                anthropic.InternalServerError, anthropic.APIConnectionError) as e:
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
        self.ack = b""       # ACK's audio, made while the greeting plays
        self.acked = False

    async def in_pool(self, fn, *args):
        return await asyncio.get_running_loop().run_in_executor(vb.pool, fn, *args)

    async def listen(self) -> None:
        """The caller's audio: utterances by loudness; talking over the bot stops it."""
        voiced = quiet = 0
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
            loud = audioop.rms(payload, 2) >= SPEECH_RMS
            if not speech:
                held.append(payload)
                voiced = voiced + 1 if loud else 0
                if voiced >= MIN_SPEECH:
                    speech += b"".join(held)
                    held.clear()
                    quiet = 0
                continue
            speech += payload
            quiet = 0 if loud else quiet + 1
            if loud and self.out and len(speech) >= BARGE_IN * vb.FRAME:
                self.out.clear()   # the caller talks over the bot: stop at once, answer the new words
                if self.reply and not self.reply.done():
                    self.reply.cancel()
            if quiet >= END_SILENCE or len(speech) >= MAX_UTTERANCE * vb.FRAME:
                await self.utterances.put(bytes(speech))
                speech, voiced = bytearray(), 0
        self.done.set()

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

    async def answer(self, pcm: bytes) -> None:
        self.acked = False
        text = (await self.hear(pcm)).strip()
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
        if short:
            job = loop.run_in_executor(vb.pool, spoken, self.t, sentence, self.voice)
        else:
            job = loop.run_in_executor(vb.pool, speak, self.t, sentence, self.voice,
                                       lambda pcm: loop.call_soon_threadsafe(q.put_nowait, pcm))

        def done(f):
            if not f.cancelled() and f.exception():
                log.warning("call %s: no voice for a sentence: %s", self.uid, f.exception())
            elif short and not f.cancelled():
                q.put_nowait(f.result())
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
        call.out += await call.in_pool(spoken, call.t, greeting(prep, company), call.voice)
        call.say("bot", greeting(prep, company))
        call.ack = await call.in_pool(spoken, call.t, ACK, call.voice)
        tasks = [asyncio.create_task(c) for c in (call.listen(), call.to_caller(), call.converse(), call.done.wait())]
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
