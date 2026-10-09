"""AI replies per company: the company's own keys, provider, model and instructions."""
import base64
import json
import subprocess
import logging
import re
import time

import httpx

from engine.llm import PROVIDERS, SYSTEM_PROMPT, KeyFailed, _call, _limits, _whatsapp_format
from engine import db
from engine.tenant import Tenant

log = logging.getLogger("zyro-engine")


def faqs(t: Tenant) -> list[dict]:
    """The company's FAQ (panel: বটের FAQ), active ones in their order."""
    try:
        return db.all_rows("""SELECT question, answer FROM bot_faqs WHERE company_id = %s AND active
                              ORDER BY sort, id""", (t.company_id,))
    except Exception:
        log.exception("could not read bot FAQ")
        return []


FAQ_ALL_CHARS = 1500  # a small FAQ goes out whole; a bigger one only the entries that match the question
FAQ_TOP = 4
_SUFFIXES = ("গুলো", "গুলা", "টার", "টা", "টি", "কে", "য়ের", "ের", "এর", "র", "ে", "য়", "s")
_STOP = {"কি", "কী", "কত", "আমি", "আমার", "আপনার", "আছে", "আর", "এই", "ওই", "কেন", "কিভাবে", "কীভাবে", "করে", "করব",
         "দিন", "দেন", "ভাই", "জি", "the", "is", "a", "to", "and", "of", "ki", "koto", "ami", "amar", "ache", "vai", "bhai"}


# Banglish/English words customers type, to the Bangla an admin usually writes (both are kept)
_SAME = {"office": "অফিস", "ofis": "অফিস", "kothay": "কোথায়", "kothai": "কোথায়", "address": "ঠিকানা", "thikana": "ঠিকানা",
         "package": "প্যাকেজ", "pakage": "প্যাকেজ", "packege": "প্যাকেজ", "offer": "অফার", "bill": "বিল", "link": "লিংক",
         "channel": "চ্যানেল", "router": "রাউটার", "price": "দাম", "dam": "দাম", "time": "সময়", "somoy": "সময়",
         "speed": "স্পিড", "mbps": "এমবিপিএস", "connection": "সংযোগ", "line": "লাইন", "new": "নতুন", "notun": "নতুন",
         "taka": "টাকা", "tk": "টাকা", "bkash": "বিকাশ", "bikash": "বিকাশ", "nagad": "নগদ", "movie": "মুভি", "game": "গেম"}
_DIGITS = str.maketrans("০১২৩৪৫৬৭৮৯", "0123456789")


def _terms(text: str) -> set[str]:
    out = set()
    for w in re.findall(r"[\wঀ-৿]+", (text or "").lower().translate(_DIGITS)):
        if w in _SAME:
            out.add(_SAME[w])
        for s in _SUFFIXES:
            if len(w) > len(s) + 1 and w.endswith(s):
                w = w[:-len(s)]
                break
        if len(w) >= 2 and w not in _STOP:
            out.add(w)
    return out


def _score(query: set[str], faq: dict) -> int:
    q = _terms(faq["question"])
    words = q | _terms(faq["answer"])
    hit = lambda w: any(w == x or (len(w) >= 3 and len(x) >= 3 and (x.startswith(w) or w.startswith(x))) for x in words)
    # a word of the FAQ's question counts double
    return sum(2 if any(w == x or (len(w) >= 3 and (x.startswith(w) or w.startswith(x))) for x in q) else 1
               for w in query if hit(w))


def relevant_faqs(rows: list[dict], query: str | None) -> list[dict]:
    """All of a small FAQ; of a big one, the few entries that share words with the customer's message."""
    if query is None or sum(len(r["question"]) + len(r["answer"]) for r in rows) <= FAQ_ALL_CHARS:
        return rows
    terms = _terms(query)
    scored = sorted(((_score(terms, r), i) for i, r in enumerate(rows)), key=lambda x: (-x[0], x[1]))
    return [rows[i] for s, i in scored[:FAQ_TOP] if s > 0]


