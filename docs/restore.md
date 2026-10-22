# ZyroDesk ব্যাকআপ ও রিস্টোর

পুরো Desk-এর এনক্রিপ্টেড ব্যাকআপ সুপার-অ্যাডমিনের Google Drive-এ যায়। নিয়ন্ত্রণ হয় /super থেকে: "সিস্টেম ব্যাকআপ" পেজে তালিকা আর বাটন, "ব্যাকআপ সেটিংস" পেজে বাকি সব।
কোড: `engine/system_backup.py`, `deploy/zyro-system-backup.{service,timer}` (root, প্রতি মিনিটে), `deploy/restore-server.sh`।

## কী কী যায়

| Drive-এ (ডিক্রিপ্ট করলে) | ভেতরে | কখন |
|---|---|---|
| `runs/<তারিখ_সময়>-db/` | `zyro_support.dump` (pg_dump -Fc), `globals.sql` (রোল), `manifest.json` (sha256) | প্রতি N ঘণ্টা (ডিফল্ট ৬) |
| `runs/<তারিখ_সময়>-full/` | উপরেরগুলো + `secrets.tar.gz` (panel/.env, engine/.env, /etc/zyro-desk), `config.tar.gz` (nginx, letsencrypt, asterisk, php-fpm pool, crontab, zyro-* systemd unit, dpkg/pip তালিকা), `code.txt` (commit + লাইভের অ-commit diff), সাপ্তাহিক/মাসিক/হাতে নেওয়াগুলোয় `code.bundle` | প্রতি রাতে (ডিফল্ট ঢাকা ৩:৩০) |
| `files/current/` | `panel/storage/app` এর হুবহু কপি (মিডিয়া, কল রেকর্ডিং, MikroTik ব্যাকআপ) | প্রতি রাতে, শুধু বদলানোগুলো যায় |
| `files/apk/` | `panel/public/apk` | প্রতি রাতে |
| `files/deleted/<তারিখ>/` | রাতের sync-এ যা মুছে গেছে বা বদলেছে তার আগের কপি | ডিফল্ট ৩০ দিন থাকে |

Redis, vendor, node_modules, .venv আর লগ যায় না, কারণ এগুলো নতুন করে বানানো যায়।

## কতদিন থাকে

একটা রান কোন স্তরের তা ঠিক হয় সে কখন হয়েছে তা দিয়ে: ডাটাবেস-রান হলে "ঘণ্টার" (৭ দিন)। পুরো রান সাধারণ দিনে হলে "দৈনিক" (৩০ দিন), রবিবারে হলে "সাপ্তাহিক" (১২ সপ্তাহ), মাসের ১ তারিখে হলে "মাসিক" (১২ মাস)। হাতে নেওয়া রান দৈনিকের মতো থাকে। প্রতি রাতের পুরো রানের পরে পুরনোগুলো মোছে। সবচেয়ে নতুন সফল ব্যাকআপ কখনো মোছে না। ফেল করা রানের আধা-আপলোড ফাইল ২ দিন পরে মোছে।

## এনক্রিপশন আর রিকভারি কী

- rclone crypt: ফাইলের ভেতরটা আর নাম দুটোই এনক্রিপ্টেড। `password` হলো রিকভারি কী, `password2` (salt) হলো `sha256("zyrodesk:" + কী)`-এর প্রথম ৩২ অক্ষর।
- কী প্রথমবার নিজে থেকে তৈরি হয়। এটা DB-তে APP_KEY দিয়ে এনক্রিপ্ট করা থাকে, আর "ব্যাকআপ সেটিংস"-এ একবার দেখায়। "লিখে রেখেছি" ঘরে টিক না দেওয়া পর্যন্ত অটো ব্যাকআপ চালু হয় না।
- "রিকভারি শিট" (.txt) ডাউনলোড করা যায়। এতে কী, Drive ফোল্ডার id, OAuth client আর refresh token থাকে, নতুন সার্ভারে রিস্টোরের জন্য এটুকুই লাগে। এটা পাসওয়ার্ড ম্যানেজারে রাখুন, আর অন্তত কী-টা কাগজেও লিখে রাখুন।
- কী হারালে কোনো ব্যাকআপ আর খোলা যাবে না।

