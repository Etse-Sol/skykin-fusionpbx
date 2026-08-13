#!/bin/sh
# Fix agent dashboard WebSocket close 1006 after FreeSWITCH is on host net.
#
# Live call-center-web MUST proxy /wss/ to FreeSWITCH WSS :7443 (not plain
# :5066). SIP.js sets Via/Contact transport=WSS; FS drops those on the
# ws listener. Docker cannot reach 10.0.0.93:7443 (rp_filter), so point
# nginx at the compose-network gateway where host-net FS now listens.
#
#   Browser --wss://host:8088/wss/--> nginx --https, verify off--> GW:7443
#
# Does not restart FreeSWITCH. Reloads nginx only.
set -eu

WEB="${WEB_CONTAINER:-skykin-web}"
GW=$(docker inspect "$WEB" --format '{{range .NetworkSettings.Networks}}{{.Gateway}}{{end}}')
GW=$(echo "$GW" | awk '{print $1}')
if [ -z "$GW" ]; then
  GW=$(docker exec "$WEB" ip route | awk '/default/{print $3; exit}')
fi
echo "web docker gateway: $GW"

echo "=== TCP from web ==="
docker exec "$WEB" getent hosts freeswitch || true
docker exec "$WEB" sh -c "timeout 3 bash -c 'echo >/dev/tcp/${GW}/7443' && echo GW_7443_OK || echo GW_7443_FAIL"
docker exec "$WEB" sh -c "timeout 3 bash -c 'echo >/dev/tcp/10.0.0.93/7443' && echo IP_7443_OK || echo IP_7443_FAIL"

echo "=== nginx files ==="
docker exec "$WEB" sh -c 'ls -l /etc/nginx/sites-enabled /etc/nginx/sites-available'

echo "=== patch every /wss/ proxy_pass -> https://${GW}:7443 (ssl verify off) ==="
docker exec "$WEB" sh -c 'grep -Rl "location /wss/" /etc/nginx 2>/dev/null' | while read -r CONF; do
  echo "file $CONF"
  SAFE=$(echo "$CONF" | tr / _)
  docker cp "$WEB:$CONF" "/tmp/skykin-wss${SAFE}.conf"
  python3 - "$GW" "/tmp/skykin-wss${SAFE}.conf" <<'PY'
import re, sys
from pathlib import Path
gw, path = sys.argv[1], Path(sys.argv[2])
text = path.read_text()
text = re.sub(
    r"proxy_pass\s+https?://[^\s;]+:(7443|5066)/?\s*;\s*(proxy_ssl_verify off;)?",
    "proxy_pass https://%s:7443;\n        proxy_ssl_verify off;" % gw,
    text,
)
path.write_text(text)
print("patched", path)
PY
  docker cp "/tmp/skykin-wss${SAFE}.conf" "$WEB:$CONF"
done

echo "=== /wss/ after ==="
docker exec "$WEB" sh -c 'grep -n "proxy_pass\|proxy_ssl_verify\|location /wss" /etc/nginx/sites-enabled/skykin.conf /etc/nginx/sites-available/skykin.conf 2>/dev/null'

docker exec "$WEB" nginx -t
docker exec "$WEB" nginx -s reload

echo "=== probe (want HTTP/1.1 101) ==="
curl -sSi -k --max-time 8 \
  -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
  -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
  -H 'Sec-WebSocket-Version: 13' \
  -H 'Sec-WebSocket-Protocol: sip' \
  https://127.0.0.1:8088/wss/ | head -30
