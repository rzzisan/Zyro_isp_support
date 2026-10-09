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

## অ্যাডমিন ড্যাশবোর্ড (2026-10-05)
- https://support.zyrotechbd.com/admin (FastAPI + Jinja2, `app/admin.py`, `templates/`)। BSOL-এর multi-AI নকশা অনুসরণ।
- প্রথম অ্যাডমিন: `/admin/setup`-এ `.env`-এর `ADMIN_SETUP_TOKEN` দিয়ে (একবারই)। পাসওয়ার্ড scrypt হ্যাশ, সেশন কুকি (SameSite strict, https), POST-এ Origin চেক, লগইনে ১০ মিনিটে ৫ বার ভুলের সীমা।
- **বিলিং পেজ**: URL/ইউজার/পাসওয়ার্ড DB-তে (পাসওয়ার্ড Fernet এনক্রিপ্টেড, `DATA_ENCRYPTION_KEY`), "পরীক্ষা করুন" দিয়ে লাইভ লগইন + সার্চ। `.env`-এর ISPDIGITAL_* শুধু fallback।
- **AI এজেন্ট পেজ**: প্রোভাইডার Claude / Grok / Gemini / Groq / OpenAI / OpenRouter; প্রতিটায় একাধিক key (এনক্রিপ্টেড, masked দেখায়), প্রতি key-তে পরীক্ষা + লাইভ মডেল তালিকা। ডিফল্ট প্রোভাইডার + মডেল + বট মোড (shadow/off) + অতিরিক্ত নির্দেশনা। 401/403/429-এ key ৬০ সেকেন্ড cooldown, পরের key।
- **টেস্ট পেজ**: নম্বর + মেসেজ দিয়ে পুরো পথ (বিলিং → AI খসড়া), কিছু পাঠায় না।
- ড্যাশবোর্ডে সাম্প্রতিক খসড়া (provider/model/error সহ)।
- `.env`-এ নতুন: DATA_ENCRYPTION_KEY (হারালে সেভ করা key/পাসওয়ার্ড আর পড়া যাবে না), SESSION_SECRET, ADMIN_SETUP_TOKEN।

## লাইভ টেস্ট ও অফিসিয়াল নম্বরের প্রস্তুতি (2026-10-05/06)
- টেস্ট নম্বর: WABA 3530709563744212-এর +880 1933-635909 (Zareen, phone id 1305203072676835), system user "BSOL"-এর token (ড্যাশবোর্ডে এনক্রিপ্টেড), অ্যাপ WABA-তে subscribed।
- বট মোড: shadow / live (+allowlist) / off। live-এ উত্তর পাঠায়, শেষে স্বাক্ষর (ডিফল্ট "- Zyro")।
- কাস্টমার খোঁজার ক্রম: নতুন মেসেজের আইডি/মোবাইল/ইউজারনেম → শেষ আলোচিত কাস্টমার (settings `link:<wa>`) → WhatsApp নম্বর।
- টিকেট: AI `[[TICKET: ক্যাটাগরি | বিবরণ]]` দিলে ispdigital-এ টিকেট (Medium, SMS বন্ধ, ডুপ্লিকেট চেক), কাস্টমারকে নম্বর জানায়। সুইচ `auto_ticket`।
- স্টাইল: ছোট কথ্য বাংলা, শুধু যা জিজ্ঞেস করা, বানানো নিষেধ, অ্যাডমিন নির্দেশনা চূড়ান্ত। Groq 429-এ অপেক্ষা + অন্য প্রোভাইডারে fallback।
- ড্যাশবোর্ড: কথোপকথন (নম্বর অনুযায়ী, থ্রেড), স্টাফের উত্তর (বট ডিফল্ট ৩ ঘণ্টা চুপ), নম্বরভিত্তিক বট বন্ধ (`pause:<wa>`), কোন business নম্বরে বট চলবে (`bot_phone_ids`), নতুন নম্বর যুক্ত → কোড → যাচাই + PIN রেজিস্টার।
- অফিসিয়াল নম্বর 01777858289: Coexistence-এর জন্য App Review (whatsapp_business_messaging/management) এখনো জমা হয়নি (প্যাকেজ `docs/meta-app-review.md`)। বিকল্প: Business অ্যাপ থেকে ডিলিট করে ড্যাশবোর্ড দিয়ে Cloud API-তে যুক্ত। যুক্ত হলে `bot_phone_ids`-এ শুধু ওই নম্বর রাখতে হবে (Zareen নম্বর বাদ)।
- নিরাপত্তা বাকি: অন্যের আইডি দিয়ে তথ্য জানা আটকাতে OTP যাচাই।

