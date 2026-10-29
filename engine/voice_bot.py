"""Hotline voice bot. Asterisk registers each company's hotline extension (Desk → সেটিংস → হটলাইন (SIP)) and asks
GET /mode whether to take a call. When the bot is on for this caller ("test" = only the test numbers, "live" = all) the
extension rings for the set seconds (the agents' phones ring at the same time) and then answers; the call audio comes
here over AudioSocket and goes to Gemini Live, which listens and speaks Bangla. The caller's customer data (bill,
due, line) is fetched while it rings. Both sides are recorded (MP3 in the panel's storage) with the transcript in
call_logs, shown on the panel's কল লগ page.

WhatsApp calls (Meta Calling API over SIP, TLS 15061 + DTLS-SRTP) land on the same Asterisk as endpoint "meta-wa" in
context zyro-wa, which asks GET /mode?wa=<business number>; the company's WhatsApp number settings (calling_mode
off/test/live, calling_test_numbers, calling_bot_delay) decide, and the same bot answers. call_logs.channel = whatsapp.

Staff take calls themselves (Desk softphone: panel/public/softphone/phone.html in the browser or the Android app, WebRTC
over wss://<desk>/sip-ws to endpoints "a<sip_agents.id>"). While a WhatsApp or hotline call rings, the members allowed
"phone" get an app push (a field technician only for the customers of their open tickets, see may_ring) and the panel
popup; the first to dial take-<uid> gets it (GET /take, the dialplan bridges the two), and
when nobody has before the ring seconds run out the bot answers (GET /bot). Staff also call customers' mobiles out
through the company's hotline extension (GET /out). Hangups come to GET /ended; staff calls are recorded by MixMonitor.

AudioSocket: frames of [type:1][length:2 BE][payload]; 0x01 = call UUID, 0x10 = 8 kHz 16-bit mono PCM, 0x00 = hangup.
Gemini Live takes 16 kHz PCM in and sends 24 kHz PCM out.
"""
import asyncio
import audioop
import base64
import json
import logging
import os
import re
import socket
import subprocess
import sys
import time
import uuid
import wave
from collections import deque
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone
from urllib.parse import parse_qs, urlparse

import websockets

from engine import db, voice_ivr
from engine.sip_calls import normalize

log = logging.getLogger("voice_bot")

STATE_DIR = os.environ.get("VOICE_DIR", "/var/lib/zyro-voice")
REC_DIR = os.environ.get("VOICE_REC_DIR", "/var/www/zyro_isp_support/panel/storage/app/call-recordings")
MODE_PORT, AUDIO_PORT = 8997, 9093
LIVE_URL = "wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContent"
DEFAULT_MODEL = "gemini-3.1-flash-live-preview"
MAX_CALL = 600          # seconds
DEFAULT_MAX_SECONDS = 180
# caller audio quieter than this (RMS of 16-bit PCM) is not sent once the caller has been quiet for SILENCE_HANG frames:
# Gemini Live bills every second of audio sent and keeps it in the context it bills again each turn. 0 = send all.
SILENCE_RMS = int(os.environ.get("VOICE_SILENCE_RMS", "200"))
SILENCE_HANG = 40       # 800 ms of quiet still go out, so Gemini hears the end of a sentence
PREROLL = 10            # the 200 ms before speech starts again are sent with it (first syllable)
# the context Gemini keeps: past this many tokens the oldest turns are dropped down to the target (long calls)
CONTEXT_TRIGGER = int(os.environ.get("VOICE_CONTEXT_TRIGGER", "16000"))
CONTEXT_TARGET = int(os.environ.get("VOICE_CONTEXT_TARGET", "8000"))
REC_SPOOL = os.environ.get("VOICE_REC_SPOOL", "/var/spool/asterisk/zyro-rec")   # MixMonitor WAVs of staff calls
FRAME = 320             # 20 ms of 8 kHz 16-bit audio
PJSIP_FILE = os.path.join(STATE_DIR, "pjsip_zyro.conf")
# WhatsApp calls: codecs offered to Meta (opus only when Asterisk has codec_opus; Meta takes G.711 since 2026-03)
WA_CODECS = os.environ.get("WA_SIP_CODECS", "ulaw,alaw")
# Meta's networks (AS32934); only INVITEs from these reach endpoint meta-wa
META_NETS = os.environ.get("WA_SIP_NETS", ",".join([
    "31.13.24.0/21", "31.13.64.0/18", "45.64.40.0/22", "57.141.0.0/16", "57.144.0.0/14", "66.220.144.0/20",
    "69.63.176.0/20", "69.171.224.0/19", "74.119.76.0/22", "102.132.96.0/20", "103.4.96.0/22", "129.134.0.0/16",
    "147.75.208.0/20", "157.240.0.0/16", "163.70.128.0/17", "163.77.128.0/17", "173.252.64.0/18", "179.60.192.0/22",
    "185.60.216.0/22", "185.89.216.0/22", "204.15.20.0/22"])).split(",")

pool = ThreadPoolExecutor(max_workers=8)
lock = __import__("threading").Lock()   # who takes a ringing WhatsApp call: staff (/take) or the bot (/bot)

# played by us the moment the bot calls a slow tool (billing lookups take several seconds)
FILLERS = {
    "lookup_customer": "একটু অপেক্ষা করুন, আমি আপনার লাইনটা চেক করছি।",
    "create_complaint": "একটু অপেক্ষা করুন, আপনার অভিযোগটা লিখে নিচ্ছি।",
    "reconnect_line": "একটু অপেক্ষা করুন, আপনার লাইনটা দেখছি।",
}
fillers: dict[str, bytes] = {}   # tool name -> 8 kHz PCM
calls: dict[str, dict] = {}   # AudioSocket UUID -> {company_id, caller, settings, prep (future)}

# the call's own short base prompt (2026-10-25): the WhatsApp prompt (~15k chars of chat rules, [[TICKET]] markers,
# examples) was sent with every call and Gemini Live bills the whole context again on every turn
VOICE_BASE = (
    "তুমি {company} (একটি ISP)-এর কাস্টমার সাপোর্ট টিমের একজন মানুষ সদস্য। নিজেকে কখনো AI বা বট বলবে না। "
    "কাস্টমার শুধু যা জানতে চেয়েছেন তার উত্তর দাও, বানিয়ে কিছু বলবে না। পাসওয়ার্ড, IP বা কারো মোবাইল নম্বর বলবে না।\n"
    "নাম ধরে ডাকা জরুরি না; নাম দেখে নিশ্চিত হলে \"ভাই\" বা \"আপু\", না হলে শুধু \"জি\"। কলার নিজে যা বলেন সেটাই মানবে।\n"
    "সমস্যা খোঁজার ক্রম: বিল বা লাইন বন্ধ (blocked/disabled) → ONU (অনলাইন/অফলাইন, LOS, Power Off/dying-gasp, অপটিক্যাল "
    "পাওয়ার) → রাউটার (mikrotik অনলাইন কিনা, uptime)।\n"
    "- ONU অফলাইন আর Power Off/dying-gasp: অনুর পাওয়ার বা চার্জার দেখতে বলো। LOS বা অপটিক্যাল পাওয়ার কম: তারের সমস্যা, টিকেট খোলো।\n"
    "- ONU অনলাইন কিন্তু রাউটার অফলাইন: রাউটার একবার বন্ধ করে চালু, অনু থেকে রাউটারের তার ঠিকমতো লাগানো কিনা।\n"
    "- লাইন চালু, তবু একটা ডিভাইসে চলে না: সেই ডিভাইসেই Wi-Fi forget করে আবার কানেক্ট, ডিভাইস রিস্টার্ট; রাউটার রিস্টার্ট না।\n"
    "- মোবাইল ডেটা (সিমের নেট) আমাদের সার্ভিস না; সেটা চালু করতে বা তুলনা করতে বলবে না।\n"
    "- স্লো নেটের অভিযোগ, কাস্টমার রেগে আছেন, টেকনিশিয়ান চান, বা ধাপ করেও হয়নি: আর ধাপ না দিয়ে টিকেট খোলো।\n"
    "- রাউটারের সেটিং (Wi-Fi নাম/পাসওয়ার্ড) বদলের ধাপ নিজে দেবে না, \"রাউটার কনফিগার\" টিকেট খোলো।\n"
    "বিল: বকেয়া, মাসের কত তারিখের মধ্যে দিতে হয় (bill_day) আর শেষ পেমেন্ট শুধু কাস্টমার তথ্য থেকে। আজকের তারিখ (today) "
    "bill_day পার হলে \"এর মধ্যে দিলে চালু থাকবে\" বলবে না। বিল দিয়েছেন কিন্তু লাইন চালু হয়নি বললে payments দেখো: পেমেন্ট "
    "থাকলে বলো পেয়েছি, কয়েক মিনিট লাগতে পারে; না থাকলে কত টাকা, কোন মাধ্যমে, কোন নম্বর থেকে জেনে Others Support টিকেট। "
    "পেমেন্টের নিয়ম ও নম্বর, অফিস, প্যাকেজ-অফার, সার্ভার-লিংকের মতো কোম্পানির তথ্য শুধু কোম্পানির নির্দেশনা বা FAQ থেকে; "
    "FAQ তোমার সামনে নেই, লাগলে আগে search_faq ডেকে খোঁজো। সেখানেও না থাকলে বলো টিমের কাছ থেকে জেনে "
    "জানানো হবে (request_callback)। ইন্টারনেট, বিল, সংযোগের বাইরের প্রশ্নে সাহায্য করা যায় না বলো।"
)
VOICE_RULES = (
    "\n\nএখন তুমি ফোন কলে কথা বলছ, WhatsApp-এ না। নিয়ম:\n"
    "- শুধু বাংলায়, ছোট ছোট বাক্যে, স্বাভাবিক ভদ্র গলায় কথা বলো। একবারে দুই-তিন বাক্যের বেশি না।\n"
    "- লিংক, মার্কডাউন, ইমোজি, তালিকা চিহ্ন বলবে না। টাকার অঙ্ক ও তারিখ মুখে বলার মতো করে বলো।\n"
    "- নিচের কাস্টমার তথ্য এই কলারের মোবাইল নম্বর মিলিয়ে পাওয়া; এর বাইরে কিছু বানিয়ে বলবে না।\n"
    "- কাস্টমার বাংলাদেশি, বাংলায় (কখনো আঞ্চলিক টানে) কথা বলেন; তাঁর কথা বাংলা হিসেবেই বুঝবে, হিন্দি না।\n"
    "- একই নম্বরে কয়েকটা সংযোগ থাকলে কোনটার কথা জিজ্ঞেস করছে আগে জেনে নাও।\n"
    "- কাস্টমার অন্য কোনো কাস্টমার আইডি বা মোবাইল নম্বর বললে আগে \"একটু দেখছি\" বলে lookup_customer ডাকো, তারপর "
    "সেই ফলাফল থেকেই বলো। আইডি বা তথ্য কখনো অনুমান করবে না, নিজের কাছে থাকা অন্য সংযোগের তথ্য বলবে না।\n"
    "- লাইন বন্ধ বা নেট নেই বললে আগে লাইনের অবস্থা দেখো (pppoe, mikrotik, onu: অনলাইন/অফলাইন, LOS, অপটিক্যাল পাওয়ার)। "
    "বকেয়া থাকলেও বিলের শেষ তারিখ (bill_day) পার না হলে বা লাইন বিলের কারণে বন্ধ (disabled/ব্লক) না দেখালে বিলকে কারণ বলবে না।\n"
    "- অভিযোগ নম্বর, আইডি, টাকার অঙ্ক বা কোনো তথ্য টুলের ফল আসার আগে কখনো বলবে না, অনুমানও করবে না।\n"
    "- লাইন বা অন্য সমস্যার অভিযোগ লিখে রাখতে বললে বা সমাধান না হলে create_complaint দিয়ে বিলিংয়ে টিকিট খোলো, "
    "তারপর অভিযোগ নম্বর জানাও।\n"
    "- কাস্টমার মানুষের সাথে কথা বলতে চাইলে request_callback (flag wants_person) দিয়ে কারণ লিখে রাখো এবং বলো "
    "আমাদের একজন স্টাফ শিগগিরই যোগাযোগ করবেন, তারপর end_call।\n"
    "- কল ছোট রাখো: কাজের কথা শেষ হলেই বিদায়। একই কথা বারবার বলবে না।\n"
    "- কাস্টমার ইন্টারনেট, লাইন, বিল বা সংযোগের বাইরের কথা বললে একবার ভদ্রভাবে বিষয়ে ফিরিয়ে আনো। আবারও অপ্রাসঙ্গিক বা "
    "আবোল-তাবোল কথা বললে আর এগোবে না: বলো \"আমাদের একজন স্টাফ আপনার সাথে যোগাযোগ করবেন, ধন্যবাদ\", "
    "request_callback (flag off_topic) দাও, তারপর end_call।\n"
    "- সমাধান করতে না পারলে request_callback (flag unsolved) দিয়ে একই কথা বলে end_call।\n"
    "- টিকেট খোলা, বা অফিস/টেকনিশিয়ানকে জানানোর কথা মুখে বলার আগে create_complaint ডাকবে (মানুষ চাইলে request_callback); "
    "ফল না আসা পর্যন্ত \"জানাচ্ছি\", \"টিকেট করছি\" বলবে না। create_complaint এক কলে একবারই।\n"
    "- টুলের নাম (end_call, lookup_customer, create_complaint) কখনো মুখে বলবে না; কল শেষ করতে শুধু end_call টুল ডাকবে।\n"
    "- কলের শুরুতে কলারের সংযোগের তথ্য দেওয়া থাকলে তিনি কাস্টমার কিনা আবার জিজ্ঞেস করবে না। কলার যে নাম বলেন তা ওই "
    "তথ্যের নামের সাথে মিললে সেটাই ধরো, আবার lookup_customer করবে না।\n"
    "- কলারের সংযোগ পাওয়া না গেলে (lookup_customer-এ found=true না আসা পর্যন্ত) কোনো বকেয়া, টাকা, প্যাকেজ বা লাইনের "
    "অবস্থা বলবে না; নির্দেশনার উদাহরণের টাকার অঙ্ক কারো আসল তথ্য না।\n"
    "- রাউটার রিস্টার্ট বা তার চেক সর্বোচ্চ একবার বলবে। কলার বলেন আগেই করেছেন বা তাতে হয়নি, তাহলে সাথে সাথে "
    "create_complaint। তার উল্টাপাল্টা লাগানোর মতো পরামর্শ দেবে না।\n"
    "- প্যাকেজের নাম, স্পিড, দাম শুধু কাস্টমার তথ্যের package থেকে বলবে; মাসিক বিল আর মোট বকেয়া আলাদা করে বলবে। "
    "পেমেন্টের নম্বর বা নিয়ম জানতে চাইলে কোম্পানির নির্দেশনা থেকে বলবে, নম্বরের অঙ্কগুলো ধীরে আলাদা করে।\n"
    "- কাস্টমার তথ্যের open_tickets-এ খোলা অভিযোগ থাকলে লাইনের সমস্যার কথায় প্রথমেই সেই অভিযোগ নম্বর জানাও আর বলো "
    "টিম কাজ করছে; আবার রিস্টার্টের ধাপ শেখাবে না।\n"
    "- লাইনের অবস্থা বাংলায় বলবে (\"বিলের কারণে বন্ধ\", \"চালু আছে\"), \"ডিসএবল্ড\" জাতীয় ইংরেজি শব্দে না।\n"
    "- কলারের কথা অন্য ভাষার মতো শোনালে বা অর্থ বোঝা না গেলে অনুমান করবে না, বলবে \"জি, আরেকবার বলবেন?\"\n"
    "- কলার কাস্টমার আইডি বা মোবাইল নম্বর বললে অঙ্কগুলো একবার ফিরিয়ে বলে নিশ্চিত হও (\"শূন্য এক তিন এক, ঠিক আছে?\"), "
    "তারপর lookup_customer।\n"
    "- কথা শেষ হলে বিদায় জানিয়ে end_call ডাকো।"
)
# the bot says it will open a ticket / tell the office (then it must have called create_complaint or request_callback)
PROMISE = re.compile(r"টিকেট|টিকিট|জানাচ্ছি|জানিয়ে দিচ্ছি|জানানো হলো|জানিয়ে রাখছি|অভিযোগটা লিখে|টেকনিশিয়ান")
FACTS = re.compile(r"টাকা|বকেয়া|বিল|টিকেট|টিকিট|অভিযোগ")
BN_DIGITS = str.maketrans("০১২৩৪৫৬৭৮৯", "0123456789")


