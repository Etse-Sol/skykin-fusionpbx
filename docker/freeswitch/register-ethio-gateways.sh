#!/bin/sh
# Ethio SBC at 10.208.233.134 returns 404 to SIP REGISTER — this is an
# IP trunk (NOREG), not a registrar. Do not set register=true.
# This script is kept as a reference only.
set -eu

FS="${FREESWITCH_CONTAINER:-skykin-freeswitch}"
DB="${DB_CONTAINER:-skykin-db}"
PROXY="${TRUNK_PROXY:-10.208.233.134:5080}"
EXT_IP="${EXT_SIP_IP:-10.0.0.93}"

docker exec -i "$DB" psql -U fusionpbx -d fusionpbx -v ON_ERROR_STOP=1 <<'SQL'
UPDATE v_gateways
   SET register = true,
       enabled = true,
       proxy = CASE
         WHEN proxy IS NULL OR proxy = '' OR proxy = '10.208.233.134'
           THEN '10.208.233.134:5080'
         ELSE proxy
       END
 WHERE gateway IN ('SIP', 'SIP759');
SQL

# gateway|username|password|realm|from_user|from_domain|auth_username|register_transport
ROWS=$(docker exec -i "$DB" psql -U fusionpbx -d fusionpbx -At -F '|' -c \
"SELECT gateway,
        COALESCE(username,''),
        COALESCE(password,''),
        COALESCE(NULLIF(realm,''), 'ims.ethiotelecom.com'),
        COALESCE(NULLIF(from_user,''), username),
        COALESCE(NULLIF(from_domain,''), NULLIF(realm,''), 'ims.ethiotelecom.com'),
        COALESCE(NULLIF(auth_username,''), username),
        COALESCE(NULLIF(register_transport,''), 'udp')
   FROM v_gateways
  WHERE gateway IN ('SIP','SIP759')
  ORDER BY gateway")

if [ -z "$ROWS" ]; then
  echo "ERROR: v_gateways has no SIP / SIP759 rows" >&2
  exit 1
fi

export FS PROXY
export ROWS
python3 <<'PY'
import os, subprocess, xml.sax.saxutils as x, sys
fs, proxy, rows = os.environ["FS"], os.environ["PROXY"], os.environ["ROWS"]
files = {"SIP": "ethio.xml", "SIP759": "ethio759.xml"}
written = []
for line in rows.splitlines():
    line = line.rstrip("\n")
    if not line:
        continue
    parts = line.split("|")
    if len(parts) < 8:
        print("bad row", file=sys.stderr)
        sys.exit(1)
    name, user, pw, realm, from_user, from_domain, auth, transport = parts[:8]
    if not pw:
        print(f"ERROR: gateway {name} has an empty password in v_gateways", file=sys.stderr)
        sys.exit(1)
    xml = f'''<include>
  <gateway name="{x.escape(name)}">
    <param name="username" value="{x.escape(user)}"/>
    <param name="auth-username" value="{x.escape(auth)}"/>
    <param name="password" value="{x.escape(pw)}"/>
    <param name="realm" value="{x.escape(realm)}"/>
    <param name="from-user" value="{x.escape(from_user)}"/>
    <param name="from-domain" value="{x.escape(from_domain)}"/>
    <param name="proxy" value="{x.escape(proxy)}"/>
    <param name="register" value="true"/>
    <param name="register-transport" value="{x.escape(transport)}"/>
    <param name="caller-id-in-from" value="true"/>
    <param name="extension-in-contact" value="true"/>
    <param name="expire-seconds" value="3600"/>
    <param name="retry-seconds" value="30"/>
    <param name="codec-prefs" value="PCMA,PCMU"/>
  </gateway>
</include>
'''
    dest = files.get(name, f"{name}.xml")
    subprocess.run(
        ["docker", "exec", "-i", fs, "tee", f"/etc/freeswitch/sip_profiles/external/{dest}"],
        input=xml.encode(), stdout=subprocess.DEVNULL, check=True,
    )
    written.append(f"{name} ({user}) -> {dest}")
if not written:
    print("ERROR: no SIP/SIP759 rows written", file=sys.stderr)
    sys.exit(1)
print("wrote: " + ", ".join(written))
PY

# Interconnect IP in Contact/SDP (public 196.189.236.140 is ignored by Ethio).
docker exec -i "$FS" fs_cli -x "global_setvar external_rtp_ip=${EXT_IP}"
docker exec -i "$FS" fs_cli -x "global_setvar external_sip_ip=${EXT_IP}"

iptables -t nat -C POSTROUTING -d 10.208.233.134 -j SNAT --to-source "$EXT_IP" 2>/dev/null \
  || iptables -t nat -I POSTROUTING -d 10.208.233.134 -j SNAT --to-source "$EXT_IP"

docker exec -i "$FS" fs_cli -x 'sofia profile external killgw SIP' || true
docker exec -i "$FS" fs_cli -x 'sofia profile external killgw SIP759' || true
docker exec -i "$FS" fs_cli -x 'sofia profile external restart'
sleep 3
docker exec -i "$FS" fs_cli -x 'sofia profile external rescan'
sleep 5
echo "=== expect SIP and SIP759 State REGED Status UP ==="
docker exec -i "$FS" fs_cli -x 'sofia status gateway'