## SaaS ধাপ ১ (2026-10-06)
- পরিকল্পনা: `docs/saas-plan.md` (Filament বেছে নেওয়া হয়েছে, Tyro না)।
- `panel/` = Laravel 13 + Filament 5, https://desk.zyrotechbd.com (nginx `deploy/nginx-desk.zyrotechbd.com.conf`, wildcard cert, PHP-FPM www-data; storage/bootstrap/cache গ্রুপ www-data)।
- DB: PostgreSQL `zyro_support` (ইউজার `zyro_support`, পাসওয়ার্ড `panel/.env`-এ), টেস্ট DB `zyro_support_test`।
- মডেল: `companies` (slug নিজে তৈরি), `company_user` (role owner/admin/agent), `users.is_super_admin`।
- প্যানেল: `/super` (সুপার-অ্যাডমিন: কোম্পানি, ইউজার, কোম্পানির ইউজার-রোল) আর `/app/{company}` (কোম্পানি tenancy)।
- টেস্ট: `tests/Feature/TenancyTest.php` (৫টা: আলাদা কোম্পানি দেখা যায় না, সুপার প্যানেলে কোম্পানি ইউজার ঢুকতে পারে না ইত্যাদি)। চালানো: `DB_DATABASE=zyro_support_test APP_ENV=testing php artisan test` (আগে `config:clear`, পরে `config:cache`)।
- সুপার-অ্যাডমিন: `php artisan make:filament-user` তারপর `php artisan zyro:make-super-admin <email>`।
- পুরনো বট (`zyro-support`, support.zyrotechbd.com) অপরিবর্তিত।

## SaaS ধাপ ২ (2026-10-06): কোম্পানির সেটআপ
- টেবিল: `billing_connections` (প্রতি কোম্পানিতে ১টা, পাসওয়ার্ড Laravel `encrypted` cast = APP_KEY), `bot_settings` (provider/model/mode/allowlist/auto_ticket/signature/extra_prompt), `ai_keys` (encrypted, প্রতি কোম্পানির নিজের key)। `Membership` মডেল = `company_user`।
- কোম্পানি প্যানেলের "সেটিংস" গ্রুপ (শুধু Owner/Admin; Agent 403): বিলিং সংযোগ (+ সংযোগ পরীক্ষা, PHP `IspDigitalClient`), বট সেটিংস, AI key (+ পরীক্ষা = provider-এর models লিস্ট, টোকেন খরচ নেই), টিম (প্যাকেজের সীমা মেনে সদস্য যোগ; Admin Owner বানাতে পারে না; Owner সরানো যায় না)।
- নিরাপত্তা: AI key আর টিম রিসোর্সে Filament tenancy-র পাশাপাশি নিজস্ব `where company_id = tenant` (টেস্টে Filament-এর নিজের স্কোপ কাজ করেনি)।
- টেস্ট মোট ২০টা পাস (`CompanySettingsTest`, `CompanyPlanTest`, `TenancyTest`)।
- বাকি: Python ইঞ্জিন এই টেবিলগুলো থেকে পড়বে (Laravel encrypted মান Python-এ ডিক্রিপ্ট: AES-256-CBC + APP_KEY)।

## SaaS ধাপ ৩ (2026-10-06): মাল্টি-কোম্পানি ইঞ্জিন
- `engine/` (FastAPI), সার্ভিস `zyro-engine`, 127.0.0.1:8994, `deploy/zyro-engine.service`। সিক্রেট `engine/.env`-এ (git-এ নেই)।
- webhook-এ phone_number_id দেখে কোম্পানি খোঁজে; কোম্পানির বিলিং লগইন, AI key, বট সেটিং, WA টোকেন PostgreSQL থেকে নেয় (Laravel encrypted ফিল্ড Python-এ decrypt হয়)।
- কনভারসেশন: wa_events, wa_contacts, wa_messages, wa_drafts। পরিচয় যাচাই flow (`ident_state`), ভয়েস→Groq Whisper, [[TICKET]] মার্কার।
- এখন `ENGINE_DRY_RUN=1`: কিছু পাঠায় না, টিকিট খোলে না। Meta webhook এখনও পুরনো বটে (Century Link অক্ষত)।
- টেস্ট: `deploy/replay_events.py 12` পুরনো বটের আসল ১২টা ইভেন্ট replay করে; ১২/১২ ড্রাফট এরর ছাড়া (2026-10-06)।
- সময়: DB session UTC; সময়ের তুলনা SQL-এ `now()` দিয়ে (Python-এ naive/aware মেশানো যাবে না)।
- বাকি: engine এখনও `app.agent` থেকে prompt/helper import করে; পুরনো বট সরানোর আগে কপি করতে হবে।

## SaaS ধাপ ৪ (2026-10-06): এজেন্ট ইনবক্স
- প্যানেলে `/app/{company}/inbox`: সব কনভারসেশন, ফিল্টার (আমার / কারো না / বট থামানো), ১৫ সেকেন্ডে রিফ্রেশ।
- চ্যাট পেজ: মেসেজ, বটের না-পাঠানো খসড়া আর টিকিট নোট, ভয়েস/ছবি/ভিডিও/ফাইল (`/media/{id}`, প্রথমবার Meta থেকে নামিয়ে `storage/app/private/media`-তে রাখে, শুধু ওই কোম্পানির মেম্বার)।
- এজেন্ট রিপ্লাই দিতে পারে (২৪ ঘণ্টার নিয়ম মানে), রিপ্লাইয়ের পর বট কয়েক ঘণ্টা চুপ রাখা যায়; "আমি নিলাম", "অন্যকে দিন", বট থামান/চালু করুন। খসড়া এক ক্লিকে রিপ্লাই বক্সে আনা যায়।
- `panel/.env` `WA_SEND_ENABLED=false`: রিপ্লাই সেভ হয় কিন্তু কাস্টমারের কাছে যায় না (টেস্ট মোড)। লাইভে নেওয়ার সময় true করতে হবে।
- Filament গোটচা: table/filter closure-এর প্যারামিটারের নাম `$query` হতে হবে (`$q` দিলে খালি Builder ইনজেক্ট হয়)। রুট ক্যাশ আছে: টেস্টের আগে `route:clear`।
- টেস্ট: `tests/Feature/InboxTest.php` (৮টা), মোট ২৮টা পাস।