def numbers_in(text: str, least: int = 3) -> list[str]:
    """Digit runs of `least`+ digits (Bangla digits too, "1,234" = 1234)."""
    t = re.sub(r"(?<=\d)[,\s](?=\d)", "", (text or "").translate(BN_DIGITS))
    return [x for x in re.findall(r"\d+", t) if len(x) >= least]


SPOKEN_TOOL = re.compile(r"\s*\b(end[_ ]?call|lookup[_ ]?customer|create[_ ]?complaint|request[_ ]?callback|reconnect[_ ]?line)\b\.?", re.I)
STAFF_RULES = (
    "তুমি {company}-এর অফিস সাপোর্ট ডেস্ক, ফোনে কথা বলছ কোম্পানির স্টাফ/টেকনিশিয়ান {tech}-এর সাথে (তিনি কাস্টমার নন)। "
    "WhatsApp-এ স্টাফরা যা পান, ফোনেও তাই দেবে:\n"
    "- কথ্য বাংলায়, খুব সংক্ষেপে, শুধু যা জানতে চেয়েছেন। সম্বোধন \"জি ভাই\"। ভূমিকা বা লম্বা বিদায় নয়।\n"
    "- যেকোনো কাস্টমারের তথ্য দেওয়া যাবে: নাম, ID, PPPoE ID, লাইন অনলাইন কিনা, uptime, IP, MAC, ONU অবস্থা ও "
    "অপটিক্যাল পাওয়ার, OLT, জোন/বক্স, বিল ও বকেয়া, খোলা টিকিট। পাসওয়ার্ড কখনো না। আগে \"একটু দেখছি\" বলে "
    "lookup_customer ডাকো; ID, মোবাইল বা PPPoE ID দিয়ে খোঁজা যায়। ফলাফলের বাইরে কিছু বানাবে না।\n"
    "- \"mikrotik\" এই মুহূর্তের রাউটারের অবস্থা; অনলাইন কিনা বলতে এটাই আগে দেখবে। blocked মানে বিলিং থেকে ব্লক।\n"
    "- টিকিট খুলতে বললে create_complaint (ক্যাটাগরিসহ), সমাধান করতে বললে solve_ticket, লাইন চালু/বন্ধ করতে বললে "
    "switch_line। স্পষ্ট করে না বললে এগুলো ডাকবে না। ফল এলে তবেই জানাবে, আগে নয়।\n"
    "- কাজ শেষ হলে \"ঠিক আছে ভাই\" বলে end_call।\n"
    "- টাকার অঙ্ক, ID, নম্বর মুখে বলার মতো করে স্পষ্ট বলো।"
)
# added when the company lets customers have a bill-disabled line turned on once a month (engine/self_reconnect.py)
RECONNECT_RULES = (
    "\n- কাস্টমার বিলের কারণে বন্ধ লাইনটা এখন চালু করে দিতে বললে reconnect_line ডাকো (সংযোগটা পাওয়া গেলে)। ফল আসার "
    "আগে \"চালু করে দিচ্ছি\" বলবে না। ফলের say লেখাটা নিজের ভাষায় না বানিয়ে ঠিক সেই কথাটাই বলবে: চালু হলে সাথে বলবে যে এই "
    "সুবিধা মাসে একবারই, আর না হলে কেন হবে না। কাস্টমার নিজে না চাইলে এই সুবিধার কথা বলবে না, reconnect_line এক কলে একবারই।"
)
FLAGS = {"off_topic", "wants_person", "unsolved", "time_up"}
CATEGORIES = ["Speed Slow", "Line Off", "Line Not Stable", "অনু লাল বাতি", "অনু বাতি জলে না(ONU Power OFF)", "অনু চার্জার নষ্ট",
              "ফাইবার তার ছিড়া", "Optical Power Low", "রাউটার নষ্ট", "রাউটার কনফিগার", "Password change",
              "Cable problem/Change", "Others Support"]
WRAP_UP = ("(কলের সময় প্রায় শেষ। এখনই এক বাক্যে বলো \"আমাদের একজন স্টাফ আপনার সাথে যোগাযোগ করবেন, ধন্যবাদ\", "
           "দরকার হলে request_callback (flag time_up) দাও, তারপর end_call।)")
TOOLS = [{"functionDeclarations": [
    {"name": "lookup_customer", "description": "Find a line by customer ID or mobile number: bill, due, line status, open tickets.",
     "parameters": {"type": "OBJECT", "properties": {"id_or_mobile": {"type": "STRING", "description": "digits only"}},
                    "required": ["id_or_mobile"]}},
    {"name": "create_complaint", "description": "Open a complaint ticket in the billing software for a customer's line.",
     "parameters": {"type": "OBJECT", "properties": {
         "customer_id": {"type": "STRING", "description": "the line's customer ID"},
         "problem": {"type": "STRING", "description": "the problem in short Bangla"},
         "category": {"type": "STRING", "enum": CATEGORIES}},
         "required": ["customer_id", "problem"]}},
    {"name": "request_callback", "description": "Record that a staff member must contact this customer, with the reason; flags the call in the call log.",
     "parameters": {"type": "OBJECT", "properties": {
         "reason": {"type": "STRING", "description": "short reason in Bangla"},
         "flag": {"type": "STRING", "enum": sorted(FLAGS), "description": "why the bot stops here"}},
         "required": ["reason", "flag"]}},
    {"name": "reconnect_line", "description": "The customer asks to have their bill-disabled line turned on now: checks the "
     "company's rule (due at most one month, once a month) and turns it on if allowed. Say the result's `say` text.",
     "parameters": {"type": "OBJECT", "properties": {"customer_id": {"type": "STRING", "description": "the line's customer ID"}},
                    "required": ["customer_id"]}},
    {"name": "search_faq", "description": "Search the company's FAQ (payment numbers and rules, office, packages, offers, "
     "servers) for what the caller asked; say the answer in your own short words.",
     "parameters": {"type": "OBJECT", "properties": {"query": {"type": "STRING", "description": "the question, short Bangla"}},
                    "required": ["query"]}},
    {"name": "end_call", "description": "Hang up after saying goodbye, when the conversation is over."},
]}]
# staff (the panel's technicians, by their number) also get what the WhatsApp staff desk does
STAFF_TOOLS = [{"functionDeclarations": [f for f in TOOLS[0]["functionDeclarations"]
                                                if f["name"] not in ("request_callback", "reconnect_line")] + [
    {"name": "solve_ticket", "description": "Solve the customer's open tickets (only while the line is online).",
     "parameters": {"type": "OBJECT", "properties": {
         "customer_id": {"type": "STRING"}, "remark": {"type": "STRING", "description": "what was done, short Bangla"}},
         "required": ["customer_id"]}},
    {"name": "switch_line", "description": "Turn the customer's line on (enable) or off (disable) in billing, if this staff member may.",
     "parameters": {"type": "OBJECT", "properties": {
         "customer_id": {"type": "STRING"}, "action": {"type": "STRING", "enum": ["enable", "disable"]}},
         "required": ["customer_id", "action"]}},
]}]


