#!/bin/sh
# Fix agent dashboard WebSocket close 1006 after FreeSWITCH is on host net.
#
# Live call-center-web MUST proxy /wss/ to FreeSWITCH WSS :7443 (not :5066).
# Never docker cp onto sites-enabled/skykin.conf — it is a symlink.
# Docker often cannot TCP to host-bound 7443 until INPUT allows 172.16/12.
set -eu

WEB="${WEB_CONTAINER:-skykin-web}"
GW=$(docker inspect "$WEB" --format '{{range .NetworkSettings.Networks}}{{.Gateway}}{{end}}' | awk '{print $1}')

echo "=== repair sites-enabled symlink ==="
docker exec "$WEB" sh -c 'rm -f /etc/nginx/sites-enabled/skykin.conf'
if [ ! -s /tmp/skykin-avail.conf ]; then
  echo "ERROR: /tmp/skykin-avail.conf missing" >&2
  exit 1
fi
python3 - "$GW" <<'PY'
import re, sys
from pathlib import Path
gw = sys.argv[1]
p = Path("/tmp/skykin-avail.conf")
text = p.read_text()
text = re.sub(
    r"proxy_pass\s+https?://[^\s;]+:(7443|5066)/?\s*;\s*(proxy_ssl_verify off;)?",
    "proxy_pass https://%s:7443;\n        proxy_ssl_verify off;" % gw,
    text,
)
text = text.replace("proxy_ssl_verify off;proxy_http_version", "proxy_ssl_verify off;\n        proxy_http_version")
p.write_text(text)
print("host copy ready, gw=", gw)
PY
docker cp /tmp/skykin-avail.conf "$WEB:/etc/nginx/sites-available/skykin.conf"
docker exec "$WEB" ln -s /etc/nginx/sites-available/skykin.conf /etc/nginx/sites-enabled/skykin.conf
docker exec "$WEB" ls -l /etc/nginx/sites-enabled/skykin.conf

echo "=== allow Docker -> host :7443 ==="
iptables -C INPUT -p tcp --dport 7443 -s 172.16.0.0/12 -j ACCEPT 2>/dev/null \
  || iptables -I INPUT -p tcp --dport 7443 -s 172.16.0.0/12 -j ACCEPT
iptables -C INPUT -p tcp --dport 7443 -s 10.0.0.0/8 -j ACCEPT 2>/dev/null \
  || iptables -I INPUT -p tcp --dport 7443 -s 10.0.0.0/8 -j ACCEPT

echo "=== TCP from web ==="
docker exec "$WEB" sh -c "timeout 3 bash -c 'echo >/dev/tcp/${GW}/7443' && echo GW_7443_OK || echo GW_7443_FAIL"
docker exec "$WEB" sh -c "timeout 3 bash -c 'echo >/dev/tcp/10.0.0.93/7443' && echo IP_7443_OK || echo IP_7443_FAIL"
timeout 2 bash -c 'echo >/dev/tcp/127.0.0.1/7443' && echo HOST_LO_7443_OK || echo HOST_LO_7443_FAIL
timeout 2 bash -c "echo >/dev/tcp/${GW}/7443" && echo HOST_GW_7443_OK || echo HOST_GW_7443_FAIL

docker exec "$WEB" nginx -t
docker exec "$WEB" nginx -s reload

echo "=== probe (want HTTP/1.1 101) ==="
curl -sSi -k --max-time 8 \
  -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
  -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
  -H 'Sec-WebSocket-Version: 13' \
  -H 'Sec-WebSocket-Protocol: sip' \
  https://127.0.0.1:8088/wss/ | head -30
