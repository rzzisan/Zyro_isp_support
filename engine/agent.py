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


# always added, also to a company's own edited rules: "[ছবি] ..." lines come from see_image
IMAGE_RULE = (
    "ছবি: কাস্টমারের মেসেজে \"[ছবি] ...\" মানে কাস্টমার একটা ছবি পাঠিয়েছে, আর সিস্টেম ছবিটা দেখে তাতে কী আছে লিখেছে। "
    "এটাকে কাস্টমারের নিজের কথার মতো ধরে উত্তর দাও: পেমেন্টের স্ক্রিনশট বা রসিদ হলে live_data-র payments-এর সাথে মিলিয়ে "
    "দেখো, আর ছবিতে টাকা, মাধ্যম বা ট্রানজেকশন আইডি থাকলে সেগুলো আবার জিজ্ঞেস করবে না। পেমেন্ট হয়েছে কি হয়নি, কত বাকি, "
    "সেটা শুধু live_data থেকে বলবে, ছবির সংখ্যা দেখে না (ছবি ভুল পড়া হতে পারে)। অনু বা রাউটারের বাতির ছবি হলে সমস্যা "
    "খোঁজার নিয়মে চলো; Wi-Fi বা এররের স্ক্রিনশট হলে স্ক্রিনে যা লেখা সেই লক্ষণ থেকে শুরু করো। ছবির বর্ণনা কাস্টমারকে "
    "ফিরিয়ে লিখবে না, \"ছবি দেখতে পাচ্ছি না\" বলবে না। ছবি থেকে বোঝা না গেলে এক লাইনে জিজ্ঞেস করো কী সমস্যা। ব্যক্তিগত "
    "ডকুমেন্ট (পাসপোর্ট, NID) বা লাইনের সাথে সম্পর্ক নেই এমন ছবি হলে ছবিটা নিয়ে কোনো মন্তব্য না করে শুধু জিজ্ঞেস করো কী "
    "সাহায্য লাগবে।"
)


# always added after a company's own rules too (daily chat review 2026-10-24: thinking sent to customers, promises the
# system can't keep, wrong months, missed tickets)
REPLY_RULES = (
    "সবসময়ের নিয়ম:\n"
    "- উত্তরে শুধু কাস্টমারকে পাঠানোর লেখাটা থাকবে। নিজের চিন্তা বা বিশ্লেষণ, \"উত্তর:\" লেবেল, live_data, bill_day, "
    "mikrotik, open_tickets-এর মতো ভেতরের নাম, বা \"-- Zyro\" সিগনেচার লিখবে না (সিগনেচার সিস্টেম নিজে যোগ করে)।\n"
    "- যা সিস্টেম করে না তার কথা দেবে না: টেকনিশিয়ান কবে বা কখন আসবেন বলবে না, \"টিকেটে যোগ করে রাখছি\" বলবে না "
    "(খোলা টিকেটে কিছু যোগ করার ব্যবস্থা নেই)। \"অফিসে জানাচ্ছি\" বা \"টিমকে জানাচ্ছি\" লিখলে সেই উত্তরেই [[TICKET: ...]] দেবে; "
    "খোলা টিকেট থাকলে শুধু বলবে টিম সেটা দেখছে।\n"
    "- কাস্টমার বলেন কালেক্টর বা অফিসের লোকের হাতে টাকা দিয়েছেন অথচ বকেয়া দেখাচ্ছে: তর্ক করবে না, "
    "[[TICKET: Others Support | কালেক্টরকে টাকা দিয়েছেন বলছেন: কত টাকা, কবে, কাকে]]।\n"
    "- বকেয়া কোন মাসের, সেটা live_data-তে মাস লেখা থাকলে তবেই বলবে; না থাকলে শুধু মোট বকেয়া।\n"
    "- ছবির বর্ণনায় নিশ্চিত না লেখা থাকলে ছবিটাকে সমস্যার কারণ বলবে না।\n"
    "- স্পিড বা প্যাকেজ বাড়াতে চাইলে \"বাড়ানো যায় না\" বলবে না: নির্দেশনা বা FAQ-তে থাকা প্যাকেজ (নাম, স্পিড, দাম) জানাও "
    "আর [[TICKET: Others Support | প্যাকেজ আপগ্রেড চান: ...]]।\n"
    "- IP ক্যামেরা, টিভি বক্স, অ্যাপ বা অন্য ডিভাইস সেটআপ আমাদের সার্ভিস না: এক লাইনে বলো, ডিভাইসের বিক্রেতা বা "
    "টেকনিশিয়ানের সাথে যোগাযোগ করতে; লম্বা টিপস দেবে না।\n"
    "- \"লাইনের সমস্যা\" বা \"নেট সমস্যা\"-র মতো অভিযোগ দ্বিতীয়বার এলে, বা কাস্টমার বলেন ধাপ করেও হয়নি: আর প্রশ্ন না করে টিকেট খোলো।\n"
    "- নেট আর ডিশের (টিভির) লাইন দুটোই বন্ধ বললে টিকেটের বিবরণে সেটা লিখবে (এলাকার তার কাটা হতে পারে)।\n"
    "- live_data-তে ONU-র তথ্য না থাকলে বা error থাকলে অনুর অবস্থা (অনলাইন/অফলাইন) দাবি করবে না।\n"
    "- প্যাকেজ জিজ্ঞেস করলে শুধু live_data-র customer.package-এ যে প্যাকেজের নাম আছে সেটা হুবহু বলবে। প্রোফাইল, "
    "স্পিড বা Mbps আলাদা করে বলবে না, প্যাকেজের সাথে লাইনের স্পিড মিলিয়ে দেখাবে না।\n"
    "- নাম দেখে নিশ্চিত না হলে \"ভাই\" বা \"আপু\" না, শুধু \"জি\"।"
)


