"""AI replies per company: the company's own keys, provider, model and instructions."""
import json
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


def knowledge(t: Tenant) -> str:
    """The company's own instructions and FAQ, as one block for the customer bot and the technician desk."""
    parts = []
    extra = (t.bot.get("extra_prompt") or "").strip()
    if extra:
        parts.append("=== কোম্পানির নির্দেশনা (অ্যাডমিন লিখেছেন; এখানকার তথ্য সঠিক ও চূড়ান্ত, হুবহু মেনে চলবে) ===\n"
                     f"{extra}\n=== নির্দেশনা শেষ ===")
    rows = faqs(t)
    if rows:
        parts.append("=== কোম্পানির FAQ (অ্যাডমিন লিখেছেন; প্রশ্নটা হুবহু না মিললেও একই বিষয় হলে এই উত্তর অনুযায়ী বলবে, "
                     "উত্তরের তথ্য বদলাবে না, নিজের ভাষায় ছোট করে বলবে; এখানে থাকা লিংক বা IP কাস্টমারকে হুবহু দেওয়া যাবে) ===\n"
                     + "\n".join(f"প্রশ্ন: {r['question']}\nউত্তর: {r['answer']}" for r in rows)
                     + "\n=== FAQ শেষ ===")
    return "\n\n".join(parts)


def system_prompt(t: Tenant) -> str:
    prompt = SYSTEM_PROMPT.replace("Century Link Network (একটি ISP)", f"{t.name} (একটি ISP)")
    block = knowledge(t)
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
    text, provider, model = generate_with_fallback(t, system_prompt(t), messages, "customer", contact_id)
    return _whatsapp_format(text), provider, model


def transcribe(t: Tenant, audio: bytes, mime: str, contact_id: int | None = None) -> str | None:
    ext = "ogg" if "ogg" in mime else ("mp4" if "mp4" in mime else ("mpeg" if "mpeg" in mime else "ogg"))
    for row in _keys(t, "groq"):
        r = httpx.post(
            "https://api.groq.com/openai/v1/audio/transcriptions",
            headers={"Authorization": f"Bearer {db.decrypt(row['api_key'])}"},
            files={"file": (f"voice.{ext}", audio, mime.split(";")[0])},
            data={"model": "whisper-large-v3", "language": "bn", "response_format": "verbose_json"},
            timeout=60,
        )
        if r.status_code in (401, 403, 429):
            record_usage(t, row["id"], "groq", "whisper-large-v3", "voice", contact_id, limits=_limits(r.headers),
                         error=f"HTTP {r.status_code}: {r.text[:300]}")
            continue
        r.raise_for_status()
        body = r.json()
        # Whisper is billed per audio second, not tokens: keep the seconds in input_tokens for this purpose
        record_usage(t, row["id"], "groq", "whisper-large-v3", "voice", contact_id,
                     {"input": round(float(body.get("duration") or 0))}, _limits(r.headers))
        return (body.get("text") or "").strip() or None
    raise RuntimeError("no usable Groq key for voice transcription")
