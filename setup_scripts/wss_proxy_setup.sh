#!/bin/bash
# SkyKin Technologies - Agent Softphone WebSocket Proxy Setup
# Run on the FusionPBX VM as root: bash setup_scripts/wss_proxy_setup.sh
# ============================================================
# Browsers reject FreeSWITCH's self-signed cert on port 7443 (no SAN, untrusted
# issuer) and cannot show a "proceed anyway" prompt for a WebSocket, so the
# handshake aborts and JS only sees close code 1006.
#
# Terminating TLS at nginx on 443 and proxying to FreeSWITCH's plain ws port
# keeps the socket same-origin with the dashboard, so it inherits whatever cert
# the browser already accepted for the page.
#
#   Browser --wss://host/wss/--> nginx :443 --ws--> FreeSWITCH :5066
# ============================================================

set -e

NGINX_SITE="/etc/nginx/sites-available/fusionpbx"
WS_PORT="5066"

echo "=== SkyKin WSS Proxy Setup ==="

if [ ! -f "$NGINX_SITE" ]; then
    echo "ERROR: $NGINX_SITE not found"
    exit 1
fi

# ── Step 1: Locate FreeSWITCH's ws listener ───────────────────────────────
echo ""
echo "[1/4] Locating FreeSWITCH ws listener on port ${WS_PORT}..."

WS_ADDR=$(ss -tlnH "sport = :${WS_PORT}" | awk '{print $4}' | head -1)

if [ -z "$WS_ADDR" ]; then
    echo "ERROR: nothing is listening on port ${WS_PORT}."
    echo "Enable ws-binding on the internal SIP profile in FusionPBX:"
    echo "  Advanced > SIP Profiles > internal > ws-binding = :${WS_PORT}"
    echo "then rescan the profile: fs_cli -x 'sofia profile internal rescan'"
    exit 1
fi

echo "      found: ${WS_ADDR}"

# ── Step 2: Add the /wss/ location to the 443 server block ────────────────
echo ""
echo "[2/4] Adding /wss/ location to nginx..."

if grep -q 'location /wss/' "$NGINX_SITE"; then
    echo "      already configured, leaving it untouched:"
    grep -A2 'location /wss/' "$NGINX_SITE" | grep proxy_pass | sed 's/^/      /'
else
    cp "$NGINX_SITE" "${NGINX_SITE}.bak.$(date +%Y%m%d%H%M%S)"

    # Insert just after the existing /websockets/ proxy inside the 443 block
    python3 - "$NGINX_SITE" "$WS_ADDR" <<'PYEOF'
import re
import sys

path, ws_addr = sys.argv[1], sys.argv[2]

block = """
	#SkyKin agent softphone - proxy WSS to FreeSWITCH ws so the socket shares
	#the dashboard's certificate instead of the self-signed cert on 7443
	location /wss/ {
		proxy_pass http://%s;
		proxy_http_version 1.1;
		proxy_set_header Upgrade $http_upgrade;
		proxy_set_header Connection "upgrade";
		proxy_set_header Host $host;
		proxy_set_header X-Real-IP $remote_addr;
		proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
		proxy_set_header X-Forwarded-Proto $scheme;
		#SIP registrations idle between calls; keep the tunnel open
		proxy_read_timeout 3600s;
		proxy_send_timeout 3600s;
	}
""" % ws_addr

with open(path) as fh:
    conf = fh.read()

anchor = re.search(
    r"\n\tlocation /websockets/ \{.*?\n\t\}\n", conf, re.DOTALL
)
if not anchor:
    sys.exit("ERROR: could not find the /websockets/ block to anchor to")

conf = conf[: anchor.end()] + block + conf[anchor.end() :]

with open(path, "w") as fh:
    fh.write(conf)

print("      inserted /wss/ -> %s" % ws_addr)
PYEOF
fi

# ── Step 3: Validate ──────────────────────────────────────────────────────
echo ""
echo "[3/4] Validating nginx configuration..."
nginx -t

# ── Step 4: Reload ────────────────────────────────────────────────────────
echo ""
echo "[4/4] Reloading nginx..."
systemctl reload nginx

echo ""
echo "=== Done ==="
echo "The agent dashboard now reaches FreeSWITCH at wss://<host>/wss/"
echo "Verify with:"
echo "  curl -sSi -o /dev/null -w '%{http_code}\\n' -k https://127.0.0.1/wss/ \\"
echo "    -H 'Connection: Upgrade' -H 'Upgrade: websocket' \\"
echo "    -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' -H 'Sec-WebSocket-Version: 13'"
echo "  (101 = WebSocket upgrade accepted)"