# added when the company lets customers have a bill-disabled line turned on once a month (engine/self_reconnect.py)
RECONNECT_RULE = (
    "লাইন চালু করে দেওয়ার অনুরোধ: কাস্টমার বিলের কারণে বন্ধ লাইনটা এখন চালু করে দিতে বললে (যেমন \"লাইনটা ছেড়ে দেন\", "
    "\"কয়েকদিন পরে বিল দিব, লাইন চালু করে দেন\") উত্তরের একদম শেষে আলাদা লাইনে লেখো [[RECONNECT]]। লাইন চালু হলো কি "
    "হলো না, কেন হবে না, মাসে কতবার পাওয়া যায়: এসব নিজে লিখবে না, সিস্টেম আসল ফলটা নিজে যোগ করে দেবে; তাই উত্তরের "
    "বাকিটা ফাঁকাও রাখতে পারো। কাস্টমার নিজে না চাইলে [[RECONNECT]] দেবে না, আর এই সুবিধার কথা নিজে থেকে বলবে না।"
)


def system_prompt(t: Tenant, query: str | None = None) -> str:
    # the company may have edited the built-in rules (Bot settings); empty = the built-in text
    base = (t.bot.get("customer_prompt") or "").strip() or SYSTEM_PROMPT
    prompt = base.replace("Century Link Network (একটি ISP)", f"{t.name} (একটি ISP)") + "\n\n" + IMAGE_RULE + "\n\n" + REPLY_RULES
    if t.bot.get("self_reconnect"):
        prompt += "\n\n" + RECONNECT_RULE
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
    system = system_prompt(t, asked)
    text, provider, model = generate_with_fallback(t, system, messages, "customer", contact_id)
    text = without_thinking(text, t.bot.get("reply_signature"))
    if text and LEAK.search(text):
        # the model wrote its reasoning for the customer to read (2026-10-23, chats 540 and 1318): one more try
        log.warning("reply for contact %s had reasoning in it, asking again", contact_id)
        text, provider, model = generate_with_fallback(t, system + RETRY_NOTE, messages, "customer", contact_id)
        text = without_thinking(text, t.bot.get("reply_signature"))
        if text and LEAK.search(text):
            text = "\n".join(x for x in text.split("\n") if not LEAK.search(x)).strip() or None
    return _whatsapp_format(text), provider, model


# words only the model's own reasoning has, never a reply to a customer
LEAK = re.compile(r"live_data|bill_day|open_tickets|mikrotik|verified|self_reconnect|can_reconnect|why_not|নির্দেশনায় (?:কিছু )?(?:নেই|বলা)|বানিয়ে বলা যাবে না|"
                  r"কাস্টমার বলছে|কাস্টমারের নাম \S+,|দিয়ে শুরু করব", re.I)
RETRY_NOTE = ("\n\nজরুরি: আগের খসড়ায় তোমার নিজের চিন্তা আর ডেটার নাম লেখা ছিল, যা কাস্টমার দেখে ফেলত। এবার শুধু কাস্টমারকে "
              "পাঠানোর ছোট উত্তরটা লেখো, আর কিছু না।")


