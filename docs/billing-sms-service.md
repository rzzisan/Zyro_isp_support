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

### গেটওয়ে: Khudebarta "SMS HTTP API Published" v5.4.0 (Zisan-এর দেওয়া PDF, 2026-10-15)
Base `http://portal.khudebarta.com:3775` (পুরনো `http://118.67.213.114:3775` এখন connection refused, সার্ভার আর অফিস PC দুই জায়গা থেকেই; BSOL-এও এটাই ছিল, শেষ সফল SMS 2026-09-25)। প্রোফাইল "centurylinknetwork HTTP", TPS 1000।
- **পাঠানো** `POST /sendtext`, `Content-Type: application/json`, body `{apikey, secretkey, callerID, toUser: "8801XXXXXXXXX", messageContent}` (ASCII + Unicode)। উত্তর `{"Status":"0","StatusDescription":"","Text":"ACCEPTD","Message_ID":"31771702"}`; `Status` 0 ছাড়া সব ব্যর্থ। GET-এও একই query দিয়ে চলে।
- **বাল্ক**: একই endpoint, `toUser` কমা দিয়ে কয়েকটা নম্বর, `Message_ID` একই ক্রমে কমা দিয়ে। Desk একই লেখার SMS ৫০টা করে এক request-এ পাঠায়; `-65 Bulk Limit Exceeded` এলে ভাগ অর্ধেক করে আবার। কাস্টমারের তথ্য বসানো (আলাদা আলাদা) লেখা একটা করে (`/send` dynamic API ডকে deprecated, ব্যবহার করা হয় না)।
- **Status কোড** (submit): 0 ACCEPTD; অ্যাকাউন্টের সমস্যা, যাতে সাথে সাথে পাঠানো বন্ধ হয় আর বাকি SMS অপেক্ষায় থাকে: -1 Org Client Not Found, -4 Insufficient Balance, -5 Org Rate Not Found, -6 Org Blocked, -7 Invalid Sender ID, -36 Invalid Request Type, -42 Authorization Failed, -52 License Limit Exceeded, -54 Sender ID Empty, -59 IP Not Allowed, -60 Invalid Hash value, -61 Invalid parameter, -64 Sender ID Block, -66 Invalid API Key, -67 Invalid Secret Key। শুধু ওই নম্বর/লেখার সমস্যা: -3 Invalid Dest No., -44/-45 ContactNo Blocked, -46 Dipping Failed, -47 Content not Whitelisted, -48 URL Blocked, -49 Content is Blocked, -55/-56 খালি, -58 HLR, -62 Internal Server Error, -63/-68 Transaction ID, -65 Bulk Limit। লগে কোড + বিবরণ দেখায়।
- **ডেলিভারি** `GET /getstatus?apikey&secretkey&messageid` → `{Status, Text, Message_ID, "Delivery Time" (ms)}`: 0 DELIVRD (পৌঁছেছে), 4 SENT / 2 PENDING (পরে আবার), 1 REJECTD (পৌঁছায়নি); 101/108/109/114 = প্রশ্নটাই বাতিল। সেটিংসে আলাদা URL না দিলে একই host-এর `/getstatus`, ১০ মিনিট পরপর ৪৮ ঘণ্টা।
- **ব্যালান্স** `POST /api/v3/balance` `{apikey, secretkey, clienttransid}` → `{"statusInfo":{"statusCode":"0","errordescription":"ACCEPTD","availablebalance":"797.88"}}`। timer-এ ৫ মিনিট পরপর, আর "ব্যালান্স রিফ্রেশ"।
- **কানেকশন যাচাই** `POST /validateclient` `{apikey, secretkey}` → `{"clientBalance":..,"clientName":"..","status":"SUCCESS"}` বা `{"status":"ERROR"}`; কোনো SMS যায় না।
- ডকের DLR push (`/submitstatus`), MO, recharge আর billing-balance API (provider x-api-key লাগে) Desk-এর কাজে লাগে না।
- আগের সংস্করণের পোর্টাল লগইন (`portal.khudebarta.com:9494`, Bearer JWT) বাদ: পোর্টাল ব্রাউজার ছাড়া সবাইকে `403 Forbidden from Charset Filter` দেয়। বিলিং পেজ থেকে ব্যালান্স পড়াও বাদ (Zisan: বিলিংয়ের সাথে কোনো যোগ নয়)। `sms_settings`-এর portal_*/balance_url/provider_id কলাম এখন অব্যবহৃত।

### ডেটা (migration 2026_10_15_000001)
- `sms_settings` (কোম্পানি-প্রতি ১টা): endpoint_url (send URL; ব্যালান্স/যাচাই/ডেলিভারি একই host-এ), status_url (ঐচ্ছিক), api_key + secret_key (Laravel encrypted, ফর্মে আর দেখানো হয় না), caller_id, verify_tls, **enabled** (বন্ধ = কিছুই যায় না), rate (টাকা/SMS ভাগ, শুধু হিসাব), balance, balance_checked_at, last_check_ok, last_error। (portal_*, balance_url, provider_id অব্যবহৃত)
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
- সেটিংস → **SMS গেটওয়ে** (`/sms-gateway`, Owner/Admin): send URL, API Key, Secret Key, Sender ID, রেট, ডেলিভারি রিপোর্ট URL (ঐচ্ছিক), SSL যাচাই, "SMS পাঠানো চালু"; বাটন "কানেকশন যাচাই" (`/validateclient`, ক্লায়েন্টের নাম + ব্যালান্স, SMS যায় না), "টেস্ট SMS পাঠান" (এখনই যায়, "চালু" বন্ধ থাকলেও; Status, বিবরণ, Message ID দেখায়), "ব্যালান্স দেখুন"।

