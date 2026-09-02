-- Drop a blacklisted inbound CID before ring_ready / agent originate.
-- Matches only the inbound domain (ahununu vs client1 are separate lists).
if not session then
  return
end

local api = freeswitch.API()
local domain = session:getVariable("domain_name") or ""
local cid = session:getVariable("caller_id_number")
  or session:getVariable("ani")
  or session:getVariable("sip_from_user")
  or session:getVariable("effective_caller_id_number")
  or session:getVariable("sip_p_asserted_identity")
  or session:getVariable("sip_cid_num")
  or ""

local function digits(s)
  s = (s or ""):gsub("%D", "")
  if s:sub(1, 3) == "251" and #s >= 12 then s = s:sub(4) end
  if #s == 10 and s:sub(1, 1) == "0" then s = s:sub(2) end
  return s
end

local want = digits(cid)
freeswitch.consoleLog("NOTICE", "skykin bl gate cid=" .. tostring(cid)
  .. " want=" .. want .. " domain=" .. domain .. "\n")

local function file_hit(path)
  if domain == "" then return false end
  local f = io.open(path, "r")
  if not f then return false end
  for line in f:lines() do
    if line:sub(1, 1) ~= "#" and line ~= "" then
      local a, b = line:match("^([^|]+)|([^|]+)")
      if a == domain then
        local n = digits(b or "")
        local k = math.min(#want, #n, 12)
        if n ~= "" and k >= 7 and want:sub(-k) == n:sub(-k) then
          f:close()
          return true
        end
      end
    end
  end
  f:close()
  return false
end

local function blocked()
  if #want < 7 or domain == "" then return false end
  local keys = { want, want:sub(-9), want:sub(-8), want:sub(-7), "251" .. want, "0" .. want }
  for _, key in ipairs(keys) do
    if #key >= 7 then
      local h = (api:execute("hash", "select/skykin_bl/" .. domain .. "~" .. key) or ""):gsub("%s+$", "")
      if h == "1" or h:match("^1%s") then
        freeswitch.consoleLog("NOTICE", "skykin bl gate hash key=" .. domain .. "~" .. key .. "\n")
        return true
      end
    end
  end
  return file_hit("/var/lib/freeswitch/recordings/skykin_blacklist.txt")
      or file_hit("/etc/freeswitch/scripts/skykin_blacklist.txt")
end

if blocked() then
  freeswitch.consoleLog("NOTICE", "skykin blacklist drop cid=" .. tostring(cid)
    .. " domain=" .. domain .. "\n")
  session:setVariable("continue_on_fail", "false")
  session:setVariable("skykin_blocked", "true")
  session:execute("hangup", "CALL_REJECTED")
  error("skykin blocked")
end