def without_thinking(text: str | None, signature: str | None = None) -> str | None:
    """Only the part meant for the customer: what follows the last "উত্তর:" label (the prompt's examples are written
    "কাস্টমার: ... → উত্তর: ...", and the model sometimes writes its reasoning, then "উত্তর:", then the reply), without
    the signature the model copies from the chat (deliver adds it once)."""
    if not text:
        return text
    labels = list(re.finditer(r"(?m)^[ \t*]*উত্তর[ \t*]*[:：][ \t*]*", text))
    if labels:
        text = text[labels[-1].end():]
    if signature and signature.strip():
        text = "\n".join(x for x in text.split("\n") if x.strip() != signature.strip())
    return re.sub(r"\n{3,}", "\n\n", text).strip() or None


# tried in order on each key: Google no longer serves gemini-2.5-flash to keys of new accounts (404 "no longer available
# to new users ... use models/gemini-3.8-flash"), so those keys go on to the newer model
GEMINI_VOICE_MODELS = ["gemini-2.5-flash", "gemini-3.8-flash"]
# 3.8 thinks by default and then takes 25-60 s per voice; with thinking off it took ~10 s and wrote the same transcript
GEMINI_VOICE_EXTRA = {"gemini-3.8-flash": {"thinkingConfig": {"thinkingBudget": 0}}}
GEMINI_VOICE_PROMPT = (
    "এটা বাংলাদেশের একটা ইন্টারনেট সার্ভিস প্রোভাইডারের কাস্টমারের WhatsApp ভয়েস মেসেজ। কাস্টমার যা বলেছেন হুবহু "
    "বাংলা হরফে লেখো, আঞ্চলিক টান থাকলেও অর্থ ঠিক রেখে। router, net, slow, ID, line-এর মতো ইংরেজি শব্দ যেভাবে বলা "
    "হয়েছে সেভাবে রাখো। মুখে বলা সংখ্যা (কাস্টমার ID, মোবাইল নম্বর, টাকা) অঙ্কে লেখো, যেমন \"ডাবল জিরো ফাইভ টু\" → 0052। "
    "শুধু কাস্টমারের কথাটা লেখো, কোনো ব্যাখ্যা বা মন্তব্য না। কিছুই বোঝা না গেলে খালি উত্তর দাও।"
)


def _transcribe_gemini(t: Tenant, audio: bytes, mime: str, contact_id: int | None) -> str | None:
    """Gemini listens to the audio itself: regional Bangla and spoken numbers come out far better than with Whisper
    (2026-10-09 test: 15/15 understandable vs 7/15). None when the company has no Gemini key or every key fails."""
    payload = {"contents": [{"parts": [
                   {"text": GEMINI_VOICE_PROMPT},
                   {"inline_data": {"mime_type": mime.split(";")[0] or "audio/ogg",
                                    "data": base64.b64encode(audio).decode()}}]}],
               "generationConfig": {"temperature": 0}}
    for row in _keys(t, "gemini", usable_only=True):
        for model in GEMINI_VOICE_MODELS:
            r = None
            for attempt in range(2):  # free tier: a 429/503 usually passes on a second try a few seconds later
                try:
                    r = httpx.post(
                        f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent",
                        headers={"x-goog-api-key": db.decrypt(row["api_key"])},
                        json={**payload, "generationConfig": {**payload["generationConfig"], **GEMINI_VOICE_EXTRA.get(model, {})}},
                        timeout=60)
                except httpx.TransportError as e:
                    record_usage(t, row["id"], "gemini", model, "voice", contact_id, error=f"{type(e).__name__}: {e}")
                    r = None
                    break
                if r.status_code in (429, 503) and attempt == 0:
                    record_usage(t, row["id"], "gemini", model, "voice", contact_id,
                                 error=f"HTTP {r.status_code} (retrying): {r.text[:200]}")
                    time.sleep(5)
                    continue
                break
            if r is None:
                break  # connection trouble: next key
            if r.status_code == 404:  # this key can't use this model: try the next model on the same key
                record_usage(t, row["id"], "gemini", model, "voice", contact_id, error=f"HTTP 404: {r.text[:300]}")
                continue
            if r.status_code != 200:
                record_usage(t, row["id"], "gemini", model, "voice", contact_id,
                             error=f"HTTP {r.status_code}: {r.text[:300]}")
                if r.status_code in (429, 503):  # still limited / overloaded: give the key a minute
                    db.execute("UPDATE ai_keys SET rate_limited_until = now() + interval '60 seconds' WHERE id = %s",
                               (row["id"],))
                break
            body = r.json()
            u = body.get("usageMetadata") or {}
            record_usage(t, row["id"], "gemini", model, "voice", contact_id,
                         {"input": u.get("promptTokenCount") or 0, "output": u.get("candidatesTokenCount") or 0})
            parts = ((body.get("candidates") or [{}])[0].get("content") or {}).get("parts") or []
            text = "".join(p.get("text", "") for p in parts).strip()
            if text:
                return text
            break
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


