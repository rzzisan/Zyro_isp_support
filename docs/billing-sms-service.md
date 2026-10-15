# SMS: বিলিংয়ের "SMS Service" (রেফারেন্স) আর Desk-এর নিজের SMS

তারিখ: 2026-10-15। Zisan-এর সিদ্ধান্ত: **Desk-এর সব SMS সরাসরি Khudebarta গেটওয়ে দিয়ে যাবে**; বিলিংয়ের `/sms/SendAsync`
দিয়ে পাঠানো হবে না, বিলিংয়ের টেমপ্লেট/লগের সাথে sync নেই। নিচের বিলিং অংশ শুধু রেফারেন্স (কী ছিল, কেন এভাবে বানানো)।

## ১. বিলিংয়ের SMS Service (ISP Digital, শুধু পড়ে দেখা; কোনো SMS পাঠানো হয়নি)

মেনু "SMS Service" → ৫টা পেজ। সব পেজ লগইন সেশনে, `permissionId` 1 = full।

| পেজ | URL | কী আছে |
|---|---|---|
| Individual SMS | `/sms/newsms` | Send To (`#number`, কমা দিয়ে কয়েকটা, যেমন 8801670000900), Select Template (`#template`, option value = পুরো লেখা), Length, Cost (খালি থাকে, কোনো হিসাব নেই), SMS Description (`#message`) |
| SMS Template | `/SMSTemplate/Index` | DataTable `/SMSTemplate/AjaxTemplates` (TemplateId, Name, Template, Vairables, Type default/None)। ২৩টা: ২১টা সিস্টেমের অটো-SMS (Due, Money Receipt, Support Created/Solved, Client Enable/Disable ...; ভেরিয়েবল `{CustomerName}` `{Due}` `{UserName}` ...), ২টা নিজের (nocall, live tv)। Add/Update: multipart `POST /SMSTemplate/Add?Length=11` (`__RequestVerificationToken`, TemplateId, Name, Template) → `{IsSucceeded, Message}`; Delete `GET /SMSTemplate/Delete/{id}` |
| SMS Group | `/MemberGroup/Index` | সেভ করা গ্রুপ, DataTable `/MemberGroup/AjaxMemberGroups` (status ফিল্টার)। গ্রুপ = সদস্যের ধরন (customer/employee/POP) + Customer Status/Zone/Sub Zone/Box, তারপর হাতে সদস্য বাছাই |
| Send SMS | `/SMS/SendGroupSMS` | বিল্ট-ইন গ্রুপ: 1 All, 2 Paid, 3 Due, 4 Collected, 5 Unpaid, 6 Active, 7 Inactive, 8 MAC Reseller, 10 Bandwidth Reseller, 11 Employee, 13 Left, 14 Reseller। "Transfer" → `GET /SMS/GetGroupUsers?id=3,&permissionId=1` → `[{Name, Mobile (01XXXXXXXXX), UserType (2 = customer), ReferenceId (= CustomerHeaderId), IsChecked}]` (All 5941, Due 2446, Left 1110 জন; ~১ সে.) |
| SMS Gateway | `/companysettings/smsgateway` | কার্ড: SMS Balance, Todays Send, This Month Send, This Month Failed (পেজের JS-এ সংখ্যা, 2026-10-15: 1496.25 / 161 / 5190 / 673)। ফর্ম `POST /CompanySettings/SmsGatewayAddOrUpdate`: SmsProvider (45 = Khudebarta (v2.0), আরও ~৫৫টা), SMSUserName, SMSSender, SMSPassword (পেজে plaintext value থাকে — কখনো লগ/কপি নয়) |

পাঠানো (Individual ও Send SMS দুটোতেই একই): `POST /sms/SendAsync` (jQuery form-urlencoded, anti-forgery টোকেন লাগে না)
`vmSms[SMSType]=23` (Others), `vmSms[SMSText]`, `vmSms[CreatedBy]` = লগইন কর্মীর id (পেজের JS `loggedInUserId`),
`vmSms[ContactList][i][ContactNumber]`, `[UserType]` (Individual 5 = Others, গ্রুপে 2 = Customer), `[ReferenceId]` (header id বা 0)।
উত্তর `"Success"`, নইলে HTTP কোড (401/403/404/414/500/503)।

