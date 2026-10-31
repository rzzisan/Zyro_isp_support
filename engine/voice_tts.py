"""Voice reminder messages read by Gemini TTS (panel → ভয়েস রিমাইন্ডার → টেমপ্লেট; later the reminder calls).

The panel fills the placeholders and sends the spoken text; this makes the audio once and keeps it by a hash of
(voice, speed, text) in AUDIO_DIR/{company}/:
  {hash}.wav  8 kHz 16-bit mono, what Asterisk plays on the call
  {hash}.mp3  for the browser preview
The same text is never made twice, so a fixed message (menu, goodbye) costs once and a customer's reminder once a month.

  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.voice_tts --company 1 --text "পরীক্ষা"
"""
import argparse
import base64
import hashlib
import logging
import os
import re
import subprocess
import threading
from concurrent.futures import ThreadPoolExecutor
from types import SimpleNamespace

import httpx

from engine import agent, db

log = logging.getLogger("zyro-voice-tts")

AUDIO_DIR = os.environ.get("VOICE_AUDIO_DIR", "/var/www/zyro_isp_support/panel/storage/app/voice-audio")
# 3.x first (fast, no style note: it reads the note aloud); 2.5 as the fallback with the style note
MODELS = ["gemini-3.8-flash-lite-tts", agent.GEMINI_TTS_MODEL]
VOICES = {"Kore", "Aoede", "Leda", "Zephyr", "Charon", "Puck", "Fenrir", "Orus"}
MAX_CHARS = 1500

_locks: dict[str, threading.Lock] = {}
_locks_lock = threading.Lock()
# Gemini TTS allows only a few requests a minute per key: asking for many pieces at once got ~13 refusals (429) per
# clip made, so at most this many are asked at a time, whatever the panel sends in parallel
_gemini_slots = threading.BoundedSemaphore(int(os.environ.get("VOICE_TTS_PARALLEL", "2")))


_ONES = ("শূন্য এক দুই তিন চার পাঁচ ছয় সাত আট নয় দশ এগারো বারো তেরো চৌদ্দ পনেরো ষোলো সতেরো আঠারো উনিশ "
         "বিশ একুশ বাইশ তেইশ চব্বিশ পঁচিশ ছাব্বিশ সাতাশ আটাশ ঊনত্রিশ ত্রিশ একত্রিশ বত্রিশ তেত্রিশ চৌত্রিশ পঁয়ত্রিশ "
         "ছত্রিশ সাঁইত্রিশ আটত্রিশ ঊনচল্লিশ চল্লিশ একচল্লিশ বিয়াল্লিশ তেতাল্লিশ চুয়াল্লিশ পঁয়তাল্লিশ ছেচল্লিশ সাতচল্লিশ "
         "আটচল্লিশ ঊনপঞ্চাশ পঞ্চাশ একান্ন বাহান্ন তিপ্পান্ন চুয়ান্ন পঞ্চান্ন ছাপ্পান্ন সাতান্ন আটান্ন ঊনষাট ষাট একষট্টি "
         "বাষট্টি তেষট্টি চৌষট্টি পঁয়ষট্টি ছেষট্টি সাতষট্টি আটষট্টি ঊনসত্তর সত্তর একাত্তর বাহাত্তর তিয়াত্তর চুয়াত্তর "
         "পঁচাত্তর ছিয়াত্তর সাতাত্তর আটাত্তর ঊনআশি আশি একাশি বিরাশি তিরাশি চুরাশি পঁচাশি ছিয়াশি সাতাশি অষ্টাশি "
         "ঊননব্বই নব্বই একানব্বই বিরানব্বই তিরানব্বই চুরানব্বই পঁচানব্বই ছিয়ানব্বই সাতানব্বই আটানব্বই নিরানব্বই").split()
_BN_DIGITS = str.maketrans("০১২৩৪৫৬৭৮৯", "0123456789")
_NUMBER = re.compile(r"\d+(?:,\d{2,3})*(?:\.\d+)?")