# WhatsApp sends photos at most 1600 px on the long side (HD ones bigger). 1600 keeps small text (a bKash
# transaction ID, a crumpled money receipt) and ONU lights readable: ~1500-2500 image tokens, about $0.0002 a photo
# on Claude Haiku 5.5 (2026-10-16, ~30 real customer photos looked at).
IMAGE_MAX_EDGE = 1600
IMAGE_MODEL = "claude-haiku-5-5"  # when the company's chat AI isn't Claude
IMAGE_PROMPT = (
    "তুমি বাংলাদেশের একটা ইন্টারনেট সার্ভিস প্রোভাইডারের (ISP) সাপোর্ট টিমকে সাহায্য করছ। কাস্টমার WhatsApp-এ একটা ছবি "
    "পাঠিয়েছে। ছবিতে কী আছে বাংলায় ১-৩ লাইনে লেখো, শুধু যা সত্যিই দেখা যাচ্ছে, অনুমান না:\n"
    "- পেমেন্টের স্ক্রিনশট বা রসিদ (বিকাশ, নগদ, রকেট, মানি রিসিপ্ট): মাধ্যম, সফল কিনা, টাকা, ট্রানজেকশন আইডি, তারিখ-সময়, "
    "কাকে পাঠানো (নাম, নম্বর), রেফারেন্স বা কাস্টমার আইডি থাকলে সেটা। কাগজের রসিদে লেখা আর সংখ্যা প্রায়ই এক লাইনে থাকে না: "
    "কোন টাকা কোন ঘরের, নিশ্চিত না হলে ঘরের নাম না দিয়ে শুধু লেখো রসিদে কী কী টাকা লেখা আছে।\n"
    "- অনু (ONU) বা রাউটারের ছবি: কোন ডিভাইস, কোন বাতি জ্বলছে আর কী রঙ (লাল বাতি, LOS/PON/Internet লেখা বাতি আলাদা করে), "
    "কোনো বাতি বন্ধ কিনা, তার খোলা কিনা। বাতি ঠিকমতো বোঝা না গেলে সেটাই লেখো।\n"
    "- মোবাইল বা কম্পিউটারের স্ক্রিনশট (Wi-Fi, স্পিড টেস্ট, এরর, অ্যাপ): স্ক্রিনের মূল লেখা হুবহু, যেমন "
    "\"Connected without internet\", Wi-Fi-এর নাম, স্পিড কত Mbps।\n"
    "- পাসপোর্ট, NID, মেডিকেল কাগজের মতো ব্যক্তিগত ডকুমেন্ট: শুধু কী ধরনের ডকুমেন্ট; ভেতরের নাম, নম্বর বা অন্য তথ্য লিখবে না।\n"
    "- অন্য কিছু (খাবার, খেলা, ভিডিও, মানুষ): এক লাইনে কী ধরনের ছবি। মানুষের পরিচয় বলবে না।\n"
    "উত্তরের শুরুতে লেখো NET: যদি ছবিটা ইন্টারনেট লাইন, অনু/রাউটার/তার, Wi-Fi, স্পিড, নেটের এরর, বিল বা পেমেন্টের "
    "সাথে সম্পর্কিত (বিকাশ, নগদ, রকেট বা ব্যাংকের যেকোনো টাকা পাঠানো/মার্চেন্ট পে/পেমেন্ট সফলের স্ক্রিনশট সবসময় NET); না হলে (খাবার, ব্যক্তিগত ডকুমেন্ট, গেম, সিনেমা, মানুষ, অন্য কিছু) লেখো OTHER:। তারপর বর্ণনা। "
    "শুধু বর্ণনা লেখো; কাস্টমারকে উত্তর, পরামর্শ বা কোনো শিরোনাম/বুলেট না।"
)
UNRELATED_PHOTO = "[অপ্রাসঙ্গিক ছবি]"  # body tag: staff see it, the bot neither answers nor reads it