## SaaS ধাপ ৭ (2026-10-06): Century Link নতুন ইঞ্জিনে
- nginx `support.zyrotechbd.com`: `location = /webhook` → engine :8994; বাকি সব (পুরনো ড্যাশবোর্ড) → :8993। আগের কনফিগ: `data/nginx-support.before-engine.conf`।
- engine `.env`: ENGINE_DRY_RUN=0, LEGACY_FORWARD_URL=http://127.0.0.1:8993/webhook (পুরনো বট প্রতিটা webhook-এর কপি পায়)। পুরনো বট `bot_mode=off`: শুধু রেকর্ড রাখে, উত্তর দেয় না।
- panel `.env` WA_SEND_ENABLED=true: ইনবক্সের রিপ্লাই সত্যিই যায়।
- পুরনো চ্যাট কপি: `deploy/migrate_legacy_chats.py century-link-network 1309537728917134` (আবার চালানো নিরাপদ)।
- engine এখন `engine/llm.py`-তে নিজের prompt রাখে (app/ থেকে আর import করে না); নাম ডাকার নিয়ম যোগ হয়েছে।
- ফেরত যাওয়া: আগের nginx কনফিগ কপি করে reload, পুরনো বটের settings-এ bot_mode=live, engine-এ ENGINE_DRY_RUN=1 আর LEGACY_FORWARD_URL মুছে restart।
- 2026-10-06: পুরনো বট বন্ধ (Zisan-এর নির্দেশে)। `zyro-support` সার্ভিস disable, engine আর কপি পাঠায় না, support.zyrotechbd.com/ → desk.zyrotechbd.com/app রিডাইরেক্ট; শুধু /webhook ইঞ্জিনে। পুরনো ডেটা `data/messages.db`-তে রাখা আছে, আগের nginx কনফিগ `data/nginx-support.with-legacy.conf`।