def knowledge(t: Tenant, query: str | None = None) -> str:
    """The company's own instructions and FAQ, as one block for the customer bot and the technician desk.
    query = the message being answered: a big FAQ is cut to the entries that match it (fewer tokens per call);
    None = everything (the panel's "what the bot is told" view)."""
    parts = []
    extra = (t.bot.get("extra_prompt") or "").strip()
    if extra:
        parts.append("=== কোম্পানির নির্দেশনা (অ্যাডমিন লিখেছেন; এখানকার তথ্য সঠিক ও চূড়ান্ত, হুবহু মেনে চলবে) ===\n"
                     f"{extra}\n=== নির্দেশনা শেষ ===")
    rows = relevant_faqs(faqs(t), query)
    if rows:
        parts.append("=== কোম্পানির FAQ (অ্যাডমিন লিখেছেন; প্রশ্নটা হুবহু না মিললেও একই বিষয় হলে এই উত্তর অনুযায়ী বলবে, "
                     "উত্তরের তথ্য বদলাবে না, নিজের ভাষায় ছোট করে বলবে; এখানে থাকা লিংক বা IP কাস্টমারকে হুবহু দেওয়া যাবে) ===\n"
                     + "\n".join(f"প্রশ্ন: {r['question']}\nউত্তর: {r['answer']}" for r in rows)
                     + "\n=== FAQ শেষ ===")
    return "\n\n".join(parts)


def system_prompt(t: Tenant, query: str | None = None) -> str:
    # the company may have edited the built-in rules (Bot settings); empty = the built-in text
    base = (t.bot.get("customer_prompt") or "").strip() or SYSTEM_PROMPT
    prompt = base.replace("Century Link Network (একটি ISP)", f"{t.name} (একটি ISP)")
    block = knowledge(t, query)
    if not block:
        return prompt
    return prompt + "\n\n" + block + (
        "\nএই নির্দেশনা ও FAQ ব্যবহারের নিয়ম: কাস্টমার যে পদ্ধতি বা বিষয় নিয়ে জিজ্ঞেস করেছে (যেমন 'paybill', 'pay bill', "
        "'merchant', 'নগদ'), নির্দেশনা থেকে ঠিক সেই অংশটা ধরে উত্তর দেবে। আগের উত্তরে অন্য পদ্ধতি বলা থাকলে সেটা আবার বলবে না। "
        "নির্দেশনায় যে ধাপ বা নাম লেখা আছে (যেমন কোন অপশনে যেতে হবে, কী লিখে সার্চ করতে হবে) সেগুলো বাদ দেবে না।"
    )


def _keys(t: Tenant, provider: str, usable_only: bool = False) -> list[dict]:
    extra = " AND (rate_limited_until IS NULL OR rate_limited_until <= now())" if usable_only else ""
    return db.all_rows(f"SELECT * FROM ai_keys WHERE company_id = %s AND provider = %s{extra} ORDER BY id",
                       (t.company_id, provider))


_DAILY = re.compile(r"on (tokens|requests) per (day|minute) \((\w+)\): Limit (\d+), Used (\d+)")


