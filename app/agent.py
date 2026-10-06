"""Draft a Bangla support reply from live billing/network data.

Provider, model and API keys come from the admin dashboard (ai_keys + settings).
Several keys per provider rotate: a key that hits 401/403/429 cools down for a minute.
"""
import json
import logging
from datetime import datetime, timedelta, timezone

import anthropic
import httpx

from app import store

log = logging.getLogger("zyro-support")

# provider id -> display name, OpenAI-compatible base URL (None = own SDK), suggested model, note
PROVIDERS = {
    "claude": {"name": "Anthropic Claude", "base_url": None, "suggested": "claude-opus-5-5", "note": "পেইড"},
    "grok": {"name": "xAI Grok", "base_url": "https://api.x.ai/v1", "suggested": "grok-4", "note": ""},
    "gemini": {"name": "Google Gemini", "base_url": "https://generativelanguage.googleapis.com/v1beta/openai", "suggested": "gemini-2.5-flash", "note": "ফ্রি টিয়ার আছে"},
    "groq": {"name": "Groq", "base_url": "https://api.groq.com/openai/v1", "suggested": "openai/gpt-oss-120b", "note": "ফ্রি টিয়ার আছে"},
    "openai": {"name": "OpenAI", "base_url": "https://api.openai.com/v1", "suggested": "", "note": "পেইড"},
    "openrouter": {"name": "OpenRouter", "base_url": "https://openrouter.ai/api/v1", "suggested": "", "note": "কিছু ফ্রি মডেল"},
}

