#!/bin/bash
# Restore ahununu inbound + agent ring on ecs-cc (pinned to last known-good git).
# Fixes: transfer 500, directory dialplan IVR, broken 02_skykin_did_ahununu.xml
# Welcome plays inside skykin_inbound.lua (NOT extension 500).
set -eu

COMMIT="${SKYKIN_RESTORE_COMMIT:-c72b43b2c}"
RAW="https://raw.githubusercontent.com/Etse-Sol/skykin-fusionpbx/${COMMIT}"
APP="${SKYKIN_APP:-/opt/skykin/app}"
PW=$(grep -E '^ESL_PASSWORD=' "$APP/.env" | cut -d= -f2-)
FS() { docker exec skykin-freeswitch fs_cli -H 127.0.0.1 -P 8021 -p "$PW" -x "$1"; }
LIVE=/opt/skykin/fs-live
mkdir -p "$LIVE"

echo "=== 1) Revert FusionPBX dialplan binding (transfer 500 does not work here) ==="
docker exec skykin-freeswitch sh -c '
  f=/etc/freeswitch/autoload_configs/lua.conf.xml
  sed -i "s|value=\"directory dialplan\"|value=\"directory\"|g" "$f"
  sed -i "s|value=\"directory  dialplan\"|value=\"directory\"|g" "$f"
  grep xml-handler-bindings "$f"
'

echo "=== 2) Inbound DID -> blacklist -> skykin_inbound.lua (NO transfer 500) ==="
docker exec -i skykin-freeswitch tee /etc/freeswitch/dialplan/public/02_skykin_did_ahununu.xml >/dev/null <<'EOF'
<include>
  <extension name="skykin_inbound_did_ahununu">
    <condition field="destination_number" expression="^\+?(?:251)?0?11619803[5-9]$" break="on-false">
      <action application="set" data="rtcp_audio_interval_msec=0"/>
      <action application="set" data="rtp_advertise_ip=10.0.0.77"/>
      <action application="set" data="include_external_ip=false"/>
      <action application="set" data="rtp_secure_media=false"/>
      <action application="set" data="media_webrtc=false"/>
      <action application="set" data="domain_name=ahununu"/>
      <action application="export" data="domain_name=ahununu"/>
      <action application="set" data="call_direction=inbound"/>
      <action application="set" data="hangup_after_bridge=true"/>
      <action application="set" data="continue_on_fail=false"/>
      <action application="set" data="ignore_early_media=true"/>
      <action application="set" data="bridge_early_media=false"/>
      <action application="set" data="originate_early_media=false"/>
      <action application="set" data="record_stereo=false"/>
      <action application="set" data="record_path=/var/lib/freeswitch/recordings/ahununu/archive/\${strftime(%Y)}/\${strftime(%b)}/\${strftime(%d)}"/>
      <action application="set" data="record_name=\${uuid}.wav"/>
      <action application="set" data="api_on_answer=uuid_record \${uuid} start \${record_path}/\${record_name}"/>
      <action application="set" data="instant_ringback=true"/>
      <action application="lua" data="/etc/freeswitch/scripts/skykin_cc_prune.lua 8000@ahununu"/>
      <action application="export" data="nolocal:execute_on_hangup=lua::/etc/freeswitch/scripts/skykin_cc_drop.lua"/>
      <action application="set" data="cc_export_vars=execute_on_hangup"/>
      <action application="lua" data="/etc/freeswitch/scripts/skykin_bl_gate.lua"/>
    </condition>
    <condition field="${skykin_blocked}" expression="^true$" break="on-true">
      <action application="hangup" data="CALL_REJECTED"/>
    </condition>
    <condition>
      <action application="ring_ready"/>
      <action application="lua" data="/etc/freeswitch/scripts/skykin_inbound.lua"/>
    </condition>
  </extension>
</include>
EOF

echo "=== 3) skykin_cc_prune + skykin_inbound from GitHub $COMMIT ==="
curl -fsSL -o /tmp/skykin_cc_prune.lua "$RAW/docker/freeswitch/scripts/skykin_cc_prune.lua"
curl -fsSL -o /tmp/skykin_inbound.lua "$RAW/docker/freeswitch/scripts/skykin_inbound.lua"
docker cp /tmp/skykin_cc_prune.lua skykin-freeswitch:/etc/freeswitch/scripts/skykin_cc_prune.lua
docker cp /tmp/skykin_inbound.lua skykin-freeswitch:/etc/freeswitch/scripts/skykin_inbound.lua
echo "  deployed lua from $COMMIT"

echo "=== 4) Queue strategy longest-idle-agent (not ring-all) ==="
docker exec skykin-freeswitch sh -c '
  f=/etc/freeswitch/autoload_configs/callcenter.conf.xml
  sed -i "s|value=\"ring-all\"|value=\"longest-idle-agent\"|g" "$f"
  grep -E "strategy|8000" "$f" | head -8
'
docker exec skykin-db psql -U fusionpbx -d fusionpbx -c \
  "UPDATE v_call_center_queues SET queue_strategy = 'longest-idle-agent' WHERE queue_extension = '8000';" \
  2>/dev/null || true
FS "callcenter_config queue reload 8000@ahununu" 2>/dev/null || true

echo "=== 5) Persist (survive container recreate) ==="
docker cp skykin-freeswitch:/etc/freeswitch/dialplan/public/02_skykin_did_ahununu.xml "$LIVE/"
docker cp skykin-freeswitch:/etc/freeswitch/scripts/skykin_inbound.lua "$LIVE/"
docker cp skykin-freeswitch:/etc/freeswitch/scripts/skykin_cc_prune.lua "$LIVE/" 2>/dev/null || true

FS "reloadxml"

echo "=== 6) Verify dialplan (must NOT show transfer 500) ==="
docker exec skykin-freeswitch grep -E 'skykin_inbound|transfer' /etc/freeswitch/dialplan/public/02_skykin_did_ahununu.xml

echo "=== 7) Dashboard index.php from GitHub $COMMIT ==="
curl -fsSL -o /tmp/index.php "$RAW/app/agent_dashboard/index.php"
docker cp /tmp/index.php skykin-web:/var/www/fusionpbx/app/agent_dashboard/index.php
docker exec skykin-web php -l /var/www/fusionpbx/app/agent_dashboard/index.php
echo "  index.php deployed from $COMMIT"

echo ""
echo "DONE. Test:"
echo "  1) Agent 202 Ready + Registered on dashboard (Ctrl+Shift+R)"
echo "  2) fs sofia_contact */202@ahununu  -> must show sip:..."
echo "  3) Call 8414 — lookup tab + Answer/Decline in phone panel (top right)"
echo "  4) grep 'skykin inbound pick' /var/log/freeswitch/freeswitch.log | tail -3"