# --- settings and Asterisk config ------------------------------------------------------------
def settings_rows() -> list[dict]:
    """Every enabled hotline extension: the bot answers through it and staff call customers out through it."""
    return db.all_rows("select * from sip_settings where enabled"
                       " and server is not null and username is not null and password is not null order by company_id")


def pjsip_config(rows: list[dict]) -> str:
    out = []
    for r in rows:
        pw = db.decrypt(r["password"])
        if not pw:
            continue
        c, host, port = f"c{r['company_id']}", r["server"], r["port"]
        try:
            ip = socket.gethostbyname(host)
        except OSError:
            ip = host
        user = r["username"]
        out.append(f"""
[{c}-auth]
type=auth
auth_type=userpass
username={r['auth_user'] or user}
password={pw}

[{c}]
type=aor
contact=sip:{host}:{port}

[{c}]
type=endpoint
transport=transport-udp
context=zyro-in
disallow=all
allow=alaw,ulaw
outbound_auth={c}-auth
aors={c}
from_user={user}
from_domain={host}
direct_media=no
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes
identify_by=ip
; no audio for 2 minutes = the call is dead (hang up instead of staying open)
rtp_timeout=120

[{c}-identify]
type=identify
endpoint={c}
match={ip}

[{c}-reg]
type=registration
transport=transport-udp
outbound_auth={c}-auth
server_uri=sip:{host}:{port};transport={r['transport']}
client_uri=sip:{user}@{host}:{port}
contact_user={user}
retry_interval=60
forbidden_retry_interval=300
expiration=300
line=yes
endpoint={c}
""")
    return "".join(out)


def wa_config() -> str:
    """Endpoint for Meta's WhatsApp calls (transport-tls is in deploy/asterisk/pjsip.conf), only while some company
    takes them. Meta calls in from its own networks; the dialplan asks /mode which company the dialed number is."""
    if not db.one("select 1 as x from wa_accounts where calling_mode in ('test', 'live') limit 1"):
        return ""
    return f"""
[meta-wa]
type=endpoint
transport=transport-tls
context=zyro-wa
disallow=all
allow={WA_CODECS}
webrtc=yes
direct_media=no
force_rport=yes
; BYE etc. go back over Meta's own TLS connection: Meta's Contact says port 5061, which is blocked outbound from bsol
rewrite_contact=yes
; no audio for 2 minutes = the call is dead (Meta sent no BYE for the call stuck 29 h on 2026-10-24)
rtp_timeout=120

[meta-wa-identify]
type=identify
endpoint=meta-wa
""" + "".join(f"match={n.strip()}\n" for n in META_NETS if n.strip())


def agents_config() -> str:
    """One WebRTC endpoint per Desk softphone user (sip_agents, made by the panel when the user first opens it).
    Browsers and the app connect over WSS (nginx /sip-ws → Asterisk's http server) and never register: they only
    place calls (take-<uid>, or a customer's mobile) into context zyro-agent."""
    out = []
    for r in db.all_rows("select id, password from sip_agents order by id"):
        pw = db.decrypt(r["password"])
        if not pw:
            continue
        a = f"a{r['id']}"
        out.append(f"""
[{a}-auth]
type=auth
auth_type=userpass
username={a}
password={pw}

[{a}]
type=aor
max_contacts=5
remove_existing=yes

[{a}]
type=endpoint
transport=transport-ws
context=zyro-agent
disallow=all
allow=opus,ulaw,alaw
webrtc=yes
direct_media=no
auth={a}-auth
aors={a}
""")
    return "".join(out)


def sync_asterisk() -> None:
    text = pjsip_config(settings_rows()) + wa_config() + agents_config()
    try:
        old = open(PJSIP_FILE).read()
    except OSError:
        old = None
    if text == old:
        return
    fd = os.open(PJSIP_FILE, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o640)
    with os.fdopen(fd, "w") as f:
        f.write(text)
    r = subprocess.run(["asterisk", "-rx", "pjsip reload"], capture_output=True, text=True, timeout=20)
    log.info("asterisk pjsip reloaded: %s", (r.stdout or r.stderr).strip()[:200])


# --- what the bot knows about the caller -------------------------------------------------------
def tenant_for(company_id: int):
    from engine import tenant as tenants
    row = db.one("select phone_number_id from wa_accounts where company_id = %s order by id limit 1", (company_id,))
    return tenants.by_phone_number_id(row["phone_number_id"]) if row else None


def pruned(v):
    """The value without empty fields (None, "", [], {}): every token of the caller's data is billed again each turn."""
    if isinstance(v, dict):
        v = {k: pruned(x) for k, x in v.items()}
        return {k: x for k, x in v.items() if x not in (None, "", [], {})}
    if isinstance(v, list):
        return [pruned(x) for x in v]
    return v


def brief(v) -> str:
    return json.dumps(pruned(json.loads(json.dumps(v, ensure_ascii=False, default=str))), ensure_ascii=False,
                      separators=(",", ":"))


def safe_view(context: dict) -> dict:
    """Like the WhatsApp bot's verified view: the caller's own number matched, but never IPs or MACs."""
    ctx = json.loads(json.dumps(context, ensure_ascii=False, default=str))
    (ctx.get("customer") or {}).pop("registered_mobile", None)
    (ctx.get("pppoe") or {}).pop("ip", None)
    ctx.pop("mac", None)
    for k in ("address", "caller_id", "profile"):  # profile: a router setting, not the package (2026-10-24)
        (ctx.get("mikrotik") or {}).pop(k, None)
    ctx["today"] = (datetime.now(timezone.utc) + timedelta(hours=6)).strftime("%Y-%m-%d")
    return ctx


def line_context(t, api, row: dict, staff: bool = False, caller: str | None = None) -> dict:
    """The WhatsApp bot's view of one line: bill, due, MikroTik/ONU status, open tickets.
    Staff see everything (PPPoE IP, MAC) like on the WhatsApp staff desk."""
    from engine import customers
    from engine.ticket_actions import open_tickets
    # never billing's ONU page here: it often takes 15+ s and a caller waits on the line meanwhile
    row = customers.fresh(api, row)
    ctx = customers.live_context(t, api, row, billing_onu=False)
    ctx["open_tickets"] = [{"no": x["complain_id"], "category": x["category"], "state": x["state"]}
                           for x in open_tickets(t, row.get("CustomerId"))]
    if staff:
        ctx = json.loads(json.dumps(ctx, ensure_ascii=False, default=str))
        ctx["customer"]["zone"] = ctx["customer"].get("zone") or row.get("ZoneName")
        return ctx
    from engine import self_reconnect
    ctx = safe_view(ctx)
    # every token here is billed again on each Live turn: the last two payments, not the router's raw answer
    if isinstance(ctx.get("payments"), list):
        ctx["payments"] = ctx["payments"][:2]
    mk = ctx.pop("mikrotik", None) or {}
    if mk and not ctx.get("pppoe"):
        ctx["router"] = {k: mk.get(k) for k in ("online", "blocked", "uptime")}
    ctx.pop("sources", None)
    state = self_reconnect.status(t, row, caller)
    if state:
        ctx["self_reconnect"] = state
    return ctx


def find_line(t, api, query: str) -> dict | None:
    """A line by customer ID, mobile or (staff) PPPoE ID."""
    from engine import customers
    q = "".join(ch for ch in str(query) if ch.isdigit())
    number = normalize(q)
    if number:
        return customers.by_whatsapp(t, api, number)
    if q and q == str(query).strip():
        return customers.by_id(t, api, q)
    return customers.by_text(t, api, str(query).strip(), allow_bare_id=True)


def lookup_customer(t, id_or_mobile: str, staff: bool = False) -> dict:
    """Tool: any line by customer ID or mobile (Zisan 2026-10-11: whoever gives an ID or number gets full support)."""
    from engine import tenant as tenants
    if not t:
        return {"error": "billing not connected"}
    api = tenants.billing(t)
    row = find_line(t, api, id_or_mobile)
    if not row:
        return {"found": False, "note": "এই আইডি বা নম্বরে কোনো সংযোগ পাওয়া যায়নি; আবার আইডিটা বলতে বলো"}
    return {"found": True, **line_context(t, api, row, staff)}


def search_faq(t, query: str) -> dict:
    """Tool: the FAQ entries that match the caller's question (a small FAQ: all of it)."""
    from engine import agent
    if not t:
        return {"found": False}
    rows = agent.relevant_faqs(agent.faqs(t), query or "")
    if not rows:
        return {"found": False, "note": "FAQ-তে এ নিয়ে কিছু নেই; বানিয়ে বলবে না, দরকার হলে request_callback"}
    return {"found": True, "faq": [{"q": r["question"], "a": r["answer"]} for r in rows]}


def staff_action(t, tech: dict, name: str, args: dict) -> dict:
    """Tools only staff have, done exactly as the WhatsApp staff desk does them (same checks and logs)."""
    from engine import tenant as tenants
    api = tenants.billing(t)
    row = find_line(t, api, args.get("customer_id", ""))
    if not row:
        return {"ok": False, "note": "এই আইডিতে সংযোগ পাওয়া যায়নি"}
    if name == "solve_ticket":
        from engine.ticket_actions import solve_for_customer
        ok, msg = solve_for_customer(t, row, args.get("remark") or f"ফোনে {tech['name']}")
        return {"ok": ok, "result": msg}
    from engine.technician import switch_line
    action = "enable" if args.get("action") == "enable" else "disable"
    return {"result": switch_line(t, tech, row, f"[ফোন কল] {action} {args.get('customer_id')}", action)}


def reconnect_line(t, caller: str, customer_id: str, channel: str) -> dict:
    """Tool: the customer's own request to turn their bill-disabled line on (same rule and log as the WhatsApp chat)."""
    from engine import self_reconnect
    from engine import tenant as tenants
    if not t:
        return {"error": "billing not connected"}
    row = find_line(t, tenants.billing(t), customer_id)
    if not row:
        return {"ok": False, "note": "এই আইডিতে সংযোগ পাওয়া যায়নি"}
    res = self_reconnect.reconnect(t, row, "wa_call" if channel == "whatsapp" else "hotline", normalize(caller) or caller,
                                   f"[{'WhatsApp কল' if channel == 'whatsapp' else 'হটলাইন কল'}] কলার লাইন চালু চেয়েছেন")
    return {"ok": res["result"] in ("enabled", "dry_run"), "result": res["result"], "say": res["say"]}