SYSTEM_PROMPT = """তুমি Century Link Network (একটি ISP)-এর WhatsApp সাপোর্ট টিমের একজন মানুষ সদস্য। নিজেকে কখনো AI বা বট বলবে না।

লেখার ধরন (সবচেয়ে গুরুত্বপূর্ণ):
- বাংলাদেশের সাধারণ কথ্য বাংলায় লেখো, যেমন একজন অভিজ্ঞ সাপোর্ট স্টাফ WhatsApp-এ লেখে: "জি ভাই", "একটু দেখে নিচ্ছি", "আপনার বিল ৫২০ টাকা বাকি আছে"। বইয়ের ভাষা বা অনুবাদের মতো ভাষা ("নিম্নলিখিত", "সম্পন্ন হয়েছে", "পুনরায়", "অনুগ্রহপূর্বক") লিখবে না।
- কাস্টমার শুধু যা জানতে চেয়েছে সেটারই উত্তর দাও। বিল জিজ্ঞেস করলে শুধু বিল; লাইনের অবস্থা জিজ্ঞেস না করলে PPPoE/আপটাইম/ডেটার কথা বলবে না।
- উত্তর ছোট: সাধারণত ১-৩ লাইন। ধাপ দিতে হলে সর্বোচ্চ ৩টা ছোট ধাপ।
- কোনো টেবিল, শিরোনাম বা ** দিয়ে বোল্ড না। দরকার হলে WhatsApp-এর মতো *এক তারকা* দিয়ে বোল্ড। টাকার জন্য "টাকা" বা ৳, কখনো ₹ না।
- প্রতিটা মেসেজে 🙏 বা ইমোজি দেবে না; খুব দরকার হলে কখনো একটা।
- "ধন্যবাদ"/"ঠিক আছে" জাতীয় মেসেজে এক লাইনে ছোট উত্তর।

উদাহরণ:
কাস্টমার: bill koto baki?  → উত্তর: জি ভাই, আপনার এই মাসের ৫২০ টাকা বাকি আছে। ১১ তারিখের মধ্যে দিয়ে দিলে লাইন চালু থাকবে।
কাস্টমার: net nai  → উত্তর: দেখলাম ভাই, আপনার রাউটার থেকে আমাদের সার্ভারে কানেক্ট হচ্ছে না। রাউটারটা একবার বন্ধ করে ৩০ সেকেন্ড পর চালু করে দেখেন তো, আর অনু থেকে রাউটারের তারটা ঠিকমতো লাগানো আছে কিনা।
কাস্টমার: thanks  → উত্তর: আপনাকেও ধন্যবাদ ভাই, কোনো সমস্যা হলে জানাবেন।

তোমাকে প্রতিবার দেওয়া হবে: কথোপকথন, আর বিলিং/নেটওয়ার্ক সিস্টেম থেকে আনা লাইভ ডেটা (JSON, অথবা কাস্টমার চেনা যায়নি এমন নোট)।

নিয়ম:
0. live_data-তে যে কাস্টমারের তথ্য আছে, সিস্টেম সেটা কাস্টমারের WhatsApp নম্বর বা তার লেখা আইডি/মোবাইল/ইউজারনেম দিয়ে খুঁজে পেয়েছে। তাই live_data থাকলে কখনো বলবে না যে তথ্য পাওয়া যায়নি; সেই কাস্টমারের নাম ও আইডি বলে উত্তর দাও। আগের কথোপকথনে "পাওয়া যায়নি" বলা থাকলেও এখনকার live_data-ই সঠিক।
1. কাস্টমার চেনা না গেলে: কাস্টমার আইডি, ইউজার আইডি বা কানেকশনের মোবাইল নম্বর চাও।
2. সমস্যা খোঁজার ক্রম: বিল/Disabled → ONU (status, optical power, deregister reason) → PPPoE (connectivity, last logout, uptime, data)।
   - disabled=true বা বিলের শেষ তারিখ পার + বকেয়া: লাইন বিলের কারণে বন্ধ, মোট বকেয়া বলো; পরিশোধ করলে চালু হবে।
   - ONU offline + "Power Off"/"dying-gasp": অনুর পাওয়ার/চার্জার চেক করতে বলো।
   - ONU online, PPPoE Disconnected: রাউটার রিস্টার্ট, ONU→রাউটার LAN ক্যাবল চেক, রাউটারের বাতি দেখতে বলো।
   - PPPoE Connected ও ডেটা চলছে: আমাদের দিক ঠিক; রাউটার রিস্টার্ট, Wi-Fi আবার কানেক্ট, অন্য ডিভাইসে নেট আছে কি না জানতে চাও।
   - ধাপগুলো করার পরও না হলে, অথবা কাস্টমার টেকনিশিয়ান/টিকেট চাইলে: উত্তরের একদম শেষে আলাদা লাইনে লেখো [[TICKET: সমস্যার এক লাইনের বিবরণ]]। এই লাইন কাস্টমার দেখবে না; সিস্টেম এটা দেখে আমাদের টিমকে জানাবে। কাস্টমারকে বলো "আপনার সমস্যাটা আমাদের টেকনিশিয়ান টিমকে জানানো হয়েছে, তারা যোগাযোগ করবেন।" কখনো টিকেট নম্বর বানিয়ে বলবে না, আর এই লাইন ছাড়া "টিকেট তৈরি হয়েছে" বলবে না।
   - কোনো কাজ (টিকেট, লাইন চালু, বিল নেওয়া) তুমি নিজে করেছ বলে দাবি করবে না, যদি না উপরের নিয়মে বলা থাকে।
3. বিল নিয়ে প্রশ্ন: বকেয়া, মাসের কত তারিখে শেষ দিন (bill_day), শেষ পেমেন্ট বলো।
4. কখনো পাসওয়ার্ড বা ভেতরের IP দেবে না।
5. **বানিয়ে বলা সম্পূর্ণ নিষেধ।** live_data বা অ্যাডমিনের অতিরিক্ত নির্দেশনায় যা নেই — পেমেন্টের নম্বর/পদ্ধতি, পুরনো মাসের বিলের অবস্থা, ওয়েব পোর্টাল, পাসওয়ার্ড রিসেটের নিয়ম, অফিসের সময় — তা কখনো বানাবে না। না জানলে বলো "এটা আমি টিমের কাছ থেকে জেনে আপনাকে জানাচ্ছি" এবং শেষে [[TICKET: কাস্টমার জানতে চায়: ...]] লেখো।
6. পেমেন্টের ইতিহাস জানতে চাইলে শুধু live_data-র payments তালিকা থেকে বলো (তারিখ, মাস, টাকা)। তালিকা খালি হলে বলো রেকর্ড পাওয়া যায়নি।
7. শুধু কাস্টমারকে পাঠানোর মতো লেখা দাও, কোনো ব্যাখ্যা বা নোট না।"""


class KeyFailed(Exception):
    """This key can't be used right now (bad key or rate limited) - try the next one."""


def active_config() -> tuple[str, str]:
    provider = store.get_setting("ai_provider", "claude")
    model = store.get_setting("ai_model") or PROVIDERS.get(provider, {}).get("suggested", "")
    return provider, model


def _system_prompt() -> str:
    extra = store.get_setting("ai_extra_prompt")
    return SYSTEM_PROMPT + (f"\n\nঅতিরিক্ত নির্দেশনা (অ্যাডমিন):\n{extra}" if extra else "")


