"""AI replies per company: the company's own keys, provider, model and instructions."""
import json
import logging
import time

import httpx

from app.agent import PROVIDERS, SYSTEM_PROMPT, KeyFailed, _call, _whatsapp_format
from engine import db
from engine.tenant import Tenant

log = logging.getLogger("zyro-engine")


def system_prompt(t: Tenant) -> str:
    prompt = SYSTEM_PROMPT.replace("Century Link Network (একটি ISP)", f"{t.name} (একটি ISP)")
    extra = t.bot.get("extra_prompt")
    if not extra:
        return prompt
    return prompt + (
        "\n\n=== কোম্পানির নির্দেশনা (অ্যাডমিন লিখেছেন; এখানকার তথ্য সঠিক ও চূড়ান্ত, হুবহু মেনে চলবে) ===\n"
        f"{extra}\n"
        "=== নির্দেশনা শেষ ===\n"
        "এই নির্দেশনা ব্যবহারের নিয়ম: কাস্টমার যে পদ্ধতি বা বিষয় নিয়ে জিজ্ঞেস করেছে (যেমন 'paybill', 'pay bill', "
        "'merchant', 'নগদ'), নির্দেশনা থেকে ঠিক সেই অংশটা ধরে উত্তর দেবে। আগের উত্তরে অন্য পদ্ধতি বলা থাকলে সেটা আবার বলবে না। "
        "নির্দেশনায় যে ধাপ বা নাম লেখা আছে (যেমন কোন অপশনে যেতে হবে, কী লিখে সার্চ করতে হবে) সেগুলো বাদ দেবে না।"
    )


def _keys(t: Tenant, provider: str, usable_only: bool = False) -> list[dict]:
    extra = " AND (rate_limited_until IS NULL OR rate_limited_until <= now())" if usable_only else ""
    return db.all_rows(f"SELECT * FROM ai_keys WHERE company_id = %s AND provider = %s{extra} ORDER BY id",
                       (t.company_id, provider))


def generate(t: Tenant, system: str, messages: list[dict], provider: str, model: str | None) -> tuple[str | None, str, str]:
    """One provider, rotating the company's keys; a failing key cools down for a minute."""
    rows = _keys(t, provider, usable_only=True)
    if not rows:
        raise RuntimeError(f"no usable {provider} key")
    last = None
    for row in rows:
        use_model = model or row["model"] or PROVIDERS.get(provider, {}).get("suggested", "")
        try:
            return _call(provider, use_model, db.decrypt(row["api_key"]), system, messages), provider, use_model
        except KeyFailed as e:
            last = e
            log.warning("company %s key %s (%s) failed: %s", t.company_id, row["id"], provider, e)
            db.execute("UPDATE ai_keys SET rate_limited_until = now() + interval '60 seconds' WHERE id = %s", (row["id"],))
    raise RuntimeError(f"all {provider} keys failed: {last}")


def generate_with_fallback(t: Tenant, system: str, messages: list[dict]) -> tuple[str | None, str, str]:
    active = t.bot.get("ai_provider") or "groq"
    model = t.bot.get("ai_model")
    try:
        return generate(t, system, messages, active, model)
    except RuntimeError as first:
        if "429" in str(first):
            time.sleep(8)
            db.execute("UPDATE ai_keys SET rate_limited_until = NULL WHERE company_id = %s AND provider = %s",
                       (t.company_id, active))
            try:
                return generate(t, system, messages, active, model)
            except RuntimeError:
                pass
        others = [r["provider"] for r in db.all_rows(
            "SELECT DISTINCT provider FROM ai_keys WHERE company_id = %s AND provider != %s", (t.company_id, active))]
        for p in others:
            try:
                return generate(t, system, messages, p, None)
            except RuntimeError:
                continue
        raise first


def draft_reply(t: Tenant, history: list[dict], context: dict | None) -> tuple[str | None, str, str]:
    ctx = json.dumps(context, ensure_ascii=False) if context else "কাস্টমার চেনা যায়নি (WhatsApp নম্বর বিলিংয়ে মেলেনি)।"
    messages = list(history)
    messages[-1] = {"role": "user",
                    "content": f"<live_data>\n{ctx}\n</live_data>\n\nকাস্টমারের মেসেজ:\n{history[-1]['content']}"}
    text, provider, model = generate_with_fallback(t, system_prompt(t), messages)
    return _whatsapp_format(text), provider, model


def transcribe(t: Tenant, audio: bytes, mime: str) -> str | None:
    ext = "ogg" if "ogg" in mime else ("mp4" if "mp4" in mime else ("mpeg" if "mpeg" in mime else "ogg"))
    for row in _keys(t, "groq"):
        r = httpx.post(
            "https://api.groq.com/openai/v1/audio/transcriptions",
            headers={"Authorization": f"Bearer {db.decrypt(row['api_key'])}"},
            files={"file": (f"voice.{ext}", audio, mime.split(";")[0])},
            data={"model": "whisper-large-v3", "language": "bn", "response_format": "json"},
            timeout=60,
        )
        if r.status_code in (401, 403, 429):
            continue
        r.raise_for_status()
        return (r.json().get("text") or "").strip() or None
    raise RuntimeError("no usable Groq key for voice transcription")