## বিলিংয়ের টিকিট প্যানেলে (2026-10-07)
- `engine/ticket_sync.py`: খোলা টিকিট (`/ClientSupport/AjaxDailyComplainList`, processing আলাদা করতে `customQueryString=processing`) আর সমাধান হওয়া (`/ClientSupport/AjaxMonthlyComplainList`, তারিখ `dd-mm-yyyy`, `permissionId=1`) → `billing_tickets`। systemd `zyro-ticket-sync.timer` প্রতি ৫ মিনিটে `--days 3`; প্রথমবার `--days 90` চালানো হয়েছে (১৮৮৯টা সমাধান)।
- বিলিংয়ের সময় "MM/dd/yyyy hh:mm:ss tt" বাংলাদেশ সময়; DB-তে UTC। Priority 1 Low, 2 Medium, 3 High। খোলা লিস্টে `SolvedBy` = দায়িত্বে কে।
- প্যানেল: `/app/{company}/tickets` (শুধু দেখা; ট্যাব, ফিল্টার, সার্চ), ড্যাশবোর্ডে TicketStats/TicketTrendChart/TicketBreakdownChart, চ্যাট পেজে কাস্টমারের টিকিট।
- 2026-10-07: প্রতিটি সদস্যের নিজের বিলিং লগইন (`company_user.billing_username/billing_password`, এনক্রিপ্টেড)। পেজ: সেটিংস → "আমার বিলিং লগইন" (সবাই), টিম পেজে owner/admin-ও দিতে পারেন। প্যানেল থেকে টিকিট খোলা/assign engine-এ `user_id` সহ যায়, `tenant.billing_for_user()` সেই লগইন ব্যবহার করে; লগইন না থাকলে ফেরত দেয় (owner হলে কোম্পানির লগইনে)। বট, টিকিট sync, কাস্টমার খোঁজা কোম্পানির লগইনে।
- 2026-10-07: ফিল্ড টেকনিশিয়ান। প্যানেল সেটিংস → "টেকনিশিয়ান" (owner/admin) নাম আর WhatsApp নম্বর (`technicians` টেবিল, 8801… আকারে)। ইঞ্জিন `engine/technician.py`: এই নম্বরের মেসেজে কাস্টমার যাচাই হয় না; যেকোনো কাস্টমারের (ID/মোবাইল/PPPoE দিয়ে) লাইন, PPPoE ID, IP, MAC, ONU, বিল, খোলা টিকিট জানায়; [[TICKET]] হলে সেই কাস্টমারের টিকিট খোলে, মন্তব্যে "[WhatsApp: টেকনিশিয়ান নাম]"। আগের কাস্টমার মনে রাখে (`ident_state.tech_customer`)। ইনবক্সে "টেকনিশিয়ান" ট্যাগ।
- 2026-10-07: টেকনিশিয়ান "লাইন চালু করো" বললে বট `[[ENABLE]]` দেয় → `engine/technician.py enable_line()`: বিলিংয়ে `POST /Billing/EnableSelectedClients cusHeadIds=<header id>`, আগে থেকে চালু থাকলে কিছু করে না, পরে আবার দেখে নেয়। প্রতিটা অনুরোধ `line_enables` টেবিলে (টেকনিশিয়ান, কাস্টমার, তখনকার বকেয়া, মেসেজ, ফল)। প্যানেল: "লাইন চালুর রেকর্ড" পেজ। কাস্টমার ID খোঁজায় এখন ৫০টা ফল দেখে (আগে ১০, তাই কিছু ID মিলত না)।
- 2026-10-07: কাস্টমার আমাদের DB-তে। `billing_customers` (৪,৮১৬ জন), `engine/customer_sync.py` (Customer list + Billing list, ~১৬ সেকেন্ড), timer `zyro-customer-sync.timer` প্রতিদিন রাত ৩টা (বাংলাদেশ)। PPPoE পাসওয়ার্ড Laravel-compatible এনক্রিপ্টেড (`db.encrypt`), portal পাসওয়ার্ড রাখা হয় না। বট `engine/customers.py` দিয়ে আগে DB-তে খোঁজে (~৩০ms), না পেলে বিলিং, তারপর `fresh()` দিয়ে বিলের অংশ লাইভ। প্যানেল: "কাস্টমার" পেজ (সবাই), লাইভ অবস্থা, PPPoE পাসওয়ার্ড (owner/admin, `customer_password_views` লগ), CSV `/export/{company}/customers.csv` (owner/admin)। গোটচা: main.py-তে কোনো ফাংশনের নাম `customers` দেওয়া যাবে না (মডিউল ঢেকে যায়)।
- 2026-10-07: MikroTik সংযোগ। `mikrotik_routers` (host, api_port, username, password এনক্রিপ্টেড, identity, billing_server)। প্যানেল সেটিংস → MikroTik (owner/admin)। "পরীক্ষা" engine `/internal/{cid}/mikrotik/{id}/test` → `engine/mikrotik.py` (নিজস্ব RouterOS API ক্লায়েন্ট, 8728/8729-TLS, শুধু পড়ে): identity, version, PPPoE অনলাইন সংখ্যা; identity-কে বিলিংয়ের Server নামের সাথে মেলায় (CLNBD, CLN_4, CLN-3; নাম অক্ষর-সংখ্যা ধরে মেলায়, না মিললে হাতে বাছা যায়)। পরের ধাপ: কাস্টমার অনলাইন কিনা `/ppp/active` থেকে।
- 2026-10-07: অনলাইন/অফলাইন। `engine/ppp_sync.py` প্রতি ২ মিনিটে (`zyro-ppp-sync.timer`) তিন রাউটারের `/ppp/active` → `ppp_sessions` (~৬,২০০, <২ সেকেন্ড)। "অনলাইন" মানে গত ৫ মিনিটে দেখা গেছে। বট আর প্যানেলের লাইভ অবস্থায় `online_now()` সরাসরি কাস্টমারের রাউটারে জিজ্ঞেস করে (~০.০৭ সেকেন্ড), live_data-তে `mikrotik`। প্যানেল: কাস্টমার তালিকায় "এখন" কলাম+ফিল্টার, ড্যাশবোর্ডে NetworkStats।
- 2026-10-07: অনলাইন ক্লায়েন্ট মনিটরিং (`/app/{company}/monitoring`, বিলিংয়ের ClientMonitoring-এর মতো)। Active কাস্টমার + `ppp_sessions`: সার্ভার কার্ড (মোট/অনলাইন/অফলাইন), Zone/Subzone/Box/Connection Type/অবস্থা ফিল্টার, IP, MAC, uptime, "শেষ অনলাইন" (ppp_sync এখন সেশন মুছে না, seen_at = শেষ দেখা)। প্রতি সারিতে রি-চেক / ট্রাফিক (`/interface/monitor-traffic <pppoe-user>`) / পিং (রাউটার থেকে) — engine `/internal/{cid}/monitor/{header}/{recheck|traffic|ping}`, সব শুধু পড়া।
- 2026-10-07: সদস্যভিত্তিক মেনু অনুমতি। `company_user.permissions` (jsonb, null = রোলের ডিফল্ট), তালিকা `app/Support/Menu.php`। Owner সব দেখে; সেটিংস মেনুগুলো শুধু admin-এর জন্য কাজ করে। প্রতিটা Resource/Page-এর `canAccess()`-এ `Menu::can('<key>')`। টিম পেজে "কোন কোন মেনু দেখবেন" টিক-ঘর। ড্যাশবোর্ড আর "আমার বিলিং লগইন" সবার।
- 2026-10-07: নিজেদের OLT (SNMP, শুধু পড়া)। `engine/snmp.py` (নিজস্ব SNMPv2c), `engine/olt_sync.py` (BDCOM EPON ড্রাইভার, timer প্রতি ১০ মিনিট)। টেবিল `olts`, `onus`, `customer_onus` (রাউটার MAC→ONU, OLT-এর dot1q FDB-তে প্রতি MAC GET, VLAN তালিকা ধরে), `onu_mac_misses`। BDCOM OID: 3320.101.10.1.1.{3 MAC,26 status,27 distance}, 3320.101.10.5.1.{5 rx/10,6 tx/10,2 temp/256,3 volt/10000}। গোটচা: BDCOM-এর নিজস্ব টেবিলে GETBULK/walk দিলে OLT-এর SNMP ১-২ মিনিট বন্ধ হয়ে যায়, তাই শুধু GET; DDM নেই এমন ONU-তে power পড়লে ~১০ সেকেন্ড লাগে আর -65535 দেয় (`no_ddm_at`, এক দিন বাদ)। প্রথম OLT: CLN_1_TILARDI 10.11.12.2 (P3608B, ৪৪৯ ONU, কাস্টমার CLN-3-এ)। প্যানেল: সেটিংস → OLT, "ONU মনিটরিং" পেজ; টিকিট ফর্ম/লাইভ অবস্থা/বট আগে আমাদের OLT, না পেলে বিলিং।
- 2026-10-07: মনিটরিং কার্ডের সংখ্যা ঠিক করা। বিলিংয়ের ClientMonitoring (`/ClientMonitoring/ClientMonitorAjax?server=<id>&permissionId=1`, সার্ভার id CLNBD=1, CLN-3=4, CLN_4=5) গোনে `Disabled = false` সব কাস্টমার (Active/Free/Personal)। আমাদের কার্ড আগে গুনত `status = 'Active'` (লাইন বন্ধও), তাই অফলাইন বেশি দেখাত। এখন Monitoring পেজ, টেবিল আর ড্যাশবোর্ড NetworkStats `disabled = false` দিয়ে গোনে। রাউটারের /ppp/active-এ ~১,৯০০ ইউজার (sat-, w., kkms. ...) বিলিংয়ে নেই, এরা কোথাও গোনা হয় না। লাইন চালু/বন্ধ দিনে বদলায়, তাই কেউ মনিটরিং পেজ খুললে (Zisan-এর সিদ্ধান্ত, timer নয়) engine `POST /internal/{cid}/customers/refresh?max_age=600` পেছনে কাস্টমার sync চালায় (~১৭ সেকেন্ড), শেষ sync ১০ মিনিটের বেশি পুরনো হলেই; একসাথে একটাই (lock + `billing_customer_syncs`)। পেজ ৫ সেকেন্ড পরপর `checkRefresh` দিয়ে শেষ হওয়া দেখে টেবিল রিফ্রেশ করে। রাতের timer আগের মতো।
- গোটচা: প্যানেলের `.env` www-data পড়তে পারে না, লাইভ চলে শুধু config cache দিয়ে। লাইভে `config:clear` দেবেন না (500 হয়)। টেস্ট (লাইভ cache না ছুঁয়ে): `APP_CONFIG_CACHE=/tmp/nocfg.php APP_ROUTES_CACHE=/tmp/noroutes.php DB_CONNECTION=pgsql DB_DATABASE=zyro_support_test APP_ENV=testing php artisan test` (DB_CONNECTION না দিলে phpunit.xml-এর sqlite চলে আর `panel/zyro_support_test` ফাইল তৈরি হয়)।

