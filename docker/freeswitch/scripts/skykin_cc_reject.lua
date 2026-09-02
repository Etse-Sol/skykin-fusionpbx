-- Drop the queued caller when an agent Declines (603 / CALL_REJECTED / BUSY).
-- Do not drop the caller on no-answer so the next Ready agent can ring.
-- Bound from lua.conf.xml: CUSTOM / callcenter::info
if not event then
  return
end

local api = freeswitch.API()
local action = event:getHeader("CC-Action") or ""
local cause = string.upper(event:getHeader("CC-Cause") or "")
local member = event:getHeader("CC-Member-Session-UUID") or ""
local agent = event:getHeader("CC-Agent") or ""

if action ~= "agent-fail" then
  return
end

freeswitch.consoleLog("NOTICE", "skykin cc agent-fail agent=" .. agent
  .. " cause=" .. cause .. " member=" .. member .. "\n")

if cause:find("NO_ANSWER", 1, true)
    or cause:find("NO ANSWER", 1, true)
    or cause:find("ALLOTTED", 1, true)
    or cause:find("TIMEOUT", 1, true)
    or cause:find("NO_USER_RESPONSE", 1, true) then
  return
end

if cause:find("REJECT", 1, true)
    or cause:find("BUSY", 1, true)
    or cause:find("CANCEL", 1, true)
    or cause:find("603", 1, true) then
  if member ~= "" then
    freeswitch.consoleLog("NOTICE", "skykin decline hangup member=" .. member .. " cause=" .. cause .. "\n")
    api:execute("uuid_kill", member .. " CALL_REJECTED")
  end
end
