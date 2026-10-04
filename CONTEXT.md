# Zyro ISP Support — Context

শুরু: 2026-10-04। প্রতিটা কাজ শেষে এই ফাইল আপডেট করতে হবে (কী বদলেছে, কেন, বাকি কাজ)।

## সম্পর্কিত রেফারেন্স
- ISP নেটওয়ার্ক (ASR920, MikroTik CLNBD/CLN-3/CLN-4): `/var/www/hybrid-stack/asr920_router_context.md` (git-ignored, ক্রেডেনশিয়াল আছে, কখনো এই রিপোতে কপি করা যাবে না)
- Zyro_log ও auto-block: `/var/www/hybrid-stack/logserver/`
- Captive portal: `/var/www/captive-portal/context.md`

## নিয়ম
- কোনো পাসওয়ার্ড/টোকেন/.env রিপোতে না (`.gitignore` দেখুন)।
- সার্ভারের `ufw` ব্যবহারকারীর স্পষ্ট নির্দেশ ছাড়া টাচ করা যাবে না।

## স্ট্যাটাস
- রিপো তৈরি ও `origin` (`rzzisan/zyro_isp_support`) সংযুক্ত। এখনো কোনো কোড নেই।

## বিলিং গবেষণা (2026-10-04)
- বিলিং: ISP Digital v8.2.4 (SoftifyBD), https://centurylink.ispdigital.cloud — ASP.NET MVC, অফিসিয়াল API নেই, ক্যাপচা নেই।
- লগইন: POST `/Account/LoginChecker` (`__RequestVerificationToken`, `Username`, `Password`, `VmAuthTracer.*`)।
- লগইনের পর JSON endpoint (DataTables; `start`, `length`, `search[value]` = মোবাইল/শেষ ৮ ডিজিট/PPPoE username):
  - `/Customer/AjaxCustomerList` — নাম, মোবাইল, username, প্যাকেজ, মাসিক বিল, Status, IsOnline, সার্ভার, জোন, MAC
  - `/Billing/AjaxCustomerBillList` — PayabaleBill, PaidAmount, BalanceDue, PaymentDate
  - `/ClientSupport/AjaxDailyComplainList` — টিকেট
- সতর্কতা: রেসপন্সে PPPoE পাসওয়ার্ড plaintext আসে, বট কখনো দেখাবে না/লগ করবে না।
- বাকি: WhatsApp অপশন ঠিক করা, read-only স্টাফ ইউজার বানানো, টিকেট তৈরির endpoint ম্যাপ করা।
## সিমুলেশন চ্যাট (2026-10-04)
- `training/simulations/2026-10-04-whatsapp-support-simulations.md`: ৪টি অনুমোদিত বট কথোপকথন (লাইভ ডেটা, যুক্তি, উত্তর) + বটের নিয়ম। AI ট্রেনিং/প্রম্পট উদাহরণ। ব্যবহারকারী git-এ রাখার অনুমতি দিয়েছেন।
- টেস্ট টিকেট #57237 (bp.apon, SMS off) খোলা হয়েছিল, ticket create endpoint যাচাই করতে।
- লাইভ single-customer endpoint: `GetCustomerOtherData/{headerId}`, `customer/GetOLTInfo?macAddress=`, `Customer/AjaxReceivedHistory/{headerId}`; ticket create: POST `/ClientSupport/DailyComplainList`।

## WhatsApp webhook (2026-10-04)
- Meta: portfolio Zareen Natural Foods (verified), app **Zyrotech BSOL** (1900768904642203, Live), Independent Tech Provider অনবোর্ডিং শুরু; advanced permission App Review "In review"। লক্ষ্য: বর্তমান কোম্পানি নম্বর Coexistence মোডে।
- `app/main.py` (FastAPI): `GET /webhook` verify (WA_VERIFY_TOKEN), `POST /webhook` X-Hub-Signature-256 যাচাই (META_APP_SECRET না থাকলে সব POST 403), সব event `data/messages.db`-এ (events, messages; `smb_message_echoes` = Business অ্যাপ থেকে পাঠানো)। `handle_message` এখনো শুধু লগ করে।
- সার্ভিস: `zyro-support.service` (uvicorn 127.0.0.1:8993), nginx `support.zyrotechbd.com` (wildcard cert), www → redirect। ফাইল `deploy/`-এ।
- `.env` (600, git-ignored): WA_VERIFY_TOKEN জেনারেট করা; META_APP_SECRET, WA_ACCESS_TOKEN, WA_PHONE_NUMBER_ID, ISPDIGITAL_* বাকি। নমুনা `.env.example`।
- python3.12-venv apt দিয়ে ইনস্টল করা হয়েছে, venv `.venv/`।
- বাকি: META_APP_SECRET বসানো, Meta-তে webhook URL/verify token ও `messages` + `smb_message_echoes` subscribe, Embedded Signup লিংক, বিলিং লুকআপ + AI রিপ্লাই।

## বট: বিলিং লুকআপ + AI খসড়া (2026-10-04)
- `app/ispdigital.py`: bot ইউজার `supportassistant` দিয়ে লগইন (ব্রাউজারের মতো header + VmAuthTracer ঘর না দিলে প্যানেল logoff করে দেয়)। `find_customer_by_whatsapp` (8801… → 01…), `diagnose` (বিল → ONU → PPPoE)। লাইভ টেস্ট ৩টি সিমুলেশন নম্বরে পাস।
- `app/agent.py`: Claude (`claude-opus-5-5`, effort low, server-side fallbacks "default") দিয়ে বাংলা খসড়া উত্তর; system prompt = সিমুলেশনের নিয়ম।
- `app/main.py`: নতুন মেসেজ → আলাদা thread-এ লুকআপ + খসড়া → `drafts` টেবিল। `BOT_MODE=shadow` = কিছু পাঠায় না। Meta retry ডুপ্লিকেট বাদ। echo মেসেজে `from_number` = কাস্টমারের নম্বর (`to`)।
- বাকি: `ANTHROPIC_API_KEY` (.env), আসল নম্বর Coexistence-এ যুক্ত, খসড়া দেখার পেজ, live মোডে পাঠানো (WA_ACCESS_TOKEN, WA_PHONE_NUMBER_ID), টিকেট খোলা।