## বটের উত্তর উন্নত (2026-10-07)
- রিভিউ: ৬-৭ অক্টোবরের চ্যাট থেকে ১৬টা দুর্বলতা (project files `bot-reply-review-2026-10-07.md`)। ১-১৩ ঠিক করা হয়েছে, টেকনিশিয়ান চ্যাটের ১৪-১৬ বাকি (1523 ID খুঁজে না পাওয়া, এক উত্তরে দুই কাস্টমার মেশা, "চালু করা হয়েছে" তারপর "আগে থেকেই চালু")।
- বারবার নম্বর চাওয়ার মূল কারণ: প্যানেলের "বট চালু করুন" `ident_state` মুছে দিত। এখন stage=ok বা technician হলে রাখে। ok অবস্থা ৭ দিন, অসম্পূর্ণ পরিচয় ২৪ ঘণ্টা।
- `engine/main.py`: মেসেজ এলে ৪ সেকেন্ড অপেক্ষা, নতুন মেসেজ এলে শুধু শেষটা উত্তর দেয় (আগের ১০ মিনিটের উত্তর-না-পাওয়া মেসেজ একসাথে); কন্টাক্ট-প্রতি lock; অপেক্ষার মধ্যে স্টাফ pause করলে উত্তর দেয় না।
- confirm ধাপে স্পষ্ট "না" ছাড়া সব হ্যাঁ ("Haaa", সরাসরি সমস্যা); কথাটা pending হিসেবে উত্তর পায়।
- `verified`: WhatsApp নম্বর = লাইনের মোবাইল, বা confirm দিয়ে, বা কাস্টমার লাইনের নামের অংশ লিখলে। না হলে `customer_view()` বিল/পেমেন্ট/নাম/প্যাকেজ সরিয়ে দেয়। মোবাইল, IP, MAC কখনো বটের live_data-তে যায় না; `today` (ঢাকা) যোগ হয়।
- টানা দ্বিতীয় "ওকে"/ইমোজিতে উত্তর নেই; "আগের অভিযোগ খোলা" নম্বর ১২ ঘণ্টায় একবার। fixed মেসেজ থেকে "ভাই" বাদ।
- `engine/llm.py` prompt: verified নিয়ম, মোবাইল না বলা, প্রশ্নের সরাসরি উত্তর, বিল দিয়েছি-নেট নেই ফ্লো, bill_day বনাম শেষ পেমেন্ট, রাউটার সেটিং বানিয়ে না বলা, সেন্ড মানি/ক্যাশআউট না, বিলের টিকিটে "অফিসে জানানো হলো", কাস্টমারের নিজের বলা সম্বোধন মানা, TICKET ফরম্যাট ঠিক।
- আগের ফাইল: `~claude-dev/bot-review/before/`। ফেরত: সেখান থেকে কপি করে `systemctl restart zyro-engine`।
- 2026-10-08: স্টাফের নির্দেশনা। (১) টেকনিশিয়ান নম্বর থেকে "X-কে জানাও/রিপ্লাই দাও" → বট `[[NOTIFY: মেসেজ]]` → `technician.notify_customer()` কাস্টমারের চ্যাটে (wa_contacts.customer_id, নাহলে নিবন্ধিত মোবাইল) `deliver` দিয়ে পাঠায়; কাস্টমার ২৪ ঘণ্টায় মেসেজ না দিলে পাঠায় না, টেকনিশিয়ানকে বলে। টেকনিশিয়ানের live_data-তে `customer_chat` (শেষ ৬টা মেসেজ)। (২) `history_for` স্টাফ/অ্যাপ মেসেজে "[স্টাফ]" লাগায়; prompt 8a: স্টাফ যা করবে বলেছে কাস্টমারের পরের উত্তরে সেটা করা। `deliver` এখন mode ফেরত দেয়। আগের ফাইল `~claude-dev/bot-review/before2/`।
- 2026-10-08: টিকিটের "সমস্যার বিবরণ" বিলিংয়ের টিকিট লিস্টে আসে না (Comment/Remarks ফাঁকা)। এটা থাকে টিকিটের প্রথম EmployeeToEmployee কনভারসেশনে: `GET /ComplainDiscussion/GetConversationInfoByTicketId?id={ComplainId}&forWhom=EmployeeToEmployee` (`Comments` HTML)। `ticket_sync.fill_descriptions` প্রতি sync-এ (৫ মিনিট ও "এখনই Sync") খোলা সব টিকিটের জন্য আবার এনে `billing_tickets.description`-এ রাখে, কারণ স্টাফ বিলিংয়ে এডিট করে ('' = নেই)। টেকনিশিয়ান প্রিন্টের মন্তব্য ঘরে description + note, প্যানেলের টিকিট ভিউতেও "সমস্যার বিবরণ"।
- গোটচা (2026-10-08): `php artisan view:cache` claude-dev হিসেবে চালালে compiled view claude-dev-এর হয়, তখন www-data `touch()` করতে না পেরে পেজে 500 দেয়। চালাতে হবে `sudo -u www-data php artisan view:cache`।

