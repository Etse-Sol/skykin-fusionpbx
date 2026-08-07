#!/bin/sh
set -eu

ESL_PASSWORD="${ESL_PASSWORD:-ClueCon}"
ESL_LISTEN_IP="${ESL_LISTEN_IP:-0.0.0.0}"
RTP_START="${RTP_START_PORT:-16384}"
RTP_END="${RTP_END_PORT:-16584}"

# Bootstrap vanilla config (same as safarov/freeswitch docker-entrypoint.sh).
# Our ENTRYPOINT replaces theirs, so we must do this ourselves.
if [ ! -f /etc/freeswitch/freeswitch.xml ]; then
  echo "Bootstrapping FreeSWITCH config from vanilla templates"
  mkdir -p /etc/freeswitch
  cp -a /usr/share/freeswitch/conf/vanilla/. /etc/freeswitch/
fi

mkdir -p \
  /etc/freeswitch/autoload_configs \
  /etc/freeswitch/sip_profiles \
  /var/lib/freeswitch/recordings \
  /var/log/freeswitch \
  /var/run/freeswitch \
  /usr/share/freeswitch/scripts

# Default ESL ACL is loopback.auto (blocks Docker bridge peers).
# rfc1918.auto allows Docker nets but NOT 127.0.0.1 (breaks fs_cli/healthcheck).
# Custom "skykin" list allows loopback + private ranges.
ACL_FILE=/etc/freeswitch/autoload_configs/acl.conf.xml
if [ -f "$ACL_FILE" ] && ! grep -q 'list name="skykin"' "$ACL_FILE"; then
  sed -i 's#</network-lists>#    <list name="skykin" default="deny">\n      <node type="allow" cidr="127.0.0.1/32"/>\n      <node type="allow" cidr="10.0.0.0/8"/>\n      <node type="allow" cidr="172.16.0.0/12"/>\n      <node type="allow" cidr="192.168.0.0/16"/>\n    </list>\n  </network-lists>#' "$ACL_FILE" || true
fi

cat > /etc/freeswitch/autoload_configs/event_socket.conf.xml <<EOF
<configuration name="event_socket.conf" description="Socket Client">
  <settings>
    <param name="nat-map" value="false"/>
    <param name="listen-ip" value="${ESL_LISTEN_IP}"/>
    <param name="listen-port" value="8021"/>
    <param name="password" value="${ESL_PASSWORD}"/>
    <param name="apply-inbound-acl" value="skykin"/>
  </settings>
</configuration>
EOF

if [ -f /etc/freeswitch/autoload_configs/switch.conf.xml ]; then
  sed -i 's/enable-monotonic-timing" value="true"/enable-monotonic-timing" value="false"/g' \
    /etc/freeswitch/autoload_configs/switch.conf.xml || true
  sed -i "s/rtp-start-port\" value=\"[0-9]*\"/rtp-start-port\" value=\"${RTP_START}\"/" \
    /etc/freeswitch/autoload_configs/switch.conf.xml || true
  sed -i "s/rtp-end-port\" value=\"[0-9]*\"/rtp-end-port\" value=\"${RTP_END}\"/" \
    /etc/freeswitch/autoload_configs/switch.conf.xml || true
fi

INTERNAL=/etc/freeswitch/sip_profiles/internal.xml
if [ -f "$INTERNAL" ]; then
  if grep -q 'ws-binding' "$INTERNAL"; then
    sed -i "s#<param name=\"ws-binding\".*#<param name=\"ws-binding\" value=\":5066\"/>#" "$INTERNAL" || true
  else
    sed -i "s#</settings>#  <param name=\"ws-binding\" value=\":5066\"/>\n  </settings>#" "$INTERNAL" || true
  fi
fi

echo "SkyKin FreeSWITCH starting"
echo "  ESL: ${ESL_LISTEN_IP}:8021"
echo "  WS:  :5066"
echo "  RTP: ${RTP_START}-${RTP_END}/udp"

FS_BIN="$(command -v freeswitch || true)"
if [ -z "$FS_BIN" ]; then
  for c in /usr/bin/freeswitch /usr/local/bin/freeswitch /usr/local/freeswitch/bin/freeswitch; do
    if [ -x "$c" ]; then FS_BIN="$c"; break; fi
  done
fi
if [ -z "$FS_BIN" ]; then
  echo "ERROR: freeswitch binary not found in image" >&2
  ls -la /usr/bin/freeswitch /usr/local/bin/freeswitch 2>&1 || true
  exit 1
fi

# -nc = no console, -nf = no fork, -nonat = skip auto-NAT (Docker).
# SCHED_FIFO / nice warnings in Docker are expected and non-fatal.
exec "$FS_BIN" -nonat -nf -nc -nosql
