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

# PCMA belongs only on the B-leg (curly-brace vars on bridge). Do not set
# absolute_codec_string on the WebRTC A-leg.
docker exec -i "$CONTAINER" tee /etc/freeswitch/dialplan/default/00_ethio_mobile.xml >/dev/null <<'XML'
<include>
  <extension name="ethio_mobile">
    <condition field="destination_number" expression="^(?:\+?|00)?(?:251)?0?([79]\d{8})$">
      <action application="set" data="effective_caller_id_number=+251111138755"/>
      <action application="set" data="effective_caller_id_name=SkyKin"/>
      <action application="bridge" data="{absolute_codec_string=PCMA,origination_caller_id_number=+251111138755}sofia/gateway/SIP/251$1"/>
    </condition>
  </extension>
</include>
XML

docker exec -i "$CONTAINER" sh -c '
  cp /etc/freeswitch/dialplan/default/00_ethio_mobile.xml \
     /etc/freeswitch/dialplan/client1.skykin.local/00_ethio_mobile.xml
  rm -f /etc/freeswitch/dialplan/default/01_ethio_mobile.xml \
        /etc/freeswitch/dialplan/client1.skykin.local/01_ethio_mobile.xml
  # Do not write client1.skykin.local.xml if SkyKin already shipped a context.
'

docker exec -i "$CONTAINER" fs_cli -x 'reloadxml'
echo "--- gateway ---"
docker exec -i "$CONTAINER" fs_cli -x 'sofia status gateway'
echo "--- dialplan files ---"
docker exec -i "$CONTAINER" sh -c 'grep -n absolute_codec_string /etc/freeswitch/dialplan/default/*.xml /etc/freeswitch/dialplan/client1.skykin.local/*.xml 2>/dev/null || true'
echo
echo "Dialplan reloaded. From agent 101 (Registered) dial 0945184650."
echo "Expect sofia/gateway/SIP/251945184650 — not Abandoned with no gateway leg."
echo "Confirm with: docker logs --since 2m $CONTAINER 2>&1 | grep -E 'gateway/SIP|Abandoned|101@'"
