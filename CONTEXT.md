# Zyro ISP Support — Context (সংক্ষিপ্ত)

এটা AI এজেন্টের জন্য মূল context। পুরনো পুরো ইতিহাস (2026-10-04 থেকে 2026-11-05, ~২৯০ KB) আছে
[`docs/archive/CONTEXT-full-2026-11-05.md`](docs/archive/CONTEXT-full-2026-11-05.md)-এ। কোনো ফিচারের খুঁটিনাটি
(endpoint-এর ঘর, migration নাম, পুরনো বাগের কারণ) লাগলে সেখানে শিরোনাম/শব্দ দিয়ে `grep` করুন, পুরো ফাইল পড়বেন না।

**এই ফাইল আপডেটের নিয়ম:** কাজ শেষে নিচের ঠিক অংশে ১-৩ লাইনে লিখুন (কী, কোথায়, কোন সুইচ/টেবিল, বাকি কী)।
লম্বা বিবরণ, deploy লগ, মাপজোখ এখানে নয়; দরকার হলে `docs/`-এ আলাদা ফাইল করে লিংক দিন। ফাইল ~৪০ KB ছাড়ালে
পুরনো অংশ `docs/archive/`-এ নতুন ফাইলে সরিয়ে এখানে সারাংশ রাখুন।

## ১. নিয়ম (সবসময়)
- কোনো পাসওয়ার্ড/টোকেন/`.env` রিপোতে নয়। বিলিংয়ের রেসপন্সে PPPoE পাসওয়ার্ড plaintext আসে: কখনো দেখানো/লগ/jsonb-তে রাখা নয় (customer_history `clean()` ফেলে দেয়)। বট কাস্টমারকে মোবাইল, IP, MAC, পাসওয়ার্ড, MikroTik profile বলে না।
- সার্ভারের `ufw` আর রাউটারের সেটিং Zisan-এর স্পষ্ট নির্দেশ ছাড়া নয়। `/var/www/hybrid-stack/asr920_router_context.md` (ক্রেডেনশিয়াল) কখনো রিপোতে নয়।
- বট নিজেকে AI বলবে না; নাম বিলিংয়ের মতো হুবহু। উত্তর ছোট কথ্য বাংলা, বানানো তথ্য নয়, অ্যাডমিনের নির্দেশনা চূড়ান্ত।
- Desk-ই ধীরে ধীরে মূল; বিলিং (ispdigital) প্রতিটা ফিচারে ঐচ্ছিক সুইচ (SaaS, কোম্পানি-ভিত্তিক)। Desk বিলিংয়ের সেটিং/কনফিগ বদলায় না।
- নতুন UI লেখা: `__('বাংলা')` + `panel/lang/en.json`-এ ইংরেজি (LocaleTest না মিললে ফেল)। const/property ডিফল্টে `__()` চলে না। কাস্টমারমুখী লেখা (SMS টেমপ্লেট, রসিদ, স্টিকার, বটের লেখা) বাংলাই।
- নতুন সাইডবার মেনু: `AppPanelProvider::NAV_GROUPS`-এর কোনো গ্রুপে, trait `QuickAccessible`, `canAccess()`-এ `Menu::can('<key>')`; key `app/Support/Menu.php`-এ।

