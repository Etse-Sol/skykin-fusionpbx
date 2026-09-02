-- Load domain-scoped blacklist hash keys from the shared file on FreeSWITCH start.
-- File format: domain|digits|display|reason|agent|unix
local api = freeswitch.API()

local function digits(s)
  s = (s or ""):gsub("%D", "")
  if s:sub(1, 3) == "251" and #s >= 12 then s = s:sub(4) end
  if #s == 10 and s:sub(1, 1) == "0" then s = s:sub(2) end
  return s
end

local n = 0
local function load_path(path)
  local f = io.open(path, "r")
  if not f then return end
  for line in f:lines() do
    if line:sub(1, 1) ~= "#" and line ~= "" then
      local domain, num = line:match("^([^|]+)|([^|]+)")
      domain = (domain or ""):gsub("[/%s|~]", "")
      local want = digits(num)
      if domain ~= "" and #want >= 7 then
        local keys = { want, want:sub(-9), want:sub(-8), want:sub(-7), "251" .. want, "0" .. want }
        for _, key in ipairs(keys) do
          if #key >= 7 then
            api:execute("hash", "insert/skykin_bl/" .. domain .. "~" .. key .. "/1")
          end
        end
        n = n + 1
      end
    end
  end
  f:close()
end

load_path("/var/lib/freeswitch/recordings/skykin_blacklist.txt")
load_path("/etc/freeswitch/scripts/skykin_blacklist.txt")
freeswitch.consoleLog("NOTICE", "skykin bl hash loaded rows=" .. n .. "\n")
