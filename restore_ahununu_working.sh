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

echo "=== 3) skykin_inbound.lua — welcome + agent hunt ==="
docker exec -i skykin-freeswitch tee /etc/freeswitch/scripts/skykin_inbound.lua >/dev/null <<'LUA'
if not session then return end
local api = freeswitch.API()
local domain = session:getVariable("domain_name") or "ahununu"
local queue = "8000@" .. domain
local rtp_ip = session:getVariable("rtp_ext_ip") or "196.189.236.126"
local wait_wav = "/var/lib/freeswitch/recordings/" .. domain .. "/ahununu-waiting.wav"
local welcome = "/var/lib/freeswitch/recordings/" .. domain .. "/ahununu-opening.wav"
if session:getVariable("skykin_blocked") == "true" or not session:ready() then
  session:execute("hangup", "CALL_REJECTED"); return
end
session:execute("ring_ready")
session:setVariable("ringback", "${us-ring}")
session:setVariable("instant_ringback", "true")
session:setVariable("hangup_after_bridge", "true")
session:setVariable("continue_on_fail", "true")
session:setVariable("ignore_early_media", "true")
session:setVariable("bridge_early_media", "false")
session:setVariable("originate_early_media", "false")
local function play_wav(path, tag)
  local f = io.open(path, "r"); if not f then return false end; f:close()
  local ok, ans = pcall(function() return session:answered() end)
  if not (ok and ans) then session:execute("pre_answer") end
  freeswitch.consoleLog("NOTICE", tag .. " play " .. path .. "\n")
  session:streamFile(path); return true
end
play_wav(welcome, "skykin welcome")
local function registered(ext)
  local r = api:execute("sofia_contact", "*/" .. ext .. "@" .. domain) or ""
  return not r:find("error", 1, true) and not r:find("user_not_registered", 1, true) and r:find("sip:", 1, true)
end
local function on_a_call(ext)
  local chans = api:execute("show", "channels") or ""
  return chans:find("user/" .. ext .. "@" .. domain, 1, true) or chans:find("/" .. ext .. "@" .. domain, 1, true)
end
local function ready_ext(skip)
  local out = api:execute("callcenter_config", "queue list agents " .. queue) or ""
  local best, best_idle = nil, nil
  for line in out:gmatch("[^\r\n]+") do
    if line:find("|") and line:sub(1,5) ~= "name|" then
      local c = {}; for x in (line.."|"):gmatch("(.-)|") do c[#c+1]=x end
      local ext = (c[5] or ""):match("user/([^@]+)")
      if ext and not skip[ext] and c[6]=="Available" and (c[7]=="Waiting" or c[7]=="Idle")
          and registered(ext) and not on_a_call(ext) then
        local idle = tonumber(c[20]) or tonumber(c[14]) or 0
        if not best or idle < best_idle then best, best_idle = ext, idle end
      end
    end
  end
  return best
end
local skip = {}; local t0 = os.time()
while session:ready() do
  if os.time()-t0 > 90 then session:hangup("NO_ANSWER"); return end
  local dest = ready_ext(skip)
  if not dest then
    freeswitch.consoleLog("NOTICE", "skykin inbound wait " .. queue .. "\n")
    if not play_wav(wait_wav, "skykin wait") then session:sleep(500) end
  else
    freeswitch.consoleLog("NOTICE", "skykin inbound try " .. dest .. "@" .. domain .. "\n")
    session:execute("bridge",
      "{ignore_early_media=true,bridge_early_media=false,originate_timeout=45,fail_on_single_reject=true}"
      .. "[leg_timeout=30,media_webrtc=true,rtp_secure_media=optional,rtp_advertise_ip="
      .. rtp_ip .. ",include_external_ip=true,execute_on_hangup=lua::/etc/freeswitch/scripts/skykin_cc_drop.lua]user/"
      .. dest .. "@" .. domain)
    if not session:ready() then return end
    local ok, ans = pcall(function() return session:answered() end)
    if ok and ans then return end
    local sip = session:getVariable("sip_invite_failure_status") or ""
    local cause = string.upper(session:getVariable("last_bridge_hangup_cause") or "")
    if sip=="603" or sip=="480" or cause:find("CALL_REJECTED",1,true) or cause:find("ORIGINATOR_CANCEL",1,true) then
      session:hangup("NORMAL_CLEARING"); return
    end
    skip[dest]=true; session:sleep(200)
  end
end
LUA

echo "=== 4) Persist (survive container recreate) ==="
docker cp skykin-freeswitch:/etc/freeswitch/dialplan/public/02_skykin_did_ahununu.xml "$LIVE/"
docker cp skykin-freeswitch:/etc/freeswitch/scripts/skykin_inbound.lua "$LIVE/"

FS "reloadxml"

echo "=== 5) Verify dialplan (must NOT show transfer 500) ==="
docker exec skykin-freeswitch grep -E 'skykin_inbound|transfer' /etc/freeswitch/dialplan/public/02_skykin_did_ahununu.xml

echo "=== 6) Dashboard (optional — copy index.php separately if needed) ==="
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
echo "  3) Call 8414 — lookup tab + Answer/Decline bar bottom-right"
echo "  4) grep 'skykin inbound try' /var/log/freeswitch/freeswitch.log | tail -3"