রিপোর্ট (Report মেনু):
- Messages Report `/report/textmessages` → DataTable `/Report/AjaxTextMessages` (`fromDate`/`toDate` dd-mm-yyyy, `toSendType` (1 Employee, 2 Customer, 3 MACReseller, 4 BandwidthReseller, 5 Others, 7 RequestedNewCustomer ...), `toSendId`, `sendById`, `msgTemType` (1 Money Reciept, 2 Due, 3 Line Man Support, 4 Support Solved, 5 Support Created, 6 Client Enable, 7 Client Disable, ... 23 Others, ... 34), `msgStatus` (1 Success, 2 Pending, 3 Failed))। Row: SMSLogId, UserType, UserId, ContactNumber, CustomerName/EmployeeName/..., SMSType, SMSTypeName, SMSText, SendingDate, DeliveredDate, SmsStatus, Status, SendBy। এক দিনের ১৬৩টার ১০০টাই অটো (Due 81, Money Receipt, Bill Collection Receipt, Support)। Failed/Pending আবার পাঠানো: `POST /Report/ReSendFailedOrPendingSms/` (`smsLogIds[]`) → `{successMessage, errorMessage}`।
- SMS History `/Report/UnpaidCustomerSms` → `/Report/AjaxUnpaidCustomerSms` (বকেয়া SMS-এর তালিকা: Client Code, Name, Mobile, Billing Month, Days Before)।
- Desk আগে থেকেই কাস্টমারদের SMS কপি করে (`engine/customer_history.py` `sync_sms`, `billing_customer_sms`, কাস্টমার পেজের SMS ট্যাব) — সেটা বদলায়নি।

## ২. Desk-এর SMS (2026-10-15)

### গেটওয়ে (Khudebarta, BSOL-এর মতো)
BSOL (`backend/app/Http/Controllers/AdminSmsGatewayController.php`, `SmsAutomationService.php`) যেভাবে পাঠায়:
form POST `endpoint_url` (BSOL-এর অ্যাডমিন → SMS → Gateways-এ রাখা, কোডে নেই) — `apikey`, `secretkey`, `callerID` (sender ID),
`toUser` (8801XXXXXXXXX), `messageContent`; প্রতি নম্বরে আলাদা request, timeout 20 সে.।
একই প্ল্যাটফর্মের পাবলিক ডক অনুযায়ী উত্তর `{"Status":"0","Text":"ACCEPTD","Message_ID":"..."}`; অন্য Status (108 ভুল key,
109 ইউজার নেই, 114 লেখা নেই, 101 সার্ভার, 137 TPS, -42 auth) = refused। JSON না হলে BSOL-এর নিয়ম: 2xx আর
error/failed/invalid/unauthorized না থাকলে সফল। ডেলিভারি `getstatus?apikey&secretkey&messageid` → Text `DELIVRD` ইত্যাদি।
Century Link-এর send URL: `http://portal.khudebarta.com:3775/sendtext` (সেটিংসের ডিফল্ট)। পুরনো `http://118.67.213.114:3775` (BSOL-এও এটাই ছিল, শেষ সফল SMS 2026-09-25) এখন connection refused দেয়, সার্ভার আর অফিস PC দুই জায়গা থেকেই (2026-10-15)। key ছাড়া নতুন ঠিকানা `{"Status":"-1","StatusDescription":"Org Client Not Found","Text":"REJECTD"}` দেয়। প্রোফাইল "centurylinknetwork HTTP", TPS 1000।

ব্যালান্স, বাস্তবে (2026-10-15): পোর্টালের `/auth/login` ব্রাউজার ছাড়া অন্য সব client-কে `403 "Forbidden from Charset Filter"` দেয়, নকল ক্রেডেনশিয়ালেও (সার্ভার ও PC, httpx/curl HTTP/2/PowerShell, axios-এর হুবহু হেডার ও body দিয়ে চেষ্টা করা)। তাই পোর্টাল ব্যর্থ হলে engine বিলিংয়ের `/companysettings/smsgateway` পেজের `var smsBalance = …;` থেকে শুধু সংখ্যাটা নেয় (শেয়ার্ড বিলিং সেশন; একই Khudebarta অ্যাকাউন্ট; পেজের বাকি অংশ, যাতে গেটওয়ের পাসওয়ার্ড আছে, রাখা হয় না)।

