#!/bin/bash
set -e

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:-fusionpbx}"
DB_USER="${DB_USER:-fusionpbx}"
DB_PASSWORD="${DB_PASSWORD:-fusionpbx}"
ESL_HOST="${ESL_HOST:-freeswitch}"
ESL_PORT="${ESL_PORT:-8021}"
ESL_PASSWORD="${ESL_PASSWORD:-ClueCon}"
FREESWITCH_WS_UPSTREAM="${FREESWITCH_WS_UPSTREAM:-freeswitch:7443}"
FREESWITCH_WS_SCHEME="${FREESWITCH_WS_SCHEME:-https}"
WEB_HTTPS_PORT="${WEB_HTTPS_PORT:-8443}"

mkdir -p /etc/fusionpbx
cat > /etc/fusionpbx/config.conf <<EOF
#database settings
database.0.type = pgsql
database.0.host = ${DB_HOST}
database.0.port = ${DB_PORT}
database.0.name = ${DB_NAME}
database.0.username = ${DB_USER}
database.0.password = ${DB_PASSWORD}
EOF

# Patch nginx upstream for FreeSWITCH WebSocket. Default is the WSS listener
# (https://freeswitch:7443) so SIP.js's WSS transport is answered correctly.
sed -i "s|FREESWITCH_WS_SCHEME://FREESWITCH_WS_UPSTREAM|${FREESWITCH_WS_SCHEME}://${FREESWITCH_WS_UPSTREAM}|g" /etc/nginx/sites-available/skykin.conf
sed -i "s|__WEB_HTTPS_PORT__|${WEB_HTTPS_PORT}|g" /etc/nginx/sites-available/skykin.conf

# Self-signed TLS cert so browsers grant microphone/WebRTC (secure origin).
# Replace /etc/ssl/skykin/* with a real cert (Let's Encrypt) in production.
CERT_DIR=/etc/ssl/skykin
if [ ! -f "$CERT_DIR/privkey.pem" ] || [ ! -f "$CERT_DIR/fullchain.pem" ]; then
  mkdir -p "$CERT_DIR"
  CN="${TLS_CN:-${SERVER_NAME:-skykin.local}}"
  openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
    -keyout "$CERT_DIR/privkey.pem" -out "$CERT_DIR/fullchain.pem" \
    -subj "/CN=${CN}" \
    -addext "subjectAltName=DNS:${CN},DNS:localhost,IP:127.0.0.1${TLS_SAN_IP:+,IP:$TLS_SAN_IP}" \
    2>/dev/null || \
  openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
    -keyout "$CERT_DIR/privkey.pem" -out "$CERT_DIR/fullchain.pem" \
    -subj "/CN=${CN}"
  chmod 600 "$CERT_DIR/privkey.pem"
fi

# Ensure agent dashboard SQLite is writable by PHP-FPM
touch /var/www/fusionpbx/app/agent_dashboard/skykin_local.db
chown www-data:www-data /var/www/fusionpbx/app/agent_dashboard/skykin_local.db
chmod 664 /var/www/fusionpbx/app/agent_dashboard/skykin_local.db
chown www-data:www-data /var/www/fusionpbx/app/agent_dashboard

# FusionPBX's CDR importer authenticates mod_xml_cdr posts by reading the expected
# credentials back out of FreeSWITCH's xml_cdr.conf.xml. FreeSWITCH runs in its own
# container, so that path does not exist here and every post is answered with
# "access denied". Mirror just the credentials FusionPBX needs.
CDR_CRED="${CDR_CRED:-fusionpbx:fusionpbx}"
mkdir -p /etc/freeswitch/autoload_configs
cat > /etc/freeswitch/autoload_configs/xml_cdr.conf.xml <<EOF
<configuration name="xml_cdr.conf" description="XML CDR CURL logger">
  <settings>
    <param name="cred" value="${CDR_CRED}"/>
  </settings>
</configuration>
EOF
chown -R www-data:www-data /etc/freeswitch

# read_files() opens the CDR spool directory unconditionally; if it is missing PHP
# fatals on readdir(false) after the import, so make sure it exists.
mkdir -p /var/log/freeswitch/xml_cdr
chown -R www-data:www-data /var/log/freeswitch

# Write runtime .env for ESL (agent status sync)
cat > /var/www/fusionpbx/.env <<EOF
ESL_HOST=${ESL_HOST}
ESL_PORT=${ESL_PORT}
ESL_PASSWORD=${ESL_PASSWORD}
EOF
chown www-data:www-data /var/www/fusionpbx/.env

echo "SkyKin web starting"
echo "  DB:  ${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME}"
echo "  ESL: ${ESL_HOST}:${ESL_PORT}"
echo "  WSS upstream: ${FREESWITCH_WS_SCHEME}://${FREESWITCH_WS_UPSTREAM}"

php-fpm -D
exec nginx -g 'daemon off;'
