#!/usr/bin/env bash
# Bring the whole Desk back on a fresh Ubuntu 24.04 server from the Google Drive backups (docs/restore.md).
#
#   sudo bash restore-server.sh zyrodesk-backup-recovery.txt [run-folder]
#
# recovery.txt is the sheet from /super → ব্যাকআপ সেটিংস (recovery key, Drive folder id, OAuth client, refresh token).
# run-folder defaults to the newest "...-full" run (list them with: rclone --config $CONF lsf zb:runs --dirs-only).
# Safe to run again: every step skips what is already there. Nothing is ever deleted on Drive.
set -euo pipefail

SHEET=${1:?usage: restore-server.sh recovery.txt [run-folder]}
RUN=${2:-}
APP=/var/www/zyro_isp_support
WORK=/var/backups/zyro-desk/restore-server
CONF=/root/.config/rclone/zyro-restore.conf
DB=zyro_support

field() { sed -n "s/^$1: //p" "$SHEET" | head -1; }
KEY=$(field 'Recovery key'); FOLDER=$(field 'Drive folder id'); CID=$(field 'OAuth client id')
CSECRET=$(field 'OAuth client secret'); RTOKEN=$(field 'Refresh token')
[ -n "$KEY" ] && [ -n "$FOLDER" ] && [ -n "$CID" ] && [ -n "$RTOKEN" ] || { echo "recovery sheet is missing a field"; exit 1; }

echo "== 1/8 packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -yq nginx postgresql-16 redis-server git curl unzip python3-venv python3-pip \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-intl php8.3-gd php8.3-bcmath php8.3-redis \
  asterisk baresip ffmpeg
command -v rclone >/dev/null || curl -fsSL https://rclone.org/install.sh | bash
command -v composer >/dev/null || { curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer; }
id claude-dev >/dev/null 2>&1 || useradd -m -s /bin/bash -G www-data claude-dev

echo "== 2/8 rclone config (Drive + crypt with the recovery key)"
mkdir -p "$(dirname "$CONF")" "$WORK"; chmod 700 "$WORK"
SALT=$(printf 'zyrodesk:%s' "$KEY" | sha256sum | cut -c1-32)
umask 077
cat > "$CONF" <<EOF
[gd]
type = drive
client_id = $CID
client_secret = $CSECRET
scope = drive.file
root_folder_id = $FOLDER
token = {"access_token":"","token_type":"Bearer","refresh_token":"$RTOKEN","expiry":"2000-01-01T00:00:00Z"}

[zb]
type = crypt
remote = gd:
filename_encryption = standard
directory_name_encryption = true
password = $(printf '%s' "$KEY" | rclone obscure -)
password2 = $(printf '%s' "$SALT" | rclone obscure -)
EOF
umask 022
R="rclone --config $CONF"
[ -n "$RUN" ] || RUN=$($R lsf zb:runs --dirs-only | grep -- '-full' | sort | tail -1 | tr -d /)
[ -n "$RUN" ] || { echo "no full backup found on Drive (wrong key or account?)"; exit 1; }
echo "restoring from runs/$RUN"
$R copy "zb:runs/$RUN" "$WORK/$RUN" -P
cd "$WORK/$RUN"
python3 - <<'PY'
import hashlib, json, pathlib
m = json.loads(pathlib.Path("manifest.json").read_text())
for name, f in m["files"].items():
    if name == "manifest.json":
        continue
    h = hashlib.sha256(pathlib.Path(name).read_bytes()).hexdigest()
    assert h == f["sha256"], f"{name}: checksum mismatch"
print("checksums OK:", ", ".join(m["files"]))
PY

echo "== 3/8 code"
COMMIT=$(sed -n 's/^commit //p' code.txt)
if [ ! -d "$APP/.git" ]; then
  if [ -f code.bundle ]; then git clone code.bundle "$APP"; else git clone https://github.com/rzzisan/zyro_isp_support.git "$APP"; fi
fi
git -c safe.directory='*' -C "$APP" fetch -q --all 2>/dev/null || true
git -c safe.directory='*' -C "$APP" checkout -q "$COMMIT" || echo "commit $COMMIT not found; staying on $(git -C "$APP" rev-parse --short HEAD)"
sed -n '/^# git diff HEAD/,$p' code.txt | tail -n +2 > "$WORK/live.diff"
[ -s "$WORK/live.diff" ] && { git -C "$APP" apply "$WORK/live.diff" && echo "applied the live uncommitted changes" || echo "live diff did not apply: $WORK/live.diff"; }

echo "== 4/8 secrets + server config"
[ -f secrets.tar.gz ] && tar -xzf secrets.tar.gz -C /
[ -f config.tar.gz ] && tar -xzf config.tar.gz -C /
chown -R claude-dev "$APP" /etc/zyro-desk 2>/dev/null || true
chmod 700 /etc/zyro-desk 2>/dev/null || true

echo "== 5/8 database"
systemctl start postgresql
runuser -u postgres -- psql -X -q -f globals.sql 2>&1 | grep -v 'already exists' || true
if runuser -u postgres -- psql -XAtc "SELECT 1 FROM pg_database WHERE datname = '$DB'" | grep -q 1; then
  echo "database $DB already exists, not touched (drop it first to restore again)"
else
  OWNER=$(sed -n 's#^ENGINE_DATABASE_URL=postgres[a-z]*://\([^:@]*\).*#\1#p' "$APP/engine/.env" | head -1)
  runuser -u postgres -- createdb -O "${OWNER:-postgres}" "$DB"
  runuser -u postgres -- pg_restore -d "$DB" < "$DB.dump"
fi

echo "== 6/8 files (media, call recordings, MikroTik backups, APKs)"
mkdir -p "$APP/panel/storage/app" "$APP/panel/public/apk"
$R copy zb:files/current "$APP/panel/storage/app" -P
$R copy zb:files/apk "$APP/panel/public/apk" -P

echo "== 7/8 app"
cd "$APP"
[ -d .venv ] || python3 -m venv .venv
.venv/bin/pip install -q -r requirements.txt
cd panel
sudo -u claude-dev composer install -q --no-dev --no-interaction --optimize-autoloader
chown -R claude-dev:www-data storage bootstrap/cache; chmod -R g+rwX storage bootstrap/cache
sudo -u claude-dev php artisan migrate --force
sudo -u claude-dev php artisan config:cache; chgrp www-data bootstrap/cache/config.php; chmod 640 bootstrap/cache/config.php
sudo -u www-data php artisan route:cache; sudo -u www-data php artisan filament:optimize; sudo -u www-data php artisan view:cache

echo "== 8/8 services"
systemctl daemon-reload
for u in /etc/systemd/system/zyro-*.timer; do systemctl enable --now "$(basename "$u")" || true; done
for s in zyro-engine zyro-sip zyro-voice; do [ -f "/etc/systemd/system/$s.service" ] && systemctl enable --now "$s" || true; done
nginx -t && systemctl reload nginx
systemctl restart php8.3-fpm asterisk || true
curl -fsS http://127.0.0.1:8994/health && echo " engine OK" || echo "engine not answering yet: journalctl -u zyro-engine"
echo
echo "Done. Point DNS (desk / support .zyrotechbd.com) to this server; Meta keeps the same webhook URL."
echo "Backups were restored from runs/$RUN. Check /super → সিস্টেম ব্যাকআপ, then re-connect Google Drive there if asked."
