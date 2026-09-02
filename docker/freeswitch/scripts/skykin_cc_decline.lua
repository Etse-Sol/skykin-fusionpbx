-- Hang up the queued caller when an agent presses Decline / End call (603).
-- Do not kill the caller on no-answer timeout so the next Ready agent can ring.
local api = freeswitch.API()

local function var(uuid, name)
  if not uuid or uuid == "" then
    return ""
  end
  local v = api:execute("uuid_getvar", uuid .. " " .. name) or ""
  v = v:gsub("%s+$", "")
  if v == "" or v:find("-ERR", 1, true) or v == "_undef_" then
    return ""
  end
  return v
end

local uuid = argv[1] or ""
if uuid == "" and session then
  uuid = session:get_uuid() or ""
end
local cause = string.upper(argv[2] or var(uuid, "hangup_cause") or "")
-- 603 Decline / Hangup while ringing. Not NO_ANSWER (next agent must still ring).
if not cause:find("CALL_REJECTED", 1, true)
    and not cause:find("USER_BUSY", 1, true)
    and not cause:find("ORIGINATOR_CANCEL", 1, true) then
  freeswitch.consoleLog("NOTICE", "skykin decline skip uuid=" .. uuid .. " cause=" .. cause .. "\n")
  return
end

local member = argv[3] or ""
if member == "" then
  member = var(uuid, "cc_member_session_uuid")
end
if member == "" then
  member = var(uuid, "signal_bond")
end
if member == "" then
  member = var(uuid, "last_bridge_to")
end
if member == "" then
  member = var(uuid, "cc_member_uuid")
end
if member == "" then
  freeswitch.consoleLog("NOTICE", "skykin decline no-member uuid=" .. uuid .. " cause=" .. cause .. "\n")
  return
end

freeswitch.consoleLog("NOTICE", "skykin decline hangup member=" .. member .. " cause=" .. cause .. "\n")
api:execute("uuid_kill", member .. " CALL_REJECTED")