## Google Drive সংযোগ (প্রথমবার)

1. Google Cloud Console → প্রজেক্ট `zyro-desk` → APIs & Services → Library → **Google Drive API** চালু করুন।
2. OAuth consent screen: External, অ্যাপের নাম "ZyroDesk Backup", স্কোপ `.../auth/drive.file`। Publishing status **In production** করুন, নাহলে ৭ দিন পর token বাতিল হয়ে যায়। `drive.file` non-sensitive স্কোপ, তাই Google-এর verification লাগে না।
3. Credentials → Create OAuth client ID → Web application। Authorized redirect URI দিন: `https://desk.zyrotechbd.com/system-backup/google/callback`।
4. /super → ব্যাকআপ সেটিংসে client ID আর secret দিন → "Google Drive সংযোগ করুন" চাপুন → যে Gmail-এ ব্যাকআপ রাখবেন সেটা দিয়ে লগইন করে অনুমতি দিন। "unverified app" সতর্কতা এলে Advanced → Continue চাপুন।
5. রিকভারি কী লিখে টিক দিন → অটো ব্যাকআপ চালু → সেভ করুন → "এখনই ব্যাকআপ" দিয়ে প্রথমটা চালান।

অ্যাকাউন্ট বদলালে নতুন Drive-এ নতুন ফোল্ডার তৈরি হয়। আগের অ্যাকাউন্টের ব্যাকআপগুলো তালিকায় "মুছে গেছে" দেখায়, তবে আসলে সেগুলো পুরনো Drive-এ থেকে যায়।

## রিস্টোর

**১. পরীক্ষা (লাইভ ছোঁয় না):** তালিকায় কোনো ব্যাকআপের "রিস্টোর টেস্ট" চাপুন। Drive থেকে নামিয়ে `zyro_support_restore_test` নামের আলাদা DB-তে বসায়। তারপর লাইভের সাথে মূল টেবিলগুলোর সারি গোনে, ২০টা র‍্যান্ডম ফাইল Drive-এর কপির সাথে মিলিয়ে দেখে, আর শেষে টেস্ট DB মুছে দেয়। প্রতি রবিবার পুরো রানের পরে এই টেস্ট নিজে থেকেও চলে।

**২. ডাউনলোড:** "ডাউনলোড" চাপলে সার্ভার রানটা ডিক্রিপ্ট করে একটা .tar বানায়। "ফাইল নিন" দিয়ে সেটা নামানো যায়, আর ২ ঘণ্টা পরে সার্ভার থেকে মুছে যায়।

**৩. লাইভ ডাটাবেস ফেরত (ভুল ডিলিট, খারাপ migration):**

```
cd /var/www/zyro_isp_support
sudo -E bash -c 'set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.system_backup restore-db <ID> --yes'
```

এটা চালালে যা হয়:
1. লাইভ DB-র একটা dump নেয় (`/var/backups/zyro-desk/restore-live/before-restore-*.dump`)।
2. ব্যাকআপটা `zyro_support_restoring` নামে বসায়।
3. engine, sip, voice আর php-fpm কয়েক সেকেন্ডের জন্য থামায়।
4. পুরনো DB-র নাম বদলে `zyro_support_before_<সময়>` রাখে, আর নতুনটার নাম `zyro_support` করে।
5. সার্ভিসগুলো আবার চালু করে।

পুরনো DB মোছে না, তাই দরকার হলে আগের অবস্থায় ফেরা যায়। ব্যাকআপ কোডের চেয়ে পুরনো হলে এরপর `php artisan migrate --force` চালাতে হবে।