def record_usage(t: Tenant, key_id: int | None, provider: str, model: str, purpose: str, contact_id: int | None,
                 usage: dict | None = None, limits: dict | None = None, error: str | None = None) -> None:
    """One row per AI call for the panel's AI খরচ page, plus the key's latest provider quota (rate-limit headers).
    Never stores the key itself."""
    try:
        usage = usage or {}
        db.execute("""INSERT INTO ai_usage (company_id, ai_key_id, provider, model, purpose, contact_id,
                          input_tokens, output_tokens, cached_tokens, ok, error, created_at)
                      VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, now())""",
                   (t.company_id, key_id, provider, model or "", purpose, contact_id, int(usage.get("input") or 0),
                    int(usage.get("output") or 0), int(usage.get("cached") or 0), error is None,
                    (error or "")[:300] or None))
        quota = dict(limits or {})
        m = _DAILY.search(error or "")
        if m:  # Groq's 429 says the daily/minute limit and how much is used: "tokens per day (TPD): Limit 200000, Used 199665"
            quota[f"limit:{m.group(3).lower()}"] = {"limit": int(m.group(4)), "used": int(m.group(5)),
                                                   "at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}
        if quota and key_id:
            db.execute("""UPDATE ai_keys SET quota = coalesce(quota, '{}'::jsonb) || %s::jsonb, quota_at = now()
                          WHERE id = %s""", (json.dumps(quota), key_id))
    except Exception:
        log.exception("could not record AI usage")


def generate(t: Tenant, system: str, messages: list[dict], provider: str, model: str | None,
             purpose: str = "customer", contact_id: int | None = None) -> tuple[str | None, str, str]:
    """One provider, rotating the company's keys; a failing key cools down for a minute."""
    rows = _keys(t, provider, usable_only=True)
    if not rows:
        raise RuntimeError(f"no usable {provider} key")
    last = None
    for row in rows:
        use_model = model or row["model"] or PROVIDERS.get(provider, {}).get("suggested", "")
        try:
            text, usage, limits = _call(provider, use_model, db.decrypt(row["api_key"]), system, messages)
        except KeyFailed as e:
            last = e
            log.warning("company %s key %s (%s) failed: %s", t.company_id, row["id"], provider, e)
            db.execute("UPDATE ai_keys SET rate_limited_until = now() + interval '60 seconds' WHERE id = %s", (row["id"],))
            record_usage(t, row["id"], provider, use_model, purpose, contact_id, limits=e.limits, error=str(e))
            continue
        record_usage(t, row["id"], provider, use_model, purpose, contact_id, usage, limits)
        return text, provider, use_model
    raise RuntimeError(f"all {provider} keys failed: {last}")


def generate_with_fallback(t: Tenant, system: str, messages: list[dict], purpose: str = "customer",
                           contact_id: int | None = None) -> tuple[str | None, str, str]:
    active = t.bot.get("ai_provider") or "groq"
    model = t.bot.get("ai_model")
    try:
        return generate(t, system, messages, active, model, purpose, contact_id)
    except RuntimeError as first:
        if "429" in str(first):
            time.sleep(8)
            db.execute("UPDATE ai_keys SET rate_limited_until = NULL WHERE company_id = %s AND provider = %s",
                       (t.company_id, active))
            try:
                return generate(t, system, messages, active, model, purpose, contact_id)
            except RuntimeError:
                pass
        others = [r["provider"] for r in db.all_rows(
            "SELECT DISTINCT provider FROM ai_keys WHERE company_id = %s AND provider != %s", (t.company_id, active))]
        for p in others:
            try:
                return generate(t, system, messages, p, None, purpose, contact_id)
            except RuntimeError:
                continue
        raise first


def draft_reply(t: Tenant, history: list[dict], context: dict | None,
                contact_id: int | None = None) -> tuple[str | None, str, str]:
    ctx = json.dumps(context, ensure_ascii=False) if context else "কাস্টমার চেনা যায়নি (WhatsApp নম্বর বিলিংয়ে মেলেনি)।"
    messages = list(history)
    messages[-1] = {"role": "user",
                    "content": f"<live_data>\n{ctx}\n</live_data>\n\nকাস্টমারের মেসেজ:\n{history[-1]['content']}"}
    # the FAQ entries that match what the customer is asking now (and just before)
    asked = "\n".join(m["content"] for m in history[-3:] if m["role"] == "user")
    text, provider, model = generate_with_fallback(t, system_prompt(t, asked), messages, "customer", contact_id)
    return _whatsapp_format(text), provider, model


GEMINI_VOICE_MODEL = "gemini-2.5-flash"
GEMINI_VOICE_PROMPT = (
    "এটা বাংলাদেশের একটা ইন্টারনেট সার্ভিস প্রোভাইডারের কাস্টমারের WhatsApp ভয়েস মেসেজ। কাস্টমার যা বলেছেন হুবহু "
    "বাংলা হরফে লেখো, আঞ্চলিক টান থাকলেও অর্থ ঠিক রেখে। router, net, slow, ID, line-এর মতো ইংরেজি শব্দ যেভাবে বলা "
    "হয়েছে সেভাবে রাখো। মুখে বলা সংখ্যা (কাস্টমার ID, মোবাইল নম্বর, টাকা) অঙ্কে লেখো, যেমন \"ডাবল জিরো ফাইভ টু\" → 0052। "
    "শুধু কাস্টমারের কথাটা লেখো, কোনো ব্যাখ্যা বা মন্তব্য না। কিছুই বোঝা না গেলে খালি উত্তর দাও।"
)


def _transcribe_gemini(t: Tenant, audio: bytes, mime: str, contact_id: int | None) -> str | None:
    """Gemini listens to the audio itself: regional Bangla and spoken numbers come out far better than with Whisper
    (2026-10-09 test: 15/15 understandable vs 7/15). None when the company has no Gemini key or every key fails."""
    for row in _keys(t, "gemini", usable_only=True):
        payload = {"contents": [{"parts": [
                       {"text": GEMINI_VOICE_PROMPT},
                       {"inline_data": {"mime_type": mime.split(";")[0] or "audio/ogg",
                                        "data": base64.b64encode(audio).decode()}}]}],
                   "generationConfig": {"temperature": 0}}
        r = None
        for attempt in range(2):  # free tier: a 429/503 usually passes on a second try a few seconds later
            try:
                r = httpx.post(
                    f"https://generativelanguage.googleapis.com/v1beta/models/{GEMINI_VOICE_MODEL}:generateContent",
                    headers={"x-goog-api-key": db.decrypt(row["api_key"])}, json=payload, timeout=45)
            except httpx.TransportError as e:
                record_usage(t, row["id"], "gemini", GEMINI_VOICE_MODEL, "voice", contact_id, error=f"{type(e).__name__}: {e}")
                r = None
                break
            if r.status_code in (429, 503) and attempt == 0:
                record_usage(t, row["id"], "gemini", GEMINI_VOICE_MODEL, "voice", contact_id,
                             error=f"HTTP {r.status_code} (retrying): {r.text[:200]}")
                time.sleep(5)
                continue
            break
        if r is None:
            continue
        if r.status_code != 200:
            record_usage(t, row["id"], "gemini", GEMINI_VOICE_MODEL, "voice", contact_id,
                         error=f"HTTP {r.status_code}: {r.text[:300]}")
            if r.status_code in (429, 503):  # still limited / overloaded: give the key a minute
                db.execute("UPDATE ai_keys SET rate_limited_until = now() + interval '60 seconds' WHERE id = %s", (row["id"],))
            continue
        body = r.json()
        u = body.get("usageMetadata") or {}
        record_usage(t, row["id"], "gemini", GEMINI_VOICE_MODEL, "voice", contact_id,
                     {"input": u.get("promptTokenCount") or 0, "output": u.get("candidatesTokenCount") or 0})
        parts = ((body.get("candidates") or [{}])[0].get("content") or {}).get("parts") or []
        text = "".join(p.get("text", "") for p in parts).strip()
        if text:
            return text
    return None


def _transcribe_groq(t: Tenant, audio: bytes, mime: str, contact_id: int | None) -> str | None:
    ext = "ogg" if "ogg" in mime else ("mp4" if "mp4" in mime else ("mpeg" if "mpeg" in mime else "ogg"))
    for row in _keys(t, "groq", usable_only=True):
        try:
            r = httpx.post(
                "https://api.groq.com/openai/v1/audio/transcriptions",
                headers={"Authorization": f"Bearer {db.decrypt(row['api_key'])}"},
                files={"file": (f"voice.{ext}", audio, mime.split(";")[0])},
                data={"model": "whisper-large-v3", "language": "bn", "response_format": "verbose_json"},
                timeout=60,
            )
        except httpx.TransportError as e:
            record_usage(t, row["id"], "groq", "whisper-large-v3", "voice", contact_id, error=f"{type(e).__name__}: {e}")
            continue
        if r.status_code != 200:
            record_usage(t, row["id"], "groq", "whisper-large-v3", "voice", contact_id, limits=_limits(r.headers),
                         error=f"HTTP {r.status_code}: {r.text[:300]}")
            continue
        body = r.json()
        # Whisper is billed per audio second, not tokens: keep the seconds in input_tokens for this model
        record_usage(t, row["id"], "groq", "whisper-large-v3", "voice", contact_id,
                     {"input": round(float(body.get("duration") or 0))}, _limits(r.headers))
        text = (body.get("text") or "").strip()
        if text:
            return text
    return None


# voice provider id -> transcriber; the company picks the first one in বট সেটিংস (voice_provider)
VOICE_PROVIDERS = {"gemini": _transcribe_gemini, "groq": _transcribe_groq}


def transcribe(t: Tenant, audio: bytes, mime: str, contact_id: int | None = None) -> str | None:
    """The company's voice AI first, every one of its keys in turn, then the other voice AIs the company has keys for."""
    first = t.bot.get("voice_provider") or "gemini"
    for provider in [first] + [p for p in VOICE_PROVIDERS if p != first]:
        fn = VOICE_PROVIDERS.get(provider)
        if not fn:
            continue
        try:
            text = fn(t, audio, mime, contact_id)
        except Exception:
            log.exception("voice transcription with %s failed", provider)
            text = None
        if text:
            return text
    if not any(_keys(t, p) for p in VOICE_PROVIDERS):
        raise RuntimeError("no Gemini or Groq key for voice transcription")
    return None


GEMINI_TTS_MODEL = "gemini-2.5-flash-preview-tts"


def speak(t: Tenant, text: str, contact_id: int | None = None) -> bytes | None:
    """The reply read aloud by Gemini TTS (voice from বট সেটিংস, default Kore), as OGG/Opus for a WhatsApp voice
    message. None when there is no usable Gemini key, every key fails, or ffmpeg is missing: the text reply is enough."""
    words = re.sub(r"[*_~`]", "", text).strip()
    if not words:
        return None
    voice = t.bot.get("voice_reply_voice") or "Kore"
    for row in _keys(t, "gemini", usable_only=True):
        try:
            r = httpx.post(
                f"https://generativelanguage.googleapis.com/v1beta/models/{GEMINI_TTS_MODEL}:generateContent",
                headers={"x-goog-api-key": db.decrypt(row["api_key"])},
                json={"contents": [{"parts": [{"text": f"বাংলাদেশি উচ্চারণে, স্বাভাবিক কথার মতো বলো: {words}"}]}],
                      "generationConfig": {"responseModalities": ["AUDIO"], "speechConfig": {
                          "voiceConfig": {"prebuiltVoiceConfig": {"voiceName": voice}}}}},
                timeout=60,
            )
        except httpx.TransportError as e:
            record_usage(t, row["id"], "gemini", GEMINI_TTS_MODEL, "voice_reply", contact_id, error=f"{type(e).__name__}: {e}")
            continue
        if r.status_code != 200:
            record_usage(t, row["id"], "gemini", GEMINI_TTS_MODEL, "voice_reply", contact_id,
                         error=f"HTTP {r.status_code}: {r.text[:300]}")
            if r.status_code in (429, 503):
                db.execute("UPDATE ai_keys SET rate_limited_until = now() + interval '60 seconds' WHERE id = %s", (row["id"],))
            continue
        body = r.json()
        u = body.get("usageMetadata") or {}
        record_usage(t, row["id"], "gemini", GEMINI_TTS_MODEL, "voice_reply", contact_id,
                     {"input": u.get("promptTokenCount") or 0, "output": u.get("candidatesTokenCount") or 0})
        parts = ((body.get("candidates") or [{}])[0].get("content") or {}).get("parts") or []
        pcm = b"".join(base64.b64decode(p["inlineData"]["data"]) for p in parts if p.get("inlineData"))
        if not pcm:
            continue
        # Gemini TTS returns raw 16-bit PCM, 24 kHz mono
        out = subprocess.run(["ffmpeg", "-loglevel", "error", "-f", "s16le", "-ar", "24000", "-ac", "1", "-i", "pipe:0",
                              "-c:a", "libopus", "-b:a", "32k", "-f", "ogg", "pipe:1"],
                             input=pcm, capture_output=True, timeout=60)
        if out.returncode != 0 or not out.stdout:
            log.error("ffmpeg could not encode the voice reply: %s", out.stderr[:300])
            return None
        return out.stdout
    return None
