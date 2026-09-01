-- Outbound to Ethio: do not answer the agent before the mobile answers.
-- When the mobile Declines/Busy, always hang up the agent leg.
if not session then
  return
end

local gw = argv[1] or "SIP8035"
local dest = argv[2] or ""
local cid = argv[3] or dest
local lan = argv[4] or "10.0.0.77"

if dest == "" then
  freeswitch.consoleLog("ERR", "skykin_outbound: empty dest\n")
  session:hangup("NO_ROUTE_DESTINATION")
  return
end

freeswitch.consoleLog("NOTICE", "skykin_outbound gw=" .. gw .. " dest=" .. dest .. " cid=" .. cid .. "\n")

session:setVariable("call_direction", "outbound")
session:setVariable("hangup_after_bridge", "true")
session:setVariable("continue_on_fail", "false")
session:setVariable("call_timeout", "60")
session:setVariable("ringback", "${us-ring}")
session:setVariable("instant_ringback", "true")
session:setVariable("ignore_early_media", "true")

-- Ringback toward agent without answering (no pre_answer / answer).
local bridge = string.format(
  "{ignore_early_media=true,originate_timeout=60,absolute_codec_string=^^:PCMA:PCMU,rtcp=-1," ..
  "rtp_secure_media=false,media_webrtc=false,rtp_advertise_ip=%s,include_external_ip=false," ..
  "origination_caller_id_number=%s,origination_caller_id_name=%s}sofia/gateway/%s/%s",
  lan, cid, cid, gw, dest
)

session:execute("bridge", bridge)

-- If we are still here, the B-leg failed (Decline/Busy/timeout) or already ended.
if session:ready() then
  freeswitch.consoleLog("NOTICE", "skykin_outbound hangup agent after bridge return\n")
  session:hangup("CALL_REJECTED")
end