def _image_for_claude(data: bytes, mime: str) -> tuple[bytes, str]:
    """The photo as Claude takes it: as it came when it is a JPEG/PNG/WebP within IMAGE_MAX_EDGE (no second
    compression), otherwise re-encoded by ffmpeg (already there for voice) as a JPEG that fits."""
    mime = (mime or "").split(";")[0].strip()
    probe = subprocess.run(["ffprobe", "-v", "error", "-select_streams", "v:0", "-show_entries", "stream=width,height",
                            "-of", "csv=p=0", "pipe:0"], input=data, capture_output=True, timeout=30)
    try:
        w, h = (int(x) for x in probe.stdout.decode().strip().splitlines()[0].split(",")[:2])
    except (ValueError, IndexError):
        w = h = 0
    if mime in ("image/jpeg", "image/png", "image/webp") and 0 < max(w, h) <= IMAGE_MAX_EDGE:
        return data, mime
    e = IMAGE_MAX_EDGE
    out = subprocess.run(["ffmpeg", "-loglevel", "error", "-i", "pipe:0", "-frames:v", "1", "-vf",
                          f"scale='min({e},iw)':'min({e},ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
                          "-q:v", "3", "-f", "image2", "-c:v", "mjpeg", "pipe:1"],
                         input=data, capture_output=True, timeout=60)
    if out.returncode != 0 or not out.stdout:
        raise RuntimeError(f"ffmpeg could not read the photo: {out.stderr[:300]!r}")
    return out.stdout, "image/jpeg"


def see_image(t: Tenant, data: bytes, mime: str, caption: str | None = None,
              contact_id: int | None = None) -> tuple[bool, str] | None:
    """(about our service?, what the customer's photo shows in a few Bangla lines) for the reply AI and the staff, read
    by Claude with the company's Claude keys (AI খরচ: "image"). Zisan 2026-10-16: photos not about the line, bill or
    payment (food, a passport) get no reply. Raises when the company has no usable Claude key or every key fails."""
    img, img_mime = _image_for_claude(data, mime)
    said = f"ছবির সাথে কাস্টমার লিখেছে: {caption}" if caption else "ছবির সাথে কাস্টমার কিছু লেখেনি।"
    messages = [{"role": "user", "content": [
        {"type": "image", "source": {"type": "base64", "media_type": img_mime, "data": base64.b64encode(img).decode()}},
        {"type": "text", "text": said}]}]
    model = t.bot.get("ai_model") if t.bot.get("ai_provider") == "claude" and t.bot.get("ai_model") else IMAGE_MODEL
    text, _, _ = generate(t, IMAGE_PROMPT, messages, "claude", model, "image", contact_id)
    text = re.sub(r"\s*\n\s*", " ", re.sub(r"[*#`]", "", text or "")).strip()
    tag = re.match(r"(NET|OTHER)\s*:\s*", text)
    text = text[tag.end():].strip() if tag else text
    if not text:
        return None
    return (not tag or tag.group(1) == "NET"), text  # no tag: answer it, as before


GEMINI_TTS_MODEL = "gemini-2.5-flash-preview-tts"
TTS_STYLE = "বাংলাদেশি উচ্চারণে, নম্র ও বন্ধুসুলভ কাস্টমার সাপোর্টের মতো স্বাভাবিক গতিতে বলো: "


def speak(t: Tenant, text: str, contact_id: int | None = None) -> bytes | None:
    """The reply read aloud by Gemini TTS (voice from বট সেটিংস, default Kore), as OGG/Opus for a WhatsApp voice
    message. None when there is no usable Gemini key, every key fails, or ffmpeg is missing: the text reply is enough."""
    words = re.sub(r"[*_~`]", "", text).strip()
    if not words:
        return None
    voice = t.bot.get("voice_reply_voice") or "Kore"
    for row in _keys(t, "gemini", usable_only=True):
        r = None
        for attempt in range(2):  # free tier: a 429/503 usually passes on a second try a few seconds later
            try:
                r = httpx.post(
                    f"https://generativelanguage.googleapis.com/v1beta/models/{GEMINI_TTS_MODEL}:generateContent",
                    headers={"x-goog-api-key": db.decrypt(row["api_key"])},
                    json={"contents": [{"parts": [{"text": TTS_STYLE + words}]}],
                          "generationConfig": {"responseModalities": ["AUDIO"], "speechConfig": {
                              "voiceConfig": {"prebuiltVoiceConfig": {"voiceName": voice}}}}},
                    timeout=60,
                )
            except httpx.TransportError as e:
                record_usage(t, row["id"], "gemini", GEMINI_TTS_MODEL, "voice_reply", contact_id,
                             error=f"{type(e).__name__}: {e}")
                r = None
                break
            if r.status_code in (429, 503) and attempt == 0:
                time.sleep(5)
                continue
            break
        if r is None:
            continue
        if r.status_code != 200:
            # no key cool-down here: Gemini limits are per model, and the TTS model's small free quota running out
            # must not push voice transcription (same key, other model) over to Whisper
            record_usage(t, row["id"], "gemini", GEMINI_TTS_MODEL, "voice_reply", contact_id,
                         error=f"HTTP {r.status_code}: {r.text[:300]}")
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