def _call(provider: str, model: str, api_key: str, system: str, messages: list[dict], max_tokens: int = 2000) -> str | None:
    if provider == "claude":
        client = anthropic.Anthropic(api_key=api_key, max_retries=1)
        try:
            response = client.beta.messages.create(
                model=model,
                max_tokens=max_tokens,
                system=system,
                messages=messages,
                output_config={"effort": "low"},
                betas=["server-side-fallback-2026-07-01"],
                fallbacks="default",
            )
        except (anthropic.AuthenticationError, anthropic.PermissionDeniedError, anthropic.RateLimitError) as e:
            raise KeyFailed(str(e)) from e
        if response.stop_reason == "refusal":
            return None
        return "".join(b.text for b in response.content if b.type == "text").strip() or None

    base_url = PROVIDERS[provider]["base_url"]
    r = httpx.post(
        f"{base_url}/chat/completions",
        headers={"Authorization": f"Bearer {api_key}"},
        json={"model": model, "messages": [{"role": "system", "content": system}] + messages, "max_tokens": max_tokens},
        timeout=60,
    )
    if r.status_code in (401, 403, 429):
        raise KeyFailed(f"HTTP {r.status_code}: {r.text[:200]}")
    r.raise_for_status()
    return (r.json()["choices"][0]["message"].get("content") or "").strip() or None


def generate(system: str, messages: list[dict], provider: str | None = None, model: str | None = None,
             key_id: int | None = None) -> tuple[str | None, str, str]:
    """Run against the active provider, rotating its keys. Returns (text, provider, model)."""
    if provider is None:
        provider, model = active_config()
    now = datetime.now(timezone.utc)
    with store.connect() as conn:
        if key_id is not None:
            rows = conn.execute("SELECT * FROM ai_keys WHERE id = ?", (key_id,)).fetchall()
        else:
            rows = conn.execute("SELECT * FROM ai_keys WHERE provider = ? ORDER BY id", (provider,)).fetchall()
    usable = [r for r in rows if key_id is not None or not r["rate_limited_until"]
              or datetime.fromisoformat(r["rate_limited_until"]) <= now]
    if not usable:
        raise RuntimeError(f"no usable API key for provider '{provider}'")
    last_error = None
    for row in usable:
        use_model = model or row["model"] or PROVIDERS[provider]["suggested"]
        try:
            return _call(provider, use_model, store.decrypt(row["api_key_enc"]), system, messages), provider, use_model
        except KeyFailed as e:
            last_error = e
            log.warning("AI key %s (%s) failed, cooling down: %s", row["id"], provider, e)
            with store.connect() as conn:
                conn.execute("UPDATE ai_keys SET rate_limited_until = ? WHERE id = ?",
                             ((now + timedelta(seconds=60)).isoformat(), row["id"]))
    raise RuntimeError(f"all keys failed for '{provider}': {last_error}")


def draft_reply(history: list[dict], context: dict | None) -> tuple[str | None, str, str]:
    """history ends with the customer's message. Returns (draft, provider, model)."""
    ctx = json.dumps(context, ensure_ascii=False) if context else "কাস্টমার চেনা যায়নি (WhatsApp নম্বর বিলিংয়ে মেলেনি)।"
    messages = list(history)
    messages[-1] = {
        "role": "user",
        "content": f"<live_data>\n{ctx}\n</live_data>\n\nকাস্টমারের মেসেজ:\n{history[-1]['content']}",
    }
    text, provider, model = generate_with_fallback(_system_prompt(), messages)
    return _whatsapp_format(text), provider, model


def generate_with_fallback(system: str, messages: list[dict]) -> tuple[str | None, str, str]:
    """Active provider first; if all its keys are rate-limited/failing, wait briefly once,
    then fall back to any other provider that has a key."""
    import time
    active, model = active_config()
    try:
        return generate(system, messages)
    except RuntimeError as first:
        if "429" in str(first):
            time.sleep(8)
            with store.connect() as conn:
                conn.execute("UPDATE ai_keys SET rate_limited_until = NULL WHERE provider = ?", (active,))
            try:
                return generate(system, messages)
            except RuntimeError:
                pass
        with store.connect() as conn:
            others = [r["provider"] for r in conn.execute(
                "SELECT DISTINCT provider FROM ai_keys WHERE provider != ?", (active,))]
        for p in others:
            try:
                log.warning("falling back from %s to %s", active, p)
                return generate(system, messages, provider=p, model=None)
            except RuntimeError:
                continue
        raise first


def _whatsapp_format(text: str | None) -> str | None:
    """Markdown the models still slip in -> WhatsApp style."""
    if not text:
        return text
    import re
    text = re.sub(r"\*\*(.+?)\*\*", r"*\1*", text)
    text = re.sub(r"^#+\s*", "", text, flags=re.M)
    text = text.replace("₹", "৳")
    return text.strip()


def list_models(provider: str, api_key: str) -> list[str]:
    """Live model list from the provider, so the admin picks a real model name."""
    if provider == "claude":
        return [m.id for m in anthropic.Anthropic(api_key=api_key).models.list()]
    r = httpx.get(f"{PROVIDERS[provider]['base_url']}/models",
                  headers={"Authorization": f"Bearer {api_key}"}, timeout=30)
    r.raise_for_status()
    return sorted(m["id"].removeprefix("models/") for m in r.json().get("data", []))
