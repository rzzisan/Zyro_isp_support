---
id: customers.change
title: প্যাকেজ / Left / পাসওয়ার্ড বদল
module: customers
menu: customer_change
status: live
plans: [all]
audience: [owner, admin]
keywords: [প্যাকেজ বদল, package, Left, বন্ধ কাস্টমার, ফেরত, পাসওয়ার্ড বদল, PPPoE পাসওয়ার্ড, secret, বিলিং-এ বদল, বিলিং secret check, secret মেলেনি]
related:
  - id: customers.list_profile
    when: কাস্টমারের বর্তমান তথ্য দেখতে হলে
  - id: customers.create
    when: নতুন সংযোগ আগে তৈরি করতে হবে
  - id: line.enable_disable
    when: Left করলে লাইন বন্ধ থাকে কি না
  - id: network.mikrotik
    when: রাউটারের secret বিলিংয়ের সাথে মিলছে না
data: []
code: [engine/desk_change.py, engine/desk_customer.py, panel/app/Filament/App/CustomerChangeActions.php, panel/app/Filament/App/Pages/BillingSecretCheck.php]
updated: 2026-11-07
---

## কী ও কেন
কাস্টমারের প্যাকেজ বদলানো, Left (সংযোগ বন্ধ করে রাখা) বা Left থেকে ফেরত আনা, এবং PPPoE পাসওয়ার্ড বদলানো। একটা জায়গা থেকে বিলিং ও রাউটার দুটোই ঠিক রাখা যায়।

## কীভাবে কাজ করে
- অনুমতি `customer_change` লাগে (মালিক সবসময় পান)।
- **ধাপ ১:** বিলিংয়ের edit form-এ বদল যায়। MikroTik-এর অংশ (Impact) বাদ দেওয়া হয়।
- **ধাপ ২:** বিলিং সফল হলে Desk নিজে রাউটারের secret বদলায়:
  - **Left:** secret মুছে যায় (গ্রাহক আর সংযোগ করতে পারেন না)।
  - **ফেরত:** বিলিংয়ের পাসওয়ার্ড দিয়ে নতুন secret তৈরি হয়।
  - **প্যাকেজ বদল:** প্রোফাইল বদলায়।
- বদল `desk_customer_changes` টেবিলে থাকে।
- সুইচ `connection_control` (off/test/all) এই কাজেও খাটে।
- Left কাস্টমারের secret মুছলে বিলিংয়ে secret থেকে যায়, তবে প্রোফাইল `BLOCKED-PROFILE` হয়। দুটো আলাদা, তাই "secret মেলাও" পেজে দেখা যায়।
- **Secret check** (`/billing-secret-check`): রাউটারের secret আর বিলিংয়ের তথ্য মেলায়। অমিল থাকলে দেখায়।
- ফর্মে Zone অনুযায়ী username শুরু হয়, সংযোগ ফি ও ONU লেখা যায়।

## কীভাবে করবেন
1. কাস্টমার পেজ খুলুন → **বিলিং-এ বদল**।
2. বদল বেছে (প্যাকেজ, Left, ফেরত বা পাসওয়ার্ড) তথ্য দিন।
3. জমা দিন। ফল দেখুন।
4. অমিল হলে মেনু থেকে **secret check** খুলে মেলান।

## সাধারণ প্রশ্ন
- **Left করলাম, লাইন কেন বন্ধ হলো না?** Left-এর পর secret মোছা হয়। লাইন বন্ধ থাকাটা স্বাভাবিক।
- **ফেরত আনলে পাসওয়ার্ড বদলায়?** বিলিংয়ের পাসওয়ার্ড দিয়ে নতুন secret হয়, তাই গ্রাহককে নতুন পাসওয়ার্ড দিতে হতে পারে।
- **প্যাকেজ বদলের পর ইন্টারনেট ধীর?** রাউটারের প্রোফাইল ঠিক হয়েছে কি না দেখুন।

## সমস্যা নির্ণয়
| লক্ষণ | কী দেখবেন | কারণ | করণীয় |
|---|---|---|---|
| বদল জমা হচ্ছে না | `connection_control` | বন্ধ বা test তালিকায় নেই | সুইচ যাচাই |
| বিলিং হয়েছে, রাউটার না | secret check | রাউটার আপডেট হয়নি | আবার চেষ্টা বা রাউটার দেখুন |
| অমিল | secret check পেজ | বিলিং ও রাউটার আলাদা | মিলিয়ে নিন |

## সীমা ও যা নেই
- Mikrotik Impact অংশ এখানে বদলায় না।
- পাসওয়ার্ড কোথাও সাদা অক্ষরে দেখানো হয় না।
- বট এই পরিবর্তন করতে পারে না।
