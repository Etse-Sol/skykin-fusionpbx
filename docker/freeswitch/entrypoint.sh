#!/bin/bash
set -euo pipefail

ESL_PASSWORD="${ESL_PASSWORD:-ClueCon}"
ESL_LISTEN_IP="${ESL_LISTEN_IP:-0.0.0.0}"
WS_BIND="${WS_BIND:-0.0.0.0}"
RTP_START="${RTP_START_PORT:-16384}"
RTP_END="${RTP_END_PORT:-16584}"

mkdir -p /etc/freeswitch/autoload_configs /etc/freeswitch/sip_profiles /var/lib/freeswitch/recordings

# Event Socket — must accept connections from the web container
cat > /etc/freeswitch/autoload_configs/event_socket.conf.xml <<EOF
<configuration name="event_socket.conf" description="Socket Client">
  <settings>
    <param name="nat-map" value="false"/>
    <param name="listen-ip" value="${ESL_LISTEN_IP}"/>
    <param name="listen-port" value="8021"/>
    <param name="password" value="${ESL_PASSWORD}"/>
  </settings>
</configuration>
EOF

# Prefer wall clock (same fix as the bare-metal VM)
if [ -f /etc/freeswitch/autoload_configs/switch.conf.xml ]; then
  sed -i 's/enable-monotonic-timing" value="true"/enable-monotonic-timing" value="false"/g' \
    /etc/freeswitch/autoload_configs/switch.conf.xml || true
fi

# Narrow RTP range so docker port maps stay manageable in lab/full-compose mode.
# For production SIP trunks, prefer network_mode: host and a full RTP range.
if [ -f /etc/freeswitch/autoload_configs/switch.conf.xml ]; then
  if grep -q 'rtp-start-port' /etc/freeswitch/autoload_configs/switch.conf.xml; then
    sed -i "s/rtp-start-port\" value=\"[0-9]*\"/rtp-start-port\" value=\"${RTP_START}\"/" \
      /etc/freeswitch/autoload_configs/switch.conf.xml || true
    sed -i "s/rtp-end-port\" value=\"[0-9]*\"/rtp-end-port\" value=\"${RTP_END}\"/" \
      /etc/freeswitch/autoload_configs/switch.conf.xml || true
  fi
fi

# Ensure internal profile exposes WebSocket for the softphone (/wss/ proxy)
INTERNAL=/etc/freeswitch/sip_profiles/internal.xml
if [ -f "$INTERNAL" ]; then
  # ws-binding: plain WS on 5066 (nginx terminates TLS on the web container)
  if grep -q 'ws-binding' "$INTERNAL"; then
    sed -i "s#<param name=\"ws-binding\".*#<param name=\"ws-binding\" value=\":5066\"/>#" "$INTERNAL" || true
  else
    sed -i "s#</settings>#  <param name=\"ws-binding\" value=\":5066\"/>\n  </settings>#" "$INTERNAL" || true
  fi
fi

echo "SkyKin FreeSWITCH starting"
echo "  ESL: ${ESL_LISTEN_IP}:8021 (password from ESL_PASSWORD)"
echo "  WS:  ${WS_BIND}:5066"
echo "  RTP: ${RTP_START}-${RTP_END}/udp"

# Run FreeSWITCH in foreground (image paths vary slightly by tag)
FS_BIN="$(command -v freeswitch || true)"
if [ -z "$FS_BIN" ]; then
  for c in /usr/bin/freeswitch /usr/local/bin/freeswitch /usr/local/freeswitch/bin/freeswitch; do
    if [ -x "$c" ]; then FS_BIN="$c"; break; fi
  done
fi
if [ -z "$FS_BIN" ]; then
  echo "ERROR: freeswitch binary not found in image" >&2
  exit 1
fi

exec "$FS_BIN" -nonat -nf -nc -nosql
