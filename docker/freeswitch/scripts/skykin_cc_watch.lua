-- Decline drops the customer call (BYE). Not Busy, not re-queue.
-- Ring timeout still goes to the next Ready agent.
local api = freeswitch.API()
local con = freeswitch.EventConsumer("CUSTOM", "callcenter::info")
freeswitch.consoleLog("NOTICE", "skykin cc watch started\n")

local function skip_cause(cause)
  cause = string.upper(cause or "")
  return cause:find("NO_ANSWER", 1, true) or cause:find("NO ANSWER", 1, true)
      or cause:find("ALLOTTED", 1, true)
      or cause:find("USER_NOT_REGISTERED", 1, true)
end

local function drop_caller(id)
  if not id or id == "" then return end
  freeswitch.consoleLog("NOTICE", "skykin decline drop member=" .. id .. "\n")
  api:execute("uuid_kill", id .. " NORMAL_CLEARING")
end

while true do
  local e = con:pop(1)
  if e then
    local action = e:getHeader("CC-Action") or ""
    local cause = e:getHeader("CC-Hangup-Cause") or e:getHeader("CC-Cause") or ""
    if (action == "agent-fail" or action == "bridge-agent-fail") and not skip_cause(cause) then
      freeswitch.consoleLog("NOTICE", "skykin cc " .. action .. " cause=" .. cause .. "\n")
      drop_caller(e:getHeader("CC-Member-Session-UUID"))
      local q = e:getHeader("CC-Queue") or ""
      if q ~= "" then
        local list = api:execute("callcenter_config", "queue list members " .. q) or ""
        for line in list:gmatch("[^\r\n]+") do
          if line:find("|", 1, true) and not line:find("session_uuid", 1, true) then
            local cols = {}
            for col in (line .. "|"):gmatch("(.-)|") do cols[#cols + 1] = col end
            if cols[4] and cols[4] ~= "" then drop_caller(cols[4]) end
          end
        end
      end
    end
  end
end
