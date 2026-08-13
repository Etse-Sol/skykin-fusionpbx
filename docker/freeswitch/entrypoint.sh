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
# Ethio IMS trunk (FusionPBX v_gateways). Vanilla FreeSWITCH only ships
# example.com — without this file, agent→mobile never leaves the box.
TRUNK_GATEWAY_NAME="${TRUNK_GATEWAY_NAME:-SIP}"
TRUNK_PROXY="${TRUNK_PROXY:-}"
TRUNK_REALM="${TRUNK_REALM:-}"
TRUNK_USERNAME="${TRUNK_USERNAME:-}"
TRUNK_FROM_USER="${TRUNK_FROM_USER:-${TRUNK_USERNAME:-}}"
TRUNK_FROM_DOMAIN="${TRUNK_FROM_DOMAIN:-${TRUNK_REALM:-}}"
TRUNK_REGISTER="${TRUNK_REGISTER:-false}"
TRUNK_PASSWORD="${TRUNK_PASSWORD:-}"
TRUNK_TRANSPORT="${TRUNK_TRANSPORT:-udp}"
TRUNK_CODEC_PREFS="${TRUNK_CODEC_PREFS:-PCMA,PCMU}"
# Second Ethio DID (must show REGED alongside SIP).
TRUNK2_GATEWAY_NAME="${TRUNK2_GATEWAY_NAME:-SIP759}"
TRUNK2_USERNAME="${TRUNK2_USERNAME:-}"
TRUNK2_PASSWORD="${TRUNK2_PASSWORD:-}"
TRUNK2_FROM_USER="${TRUNK2_FROM_USER:-${TRUNK2_USERNAME:-}}"
TRUNK2_FROM_DOMAIN="${TRUNK2_FROM_DOMAIN:-${TRUNK_FROM_DOMAIN:-}}"
TRUNK2_REALM="${TRUNK2_REALM:-${TRUNK_REALM:-}}"
TRUNK2_PROXY="${TRUNK2_PROXY:-${TRUNK_PROXY:-}}"
TRUNK2_REGISTER="${TRUNK2_REGISTER:-${TRUNK_REGISTER:-false}}"

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
# Bind WS/WSS on all interfaces. Pinning them to EXT_SIP_IP (10.0.0.93)
# makes skykin-web on the Docker bridge unable to complete /wss/ (browser
# close 1006). Signaling to Ethio stays on the external profile.
upsert_sofia_param "$INTERNAL" ws-binding "0.0.0.0:5066"
upsert_sofia_param "$INTERNAL" wss-binding "0.0.0.0:7443"

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
# Literal profile values so STUN cannot put the public IP in Contact again.
set_fs_var external_rtp_ip "$EXT_RTP_IP"
set_fs_var external_sip_ip "$EXT_SIP_IP"
if [ -n "$EXT_SIP_IP" ]; then
  upsert_sofia_param "$EXTERNAL"  ext-sip-ip "$EXT_SIP_IP"
  upsert_sofia_param "$EXTERNAL"  sip-ip "$EXT_SIP_IP"
  upsert_sofia_param "$EXTERNAL6" ext-sip-ip "$EXT_SIP_IP"
fi
if [ -n "$EXT_RTP_IP" ]; then
  upsert_sofia_param "$EXTERNAL"  ext-rtp-ip "$EXT_RTP_IP"
  upsert_sofia_param "$EXTERNAL"  rtp-ip "$EXT_RTP_IP"
  upsert_sofia_param "$EXTERNAL6" ext-rtp-ip "$EXT_RTP_IP"
fi

# Drop the vanilla example.com gateway so Sofia does not show a fake trunk.
rm -f /etc/freeswitch/sip_profiles/external/example.xml \
      /etc/freeswitch/sip_profiles/external/example.com.xml 2>/dev/null || true

write_ethio_gateway() {
  _file=$1
  _name=$2
  _user=$3
  _pass=$4
  _realm=$5
  _from_user=$6
  _from_domain=$7
  _proxy=$8
  _register=$9
  [ -n "$_name" ] && [ -n "$_user" ] && [ -n "$_proxy" ] || return 0
  cat > "$_file" <<EOF
<include>
  <gateway name="${_name}">
    <param name="username" value="${_user}"/>
    <param name="password" value="${_pass}"/>
    <param name="realm" value="${_realm}"/>
    <param name="from-user" value="${_from_user}"/>
    <param name="from-domain" value="${_from_domain}"/>
    <param name="proxy" value="${_proxy}"/>
    <param name="register" value="${_register}"/>
    <param name="register-transport" value="${TRUNK_TRANSPORT}"/>
    <param name="caller-id-in-from" value="true"/>
    <param name="extension-in-contact" value="true"/>
    <param name="expire-seconds" value="3600"/>
    <param name="retry-seconds" value="30"/>
    <param name="codec-prefs" value="${TRUNK_CODEC_PREFS}"/>
  </gateway>
</include>
EOF
}

mkdir -p /etc/freeswitch/dialplan/default \
         /etc/freeswitch/dialplan/client1.skykin.local