## Left কাস্টমার (2026-10-08)
- বিলিংয়ের Customer → Left (`GET /Customer/AjaxLeftCustomerList`, DataTables; ফিল্টার ফাঁকা রাখলে সব, ~১,১১০ জন)। CustomerHeaderId একই id-জগৎ, তাই একই `billing_customers` টেবিলে `is_left = true`, `left_on` (বিলিংয়ের LeftDate "04 Oct 2026"), status "Left", disabled true। বিলিং লিস্ট নেই, তাই `due` = সারির `Due`। Left-এর কোনো কারণ বিলিং দেয় না। এদের PPPoE MikroTik থেকে মুছে ফেলা থাকে। AjaxCustomerList-এ Left কাস্টমার আসে না।
- `engine/customer_sync.py` এখন তিনটা লিস্ট আনে (Active, Billing, Left) একই sync-এ (রাতের timer, "এখনই Sync", মনিটরিং পেজের on-demand)। দুই লিস্টে একসাথে থাকলে Active জেতে। Active লিস্টে এলে `is_left = false`, Left লিস্টে এলে true, তাই দুই দিকের বদল নিজে থেকেই হয়। আগে থেকে জানা কাস্টমারের বদল `billing_customer_changes`-এ (change = left | returned, left_on, seen_at)। কোনো লিস্টেই না থাকলে আগের মতো `gone_at`। `billing_customer_syncs`-এ `left_total`, `became_left`, `came_back`।
- "বর্তমান কাস্টমার" মানে `gone_at IS NULL AND NOT is_left`: বটের লুকআপ (`engine/customers.py`, যাতে পুরনো Left লাইনের একই মোবাইল বর্তমান কাস্টমারকে ঢেকে না দেয়), ppp গোনা, মনিটরিং, NetworkStats, MikroTik কাউন্ট, CSV, কাস্টমার পেজের ডিফল্ট।
- প্যানেল: কাস্টমার পেজে "তালিকা" ফিল্টার (বর্তমান / Left / বিলিংয়ে নেই / সব), Left ব্যাজ + তারিখ, ভিউতে "Active / Left বদল"। নতুন টিকিটের কাস্টমার খোঁজায় Left কাস্টমারও আসে (বর্তমানদের পরে, লেবেলে "LEFT (তারিখ)"), নিচের তথ্যবক্সে Billing Status "Left"।

