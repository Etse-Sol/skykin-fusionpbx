#!/bin/sh
# Fix agent dashboard WebSocket close 1006 after FreeSWITCH is on host net.
#
# Browser:  wss://196.189.236.140:8088/wss/   (page cert — already accepted)
# nginx:    http://freeswitch:5066/           (plain WS, trailing slash)
# FS:       0.0.0.0:5066                      (not only 10.0.0.93:7443)
#
# Run on flipstar-app-server. Restarts Sofia INTERNAL only (not the Ethio
# external profile / not the FS container).
set -eu

WEB="${WEB_CONTAINER:-skykin-web}"
FS="${FREESWITCH_CONTAINER:-skykin-freeswitch}"
FS_IP="${FS_IP:-10.0.0.93}"

echo "=== dump nginx /wss/ ==="
docker exec "$WEB" sh -c 'grep -RIn --include="*.conf" "wss\|7443\|5066\|proxy_pass" /etc/nginx 2>/dev/null' || true

echo
echo "=== reachability from web ==="
docker exec "$WEB" getent hosts freeswitch || true
docker exec "$WEB" sh -c "timeout 3 bash -c 'echo >/dev/tcp/${FS_IP}/7443' && echo TCP_7443_OK || echo TCP_7443_FAIL"
docker exec "$WEB" sh -c "timeout 3 bash -c 'echo >/dev/tcp/${FS_IP}/5066' && echo TCP_5066_OK || echo TCP_5066_FAIL"

echo
echo "=== enable FS plain WS on 0.0.0.0:5066 (internal profile only) ==="
docker exec "$FS" sh -c '
f=/etc/freeswitch/sip_profiles/internal.xml
[ -f "$f" ] || { echo "missing $f"; exit 1; }
cp -a "$f" "$f.bak.wss-$(date +%Y%m%d%H%M%S)"
# Drop XML comments around these params so Sofia actually loads them.
sed -i "s/<!--[[:space:]]*<param name=\"ws-binding\"[^>]*\\/>[[:space:]]*-->/<param name=\"ws-binding\" value=\"0.0.0.0:5066\"\\/>/" "$f"
sed -i "s/<!--[[:space:]]*<param name=\"wss-binding\"[^>]*\\/>[[:space:]]*-->/<param name=\"wss-binding\" value=\"0.0.0.0:7443\"\\/>/" "$f"
if grep -q "<param name=\"ws-binding\"" "$f"; then
  sed -i "s#<param name=\"ws-binding\".*#    <param name=\"ws-binding\" value=\"0.0.0.0:5066\"/>#" "$f"
else
  sed -i "s#</settings>#    <param name=\"ws-binding\" value=\"0.0.0.0:5066\"/>\n  </settings>#" "$f"
fi
if grep -q "<param name=\"wss-binding\"" "$f"; then
  sed -i "s#<param name=\"wss-binding\".*#    <param name=\"wss-binding\" value=\"0.0.0.0:7443\"/>#" "$f"
fi
echo "--- bindings ---"
grep -n "ws-binding\|wss-binding\|sip-ip" "$f" | head -20
'
docker exec "$FS" fs_cli -x 'sofia profile internal restart'
sleep 3
echo "--- sofia status ---"
docker exec "$FS" fs_cli -x 'sofia status'
echo "--- host listeners ---"
ss -lntp | grep -E '5066|7443' || true

echo
echo "=== point nginx /wss/ at http://freeswitch:5066/ ==="
CONF=$(docker exec "$WEB" sh -c 'grep -Rlm1 "location /wss/" /etc/nginx 2>/dev/null | head -1')
if [ -z "$CONF" ]; then
  CONF=$(docker exec "$WEB" sh -c 'grep -Rlm1 "7443\|/wss" /etc/nginx 2>/dev/null | head -1')
fi
echo "nginx conf: ${CONF:-MISSING}"
if [ -n "$CONF" ]; then
  docker cp "$WEB:$CONF" /tmp/skykin-wss.conf
  python3 - <<'PY'
from pathlib import Path
import re
p = Path("/tmp/skykin-wss.conf")
text = p.read_text()
block = """    location /wss/ {
        proxy_pass http://freeswitch:5066/;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_buffering off;
    }"""
loc_re = re.compile(r"location\s+/wss/\s*\{.*?\n[ \t]*\}", re.S)
if loc_re.search(text):
    text = loc_re.sub(block, text, count=1)
text = re.sub(r"proxy_pass\s+https?://freeswitch:7443/;?", "proxy_pass http://freeswitch:5066/;", text)
if "proxy_ssl_verify" not in text and "7443" in text:
    text = text.replace("proxy_pass https://freeswitch:7443;", "proxy_pass http://freeswitch:5066/;\n        proxy_ssl_verify off;")
p.write_text(text)
print("wrote /tmp/skykin-wss.conf")
PY
  docker cp /tmp/skykin-wss.conf "$WEB:$CONF"
fi

docker exec "$WEB" nginx -t
docker exec "$WEB" nginx -s reload

docker exec "$WEB" nginx -t
docker exec "$WEB" nginx -s reload

echo
echo "=== websocket probe (101 = upgrade ok) ==="
curl -sSi -k --max-time 8 \
  -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
  -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
  -H 'Sec-WebSocket-Version: 13' \
  -H 'Sec-WebSocket-Protocol: sip' \
  https://127.0.0.1:8088/wss/ | head -40

echo
echo "Reload the agent page (Agent2). WebSocket should stay connected."
echo "Do not docker restart $WEB until FREESWITCH_WS_UPSTREAM=freeswitch:5066"
echo "or this nginx patch is regenerated back to :7443."
