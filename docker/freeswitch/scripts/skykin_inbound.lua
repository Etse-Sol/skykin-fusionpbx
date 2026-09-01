-- Ring the longest-idle Ready registered agent who is not already on a call.
-- Decline ends this customer call (does not roll to the next agent).
-- If every Ready agent is busy, park in callcenter so the caller waits.
if not session then
  return
end

local api = freeswitch.API()
local domain = session:getVariable("domain_name") or "client1.skykin.local"
local cid = session:getVariable("caller_id_number")
    or session:getVariable("ani")
    or session:getVariable("sip_from_user")
    or session:getVariable("effective_caller_id_number")
    or session:getVariable("sip_p_asserted_identity")
    or session:getVariable("sip_cid_num")
    or ""
if session:getVariable("skykin_blocked") == "true" or not session:ready() then
  session:execute("hangup", "CALL_REJECTED")
  return
end
local function bl_digits(s)
  s = (s or ""):gsub("%D", "")
  if s:sub(1, 3) == "251" and #s >= 12 then s = s:sub(4) end
  if #s == 10 and s:sub(1, 1) == "0" then s = s:sub(2) end
  return s
end
local function bl_file_hit(want, path)
  if domain == "" then return false end
  local f = io.open(path, "r")
  if not f then return false end
  for line in f:lines() do
    if line:sub(1, 1) ~= "#" and line ~= "" then
      local a, b = line:match("^([^|]+)|([^|]+)")
      if a == domain then
        local n = bl_digits(b or "")
        if n ~= "" then
          local k = math.min(#want, #n, 12)
          if k >= 7 and want:sub(-k) == n:sub(-k) then
            f:close()
            return true
          end
        end
      end
    end
  end
  f:close()
  return false
end
local function blacklisted()
  local want = bl_digits(cid)
  if #want < 7 or domain == "" then return false end
  local keys = { want, want:sub(-9), want:sub(-8), want:sub(-7), "251" .. want, "0" .. want }
  for _, key in ipairs(keys) do
    if #key >= 7 then
      local h = api:execute("hash", "select/skykin_bl/" .. domain .. "~" .. key) or ""
      h = h:gsub("%s+$", "")
      if h == "1" or h:match("^1%s") then
        return true
      end
    end
  end
  return bl_file_hit(want, "/etc/freeswitch/scripts/skykin_blacklist.txt")
      or bl_file_hit(want, "/var/lib/freeswitch/recordings/skykin_blacklist.txt")
end
if blacklisted() then
  freeswitch.consoleLog("NOTICE", "skykin blacklist drop cid=" .. cid .. "\n")
  session:setVariable("continue_on_fail", "false")
  session:setVariable("skykin_blocked", "true")
  session:execute("hangup", "CALL_REJECTED")
  error("skykin blocked")
end

local queue = "8000@" .. domain
local rtp_ip = "196.189.236.140"

session:execute("ring_ready")
session:setVariable("hangup_after_bridge", "true")
session:setVariable("continue_on_fail", "true")
session:setVariable("ignore_early_media", "true")
session:setVariable("bridge_early_media", "false")

local function cols(line)
  local c = {}
  for x in (line .. "|"):gmatch("(.-)|") do c[#c + 1] = x end
  return c
end

local function registered(ext)
  local r = api:execute("sofia_contact", "*/" .. ext .. "@" .. domain) or ""
  if r:find("error", 1, true) or r:find("user_not_registered", 1, true) then
    return false
  end
  return r:find("sip:", 1, true) ~= nil
end

local function on_a_call(ext)
  local chans = api:execute("show", "channels") or ""
  return chans:find("user/" .. ext .. "@" .. domain, 1, true) ~= nil
      or chans:find("/" .. ext .. "@" .. domain, 1, true) ~= nil
end

local function ready_agents(skip)
  local out = api:execute("callcenter_config", "queue list agents " .. queue) or ""
  local rows = {}
  for line in out:gmatch("[^\r\n]+") do
    if line:find("|", 1, true) and line:sub(1, 5) ~= "name|" then
      local c = cols(line)
      local ext = (c[5] or ""):match("user/([^@]+)")
      local status, state = c[6] or "", c[7] or ""
      if ext and not skip[ext]
          and status == "Available" and (state == "Waiting" or state == "Idle")
          and registered(ext) and not on_a_call(ext) then
        rows[#rows + 1] = { ext = ext, idle = tonumber(c[20]) or tonumber(c[14]) or 0 }
      end
    end
  end
  table.sort(rows, function(a, b) return a.idle < b.idle end)
  return rows
end

local skip = {}
while session:ready() do
  local agents = ready_agents(skip)
  local dest = agents[1] and agents[1].ext
  if not dest then
    freeswitch.consoleLog("NOTICE", "skykin inbound queue wait " .. queue .. "\n")
    session:execute("callcenter", queue)
    return
  end
  local bridge =
    "{ignore_early_media=true,bridge_early_media=false,originate_timeout=45}" ..
    "[leg_timeout=30,media_webrtc=true,rtp_secure_media=optional,rtp_advertise_ip=" ..
    rtp_ip .. ",include_external_ip=true]user/" .. dest .. "@" .. domain
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
  if sip == "486" or cause:find("USER_BUSY", 1, true)
      or cause:find("NO_ANSWER", 1, true) or cause:find("ALLOTTED", 1, true)
      or cause:find("USER_NOT_REGISTERED", 1, true) or sip == "408" then
    skip[dest] = true
    session:sleep(200)
  else
    freeswitch.consoleLog("NOTICE", "skykin inbound decline drop dest=" .. dest .. "\n")
    session:hangup("NORMAL_CLEARING")
    return
  end
end