# 102→101 is processed in context default (user_context), not
# client1.skykin.local. This file must live in default/ or it never runs.
cat > /etc/freeswitch/dialplan/default/00_webrtc_local.xml <<'EOF'
<include>
  <extension name="webrtc_local" continue="false">
    <condition field="destination_number" expression="^(101|102)$">
      <action application="set" data="hangup_after_bridge=true"/>
      <action application="set" data="rtp_secure_media=optional"/>
      <action application="bridge" data="{media_webrtc=true,rtp_secure_media=optional,absolute_codec_string=OPUS}${sofia_contact(*/$1@client1.skykin.local)}"/>
    </condition>
  </extension>
</include>
EOF
cp /etc/freeswitch/dialplan/default/00_webrtc_local.xml \
   /etc/freeswitch/dialplan/client1.skykin.local/00_webrtc_local.xml
# Include at the top of context default. default/*.xml is expanded after
# skykin_local_extension, so a file in default/ alone never matches 101.
for f in /etc/freeswitch/dialplan/default.xml /etc/freeswitch/dialplan/*.xml; do
  [ -f "$f" ] || continue
  grep -q '<context name="default">' "$f" || continue
  sed -i '/00_webrtc_local.xml/d' "$f"
  sed -i '/<context name="default">/a\
    <X-PRE-PROCESS cmd="include" data="default/00_webrtc_local.xml"/>' "$f" || true
done

if [ -n "$TRUNK_PROXY" ]; then
  mkdir -p /etc/freeswitch/sip_profiles/external
  write_ethio_gateway /etc/freeswitch/sip_profiles/external/ethio.xml \
    "$TRUNK_GATEWAY_NAME" "$TRUNK_USERNAME" "$TRUNK_PASSWORD" "$TRUNK_REALM" \
    "$TRUNK_FROM_USER" "$TRUNK_FROM_DOMAIN" "$TRUNK_PROXY" "$TRUNK_REGISTER"
  write_ethio_gateway /etc/freeswitch/sip_profiles/external/ethio759.xml \
    "$TRUNK2_GATEWAY_NAME" "$TRUNK2_USERNAME" "$TRUNK2_PASSWORD" "$TRUNK2_REALM" \
    "$TRUNK2_FROM_USER" "$TRUNK2_FROM_DOMAIN" "$TRUNK2_PROXY" "$TRUNK2_REGISTER"
  # Agent 101 is WebRTC/Opus. Setting absolute_codec_string=PCMA on the A-leg
  # abandons the call before sofia/gateway/SIP is ever dialed. Put PCMA only
  # on the B-leg (curly-brace vars on bridge). Rewrite 09… / +251… → 251….
  cat > /etc/freeswitch/dialplan/default/00_ethio_mobile.xml <<EOF
<include>
  <extension name="ethio_mobile">
    <condition field="destination_number" expression="^(?:\\+?|00)?(?:251)?0?([79]\\d{8})\$">
      <action application="set" data="effective_caller_id_number=${TRUNK_USERNAME}"/>
      <action application="set" data="effective_caller_id_name=SkyKin"/>
      <action application="bridge" data="{absolute_codec_string=PCMA,origination_caller_id_number=${TRUNK_USERNAME},originate_timeout=60}sofia/gateway/${TRUNK_GATEWAY_NAME}/0\$1,sofia/gateway/${TRUNK_GATEWAY_NAME}/251\$1"/>
    </condition>
  </extension>
</include>
EOF
  rm -f /etc/freeswitch/dialplan/default/01_ethio_mobile.xml \
        /etc/freeswitch/dialplan/client1.skykin.local/01_ethio_mobile.xml
  cp /etc/freeswitch/dialplan/default/00_ethio_mobile.xml \
     /etc/freeswitch/dialplan/client1.skykin.local/00_ethio_mobile.xml
  # Hook ethio_mobile into the real SkyKin context. Do not write a second
  # <context name="client1.skykin.local"> — that either replaces 01_skykin
  # or is ignored, and 101 then ends with NO_ROUTE_DESTINATION.
  SKYKIN_CTX=/etc/freeswitch/dialplan/01_skykin_client1.skykin.local.xml
  if [ -f "$SKYKIN_CTX" ]; then
    if ! grep -q 'client1.skykin.local/\*\.xml' "$SKYKIN_CTX"; then
      sed -i '/<context name="client1.skykin.local">/a\
    <X-PRE-PROCESS cmd="include" data="client1.skykin.local/*.xml"/>' "$SKYKIN_CTX" || true
    fi
    rm -f /etc/freeswitch/dialplan/client1.skykin.local.xml
  elif [ ! -f /etc/freeswitch/dialplan/client1.skykin.local.xml ]; then
    cat > /etc/freeswitch/dialplan/client1.skykin.local.xml <<'EOF'
<include>
  <context name="client1.skykin.local">
    <X-PRE-PROCESS cmd="include" data="client1.skykin.local/*.xml"/>
  </context>
</include>
EOF
  fi
  echo "  Trunk:    ${TRUNK_GATEWAY_NAME} -> ${TRUNK_PROXY} (${TRUNK_REALM}) register=${TRUNK_REGISTER}"
  if [ -n "$TRUNK2_USERNAME" ]; then
    echo "  Trunk2:   ${TRUNK2_GATEWAY_NAME} ${TRUNK2_USERNAME} register=${TRUNK2_REGISTER}"
  fi
fi

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
