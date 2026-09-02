-- Sequential hunt: 101 then 102. Decline/busy/no-answer moves forward
-- once. Never wrap back to an agent who already declined or timed out.
if not session then
  return
end

local api = freeswitch.API()
local domain = session:getVariable("domain_name") or "client1.skykin.local"
local rtp_ip = session:getVariable("rtp_ext_ip") or "196.189.236.140"

session:execute("ring_ready")
session:setVariable("ignore_early_media", "true")
session:setVariable("bridge_early_media", "false")
session:setVariable("hangup_after_bridge", "true")
session:setVariable("continue_on_fail", "USER_BUSY,USER_NOT_REGISTERED")
session:setVariable("originate_timeout", "30")
session:setVariable("call_timeout", "30")

local function registered(ext)
  local r = api:execute("sofia_contact", "*/" .. ext .. "@" .. domain) or ""
  if r:find("error", 1, true) or r:find("user_not_registered", 1, true) then
    return false
  end
  return r:find("sip:", 1, true) ~= nil or r:find("sofia/", 1, true) ~= nil
end

local function bridge_one(ext)
  local dest =
    "{ignore_early_media=true,bridge_early_media=false,originate_timeout=30}" ..
    "[leg_timeout=30,media_webrtc=true,rtp_secure_media=optional,rtp_advertise_ip=" ..
    rtp_ip .. ",include_external_ip=true]user/" .. ext .. "@" .. domain
  freeswitch.consoleLog("NOTICE", "skykin_inbound_queue try " .. ext .. "\n")
  session:execute("bridge", dest)
end

for _, ext in ipairs({"101", "102"}) do
  if not session:ready() then
    break
  end
  local ok, answered = pcall(function() return session:answered() end)
  if ok and answered then
    break
  end
  if registered(ext) then
    bridge_one(ext)
  end
end
