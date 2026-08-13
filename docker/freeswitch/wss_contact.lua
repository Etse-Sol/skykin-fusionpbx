-- Return the sofia_contact that is a WebSocket registration.
-- Skip sip:ext@<stun-ip>:<ice-port>;ob (that 488s INCOMPATIBLE_DESTINATION).
-- Live: 101 was also registered as MicroSIP/3.22.12 UDP, which stole the AOR.
local api = freeswitch.API()
local user = argv[1] or ""
local domain = argv[2] or "client1.skykin.local"
if user == "" then
  stream:write("error/user_not_specified")
  return
end

local function contacts_for(spec)
  local raw = api:execute("sofia_contact", spec) or ""
  return raw:gsub("%s+$", "")
end

local raw = contacts_for("internal/" .. user .. "@" .. domain)
if raw == "" or raw:find("error/", 1, true) then
  raw = contacts_for("*/" .. user .. "@" .. domain)
end

local best
for part in string.gmatch(raw .. ",", "([^,]+)") do
  part = part:gsub("^%s+", ""):gsub("%s+$", "")
  if part ~= "" and (
      part:find("fs_path", 1, true)
      or part:find("transport=wss", 1, true)
      or part:find("transport=ws", 1, true)
      or part:find(".invalid", 1, true)
    ) then
    best = part
    break
  end
end

if not best then
  freeswitch.consoleLog("WARNING", "wss_contact: no WSS registration for " .. user .. "@" .. domain .. " raw=" .. tostring(raw) .. "\n")
  stream:write("error/user_not_registered")
  return
end
stream:write(best)
