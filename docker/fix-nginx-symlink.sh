#!/bin/sh
# Restore skykin nginx after a bad docker cp into sites-enabled (symlink),
# then point /wss/ at a 7443 address the web container can actually TCP to.
#
# Never docker cp onto sites-enabled/skykin.conf — it is a symlink.
set -eu

WEB="${WEB_CONTAINER:-skykin-web}"
SRC="${SKYKIN_CONF_SRC:-/tmp/skykin-avail.conf}"

echo "=== repair symlink ==="
docker exec "$WEB" sh -c '
  rm -f /etc/nginx/sites-enabled/skykin.conf
  ls -l /etc/nginx/sites-available /etc/nginx/sites-enabled
'

if [ ! -s "$SRC" ]; then
  echo "ERROR: $SRC missing/empty — copy from backup container" >&2
  exit 1
fi
docker cp "$SRC" "$WEB:/etc/nginx/sites-available/skykin.conf"
docker exec "$WEB" ln -s /etc/nginx/sites-available/skykin.conf /etc/nginx/sites-enabled/skykin.conf
docker exec "$WEB" ls -l /etc/nginx/sites-enabled/skykin.conf /etc/nginx/sites-available/skykin.conf
docker exec "$WEB" nginx -t
docker exec "$WEB" nginx -s reload || true
echo "nginx repaired"