## ২. সিস্টেম এক নজরে
- সার্ভার bsol.zyrotechbd.com (103.157.253.197, Ubuntu 24.04), ইউজার `claude-dev` (sudo)। কোড `/var/www/zyro_isp_support` (রিপো `rzzisan/zyro_isp_support`, ব্রাঞ্চ main)। সার্ভারের আপস্ট্রিমে SIP 5060/5061 ব্লক।
- **panel/** = Laravel 13 + Filament 5, https://desk.zyrotechbd.com। প্যানেল `/super` (সুপার-অ্যাডমিন) আর `/app/{company}` (tenancy; Century Link slug `century-link-network`, company id 1)। PHP-FPM www-data। API `/api/v1` (Sanctum) Android অ্যাপের জন্য (`routes/api.php`)।
- **engine/** = Python FastAPI, `zyro-engine` 127.0.0.1:8994: WhatsApp webhook, বট, বিলিং ক্লায়েন্ট, প্যানেলের `/internal/{company}/...` endpoint, sync স্ক্রিপ্ট। সিক্রেট `engine/.env`, venv `.venv/`। চালানো: `set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.<module>`।
- DB PostgreSQL `zyro_support` (লাইভ UTF8, session UTC; সময় তুলনা SQL `now()` দিয়ে)। টেস্ট DB `zyro_support_test` (SQL_ASCII: Python-এ `client_encoding=utf8`)। Laravel `encrypted` cast (APP_KEY) Python-এ `db.encrypt/decrypt` দিয়ে পড়া যায়।
- nginx: `support.zyrotechbd.com` শুধু `/webhook` → engine (বাকি → desk-এ redirect); desk-এ `/pay/` → engine (bKash), `/sip-ws` → Asterisk WS, `/apk/` ডাউনলোড। কনফিগ `deploy/nginx-*.conf`। সার্টিফিকেট `*.zyrotechbd.com` wildcard (dns-cloudflare)।
- পুরনো বট `app/` + `zyro-support` (support.zyrotechbd.com:8993) বন্ধ 2026-10-06; ডেটা `data/messages.db`।
- **systemd সার্ভিস:** zyro-engine, zyro-voice (`engine/voice_bot.py`, Asterisk-এর ভয়েস বট/IVR, HTTP 127.0.0.1:8997, AudioSocket 9093, FastAGI 4574), zyro-sip (baresip কলার পপআপ), zyro-walkie (MemoryMax=2G), zyro-livekit, asterisk।
- **timer (deploy/-এ unit):** ticket-sync ৫ মি., ppp-sync ২ মি., olt-sync ১০ মি., customer-sync রাত ৩টা BD, customer-new ২০ মি., customer-history রাত ১২:৩০-৭টা BD, daily-bill-sync ১০ মি., online-payment-sync ৩ মি., sms-send/wa-campaign/voice-reminder/system-backup প্রতি মিনিট, sms-sync ৩০ মি., sms-reminder ঘণ্টায় :05, expire-date ঘণ্টায় :20, ticket-followup ১০ মি., mikrotik-backup 21:00 UTC, pbx-cdr ০১:০০ BD, alt-numbers ০৩:৪০ BD, desk-billing 01:15 UTC, desk-disable-shadow ঘণ্টায় :05।

## ৩. Deploy ও গোটচা (লাইভে)
- স্ট্যান্ডিং অনুমতি: Zisan যা চান তার PR merge, deploy, APK build জিজ্ঞেস ছাড়াই। লাইভ ডেটা বদল/bulk delete/না-চাওয়া কাজে আগে জিজ্ঞেস।
- ধাপ: `git pull` + `php artisan migrate` **একসাথে** (merge আগে migrate পরে হলে নতুন কলামে 500 হয়) → `config:cache` (claude-dev, তারপর cache ফাইল chgrp www-data, 640) → `sudo -u www-data php artisan route:cache && filament:optimize && view:cache` → php-fpm reload → দরকার হলে `systemctl restart zyro-engine`। যাচাই: engine `/health` 200, পেজ 302 → login, laravel.log-এ নতুন এরর নেই।
- **লাইভে কখনো `config:clear` নয়** (www-data `.env` পড়তে পারে না, 500 হয়)। `view:cache` claude-dev হিসেবে দিলে 500 (www-data লাগবে)।
- `zyro-voice` restart শুধু চলতি কল ০ হলে, এক কমান্ডে শর্ত দিয়ে। Asterisk কনফিগ: `deploy/asterisk/*.conf` → `/etc/asterisk/` (asterisk:asterisk 0640), তারপর `pjsip reload`/`dialplan reload`, নতুন transport হলে restart (০ কলে)।
- টেস্ট (লাইভ cache না ছুঁয়ে): `APP_CONFIG_CACHE=/tmp/nocfg.php APP_ROUTES_CACHE=/tmp/noroutes.php DB_CONNECTION=pgsql DB_DATABASE=zyro_support_test APP_ENV=testing php artisan test`। লাইভ vendor-এ dev প্যাকেজ নেই: worktree-তে `composer install --no-scripts && php artisan package:discover`। engine টেস্ট `engine/tests/` (unittest)।
- Windows PC (PowerShell 5.1) থেকে বাংলা ফাইল Get/Set-Content দিয়ে নষ্ট হয়; scp করে সার্ভারে CRLF/BOM সরান।
- Filament গোটচা: table/filter closure-এ `$query`, `formatStateUsing`-এ `$state` নামেই প্যারামিটার; `Resource::getUrl('index', [...])` (শুধু অ্যারে দিলে পুরো প্যানেল 500)। Global model listener-এ `auth()->check()` নয় (ভুল ইউজার cache হয়)। PHP অ্যারেতে মোবাইল নম্বর key int হয়ে যায়।
- engine-এর `main.py`-তে `customers` নামে ফাংশন নয় (মডিউল ঢেকে যায়)।

## ৪. বিলিং সফটওয়্যার (ISP Digital v8.2.4, SoftifyBD)
- https://centurylink.ispdigital.cloud, ASP.NET MVC, অফিসিয়াল API নেই; লগইনের পর DataTables JSON। ক্লায়েন্ট `engine/ispdigital.py` (`ISPDigital`), PHP-তে `IspDigitalClient`। বট/sync কোম্পানির লগইনে (`supportassistant`), স্টাফের কাজ সদস্যের নিজের বিলিং লগইনে (`billing_for_user`; বিলিং লগইন শুধু Owner দেন; বিল/লাইনে owner fallback নেই)।
- এক লগইনে বিলিং একটাই সেশন রাখে: সেশন কুকি `data/billing-sessions/<hash>.json`-এ ভাগ করা, লগইন flock দিয়ে। ব্রাউজারের মতো header + `VmAuthTracer` না দিলে logoff।
- প্রধান endpoint (বিস্তারিত আর্কাইভে): কাস্টমার `/Customer/AjaxCustomerList`, বিল `/Billing/AjaxCustomerBillList`, Left `/Customer/AjaxLeftCustomerList`, হেডার সার্চ `/Customer/GetAllCustomerUserName[WithoutLeft]`, টিকিট `/ClientSupport/AjaxDailyComplainList` + `AjaxMonthlyComplainList`, টিকিট খোলা/এডিট POST `/ClientSupport/DailyComplainList`, Solve `/ClientSupport/Solved/`, লাইন চালু `/Billing/EnableSelectedClients`, বিল নেওয়া `/DailyAccountClosing/BillReceivePartial` → `DailyBill`, approve `ApproveSelectedTransaction/`, দৈনিক বিল `AjaxDailyBillQuery`, অনলাইন পেমেন্ট `WebhookPaymentNotifications` / `PaybillPaymentTransactions`, কনফিগ তালিকা `/Config/Ajax*`, কাস্টমার Edit `POST /Customer/Edit`।
- জানা ফাঁদ: বিল ফর্মে `Discount` disabled থাকে, তবু সবসময় পাঠাতে হয়; approve না হলে বকেয়া কমে না; বিল POST ২০+ সে. লাগতে পারে (timeout 90, তারপর pending তালিকায় মেলানো); Edit ফর্মে `IsMikrotikChanged` কখনো পাঠানো নয় (বন্ধ লাইন চালু হয়ে যায়); SubZone/Box/Speed/ConnectedByIds JS ভ্যারিয়েবল থেকে পড়তে হয়; সময় বাংলাদেশ সময়ে নানা ফরম্যাটে, DB-তে UTC; খালি তারিখ `01-Jan-0001` (১৯০০-এর আগে = খালি)।
- Expire Date = `BillingLastDate` = `billing_customers.bill_day`। বিলিং লাইন বন্ধ করলে MikroTik profile `BLOCKED-PROFILE` করে (secret মোছে না)।
- বিলিংয়ের অটো due SMS বন্ধ করার অপশন নেই (ডিফল্ট ১ দিন আগে); বন্ধ করতে হলে SoftifyBD।

## ৫. ডেটা কপি (Desk-এর নিজস্ব টেবিল)
- `billing_customers` (রাতে পূর্ণ sync, ২০ মিনিটে নতুন ID, মনিটরিং পেজ খুললে ১০ মিনিটের পুরনো হলে refresh; Left = `is_left`)। "বর্তমান কাস্টমার" = `gone_at IS NULL AND NOT is_left`।
- `billing_customer_*` (bills/payments/remarks/sms/change_logs/line_logs/invoices): `engine/customer_history.py`, পেজ খুললে বা রাতে। change log সারি = বদলের পরের অবস্থা।
- `billing_tickets` (`source`: null = sync, `history`, `desk` = Desk-এর নিজের টিকিট; sync কখনো `desk` সারি ছোঁয় না)। `billing_daily_bills`, `billing_online_payments`, `billing_options` (কর্মী/ডিপার্টমেন্ট/ক্যাটাগরি/priority + ১০টা কনফিগ তালিকা, শুধু হাতে Sync)।
- নেটওয়ার্ক: `mikrotik_routers` (RouterOS API নিজস্ব ক্লায়েন্ট `engine/mikrotik.py`, শুধু পড়া), `ppp_sessions` (+profile, blocked), `olts`/`onus`/`customer_onus` (`engine/snmp.py`, `olt_sync.py`; ড্রাইভার BDCOM, VSOL, ECOM/C-Data; BDCOM-এ GETBULK/walk দিলে SNMP বন্ধ হয়, শুধু GET; প্রতি OLT আলাদা প্রসেস)।
- লাইভ অবস্থা `engine/customers.py: live_context`: আগে MikroTik (`online_now`), MAC → নিজের OLT; বিলিং শুধু ফাঁক পূরণে (সুইচ `billing_connections.live_from_billing`, `onu_from_billing`; গণনা `billing_fallbacks`)।

## ৬. ফিচার মানচিত্র (কোথায় কী)
**WhatsApp ও বট**
- Meta Cloud API, app Zyrotech BSOL (1900768904642203), নম্বর +880 1777-858289 (phone id 1309537728917134), webhook https://support.zyrotechbd.com/webhook। Display name DECLINED, মেসেজ LIMITED (2026-10-22 অবস্থা)। নম্বর-লুকানো ইউজার (BSUID) `wa_contacts.wa_user_id`, পাঠানো `recipient`।
- engine `main.py` (burst অপেক্ষা ৭ সে., শেষ মেসেজের উত্তর, superseded), `agent.py` (key বাছাই, FAQ `relevant_faqs`, `REPLY_RULES`/`IMAGE_RULE`/`RECONNECT_RULE` সবসময় যোগ), `llm.py` (built-in prompt), `technician.py` (টেকনিশিয়ান নম্বর = স্টাফ মোড: যেকোনো কাস্টমারের তথ্য, `[[TICKET]] [[ENABLE]] [[NOTIFY]] [[SOLVE]]`)। মার্কার কাস্টমার বটে `[[TICKET]] [[SOLVED]] [[RECONNECT]]`।
- পরিচয়: নিবন্ধিত নম্বর/ID/মোবাইল দিলেই verified (Zisan 2026-10-11: কড়া যাচাই নয়); এজেন্ট চ্যাটে ID দিলে (`wa_contacts.customer_set_at`) সেটাই চূড়ান্ত। বাংলা অঙ্ক, লিংক/IP/MAC বাদ দিয়ে ID খোঁজা।
- ভয়েস মেসেজ পড়া Gemini (fallback Groq Whisper), ভয়েসে উত্তর Gemini TTS (Kore), ছবি পড়া Claude (`see_image`, অপ্রাসঙ্গিক ছবিতে চুপ)।
- বট সেটিংস: mode, allowlist, signature, extra_prompt, এডিটযোগ্য মূল prompt (null = built-in), FAQ (`bot_faqs`), self_reconnect (বিলের কারণে বন্ধ লাইন মাসে একবার, নম্বর-প্রতি সীমা ২)।
- টিকিট অটোমেশন: স্লো অভিযোগে টিকিট, ২০-২৩.৫ ঘণ্টায় ফলো-আপ, কাস্টমার বললে solve (MikroTik-এ অনলাইন হলেই)।
- ইনবক্স `/inbox`: রিপ্লাই, assign, বট থামানো, মিউট, মেসেজ ডিলিট, নাম সিগনেচার, উপরে লাইনের লাইভ অবস্থা + টিকিট Solve। নোটিফিকেশন: বেল + Web Push (`engine/webpush.py`) + অ্যাপে FCM (`engine/fcm.py`)।
- "WhatsApp পাঠান" টেমপ্লেট ক্যাম্পেইন (`wa_campaigns`, `engine/wa_campaign.py`), টেকনিশিয়ানকে টিকিট assign-এর WhatsApp (`ticket_notify`, টেমপ্লেট `ticket_assigned`)।

**টিকিট**
- `/tickets`: খোলা, এডিট, assign, Solve (অনলাইন যাচাই, ব্লক/অফলাইনে আটকায়), প্রিন্ট (কর্মী/ফিল্ড টিম), আগের/পরের নেভিগেশন। এক কাস্টমারের একসাথে একটাই খোলা টিকিট। Owner শুধু Desk টিকিট ডিলিট করতে পারেন।
- Desk মোড: `billing_connections.tickets_in_billing` বন্ধ = নতুন টিকিট Desk-এ (`engine/desk_tickets.py`, নম্বর 100001 থেকে, `ticket_events`); `ticket_lists_in_billing` বন্ধ = তালিকা Desk-এ (Century Link-এ বন্ধ করতে বলা হয়েছিল)। Desk-এ বানানো কর্মী/ধরন বিলিং টিকিটে চলে না।
- অটো assign (`engine/ticket_autoassign.py`, `area_staff`): একই বক্স → সাব-জোন → জোন-এর শেষ কর্মী, ক্যাটাগরি অনুযায়ী, ফিল্ড টিম (`staff_teams`) পুরোটা।

**কাস্টমার ও বিল**
- কাস্টমার পেজ: লাইভ অবস্থা, ১১টা ট্যাব (বিলিংয়ের প্রোফাইলের সব), হেডার/global search (বিকল্প নম্বরসহ), CSV, PPPoE পাসওয়ার্ড দেখা (owner/admin, লগসহ)।
- বিল নেওয়া (`engine/bills.py`): pending থাকলে আটকায়, অগ্রিম চলে, `bill_approve` অনুমতি থাকলে সাথে সাথে approve (পেছনে), রিসিট প্রিন্ট (থার্মাল 80/58mm, `print/receipt`), পরের বিলিং তারিখ সেট ডিফল্ট চালু (৮০%+ বকেয়া নিলে; মেয়াদের দিনের আগে এক মাসের বকেয়া দিলে বন্ধ: `BillActions::paidEarly`), ফর্মে "কতদিন ব্লক"। "বিল কালেকশন" = বিলিংয়ের সব দৈনিক বিল (লাইভ ৫ সে.), দেখার সীমা `bill_view_employees`।
- অনলাইন পেমেন্ট `/online-payments` (bKash/Nagad webhook + PayBill, Desk সেটেল করে না)। bKash মার্চেন্ট webhook সরাসরি Desk-এ (`engine/bkash_webhook.py`, URL `/pay/bkash/webhook/{slug}`, SNS signature যাচাই, নিজে জমা + লাইন চালু) — কোড আছে, **লাইভ সুইচ বন্ধ**; চালুর ক্রম আর্কাইভে।
- মেয়াদ বদল (`engine/expire_dates.py`, সেটিংস → মেয়াদ বদল): বন্ধ লাইন বিল দিয়ে চালু হলে Expire Date = পেমেন্টের দিন (≥৮৫%, সর্বোচ্চ ২৮); bulk চালান শুধু Owner; `expire_auto` সুইচ।
- লাইন চালু/বন্ধ রেকর্ড `line_enables`। বিকল্প নম্বর `customer_alt_numbers` (রাতে rebuild, স্টাফ যোগ/সরান)।
- ONU স্টক ও নতুন সংযোগ (মডিউল `onu`, শুধু Century Link; পুরনো zisan.me/onu থেকে import `php artisan onu:import`; cutover দিন বাকি)।

**SMS**
- Desk সরাসরি গেটওয়ে দিয়ে (`engine/sms.py`, `engine/sms_gateways/`): Khudebarta (`http://portal.khudebarta.com:3775`) আর Bulk SMS Dhaka (IP whitelist লাগে), মূল + ব্যাকআপ, ৬ ঘণ্টার পুরনো SMS বাতিল। টেমপ্লেট ধরন general/bill_paid/ticket_open/ticket_solved/bill_reminder। `sms_settings.enabled` বন্ধ = কিছুই যায় না। বিলিংকে সবসময় SMS বন্ধ পাঠানো হয়।

**ফোন ও কল**
- হটলাইন pbx.bd (FusionPBX, century.alphapbx.net), DID 9613009900 → রিং গ্রুপ 800, Desk এক্সটেনশন 106, রেজিস্ট্রেশন UDP 8090, trunk একসাথে সর্বোচ্চ ৪ কল। কলার পপআপ (baresip), PBX CDR টানা (`pbx:cdr-pull`)।
- ভয়েস বট (Asterisk 20 + Gemini Live; বিকল্প `voice_cascade.py` = STT → Claude Haiku → TTS), টুল lookup/complaint/solve/reconnect/search_faq, রেকর্ডিং MP3 + কল লগ (outcome)। সেটিং হটলাইন (SIP) পাতায়।
- WhatsApp কল: Meta SIP → Asterisk TLS **15061** (`engine/wa_calling.py` CLI), calling_mode test (টেস্ট নম্বর 01782703244), কল আইকন দেখানো। Desk সফটফোন (WebRTC, `/sip-ws`) দিয়ে স্টাফ কল ধরে ও কাস্টমারকে কল করে; ডিউটি সময় `company_user.call_hours`।
- ভয়েস বিল রিমাইন্ডার (`engine/voice_ivr.py`, `php artisan voice:reminders`, কলে AI নেই, আগে বানানো অডিও + বাটন, বিকল্প নম্বর, নিয়মিতদের SMS সাশ্রয়)। ডিফল্ট বন্ধ + টেস্ট মোড।
- ওয়াকি-টকি (নিজস্ব LiveKit, `wss://rtc.zyrotechbd.com`, `engine/walkie.py` রেকর্ড) আর টিম চ্যাট (১:১ + ওয়াকির গ্রুপ, ৯০ দিনে মিডিয়া মোছে)।

**AI**
- AI key কোম্পানি-ভিত্তিক (`ai_keys`, এনক্রিপ্টেড); "কোন কাজে কোন key" (`ai_key_jobs`, একাধিক key ক্রমে, যেকোনো 4xx/5xx-এ পরের key)। খরচ `ai_usage` + "AI খরচ" পেজ। Gemini TTS দৈনিক সীমা আছে (key 5 পেইড ১০০/দিন/মডেল, ০৭:০০ UTC রিসেট; শেষ হলে key রিসেট পর্যন্ত বিশ্রাম)।

**অ্যাডমিন/সিস্টেম**
- টিম, রোল (owner/admin/agent), মেনু অনুমতি (`company_user.permissions` ∪ পারমিশন গ্রুপ), কুইক অ্যাক্সেস (owner বাছেন), নিজের পাসওয়ার্ড বদল, বাংলা/English (`users.locale`)।
- MikroTik রাতের ব্যাকআপ (`engine/mikrotik_backup.py`; CLN_4 RouterOS 7.12 + IP সীমায় ব্যর্থ, Zisan-এর সিদ্ধান্ত বাকি)।
- পুরো সিস্টেম ব্যাকআপ Google Drive-এ (`engine/system_backup.py`, rclone crypt, /super → সিস্টেম ব্যাকআপ; রিস্টোর `docs/restore.md`)।
- কাজের লগ `activity_logs` (append-only, hash chain; শুধু Owner দেখেন)।

**Android অ্যাপ** `android/` (Kotlin + Compose, `com.zyrotechbd.desk`)
- বিল্ড সার্ভারে `cd android && ./build-apk.sh` (claude-dev), APK `panel/public/apk/zyro-desk.apk`, পেজ https://desk.zyrotechbd.com/apk/। প্রতি রিলিজে `app/build.gradle.kts`-এ versionCode বাড়ান।
- সাইনিং key `/etc/zyro-desk/desk-release.jks` (পাসওয়ার্ড `/etc/zyro-desk/README`), Firebase `/etc/zyro-desk/firebase-service-account.json` + `google-services.json`। এই key-গুলো হারালে আপডেট ইনস্টল হবে না: ব্যাকআপ রাখুন।
- রুট `.gitignore`-এর `data/` অ্যাপের `data/` প্যাকেজ লুকায়; negation আছে, নতুন ফোল্ডারে সাবধান।

## ৭. নিজের বিলিং (চলমান বড় কাজ)
- প্ল্যান `/mnt/project-files/billing-system/plan.md` (রিসেলার: `reseller-plan.md`)। লক্ষ্য cutover 1 Jan 2027 (মিল <৯৯% হলে Feb)। **billing_mode সব কোম্পানিতে `ispdigital`**: Desk এখনো শুধু ছায়া হিসাব রাখে, বিলিংয়ে কিছু লেখে না।
- ধাপ ০ লাইভ (PR #110-#117): partition করা `activity_logs`, `desk_bills`, `desk_payments`, `desk_ledger_entries` + `desk_customers`, `desk_packages`, `desk_customer_balances`, `billing_settings`। `engine/desk_billing.py` রোজ কপি করে খাতা ও balance বানায় (বিলিংয়ে request নেই)। সেটিংস → "বিলিং নিয়ম" (`/billing-rules`, Owner; মেলানো থেকে বাদ: 0001 = Zisan-এর হাউস অ্যাকাউন্ট)।
- ধাপ ১ (PR #118, main 5690ee6): `desk_customer_balances.diff/mismatch_reason/mismatch_since`, `desk_shadow_bills` (Desk-এর মাসিক বিল বনাম বিলিংয়ের), timer এখন `--partitions --import --shadow`; পেজ `/billing-due-match`, `/billing-shadow-bills`। মিল ~৯৯.২% (5935/5981)। বিলিং paid/inactive কাস্টমারের due খালি রাখে (= ০ বা শেষ বিল-সারির due)।
- ধাপ ২ (বন্ধের তালিকা ছায়া): `engine/desk_disable.py`, টেবিল `desk_disable_shadow`, timer `zyro-desk-disable-shadow` (ঘণ্টায় :05; কোম্পানির `disable_hour`-এ দিনে একবার তালিকা, তারপর বিলিং-এর enable/disable লগের সাথে মেলানো, রাতের কপির পরেও)। নিয়ম: মাসের বিলের শেষ দিন = Expire + `disable_when` দিন; রাখা হয় VIP/EffectiveTo/৩ দিনে চালু/অল্প বাকি। পেজ `/billing-disable-shadow`; নতুন সেটিং `disable_min_due`। কারো লাইন বন্ধ করে না।
- ধাপ ৩ (MikroTik সরাসরি): `engine/desk_line.py` প্রোফাইল ব্লক/ফেরত বা secret disable, সেশন kill, রাউটার আবার পড়ে যাচাই + `mikrotik_retries`; টেবিল `desk_line_actions`, `mikrotik_routers.block_profile`, সেটিং `line_control` (off/test/all; all শুধু billing_mode desk) + `line_test_codes`। পেজ `/billing-line-actions`, endpoint `/internal/{c}/desk-line`। রাউটারের API ইউজারের write অনুমতি লাগে। বিলিং-এ কিছু লেখে না।
- নতুন অনুমতি (এখনো কাজে নেই): `bill_edit`, `payment_delete`, `payment_transfer`।

## ৮. বাকি / Zisan-এর সিদ্ধান্তের অপেক্ষায়
- Gemini key-এর billing tier বাড়ানো; Bulk SMS Dhaka IP whitelist/key/লাইভ টেস্ট (সফল উত্তরের ফরম্যাট মেলানো বাকি)।
- bKash webhook লাইভ চালু (nginx `/pay/`, সেটিং, bKash-কে URL, ১০ টাকার টেস্ট)।
- WhatsApp কল লাইভ মোড; Display name আবার জমা।
- ONU স্টকের cutover দিন; পুরনো zisan.me/onu-এর web root-এ DB dump/.git খোলা (নিরাপত্তা)।
- CLN_4 MikroTik ব্যাকআপ (ssh/ftp-এ 10.85.1.13/32 যোগ বা RouterOS 7.13+)।
- বটের FAQ উত্তর (অফিস সময়, Wi-Fi পাসওয়ার্ড, রাউটার লগইন নীতি, ৫৫০ টাকা 20Mbps + Bongo অফার, FTP লিংক)।
- টিকিট 2556/3564/3697 ডুপ্লিকেট; ওয়াকি-টকি ও টিম চ্যাট টেস্ট; ভয়েস রিমাইন্ডার লাইভ চালু।
- নিজের বিলিং: অমিলগুলো (~৪৬) পরের রাতের কপির পর আবার দেখা; ধাপ ২-এর বন্ধের তালিকা কয়েক দিন মিলিয়ে নিয়ম ঠিক করা, তারপর ধাপ ৪/৩।
- VPS-এ সরানো নিয়ে সিদ্ধান্ত (Hostinger KVM 4 প্রস্তাব; OLT/MikroTik প্রাইভেট IP-তে, তাই WireGuard লাগবে)।
- আগে থেকে ফেল করা কয়েকটা পুরনো টেস্ট (CustomerTest left তারিখ, TicketActionsTest টাকা ফরম্যাট, AreaStaffTest, SoftphoneTest APP_URL) — নতুন কাজের দোষ নয়।

## ৯. সম্পর্কিত ডক
- `docs/`: saas-plan, customer-profile-plan, multilang-plan, billing-sms-service, olt-snmp-plan, restore, meta-app-review, customer-cache-plan।
- প্রজেক্ট ফাইল `/mnt/project-files/`: billing-system/, billing-expire/, sms-bulksmsdhaka/, voice-bot/, voice-reminder/, walkie-talkie/, staff-chat/, desk-backup-plan.md, onu-to-desk-plan.md, bot-reply-review-2026-10-07.md।
- নেটওয়ার্ক: `/var/www/hybrid-stack/` (git-ignored), captive portal `/var/www/captive-portal/context.md`।
- বট সিমুলেশন/নিয়মের উদাহরণ: `training/simulations/`।