## WhatsApp মেসেজ নোটিফিকেশন (2026-10-08)
- কোম্পানি প্যানেলের টপবারে বেল (`resources/views/filament/notify-bell.blade.php`, `USER_MENU_BEFORE` হুক), শুধু যাদের "ইনবক্স" মেনু আছে। ক্লিক করলে ব্রাউজার Notification permission চায় + সাউন্ড আনলক হয়; আবার ক্লিক = বন্ধ (localStorage `zyroNotify:mode`)। হলুদ ডট = এখনো ডেস্কটপ নোটিফিকেশন চালু হয়নি, লাল = ব্রাউজারে ব্লক।
- `public/js/zyro-notify.js` + `zyro-notify-worker.js`: worker প্রতি ৮ সেকেন্ডে `GET /notify/{company}/poll?after={id}` (routes/web.php, `notify.poll`) — নতুন `direction='in'` মেসেজ (সর্বোচ্চ ২০টা), নাম/নম্বর/১৪০ অক্ষর/স্টাফ কিনা/`human` (বট live না বা চ্যাটে বট থামানো)/চ্যাট URL। প্রথম কল শুধু শেষ id নেয়, তাই পেজ খুললে পুরনো মেসেজ বাজে না।
- ট্যাব পেছনে থাকলে: ডেস্কটপ নোটিফিকেশন (চ্যাট-প্রতি একটা, ক্লিকে চ্যাট খোলে) + WebAudio "ডিং" + টাইটেলে "(৩)"। ট্যাব সামনে থাকলে Filament toast + ডিং; যে চ্যাট খোলা আছে তার জন্য কিছু না। কয়েকটা ট্যাব খোলা থাকলেও localStorage `zyroNotify:last:{slug}` দিয়ে একবারই বাজে।
- Web Push (ট্যাব বন্ধ থাকলেও): বেল চালু করলে ব্রাউজার `/zyro-sw.js` (service worker, scope `/`) রেজিস্টার করে subscribe করে, `POST /notify/{company}/push` → `push_subscriptions` (company_id+endpoint unique; বেল বন্ধ করলে `/push/delete`)। প্রতি পেজ লোডে আবার পাঠায়। engine webhook-এ নতুন inbound মেসেজ সেভ হওয়ার সাথে সাথে `engine/webpush.py` (pywebpush) কোম্পানির সাবস্ক্রাইব করা ইনবক্স-অনুমতিওয়ালা সবাইকে push পাঠায়; 404/410 হলে সাবস্ক্রিপশন মুছে দেয়। VAPID key `web_push_keys` টেবিলে, engine চালু হওয়ার সময় না থাকলে বানায় (private key APP_KEY দিয়ে encrypted); key বদলালে ব্রাউজার নিজে আবার subscribe করে। প্যানেলের কোনো ট্যাব স্ক্রিনে থাকলে SW নোটিফিকেশন দেখায় না (পেজ নিজে toast+ডিং দেয়); push চালু থাকলে লুকানো ট্যাব নিজের নোটিফিকেশন দেয় না (দুবার না আসে)।
- সীমা: ব্রাউজার একদম বন্ধ থাকলে আসে না, খুললে আসে (TTL ১ ঘণ্টা)। Windows-এ Chrome "Continue running background apps" চালু থাকলে জানালা বন্ধ থাকলেও আসে। রিলোডের পর পেজে একবার ক্লিক না করা পর্যন্ত ব্রাউজার "ডিং" আটকাতে পারে।

## টেকনিশিয়ান/বট বাগ ফিক্স: 01406369392 চ্যাট (2026-10-08)
- ঘটনা: টেকনিশিয়ান Zisan "Send ftp link to 1565"-এর পর FTP IP `172.19.178.178` পেস্ট করলেন; বট "172"-কে কাস্টমার ID 0172 ধরে ফেলল (অন্য কাস্টমার, WhatsApp চ্যাট নেই → "আগে কথা হয়নি")। আগে লিংক ছাড়া "লিংকগুলো পাঠানো হয়েছে" কাস্টমারের কাছে গিয়েছিল। টেকনিশিয়ানের "Hi/Hlw" আগের খোঁজা কাস্টমার Babul Ahammed-এর কাছে "জি, হাই/হ্যালো" হয়ে চলে গিয়েছিল (tech_customer সময়সীমাহীন + মডেলের নিজে থেকে [[NOTIFY]])।
- ফিক্স: `ispdigital.number_text/without_urls` — মোবাইল/ID খোঁজার আগে লিংক, IP, MAC, ডট/কোলন দেওয়া সংখ্যা বাদ (বিলিং ও লোকাল দুই লুকআপে)। টেকনিশিয়ানের মেসেজে খালি সংখ্যা ID ধরা হয় শুধু ছোট মেসেজে (≤৬ শব্দ) বা "id/কাস্টমার" লেখা থাকলে। `tech_customer` ৩০ মিনিট পর ভুলে যায় (`ident_state.at`)। [[NOTIFY]] যায় শুধু টেকনিশিয়ান গত ১৫ মিনিটের মেসেজে স্পষ্ট বললে (জানাও/বলো/পাঠাও/send/reply...); টেকনিশিয়ানের পেস্ট করা লিংক NOTIFY-তে নিজে যোগ হয়; "পাঠানো হয়েছে/লিংক" দাবি কিন্তু লিংক/সংখ্যা নেই এমন NOTIFY আটকে যায়। ফলাফলে কাস্টমারের নাম+ID দেখায়। টেকনিশিয়ান prompt-এ কোম্পানির নির্দেশনা (extra_prompt) যোগ।
- কাস্টমার বট: BURST_WAIT 7s, আর উত্তর পাঠানোর ঠিক আগে নতুন মেসেজ এলে এই উত্তর বাদ (draft mode `superseded`), যাতে এক প্রশ্নে দুই উত্তর না যায়। prompt-এ 5a/5b: অফিস/স্টাফ কোথায়/প্যাকেজ-অফার নির্দেশনায় না থাকলে বানাবে না ("এমন প্যাকেজ নেই" বলবে না); বিষয়ের বাইরের প্রশ্নে (গেম, অ্যাপ) ভদ্র না, টিকিট নয়।
- বাকি (অ্যাডমিনের কাজ): বট সেটিংসের নির্দেশনায় 550 টাকা 20Mbps + Bongo অফার ও FTP লিংক যোগ; Gemini key quota শেষ, Groq 429 — key ঠিক করা/নতুন provider।