def create_complaint(t, caller: str, customer_id: str, problem: str, category: str | None = None) -> dict:
    """Tool: a ticket (in the Desk or the billing software), like the WhatsApp bot's (one open ticket per line)."""
    from engine import customers
    from engine import tenant as tenants
    from engine.ispdigital import normalize_bd_mobile
    if not t:
        return {"error": "billing not connected"}
    if not t.bot.get("auto_ticket", True):
        return {"ok": False, "note": "বট দিয়ে টিকিট খোলা বন্ধ; request_callback দাও"}
    billing_api = tenants.billing(t)
    row = find_line(t, billing_api, customer_id)
    if not row:
        return {"ok": False, "note": "এই আইডিতে সংযোগ পাওয়া যায়নি"}
    from engine.desk_tickets import for_new
    api = for_new(t, actor="ফোন বট", billing=lambda: billing_api)
    existing = api.open_tickets_for(row.get("UserName") or "")
    if existing:
        return {"ok": True, "already_open": True, "ticket_no": existing[0].get("ComplainId")}
    _, cats = api.ticket_form()
    cid = (cats.get(category) if category else None) \
        or next((v for k, v in cats.items() if category and category.lower() in k.lower()), None) \
        or next((v for k, v in cats.items() if any(w in k.lower() for w in ("line off", "internet", "line"))), None) \
        or cats.get("Others Support") or next(iter(cats.values()))
    mobile = normalize_bd_mobile(row.get("MobileNumber") or "") or normalize(caller) or caller
    api.create_ticket(row["CustomerHeaderId"], cid, 2, mobile, f"[ফোন বট] {problem}\nকলার: {caller}")
    no = getattr(api, "created_id", None)
    if not no:
        created = api.open_tickets_for(row.get("UserName") or "")
        no = created[0].get("ComplainId") if created else None
    try:
        from engine.ticket_autoassign import assign
        assign(t, api, no, row.get("CustomerHeaderId"), row, category_id=cid)
    except Exception:
        log.exception("ticket %s auto-assign failed", no)
    return {"ok": True, "ticket_no": no}


def prepare(company_id: int, caller: str, staff: dict | None = None, channel: str = "hotline") -> dict:
    """System prompt and voice, built while the call rings (billing lookups take a few seconds)."""
    from engine import agent, customers
    t = tenant_for(company_id)
    company = db.one("select name from companies where id = %s", (company_id,)) or {"name": ""}
    voice = ((t.bot.get("voice_reply_voice") if t else None) or "Kore")
    if staff:
        block = agent.knowledge(t) if t else ""
        prompt = STAFF_RULES.replace("{company}", company["name"]).replace("{tech}", staff["name"])
        return {"prompt": prompt + ("\n\n" + block if block else ""), "voice": voice, "tenant": t, "staff": staff}
    # the call's short rules plus the company's own instructions and FAQ (payment numbers, offers, office)
    rules = VOICE_BASE.replace("{company}", (t.name if t else "") or company["name"]) + VOICE_RULES
    if t and t.bot.get("self_reconnect"):
        rules += RECONNECT_RULES
    if channel == "whatsapp":
        rules = rules.replace("এখন তুমি ফোন কলে কথা বলছ, WhatsApp-এ না।", "এখন তুমি WhatsApp কলে (ভয়েসে) কথা বলছ, লেখা মেসেজে না।")
    prompt = rules
    # Live bills the whole prompt again on every turn: the company's instructions stay, its FAQ comes by search_faq
    extra = ((t.bot.get("extra_prompt") or "").strip() if t else "")
    if extra:
        prompt += ("\n\n=== কোম্পানির নির্দেশনা (অ্যাডমিন লিখেছেন; এখানকার তথ্য সঠিক ও চূড়ান্ত, হুবহু মেনে চলবে) ===\n"
                   f"{extra}\n=== নির্দেশনা শেষ ===")
    number = normalize(caller)
    matched = None
    info = ("কলারের সংযোগ: পাওয়া যায়নি (CUSTOMER NOT FOUND)। কলারের নম্বর আমাদের কোনো কাস্টমারের সাথে মেলেনি, তাই "
            "এই কলারের কোনো বকেয়া, প্যাকেজ বা লাইনের অবস্থা তোমার জানা নেই। কাস্টমার আইডি জিজ্ঞেস করে lookup_customer দিয়ে খোঁজো।")
    if t and number:
        try:
            from engine import tenant as tenants
            api = tenants.billing(t)
            row = customers.by_whatsapp(t, api, number)
            if row:
                info = "কলারের নম্বরে মেলা সংযোগের তথ্য (JSON):\n" + brief(line_context(t, api, row, caller=number))
                matched = {"customer_id": str(row.get("CustomerId") or ""), "name": row.get("CustomerName") or ""}
        except Exception:
            log.exception("company %s: customer lookup for %s failed", company_id, caller)
    others = db.all_rows("select customer_id, name, zone, due, is_left from billing_customers"
                         " where company_id = %s and mobile_normalized = %s order by is_left, header_id desc limit 5",
                         (company_id, number)) if number else []
    if len(others) > 1:
        info += "\nএই নম্বরে আরও সংযোগ: " + brief(others)
    # rules / info apart: the cascade (engine/voice_cascade.py) caches the rules and adds only the FAQ a turn needs
    return {"prompt": prompt + "\n\n" + info, "voice": voice, "tenant": t, "staff": None, "matched": matched,
            "rules": rules, "info": info}


# --- GET /mode (asked by the Asterisk dialplan on every incoming call) -------------------------
def numbers(text) -> set[str]:
    return {normalize(x) or x.strip() for x in (text or "").replace("\n", ",").split(",") if x.strip()}


def start(company_id: int, caller: str, settings: dict, delay: int, channel: str = "hotline") -> str:
    """Book the call for the AudioSocket side and start building the prompt while it rings."""
    number = normalize(caller) or caller
    # the company's technicians (panel → টেকনিশিয়ান, by their WhatsApp number) get the staff desk, as on WhatsApp
    staff = db.one("select id, name, wa_number, can_switch_lines from technicians where company_id = %s and wa_number = %s and active",
                   (company_id, number))
    uid = str(uuid.uuid4())
    info = {"company_id": company_id, "caller": caller, "settings": settings, "at": time.time(), "staff": staff,
            "channel": channel, "state": "waiting", "prep": pool.submit(prepare, company_id, caller, staff, channel)}
    calls[uid] = info
    if channel == "whatsapp":
        # rings in Desk (panel popup, app push) for the staff allowed to take calls; the bot answers after `delay`
        row = db.execute("insert into call_logs (company_id, call_ref, caller, caller_normalized, status, started_at,"
                         " channel, direction, created_at, updated_at)"
                         " values (%s, %s, %s, %s, 'ringing', now(), 'whatsapp', 'in', now(), now()) returning id",
                         (company_id, "wa-" + uid, caller[:80], normalize(caller)))
        info["row_id"] = row["id"]
    # rings in Desk for the staff allowed to take calls (a hotline call rings the office phones too)
    if delay > 0:
        notify_staff(company_id, {"type": "call", "uid": uid, "caller": display_number(caller),
                                  "name": caller_name(company_id, caller), "channel": channel}, caller=caller)
    return f"bot:{uid}:{delay}"


def ring_only(company_id: int, caller: str) -> str:
    """A hotline call the bot leaves alone: "ring:<uid>", it rings in Desk (and on the office phones) until someone
    takes it, the office picks it up or the caller hangs up."""
    uid = str(uuid.uuid4())
    calls[uid] = {"company_id": company_id, "caller": caller, "at": time.time(), "channel": "hotline", "state": "waiting"}
    notify_staff(company_id, {"type": "call", "uid": uid, "caller": display_number(caller),
                              "name": caller_name(company_id, caller), "channel": "hotline"}, caller=caller)
    return f"ring:{uid}"


def hotline_row(uid: str, info: dict) -> int:
    """The call_logs row of a hotline call taken in Desk: the one the caller ID popup made (engine/sip_calls.py), or a
    new one."""
    if info.get("row_id"):
        return info["row_id"]
    caller, at = info.get("caller") or "", info.get("at") or time.time()
    row = db.one("select id from call_logs where company_id = %s and channel = 'hotline' and not bot_answered"
                 " and (caller = %s or caller_normalized = %s) and abs(extract(epoch from started_at) - %s) < 90"
                 " order by abs(extract(epoch from started_at) - %s) limit 1",
                 (info["company_id"], caller[:80], normalize(caller), at, at)) \
        or db.execute("insert into call_logs (company_id, call_ref, caller, caller_normalized, status, started_at, channel,"
                      " direction, created_at, updated_at) values (%s, %s, %s, %s, 'ringing', now(), 'hotline', 'in', now(), now())"
                      " returning id", (info["company_id"], "ring-" + uid, caller[:80], normalize(caller)))
    info["row_id"] = row["id"]
    return row["id"]


def display_number(raw: str) -> str:
    n = normalize(raw)
    return "0" + n[3:] if n else raw


def caller_name(company_id: int, caller: str) -> str:
    n = normalize(caller)
    row = db.one("select name, customer_id from billing_customers where company_id = %s and mobile_normalized = %s"
                 " order by is_left, header_id desc limit 1", (company_id, n)) if n else None
    return f"{row['name']} (ID {row['customer_id']})" if row else ""


PHONE_USERS = """select cu.user_id from company_user cu where cu.company_id = %s and (
    cu.role = 'owner' or cu.permissions ? 'phone'
    or exists (select 1 from membership_permission_group mpg join permission_groups g on g.id = mpg.permission_group_id
               where mpg.membership_id = cu.id and g.permissions ? 'phone')
    or (cu.permissions is null and cu.role = 'admin'
        and not exists (select 1 from membership_permission_group mpg where mpg.membership_id = cu.id)))"""


# an open ticket naming the technician whose customer is the caller: the ticket's mobile, its contact number
# (billing's "Contact mobile", raw ComplainNumber) or the customer's own mobile; numbers compared by their last 10 digits
TECH_CALLER = """select 1 from billing_tickets bt
    left join billing_customers bc on bc.company_id = bt.company_id and bc.header_id = bt.customer_header_id
    where bt.company_id = %s and bt.state in ('pending', 'processing') and bt.assigned_to ~* %s
      and %s in (right(regexp_replace(coalesce(bt.mobile, ''), '\\D', '', 'g'), 10),
                 right(regexp_replace(coalesce(bt.raw->>'ComplainNumber', ''), '\\D', '', 'g'), 10),
                 right(coalesce(bc.mobile_normalized, ''), 10))
    limit 1"""


def may_ring(company_id: int, user_id: int, caller: str) -> bool:
    """A field technician (agent ticked on the team page) gets only the calls of the customers on their open tickets;
    everyone else allowed "phone" gets every call."""
    m = db.one("""select cu.role, cu.field_technician, o.name from company_user cu
                  left join billing_options o on o.company_id = cu.company_id and o.kind = 'employees'
                       and o.option_id = cu.billing_employee_id
                  where cu.company_id = %s and cu.user_id = %s""", (company_id, user_id))
    if not m or m["role"] != "agent" or not m["field_technician"]:
        return True
    name, digits = (m["name"] or "").strip(), re.sub(r"\D", "", caller or "")[-10:]
    if not name or len(digits) < 10:
        return False
    # the panel's BillingTicket::forEmployee: the name in the comma list, maybe followed by " (date)"
    return bool(db.one(TECH_CALLER, (company_id, "(^|, )" + re.escape(name) + "( \\(|,|$)", digits)))


def phone_allowed(company_id: int, user_id: int) -> bool:
    """The panel's Menu::allows(user, company, 'phone') in SQL (owner; own list; groups; admin defaults)."""
    return bool(db.one(PHONE_USERS + " and cu.user_id = %s", (company_id, user_id)))


BD = timezone(timedelta(hours=6))


def in_call_hours(hours, at: datetime | None = None) -> bool:
    """The panel's App\\Support\\CallDuty::inHours: {days: [ISO weekdays], from, to} in Dhaka time; none = any time;
    a night shift (from after to) belongs to the day it starts."""
    if isinstance(hours, str):
        try:
            hours = json.loads(hours)
        except ValueError:
            hours = None
    hours = hours or {}
    days = [int(d) for d in hours.get("days") or []]
    start, end = (hours.get("from") or "")[:5], (hours.get("to") or "")[:5]
    if not days and not start and not end:
        return True
    at = (at or datetime.now(timezone.utc)).astimezone(BD)
    hm = at.strftime("%H:%M")
    start, end = start or "00:00", end or "24:00"
    day = (at - timedelta(days=1)).isoweekday() if start > end and hm < end else at.isoweekday()
    if days and day not in days:
        return False
    return start <= hm < end if start <= end else (hm >= start or hm < end)


