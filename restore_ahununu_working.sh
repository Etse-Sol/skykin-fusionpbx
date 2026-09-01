#!/bin/bash
# Restore ahununu inbound + agent ring on ecs-cc.
# Fixes: transfer 500, directory dialplan IVR, broken 02_skykin_did_ahununu.xml
# Welcome plays inside skykin_inbound.lua (NOT extension 500).
set -eu

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

echo "=== 3) skykin_cc_prune + skykin_inbound (longest-idle hunt, one agent) ==="
PRUNE="$APP/docker/freeswitch/scripts/skykin_cc_prune.lua"
INB="$APP/docker/freeswitch/scripts/skykin_inbound.lua"
if [ -f "$INB" ] && [ -f "$PRUNE" ]; then
  docker cp "$PRUNE" skykin-freeswitch:/etc/freeswitch/scripts/skykin_cc_prune.lua
  docker cp "$INB" skykin-freeswitch:/etc/freeswitch/scripts/skykin_inbound.lua
  echo "  copied from $APP/docker/freeswitch/scripts/"
else
  echo "  WARN: repo lua missing — use curl raw files from GitHub commit"
fi

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

echo "=== 7) Dashboard (optional — copy index.php separately if needed) ==="
IDX="$APP/app/agent_dashboard/index.php"
if [ -f "$IDX" ]; then
  docker cp "$IDX" skykin-web:/var/www/fusionpbx/app/agent_dashboard/index.php
  docker exec skykin-web php -l /var/www/fusionpbx/app/agent_dashboard/index.php
  echo "  index.php deployed from $IDX"
else
  echo "  SKIP: $IDX not found — deploy index.php manually"
fi

echo ""
echo "DONE. Test:"
echo "  1) Agent 202 Ready + Registered on dashboard (Ctrl+Shift+R)"
echo "  2) fs sofia_contact */202@ahununu  -> must show sip:..."
echo "  3) Call 8414 — lookup tab + Answer/Decline in phone panel (top right)"
echo "  4) grep 'skykin inbound pick' /var/log/freeswitch/freeswitch.log | tail -3"
