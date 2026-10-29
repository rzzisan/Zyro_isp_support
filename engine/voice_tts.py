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


def key(voice: str, speed: float, text: str) -> str:
    return hashlib.sha1(f"{voice}|{speed:.2f}|{text}".encode()).hexdigest()[:20]


def _gemini(company_id: int, text: str, voice: str) -> bytes:
    """Raw 16-bit PCM at 24 kHz from the company's Gemini keys (the one picked for voice_reminder first)."""
    t = SimpleNamespace(company_id=company_id)
    last = "কোম্পানির কোনো Gemini key নেই"
    for row in agent._keys(t, "gemini", usable_only=True, job="voice_reminder"):
        for model in MODELS:
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
    h = key(voice, speed, text)
    folder = os.path.join(AUDIO_DIR, str(int(company_id)))
    base = os.path.join(folder, h)
    with _locks_lock:
        lock = _locks.setdefault(base, threading.Lock())
    with lock:   # the same text asked twice at once (preview double-click) is made once
        if os.path.exists(base + ".wav") and os.path.exists(base + ".mp3"):
            return {"file": f"{int(company_id)}/{h}", "cached": True}
        os.makedirs(folder, exist_ok=True)
        pcm = _gemini(company_id, text, voice)
        _ffmpeg(pcm, speed, base + ".mp3.tmp", ["-c:a", "libmp3lame", "-b:a", "48k", "-f", "mp3"])
        _ffmpeg(pcm, speed, base + ".wav.tmp", ["-ar", "8000", "-ac", "1", "-c:a", "pcm_s16le", "-f", "wav"])
        os.replace(base + ".mp3.tmp", base + ".mp3")
        os.replace(base + ".wav.tmp", base + ".wav")
    log.info("voice audio made: company %s %s (%d chars)", company_id, h, len(text))
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
