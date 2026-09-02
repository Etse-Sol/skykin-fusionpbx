-- Agent declined (ringing leg never answered). Hang up the Ethio caller too.
-- End call after Answer is unchanged: this script returns if the agent had answered.
local api = freeswitch.API()

local uuid = argv[1] or ""
if uuid == "" and session then
  uuid = session:get_uuid() or ""
end

if session then
  local ok, answered = pcall(function() return session:answered() end)
  if ok and answered then
    return
  end
end

local function var(name)
  if session then
    local v = session:getVariable(name)
    if v and v ~= "" and v ~= "_undef_" then return v end
  end
  if uuid == "" then return "" end
  local v = api:execute("uuid_getvar", uuid .. " " .. name) or ""
  v = v:gsub("%s+$", "")
  if v == "" or v:find("-ERR", 1, true) or v == "_undef_" then return "" end
  return v
end

local function kill(id, why)
  if not id or id == "" or id == uuid then return end
  freeswitch.consoleLog("NOTICE", "skykin kill " .. why .. "=" .. id .. "\n")
  -- NORMAL_CLEARING sends BYE if Ethio already started the talk timer.
  api:execute("uuid_kill", id .. " NORMAL_CLEARING")
end

local member = var("cc_member_session_uuid")
if member == "" then member = var("originating_leg_uuid") end
if member == "" then member = var("signal_bond") end
if member == "" then member = var("last_bridge_to") end
kill(member, "member")

local cid = var("cc_member_cid_number")
if cid == "" then cid = var("caller_id_number") end
if cid == "" then cid = var("origination_caller_id_number") end
if cid == "" then cid = var("sip_from_user") end

if cid ~= "" then
  freeswitch.consoleLog("NOTICE", "skykin kill hupall cid=" .. cid .. "\n")
  api:execute("hupall", "NORMAL_CLEARING caller_id_number " .. cid)
  -- Ethio sometimes stores the number with/without +
  if cid:sub(1, 1) ~= "+" then
    api:execute("hupall", "NORMAL_CLEARING caller_id_number +" .. cid)
  end
end

if cid ~= "" then
  local chans = api:execute("show", "channels") or ""
  for line in chans:gmatch("[^\r\n]+") do
    if line:find("sofia/external", 1, true) and line:find(cid, 1, true) then
      local aleg = line:match("^([^,]+)")
      if aleg and aleg ~= "uuid" then
        kill(aleg, "external")
      end
    end
  end
end