## 2026-10-08 17:13 UTC: Bot FAQ deployed (58e146a)
- New table `bot_faqs` (migration 2026_10_08_000004), panel page "বটের FAQ" (সেটিংস group). Active rows go into both the customer and the technician prompts via engine/agent.py `knowledge()`.
- Bot Settings now has a collapsed read-only section "বট এখন যা নির্দেশনা পায়", fed by the engine's `GET /internal/{company}/bot-prompts`.
- Deploy: migrate, then config:cache (claude-dev, chgrp www-data, 640), then route:cache/filament:optimize/view:cache as www-data, reload fpm, restart zyro-engine. Verified: /health 200, bot-prompts returns customer+technician, both pages 302 to login, no new log errors.
- Pending: add package/offer FAQs (550 tk 20 Mbps + Bongo); bot pause hours after a staff reply (bug 4); Gemini key quota (bug 8).

## 2026-10-08: AI খরচ page (token usage per key/model)
- Engine records every AI call in `ai_usage` (company, key, provider, model, purpose customer/technician/voice, contact, input/output/cached tokens, ok/error). `llm._call` now returns (text, usage, rate-limit headers); `agent.record_usage` writes the row and merges the provider's rate-limit headers into `ai_keys.quota` (+ `quota_at`). Groq's 429 text "tokens per day (TPD): Limit X, Used Y" is saved as `quota["limit:tpd"]` with time. Voice (Whisper) rows keep audio seconds in input_tokens. Keys are never stored or logged.
- Panel page "AI খরচ" (`/app/{company}/ai-usage`, সেটিংস, same access as AI key): today/7/30-day cost + tokens, per key (with provider's last-known limits), per model (editable USD price per 1M tokens, `ai_model_prices`, defaults in `AiModelPrice::DEFAULTS`), per purpose, last 14 days. Cost is an estimate; free-tier keys really cost 0.
- Migration 2026_10_08_000005. Counting starts at deploy; nothing before is reconstructed.

- বটের মূল নির্দেশনা এডিট (2026-10-08): `bot_settings.customer_prompt` / `technician_prompt` (null = engine-এর built-in `SYSTEM_PROMPT` / `TECH_PROMPT`)। বট সেটিংস → "বটের মূল নির্দেশনা (এডিট করা যায়)" বক্সে built-in লেখা দেখায়; না বদলে সেভ করলে null থাকে (পরের কোড-উন্নতি পায়), পুরো মুছে সেভ = ডিফল্টে ফেরত। টেকনিশিয়ান লেখায় `{company}`, `{tech}`, `{knowledge}` str.replace দিয়ে বসে (`{knowledge}` না থাকলে শেষে যোগ)। extra_prompt + FAQ সবসময় engine নিজে শেষে যোগ করে। `/internal/{id}/bot-prompts` এখন `customer_default`/`technician_default`-ও দেয়।
- AI token কমানো (2026-10-08): FAQ মোট ১,৫০০ অক্ষরের বেশি হলে প্রতি AI call-এ শুধু মেসেজের সাথে শব্দ মেলে এমন সর্বোচ্চ ৪টা FAQ যায় (`agent.relevant_faqs`; বাংলা প্রত্যয় ছাঁটা, বাংলা অঙ্ক→ইংরেজি, Banglish→বাংলা `_SAME` তালিকা)। কাস্টমার বটে শেষ ৩টা user মেসেজ দিয়ে মেলানো, টেকনিশিয়ানে বর্তমান মেসেজ। অতিরিক্ত নির্দেশনা সবসময় পুরো যায়। প্যানেলের "বট এখন যা নির্দেশনা পায়" সব FAQ দেখায়। পেমেন্ট তালিকা: মেসেজে পেমেন্ট/টাকা/বিকাশ... না থাকলে শুধু শেষ পেমেন্ট। ইতিহাস: কাস্টমার ও টেকনিশিয়ান দুটোতেই শেষ ৬টা মেসেজ।

## টিকিট বিস্তারিতে লাইনের তথ্য (2026-10-09)
- টিকিট লিস্টে সারিতে ক্লিক (`recordAction("view")`) বা "বিস্তারিত" → মোডালের নিচে "কর্মী যোগ / বাদ" বাটন (একই `TicketActions::assign()`, বর্তমান কর্মীরা আগে থেকে বাছা, বাদ দিলে বিলিংয়ে AddSolver নতুন তালিকা পায়; অন্তত একজন লাগে)। মোডালে "কাস্টমারের লাইন এখন" সেকশন: নতুন-টিকিট ফর্মের একই বক্স (`TicketActions::customerInfo($headerId)` → engine `/internal/{cid}/customers/{header_id}/ticket-info`, ৬০ সেকেন্ড cache): বিল, MikroTik (অনলাইন/অফলাইন, রাউটার, uptime, IP, MAC, শেষ অনলাইন), OLT/ONU (OLT, পোর্ট, স্ট্যাটাস, Rx/Tx dBm, দূরত্ব, তাপমাত্রা; আমাদের OLT না পেলে বিলিং)। `billing_tickets.customer_header_id` দিয়ে কাস্টমার মেলে। PPPoE পাসওয়ার্ড দেখানো হয় না।