ব্যালান্স: API-key দিয়ে কোনো ব্যালান্স call নেই (BSOL-এও নেই)। Khudebarta-র ওয়েব পোর্টালের API
(`https://portal.khudebarta.com:9494/api`, পোর্টালের JS আর Technical Details পেজ থেকে):
- লগইন `POST /auth/login` JSON `{"username","password"}` → `idToken` (JWT, ~১.৫ ঘণ্টা), `refreshToken`, `userID` (= providerID, Century Link 23688)। উত্তরে `otpGenerationType` বা `passwordExpiredAt` থাকলে হাতে পোর্টালে লগইন করে ঠিক করতে হবে।
- রিনিউ `GET /auth/refresh-access-token?oldToken=&refreshToken=` → `{left: নতুন idToken, right: নতুন refreshToken}`।
- ব্যালান্স `GET /util/get-current-balance?providerID=` হেডার `Authorization: Bearer idToken` → JSON (পোর্টালে 1495.55 BDT দেখাচ্ছিল)।
- Desk: পোর্টাল ইউজারনেম/পাসওয়ার্ড সেটিংসে (পাসওয়ার্ড এনক্রিপ্টেড), টোকেন `sms_settings`-এ এনক্রিপ্টেড; মেয়াদ থাকলে একই টোকেন, 401 হলে আগে রিনিউ তারপর একবার নতুন লগইন; লগইন ব্যর্থ হলে ১০ মিনিট নতুন লগইন হয় না (অ্যাকাউন্ট লক না হয়)। timer ৫ মিনিট পরপর, আর "ব্যালান্স রিফ্রেশ" বাটন।

### ডেটা (migration 2026_10_15_000001)
- `sms_settings` (কোম্পানি-প্রতি ১টা): endpoint_url, balance_url (`{provider_id}` বসে), provider_id, portal_login_url, portal_username, portal_password/portal_token/portal_refresh_token (encrypted), portal_token_expires_at, portal_login_failed_at, status_url, api_key + secret_key (Laravel encrypted, ফর্মে আর দেখানো হয় না), caller_id, verify_tls, **enabled** (বন্ধ = কিছুই যায় না), rate (টাকা/SMS ভাগ, শুধু হিসাব), balance, balance_checked_at, last_check_ok, last_error।
- `sms_templates`: name, body, sort।
- `sms_campaigns`: একবার "পাঠান" = একটা সারি (kind single/bulk, body, filters, total, sent, failed, parts, cost, status queued/sending/done/cancelled)।
- `sms_messages` = SMS লগ: প্রতি নম্বরে একটা (customer_header_id, mobile 8801..., placeholder ভরা text, parts, unicode, status queued → sending → sent → delivered | failed; cancelled; unknown, gateway_status/text, message_id, error, sent_at, delivered_at)।