def words(n: int) -> str:
    """520 → "পাঁচশো বিশ" (Bangla counting: কোটি, লাখ, হাজার, শো)."""
    if n < 100:
        return _ONES[n]
    out = []
    for size, name in ((10_000_000, "কোটি"), (100_000, "লাখ"), (1000, "হাজার")):
        if n >= size:
            out.append(f"{words(n // size)} {name}")
            n %= size
    if n >= 100:
        out.append(_ONES[n // 100] + "শো")
        n %= 100
    if n:
        out.append(_ONES[n])
    return " ".join(out)


def spoken(text: str) -> str:
    """Numbers written out in Bangla words, so the voice can't misread them ("২০ টাকা" was once read "2 dollars").
    A number starting with 0 or longer than 7 digits (a phone number) is read digit by digit."""
    def one(m: re.Match) -> str:
        raw = m.group(0).translate(_BN_DIGITS).replace(",", "")
        whole, _, frac = raw.partition(".")
        if (whole.startswith("0") and len(whole) > 1) or len(whole) > 7:
            return " ".join(_ONES[int(d)] for d in whole)
        out = words(int(whole))
        frac = frac.rstrip("0")
        return f"{out} দশমিক {' '.join(_ONES[int(d)] for d in frac)}" if frac else out

    return _NUMBER.sub(one, text.translate(_BN_DIGITS)) if re.search(r"[0-9০-৯]", text) else text


def key(voice: str, speed: float, text: str) -> str:
    return hashlib.sha1(f"{voice}|{speed:.2f}|{text}".encode()).hexdigest()[:20]


def chosen_model(company_id: int) -> str | None:
    """The voice model picked in রিমাইন্ডার সেটিংস → অডিও বানানো (None: 'auto', the newest first)."""
    try:
        row = db.one("SELECT tts_model FROM voice_reminder_settings WHERE company_id = %s", (company_id,))
    except Exception:   # before the migration
        return None
    model = (row or {}).get("tts_model") or "auto"
    return None if model == "auto" else model


def _gemini(company_id: int, text: str, voice: str, model: str | None = None) -> bytes:
    """Raw 16-bit PCM at 24 kHz from the company's Gemini keys (the one picked for voice_reminder first); the picked
    model first, the others as the fallback."""
    t = SimpleNamespace(company_id=company_id)
    last = "কোম্পানির কোনো Gemini key নেই"
    models = [model] + [m for m in MODELS if m != model] if model else MODELS
    for row in agent._keys(t, "gemini", usable_only=True, job="voice_reminder"):
        for model in models:
            words = text if model.startswith("gemini-3") else agent.TTS_STYLE + text
            try:
                r = httpx.post(f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent",
                               headers={"x-goog-api-key": db.decrypt(row["api_key"])}, timeout=60,
                               json={"contents": [{"parts": [{"text": words}]}],
                                     "generationConfig": {"responseModalities": ["AUDIO"], "speechConfig": {
                                         "voiceConfig": {"prebuiltVoiceConfig": {"voiceName": voice}}}}})
            except httpx.TransportError as e:
                agent.record_usage(t, row["id"], "gemini", model, "voice_reminder_tts", None, error=f"{type(e).__name__}: {e}")
                last = str(e)
                continue
            if r.status_code == 404:
                continue
            if r.status_code != 200:
                agent.record_usage(t, row["id"], "gemini", model, "voice_reminder_tts", None,
                                   error=f"HTTP {r.status_code}: {r.text[:200]}")
                last = f"Gemini HTTP {r.status_code}"
                continue
            data = r.json()
            u = data.get("usageMetadata") or {}
            agent.record_usage(t, row["id"], "gemini", model, "voice_reminder_tts", None,
                               {"input": u.get("promptTokenCount") or 0, "output": u.get("candidatesTokenCount") or 0})
            parts = ((data.get("candidates") or [{}])[0].get("content") or {}).get("parts") or []
            pcm = b"".join(base64.b64decode(p["inlineData"]["data"]) for p in parts if (p.get("inlineData") or {}).get("data"))
            if pcm:
                return pcm
            last = "Gemini কোনো অডিও দেয়নি"
    raise RuntimeError(last)


def _ffmpeg(pcm: bytes, speed: float, out: str, args: list[str]) -> None:
    tempo = [] if abs(speed - 1) < 0.01 else ["-af", f"atempo={speed:.2f}"]
    r = subprocess.run(["ffmpeg", "-loglevel", "error", "-y", "-f", "s16le", "-ar", "24000", "-ac", "1", "-i", "pipe:0",
                        *tempo, *args, out], input=pcm, capture_output=True, timeout=60)
    if r.returncode != 0:
        raise RuntimeError(f"ffmpeg: {r.stderr[:200]!r}")


def make(company_id: int, text: str, voice: str = "Kore", speed: float = 1.0) -> dict:
    """The audio for one spoken text: {"file": "{company}/{hash}", "cached": bool}; both .wav and .mp3 exist after."""
    text = re.sub(r"\s+", " ", text or "").strip()
    if not text:
        raise ValueError("লেখা খালি")
    if len(text) > MAX_CHARS:
        raise ValueError(f"লেখা {MAX_CHARS} অক্ষরের বেশি")
    voice = voice if voice in VOICES else "Kore"
    speed = min(1.5, max(0.7, float(speed or 1)))
    text = spoken(text)   # before the hash: audio made from digits before this is never used again
    model = chosen_model(company_id)
    h = key(voice, speed, text + (f"|{model}" if model else ""))   # a picked model makes its own audio
    folder = os.path.join(AUDIO_DIR, str(int(company_id)))
    base = os.path.join(folder, h)
    with _locks_lock:
        lock = _locks.setdefault(base, threading.Lock())
    with lock:   # the same text asked twice at once (preview double-click) is made once
        if os.path.exists(base + ".wav") and os.path.exists(base + ".mp3"):
            return {"file": f"{int(company_id)}/{h}", "cached": True}
        os.makedirs(folder, exist_ok=True)
        with _gemini_slots:
            pcm = _gemini(company_id, text, voice, model)
        _ffmpeg(pcm, speed, base + ".mp3.tmp", ["-c:a", "libmp3lame", "-b:a", "48k", "-f", "mp3"])
        _ffmpeg(pcm, speed, base + ".wav.tmp", ["-ar", "8000", "-ac", "1", "-c:a", "pcm_s16le", "-f", "wav"])
        os.replace(base + ".mp3.tmp", base + ".mp3")
        os.replace(base + ".wav.tmp", base + ".wav")
    log.info("voice audio made: company %s %s (%d chars)", company_id, h, len(text))
    return {"file": f"{int(company_id)}/{h}", "cached": False}


GAP = 0.12        # seconds between two joined pieces inside a sentence
GAP_STOP = 0.35   # after a piece that ends a phrase (। , ? !)
_TRIM = "silenceremove=start_periods=1:start_threshold=-45dB:start_silence=0.03"


def make_parts(company_id: int, parts: list[str], voice: str = "Kore", speed: float = 1.0) -> dict:
    """A message joined from pieces (the template's words, each customer value apart; the panel's
    VoiceTemplates::parts): every piece is made once (make(), shared by every message that has it) and the pieces are joined with
    their own silence trimmed and a short pause between, so it runs on like one sentence. Same answer as make()."""
    parts = [p for p in (re.sub(r"\s+", " ", x or "").strip() for x in parts) if p]
    if len(parts) < 2:
        return make(company_id, " ".join(parts), voice, speed)
    with ThreadPoolExecutor(max_workers=4) as pool:   # the new pieces at once
        made = list(pool.map(lambda p: make(company_id, p, voice, speed), parts))
    h = key(voice, speed, "parts:" + "|".join(m["file"] for m in made))
    folder = os.path.join(AUDIO_DIR, str(int(company_id)))
    base = os.path.join(folder, h)
    if os.path.exists(base + ".wav") and os.path.exists(base + ".mp3"):
        return {"file": f"{int(company_id)}/{h}", "cached": all(m["cached"] for m in made)}
    inputs, chains = [], []
    for i, (p, m) in enumerate(zip(parts, made)):
        inputs += ["-i", os.path.join(AUDIO_DIR, m["file"] + ".mp3")]
        gap = 0 if i == len(parts) - 1 else (GAP_STOP if re.search(r"[।,?!.;:]$", p) else GAP)
        chains.append(f"[{i}:a]aresample=24000,aformat=channel_layouts=mono,{_TRIM},areverse,{_TRIM},areverse,"
                      f"apad=pad_dur={gap:.2f}[a{i}]")
    graph = ";".join(chains) + ";" + "".join(f"[a{i}]" for i in range(len(parts))) + f"concat=n={len(parts)}:v=0:a=1[out]"
    for ext, args in ((".mp3", ["-c:a", "libmp3lame", "-b:a", "48k", "-f", "mp3"]),
                      (".wav", ["-ar", "8000", "-ac", "1", "-c:a", "pcm_s16le", "-f", "wav"])):
        r = subprocess.run(["ffmpeg", "-loglevel", "error", "-y", *inputs, "-filter_complex", graph, "-map", "[out]", *args,
                            base + ext + ".tmp"], capture_output=True, timeout=60)
        if r.returncode != 0:
            raise RuntimeError(f"ffmpeg: {r.stderr[:200]!r}")
    os.replace(base + ".mp3.tmp", base + ".mp3")
    os.replace(base + ".wav.tmp", base + ".wav")
    log.info("voice audio joined: company %s %s (%d pieces)", company_id, h, len(parts))
    return {"file": f"{int(company_id)}/{h}", "cached": False}


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    p = argparse.ArgumentParser()
    p.add_argument("--company", type=int, required=True)
    p.add_argument("--text", required=True)
    p.add_argument("--voice", default="Kore")
    p.add_argument("--speed", type=float, default=1.0)
    a = p.parse_args()
    print(make(a.company, a.text, a.voice, a.speed))


if __name__ == "__main__":
    main()