def on_call_duty(company_id: int, users: list[int]) -> list[int]:
    """Of these members, the ones taking calls now: duty hours from the team page, the app's "কল নিচ্ছি" switch."""
    if not users:
        return []
    rows = db.all_rows("select user_id, call_hours, calls_paused from company_user where company_id = %s and user_id = any(%s)",
                       (company_id, list(users)))
    ok = {r["user_id"] for r in rows if not r["calls_paused"] and in_call_hours(r["call_hours"])}
    return [u for u in users if u in ok]


def ring_users(company_id: int, caller: str) -> list[int]:
    """Whose phones ring for this caller now: allowed "phone", may take this caller (technicians), on duty."""
    users = [r["user_id"] for r in db.all_rows(PHONE_USERS, (company_id,))]
    return on_call_duty(company_id, [u for u in users if may_ring(company_id, u, caller)])


def notify_staff(company_id: int, data: dict, caller: str | None = None, only: list[int] | None = None) -> None:
    """App push (engine/fcm.py) to the phones of the members allowed to take calls; with [caller], a ringing call, only
    to those who may take that caller's call (see may_ring) and are on call duty; [only] narrows it to these members."""
    try:
        from engine import fcm
        users = [r["user_id"] for r in db.all_rows(PHONE_USERS, (company_id,))]
        if caller is not None:
            users = on_call_duty(company_id, [u for u in users if may_ring(company_id, u, caller)])
        if only is not None:
            users = [u for u in users if u in only]
        company = db.one("select slug from companies where id = %s", (company_id,)) or {}
        if users:
            fcm.send_users(company_id, users, {"company": company.get("slug") or "", **{k: str(v) for k, v in data.items()}})
    except Exception:
        log.exception("call push")


def agent_row(ep: str) -> dict | None:
    if not ep.startswith("a") or not ep[1:].isdigit():
        return None
    return db.one("select a.id, a.company_id, a.user_id, u.name from sip_agents a join users u on u.id = a.user_id"
                  " where a.id = %s", (int(ep[1:]),))


def take(uid: str, ep: str) -> str:
    """A staff softphone dialed take-<uid>: "ok" for the first allowed member while the call still rings."""
    info, agent = calls.get(uid), agent_row(ep)
    if not info or not agent or agent["company_id"] != info["company_id"] \
            or not phone_allowed(agent["company_id"], agent["user_id"]) \
            or not may_ring(agent["company_id"], agent["user_id"], info.get("caller") or ""):
        return "no"
    with lock:
        if info.get("state") != "waiting":
            return "gone"
        info.update(state="taken", agent=agent, taken_at=time.time())
    row_id = hotline_row(uid, info) if info.get("channel") == "hotline" else info.get("row_id")
    db.execute("update call_logs set status = 'answered', answered_by = %s, updated_at = now() where id = %s",
               (agent["user_id"], row_id))
    notify_staff(info["company_id"], {"type": "call_taken", "uid": uid, "by": agent["name"]})
    log.info("call %s taken by %s", uid, agent["name"])
    return "ok"


def bot_turn(uid: str) -> str:
    """The ring seconds ran out: "ok" when the bot may answer (nobody took the call)."""
    info = calls.get(uid)
    if not info:
        return "no"
    with lock:
        # taken, but the member's phone never got bridged (dropped while connecting): the bot answers after all
        stale = info.get("state") == "taken" and time.time() - info.get("taken_at", 0) > 25
        if info.get("state") != "waiting" and not stale:
            return "no"
        info["state"] = "bot"
    notify_staff(info["company_id"], {"type": "call_taken", "uid": uid, "by": "bot"})
    return "ok"


def call_out(ep: str, to: str) -> str:
    """A staff softphone dialed a mobile: "ok:<uid>:c<company>:<number>" through the company's hotline extension."""
    agent = agent_row(ep)
    number = normalize(to)
    if not agent or not number or not phone_allowed(agent["company_id"], agent["user_id"]):
        return "no"
    if not db.one("select 1 as x from sip_settings where company_id = %s and enabled and password is not null",
                  (agent["company_id"],)):
        return "no"
    uid = str(uuid.uuid4())
    row = db.execute("insert into call_logs (company_id, call_ref, caller, caller_normalized, status, started_at, channel,"
                     " direction, answered_by, created_at, updated_at)"
                     " values (%s, %s, %s, %s, 'ringing', now(), 'hotline', 'out', %s, now(), now()) returning id",
                     (agent["company_id"], "out-" + uid, "0" + number[3:], number, agent["user_id"]))
    calls[uid] = {"company_id": agent["company_id"], "caller": number, "at": time.time(), "state": "out",
                  "channel": "hotline", "row_id": row["id"], "agent": agent}
    log.info("call out %s by %s", number, agent["name"])
    return f"ok:{uid}:c{agent['company_id']}:0{number[3:]}"


def ended(uid: str, secs: str = "") -> str:
    """Asterisk hangup handler of a hotline, WhatsApp or outgoing call. Bot calls are saved by save_call instead."""
    info = calls.get(uid)
    if not info:
        return "no"
    with lock:
        state = info.get("state")
        if state == "waiting":
            info["state"] = "missed"
    if state == "waiting":   # the caller hung up while it rang
        db.execute("update call_logs set status = 'ended', ended_at = now(), updated_at = now() where id = %s",
                   (info.get("row_id"),))
        notify_staff(info["company_id"], {"type": "call_taken", "uid": uid, "by": ""})
        calls.pop(uid, None)
    elif state in ("taken", "out"):
        talk = int(float(secs)) if secs.replace(".", "").isdigit() else \
            (int(time.time() - info["taken_at"]) if info.get("taken_at") else 0)
        db.execute("update call_logs set status = 'ended', ended_at = now(), agent_seconds = %s, updated_at = now()"
                   " where id = %s", (talk, info.get("row_id")))
        pool.submit(save_recording, uid, info)
    return "ok"


def save_recording(uid: str, info: dict) -> None:
    """The MixMonitor WAV of a staff call → MP3 next to the bot's recordings, linked on the call_logs row."""
    wav = os.path.join(REC_SPOOL, f"{uid}.wav")
    for _ in range(10):   # MixMonitor closes the file as the channel goes away
        time.sleep(2)
        if os.path.exists(wav) and os.path.getsize(wav) > 44:
            break
    try:
        if os.path.exists(wav) and os.path.getsize(wav) > 8000:
            company = info["company_id"]
            os.makedirs(os.path.join(REC_DIR, str(company)), exist_ok=True)
            rel = f"{company}/{info['row_id']}.mp3"
            r = subprocess.run(["ffmpeg", "-loglevel", "error", "-y", "-i", wav, "-ac", "1", "-b:a", "32k",
                                os.path.join(REC_DIR, rel)], capture_output=True, text=True, timeout=300)
            if r.returncode == 0:
                db.execute("update call_logs set recording_path = %s, updated_at = now() where id = %s", (rel, info["row_id"]))
            else:
                log.error("ffmpeg %s: %s", uid, r.stderr[:300])
    except Exception:
        log.exception("recording %s", uid)
    finally:
        try:
            os.unlink(wav)
        except OSError:
            pass
        calls.pop(uid, None)


def decide(ep: str, caller: str) -> str:
    """A hotline call: "bot:<uid>:<seconds>" (rings in Desk, then the bot answers), "ring:<uid>" (rings in Desk until
    taken) or "ring" (only rings here)."""
    if not ep.startswith("c") or not ep[1:].isdigit():
        return "ring"
    s = db.one("select * from sip_settings where company_id = %s and enabled", (int(ep[1:]),))
    if not s:
        return "ring"
    number = normalize(caller) or caller
    if s["bot_mode"] not in ("test", "live") or number in numbers(s.get("bot_blocked_numbers")) \
            or (s["bot_mode"] == "test" and number not in numbers(s.get("bot_test_numbers"))):
        return ring_only(s["company_id"], caller)   # the bot is off for this caller: staff and office phones only
    return start(s["company_id"], caller, s, int(s.get("bot_delay") or 20))


def decide_wa(to: str, caller: str) -> str:
    """A WhatsApp call to our business number `to`: "bot:..." or "busy" (calling off, or not a test number)."""
    digits = re.sub(r"\D", "", to or "")
    rows = db.all_rows("select company_id, display_phone_number, calling_mode, calling_test_numbers, calling_bot_delay"
                       " from wa_accounts where calling_mode in ('test', 'live') order by id")
    acc = next((a for a in rows if digits and re.sub(r"\D", "", a["display_phone_number"] or "") == digits), None)
    if not acc and len(rows) == 1:
        acc = rows[0]   # Meta's request URI may not carry the number; one number taking calls is unambiguous
    if not acc:
        return "busy"
    number = normalize(caller) or caller
    if acc["calling_mode"] == "test" and number not in numbers(acc["calling_test_numbers"]):
        return "busy"
    # model, length limit and blocked numbers come from the hotline bot's settings when the company has them
    s = db.one("select * from sip_settings where company_id = %s", (acc["company_id"],)) or {}
    if number in numbers(s.get("bot_blocked_numbers")):
        return "busy"
    return start(acc["company_id"], caller, s, min(int(acc["calling_bot_delay"] or 0), 45), "whatsapp")


