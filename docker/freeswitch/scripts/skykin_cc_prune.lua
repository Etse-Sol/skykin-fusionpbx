-- Before inbound hunt: drop unregistered agents; put Available agents in Waiting.
-- Do NOT reset ready_time (that breaks longest-idle selection).
if not session then
  return
end

local api = freeswitch.API()
local queue = argv[1]
if not queue or queue == "" then
  queue = "8000@" .. (session:getVariable("domain_name") or "ahununu")
end
local domain = queue:match("@(.+)$") or "ahununu"

local function cols(line)
  local c = {}
  for x in (line .. "|"):gmatch("(.-)|") do
    c[#c + 1] = x
  end
  return c
end

local function registered(ext)
  local r = api:execute("sofia_contact", "*/" .. ext .. "@" .. domain) or ""
  if r:find("error", 1, true) or r:find("user_not_registered", 1, true) then
    return false
  end
  return r:find("sip:", 1, true) ~= nil
end

local out = api:execute("callcenter_config", "queue list agents " .. queue) or ""
for line in out:gmatch("[^\r\n]+") do
  if line:find("|", 1, true) and line:sub(1, 5) ~= "name|" then
    local c = cols(line)
    local uuid, contact, status, state = c[1] or "", c[5] or "", c[6] or "", c[7] or ""
    local ext = contact:match("user/([^@]+)")
    if uuid ~= "" and ext then
      if registered(ext) then
        if status == "Available" and state ~= "In a queue call" then
          api:execute("callcenter_config", "agent set state " .. uuid .. " Waiting")
        end
        freeswitch.consoleLog("NOTICE", "skykin_cc_prune keep " .. ext .. "@" .. domain
          .. " status=" .. status .. " state=" .. state .. "\n")
      else
        api:execute("callcenter_config", "agent set status " .. uuid .. " Logged Out")
        freeswitch.consoleLog("NOTICE", "skykin_cc_prune skip " .. ext .. " not registered\n")
      end
    end
  end
end
