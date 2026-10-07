# কাস্টমারের তথ্য নিজেদের ডেটাবেসে রাখা: পরিকল্পনা

তারিখ: 2026-10-07 · কোম্পানি: Century Link Network (ISP Digital)

## লক্ষ্য
এখন বট বা প্যানেল কোনো কাস্টমারের ID, username, মাসিক বিল ইত্যাদি জানতে প্রতিবার বিলিং সফটওয়্যারে অনুরোধ পাঠায়।
লক্ষ্য হলো কাস্টমারের স্থির তথ্য আমাদের ডেটাবেসে রাখা, আর শুধু যে তথ্য প্রতি মুহূর্তে বদলায় সেটার জন্য বিলিংয়ে যাওয়া।

## বিলিংয়ে কী পেলাম (শুধু পড়ে দেখেছি, কিছু বদলাইনি)

| উৎস | কী দেয় | খরচ |
|---|---|---|
| `/Customer/AjaxCustomerList` (Customer তালিকা) | সব কাস্টমার একসাথে, পাতায় পাতায়: header id, ID, username, নাম, মোবাইল, NID, ইমেইল, ঠিকানা, Zone/SubZone/Box, connection type, customer type, package, speed, মাসিক বিল, protocol, server, status, disabled, VIP, bill day, joining date, registration date, থানা, জেলা, device, assigned employee | ৪,৮১৬ জন, ১০টা অনুরোধ (৫০০ করে), ~৬ MB, ~১৫ সেকেন্ড |
| `/Billing/AjaxCustomerBillList` (Billing তালিকা) | উপরের প্রায় সব + package id, এই মাসের payable, paid, due, advance, শেষ পেমেন্টের তারিখ, payment status | ৪,৬৩৪ জন (left ছাড়া), একই রকম খরচ |
| `/Customer/Search/{headerId}` (আপনার দেওয়া পেজ) | উপরের সব + device MAC/serial, latitude/longitude, fiber code, core color, cable মিটার, created/joined date, শেষ পেমেন্ট, OLT তথ্য, আর নিচে complain/remarks/SMS/payment/invoice/change-log ইতিহাস | একজনের জন্য ~১.৬ MB (পেজে সব কাস্টমারের ড্রপডাউন থাকে)। ৪,৮১৬ জনের জন্য ~৮ GB, তাই সবার জন্য নিয়মিত টানা ঠিক না |

সিদ্ধান্ত: দুই তালিকা দিয়েই প্রায় সব স্থির তথ্য পাওয়া যায়, খুব কম খরচে। Search পেজের বাড়তি তথ্য (device MAC, lat/long, fiber code ইত্যাদি) কারো দরকার হলে তখনই একবার এনে রেখে দেব, সবার জন্য একসাথে না।

**কখনো রাখব না:** PPPoE/Server password, portal login password (`Password`, `LoginPassword`, `vPassword`)। তালিকা দুটোতে এগুলো আসে, সেভ করার আগেই বাদ দেব।

## কোন তথ্য কোথা থেকে

| ধরন | উদাহরণ | কোথায় থাকবে | কতদিন পরপর |
|---|---|---|---|
| স্থির (পরিচয়) | ID, header id, username, নাম, মোবাইল, NID, ঠিকানা, থানা/জেলা, joining date | আমাদের DB | ১৫ দিনে একবার পুরো sync (প্রস্তাব: প্রতিদিন রাতে, খরচ খুব কম) |
| প্রায় স্থির (সংযোগ) | Zone/SubZone/Box, package, speed, মাসিক বিল, server, protocol, connection/customer type, assigned employee, VIP | আমাদের DB | পুরো sync-এর সাথে |
| মাঝে মাঝে বদলায় (বিল) | status, disabled, এই মাসের payable/paid/due, শেষ পেমেন্ট, bill day | আমাদের DB, কিন্তু উত্তর দেওয়ার সময় লাইভ যাচাই | পুরো sync + দরকারে লাইভ |
| লাইভ | PPPoE connected/uptime/last logout, IP, MAC (caller id), ডাউনলোড, ONU status/optical power, খোলা টিকিট, আজকের পেমেন্ট | DB-তে না, প্রতিবার বিলিংয়ে | প্রতিবার |
| ইতিহাস | payment history, complain history | পেমেন্ট দরকারে লাইভ; টিকিট আগে থেকেই `billing_tickets`-এ | — |

## টেবিল ডিজাইন

