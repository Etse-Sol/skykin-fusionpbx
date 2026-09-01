-- Direct-bridge Ready agents. 180 only — never callcenter (that 183s Ethio).
-- 603 Decline ends this customer. 486/timeout can try the next Ready agent.
if not session then
  return
end

local api = freeswitch.API()
local domain = session:getVariable("domain_name") or "client1.skykin.local"
local queue = "8000@" .. domain
local rtp_ip = session:getVariable("rtp_ext_ip") or "196.189.236.126"
local wait_wav = os.getenv("FS_WAIT_WAV") or os.getenv("FS_INBOUND_WAIT_WAV")
  or "/var/lib/freeswitch/recordings/" .. domain .. "/ahununu-waiting.wav"

if session:getVariable("skykin_blocked") == "true" or not session:ready() then
  session:execute("hangup", "CALL_REJECTED")
  return
end

session:execute("ring_ready")
session:setVariable("ringback", "${us-ring}")
session:setVariable("instant_ringback", "true")
session:setVariable("hangup_after_bridge", "true")
session:setVariable("continue_on_fail", "true")
session:setVariable("ignore_early_media", "true")
session:setVariable("bridge_early_media", "false")
session:setVariable("originate_early_media", "false")

-- Welcome after 180 ring_ready (keeps agent hunt working on Ethio IMS).
-- Optional: FS_WELCOME_WAV=/full/path.wav  FS_USE_HOURS=1 + FS_CLOSED_WAV for after-hours.
local function play_wav(path, tag)
  if path == "" then return false end
  local f = io.open(path, "r")
  if not f then return false end
  f:close()
  local ok, ans = pcall(function() return session:answered() end)
  if not (ok and ans) then session:execute("pre_answer") end
  freeswitch.consoleLog("NOTICE", tag .. " play " .. path .. "\n")
  session:streamFile(path)
  return true
end
local function mins_now()
  local t = os.date("*t")
  return t.hour * 60 + t.min, t.wday
end
if os.getenv("FS_USE_HOURS") == "1" then
  local mins, wday = mins_now()
  local open, close = 8 * 60, 19 * 60 + 20
  local weekday = wday >= 2 and wday <= 6
  if not weekday or mins < open or mins >= close then
    play_wav(os.getenv("FS_CLOSED_WAV") or "", "skykin closed")
    freeswitch.consoleLog("NOTICE", "skykin inbound after hours\n")
    session:hangup("NORMAL_CLEARING")
    return
  end
end
local welcome = os.getenv("FS_WELCOME_WAV")
  or "/var/lib/freeswitch/recordings/" .. domain .. "/ahununu-opening.wav"
play_wav(welcome, "skykin welcome")

local function registered(ext)
  local r = api:execute("sofia_contact", "*/" .. ext .. "@" .. domain) or ""
  if r:find("error", 1, true) or r:find("user_not_registered", 1, true) then
    return false
  end
  return r:find("sip:", 1, true) ~= nil
end

local function cols(line)
  local c = {}
  for x in (line .. "|"):gmatch("(.-)|") do c[#c + 1] = x end
  return c
end

local function on_a_call(ext)
  local chans = api:execute("show", "channels like user/" .. ext .. "@" .. domain) or ""
  if chans:find("total", 1, true) then
    local total = tonumber(chans:match("(%d+) total")) or 0
    return total > 0
  end
  return chans:find("user/" .. ext .. "@" .. domain, 1, true) ~= nil
end

local function agent_uuid_for(ext)
  local out = api:execute("callcenter_config", "queue list agents " .. queue) or ""
  for line in out:gmatch("[^\r\n]+") do
    if line:find("|", 1, true) and line:sub(1, 5) ~= "name|" then
      local c = cols(line)
      local e = (c[5] or ""):match("user/([^@]+)")
      if e == ext then return c[1] or "" end
    end
  end
  return ""
end

local function release_agent(ext)
  local uuid = agent_uuid_for(ext)
  if uuid ~= "" then
    api:execute("callcenter_config", "agent set state " .. uuid .. " Waiting")
  end
end

-- Smaller ready_time epoch = idle longer. Do not reset ready_time in cc_prune.
local function idle_rank(c)
  local rt = tonumber(c[20]) or 0
  if rt > 0 then return rt end
  local lsc = tonumber(c[16]) or 0
  if lsc > 0 then return lsc end
  return os.time()
end

local function ready_ext(skip)
  local out = api:execute("callcenter_config", "queue list agents " .. queue) or ""
  local best, best_idle = nil, nil
  for line in out:gmatch("[^\r\n]+") do
    if line:find("|", 1, true) and line:sub(1, 5) ~= "name|" then
      local c = cols(line)
      local ext = (c[5] or ""):match("user/([^@]+)")
      local status, state = c[6] or "", c[7] or ""
      if ext and not skip[ext]
          and status == "Available" and (state == "Waiting" or state == "Idle")
          and registered(ext) and not on_a_call(ext) then
        local idle = idle_rank(c)
        if not best or idle < best_idle then
          best, best_idle = ext, idle
        end
      end
    end
  end
  if best then
    freeswitch.consoleLog("NOTICE", "skykin inbound pick " .. best .. "@" .. domain
      .. " ready_epoch=" .. tostring(best_idle) .. "\n")
  else
    freeswitch.consoleLog("NOTICE", "skykin inbound no ready agent for " .. queue .. "\n")
  end
  return best
end

local skip = {}
local t0 = os.time()
while session:ready() do
  if os.time() - t0 > 90 then
    session:hangup("NO_ANSWER")
    return
  end
  local dest = ready_ext(skip)
  if not dest then
    freeswitch.consoleLog("NOTICE", "skykin inbound wait " .. queue .. "\n")
    if wait_wav ~= "" and session:ready() then
      if not play_wav(wait_wav, "skykin wait") then
        session:sleep(500)
      end
    else
      session:sleep(500)
    end
  else
    local bridge =
      "{ignore_early_media=true,bridge_early_media=false,originate_timeout=45,fail_on_single_reject=true}" ..
      "[leg_timeout=30,media_webrtc=true,rtp_secure_media=optional,rtp_advertise_ip=" ..
      rtp_ip .. ",include_external_ip=true," ..
      "execute_on_hangup=lua::/etc/freeswitch/scripts/skykin_cc_drop.lua]user/" ..
      dest .. "@" .. domain
    freeswitch.consoleLog("NOTICE", "skykin inbound try " .. dest .. "@" .. domain .. "\n")
    session:execute("bridge", bridge)
    if not session:ready() then
      return
    end
    local ok, answered = pcall(function() return session:answered() end)
    if ok and answered then
      return
    end
    local cause = string.upper(session:getVariable("last_bridge_hangup_cause")
        or session:getVariable("originate_disposition") or "")
    local sip = session:getVariable("sip_invite_failure_status") or ""
    freeswitch.consoleLog("NOTICE", "skykin inbound cause=" .. cause
      .. " sip=" .. sip .. " dest=" .. dest .. "\n")
    if sip == "603" or sip == "480" or cause:find("CALL_REJECTED", 1, true)
        or cause:find("ORIGINATOR_CANCEL", 1, true) then
      freeswitch.consoleLog("NOTICE", "skykin inbound decline drop dest=" .. dest .. "\n")
      release_agent(dest)
      session:hangup("NORMAL_CLEARING")
      return
    end
    release_agent(dest)
    skip[dest] = true
    session:sleep(200)
  end
end
