#!/bin/sh
# Copies the Let's Encrypt certificate covering desk.zyrotechbd.com for Asterisk's TLS transport (WhatsApp calls from Meta).
# On bsol that is the wildcard *.zyrotechbd.com (lineage wildcard-zyrotechbd); ZYRO_SIP_CERT picks another lineage.
# Install: ln -s /var/www/zyro_isp_support/deploy/asterisk/cert-hook.sh /etc/letsencrypt/renewal-hooks/deploy/zyro-asterisk.sh
set -e
LIVE=/etc/letsencrypt/live/${ZYRO_SIP_CERT:-wildcard-zyrotechbd}
install -d -o root -g asterisk -m 0750 /etc/asterisk/keys
install -o root -g asterisk -m 0640 "$LIVE/fullchain.pem" /etc/asterisk/keys/zyro-sip.crt
install -o root -g asterisk -m 0640 "$LIVE/privkey.pem" /etc/asterisk/keys/zyro-sip.key
asterisk -rx "pjsip reload" >/dev/null 2>&1 || true