**৪. ফাইল ফেরত:** নিচের কমান্ড `panel/storage/app` (বা তার ভেতরের একটা path) Drive থেকে ফিরিয়ে আনে। লোকাল ফাইল কখনো মোছে না।

```
sudo -E bash -c 'set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.system_backup restore-files private/media'
```

পুরনো তারিখের কোনো ফাইল লাগলে সেটা `files/deleted/<তারিখ>/`-এ থাকে। তুলতে rclone দিয়ে `--config /var/backups/zyro-desk/...` ব্যবহার করুন, অথবা `restore-server.sh`-এর মতো করে একটা config বানিয়ে নিন।

**৫. পুরো সার্ভার গেছে:** নতুন Ubuntu 24.04-এ:

```
scp zyrodesk-backup-recovery.txt deploy/restore-server.sh root@new-server:
ssh root@new-server 'bash restore-server.sh zyrodesk-backup-recovery.txt'
```

স্ক্রিপ্ট যা করে:
1. প্যাকেজ বসায়।
2. রিকভারি শিট থেকে rclone config বানায়।
3. সর্বশেষ পুরো রান নামিয়ে checksum মেলায়।
4. কোড বসায় (code.bundle থাকলে সেখান থেকে, নাহলে GitHub থেকে), তারপর সেই commit চেকআউট করে লাইভের অ-commit diff বসায়।
5. secrets আর config খুলে বসায়।
6. ডাটাবেস রিস্টোর করে।
7. ফাইল আর APK ফেরত আনে।
8. venv, composer, migrate আর cache চালায়।
9. timer আর সার্ভিস চালু করে।

এরপর DNS নতুন IP-তে দিন। Meta-র webhook URL একই ডোমেইনে থাকে, তাই নতুন করে কিছু লাগে না। পুরনো কোনো রান থেকে ফেরাতে চাইলে দ্বিতীয় আর্গুমেন্টে রানের ফোল্ডারের নাম দিন। একই স্ক্রিপ্ট আবার চালানো নিরাপদ।

**ড্রিল:** প্রতি ৩ মাসে একবার একটা আলাদা টেস্ট কন্টেইনারে ৫ নম্বর ধাপ চালিয়ে দেখুন, আর কত সময় লাগল লিখে রাখুন।

## অ্যালার্ট

যা হলে অ্যালার্ট যায়:
- ব্যাকআপ বা রিস্টোর টেস্ট ফেল করলে
- ২৬ ঘণ্টা কোনো সফল ব্যাকআপ না হলে (দিনে একবার)
- Google token বাতিল হলে

অ্যালার্ট যায় সেটিংসে বাছা কোম্পানির WhatsApp নম্বর থেকে। প্রাপক গত ২৪ ঘণ্টায় ওই নম্বরে মেসেজ দিলে সাধারণ টেক্সট যায়। না দিলে UTILITY টেমপ্লেট `system_backup_alert` যায়, যা প্রথমবার নিজে থেকে Meta-তে জমা হয় আর অনুমোদনের পরেই কাজ করে। `ENGINE_DRY_RUN=1` থাকলে কিছু পাঠায় না, শুধু লগে লেখে।

## হাতে চালানো / সমস্যা

- `sudo systemctl start zyro-system-backup` চালালে একটা tick হয়। লগ দেখতে: `journalctl -u zyro-system-backup -n 100`।
- সার্ভারে লোকাল কাজের ফোল্ডার `/var/backups/zyro-desk/` (root 700)। সর্বশেষ DB dump `latest/`-এও রাখা থাকে, তাই Drive ছাড়াই দ্রুত রিস্টোর করা যায়।
- `SYSTEM_BACKUP_LOCAL_REMOTE=/path` দিলে Drive-এর বদলে একটা লোকাল ফোল্ডার ব্যবহার হয় (টেস্ট আর ড্রিলের জন্য)।