### প্যানেল (মেনু গ্রুপ "SMS", অনুমতি `sms` — Owner/Admin ডিফল্ট, Agent-কে টিম পেজে দিতে হয়)
- **SMS পাঠান** (`/sms`, `SmsCampaignResource`): উপরে কার্ড (গেটওয়ে ব্যালান্স, আজ গেছে, এই মাসে SMS ভাগ + আনুমানিক খরচ, ব্যর্থ/অপেক্ষায়), প্রতিটা পাঠানোর অগ্রগতি (৫ সেকেন্ডে আপডেট), "SMS গুলো" (লগে ফিল্টার), "বাতিল" (যেগুলো যায়নি)। বাটন "SMS পাঠান" (`App\Filament\App\SmsActions`):
  - নির্দিষ্ট: কাস্টমার বাছাই (একাধিক) + অন্য নম্বর (কমা/লাইন); টাইপ করা নম্বর কোনো কাস্টমারের হলে তার তথ্যে placeholder ভরে।
  - ফিল্টার: তালিকা (বর্তমান/Left/সব), Zone, Subzone, Box, প্যাকেজ, Connection Type, অবস্থা, লাইন চালু/বন্ধ, বকেয়া আছে/নেই, বকেয়া কমপক্ষে, এখন অনলাইন/অফলাইন — সব Desk-এর `billing_customers` থেকে (বিলিং-এ যায় না)। এক নম্বরে কয়েকজন হলে একটাই SMS; সঠিক মোবাইল না থাকলে বাদ। পাঠাতে টিক লাগে।
  - টেমপ্লেট বাছলে লেখা ভরে; placeholder `{name} {id} {username} {mobile} {package} {monthly} {due} {zone}`।
  - লাইভ সারাংশ: কতজন, অক্ষর/ভাগ (GSM 160/153, বাংলা/ইউনিকোড 70/67, `{ } [ ] ~ ^ | € \` দুই ঘর), মোট SMS, আনুমানিক খরচ, বাদ পড়া, প্রথম ৩টার প্রিভিউ, গেটওয়ে বন্ধ থাকলে সতর্কতা।
  - জমা দিলে শুধু কিউ লেখা হয় (`Sms::queue`, ৫০০ করে insert) আর engine-কে ডাকা হয় (`/internal/{c}/sms/kick`, ৩ সে. timeout) — পেজ অপেক্ষা করে না।
- **SMS লগ** (`/sms-log`): প্রতিটা SMS, অবস্থা/কে পাঠালেন/কোন পাঠানো/তারিখ ফিল্টার, কাস্টমারে লিংক।
- **SMS টেমপ্লেট** (`/sms-templates`): যোগ/এডিট/মুছুন/ক্রম, দৈর্ঘ্য দেখায়।
- কাস্টমার পেজে "SMS" বাটন (ওই কাস্টমারকে)।
- সেটিংস → **SMS গেটওয়ে** (`/sms-gateway`, Owner/Admin): send URL, API Key, Secret Key, Sender ID, রেট, পোর্টাল ইউজারনেম/পাসওয়ার্ড/লগইন URL, ব্যালান্স URL, Provider ID, ডেলিভারি রিপোর্ট URL, SSL যাচাই, "SMS পাঠানো চালু"; "টেস্ট SMS পাঠান" (এখনই যায়, "চালু" বন্ধ থাকলেও; গেটওয়ের উত্তর দেখায়), "ব্যালান্স দেখুন"।

### engine (`engine/sms.py`)
- `send_queue(company)`: কোম্পানি-প্রতি একটাই রানার (`data/sms-send-{c}.lock`), ২০টা করে claim (`FOR UPDATE SKIP LOCKED`), ৪টা সমান্তরাল request, প্রতিটার ফল সাথে সাথে লেখা, তারপর campaign-এর হিসাব। প্রতি ব্যাচের আগে `enabled` দেখে — বন্ধ করলে সাথে সাথে থামে।
- ১০ মিনিটের বেশি `sending`-এ আটকে থাকা = `unknown`, আবার পাঠানো হয় না (ডাবল SMS নয়)।
- একই ধরনের ১০টা পরপর ব্যর্থ (ভুল key, ব্যালান্স নেই) → `enabled = false`, কারণ `last_error`-এ, বাকিগুলো কিউতে থাকে।
- `check_balance` (প্যানেলের বাটন + timer-এ ৫ মিনিট পরপর), `check_status` (status URL থাকলে, গত ২ দিনের sent, ১০ মিনিট পরপর)।
- `send_now` = সেটিংসের টেস্ট SMS (শুধু ওই একটা, `enabled` না দেখে)।
- key/টোকেন কখনো লগে নয়; গেটওয়ের উত্তর সংরক্ষণের আগে key/টোকেন মুছে ৩০০ অক্ষরে কাটা।
- endpoint: `POST /internal/{c}/sms/kick`, `POST /internal/{c}/sms/balance`, `POST /internal/{c}/sms/messages/{id}/send` (শুধু internal key দেখে, বিলিং লাগে না)।
- যাচাই (2026-10-15): panel `tests/Feature/SmsTest.php` (১৩টা); engine-এর পুরো লুপ test DB + 127.0.0.1-এ নকল গেটওয়ে/পোর্টাল দিয়ে (বন্ধ থাকলে কিছু যায় না, ২৫টা → ২৩ sent + ২ failed, campaign done, পোর্টাল লগইন + টোকেন এনক্রিপ্টেড + রিনিউ + ভুল পাসওয়ার্ডে বিরতি, ডেলিভারি রিপোর্ট, ভুল key-তে ১০টা পরপর ব্যর্থ হলে বন্ধ, আটকে থাকা sending → unknown, আবার পাঠানো হয়নি)। কোনো আসল SMS পাঠানো হয়নি।
- systemd `zyro-sms-send.timer` প্রতি মিনিটে `python -m engine.sms` (kick-এর ব্যাকআপ, ব্যালান্স, ডেলিভারি)।

### চালু করার ধাপ (deploy-এর পর)
1. `php artisan migrate --force` (panel), engine restart, `deploy/zyro-sms-send.{service,timer}` → `/etc/systemd/system`, `systemctl enable --now zyro-sms-send.timer`।
2. Zisan: সেটিংস → SMS গেটওয়ে-তে API Key, Secret Key, Sender ID, রেট, পোর্টাল ইউজারনেম/পাসওয়ার্ড দিন (send URL আর পোর্টালের URL আগে থেকে ভরা); "ব্যালান্স দেখুন"; "টেস্ট SMS পাঠান" নিজের নম্বরে; গেটওয়ে নিলে "SMS পাঠানো চালু"।
3. Agent-দের টিম পেজে "SMS" মেনু টিক।