### engine (`engine/sms.py`)
- `send_queue(company)`: কোম্পানি-প্রতি একটাই রানার (`data/sms-send-{c}.lock`), ১০০টা করে claim (`FOR UPDATE SKIP LOCKED`), একই লেখা ৫০টা নম্বর করে এক request-এ (বাল্ক), ৪টা সমান্তরাল request, প্রতিটার ফল সাথে সাথে লেখা, তারপর campaign-এর হিসাব। প্রতি ব্যাচের আগে `enabled` দেখে — বন্ধ করলে সাথে সাথে থামে। অ্যাকাউন্ট error হলে ওই SMS-গুলো আবার queued, পাঠানো বন্ধ।
- ১০ মিনিটের বেশি `sending`-এ আটকে থাকা = `unknown`, আবার পাঠানো হয় না (ডাবল SMS নয়)।
- একই ধরনের ১০টা পরপর ব্যর্থ (ভুল key, ব্যালান্স নেই) → `enabled = false`, কারণ `last_error`-এ, বাকিগুলো কিউতে থাকে।
- `check_balance` (প্যানেলের বাটন + timer-এ ৫ মিনিট পরপর), `check_status` (status URL থাকলে, গত ২ দিনের sent, ১০ মিনিট পরপর)।
- `send_now` = সেটিংসের টেস্ট SMS (শুধু ওই একটা, `enabled` না দেখে)।
- key/টোকেন কখনো লগে নয়; গেটওয়ের উত্তর সংরক্ষণের আগে key/টোকেন মুছে ৩০০ অক্ষরে কাটা।
- endpoint: `POST /internal/{c}/sms/kick`, `POST /internal/{c}/sms/balance`, `POST /internal/{c}/sms/messages/{id}/send` (শুধু internal key দেখে, বিলিং লাগে না)।
- যাচাই (2026-10-15, v5.4.0 অনুযায়ী): panel `tests/Feature/SmsTest.php` (১৬টা); engine-এর পুরো লুপ test DB + 127.0.0.1-এ ডকের মতো নকল গেটওয়ে দিয়ে: JSON POST, validate + ব্যালান্স, বন্ধ থাকলে কিছু যায় না, ১২০টা একই লেখা বাল্কে (৫০ → -65 → ২৫), ৫টা আলাদা লেখা একটা করে, -3 শুধু ওই নম্বর ব্যর্থ, Message_ID প্রতিটায়, ডেলিভারি (DELIVRD/REJECTD/SENT), -66-এ সাথে সাথে বন্ধ আর SMS queued থাকে, ঠিক key-তে ৩০টা এক request-এ, আটকে থাকা sending → unknown, ভুল key-র error-এ secret নেই। কোনো আসল SMS পাঠানো হয়নি।
- systemd `zyro-sms-send.timer` প্রতি মিনিটে `python -m engine.sms` (kick-এর ব্যাকআপ, ব্যালান্স, ডেলিভারি)।

### চালু করার ধাপ (deploy-এর পর)
1. `php artisan migrate --force` (panel), engine restart, `deploy/zyro-sms-send.{service,timer}` → `/etc/systemd/system`, `systemctl enable --now zyro-sms-send.timer`।
2. Zisan: সেটিংস → SMS গেটওয়ে-তে API Key, Secret Key, Sender ID, রেট দিন (send URL আগে থেকে ভরা); "কানেকশন যাচাই"; "টেস্ট SMS পাঠান" নিজের নম্বরে; গেটওয়ে নিলে "SMS পাঠানো চালু"।
3. Agent-দের টিম পেজে "SMS" মেনু টিক।

### বিল পরিশোধের SMS (2026-10-15)
- টেমপ্লেটের ধরন: সাধারণ (হাতে পাঠানোর ফর্মে) আর বিল পরিশোধ ("বিল নিন"-এর পর)। প্রতিটা কোম্পানিতে ডিফল্ট: "প্রিয় {name}, আপনার {months} মাসের বিল {paid} টাকা পরিশোধ হয়েছে। ছাড়: {discount} টাকা, বকেয়া: {due} টাকা। ধন্যবাদ, {company}"।
- বিলের placeholder: {paid} জমা, {discount} ছাড়, {due} জমার পরের বকেয়া (engine-এর উত্তরের balance, না থাকলে মোট − জমা − ছাড়), {payable} জমার আগের বকেয়া, {months} বিলের মাস, {method} মাধ্যম, {receipt_no} (Desk কালেকশনের রিসিট নং, approve-এর আগে D-{id}), {date} (বাংলাদেশ সময়)। সব SMS-এ {company} (মানি রিসিট সেটিংসের নাম, না থাকলে কোম্পানির নাম)।
- placeholder বোতাম টেমপ্লেট এডিটর আর SMS পাঠানোর ফর্মে: ক্লিক করলে লেখার কার্সরের জায়গায় বসে, ফোকাস থাকে, অক্ষর/ভাগ/প্রিভিউ আপডেট হয়।
- সেটিংস → SMS গেটওয়ে: "বিল নিলে কাস্টমারকে SMS" (ডিফল্ট বন্ধ) আর কোন টেমপ্লেট। "বিল নিন" ফর্মে "কাস্টমারকে SMS (Desk)" টগল (সেটিং চালু থাকলে ডিফল্ট চালু, প্রতিবার বন্ধ করা যায়); বিলিংয়ের নিজের SMS ("বিলিং থেকে SMS") আলাদা, ডিফল্ট বন্ধ। জমা সফল হলে SMS কিউতে ওঠে আর engine-কে ডাকা হয়, জমা দেরি হয় না।