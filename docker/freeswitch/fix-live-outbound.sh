#!/bin/sh
# Restore agent → Ethio mobile outbound on the running skykin-freeswitch
# container. Run this on flipstar-app-server (the Docker host), not inside
# fs_cli and not inside skykin-web.
#
# What broke: a dialplan that set absolute_codec_string=PCMA on the A-leg.
# Agent 101 is WebRTC/Opus, so FreeSWITCH abandoned the call before bridging
# sofia/gateway/SIP. Calls connected before that change; they must connect
# again after this script. One-way audio toward the handset is a separate
# RTCP / carrier-transcoder issue.
set -eu

CONTAINER="${FREESWITCH_CONTAINER:-skykin-freeswitch}"

if ! docker inspect "$CONTAINER" >/dev/null 2>&1; then
  echo "ERROR: container $CONTAINER is not running" >&2
  exit 1
fi

docker exec -i "$CONTAINER" mkdir -p \
  /etc/freeswitch/dialplan/default \
  /etc/freeswitch/dialplan/client1.skykin.local

# Agent-to-agent MUST live in context default. Live logs show:
#   Processing 102 <102>->101 in context default
#   parsing [default->skykin_local_extension]
# Putting this only under client1.skykin.local/ never runs. python3 is
# not in the FS image; use tee/sed only.
docker exec -i "$CONTAINER" tee /etc/freeswitch/dialplan/default/00_webrtc_local.xml >/dev/null <<'XML'
<include>
  <extension name="webrtc_local" continue="false">
    <condition field="destination_number" expression="^(101|102)$">
      <action application="set" data="hangup_after_bridge=true"/>
      <action application="set" data="rtp_secure_media=optional"/>
      <action application="bridge" data="{media_webrtc=true,rtp_secure_media=optional,absolute_codec_string=OPUS}${sofia_contact(*/$1@client1.skykin.local)}"/>
    </condition>
  </extension>
</include>
XML
docker exec -i "$CONTAINER" sh -c '
  cp /etc/freeswitch/dialplan/default/00_webrtc_local.xml \
     /etc/freeswitch/dialplan/client1.skykin.local/00_webrtc_local.xml
  # default/*.xml is included AFTER skykin_local_extension in default.xml,
  # so 00_webrtc_local never ran (08:05 still executed record_session +
  # sofia/internal/101@domain → 503). Pin it at the top of context default.
  for f in /etc/freeswitch/dialplan/default.xml /etc/freeswitch/dialplan/*.xml; do
    [ -f "$f" ] || continue
    grep -q "<context name=\"default\">" "$f" || continue
    sed -i "/00_webrtc_local.xml/d" "$f"
    sed -i "/<context name=\"default\">/a\\    <X-PRE-PROCESS cmd=\"include\" data=\"default/00_webrtc_local.xml\"/>" "$f"
    echo "pinned webrtc_local at top of $f"
  done
'

# PCMA belongs only on the B-leg (curly-brace vars on bridge). Do not set
# absolute_codec_string on the WebRTC A-leg.
docker exec -i "$CONTAINER" tee /etc/freeswitch/dialplan/default/00_ethio_mobile.xml >/dev/null <<'XML'
<include>
  <extension name="ethio_mobile">
    <condition field="destination_number" expression="^(?:\+?|00)?(?:251)?0?([79]\d{8})$">
      <action application="set" data="effective_caller_id_number=+251111138755"/>
      <action application="set" data="effective_caller_id_name=SkyKin"/>
      <action application="bridge" data="{absolute_codec_string=PCMA,origination_caller_id_number=+251111138755,originate_timeout=60}sofia/gateway/SIP/0$1,sofia/gateway/SIP/251$1"/>
    </condition>
  </extension>
</include>
XML

docker exec -i "$CONTAINER" sh -c '
  cp /etc/freeswitch/dialplan/default/00_ethio_mobile.xml \
     /etc/freeswitch/dialplan/client1.skykin.local/00_ethio_mobile.xml
  rm -f /etc/freeswitch/dialplan/default/01_ethio_mobile.xml \
        /etc/freeswitch/dialplan/client1.skykin.local/01_ethio_mobile.xml
  f=/etc/freeswitch/dialplan/01_skykin_client1.skykin.local.xml
  if [ -f "$f" ] && ! grep -q "client1.skykin.local/\*\.xml" "$f"; then
    sed -i "/<context name=\"client1.skykin.local\">/a\\    <X-PRE-PROCESS cmd=\"include\" data=\"client1.skykin.local/*.xml\"/>" "$f"
  fi
  rm -f /etc/freeswitch/dialplan/client1.skykin.local.xml
'

docker exec -i "$CONTAINER" tee /tmp/patch_skykin.sed >/dev/null <<'SED'
s#{rtp_secure_media=optional,absolute_codec_string=OPUS,PCMU,PCMA}sofia/internal/\([^"]*\)#{media_webrtc=true,rtp_secure_media=optional,absolute_codec_string=OPUS}${sofia_contact(*/\1)}#g
s#bridge" data="user/\([^"]*\)#bridge" data="{media_webrtc=true,rtp_secure_media=optional,absolute_codec_string=OPUS}${sofia_contact(*/\1)}#g
SED
docker exec -i "$CONTAINER" sh -c '
  find /etc/freeswitch/dialplan -name "*.xml" | while read -r f; do
    grep -q "skykin_local_extension" "$f" || continue
    sed -i "/rtp_advertise_ip/d; /include_external_ip/d" "$f"
    sed -i -f /tmp/patch_skykin.sed "$f"
    echo "patched skykin_local_extension in $f"
    grep -n "bridge\|sofia_contact" "$f" | head -20
  done
'

docker exec -i "$CONTAINER" fs_cli -x 'reloadxml'

# This interconnect is an IP trunk. REGISTER returns 404/408 and leaves
# the gateway in FAIL_WAIT so outbound drops immediately.
docker exec -i "$CONTAINER" sh -c '
  for f in /etc/freeswitch/sip_profiles/external/*.xml; do
    [ -f "$f" ] || continue
    grep -q "gateway name=\"SIP\"" "$f" || continue
    sed -i "s/<param name=\"register\" value=\"true\"/<param name=\"register\" value=\"false\"/" "$f"
    echo "patched $f"
  done
  rm -f /etc/freeswitch/sip_profiles/external/example.xml \
        /etc/freeswitch/sip_profiles/external/example.com.xml
'
docker exec -i "$CONTAINER" fs_cli -x 'sofia profile external killgw example.com' || true
docker exec -i "$CONTAINER" fs_cli -x 'sofia profile external rescan'
sleep 2

echo "--- gateway ---"
docker exec -i "$CONTAINER" fs_cli -x 'sofia status gateway'
echo "--- dialplan files ---"
docker exec -i "$CONTAINER" sh -c 'ls -l /etc/freeswitch/dialplan/default/00_webrtc_local.xml; grep -n "webrtc_local\|skykin_local_extension\|rtp_advertise_ip\|user/" /etc/freeswitch/dialplan/default/*.xml /etc/freeswitch/dialplan/default.xml 2>/dev/null | head -80 || true'
echo
echo "Dialplan reloaded."
echo "Agent-to-agent: from 102 dial 101. Expect parsing [default->webrtc_local] FIRST"
echo "  then EXECUTE bridge({media_webrtc=true...}\${sofia_contact(...)})."
echo "NOT: record_session + bridge(sofia/internal/101@client1.skykin.local) → 503."