async def mode_server(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
    try:
        line = (await asyncio.wait_for(reader.readline(), 5)).decode(errors="replace")
        while (await reader.readline()) not in (b"\r\n", b"\n", b""):
            pass
        url = urlparse(line.split(" ")[1] if " " in line else "/")
        q = {k: v[0] for k, v in parse_qs(url.query).items()}
        ep, caller, wa = q.get("ep", ""), q.get("caller", ""), q.get("wa")
        loop = asyncio.get_running_loop()
        # everything but /mode comes from the Asterisk dialplan for staff calls (and the panel for /sync)
        routes = {"/take": (take, q.get("uid", ""), q.get("agent", "")), "/bot": (bot_turn, q.get("uid", "")),
                  "/out": (call_out, q.get("agent", ""), q.get("to", "")),
                  "/ended": (ended, q.get("uid", ""), q.get("secs", "")), "/sync": (lambda: sync_asterisk() or "ok",),
                  # voice reminder calls (engine/voice_ivr.py): the panel's kick, and the zyro-remind dialplan
                  "/remind": (lambda i: voice_ivr.start(int(i)) if i.isdigit() else "no", q.get("id", "")),
                  "/remind-dial": (voice_ivr.dial_plan, q.get("uid", "")),
                  "/remind-dialed": (voice_ivr.dialed, q.get("uid", ""), q.get("status", "")),
                  "/remind-ended": (voice_ivr.ended, q.get("uid", ""))}
        if url.path in routes:
            fn, *args = routes[url.path]
            try:
                body = await loop.run_in_executor(pool, fn, *args)
            except Exception:
                log.exception(url.path)
                body = "no"
        else:
            try:
                body = await (loop.run_in_executor(pool, decide_wa, wa, caller) if wa is not None
                              else loop.run_in_executor(pool, decide, ep, caller))
                if body.startswith("bot:") and body.split(":")[1] in calls and not use_cascade(calls[body.split(":")[1]]):
                    uid = body.split(":")[1]
                    calls[uid]["warm_task"] = asyncio.create_task(warm_up(uid))
            except Exception:
                log.exception("mode")
                body = "ring" if wa is None else "busy"
            log.info("mode %s %s -> %s", f"wa {wa}" if wa is not None else ep, caller, body.split(":")[0])
        writer.write(f"HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: {len(body)}\r\nConnection: close\r\n\r\n{body}".encode())
        await writer.drain()
    finally:
        writer.close()


# --- what the bot did on a call (call_logs.actions / outcome, shown on the panel's কল লগ) ---------------------------
FOREIGN = re.compile(r"[\u0900-\u0963\u0966-\u097F\u0600-\u06FF]")   # Devanagari (not । ॥: Bangla uses them too), Arabic
FOREIGN_HINT = ("(সিস্টেম: কলারের শেষ কথাটা ঠিকমতো শোনা যায়নি, অন্য ভাষার মতো এসেছে। কলার বাংলাতেই বলছেন। এর ওপর ভিত্তি করে "
                "অনুমানে কিছু বলবে না, অভিযোগও খুলবে না; ভদ্রভাবে বলো \"জি, একটু আরেকবার বলবেন?\")")
OUTCOME_LABELS = {"solved": "সমাধান", "info": "তথ্য দেওয়া", "ticket": "টিকেট খোলা", "open_ticket": "আগের টিকেট জানানো",
                  "callback": "কল ব্যাক দরকার", "unclear": "বোঝা যায়নি", "hung_up": "কলার কেটেছেন"}
FLAG_TEXT = {"off_topic": "অপ্রাসঙ্গিক কথা", "wants_person": "মানুষের সাথে কথা বলতে চান", "unsolved": "সমাধান হয়নি",
             "time_up": "সময় শেষ"}


def greet_actions(call) -> None:
    """The first line: who the bot knew the caller to be."""
    prep = call.info.get("prep_result") or {}
    staff = call.info.get("staff")
    if staff:
        call.act("identified", f"স্টাফ/টেকনিশিয়ান: {staff.get('name') or ''}")
    elif prep.get("matched"):
        m = prep["matched"]
        call.act("identified", f"নম্বর মিলে চেনা গেছে: {m['customer_id']} {m['name']}".strip(), customer_id=m["customer_id"])
    elif prep:
        call.act("identified", "নম্বরে কোনো কাস্টমার পাওয়া যায়নি", ok=False)


def tool_action(call, name: str, args: dict, result: dict) -> None:
    """One line per tool the bot used, with its result."""
    r = result or {}
    if r.get("error"):
        call.act(name, f"{name}: ব্যর্থ ({str(r['error'])[:80]})", ok=False)
    elif name == "lookup_customer":
        q = args.get("id_or_mobile", "")
        if r.get("found"):
            c = r.get("customer") or {}
            cid = str(c.get("customer_id") or c.get("id") or q)
            call.act("lookup", f"{q} খোঁজা হলো → পাওয়া গেছে: {cid} {c.get('name') or ''}".strip(), customer_id=cid)
        else:
            call.act("lookup", f"{q} খোঁজা হলো → পাওয়া যায়নি", ok=False)
    elif name == "create_complaint":
        cid, cat = args.get("customer_id", ""), args.get("category") or ""
        if r.get("already_open"):
            call.act("open_ticket", f"আগে থেকে খোলা টিকেট #{r.get('ticket_no')} জানানো হলো", customer_id=cid, ticket=r.get("ticket_no"))
        elif r.get("ok"):
            call.act("ticket", f"টিকেট #{r.get('ticket_no') or '?'} খোলা হলো" + (f" ({cat})" if cat else "")
                     + f": {args.get('problem', '')[:80]}", customer_id=cid, ticket=r.get("ticket_no"))
        else:
            call.act("ticket", f"টিকেট খোলা যায়নি: {r.get('note') or ''}", ok=False, customer_id=cid)
    elif name == "request_callback":
        flag = args.get("flag") if args.get("flag") in FLAGS else "unsolved"
        call.act("callback", f"স্টাফ ফোন করবেন ({FLAG_TEXT.get(flag, flag)}): {args.get('reason') or ''}"[:200])
    elif name == "solve_ticket":
        call.act("solved", f"{args.get('customer_id')}: টিকেট সমাধান: {r.get('result') or ''}"[:200], ok=bool(r.get("ok")),
                 customer_id=args.get("customer_id"))
    elif name == "reconnect_line":
        call.act("reconnect", f"{args.get('customer_id')}: লাইন আবার চালুর চেষ্টা: {r.get('result') or r.get('note') or ''}"[:200],
                 ok=bool(r.get("ok", True)), customer_id=args.get("customer_id"))
    elif name == "switch_line":
        call.act("switch", f"{args.get('customer_id')}: লাইন {'চালু' if args.get('action') == 'enable' else 'বন্ধ'}: "
                 f"{r.get('result') or ''}"[:200], customer_id=args.get("customer_id"))


def outcome_of(call, error: str | None = None) -> tuple[str, str]:
    """The call's result in one word and one line, from what the bot did (no AI)."""
    acts, secs = call.actions, int(time.time() - call.started)
    kinds = {a["kind"] for a in acts if a.get("ok", True)}
    if call.ended_by == "time_up" or call.flag == "time_up":
        call.act("end", "সময় শেষ, বট কল শেষ করেছে")
    elif call.ended_by == "bot":
        call.act("end", "বট বিদায় জানিয়ে কল শেষ করেছে")
    else:
        call.act("end", "কলার কল কেটে দিয়েছেন" + (f" (এরর: {error[:80]})" if error else ""), ok=not error)
    known = next((a for a in acts if a.get("customer_id") and a.get("ok", True)), None)
    who = f" · কাস্টমার {known['customer_id']}" if known else ""
    pick = lambda k: next(a for a in reversed(acts) if a["kind"] == k and a.get("ok", True))
    prep_tickets = re.findall(r'"no":"?(\d+)', (call.info.get("prep_result") or {}).get("prompt", ""))
    bot_text = " ".join(x["text"] for x in call.turns if x["who"] == "bot").translate(BN_DIGITS)
    if "solved" in kinds or "switch" in kinds:
        out, line = "solved", pick("solved" if "solved" in kinds else "switch")["text"]
    elif "ticket" in kinds:
        out, line = "ticket", pick("ticket")["text"]
    elif "open_ticket" in kinds or any(n in bot_text for n in prep_tickets):
        out = "open_ticket"
        line = pick("open_ticket")["text"] if "open_ticket" in kinds else \
            "খোলা টিকেট #" + next(n for n in prep_tickets if n in bot_text) + " জানানো হলো"
    elif "callback" in kinds or call.callback:
        out, line = "callback", call.callback or pick("callback")["text"]
    elif any(a["kind"] == "unclear" for a in acts) and len([x for x in call.turns if x["who"] == "caller"]) <= 3:
        out, line = "unclear", "কলারের কথা বোঝা যায়নি"
    elif call.ended_by is None and (secs < 20 or not known):
        out, line = "hung_up", "কলার কেটে দিয়েছেন" + (f" ({secs} সে.)" if secs < 20 else "")
    else:
        out, line = "info", "প্রশ্নের উত্তর/তথ্য দেওয়া হয়েছে"
    return out, f"{OUTCOME_LABELS[out]}: {line}{who}"


# --- one answered call -------------------------------------------------------------------------
class Call:
    def __init__(self, uid: str, info: dict, reader, writer):
        self.uid, self.info, self.reader, self.writer = uid, info, reader, writer
        self.out = bytearray()            # bot audio waiting to be played (8 kHz)
        self.caller_track = bytearray()   # what the caller said, 8 kHz, from the answer on
        self.bot_track = bytearray()      # what we played, same clock
        self.turns: list[dict] = []
        self.callback: str | None = None
        self.flag: str | None = None
        self.hangup_after_drain = False
        self.done = asyncio.Event()
        self.up_state = self.down_state = None
        self.usage = {"audio_in": 0, "text_in": 0, "audio_out": 0, "text_out": 0, "cached": 0}
        self.started = time.time()
        self.tools_used: set[str] = set()
        self.end_refused = False
        self.sent_bytes = self.heard_bytes = 0
        # every number the bot may say: the caller's data, tool results and what the caller said (see check_numbers)
        self.known: set[str] = set(numbers_in((info.get("prep_result") or {}).get("prompt", "")))
        self.bot_turn_text = ""
        self.corrections = 0
        self.actions: list[dict] = []     # what the bot did, for the call log (see outcome_of)
        self.ended_by: str | None = None  # bot | time_up; None = the caller hung up
        self.foreign_turn, self.foreign_hints = False, 0
        greet_actions(self)

    def promised_without_tool(self) -> bool:
        """The bot said "টিকেট করছি / জানাচ্ছি" but never called create_complaint or request_callback (review
        2026-10-24, calls 103 and 104: no ticket was opened)."""
        if self.info.get("staff") or {"create_complaint", "request_callback"} & self.tools_used:
            return False
        return any(x["who"] == "bot" and PROMISE.search(x["text"]) for x in self.turns)

    def add_usage(self, u: dict) -> None:
        """Live bills audio and text input at different prices (text = the prompt and tool results), so both are kept."""
        def split(total, details):
            text = sum(int(d.get("tokenCount") or 0) for d in details or [] if d.get("modality") == "TEXT")
            return (max(int(total or 0) - text, 0), text) if details else (int(total or 0), 0)
        a, t = split(u.get("promptTokenCount"), u.get("promptTokensDetails"))
        self.usage["audio_in"] += a
        self.usage["text_in"] += t
        a, t = split(u.get("responseTokenCount") or u.get("candidatesTokenCount"),
                     u.get("responseTokensDetails") or u.get("candidatesTokensDetails"))
        self.usage["audio_out"] += a
        self.usage["text_out"] += t
        self.usage["cached"] += int(u.get("cachedContentTokenCount") or 0)

    def act(self, kind: str, text: str, ok: bool = True, **extra) -> None:
        self.actions.append({"at": round(time.time() - self.started, 1), "kind": kind, "text": text, "ok": ok, **extra})

    def say(self, who: str, text: str) -> None:
        if not text:
            return
        if self.turns and self.turns[-1]["who"] == who:
            self.turns[-1]["text"] += text
        else:
            self.turns.append({"who": who, "text": text, "at": round(time.time() - self.started, 1)})

    async def send_frame(self, kind: int, payload: bytes = b"") -> None:
        self.writer.write(bytes([kind]) + len(payload).to_bytes(2, "big") + payload)
        await self.writer.drain()

    async def from_caller(self, ws) -> None:
        quiet, paused, held = 0, False, deque(maxlen=PREROLL)
        while not self.done.is_set():
            head = await self.reader.readexactly(3)
            n = int.from_bytes(head[1:], "big")
            payload = await self.reader.readexactly(n) if n else b""
            if head[0] == 0x00 or head[0] == 0xFF:
                break
            if head[0] != 0x10:
                continue
            self.caller_track += payload
            self.heard_bytes += len(payload)
            if SILENCE_RMS and audioop.rms(payload, 2) < SILENCE_RMS:
                quiet += 1
                if quiet > SILENCE_HANG:
                    if not paused:   # Gemini's own speech detection is told the caller's audio stopped
                        paused = True
                        await ws.send(json.dumps({"realtimeInput": {"audioStreamEnd": True}}))
                    held.append(payload)
                    continue
            else:
                quiet = 0
                if paused:
                    paused = False
                    payload = b"".join(held) + payload
                    held.clear()
            self.sent_bytes += len(payload)
            pcm16, self.up_state = audioop.ratecv(payload, 2, 1, 8000, 16000, self.up_state)
            await ws.send(json.dumps({"realtimeInput": {"audio": {"data": base64.b64encode(pcm16).decode(),
                                                                  "mimeType": "audio/pcm;rate=16000"}}}))
        self.done.set()

    async def check_numbers(self, ws) -> None:
        """After each bot turn: a number of 3+ digits the bot said that is in none of the caller's data, tool results
        or the caller's own words was made up (reviews 2026-10-21/24: ticket "1234" before the tool answered, dues
        for a caller not found). The bot is told at once to take it back; twice per call at most."""
        said, self.bot_turn_text = self.bot_turn_text, ""
        if not FACTS.search(said):   # only money and ticket numbers; an ID read back to the caller is fine
            return
        bad = [x for x in numbers_in(said) if not any(x in k for k in self.known)]
        if not bad or self.corrections >= 2:
            return
        self.corrections += 1
        log.warning("call %s: bot said unknown number(s) %s", self.uid, bad)
        self.act("corrected", f"যাচাই হয়নি এমন অঙ্ক বলেছিল ({', '.join(bad)}), শুধরে নিতে বলা হলো", ok=False)
        self.say("bot", f" [যাচাই হয়নি: {', '.join(bad)}]")
        await ws.send(json.dumps({"clientContent": {"turns": [{"role": "user", "parts": [{"text":
            f"(সিস্টেম: তুমি এইমাত্র {', '.join(bad)} বলেছ, কিন্তু এই অঙ্ক কাস্টমার তথ্যে বা টুলের ফলে নেই। কলারকে এক বাক্যে "
            "বলো আগের অঙ্কটা ঠিক না, তারপর দরকার হলে lookup_customer বা create_complaint ডেকে শুধু ফল থেকে বলো।)"}]}],
            "turnComplete": True}}, ensure_ascii=False))

    async def to_caller(self) -> None:
        """Every 20 ms one frame to Asterisk (silence when the bot is quiet), the same frame onto the recording."""
        nxt = time.monotonic()
        while not self.done.is_set():
            frame = bytes(self.out[:FRAME])
            del self.out[:FRAME]
            if len(frame) < FRAME:
                if self.hangup_after_drain:
                    await asyncio.sleep(0.4)
                    await self.send_frame(0x00)
                    self.done.set()
                    return
                frame = frame + b"\x00" * (FRAME - len(frame))
            self.bot_track += frame
            await self.send_frame(0x10, frame)
            nxt += 0.02
            await asyncio.sleep(max(0, nxt - time.monotonic()))

    async def run_tool(self, name: str, args: dict) -> dict:
        """One tool the bot called (Gemini Live or the cascade's Claude), with its line in the call log."""
        result = {"ok": True}
        t = (self.info.get("prep_result") or {}).get("tenant")
        staff = self.info.get("staff")
        loop = asyncio.get_running_loop()
        if name in FILLERS and fillers.get(name):
            self.out += fillers[name]   # the caller hears "একটু অপেক্ষা করুন..." at once, not silence
        try:
            if name == "request_callback":
                self.callback = (args.get("reason") or "কল ব্যাক চেয়েছেন")[:300]
                self.flag = args.get("flag") if args.get("flag") in FLAGS else "unsolved"
            elif name == "end_call":
                if not self.end_refused and self.promised_without_tool():
                    self.end_refused = True   # once: never keep a caller on the line for this
                    result = {"ok": False, "note": "কল এখনো শেষ হয়নি। তুমি টিকেট বা অফিসে জানানোর কথা বলেছ কিন্তু "
                              "create_complaint বা request_callback ডাকোনি। এখনই সেটা ডাকো, ফল এলে অভিযোগ নম্বর "
                              "বলো, তারপর বিদায় জানিয়ে end_call।"}
                else:
                    self.hangup_after_drain = True
                    self.ended_by = self.ended_by or "bot"
            elif name == "lookup_customer":
                result = await loop.run_in_executor(pool, lookup_customer, t, args.get("id_or_mobile", ""), bool(staff))
            elif name == "search_faq":
                result = await loop.run_in_executor(pool, search_faq, t, args.get("query", ""))
            elif name == "create_complaint":
                result = await loop.run_in_executor(pool, create_complaint, t, self.info["caller"],
                                                    args.get("customer_id", ""), args.get("problem", ""),
                                                    args.get("category"))
            elif name == "reconnect_line" and not staff:
                channel = (calls.get(self.uid) or {}).get("channel") or "hotline"
                result = await loop.run_in_executor(pool, reconnect_line, t, self.info["caller"],
                                                    args.get("customer_id", ""), channel)
            elif name in ("solve_ticket", "switch_line") and staff:
                result = await loop.run_in_executor(pool, staff_action, t, staff, name, args)
                if result.get("ticket_no"):
                    self.say("bot", f" [টিকিট #{result['ticket_no']}]")
        except Exception as e:
            log.exception("tool %s", name)
            result = {"error": str(e)[:200]}
        if name:
            self.tools_used.add(name)
            tool_action(self, name, args, result)
        self.known.update(numbers_in(json.dumps(result, ensure_ascii=False, default=str), 1))
        log.info("call %s tool %s -> %s", self.uid, name, str(result)[:120])
        return result

    async def from_gemini(self, ws) -> None:
        async for raw in ws:
            m = json.loads(raw)
            sc = m.get("serverContent") or {}
            for p in (sc.get("modelTurn") or {}).get("parts", []):
                data = (p.get("inlineData") or {}).get("data")
                if data:
                    pcm8, self.down_state = audioop.ratecv(base64.b64decode(data), 2, 1, 24000, 8000, self.down_state)
                    self.out += pcm8
            if sc.get("interrupted"):
                self.out.clear()           # the caller started talking: stop at once
            heard = (sc.get("inputTranscription") or {}).get("text", "")
            self.known.update(numbers_in(heard, 1))
            self.say("caller", heard)
            if heard and FOREIGN.search(heard) and not self.foreign_turn and self.foreign_hints < 3:
                # Live's transcription took the caller's (regional) Bangla for Hindi (Zisan 2026-10-26, call from
                # 01911629722: a ticket number was made up next). Added to the context without a turn of its own.
                self.foreign_turn = True
                self.foreign_hints += 1
                self.act("unclear", "কলারের কথা অন্য ভাষার হরফে এসেছে (ঠিক বোঝা যায়নি), বটকে আবার জিজ্ঞেস করতে বলা হলো", ok=False)
                await ws.send(json.dumps({"clientContent": {"turns": [{"role": "user", "parts": [{"text": FOREIGN_HINT}]}],
                                          "turnComplete": False}}, ensure_ascii=False))
            spoken = (sc.get("outputTranscription") or {}).get("text", "")
            if SPOKEN_TOOL.search(spoken):
                # it said "end_call" aloud instead of calling the tool (call 106 hung on until the caller hung up)
                if re.search(r"end[_ ]?call", spoken, re.I) and not self.promised_without_tool():
                    self.hangup_after_drain = True
                    self.ended_by = self.ended_by or "bot"
                spoken = SPOKEN_TOOL.sub("", spoken)
            self.say("bot", spoken)
            self.bot_turn_text += spoken
            if spoken:
                self.foreign_turn = False
            if sc.get("turnComplete") or sc.get("interrupted"):
                await self.check_numbers(ws)
            u = m.get("usageMetadata")
            if u:
                self.add_usage(u)
            for fc in (m.get("toolCall") or {}).get("functionCalls", []):
                name, args = fc.get("name"), fc.get("args") or {}
                result = await self.run_tool(name, args)
                await ws.send(json.dumps({"toolResponse": {"functionResponses": [
                    {"id": fc.get("id"), "name": name, "response": json.loads(brief(result))}]}},
                    ensure_ascii=False))
        self.done.set()


def use_cascade(info: dict) -> bool:
    """The cheaper cascade bot (engine/voice_cascade.py) answers this call: হটলাইন (SIP) → বট চালানোর পদ্ধতি."""
    s = info.get("settings") or {}
    if s.get("bot_engine") != "cascade":
        return False
    return s.get("cascade_scope") == "all" or (normalize(info.get("caller") or "") or info.get("caller")) \
        in numbers(s.get("bot_test_numbers"))


async def warm_up(uid: str) -> None:
    """While the call rings: the prompt is built and the Live session opened, so the bot greets the moment it answers
    (review 2026-10-24, call 108: 14 s of silence first). The session is closed unused when staff take the call or
    the caller hangs up; an open session with no turns costs nothing."""
    info = calls.get(uid)
    if not info:
        return
    try:
        prep = await asyncio.wrap_future(info["prep"])
        info["prep_result"] = prep
        info["warm"] = await open_model(info, prep)
    except Exception as e:
        log.warning("call %s: warm-up failed (%s), the bot connects on answer", uid, e)
        return
    for _ in range(120):   # the bot answers after at most 45 s of ringing
        await asyncio.sleep(1)
        if uid not in calls or info.get("state") in ("taken", "missed") or info.get("warm") is None:
            break
    w = info.pop("warm", None)
    if w:
        try:
            await w[0].close()
        except Exception:
            pass


async def take_warm(info: dict):
    """The session warm_up opened for this call, when it is still open (else None: connect now)."""
    task = info.get("warm_task")
    if task and not task.done() and "warm" not in info:
        await asyncio.wait({task}, timeout=3)   # opening right now: a few seconds at most
    w = info.pop("warm", None)
    if w and getattr(w[0], "close_code", None) is None:
        return w
    return None


def context_window(prompt: str) -> dict:
    """Old turns go once the call's context passes CONTEXT_TRIGGER, down to CONTEXT_TARGET (env, 6000/3000 since
    2026-10-28: most of a Live call's cost is old audio billed again each turn). Never below the prompt itself plus
    room for the last turns, or the bot would forget what the caller just said (rough Bangla token count: chars/2)."""
    base = len(prompt) // 2
    trigger = max(CONTEXT_TRIGGER, base + 3000)
    return {"triggerTokens": trigger, "slidingWindow": {"targetTokens": min(max(CONTEXT_TARGET, base + 1200), trigger - 1000)}}


async def open_model(info: dict, prep: dict):
    """The Live session on the company's chosen model; when that model refuses our setup (e.g. the native-audio models
    reject languageCode bn-IN), on the default one, so a bad pick in the settings never leaves calls unanswered."""
    model = (info.get("settings") or {}).get("bot_model") or DEFAULT_MODEL
    try:
        ws, key_id = await open_live(info["company_id"], model, prep)
    except Exception as e:
        if model == DEFAULT_MODEL:
            raise
        log.warning("company %s: model %s did not open (%s), using %s", info["company_id"], model, e, DEFAULT_MODEL)
        model = DEFAULT_MODEL
        ws, key_id = await open_live(info["company_id"], model, prep)
    info["model"] = model
    return ws, key_id


async def open_live(company_id: int, model: str, prep: dict):
    """A Gemini Live session on the first of the company's Gemini keys that accepts it."""
    keys = db.all_rows("select id, api_key from ai_keys where company_id = %s and provider = 'gemini'"
                       " and (rate_limited_until is null or rate_limited_until <= now()) order by id", (company_id,))
    from engine.agent import job_key
    picked = job_key(company_id, "call_live")   # the key picked for calls first (panel: কোন কাজে কোন key)
    if picked:
        keys.sort(key=lambda k: k["id"] != picked["id"])
    last = None
    for k in keys:
        try:
            ws = await websockets.connect(LIVE_URL, additional_headers={"x-goog-api-key": db.decrypt(k["api_key"])},
                                          max_size=None, open_timeout=8)
            await ws.send(json.dumps({"setup": {
                "model": f"models/{model}",
                # languageCode: the caller's speech is heard as Bangla (without it some turns came out as Hindi)
                "generationConfig": {"responseModalities": ["AUDIO"], "speechConfig": {"languageCode": "bn-IN",
                    "voiceConfig": {"prebuiltVoiceConfig": {"voiceName": prep["voice"]}}}},
                "systemInstruction": {"parts": [{"text": prep["prompt"]}]},
                "tools": STAFF_TOOLS if prep.get("staff") else TOOLS,
                "inputAudioTranscription": {}, "outputAudioTranscription": {},
                # long calls: the oldest turns go once the context passes the trigger (the prompt always stays)
                "contextWindowCompression": context_window(prep["prompt"]),
            }}))
            first = json.loads(await asyncio.wait_for(ws.recv(), 8))
            if "setupComplete" in first:
                return ws, k["id"]
            last = str(first)[:200]
            await ws.close()
        except Exception as e:
            last = f"{type(e).__name__}: {e}"
            log.warning("company %s gemini key %s: %s", company_id, k["id"], last)
    raise RuntimeError(f"no Gemini key opened a Live session: {last}")


def save_call(call: Call, company_id: int, caller: str, model: str, key_id: int | None, error: str | None) -> None:
    """MP3 of both sides, transcript and the outcome on the call_logs row (the one the popup made, or a new one)."""
    number = normalize(caller)
    channel = (calls.get(call.uid) or {}).get("channel") or "hotline"
    # the popup's row for this very call: same caller, started when /mode was asked (not just the caller's latest call);
    # WhatsApp calls have no popup row
    rang = (calls.get(call.uid) or {}).get("at") or call.started
    row_id = (calls.get(call.uid) or {}).get("row_id")
    row = {"id": row_id} if row_id else db.one("select id from call_logs where company_id = %s and caller = %s and not bot_answered and channel = 'hotline'"
                 " and abs(extract(epoch from started_at) - %s) < 90"
                 " order by abs(extract(epoch from started_at) - %s) limit 1",
                 (company_id, caller[:80], rang, rang)) if channel == "hotline" else None
    if not row:
        row = db.execute("insert into call_logs (company_id, call_ref, caller, caller_normalized, status, started_at, channel, created_at, updated_at)"
                         " values (%s, %s, %s, %s, 'ended', now() - make_interval(secs => %s), %s, now(), now()) returning id",
                         (company_id, ("wa-" if channel == "whatsapp" else "bot-") + call.uid, caller[:80], number,
                          time.time() - call.started, channel))
    rel = None
    n = max(len(call.caller_track), len(call.bot_track))
    if n > FRAME * 25:   # more than half a second
        a = bytes(call.caller_track) + b"\x00" * (n - len(call.caller_track))
        b = bytes(call.bot_track) + b"\x00" * (n - len(call.bot_track))
        mixed = audioop.add(a, b, 2)
        os.makedirs(os.path.join(REC_DIR, str(company_id)), exist_ok=True)
        rel = f"{company_id}/{row['id']}.mp3"
        wav = os.path.join(STATE_DIR, f"{call.uid}.wav")
        with wave.open(wav, "wb") as w:
            w.setnchannels(1)
            w.setsampwidth(2)
            w.setframerate(8000)
            w.writeframes(mixed)
        r = subprocess.run(["ffmpeg", "-loglevel", "error", "-y", "-i", wav, "-ac", "1", "-b:a", "32k",
                            os.path.join(REC_DIR, rel)], capture_output=True, text=True, timeout=120)
        os.unlink(wav)
        if r.returncode != 0:
            log.error("ffmpeg: %s", r.stderr[:300])
            rel = None
    # ended_at = now(): the caller ID popup already wrote the end of the ringing on a hotline row (call 232)
    outcome, outcome_text = outcome_of(call, error)
    db.execute("""update call_logs set status = 'ended', ended_at = now(), bot_answered = true,
                    bot_seconds = %s, recording_path = %s, transcript = %s, callback_reason = %s, bot_flag = %s,
                    bot_error = %s, actions = %s, outcome = %s, outcome_text = %s, updated_at = now() where id = %s""",
               (int(time.time() - call.started), rel, json.dumps(call.turns, ensure_ascii=False), call.callback,
                call.flag, (error or "")[:300] or None, json.dumps(call.actions, ensure_ascii=False), outcome,
                outcome_text[:300], row["id"]))
    try:
        from engine import agent
        info = calls.get(call.uid) or {}
        t = (info.get("prep_result") or {}).get("tenant")
        if t and not getattr(call, "cascade", False):   # the cascade records each of its steps itself
            u = call.usage
            agent.record_usage(t, key_id, "gemini", model, "voice_call", None,
                               {"input": u["audio_in"], "output": u["audio_out"]}, error=error)
            if u["text_in"] or u["text_out"]:   # the prompt and tool results, billed at the text price
                agent.record_usage(t, key_id, "gemini", f"{model} (text)", "voice_call_text", None,
                                   {"input": u["text_in"], "output": u["text_out"], "cached": u["cached"]}, error=error)
    except Exception:
        log.exception("usage")


async def audio_server(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
    uid, ws, error, key_id, call, info = None, None, None, None, None, {}
    try:
        head = await asyncio.wait_for(reader.readexactly(3), 5)
        payload = await reader.readexactly(int.from_bytes(head[1:], "big"))
        if head[0] != 0x01:
            return
        uid = str(uuid.UUID(bytes=payload))
        info = calls.get(uid)
        if not info:
            log.warning("audiosocket: unknown call %s", uid)
            return
        loop = asyncio.get_running_loop()
        prep = await loop.run_in_executor(None, info["prep"].result, 15)
        info["prep_result"] = prep
        if use_cascade(info):
            from engine import voice_cascade
            await voice_cascade.run(uid, info, reader, writer)
            uid = None   # saved there
            return
        model = info["settings"].get("bot_model") or DEFAULT_MODEL
        info["model"] = model
        call = Call(uid, info, reader, writer)
        ws, key_id = await take_warm(info), None
        if ws:
            ws, key_id = ws
        else:
            ws, key_id = await open_model(info, prep)
        await ws.send(json.dumps({"clientContent": {"turns": [{"role": "user", "parts": [{"text":
            ("(কল ধরা হয়েছে। শুধু বলো \"জি ভাই, বলুন\"।)" if prep.get("staff") else
             "(কল ধরা হয়েছে। প্রথমে কোম্পানির নাম বলে শুভেচ্ছা জানাও, জানাও যে কলটি রেকর্ড করা হচ্ছে, তারপর জিজ্ঞেস করো কীভাবে সাহায্য করতে পারো।)")}]}],
            "turnComplete": True}}))
        # Asterisk usually just closes the socket on hangup (no 0x00 frame): whichever side stops first ends the call
        tasks = [asyncio.create_task(c) for c in (call.from_caller(ws), call.to_caller(), call.from_gemini(ws),
                                                    call.done.wait())]
        limit = min(int(info["settings"].get("bot_max_seconds") or DEFAULT_MAX_SECONDS), MAX_CALL)
        finished, _ = await asyncio.wait(tasks, timeout=max(limit - 25, 10), return_when=asyncio.FIRST_COMPLETED)
        if not finished:
            # time is nearly up: the bot says a staff member will call and hangs up itself; cut it 45 s later at most
            call.flag = call.flag or "time_up"
            call.ended_by = "time_up"
            await ws.send(json.dumps({"clientContent": {"turns": [{"role": "user", "parts": [{"text": WRAP_UP}]}],
                                      "turnComplete": True}}, ensure_ascii=False))
            finished, _ = await asyncio.wait(tasks, timeout=45, return_when=asyncio.FIRST_COMPLETED)
            if not finished:
                error = "max call length"
        call.done.set()
        for t in tasks:
            t.cancel()
        log.info("call %s from %s: %ss with the bot, %.0f%% of the caller's audio sent", uid, info["caller"],
                 int(time.time() - call.started), 100 * call.sent_bytes / max(call.heard_bytes, 1))
    except (asyncio.IncompleteReadError, ConnectionError):
        pass
    except Exception as e:
        error = f"{type(e).__name__}: {e}"
        log.exception("call %s", uid)
    finally:
        if ws:
            try:
                await ws.close()
            except Exception:
                pass
        try:
            # Asterisk 20's AudioSocket can miss a bare socket close: the WhatsApp call of 2026-10-24 08:05 stayed up
            # 29 h after the bot had finished. A hangup frame first ends the dialplan app for sure.
            writer.write(b"\x00\x00\x00")
            await asyncio.wait_for(writer.drain(), 2)
        except Exception:
            pass
        try:
            writer.close()
        except Exception:
            pass
        if uid and info:
            if call is None:
                call = Call(uid, info, reader, writer)
            await asyncio.get_running_loop().run_in_executor(
                pool, save_call, call, info["company_id"], info["caller"], info.get("model", DEFAULT_MODEL), key_id, error)
            calls.pop(uid, None)


def load_fillers() -> None:
    """The filler lines as 8 kHz PCM, made once with Gemini TTS (same voice as the bot) and kept in STATE_DIR."""
    import hashlib
    from engine import agent
    for name, text in FILLERS.items():
        if fillers.get(name):
            continue
        path = os.path.join(STATE_DIR, f"filler-{hashlib.sha1(text.encode()).hexdigest()[:10]}.pcm")
        if not os.path.exists(path):
            row = db.one("select company_id from sip_settings where bot_mode in ('test', 'live') union"
                         " select company_id from wa_accounts where calling_mode in ('test', 'live') order by company_id limit 1")
            t = tenant_for(row["company_id"]) if row else None
            ogg = agent.speak(t, text) if t else None
            if not ogg:
                continue
            pcm = subprocess.run(["ffmpeg", "-loglevel", "error", "-i", "pipe:0", "-f", "s16le", "-ac", "1", "-ar", "8000", "pipe:1"],
                                 input=ogg, capture_output=True, timeout=60).stdout
            if not pcm:
                continue
            with open(path, "wb") as f:
                f.write(pcm)
        fillers[name] = open(path, "rb").read()
        log.info("filler %s: %.1fs", name, len(fillers[name]) / 16000)


async def housekeeping() -> None:
    while True:
        try:
            await asyncio.get_running_loop().run_in_executor(pool, load_fillers)
        except Exception:
            log.exception("fillers")
        try:
            await asyncio.get_running_loop().run_in_executor(pool, sync_asterisk)
        except Exception:
            log.exception("asterisk sync")
        for uid, c in list(calls.items()):
            # staff calls can run long; their /ended still needs the entry
            if time.time() - c["at"] > (4 * 3600 if c.get("state") in ("taken", "out") else MAX_CALL + 300):
                calls.pop(uid, None)
        await asyncio.sleep(30)


async def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(name)s: %(message)s")
    os.makedirs(STATE_DIR, exist_ok=True)
    voice_ivr.bind(sys.modules[__name__])
    a = await asyncio.start_server(mode_server, "127.0.0.1", MODE_PORT)
    b = await asyncio.start_server(audio_server, "127.0.0.1", AUDIO_PORT)
    c = await asyncio.start_server(voice_ivr.agi_server, "127.0.0.1", voice_ivr.AGI_PORT)
    log.info("voice bot: /mode on %s, AudioSocket on %s, reminder calls (FastAGI) on %s", MODE_PORT, AUDIO_PORT, voice_ivr.AGI_PORT)
    asyncio.create_task(voice_ivr.queue_loop())
    async with a, b, c:
        await housekeeping()


if __name__ == "__main__":
    asyncio.run(main())