### `billing_customers` (এক কোম্পানির এক কাস্টমার এক সারি)
```
id                    bigint PK
company_id            FK companies           -- সব কোম্পানির জন্য আলাদা
header_id             bigint                 -- CustomerHeaderId (বিলিংয়ের ভেতরের id)
customer_id           varchar                -- "0976" (শুরুর শূন্যসহ)
username              varchar                -- PPPoE ID, যেমন kp.mitu
name                  varchar
mobile                varchar                -- 01XXXXXXXXX
mobile_normalized     varchar                -- 8801XXXXXXXXX, WhatsApp নম্বর মেলাতে
phone, email, nid     varchar null
address, house, road  varchar null
thana, district       varchar null
zone, subzone, box    varchar null
package, package_id, speed  varchar/int null
monthly_bill          numeric(10,2)
connection_type       varchar null           -- Fiber
customer_type         varchar null           -- Student, Home…
protocol, server      varchar null
status                varchar                -- Active / Inactive / Left
disabled              boolean
is_vip                boolean
bill_day              smallint null          -- মাসের কোন তারিখে বিলের শেষ দিন
payable, paid, due, advance  numeric(10,2) null  -- শেষ sync-এর সময়ের
last_payment_date     date null
assigned_employee     varchar null
joined_on, registered_on  date null
device, device_mac, latitude, longitude, fiber_code  varchar null  -- Search পেজ থেকে, দরকারে
extra                 jsonb                  -- বাকি সব ফিল্ড (পাসওয়ার্ড বাদে), যাতে নতুন কলাম না লাগে
details_fetched_at    timestamp null         -- Search পেজ থেকে বাড়তি তথ্য কবে আনা হয়েছে
synced_at             timestamp              -- শেষ কোন sync-এ দেখা গেছে
gone_at               timestamp null         -- বিলিং তালিকা থেকে উধাও হলে (মুছব না)
created_at, updated_at
UNIQUE (company_id, header_id)
INDEX (company_id, customer_id), (company_id, username), (company_id, mobile_normalized), (company_id, zone, subzone)
```

### `billing_customer_syncs` (প্রতিটা sync-এর হিসাব)
```
id, company_id, started_at, finished_at, total, created, updated, gone, error
```
প্যানেলে দেখা যাবে শেষ sync কবে হয়েছে, কতজন নতুন/বদলেছে, কোনো ভুল হয়েছে কিনা।

## Sync কীভাবে চলবে
1. `engine/customer_sync.py` (টিকিট sync-এর মতো): Customer তালিকা ৫০০ করে সব পাতা আনবে, তারপর Billing তালিকা থেকে বিলের ঘরগুলো মিলিয়ে দেবে (header id দিয়ে)।
2. প্রতিটা কাস্টমার upsert হবে। আগে ছিল কিন্তু এবার নেই এমন কাস্টমারের `gone_at` বসবে, মুছবে না (পুরনো চ্যাট আর টিকিটে নাম থাকে)।
3. সময়: systemd timer, প্রতি ১৫ দিনে একবার রাত ৩টায়, যেমনটা আপনি বলেছেন। আমার প্রস্তাব প্রতিদিন রাতে একবার, কারণ পুরোটা ~১৫ সেকেন্ডের কাজ, আর তাতে মাসিক বিল, status, নতুন কাস্টমার এক দিনের বেশি পুরনো থাকবে না। প্যানেলে "এখনই Sync" বাটনও থাকবে।
4. Search পেজের বাড়তি তথ্য: কেউ প্যানেলে কাস্টমারের বিস্তারিত খুললে বা টেকনিশিয়ান device MAC/লোকেশন চাইলে তখন একবার এনে রেখে দেব (`details_fetched_at`)। সবার জন্য একসাথে টানব না।

## বট আর প্যানেলে কী বদলাবে
- **কাস্টমার খোঁজা** (WhatsApp নম্বর, ID, মোবাইল, PPPoE দিয়ে): আগে আমাদের DB-তে খুঁজবে, তাই বিলিংয়ে অনুরোধ লাগবে না আর অনেক দ্রুত হবে। DB-তে না পেলে তবেই বিলিংয়ে খুঁজবে (নতুন কাস্টমার যে এখনো sync হয়নি), আর পেলে DB-তে যোগ করে দেবে।
- **উত্তর বানানো:** নাম, ID, PPPoE, package, মাসিক বিল, zone আসবে DB থেকে। PPPoE/ONU অবস্থা, বকেয়া আর আজকের পেমেন্ট বিলিং থেকে লাইভ (একটা অনুরোধ, আগে ছিল তিন-চারটা)।
- **প্যানেল:** নতুন "কাস্টমার" পেজ: সার্চ, Zone/SubZone/package/status দিয়ে ফিল্টার, বকেয়া অনুযায়ী সাজানো, CSV ডাউনলোড। কাস্টমার খুললে স্থির তথ্য সাথে সাথে, আর "লাইভ অবস্থা" অংশ বিলিং থেকে।
- **যা নতুন করে সহজ হবে:** zone ধরে ক্যাম্পেইন মেসেজ (যেমন এনায়েতনগরের সবাই), টেকনিশিয়ানের কথায় "Binodpur-এর বড় বাড়ি box-এর সব কাস্টমার", ইনবক্সে WhatsApp নম্বর দিয়ে সাথে সাথে কাস্টমার চেনা।

## ধাপ
1. টেবিল দুটো আর sync স্ক্রিপ্ট, একবার চালিয়ে ৪,৮১৬ জন আনা, সংখ্যা মিলিয়ে দেখা।
2. বটের কাস্টমার খোঁজা DB-তে সরানো (বিলিং fallback সহ), পুরনো চ্যাট replay করে উত্তর আগের মতোই আসছে কিনা যাচাই।
3. প্যানেলে কাস্টমার পেজ আর "এখনই Sync" বাটন।
4. Search পেজ থেকে দরকারে বাড়তি তথ্য আনা।

## আপনার সিদ্ধান্ত দরকার
- পুরো sync প্রতি ১৫ দিনে নাকি প্রতিদিন রাতে? (আমার পরামর্শ: প্রতিদিন রাতে, খরচ খুব কম)
- কাস্টমার পেজ কারা দেখবে: সবাই (এজেন্টসহ) নাকি শুধু owner/admin? (আমার পরামর্শ: সবাই দেখবে, CSV ডাউনলোড শুধু owner/admin)
