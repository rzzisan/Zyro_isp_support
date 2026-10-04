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
