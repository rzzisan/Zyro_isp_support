#!/bin/sh
# Copies desk.zyrotechbd.com's Let's Encrypt certificate for Asterisk's TLS transport (WhatsApp calls from Meta).
# Install: ln -s /var/www/zyro_isp_support/deploy/asterisk/cert-hook.sh /etc/letsencrypt/renewal-hooks/deploy/zyro-asterisk.sh
set -e
LIVE=/etc/letsencrypt/live/${ZYRO_SIP_DOMAIN:-desk.zyrotechbd.com}
install -d -o root -g asterisk -m 0750 /etc/asterisk/keys
install -o root -g asterisk -m 0640 "$LIVE/fullchain.pem" /etc/asterisk/keys/zyro-sip.crt
install -o root -g asterisk -m 0640 "$LIVE/privkey.pem" /etc/asterisk/keys/zyro-sip.key
asterisk -rx "pjsip reload" >/dev/null 2>&1 || true
