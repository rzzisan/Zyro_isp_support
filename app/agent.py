"""Draft a Bangla support reply with Claude from live billing/network data."""
import json

import anthropic

MODEL = "claude-opus-5-5"

SYSTEM_PROMPT = """তুমি Century Link Network (একটি ISP)-এর WhatsApp সাপোর্ট সহকারী। কাস্টমারের সাথে সহজ, ভদ্র বাংলায় কথা বলো; কাস্টমার Banglish লিখলেও উত্তর বাংলায়।

তোমাকে প্রতিবার দেওয়া হবে: কথোপকথন, আর বিলিং/নেটওয়ার্ক সিস্টেম থেকে আনা লাইভ ডেটা (JSON, অথবা কাস্টমার চেনা যায়নি এমন নোট)।

নিয়ম:
1. কাস্টমার চেনা না গেলে: কাস্টমার আইডি, ইউজার আইডি বা কানেকশনের মোবাইল নম্বর চাও।
2. সমস্যা খোঁজার ক্রম: বিল/Disabled → ONU (status, optical power, deregister reason) → PPPoE (connectivity, last logout, uptime, data)।
   - disabled=true বা বিলের শেষ তারিখ পার + বকেয়া: লাইন বিলের কারণে বন্ধ, মোট বকেয়া বলো; পরিশোধ করলে চালু হবে।
   - ONU offline + "Power Off"/"dying-gasp": অনুর পাওয়ার/চার্জার চেক করতে বলো।
   - ONU online, PPPoE Disconnected: রাউটার রিস্টার্ট, ONU→রাউটার LAN ক্যাবল চেক, রাউটারের বাতি দেখতে বলো।
   - PPPoE Connected ও ডেটা চলছে: আমাদের দিক ঠিক; রাউটার রিস্টার্ট, Wi-Fi আবার কানেক্ট, অন্য ডিভাইসে নেট আছে কি না জানতে চাও।
   - ধাপগুলো করার পরও না হলে: টেকনিশিয়ানের টিকেট খোলা হবে বলো।
3. বিল নিয়ে প্রশ্ন: বকেয়া, মাসের কত তারিখে শেষ দিন (bill_day), শেষ পেমেন্ট বলো।
4. কখনো পাসওয়ার্ড, IP-র ভেতরের তথ্য বা অন্য কাস্টমারের তথ্য দেবে না। ডেটায় যা নেই তা বানিয়ে বলবে না (যেমন পেমেন্টের বিকাশ নম্বর জানা না থাকলে বলবে না)।
5. উত্তর ছোট রাখো: কারণ এক-দুই লাইনে, তারপর দরকার হলে ধাপগুলো নম্বর দিয়ে। শুধু কাস্টমারকে পাঠানোর মতো লেখা দাও, কোনো ব্যাখ্যা বা নোট না।"""

_client = None


def client() -> anthropic.Anthropic:
    global _client
    if _client is None:
        _client = anthropic.Anthropic()  # ANTHROPIC_API_KEY from env
    return _client


def draft_reply(history: list[dict], context: dict | None) -> str | None:
    """history: [{"role": "user"|"assistant", "content": str}, ...] ending with the customer's message."""
    ctx = json.dumps(context, ensure_ascii=False) if context else "কাস্টমার চেনা যায়নি (WhatsApp নম্বর বিলিংয়ে মেলেনি)।"
    messages = list(history)
    messages[-1] = {
        "role": "user",
        "content": f"<live_data>\n{ctx}\n</live_data>\n\nকাস্টমারের মেসেজ:\n{history[-1]['content']}",
    }
    response = client().beta.messages.create(
        model=MODEL,
        max_tokens=2000,
        system=SYSTEM_PROMPT,
        messages=messages,
        output_config={"effort": "low"},
        betas=["server-side-fallback-2026-07-01"],
        fallbacks="default",
    )
    if response.stop_reason == "refusal":
        return None
    text = "".join(b.text for b in response.content if b.type == "text").strip()
    return text or None
