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
FREESWITCH_WS_UPSTREAM="${FREESWITCH_WS_UPSTREAM:-freeswitch:5066}"

# Host-network FreeSWITCH has no Docker DNS name. extra_hosts is preferred;
# this lets an existing image also pin "freeswitch" without a compose overlay.
if [ -n "${FREESWITCH_HOSTS_IP:-}" ]; then
  grep -qE '(^|[[:space:]])freeswitch([[:space:]]|$)' /etc/hosts || \
    echo "${FREESWITCH_HOSTS_IP} freeswitch" >> /etc/hosts
fi

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

# Patch nginx upstream for FreeSWITCH plain WebSocket (never FS :7443 —
# that cert has no SAN and nginx/browsers close the socket with 1006).
WS_UPSTREAM="${FREESWITCH_WS_UPSTREAM#http://}"
WS_UPSTREAM="${WS_UPSTREAM#https://}"
sed -i "s|http://FREESWITCH_WS_UPSTREAM/|http://${WS_UPSTREAM}/|g" /etc/nginx/sites-available/skykin.conf
sed -i "s|http://FREESWITCH_WS_UPSTREAM|http://${WS_UPSTREAM}|g" /etc/nginx/sites-available/skykin.conf

# Ensure agent dashboard SQLite is writable by PHP-FPM
touch /var/www/fusionpbx/app/agent_dashboard/skykin_local.db
chown www-data:www-data /var/www/fusionpbx/app/agent_dashboard/skykin_local.db
chmod 664 /var/www/fusionpbx/app/agent_dashboard/skykin_local.db
chown www-data:www-data /var/www/fusionpbx/app/agent_dashboard

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
echo "  WSS upstream: ${FREESWITCH_WS_UPSTREAM}"

php-fpm -D
exec nginx -g 'daemon off;'
