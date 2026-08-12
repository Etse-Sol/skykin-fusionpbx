#!/bin/sh
set -eu

ESL_PASSWORD="${ESL_PASSWORD:-ClueCon}"
ESL_LISTEN_IP="${ESL_LISTEN_IP:-0.0.0.0}"
RTP_START="${RTP_START_PORT:-16384}"
RTP_END="${RTP_END_PORT:-16584}"
SIP_EXTERNAL_PORT="${SIP_EXTERNAL_PORT:-5080}"
# 5000 ms = send/receive RTCP on RTP+1. 0 disables RTCP and yields ICMP
# "port unreachable" when the carrier sends receiver reports.
RTCP_AUDIO_INTERVAL_MSEC="${RTCP_AUDIO_INTERVAL_MSEC:-5000}"
EXT_RTP_IP="${EXT_RTP_IP:-}"
EXT_SIP_IP="${EXT_SIP_IP:-${EXT_RTP_IP:-}}"

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

# Upsert an uncommented Sofia <param>. Inserts before the first </settings>
# if the name is missing. Leaves XML comments alone.
upsert_sofia_param() {
  _file=$1
  _name=$2
  _value=$3
  [ -f "$_file" ] || return 0
  if grep -q "^[[:space:]]*<param name=\"${_name}\"" "$_file"; then
    sed -i "s#^[[:space:]]*<param name=\"${_name}\".*#    <param name=\"${_name}\" value=\"${_value}\"/>#" "$_file" || true
  else
    sed -i "s#</settings>#    <param name=\"${_name}\" value=\"${_value}\"/>\n  </settings>#" "$_file" || true
  fi
}

set_fs_var() {
  _file=/etc/freeswitch/vars.xml
  _name=$1
  _value=$2
  [ -f "$_file" ] || return 0
  [ -n "$_value" ] || return 0
  if grep -q "data=\"${_name}=" "$_file"; then
    sed -i "s#data=\"${_name}=[^\"]*\"#data=\"${_name}=${_value}\"#" "$_file" || true
  fi
}

INTERNAL=/etc/freeswitch/sip_profiles/internal.xml
if [ -f "$INTERNAL" ]; then
  if grep -q 'ws-binding' "$INTERNAL"; then
    sed -i "s#<param name=\"ws-binding\".*#<param name=\"ws-binding\" value=\":5066\"/>#" "$INTERNAL" || true
  else
    sed -i "s#</settings>#    <param name=\"ws-binding\" value=\":5066\"/>\n  </settings>#" "$INTERNAL" || true
  fi
fi

# Trunk / carrier profile (Ethio interconnect uses 5080). Bake live-server
# media fixes here so a container rebuild does not undo them:
#   - sip-port 5080
#   - RTCP on RTP+1 (carrier IMS often will not open the subscriber path
#     without receiver reports; disabled RTCP produced ICMP port unreachable)
EXTERNAL=/etc/freeswitch/sip_profiles/external.xml
EXTERNAL6=/etc/freeswitch/sip_profiles/external-ipv6.xml
upsert_sofia_param "$EXTERNAL"  sip-port "$SIP_EXTERNAL_PORT"
upsert_sofia_param "$EXTERNAL6" sip-port "$SIP_EXTERNAL_PORT"

for _profile in "$INTERNAL" "$EXTERNAL" "$EXTERNAL6"; do
  upsert_sofia_param "$_profile" rtcp-audio-interval-msec "$RTCP_AUDIO_INTERVAL_MSEC"
  upsert_sofia_param "$_profile" rtcp-video-interval-msec "$RTCP_AUDIO_INTERVAL_MSEC"
done

# Advertise the interconnect IP in SDP (e.g. 10.0.0.93), not the Docker bridge.
set_fs_var external_rtp_ip "$EXT_RTP_IP"
set_fs_var external_sip_ip "$EXT_SIP_IP"

echo "SkyKin FreeSWITCH starting"
echo "  ESL:      ${ESL_LISTEN_IP}:8021"
echo "  WS:       :5066"
echo "  SIP ext:  :${SIP_EXTERNAL_PORT}/udp (trunk)"
echo "  RTP:      ${RTP_START}-${RTP_END}/udp"
echo "  RTCP:     RTP+1 (interval ${RTCP_AUDIO_INTERVAL_MSEC} ms) — odd ports in the RTP range must be published"
if [ -n "$EXT_RTP_IP" ]; then
  echo "  ext-rtp:  ${EXT_RTP_IP}"
fi
if [ -n "$EXT_SIP_IP" ]; then
  echo "  ext-sip:  ${EXT_SIP_IP}"
fi

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
