#!/bin/bash
# Builds the Zyro Desk Android app and publishes it on the Desk's download page (https://desk.zyrotechbd.com/apk/).
# Release (signed) when android/local.properties names a keystore (KEYSTORE_PATH, KEYSTORE_PASSWORD, KEY_ALIAS,
# KEY_PASSWORD; never in git); otherwise a debug build. Phones only update over an APK signed with the same key.
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APK_DIR="${APK_DIR:-$SCRIPT_DIR/../panel/public/apk}"
SITE="${SITE:-https://desk.zyrotechbd.com}"
TIMESTAMP=$(TZ=Asia/Dhaka date +"%Y%m%d-%H%M")
BUILD_TIME=$(TZ=Asia/Dhaka date +"%d %b %Y, %I:%M %p")

cd "$SCRIPT_DIR"
VERSION=$(grep -oP 'versionName = "\K[^"]+' app/build.gradle.kts)
if grep -q '^KEYSTORE_PATH=' local.properties 2>/dev/null; then
    TASK=assembleRelease; OUT=app/build/outputs/apk/release/app-release.apk; KIND=release
else
    TASK=assembleDebug; OUT=app/build/outputs/apk/debug/app-debug.apk; KIND=debug
fi
SUFFIX=""; [ "$KIND" = debug ] && SUFFIX="-debug"
APK_NAME="zyro-desk-${VERSION}-${TIMESTAMP}${SUFFIX}.apk"

echo "==> Building $KIND APK $VERSION ..."
./gradlew "$TASK" --max-workers=2

mkdir -p "$APK_DIR"
cp "$OUT" "$APK_DIR/$APK_NAME"
cp "$OUT" "$APK_DIR/zyro-desk.apk"  # always the newest: the link staff keep

ROWS=""
for f in $(ls -t "$APK_DIR"/zyro-desk-*.apk 2>/dev/null); do
    fname=$(basename "$f")
    fsize=$(du -h "$f" | cut -f1)
    ftime=$(TZ=Asia/Dhaka date -r "$f" +"%d %b %Y, %I:%M %p")
    badge=""; [ "$fname" = "$APK_NAME" ] && badge=' <span class="new">নতুন</span>'
    ROWS="${ROWS}<tr><td><a href=\"/apk/$fname\" download>$fname</a>$badge</td><td>$fsize</td><td>$ftime</td></tr>"
done

cat > "$APK_DIR/index.html" << HTML
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Zyro Desk অ্যাপ</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, sans-serif; background: #F9FAFB; color: #111827; }
  .head { background: #0F7C7B; color: #fff; padding: 32px 16px; text-align: center; }
  .head h1 { font-size: 22px; } .head p { opacity: .85; font-size: 14px; margin-top: 6px; }
  .wrap { max-width: 760px; margin: 24px auto; padding: 0 16px; }
  .btn { display: block; text-align: center; background: #0F7C7B; color: #fff; padding: 14px; border-radius: 8px; font-weight: 600; text-decoration: none; }
  .card { background: #fff; border-radius: 12px; border: 1px solid #E5E7EB; margin-top: 18px; overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { padding: 10px 14px; text-align: left; border-bottom: 1px solid #E5E7EB; white-space: nowrap; }
  th { font-size: 12px; color: #6B7280; }
  a { color: #0B5F5E; }
  .new { background: #D7F2E6; color: #12704F; border-radius: 999px; padding: 1px 8px; font-size: 11px; font-weight: 700; }
  .note { margin-top: 18px; background: #fff; border: 1px solid #E5E7EB; border-radius: 10px; padding: 12px 14px; font-size: 13px; line-height: 1.6; }
  .foot { text-align: center; color: #9AA1AE; font-size: 12px; margin: 16px 0; }
</style>
</head>
<body>
<div class="head"><h1>Zyro Desk</h1><p>Android অ্যাপ · সংস্করণ $VERSION</p></div>
<div class="wrap">
  <a class="btn" href="/apk/zyro-desk.apk" download>সর্বশেষ অ্যাপ ডাউনলোড</a>
  <div class="card"><table>
    <thead><tr><th>ফাইল</th><th>সাইজ</th><th>বিল্ড</th></tr></thead>
    <tbody>$ROWS</tbody>
  </table></div>
  <div class="note"><b>ইনস্টল:</b> ডাউনলোডের পর ফাইলটা খুলুন; ফোন জিজ্ঞেস করলে "এই উৎস থেকে ইনস্টল" অনুমতি দিন।
    লগইন Desk-এর একই ইমেইল ও পাসওয়ার্ড। নতুন সংস্করণ পুরনোটার উপরেই ইনস্টল হয়, আনইনস্টল লাগে না।</div>
  <div class="foot">সর্বশেষ বিল্ড: $BUILD_TIME</div>
</div>
</body>
</html>
HTML

echo "==> Done: $SITE/apk/$APK_NAME"
echo "==> Page:  $SITE/apk/"
