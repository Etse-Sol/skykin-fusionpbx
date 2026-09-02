#!/bin/sh
set -eu

ESL_PASSWORD="${ESL_PASSWORD:-ClueCon}"
ESL_LISTEN_IP="${ESL_LISTEN_IP:-0.0.0.0}"
RTP_START="${RTP_START_PORT:-16384}"
RTP_END="${RTP_END_PORT:-16584}"
FS_DOMAIN="${FS_DOMAIN:-}"
EXTERNAL_SIP_IP="${EXTERNAL_SIP_IP:-}"
EXTERNAL_RTP_IP="${EXTERNAL_RTP_IP:-${EXTERNAL_SIP_IP:-}}"
# Comma-separated extension:password pairs, e.g. 100:11112222,101:1234567890,102:0987654321
FS_DIRECTORY_USERS="${FS_DIRECTORY_USERS:-}"
# Call center queue extension (mod_callcenter) and its member agents.
FS_QUEUE_EXT="${FS_QUEUE_EXT:-8000}"
FS_QUEUE_STRATEGY="${FS_QUEUE_STRATEGY:-longest-idle-agent}"
FS_QUEUE_AGENTS="${FS_QUEUE_AGENTS:-}"
# Extra domains (comma-separated) that get queue 8000@domain for waiting / longest-idle.
FS_QUEUE_DOMAINS="${FS_QUEUE_DOMAINS:-}"
# Overrides the vanilla SIP password for the unused 1000-1019 users. Left empty a
# random one is generated; it must not stay 1234 or the stock dialplan sleeps 10s
# on every call. See the vars.xml handling below.
FS_DEFAULT_PASSWORD="${FS_DEFAULT_PASSWORD:-}"
# FusionPBX call detail record importer (drives dashboard call history/recordings).
# Defaults to the compose web service; failed posts spool to err-log-dir and are
# retried, so a wrong host degrades gracefully rather than losing records.
# Host-network FreeSWITCH cannot resolve the compose hostname "web" and a
# 301 from the Docker bridge drops every CDR. Prefer the published HTTP port.
CDR_URL="${CDR_URL:-http://127.0.0.1:8090/app/xml_cdr/xml_cdr_import.php}"
CDR_CRED="${CDR_CRED:-fusionpbx:fusionpbx}"
# FusionPBX database, read directly by the Lua XML handler so extensions created in
# the web UI can register without rebuilding this container. Leave the host empty to
# fall back to the static FS_DIRECTORY_USERS list only.
FUSIONPBX_DB_HOST="${FUSIONPBX_DB_HOST:-}"
FUSIONPBX_DB_PORT="${FUSIONPBX_DB_PORT:-5432}"
FUSIONPBX_DB_NAME="${FUSIONPBX_DB_NAME:-fusionpbx}"
FUSIONPBX_DB_USER="${FUSIONPBX_DB_USER:-fusionpbx}"
FUSIONPBX_DB_PASSWORD="${FUSIONPBX_DB_PASSWORD:-}"
# Where FreeSWITCH looks for Lua scripts ($${script_dir}); the FusionPBX script
# tree is mounted here by docker-compose.
FS_SCRIPTS_DIR="${FS_SCRIPTS_DIR:-/usr/share/freeswitch/scripts}"
# Sound files ($${sounds_dir}); the Dockerfile installs hold music under music/.
FS_SOUNDS_DIR="${FS_SOUNDS_DIR:-/usr/share/freeswitch/sounds}"
# Outbound SIP trunk (Ethio Telecom IP trunk style: register=false, auth by source IP).
# Leave FS_OUTBOUND_PROXY empty to skip writing a gateway file at boot.
FS_OUTBOUND_GATEWAY="${FS_OUTBOUND_GATEWAY:-SIP}"
FS_OUTBOUND_PROXY="${FS_OUTBOUND_PROXY:-}"
FS_OUTBOUND_USERNAME="${FS_OUTBOUND_USERNAME:-}"
FS_OUTBOUND_PASSWORD="${FS_OUTBOUND_PASSWORD:-}"
FS_OUTBOUND_CID="${FS_OUTBOUND_CID:-${FS_OUTBOUND_USERNAME}}"
# Second Ethio identity (DID 756). Agents prefix the number with 756 to use it.
FS_OUTBOUND_GATEWAY2="${FS_OUTBOUND_GATEWAY2:-SIP2}"
FS_OUTBOUND_CID2="${FS_OUTBOUND_CID2:-+251111138756}"
# true only if the carrier requires REGISTER; IP trunks must stay false.
FS_OUTBOUND_REGISTER="${FS_OUTBOUND_REGISTER:-false}"
# IMS / digest extras. Leave empty for a plain IP trunk (proxy-only).
FS_OUTBOUND_REALM="${FS_OUTBOUND_REALM:-}"
FS_OUTBOUND_AUTH_USERNAME="${FS_OUTBOUND_AUTH_USERNAME:-}"
FS_OUTBOUND_FROM_DOMAIN="${FS_OUTBOUND_FROM_DOMAIN:-${FS_OUTBOUND_REALM}}"
FS_OUTBOUND_REGISTER_PROXY="${FS_OUTBOUND_REGISTER_PROXY:-}"
# LAN address the carrier is allowed to send RTP to (host NIC, not Docker IP).
FS_LAN_RTP_IP="${FS_LAN_RTP_IP:-}"
# Inbound DID regex for the public context. Empty skips the inbound file.
FS_INBOUND_DID_REGEX="${FS_INBOUND_DID_REGEX:-}"

# Bootstrap vanilla config (same as safarov/freeswitch docker-entrypoint.sh).
# Our ENTRYPOINT replaces theirs, so we must do this ourselves.
if [ ! -f /etc/freeswitch/freeswitch.xml ]; then
  echo "Bootstrapping FreeSWITCH config from vanilla templates"
  mkdir -p /etc/freeswitch
  cp -a /usr/share/freeswitch/conf/vanilla/. /etc/freeswitch/
fi

mkdir -p \
  /etc/freeswitch/autoload_configs \
  /etc/freeswitch/sip_profiles \
  /etc/freeswitch/directory/default \
  /var/lib/freeswitch/recordings \
  /var/log/freeswitch \
  /var/run/freeswitch \
  /usr/share/freeswitch/scripts \
  /etc/freeswitch/scripts

# Default ESL ACL is loopback.auto (blocks Docker bridge peers).
# rfc1918.auto allows Docker nets but NOT 127.0.0.1 (breaks fs_cli/healthcheck).
# Custom "skykin" list allows loopback + private ranges.
ACL_FILE=/etc/freeswitch/autoload_configs/acl.conf.xml
if [ -f "$ACL_FILE" ] && ! grep -q 'list name="skykin"' "$ACL_FILE"; then
  sed -i 's#</network-lists>#    <list name="skykin" default="deny">\n      <node type="allow" cidr="127.0.0.1/32"/>\n      <node type="allow" cidr="10.0.0.0/8"/>\n      <node type="allow" cidr="172.16.0.0/12"/>\n      <node type="allow" cidr="192.168.0.0/16"/>\n    </list>\n  </network-lists>#' "$ACL_FILE" || true
fi

cat > /etc/freeswitch/autoload_configs/event_socket.conf.xml <<EOF
<configuration name="event_socket.conf" description="Socket Client">
  <settings>
    <param name="nat-map" value="false"/>
    <param name="listen-ip" value="${ESL_LISTEN_IP}"/>
    <param name="listen-port" value="8021"/>
    <param name="password" value="${ESL_PASSWORD}"/>
    <param name="apply-inbound-acl" value="skykin"/>
  </settings>
</configuration>
EOF

if [ -f /etc/freeswitch/autoload_configs/switch.conf.xml ]; then
  SW=/etc/freeswitch/autoload_configs/switch.conf.xml
  sed -i 's/enable-monotonic-timing" value="true"/enable-monotonic-timing" value="false"/g' "$SW" || true
  # Vanilla ships rtp-start/end-port commented out, so FS falls back to its
  # default 16384-32768. Docker only publishes 16384-16584, so any call that
  # lands on a higher port has no audio. Force the active range to match.
  sed -i "s#<!--[[:space:]]*<param name=\"rtp-start-port\"[^>]*/>[[:space:]]*-->#<param name=\"rtp-start-port\" value=\"${RTP_START}\"/>#" "$SW" || true
  sed -i "s#<!--[[:space:]]*<param name=\"rtp-end-port\"[^>]*/>[[:space:]]*-->#<param name=\"rtp-end-port\" value=\"${RTP_END}\"/>#" "$SW" || true
  sed -i "s#<param name=\"rtp-start-port\" value=\"[0-9]*\"/>#<param name=\"rtp-start-port\" value=\"${RTP_START}\"/>#" "$SW" || true
  sed -i "s#<param name=\"rtp-end-port\" value=\"[0-9]*\"/>#<param name=\"rtp-end-port\" value=\"${RTP_END}\"/>#" "$SW" || true
  grep -q 'name="rtp-start-port"' "$SW" || sed -i "s#</settings>#  <param name=\"rtp-start-port\" value=\"${RTP_START}\"/>\n    <param name=\"rtp-end-port\" value=\"${RTP_END}\"/>\n  </settings>#" "$SW" || true
fi

INTERNAL=/etc/freeswitch/sip_profiles/internal.xml
if [ -f "$INTERNAL" ]; then
  if grep -q 'ws-binding' "$INTERNAL"; then
    sed -i "s#<param name=\"ws-binding\".*#<param name=\"ws-binding\" value=\":5066\"/>#" "$INTERNAL" || true
  else
    sed -i "s#</settings>#  <param name=\"ws-binding\" value=\":5066\"/>\n  </settings>#" "$INTERNAL" || true
  fi
  # Agent-to-agent INVITEs fail with 503 when WSS is proxied through nginx
  # (FreeSWITCH tries a new WebSocket to nginx's ephemeral port). TCP 5060 is
  # already reachable from the internet; 7443 is not. Disable SIP TCP so WSS
  # can own 5060/tcp. SIP UDP stays on 5060 for hardphones.
  if grep -q 'name="disable-tcp"' "$INTERNAL"; then
    sed -i 's#<!--[[:space:]]*<param name="disable-tcp"[^>]*/>[[:space:]]*-->#<param name="disable-tcp" value="true"/>#' "$INTERNAL" || true
    sed -i 's#<param name="disable-tcp" value="[^"]*"/>#<param name="disable-tcp" value="true"/>#' "$INTERNAL" || true
  else
    sed -i 's#</settings>#    <param name="disable-tcp" value="true"/>\n  </settings>#' "$INTERNAL" || true
  fi
  # WSS stays on 7443. Host iptables redirects public TCP 5060 -> 7443 so
  # browsers can reach it (cloud SG blocks 7443; 5060 is already open).
  if grep -q 'wss-binding' "$INTERNAL"; then
    sed -i 's#<param name="wss-binding".*#<param name="wss-binding" value="0.0.0.0:7443"/>#' "$INTERNAL" || true
  else
    sed -i 's#</settings>#    <param name="wss-binding" value="0.0.0.0:7443"/>\n  </settings>#' "$INTERNAL" || true
  fi
  # rtp-ip is the socket *bind* address, so it must be an address that exists on
  # a local interface. On NAT'd cloud hosts the public IP is not local, and
  # binding fails ("Bind Error!"), killing every call with
  # INCOMPATIBLE_DESTINATION. The public address belongs in ext-rtp-ip (SDP
  # advertisement) only, so keep the bind on the local IP.
  sed -i 's#name="rtp-ip" value="$${external_rtp_ip}"#name="rtp-ip" value="$${local_ip_v4}"#' "$INTERNAL" || true
  # WebRTC clients reach FreeSWITCH through the nginx reverse proxy, so the WSS
  # connection arrives from an internal Docker IP. FreeSWITCH decides whether to
  # advertise rtp-ip (local) or ext-rtp-ip (public) in the SDP/ICE candidates by
  # matching the peer against local-network-acl: a "local" peer gets the private
  # 172.x address, which browsers on the internet cannot reach, so DTLS/ICE never
  # completes and calls connect with no audio. Forcing local-network-acl=none
  # makes FreeSWITCH treat every peer as external and emit the public ext-rtp-ip
  # srflx candidate. See FreeSWITCH webrtc-sip-wss docs (proxied WSS / BBB case).
  if [ -n "$EXTERNAL_RTP_IP" ]; then
    if grep -q 'name="local-network-acl"' "$INTERNAL"; then
      sed -i 's#name="local-network-acl" value="[^"]*"#name="local-network-acl" value="none"#' "$INTERNAL" || true
    else
      sed -i 's#</settings>#  <param name="local-network-acl" value="none"/>\n  </settings>#' "$INTERNAL" || true
    fi
  fi
  # Vanilla sofia forces every REGISTER onto $${domain} (FS_DOMAIN). That makes
  # 101@client1.skykin.local work, but a second FusionPBX domain (e.g. ahununu)
  # is rewritten to 201@client1.skykin.local and never finds the user.
  for p in force-register-domain force-register-db-domain force-subscription-domain; do
    if grep -q "name=\"$p\"" "$INTERNAL"; then
      sed -i "s#<param name=\"${p}\" value=\"[^\"]*\"/>#<param name=\"${p}\" value=\"\"/>#" "$INTERNAL" || true
    fi
  done
fi

VARS=/etc/freeswitch/vars.xml
if [ -f "$VARS" ]; then
  # The stock default dialplan punishes installs still using the factory SIP
  # password with <action application="sleep" data="10000"/> on *every* call, so
  # each call sat in silence for 10s before the far end even began ringing.
  # Agents are provisioned with their own passwords below, so this value only
  # guards the unused vanilla 1000-1019 users; it just has to differ from 1234.
  if [ -z "$FS_DEFAULT_PASSWORD" ]; then
    FS_DEFAULT_PASSWORD="$(od -An -tx1 -N12 /dev/urandom 2>/dev/null | tr -d ' \n')"
    [ -n "$FS_DEFAULT_PASSWORD" ] || FS_DEFAULT_PASSWORD="skykin-$(date +%s)"
  fi
  sed -i "s#data=\"default_password=[^\"]*\"#data=\"default_password=${FS_DEFAULT_PASSWORD}\"#" "$VARS" || true
  if [ -n "$FS_DOMAIN" ]; then
    # Force SIP directory domain to the FusionPBX / agent domain (not container IP).
    sed -i "s#data=\"domain=\${\${local_ip_v4}}\"#data=\"domain=${FS_DOMAIN}\"#" "$VARS" || true
    sed -i "s#data=\"domain=[^\"]*\"#data=\"domain=${FS_DOMAIN}\"#" "$VARS" || true
    if ! grep -q 'data="domain=' "$VARS"; then
      sed -i "s#</X-PRE-PROCESS>#</X-PRE-PROCESS>\n  <X-PRE-PROCESS cmd=\"set\" data=\"domain=${FS_DOMAIN}\"/>#" "$VARS" || true
    fi
  fi
  if [ -n "$EXTERNAL_SIP_IP" ]; then
    sed -i "s#data=\"external_sip_ip=[^\"]*\"#data=\"external_sip_ip=${EXTERNAL_SIP_IP}\"#" "$VARS" || true
    if ! grep -q 'data="external_sip_ip=' "$VARS"; then
      printf '  <X-PRE-PROCESS cmd="set" data="external_sip_ip=%s"/>\n' "$EXTERNAL_SIP_IP" >> "$VARS"
    else
      # Replace stun-set lines with plain set for Docker/cloud public IP.
      sed -i "s#stun-set\" data=\"external_sip_ip=[^\"]*\"#set\" data=\"external_sip_ip=${EXTERNAL_SIP_IP}\"#" "$VARS" || true
      sed -i "s#cmd=\"stun-set\" data=\"external_sip_ip=[^\"]*\"#cmd=\"set\" data=\"external_sip_ip=${EXTERNAL_SIP_IP}\"#" "$VARS" || true
    fi
  fi
  if [ -n "$EXTERNAL_RTP_IP" ]; then
    sed -i "s#stun-set\" data=\"external_rtp_ip=[^\"]*\"#set\" data=\"external_rtp_ip=${EXTERNAL_RTP_IP}\"#" "$VARS" || true
    sed -i "s#cmd=\"stun-set\" data=\"external_rtp_ip=[^\"]*\"#cmd=\"set\" data=\"external_rtp_ip=${EXTERNAL_RTP_IP}\"#" "$VARS" || true
    sed -i "s#data=\"external_rtp_ip=[^\"]*\"#data=\"external_rtp_ip=${EXTERNAL_RTP_IP}\"#" "$VARS" || true
    if ! grep -q 'data="external_rtp_ip=' "$VARS"; then
      printf '  <X-PRE-PROCESS cmd="set" data="external_rtp_ip=%s"/>\n' "$EXTERNAL_RTP_IP" >> "$VARS"
    fi
  fi
fi

# ── Hold music ───────────────────────────────────────────────────────────────
# mod_callcenter plays hold_music (local_stream://moh) to everyone waiting in a
# queue, and mod_local_stream resolves that per call rate: a browser at 48 kHz
# asks for the "moh/48000" stream, a desk phone for "moh/8000". A stream is only
# registered for directories that actually hold files, so the stock config -
# which points each rate at its own sounds/music/<rate> directory - leaves every
# rate but the one installed unresolvable. A queue member whose hold music will
# not open receives no media at all: the caller hears silence and record_session
# has nothing to write, which is why queue recordings were empty or seconds long
# while direct extension calls recorded in full. Point every rate at the music
# actually present and let FreeSWITCH resample on playback.
MOH_RATE=""
for rate in 8000 16000 32000 48000; do
  if [ -d "$FS_SOUNDS_DIR/music/$rate" ] &&
     [ -n "$(find "$FS_SOUNDS_DIR/music/$rate" -name '*.wav' 2>/dev/null | head -1)" ]; then
    MOH_RATE="$rate"
    break
  fi
done

LOCAL_STREAM=/etc/freeswitch/autoload_configs/local_stream.conf.xml
if [ -n "$MOH_RATE" ] && [ -f "$LOCAL_STREAM" ]; then
  sed -i "s#\(<directory name=\"moh/[0-9]*\" path=\"\)[^\"]*\"#\1\$\${sounds_dir}/music/${MOH_RATE}\"#" \
    "$LOCAL_STREAM" || true
  echo "  Hold music: sounds/music/${MOH_RATE} serving every call rate"
elif [ -f "$VARS" ]; then
  # No music in the image (offline build). A generated tone is not pretty but it
  # keeps media flowing, so queue callers hear something and their calls record.
  sed -i 's#data="hold_music=[^"]*"#data="hold_music=tone_stream://%(10000,0,350,440);loops=-1"#' \
    "$VARS" || true
  echo "  Hold music: no sound files installed, falling back to a generated tone"
fi

# ── FusionPBX-backed SIP directory ───────────────────────────────────────────
# Without this, FreeSWITCH only knows the extensions listed in FS_DIRECTORY_USERS,
# which is a fixed list baked in at container start. An agent created afterwards in
# the FusionPBX web UI has no directory entry, so it can never register no matter
# how correct its credentials look in the admin pages.
#
# FusionPBX's own answer to this is a Lua XML handler that FreeSWITCH consults on
# every lookup and that reads the FusionPBX database directly, so new extensions
# work the moment they are saved. Bind only the "directory" section: binding
# "configuration" as well would also serve sofia.conf/acl.conf from the database
# and discard the WebRTC and NAT settings this container writes below.
if [ -n "$FUSIONPBX_DB_HOST" ] && [ -d "$FS_SCRIPTS_DIR/app/xml_handler" ]; then
  mkdir -p /etc/fusionpbx

  # config.lua reads this file to build its database DSN and to locate the script
  # tree. Cache is deliberately left on the default "memcache" method with no
  # mod_memcache present: cache.support() then reports false and every lookup goes
  # to the database. A working cache would live inside this container where the web
  # app cannot invalidate it, so a newly created agent would stay unregisterable
  # until the entry expired.
  cat > /etc/fusionpbx/config.conf <<CFGEOF
database.0.type = pgsql
database.0.host = ${FUSIONPBX_DB_HOST}
database.0.port = ${FUSIONPBX_DB_PORT}
database.0.name = ${FUSIONPBX_DB_NAME}
database.0.username = ${FUSIONPBX_DB_USER}
database.0.password = ${FUSIONPBX_DB_PASSWORD}

switch.conf.dir = /etc/freeswitch
switch.scripts.dir = ${FS_SCRIPTS_DIR}
switch.recordings.dir = /var/lib/freeswitch/recordings
switch.storage.dir = /var/lib/freeswitch/storage
switch.voicemail.dir = /var/lib/freeswitch/storage/voicemail
switch.sounds.dir = /usr/share/freeswitch/sounds
switch.database.dir = /var/lib/freeswitch/db

switch.event_socket.host = 127.0.0.1
switch.event_socket.port = 8021
switch.event_socket.password = ${ESL_PASSWORD}
CFGEOF
  chmod 600 /etc/fusionpbx/config.conf

  LUACONF=/etc/freeswitch/autoload_configs/lua.conf.xml
  if [ -f "$LUACONF" ]; then
    # The handler's very first line is require "resources.functions.trim", which
    # mod_lua cannot resolve until the script tree is on LUA_PATH; without this the
    # script aborts with "module not found" and FreeSWITCH falls back to the static
    # directory, so DB-only extensions still cannot register. mod_lua reads this
    # only at load time and is not unloadable, so it takes a restart to apply.
    # Delete every existing entry (the stock file ships two commented examples)
    # before adding ours, so re-running cannot accumulate duplicates.
    sed -i '/name="script-directory"/d' "$LUACONF" || true
    sed -i 's#<settings>#<settings>\n    <param name="script-directory" value="$${script_dir}/?.lua"/>#' "$LUACONF" || true
    # Run the handler through app.lua, the way FusionPBX itself does: app.lua loads
    # config.lua (which defines scripts_dir and the database DSN) before dispatching
    # to app/xml_handler/index.lua. Pointing straight at index.lua instead fails with
    # "attempt to concatenate a nil value (global 'scripts_dir')".
    # Same delete-then-insert approach as above to stay idempotent.
    sed -i '/name="xml-handler-script"/d;/name="xml-handler-bindings"/d' "$LUACONF" || true
    sed -i 's#</settings>#    <param name="xml-handler-script" value="app.lua xml_handler"/>\n    <param name="xml-handler-bindings" value="directory dialplan"/>\n  </settings>#' "$LUACONF" || true
    sed -i '/skykin_cc_watch/d;/skykin_bl_hash/d' "$LUACONF" || true
    sed -i 's#</settings>#    <param name="startup-script" value="/etc/freeswitch/scripts/skykin_cc_watch.lua"/>\n    <param name="startup-script" value="/etc/freeswitch/scripts/skykin_bl_hash.lua"/>\n  </settings>#' "$LUACONF" || true
    echo "  FusionPBX directory handler enabled (db ${FUSIONPBX_DB_HOST}/${FUSIONPBX_DB_NAME})"
  fi
else
  echo "  FusionPBX directory handler NOT enabled; only FS_DIRECTORY_USERS can register"
fi

# Static directory users. Now a fallback for DB-less labs: when the handler above
# is active the FusionPBX database is authoritative and answers first.
if [ -n "$FS_DIRECTORY_USERS" ]; then
  echo "$FS_DIRECTORY_USERS" | tr ',' '\n' | while IFS= read -r pair; do
    [ -n "$pair" ] || continue
    ext="${pair%%:*}"
    pass="${pair#*:}"
    [ -n "$ext" ] && [ -n "$pass" ] && [ "$ext" != "$pass" ] || continue
    cat > "/etc/freeswitch/directory/default/${ext}.xml" <<USEREOF
<include>
  <user id="${ext}">
    <params>
      <param name="password" value="${pass}"/>
      <param name="vm-password" value="${ext}"/>
    </params>
    <variables>
      <variable name="toll_allow" value="domestic,international,local"/>
      <variable name="accountcode" value="${ext}"/>
      <variable name="user_context" value="default"/>
      <variable name="effective_caller_id_name" value="Extension ${ext}"/>
      <variable name="effective_caller_id_number" value="${ext}"/>
      <variable name="outbound_caller_id_name" value="Extension ${ext}"/>
      <variable name="outbound_caller_id_number" value="${ext}"/>
      <variable name="callgroup" value="skykin"/>
    </variables>
  </user>
</include>
USEREOF
    echo "  directory user ${ext} provisioned"
  done
fi

# mod_callcenter drives agent status / queue stats used by the dashboards.
# mod_xml_cdr posts call detail records to FusionPBX, which is the only source
# the dashboards read for "calls today", call history and the recordings list.
# mod_lua runs the FusionPBX XML handler that serves the SIP directory from the
# database; without it only FS_DIRECTORY_USERS can register.
MODULES=/etc/freeswitch/autoload_configs/modules.conf.xml
if [ -f "$MODULES" ]; then
  for m in mod_callcenter mod_xml_cdr mod_lua; do
    sed -i "s#<!--[[:space:]]*<load module=\"${m}\"/>[[:space:]]*-->#<load module=\"${m}\"/>#" "$MODULES" || true
    if ! grep -q "^[[:space:]]*<load module=\"${m}\"/>" "$MODULES"; then
      sed -i "s#</modules>#    <load module=\"${m}\"/>\n  </modules>#" "$MODULES" || true
    fi
  done
  # Unused here. The image has no ca-certificates.crt, so a loaded
  # mod_signalwire logs Curl Result 77 every ~15 minutes.
  sed -i '/mod_signalwire/s#^[[:space:]]*<load module="mod_signalwire"/>#    <!-- <load module="mod_signalwire"/> -->#' "$MODULES" || true
fi

# Point mod_xml_cdr at the FusionPBX importer. The importer requires HTTP basic
# auth whose credentials it reads back out of this same file, so both sides stay
# in sync by construction. encode=true is required: the importer reads the
# url-encoded $_POST["cdr"] field.
if [ -n "$CDR_URL" ]; then
  cat > /etc/freeswitch/autoload_configs/xml_cdr.conf.xml <<CDREOF
<configuration name="xml_cdr.conf" description="XML CDR CURL logger">
  <settings>
    <param name="url" value="${CDR_URL}"/>
    <param name="cred" value="${CDR_CRED}"/>
    <param name="encode" value="true"/>
    <param name="retries" value="2"/>
    <param name="delay" value="5"/>
    <param name="log-b-leg" value="false"/>
    <param name="prefix-a-leg" value="true"/>
    <!-- Spool failed posts here so no record is lost while the web app is down;
         the importer picks them up later. -->
    <param name="err-log-dir" value="/var/log/freeswitch/xml_cdr"/>
  </settings>
</configuration>
CDREOF
  mkdir -p /var/log/freeswitch/xml_cdr
fi

# Queue + agents + tiers for mod_callcenter, scoped to the SIP domain.
if [ -n "$FS_DOMAIN" ]; then
  CC_FILE=/etc/freeswitch/autoload_configs/callcenter.conf.xml
  {
    echo '<configuration name="callcenter.conf" description="CallCenter">'
    echo '  <settings>'
    echo '    <param name="odbc-dsn" value=""/>'
    echo '  </settings>'
    echo '  <queues>'
    printf '    <queue name="%s@%s">\n' "$FS_QUEUE_EXT" "$FS_DOMAIN"
    printf '      <param name="strategy" value="%s"/>\n' "$FS_QUEUE_STRATEGY"
    # Empty MOH: if the queue answers to play hold music, Ethio IMS locks
    # that first 200 and never plays the agent after the bridge.
    echo '      <param name="moh-sound" value=""/>'
    echo '      <param name="time-base-score" value="system"/>'
    echo '      <param name="max-wait-time" value="0"/>'
    echo '      <param name="max-wait-time-with-no-agent" value="0"/>'
    echo '      <param name="max-wait-time-with-no-agent-time-reached" value="5"/>'
    echo '      <param name="tier-rules-apply" value="false"/>'
    echo '      <param name="tier-rule-wait-second" value="300"/>'
    echo '      <param name="tier-rule-wait-multiply-level" value="true"/>'
    echo '      <param name="tier-rule-no-agent-no-wait" value="false"/>'
    echo '      <param name="discard-abandoned-after" value="60"/>'
    echo '      <param name="abandoned-resume-allowed" value="false"/>'
    echo '    </queue>'
    echo "${FS_QUEUE_DOMAINS:-}" | tr ',' '\n' | while IFS= read -r extra; do
      extra=$(echo "$extra" | tr -d ' ')
      [ -n "$extra" ] || continue
      [ "$extra" = "$FS_DOMAIN" ] && continue
      printf '    <queue name="%s@%s">\n' "$FS_QUEUE_EXT" "$extra"
      printf '      <param name="strategy" value="%s"/>\n' "$FS_QUEUE_STRATEGY"
      echo '      <param name="moh-sound" value=""/>'
      echo '      <param name="time-base-score" value="system"/>'
      echo '      <param name="max-wait-time" value="0"/>'
      echo '      <param name="max-wait-time-with-no-agent" value="0"/>'
      echo '      <param name="max-wait-time-with-no-agent-time-reached" value="5"/>'
      echo '      <param name="tier-rules-apply" value="false"/>'
      echo '      <param name="discard-abandoned-after" value="60"/>'
      echo '      <param name="abandoned-resume-allowed" value="false"/>'
      echo '    </queue>'
    done
    echo '  </queues>'
    # Each agent entry is "name:ext". "name" is normally the FusionPBX
    # call_center_agent_uuid (the dashboard sets status by that name); "ext" is
    # the softphone extension used to build the SIP contact. If no ":" is given,
    # the extension doubles as the agent name (lab default).
    echo '  <agents>'
    echo "$FS_QUEUE_AGENTS" | tr ',' '\n' | while IFS= read -r ag; do
      [ -n "$ag" ] || continue
      aname="${ag%%:*}"
      aext="${ag#*:}"
      [ -n "$aext" ] || aext="$aname"
      [ "$aname" != "$ag" ] || aname="$aext"
      printf '    <agent name="%s" type="callback" contact="{ignore_early_media=true,bridge_early_media=false,originate_timeout=45}[leg_timeout=30,media_webrtc=true,rtp_secure_media=optional,rtp_advertise_ip=%s,include_external_ip=true]user/%s@%s" status="Available" max-no-answer="999" wrap-up-time="10" reject-delay-time="10" busy-delay-time="60"/>\n' \
        "$aname" "${EXTERNAL_RTP_IP:-196.189.236.126}" "$aext" "$FS_DOMAIN"
    done
    echo '  </agents>'
    echo '  <tiers>'
    echo "$FS_QUEUE_AGENTS" | tr ',' '\n' | while IFS= read -r ag; do
      [ -n "$ag" ] || continue
      aname="${ag%%:*}"
      aext="${ag#*:}"
      [ -n "$aext" ] || aext="$aname"
      [ "$aname" != "$ag" ] || aname="$aext"
      printf '    <tier agent="%s" queue="%s@%s" level="1" position="1"/>\n' \
        "$aname" "$FS_QUEUE_EXT" "$FS_DOMAIN"
    done
    echo '  </tiers>'
    echo '</configuration>'
  } > "$CC_FILE"
fi

# Outbound SIP trunk gateway (IP-auth / Ethio style). Written into the external
# profile so sofia/gateway/<name>/... works even when FusionPBX and FreeSWITCH
# live in separate containers and the web UI cannot push XML into this one.
if [ -n "$FS_OUTBOUND_PROXY" ] && [ -n "$FS_OUTBOUND_GATEWAY" ]; then
  mkdir -p /etc/freeswitch/sip_profiles/external
  {
    echo '<include>'
    printf '  <gateway name="%s">\n' "$FS_OUTBOUND_GATEWAY"
    [ -n "$FS_OUTBOUND_USERNAME" ] && printf '    <param name="username" value="%s"/>\n' "$FS_OUTBOUND_USERNAME"
    [ -n "$FS_OUTBOUND_AUTH_USERNAME" ] && printf '    <param name="auth-username" value="%s"/>\n' "$FS_OUTBOUND_AUTH_USERNAME"
    [ -n "$FS_OUTBOUND_PASSWORD" ] && printf '    <param name="password" value="%s"/>\n' "$FS_OUTBOUND_PASSWORD"
    [ -n "$FS_OUTBOUND_USERNAME" ] && printf '    <param name="from-user" value="%s"/>\n' "$FS_OUTBOUND_USERNAME"
    [ -n "$FS_OUTBOUND_FROM_DOMAIN" ] && printf '    <param name="from-domain" value="%s"/>\n' "$FS_OUTBOUND_FROM_DOMAIN"
    [ -n "$FS_OUTBOUND_REALM" ] && printf '    <param name="realm" value="%s"/>\n' "$FS_OUTBOUND_REALM"
    if [ -n "$FS_OUTBOUND_REALM" ]; then
      printf '    <param name="proxy" value="%s"/>\n' "$FS_OUTBOUND_REALM"
      printf '    <param name="register-proxy" value="%s"/>\n' "${FS_OUTBOUND_REGISTER_PROXY:-$FS_OUTBOUND_PROXY}"
      printf '    <param name="outbound-proxy" value="%s"/>\n' "${FS_OUTBOUND_REGISTER_PROXY:-$FS_OUTBOUND_PROXY}"
    else
      printf '    <param name="proxy" value="%s"/>\n' "$FS_OUTBOUND_PROXY"
    fi
    printf '    <param name="register" value="%s"/>\n' "$FS_OUTBOUND_REGISTER"
    echo '    <param name="expire-seconds" value="3600"/>'
    echo '    <param name="retry-seconds" value="30"/>'
    echo '    <param name="context" value="public"/>'
    echo '    <param name="caller-id-in-from" value="true"/>'
    echo '    <param name="extension-in-contact" value="true"/>'
    echo '  </gateway>'
    echo '</include>'
  } > "/etc/freeswitch/sip_profiles/external/${FS_OUTBOUND_GATEWAY}.xml"
  echo "  Outbound gateway ${FS_OUTBOUND_GATEWAY} -> ${FS_OUTBOUND_PROXY} (register=${FS_OUTBOUND_REGISTER})"
  # ZTE / IMS cores send 183+SDP and drop the call with 488 unless we PRACK.
  EXT_PROF=/etc/freeswitch/sip_profiles/external.xml
  if [ -f "$EXT_PROF" ]; then
    if grep -q 'name="enable-100rel"' "$EXT_PROF"; then
      sed -i 's#<!--[[:space:]]*<param name="enable-100rel"[^>]*/>[[:space:]]*-->#<param name="enable-100rel" value="true"/>#' "$EXT_PROF" || true
      sed -i 's#<param name="enable-100rel" value="[^"]*"/>#<param name="enable-100rel" value="true"/>#' "$EXT_PROF" || true
    else
      sed -i 's#</settings>#    <param name="enable-100rel" value="true"/>\n  </settings>#' "$EXT_PROF" || true
    fi
    # Carrier (Ethio IMS) is on the LAN. Advertising the public NAT IP in
    # Contact/Via makes REGISTER FAIL_WAIT: the IMS replies to the WAN IP,
    # which is not this VM. Pin sip-ip/rtp-ip and ext-* to the host NIC.
    # Internal/WebRTC keeps $${external_sip_ip} for browsers.
    if [ -n "$FS_LAN_RTP_IP" ]; then
      sed -i "s#name=\"sip-ip\" value=\"[^\"]*\"#name=\"sip-ip\" value=\"${FS_LAN_RTP_IP}\"#" "$EXT_PROF" || true
      sed -i "s#name=\"rtp-ip\" value=\"[^\"]*\"#name=\"rtp-ip\" value=\"${FS_LAN_RTP_IP}\"#" "$EXT_PROF" || true
      sed -i "s#name=\"ext-sip-ip\" value=\"[^\"]*\"#name=\"ext-sip-ip\" value=\"${FS_LAN_RTP_IP}\"#" "$EXT_PROF" || true
      sed -i "s#name=\"ext-rtp-ip\" value=\"[^\"]*\"#name=\"ext-rtp-ip\" value=\"${FS_LAN_RTP_IP}\"#" "$EXT_PROF" || true
      echo "  External profile Contact/RTP advertised as ${FS_LAN_RTP_IP}"
    fi
  fi
  if [ -n "$FS_OUTBOUND_REALM" ] && [ -n "$FS_OUTBOUND_PROXY" ]; then
    if ! grep -q "[[:space:]]${FS_OUTBOUND_REALM}$" /etc/hosts 2>/dev/null; then
      echo "${FS_OUTBOUND_PROXY} ${FS_OUTBOUND_REALM}" >> /etc/hosts
    fi
  fi
fi

# SkyKin dialplan: 3-digit agent extensions and the call center queue.
# Vanilla only routes 1000-1019, so 100/101/102 would hang up immediately.
if [ -n "$FS_DOMAIN" ]; then
  # Force public media IP into WebRTC SDP/ICE when known (Docker bridge IPs are unreachable).
  # include_external_ip=true makes FreeSWITCH add the ext-rtp-ip candidate even when
  # the peer would otherwise be classified as local (proxied WSS), which is the
  # channel-variable counterpart to local-network-acl=none on the profile.
  RTP_ADV=""
  if [ -n "$EXTERNAL_RTP_IP" ]; then
    # Plain export (not nolocal:). Agent-to-agent WebRTC needs the public NAT
    # IP in SDP. The Ethio b-leg overrides this in the sofia/gateway {} string
    # with FS_LAN_RTP_IP. nolocal: stripped the public IP from callee WebRTC
    # and made agent-to-agent silent.
    RTP_ADV="      <action application=\"export\" data=\"rtp_advertise_ip=${EXTERNAL_RTP_IP}\"/>
      <action application=\"export\" data=\"include_external_ip=true\"/>"
  fi
  # Local/queue bridges to user/<ext> must enable DTLS (media_webrtc) or
  # Chrome answers with "SDP without DTLS fingerprint" and the call dies.
  USER_BRIDGE="{rtp_secure_media=optional,media_webrtc=true}"
  if [ -n "$EXTERNAL_RTP_IP" ]; then
    USER_BRIDGE="{rtp_secure_media=optional,media_webrtc=true,rtp_advertise_ip=${EXTERNAL_RTP_IP},include_external_ip=true}"
  fi
  # The dashboards look recordings up through the CDR, so the call record has to
  # carry domain_name plus record_path/record_name. Use the FusionPBX archive
  # layout (domain/archive/YYYY/Mon/DD) that play_recording.php searches.
  CDR_VARS=$(cat <<'CDRVARS'
      <action application="export" data="domain_name=@FS_DOMAIN@"/>
      <action application="set" data="record_path=/var/lib/freeswitch/recordings/@FS_DOMAIN@/archive/${strftime(%Y)}/${strftime(%b)}/${strftime(%d)}"/>
      <action application="set" data="record_name=${uuid}.wav"/>
CDRVARS
)
  CDR_VARS=$(printf '%s' "$CDR_VARS" | sed "s#@FS_DOMAIN@#${FS_DOMAIN}#g")
  SKYKIN_EXTENSIONS=$(cat <<DPEOF

  <extension name="skykin_queue">
    <condition field="destination_number" expression="^(${FS_QUEUE_EXT})\$">
      <action application="answer"/>
${RTP_ADV}
      <action application="set" data="hangup_after_bridge=true"/>
      <action application="set" data="record_stereo=true"/>
      <action application="set" data="recording_follow_transfer=true"/>
${CDR_VARS}
      <action application="record_session" data="\${record_path}/\${record_name}"/>
      <action application="callcenter" data="\$1@${FS_DOMAIN}"/>
    </condition>
  </extension>

  <extension name="skykin_local_extension">
    <condition field="destination_number" expression="^(1\d{2})\$">
      <action application="export" data="dialed_extension=\$1"/>
${RTP_ADV}
      <action application="set" data="hangup_after_bridge=true"/>
      <action application="set" data="continue_on_fail=true"/>
      <action application="set" data="call_timeout=30"/>
      <action application="set" data="record_stereo=true"/>
${CDR_VARS}
      <action application="record_session" data="\${record_path}/\${record_name}"/>
      <action application="bridge" data="${USER_BRIDGE}user/\$1@${FS_DOMAIN}"/>
    </condition>
  </extension>

  <extension name="skykin_echo_test">
    <condition field="destination_number" expression="^9196\$">
${RTP_ADV}
      <action application="answer"/>
      <action application="echo"/>
    </condition>
  </extension>
DPEOF
)

  # Outbound through the SIP trunk. Matched after local 1xx / queue so internal
  # dialling is never stolen. Ethiopian mobiles dialled as 09xxxxxxxx are
  # rewritten to +2519xxxxxxxx; bare/E.164 251… forms are accepted as-is.
  if [ -n "$FS_OUTBOUND_GATEWAY" ]; then
    # pre_answer (not answer): talk timer starts when the mobile answers.
    # Do not uuid_media_reneg on this trunk — it drops the call immediately.
    # Pin LAN RTP toward Ethio (SIP whitelist ≠ RTP/media ACL). Keep AMR in
    # the list (PT 102); order matches the last working ecs-cc runtime.
    # Carrier later asked SDP 8 0 101 (PCMA,PCMU,telephone-event) with AMR
    # still offered — switch the string to ^^:PCMA:PCMU:AMR@8000h@20i if they
    # require that order. No send_silence_when_idle (it blocked agent audio).
    # Ethio SDP order 8 0 101, keep AMR (PT 102) but not first.
    BLEG_RTP="origination_uuid=\${bleg_uuid},absolute_codec_string=^^:PCMA:PCMU:AMR@8000h@20i,amr_octet_align=1,rtcp=-1,rtp_secure_media=false,media_webrtc=false,ignore_early_media=false,sip_session_expires=180,sip_force_session_timer=true"
    if [ -n "$FS_LAN_RTP_IP" ]; then
      BLEG_RTP="${BLEG_RTP},rtp_advertise_ip=${FS_LAN_RTP_IP},include_external_ip=false"
    fi
    if [ -n "$FS_OUTBOUND_CID" ]; then
      BLEG_RTP="${BLEG_RTP},origination_caller_id_number=${FS_OUTBOUND_CID},origination_caller_id_name=${FS_OUTBOUND_CID}"
    fi
    BLEG_RTP2="${BLEG_RTP}"
    if [ -n "$FS_OUTBOUND_CID2" ]; then
      BLEG_RTP2="$(printf '%s' "$BLEG_RTP" | sed "s/origination_caller_id_number=[^,]*/origination_caller_id_number=${FS_OUTBOUND_CID2}/;s/origination_caller_id_name=[^,]*/origination_caller_id_name=${FS_OUTBOUND_CID2}/")"
      if ! printf '%s' "$BLEG_RTP2" | grep -q origination_caller_id_number; then
        BLEG_RTP2="${BLEG_RTP2},origination_caller_id_number=${FS_OUTBOUND_CID2},origination_caller_id_name=${FS_OUTBOUND_CID2}"
      fi
    fi
    # continue_on_fail=true through pre_answer/record so a recording-path miss
    # does not kill the call before the mobile rings. Flip to false immediately
    # before bridge so Decline/Busy hangs up the agent instead of retrying.
    OUT_PRE="${RTP_ADV}
      <action application=\"set\" data=\"hangup_after_bridge=true\"/>
      <action application=\"set\" data=\"call_direction=outbound\"/>
      <action application=\"export\" data=\"call_direction=outbound\"/>
      <action application=\"set\" data=\"continue_on_fail=true\"/>
      <action application=\"set\" data=\"call_timeout=60\"/>
      <action application=\"pre_answer\"/>
      <action application=\"set\" data=\"record_stereo=true\"/>
${CDR_VARS}
      <action application=\"record_session\" data=\"\${record_path}/\${record_name}\"/>
      <action application=\"set\" data=\"bleg_uuid=\${create_uuid()}\"/>
      <action application=\"set\" data=\"continue_on_fail=false\"/>"
    OUT_HUP="      <action application=\"hangup\"/>"
    SKYKIN_EXTENSIONS="${SKYKIN_EXTENSIONS}

  <extension name=\"skykin_outbound_et_normalize_mobile\">
    <condition field=\"destination_number\" expression=\"^\\\\+?(?:00251|251)(9[0-9]{8})\$\">
      <action application=\"transfer\" data=\"\$1 XML ${FS_DOMAIN}\"/>
    </condition>
  </extension>

  <extension name=\"skykin_outbound_et_normalize_land\">
    <condition field=\"destination_number\" expression=\"^\\\\+?(?:00251|251)([1-8][0-9]{8})\$\">
      <action application=\"transfer\" data=\"0\$1 XML ${FS_DOMAIN}\"/>
    </condition>
  </extension>

  <extension name=\"skykin_outbound_756_zero\">
    <condition field=\"destination_number\" expression=\"^7560([0-9]{9})\$\">
${OUT_PRE}
      <action application=\"bridge\" data=\"{${BLEG_RTP2}}sofia/gateway/${FS_OUTBOUND_GATEWAY2}/+251\$1\"/>
${OUT_HUP}
    </condition>
  </extension>

  <extension name=\"skykin_outbound_756_nozero\">
    <condition field=\"destination_number\" expression=\"^756(9[0-9]{8})\$\">
${OUT_PRE}
      <action application=\"bridge\" data=\"{${BLEG_RTP2}}sofia/gateway/${FS_OUTBOUND_GATEWAY2}/+251\$1\"/>
${OUT_HUP}
    </condition>
  </extension>

  <extension name=\"skykin_outbound_et_zero\">
    <condition field=\"destination_number\" expression=\"^0([0-9]{9})\$\">
${OUT_PRE}
      <action application=\"bridge\" data=\"{${BLEG_RTP}}sofia/gateway/${FS_OUTBOUND_GATEWAY}/+251\$1\"/>
${OUT_HUP}
    </condition>
  </extension>

  <extension name=\"skykin_outbound_et_nozero\">
    <condition field=\"destination_number\" expression=\"^(9[0-9]{8})\$\">
${OUT_PRE}
      <action application=\"bridge\" data=\"{${BLEG_RTP}}sofia/gateway/${FS_OUTBOUND_GATEWAY}/+251\$1\"/>
${OUT_HUP}
    </condition>
  </extension>

  <extension name=\"skykin_outbound_et_land\">
    <condition field=\"destination_number\" expression=\"^([1-8][0-9]{8})\$\">
${OUT_PRE}
      <action application=\"bridge\" data=\"{${BLEG_RTP}}sofia/gateway/${FS_OUTBOUND_GATEWAY}/+251\$1\"/>
${OUT_HUP}
    </condition>
  </extension>

  <extension name=\"skykin_outbound_et_e164\">
    <condition field=\"destination_number\" expression=\"^\\\\+?(?:00251|251)([0-9]{9})\$\">
${OUT_PRE}
      <action application=\"bridge\" data=\"{${BLEG_RTP}}sofia/gateway/${FS_OUTBOUND_GATEWAY}/+251\$1\"/>
${OUT_HUP}
    </condition>
  </extension>
"
  fi

  # The static directory users below put callers in the "default" context.
  printf '<include>\n%s\n</include>\n' "$SKYKIN_EXTENSIONS" \
    > /etc/freeswitch/dialplan/default/00_skykin.xml

  # FusionPBX, however, names each extension's user_context after its domain, so
  # agents authenticated out of the database arrive in a context that would
  # otherwise hold no rules at all and their calls would fail immediately with no
  # route. Publish the same rules there. Both contexts are generated from one
  # source so they cannot drift apart.
  printf '<include>\n  <context name="%s">\n%s\n  </context>\n</include>\n' \
    "$FS_DOMAIN" "$SKYKIN_EXTENSIONS" \
    > "/etc/freeswitch/dialplan/01_skykin_${FS_DOMAIN}.xml"

  # Second FusionPBX domain (ahununu / 201-205). Inbound 035-039 (SIP8035-8039);
  # agents still need a named context or every outbound is NO_ROUTE_DESTINATION.
  AHUNUNU_CID758="${FS_OUTBOUND_CID758:-+251111138758}"
  AHUNUNU_CID759="${FS_OUTBOUND_CID759:-+251111138759}"
  AHUNUNU_GW="${FS_AHUNUNU_OUTBOUND_GATEWAY:-SIP8035}"
  AHUNUNU_CID="${FS_AHUNUNU_OUTBOUND_CID:-+251116198035}"
  AHUNUNU_LAN="${FS_LAN_RTP_IP:-10.0.0.77}"
  AHUNUNU_EXTIP="${EXTERNAL_RTP_IP:-196.189.236.126}"
  mkdir -p /var/lib/freeswitch/recordings/ahununu/archive
  cat > /etc/freeswitch/dialplan/01_skykin_ahununu.xml <<AHUNUNUEOF
<include>
  <context name="ahununu">
    <extension name="skykin_local_2xx">
      <condition field="destination_number" expression="^(2[0-9]{2})\$">
        <action application="export" data="rtp_advertise_ip=${AHUNUNU_EXTIP}"/>
        <action application="export" data="include_external_ip=true"/>
        <action application="set" data="hangup_after_bridge=true"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="set" data="call_timeout=30"/>
        <action application="set" data="record_stereo=true"/>
        <action application="export" data="domain_name=ahununu"/>
        <action application="set" data="record_path=/var/lib/freeswitch/recordings/ahununu/archive/\${strftime(%Y)}/\${strftime(%b)}/\${strftime(%d)}"/>
        <action application="set" data="record_name=\${uuid}.wav"/>
        <action application="record_session" data="\${record_path}/\${record_name}"/>
        <action application="bridge" data="{rtp_secure_media=optional,media_webrtc=true,rtp_advertise_ip=${AHUNUNU_EXTIP},include_external_ip=true}user/\$1@ahununu"/>
      </condition>
    </extension>
    <extension name="skykin_outbound_et_normalize_mobile">
      <condition field="destination_number" expression="^\+?(?:00251|251)(9[0-9]{8})$">
        <action application="transfer" data="$1 XML ahununu"/>
      </condition>
    </extension>
    <extension name="skykin_outbound_et_normalize_land">
      <condition field="destination_number" expression="^\+?(?:00251|251)([1-8][0-9]{8})$">
        <action application="transfer" data="0$1 XML ahununu"/>
      </condition>
    </extension>
    <extension name="skykin_outbound_758_zero">
      <condition field="destination_number" expression="^7580([0-9]{9})\$">
        <action application="export" data="rtp_advertise_ip=${AHUNUNU_EXTIP}"/>
        <action application="set" data="hangup_after_bridge=true"/>
        <action application="set" data="call_direction=outbound"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="pre_answer"/>
        <action application="export" data="domain_name=ahununu"/>
        <action application="set" data="record_stereo=true"/>
        <action application="set" data="record_path=/var/lib/freeswitch/recordings/ahununu/archive/\${strftime(%Y)}/\${strftime(%b)}/\${strftime(%d)}"/>
        <action application="set" data="record_name=\${uuid}.wav"/>
        <action application="record_session" data="\${record_path}/\${record_name}"/>
        <action application="set" data="bleg_uuid=\${create_uuid()}"/>
        <action application="set" data="continue_on_fail=false"/>
        <action application="bridge" data="{origination_uuid=\${bleg_uuid},absolute_codec_string=^^:PCMA:PCMU:AMR@8000h@20i,amr_octet_align=1,rtcp=-1,rtp_secure_media=false,media_webrtc=false,ignore_early_media=false,rtp_advertise_ip=${AHUNUNU_LAN},include_external_ip=false,origination_caller_id_number=${AHUNUNU_CID758},origination_caller_id_name=${AHUNUNU_CID758}}sofia/gateway/SIP758/+251\$1"/>
        <action application="hangup"/>
      </condition>
    </extension>
    <extension name="skykin_outbound_758_nozero">
      <condition field="destination_number" expression="^758(9[0-9]{8})\$">
        <action application="export" data="rtp_advertise_ip=${AHUNUNU_EXTIP}"/>
        <action application="set" data="hangup_after_bridge=true"/>
        <action application="set" data="call_direction=outbound"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="pre_answer"/>
        <action application="export" data="domain_name=ahununu"/>
        <action application="set" data="record_stereo=true"/>
        <action application="set" data="record_path=/var/lib/freeswitch/recordings/ahununu/archive/\${strftime(%Y)}/\${strftime(%b)}/\${strftime(%d)}"/>
        <action application="set" data="record_name=\${uuid}.wav"/>
        <action application="record_session" data="\${record_path}/\${record_name}"/>
        <action application="set" data="bleg_uuid=\${create_uuid()}"/>
        <action application="set" data="continue_on_fail=false"/>
        <action application="bridge" data="{origination_uuid=\${bleg_uuid},absolute_codec_string=^^:PCMA:PCMU:AMR@8000h@20i,amr_octet_align=1,rtcp=-1,rtp_secure_media=false,media_webrtc=false,ignore_early_media=false,rtp_advertise_ip=${AHUNUNU_LAN},include_external_ip=false,origination_caller_id_number=${AHUNUNU_CID758},origination_caller_id_name=${AHUNUNU_CID758}}sofia/gateway/SIP758/+251\$1"/>
        <action application="hangup"/>
      </condition>
    </extension>
    <extension name="skykin_outbound_759_zero">
      <condition field="destination_number" expression="^7590([0-9]{9})\$">
        <action application="export" data="rtp_advertise_ip=${AHUNUNU_EXTIP}"/>
        <action application="set" data="hangup_after_bridge=true"/>
        <action application="set" data="call_direction=outbound"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="pre_answer"/>
        <action application="export" data="domain_name=ahununu"/>
        <action application="set" data="record_stereo=true"/>
        <action application="set" data="record_path=/var/lib/freeswitch/recordings/ahununu/archive/\${strftime(%Y)}/\${strftime(%b)}/\${strftime(%d)}"/>
        <action application="set" data="record_name=\${uuid}.wav"/>
        <action application="record_session" data="\${record_path}/\${record_name}"/>
        <action application="set" data="bleg_uuid=\${create_uuid()}"/>
        <action application="set" data="continue_on_fail=false"/>
        <action application="bridge" data="{origination_uuid=\${bleg_uuid},absolute_codec_string=^^:PCMA:PCMU:AMR@8000h@20i,amr_octet_align=1,rtcp=-1,rtp_secure_media=false,media_webrtc=false,ignore_early_media=false,rtp_advertise_ip=${AHUNUNU_LAN},include_external_ip=false,origination_caller_id_number=${AHUNUNU_CID759},origination_caller_id_name=${AHUNUNU_CID759}}sofia/gateway/SIP759/+251\$1"/>
        <action application="hangup"/>
      </condition>
    </extension>
    <extension name="skykin_outbound_759_nozero">
      <condition field="destination_number" expression="^759(9[0-9]{8})\$">
        <action application="export" data="rtp_advertise_ip=${AHUNUNU_EXTIP}"/>
        <action application="set" data="hangup_after_bridge=true"/>
        <action application="set" data="call_direction=outbound"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="pre_answer"/>
        <action application="export" data="domain_name=ahununu"/>
        <action application="set" data="record_stereo=true"/>
        <action application="set" data="record_path=/var/lib/freeswitch/recordings/ahununu/archive/\${strftime(%Y)}/\${strftime(%b)}/\${strftime(%d)}"/>
        <action application="set" data="record_name=\${uuid}.wav"/>
        <action application="record_session" data="\${record_path}/\${record_name}"/>
        <action application="set" data="bleg_uuid=\${create_uuid()}"/>
        <action application="set" data="continue_on_fail=false"/>
        <action application="bridge" data="{origination_uuid=\${bleg_uuid},absolute_codec_string=^^:PCMA:PCMU:AMR@8000h@20i,amr_octet_align=1,rtcp=-1,rtp_secure_media=false,media_webrtc=false,ignore_early_media=false,rtp_advertise_ip=${AHUNUNU_LAN},include_external_ip=false,origination_caller_id_number=${AHUNUNU_CID759},origination_caller_id_name=${AHUNUNU_CID759}}sofia/gateway/SIP759/+251\$1"/>
        <action application="hangup"/>
      </condition>
    </extension>
    <extension name="skykin_outbound_et_zero">
      <condition field="destination_number" expression="^0([0-9]{9})\$">
        <action application="export" data="rtp_advertise_ip=${AHUNUNU_EXTIP}"/>
        <action application="set" data="hangup_after_bridge=true"/>
        <action application="set" data="call_direction=outbound"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="pre_answer"/>
        <action application="export" data="domain_name=ahununu"/>
        <action application="set" data="record_stereo=true"/>
        <action application="set" data="record_path=/var/lib/freeswitch/recordings/ahununu/archive/\${strftime(%Y)}/\${strftime(%b)}/\${strftime(%d)}"/>
        <action application="set" data="record_name=\${uuid}.wav"/>
        <action application="record_session" data="\${record_path}/\${record_name}"/>
        <action application="set" data="bleg_uuid=\${create_uuid()}"/>
        <action application="set" data="continue_on_fail=false"/>
        <action application="bridge" data="{origination_uuid=\${bleg_uuid},absolute_codec_string=^^:PCMA:PCMU:AMR@8000h@20i,amr_octet_align=1,rtcp=-1,rtp_secure_media=false,media_webrtc=false,ignore_early_media=false,rtp_advertise_ip=${AHUNUNU_LAN},include_external_ip=false,origination_caller_id_number=${AHUNUNU_CID},origination_caller_id_name=${AHUNUNU_CID}}sofia/gateway/${AHUNUNU_GW}/+251\$1"/>
        <action application="hangup"/>
      </condition>
    </extension>
    <extension name="skykin_outbound_et_nozero">
      <condition field="destination_number" expression="^(9[0-9]{8})\$">
        <action application="export" data="rtp_advertise_ip=${AHUNUNU_EXTIP}"/>
        <action application="set" data="hangup_after_bridge=true"/>
        <action application="set" data="call_direction=outbound"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="pre_answer"/>
        <action application="export" data="domain_name=ahununu"/>
        <action application="set" data="record_stereo=true"/>
        <action application="set" data="record_path=/var/lib/freeswitch/recordings/ahununu/archive/\${strftime(%Y)}/\${strftime(%b)}/\${strftime(%d)}"/>
        <action application="set" data="record_name=\${uuid}.wav"/>
        <action application="record_session" data="\${record_path}/\${record_name}"/>
        <action application="set" data="bleg_uuid=\${create_uuid()}"/>
        <action application="set" data="continue_on_fail=false"/>
        <action application="bridge" data="{origination_uuid=\${bleg_uuid},absolute_codec_string=^^:PCMA:PCMU:AMR@8000h@20i,amr_octet_align=1,rtcp=-1,rtp_secure_media=false,media_webrtc=false,ignore_early_media=false,rtp_advertise_ip=${AHUNUNU_LAN},include_external_ip=false,origination_caller_id_number=${AHUNUNU_CID},origination_caller_id_name=${AHUNUNU_CID}}sofia/gateway/${AHUNUNU_GW}/+251\$1"/>
        <action application="hangup"/>
      </condition>
    </extension>
    <extension name="skykin_outbound_et_land">
      <condition field="destination_number" expression="^([1-8][0-9]{8})\$">
        <action application="export" data="rtp_advertise_ip=${AHUNUNU_EXTIP}"/>
        <action application="set" data="hangup_after_bridge=true"/>
        <action application="set" data="call_direction=outbound"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="pre_answer"/>
        <action application="export" data="domain_name=ahununu"/>
        <action application="set" data="record_stereo=true"/>
        <action application="set" data="record_path=/var/lib/freeswitch/recordings/ahununu/archive/\${strftime(%Y)}/\${strftime(%b)}/\${strftime(%d)}"/>
        <action application="set" data="record_name=\${uuid}.wav"/>
        <action application="record_session" data="\${record_path}/\${record_name}"/>
        <action application="set" data="bleg_uuid=\${create_uuid()}"/>
        <action application="set" data="continue_on_fail=false"/>
        <action application="bridge" data="{origination_uuid=\${bleg_uuid},absolute_codec_string=^^:PCMA:PCMU:AMR@8000h@20i,amr_octet_align=1,rtcp=-1,rtp_secure_media=false,media_webrtc=false,ignore_early_media=false,rtp_advertise_ip=${AHUNUNU_LAN},include_external_ip=false,origination_caller_id_number=${AHUNUNU_CID},origination_caller_id_name=${AHUNUNU_CID}}sofia/gateway/${AHUNUNU_GW}/+251\$1"/>
        <action application="hangup"/>
      </condition>
    </extension>
    <extension name="skykin_outbound_et_e164">
      <condition field="destination_number" expression="^\+?(?:00251|251)([0-9]{9})\$">
        <action application="export" data="rtp_advertise_ip=${AHUNUNU_EXTIP}"/>
        <action application="set" data="hangup_after_bridge=true"/>
        <action application="set" data="call_direction=outbound"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="pre_answer"/>
        <action application="export" data="domain_name=ahununu"/>
        <action application="set" data="record_stereo=true"/>
        <action application="set" data="record_path=/var/lib/freeswitch/recordings/ahununu/archive/\${strftime(%Y)}/\${strftime(%b)}/\${strftime(%d)}"/>
        <action application="set" data="record_name=\${uuid}.wav"/>
        <action application="record_session" data="\${record_path}/\${record_name}"/>
        <action application="set" data="bleg_uuid=\${create_uuid()}"/>
        <action application="set" data="continue_on_fail=false"/>
        <action application="bridge" data="{origination_uuid=\${bleg_uuid},absolute_codec_string=^^:PCMA:PCMU:AMR@8000h@20i,amr_octet_align=1,rtcp=-1,rtp_secure_media=false,media_webrtc=false,ignore_early_media=false,rtp_advertise_ip=${AHUNUNU_LAN},include_external_ip=false,origination_caller_id_number=${AHUNUNU_CID},origination_caller_id_name=${AHUNUNU_CID}}sofia/gateway/${AHUNUNU_GW}/+251\$1"/>
        <action application="hangup"/>
      </condition>
    </extension>
    <extension name="skykin_welcome_route">
      <condition field="destination_number" expression="^8098$">
        <action application="set" data="domain_name=ahununu"/>
        <action application="export" data="domain_name=ahununu"/>
        <action application="set" data="call_direction=inbound"/>
        <action application="export" data="call_direction=inbound"/>
        <action application="set" data="hangup_after_bridge=true"/>
        <action application="set" data="ignore_early_media=true"/>
        <action application="set" data="bridge_early_media=false"/>
        <action application="set" data="instant_ringback=true"/>
        <action application="ring_ready"/>
        <action application="lua" data="/etc/freeswitch/scripts/skykin_welcome.lua"/>
        <action application="transfer" data="8099 XML ahununu"/>
      </condition>
    </extension>
    <extension name="skykin_agent_hunt">
      <condition field="destination_number" expression="^8099$">
        <action application="set" data="domain_name=ahununu"/>
        <action application="export" data="domain_name=ahununu"/>
        <action application="set" data="call_direction=inbound"/>
        <action application="set" data="hangup_after_bridge=true"/>
        <action application="set" data="continue_on_fail=false"/>
        <action application="set" data="ignore_early_media=true"/>
        <action application="set" data="bridge_early_media=false"/>
        <action application="set" data="originate_early_media=false"/>
        <action application="set" data="instant_ringback=true"/>
        <action application="lua" data="/etc/freeswitch/scripts/skykin_cc_prune.lua 8000@ahununu"/>
        <action application="export" data="nolocal:execute_on_hangup=lua::/etc/freeswitch/scripts/skykin_cc_drop.lua"/>
        <action application="set" data="cc_export_vars=execute_on_hangup"/>
        <action application="ring_ready"/>
        <action application="lua" data="/etc/freeswitch/scripts/skykin_inbound.lua"/>
      </condition>
    </extension>
  </context>
</include>
AHUNUNUEOF
  echo "  Ahununu context: 2xx local, outbound ${AHUNUNU_GW} (035-039 SIP8035-8039; 758/759 prefix optional)"

  # A leftover webrtc_local that only matches 101|102 and uses a WSS-only
  # contact steals agent-to-agent calls before skykin_local_extension runs.
  # Overwrite every copy so any 1xx rings via user/<ext> (WSS or UDP).
  WEBRTC_LOCAL=$(cat <<WLEOF
<include>
  <extension name="webrtc_local" continue="false">
    <condition field="destination_number" expression="^(1\\d{2})\$">
      <action application="set" data="hangup_after_bridge=true"/>
      <action application="set" data="continue_on_fail=true"/>
      <action application="set" data="call_timeout=30"/>
      <action application="set" data="rtp_secure_media=optional"/>
${RTP_ADV}
      <action application="bridge" data="${USER_BRIDGE}user/\$1@${FS_DOMAIN}"/>
    </condition>
  </extension>
</include>
WLEOF
)
  mkdir -p "/etc/freeswitch/dialplan/${FS_DOMAIN}"
  printf '%s\n' "$WEBRTC_LOCAL" > /etc/freeswitch/dialplan/default/00_webrtc_local.xml
  printf '%s\n' "$WEBRTC_LOCAL" > /etc/freeswitch/dialplan/default/00_aa_webrtc_local.xml
  printf '%s\n' "$WEBRTC_LOCAL" > "/etc/freeswitch/dialplan/${FS_DOMAIN}/00_webrtc_local.xml"
  # Leftover rule dialled 251… without + and stole mobiles before +251 outbound.
  rm -f /etc/freeswitch/dialplan/default/00_ethio_mobile.xml
fi

# Ring available agents without answering Ethio. mod_callcenter pre-answers
# the caller (183/SDP); that IMS treats 183 as answered so the mobile ring
# stops. Direct bridge after 180 keeps the phone ringing until Answer.
mkdir -p /etc/freeswitch/scripts
cat > /etc/freeswitch/scripts/skykin_inbound_queue.lua << 'LUAEOF'
-- Ring idle agents only. Never answer Ethio (180 only) until an agent answers.
if not session then
  return
end

local api = freeswitch.API()
local domain = session:getVariable("domain_name") or "client1.skykin.local"
local rtp_ip = session:getVariable("rtp_ext_ip") or "196.189.236.140"
local uuid = session:getVariable("uuid") or ""

session:execute("ring_ready")
session:setVariable("ignore_early_media", "true")
session:setVariable("bridge_early_media", "false")
session:setVariable("hangup_after_bridge", "true")
session:setVariable("continue_on_fail", "true")
session:setVariable("originate_timeout", "45")
session:setVariable("call_timeout", "45")
session:setVariable("hangup_cause", "NO_ANSWER")

local function split_pipe(line)
  local cols = {}
  local i = 0
  for col in (line .. "|"):gmatch("([^|]*)|") do
    i = i + 1
    cols[i] = col
  end
  return cols
end

local function registered(ext)
  local r = api:execute("sofia_contact", "*/" .. ext .. "@" .. domain) or ""
  if r:find("error", 1, true) or r:find("user_not_registered", 1, true) then
    return false
  end
  return r:find("sip:", 1, true) ~= nil or r:find("sofia/", 1, true) ~= nil
end

local function in_call(ext)
  local raw = api:execute("show", "channels as json") or ""
  if raw == "" or raw:sub(1, 4) == "-ERR" then
    raw = api:execute("show", "channels") or ""
  end
  if uuid ~= "" then
    raw = raw:gsub(uuid, "")
  end
  ext = tostring(ext)
  if raw:find(ext .. "@" .. domain, 1, true) then return true end
  if raw:find("/" .. ext .. "@", 1, true) then return true end
  if raw:find('presence_id":"' .. ext .. "@", 1, true) then return true end
  if raw:find('cid_num":"' .. ext .. '"', 1, true) then return true end
  if raw:find('dest":"' .. ext .. '"', 1, true) then return true end
  if raw:find("presence_id: " .. ext .. "@", 1, true) then return true end
  return false
end

local function collect()
  local raw = api:execute("callcenter_config", "agent list") or ""
  local header_idx = nil
  local dests = {}
  local seen = {}
  for line in raw:gmatch("[^\r\n]+") do
    if line:sub(1, 3) ~= "+OK" and line ~= "" then
      if not header_idx then
        header_idx = {}
        for i, name in ipairs(split_pipe(line)) do
          header_idx[name] = i
        end
      else
        local cols = split_pipe(line)
        local status = cols[header_idx.status or 0] or ""
        local contact = cols[header_idx.contact or 0] or ""
        local ext = contact:match("user/(%d+)@")
        if ext and not seen[ext] then
          seen[ext] = true
          if status ~= "Logged Out" and status ~= "On Break"
              and registered(ext) and not in_call(ext) then
            dests[#dests + 1] = ext
          end
        end
      end
    end
  end
  if #dests == 0 then
    for _, ext in ipairs({"101", "102"}) do
      if registered(ext) and not in_call(ext) then
        dests[#dests + 1] = ext
      end
    end
  end
  return dests
end

local function bridge_to(exts)
  local legs = {}
  for _, ext in ipairs(exts) do
    legs[#legs + 1] =
      "{ignore_early_media=true,bridge_early_media=false,originate_timeout=45,fail_on_single_reject=false}" ..
      "[leg_timeout=30,media_webrtc=true,rtp_secure_media=optional,rtp_advertise_ip=" ..
      rtp_ip .. ",include_external_ip=true]user/" .. ext .. "@" .. domain
  end
  local dest = table.concat(legs, ":_:")
  freeswitch.consoleLog("NOTICE", "skykin_inbound_queue idle=" .. table.concat(exts, ",") .. " dest=" .. dest .. "\n")
  session:execute("bridge", dest)
end

local tries = 0
while session:ready() and tries < 40 do
  tries = tries + 1
  local exts = collect()
  if #exts > 0 then
    bridge_to(exts)
    local ok, answered = pcall(function() return session:answered() end)
    if ok and answered then
      break
    end
    session:sleep(500)
  else
    session:sleep(2000)
  end
end
LUAEOF

cat > /etc/freeswitch/scripts/skykin_cc_prune.lua << 'PRUNEEOF'
-- Before callcenter: skip unregistered agents; clear stale ready_time so
-- the one registered agent rings instead of the call blocking on offline legs.
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
    local uuid, contact, status = c[1] or "", c[5] or "", c[6] or ""
    local ext = contact:match("user/([^@]+)")
    if uuid ~= "" and ext then
      if registered(ext) then
        api:execute("callcenter_config", "agent set wrap_up_time " .. uuid .. " 0")
        api:execute("callcenter_config", "agent set ready_time " .. uuid .. " 0")
        if status == "Available" then
          api:execute("callcenter_config", "agent set state " .. uuid .. " Waiting")
        end
        freeswitch.consoleLog("NOTICE", "skykin_cc_prune keep " .. ext .. "@" .. domain .. "\n")
      else
        api:execute("callcenter_config", "agent set status " .. uuid .. " Logged Out")
        freeswitch.consoleLog("NOTICE", "skykin_cc_prune skip " .. ext .. " not registered\n")
      end
    end
  end
end
PRUNEEOF

cat > /etc/freeswitch/scripts/skykin_cc_watch.lua << 'WATCHEOF'
-- Decline drops the customer call (BYE). Not Busy, not re-queue.
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
WATCHEOF

cat > /etc/freeswitch/scripts/skykin_cc_drop.lua << 'DROPEOF'
local api = freeswitch.API()
local function var(name)
  if not session then return "" end
  local v = session:getVariable(name)
  if v and v ~= "" and v ~= "_undef_" then return v end
  return ""
end
local ok, answered = pcall(function() return session and session:answered() end)
if ok and answered then return end
local cause = string.upper(var("hangup_cause") .. " " .. var("proto_specific_hangup_cause") .. " " .. (argv[1] or ""))
if cause:find("NO_ANSWER", 1, true) or cause:find("ALLOTTED", 1, true)
    or cause:find("USER_NOT_REGISTERED", 1, true) then
  freeswitch.consoleLog("NOTICE", "skykin drop skip cause=" .. cause .. "\n")
  return
end
local member = var("cc_member_session_uuid")
if member == "" then member = var("originating_leg_uuid") end
if member == "" then member = var("cc_member_uuid") end
if member == "" then member = var("signal_bond") end
if member == "" then
  freeswitch.consoleLog("NOTICE", "skykin drop no-member cause=" .. cause .. "\n")
  return
end
freeswitch.consoleLog("NOTICE", "skykin drop caller=" .. member .. " cause=" .. cause .. "\n")
api:execute("uuid_kill", member .. " NORMAL_CLEARING")
DROPEOF

cat > /etc/freeswitch/scripts/skykin_bl_gate.lua << 'GATEEOF'
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
GATEEOF

cat > /etc/freeswitch/scripts/skykin_bl_hash.lua << 'HASHEOF'
-- Load domain-scoped blacklist hash keys from the shared file on FreeSWITCH start.
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
HASHEOF

cat > /etc/freeswitch/scripts/skykin_inbound.lua << 'INBOUNDEOF'
-- Ring the longest-idle Ready registered agent who is not already on a call.
-- Decline ends this customer call (does not roll to the next agent).
-- If every Ready agent is busy, park in callcenter so the caller waits.
if not session then
  return
end

local api = freeswitch.API()
local domain = session:getVariable("domain_name") or "client1.skykin.local"
local cid = session:getVariable("caller_id_number")
    or session:getVariable("ani")
    or session:getVariable("sip_from_user")
    or session:getVariable("effective_caller_id_number")
    or session:getVariable("sip_p_asserted_identity")
    or session:getVariable("sip_cid_num")
    or ""
if session:getVariable("skykin_blocked") == "true" or not session:ready() then
  session:execute("hangup", "CALL_REJECTED")
  return
end
local function bl_digits(s)
  s = (s or ""):gsub("%D", "")
  if s:sub(1, 3) == "251" and #s >= 12 then s = s:sub(4) end
  if #s == 10 and s:sub(1, 1) == "0" then s = s:sub(2) end
  return s
end
local function bl_file_hit(want, path)
  if domain == "" then return false end
  local f = io.open(path, "r")
  if not f then return false end
  for line in f:lines() do
    if line:sub(1, 1) ~= "#" and line ~= "" then
      local a, b = line:match("^([^|]+)|([^|]+)")
      if a == domain then
        local n = bl_digits(b or "")
        if n ~= "" then
          local k = math.min(#want, #n, 12)
          if k >= 7 and want:sub(-k) == n:sub(-k) then
            f:close()
            return true
          end
        end
      end
    end
  end
  f:close()
  return false
end
local function blacklisted()
  local want = bl_digits(cid)
  if #want < 7 or domain == "" then return false end
  local keys = { want, want:sub(-9), want:sub(-8), want:sub(-7), "251" .. want, "0" .. want }
  for _, key in ipairs(keys) do
    if #key >= 7 then
      local h = api:execute("hash", "select/skykin_bl/" .. domain .. "~" .. key) or ""
      h = h:gsub("%s+$", "")
      if h == "1" or h:match("^1%s") then
        return true
      end
    end
  end
  return bl_file_hit(want, "/etc/freeswitch/scripts/skykin_blacklist.txt")
      or bl_file_hit(want, "/var/lib/freeswitch/recordings/skykin_blacklist.txt")
end
if blacklisted() then
  freeswitch.consoleLog("NOTICE", "skykin blacklist drop cid=" .. cid .. "\n")
  session:setVariable("continue_on_fail", "false")
  session:setVariable("skykin_blocked", "true")
  session:execute("hangup", "CALL_REJECTED")
  error("skykin blocked")
end

local queue = "8000@" .. domain
local rtp_ip = "196.189.236.140"

session:execute("ring_ready")
session:setVariable("ringback", "${us-ring}")
session:setVariable("instant_ringback", "true")
session:setVariable("hangup_after_bridge", "true")
session:setVariable("continue_on_fail", "true")
session:setVariable("ignore_early_media", "true")
session:setVariable("bridge_early_media", "false")

local function cols(line)
  local c = {}
  for x in (line .. "|"):gmatch("(.-)|") do c[#c + 1] = x end
  return c
end

local function registered(ext)
  local r = api:execute("sofia_contact", "*/" .. ext .. "@" .. domain) or ""
  if r:find("error", 1, true) or r:find("user_not_registered", 1, true) then
    return false
  end
  return r:find("sip:", 1, true) ~= nil
end

local function on_a_call(ext)
  local chans = api:execute("show", "channels") or ""
  return chans:find("user/" .. ext .. "@" .. domain, 1, true) ~= nil
      or chans:find("/" .. ext .. "@" .. domain, 1, true) ~= nil
end

local function ready_agents(skip)
  local out = api:execute("callcenter_config", "queue list agents " .. queue) or ""
  local rows = {}
  for line in out:gmatch("[^\r\n]+") do
    if line:find("|", 1, true) and line:sub(1, 5) ~= "name|" then
      local c = cols(line)
      local ext = (c[5] or ""):match("user/([^@]+)")
      local status, state = c[6] or "", c[7] or ""
      if ext and not skip[ext]
          and status == "Available" and (state == "Waiting" or state == "Idle")
          and registered(ext) and not on_a_call(ext) then
        rows[#rows + 1] = { ext = ext, idle = tonumber(c[20]) or tonumber(c[14]) or 0 }
      end
    end
  end
  table.sort(rows, function(a, b) return a.idle < b.idle end)
  return rows
end

local skip = {}
while session:ready() do
  local agents = ready_agents(skip)
  local dest = agents[1] and agents[1].ext
  if not dest then
    freeswitch.consoleLog("NOTICE", "skykin inbound queue wait " .. queue .. "\n")
    session:execute("callcenter", queue)
    return
  end
  local bridge =
    "{ignore_early_media=true,bridge_early_media=false,originate_timeout=45}" ..
    "[leg_timeout=30,media_webrtc=true,rtp_secure_media=optional,rtp_advertise_ip=" ..
    rtp_ip .. ",include_external_ip=true]user/" .. dest .. "@" .. domain
  freeswitch.consoleLog("NOTICE", "skykin inbound try " .. dest .. "@" .. domain .. "\n")
  session:execute("bridge", bridge)
  if not session:ready() then
    return
  end
  local ok, answered = pcall(function() return session:answered() end)
  if ok and answered then
    return
  end
  local cause = string.upper(session:getVariable("last_bridge_hangup_cause")
      or session:getVariable("originate_disposition") or "")
  local sip = session:getVariable("sip_invite_failure_status") or ""
  freeswitch.consoleLog("NOTICE", "skykin inbound cause=" .. cause
    .. " sip=" .. sip .. " dest=" .. dest .. "\n")
  if sip == "486" or cause:find("USER_BUSY", 1, true)
      or cause:find("NO_ANSWER", 1, true) or cause:find("ALLOTTED", 1, true)
      or cause:find("USER_NOT_REGISTERED", 1, true) or sip == "408" then
    skip[dest] = true
    session:sleep(200)
  else
    freeswitch.consoleLog("NOTICE", "skykin inbound decline drop dest=" .. dest .. "\n")
    session:hangup("NORMAL_CLEARING")
    return
  end
end
INBOUNDEOF




# Inbound DIDs from the SIP trunk land in context "public".
if [ -n "$FS_INBOUND_DID_REGEX" ] && [ -n "$FS_DOMAIN" ]; then
  mkdir -p /etc/freeswitch/dialplan/public
  cat > /etc/freeswitch/dialplan/public/01_skykin_did.xml <<DIDEOF
<include>
  <extension name="skykin_inbound_did">
    <condition field="destination_number" expression="${FS_INBOUND_DID_REGEX}" break="on-false">
      <action application="set" data="rtcp_audio_interval_msec=0"/>
      <action application="set" data="rtp_advertise_ip=${FS_LAN_RTP_IP}"/>
      <action application="set" data="include_external_ip=false"/>
      <action application="set" data="rtp_secure_media=false"/>
      <action application="set" data="media_webrtc=false"/>
      <action application="set" data="domain_name=${FS_DOMAIN}"/>
      <action application="export" data="domain_name=${FS_DOMAIN}"/>
      <action application="set" data="call_direction=inbound"/>
      <action application="export" data="call_direction=inbound"/>
      <action application="set" data="hangup_after_bridge=true"/>
      <action application="set" data="continue_on_fail=false"/>
      <action application="set" data="ignore_early_media=true"/>
      <action application="set" data="bridge_early_media=false"/>
      <action application="set" data="originate_early_media=false"/>
      <action application="set" data="cc_moh_override="/>
      <action application="set" data="record_stereo=true"/>
      <action application="set" data="record_path=/var/lib/freeswitch/recordings/${FS_DOMAIN}/archive/\${strftime(%Y)}/\${strftime(%b)}/\${strftime(%d)}"/>
      <action application="set" data="record_name=\${uuid}.wav"/>
      <action application="set" data="execute_on_answer=record_session \${record_path}/\${record_name}"/>
      <action application="set" data="instant_ringback=true"/>
      <action application="lua" data="/etc/freeswitch/scripts/skykin_cc_prune.lua ${FS_QUEUE_EXT}@${FS_DOMAIN}"/>
      <action application="export" data="nolocal:execute_on_hangup=lua::/etc/freeswitch/scripts/skykin_cc_drop.lua"/>
      <action application="set" data="cc_export_vars=execute_on_hangup"/>
      <action application="lua" data="/etc/freeswitch/scripts/skykin_bl_gate.lua"/>
    </condition>
    <condition field="${skykin_blocked}" expression="^true$" break="on-true">
      <action application="hangup" data="CALL_REJECTED"/>
    </condition>
    <condition>
      <action application="ring_ready"/>
      <action application="lua" data="/etc/freeswitch/scripts/skykin_inbound.lua"/>
    </condition>
  </extension>
</include>
DIDEOF
  echo "  Inbound DID ${FS_INBOUND_DID_REGEX} -> queue ${FS_QUEUE_EXT}@${FS_DOMAIN}"
fi

# Second tenant (ahununu / 757-759): same queue + waiting list as client1.
FS_DOMAIN2="${FS_DOMAIN2:-}"
FS_INBOUND_DID2_REGEX="${FS_INBOUND_DID2_REGEX:-}"
if [ -n "$FS_DOMAIN2" ] && [ -n "$FS_INBOUND_DID2_REGEX" ]; then
  mkdir -p /etc/freeswitch/dialplan/public
  cat > /etc/freeswitch/dialplan/public/02_skykin_did_${FS_DOMAIN2}.xml <<DID2EOF
<include>
  <extension name="skykin_inbound_did_${FS_DOMAIN2}">
    <condition field="destination_number" expression="${FS_INBOUND_DID2_REGEX}" break="on-false">
      <action application="set" data="rtcp_audio_interval_msec=0"/>
      <action application="set" data="rtp_advertise_ip=${FS_LAN_RTP_IP}"/>
      <action application="set" data="include_external_ip=false"/>
      <action application="set" data="rtp_secure_media=false"/>
      <action application="set" data="media_webrtc=false"/>
      <action application="set" data="domain_name=${FS_DOMAIN2}"/>
      <action application="export" data="domain_name=${FS_DOMAIN2}"/>
      <action application="set" data="call_direction=inbound"/>
      <action application="set" data="hangup_after_bridge=true"/>
      <action application="set" data="continue_on_fail=false"/>
      <action application="set" data="ignore_early_media=true"/>
      <action application="set" data="bridge_early_media=false"/>
      <action application="set" data="originate_early_media=false"/>
      <action application="set" data="cc_moh_override="/>
      <action application="set" data="record_stereo=true"/>
      <action application="set" data="record_path=/var/lib/freeswitch/recordings/${FS_DOMAIN2}/archive/\${strftime(%Y)}/\${strftime(%b)}/\${strftime(%d)}"/>
      <action application="set" data="record_name=\${uuid}.wav"/>
      <action application="set" data="execute_on_answer=record_session \${record_path}/\${record_name}"/>
      <action application="set" data="instant_ringback=true"/>
      <action application="lua" data="/etc/freeswitch/scripts/skykin_cc_prune.lua ${FS_QUEUE_EXT}@${FS_DOMAIN2}"/>
      <action application="export" data="nolocal:execute_on_hangup=lua::/etc/freeswitch/scripts/skykin_cc_drop.lua"/>
      <action application="set" data="cc_export_vars=execute_on_hangup"/>
      <action application="lua" data="/etc/freeswitch/scripts/skykin_bl_gate.lua"/>
    </condition>
    <condition field="${skykin_blocked}" expression="^true$" break="on-true">
      <action application="hangup" data="CALL_REJECTED"/>
    </condition>
    <condition>
      <action application="ring_ready"/>
      <action application="lua" data="/etc/freeswitch/scripts/skykin_inbound.lua"/>
    </condition>
  </extension>
</include>
DID2EOF
  echo "  Inbound DID ${FS_INBOUND_DID2_REGEX} -> skykin_inbound.lua (welcome inside lua)"
fi

# Remove vanilla demo dialplans that hijack agent extensions. The stock
# 00_ladspa.xml defines extension "101" (autotalent toy) and bridges to the
# public 888@conference.freeswitch.org, so calls to 101 drop with
# DESTINATION_OUT_OF_ORDER before SkyKin's 1xx rule is reached.
for demo in 00_ladspa.xml 00_pizza_demo.xml 01_Talking_Clock.xml 01_example.com.xml; do
  rm -f "/etc/freeswitch/dialplan/default/$demo"
done
# Vanilla del-group/add-group match ^80xx and steal queue 8000 (beep + hangup).
if [ -f /etc/freeswitch/dialplan/default.xml ]; then
  sed -i 's/name="del-group"/name="del-group-disabled"/; s/name="add-group"/name="add-group-disabled"/' \
    /etc/freeswitch/dialplan/default.xml || true
  sed -i 's#expression="^80(\\d{2})$"#expression="^80xx-disabled$"#g' \
    /etc/freeswitch/dialplan/default.xml || true
fi

# Recordings live on a volume shared with the web container, where PHP runs as
# www-data. FreeSWITCH creates the archive date directories mode 0750 owned by
# its own user, so www-data cannot even traverse them: the Recordings tab lists
# nothing and playback 404s while the .wav files sit on disk. Hand the tree to
# www-data's group and set the setgid bit, which Linux propagates to every
# subdirectory FreeSWITCH creates later (including tomorrow's date folder), so
# group read/traverse survives without a nightly chmod.
REC_ROOT=/var/lib/freeswitch/recordings
WWW_GID="${WWW_DATA_GID:-33}"
if [ -d "$REC_ROOT" ]; then
  [ -n "$FS_DOMAIN" ] && mkdir -p "$REC_ROOT/${FS_DOMAIN}/archive"
  chgrp -R "$WWW_GID" "$REC_ROOT" 2>/dev/null || true
  find "$REC_ROOT" -type d -exec chmod g+rwxs {} + 2>/dev/null || true
  find "$REC_ROOT" -type f -exec chmod g+rw {} + 2>/dev/null || true
  touch "$REC_ROOT/skykin_blacklist.txt" 2>/dev/null || true
  chmod 666 "$REC_ROOT/skykin_blacklist.txt" 2>/dev/null || true
fi

# Image Lua (repo docker/freeswitch/scripts) overwrites stale heredocs above.
if [ -d /opt/skykin/fs-scripts ]; then
  mkdir -p /etc/freeswitch/scripts
  cp -a /opt/skykin/fs-scripts/. /etc/freeswitch/scripts/
  echo "  Installed SkyKin Lua from image /opt/skykin/fs-scripts"
fi

# Host overlay from the live backup. Must run last so a recreate keeps
# working DID XML, Ethio gateways (SIP/SIP2/SIP757-759), and internal.xml.
# Populate on ecs-cc: cp -a /opt/skykin/backups/fs-YYYYMMDD-*/live/. /opt/skykin/fs-config/
LIVE=/opt/skykin/fs-live
if [ -d "$LIVE" ] && [ -f "$LIVE/skykin_inbound.lua" ]; then
  echo "  Overlaying live FreeSWITCH config from $LIVE"
  mkdir -p /etc/freeswitch/scripts \
    /etc/freeswitch/dialplan/public \
    /etc/freeswitch/sip_profiles/external \
    /etc/freeswitch/autoload_configs
  for f in skykin_inbound.lua skykin_welcome.lua skykin_cc_drop.lua skykin_bl_gate.lua skykin_bl_hash.lua; do
    [ -f "$LIVE/$f" ] && cp "$LIVE/$f" /etc/freeswitch/scripts/
  done
  [ -f "$LIVE/01_skykin_did.xml" ] && cp "$LIVE/01_skykin_did.xml" /etc/freeswitch/dialplan/public/
  [ -f "$LIVE/02_skykin_did_ahununu.xml" ] && cp "$LIVE/02_skykin_did_ahununu.xml" /etc/freeswitch/dialplan/public/
  [ -f "$LIVE/01_skykin_ahununu.xml" ] && cp "$LIVE/01_skykin_ahununu.xml" /etc/freeswitch/dialplan/
  [ -f "$LIVE/01_skykin_client1.skykin.local.xml" ] && cp "$LIVE/01_skykin_client1.skykin.local.xml" /etc/freeswitch/dialplan/
  [ -f "$LIVE/internal.xml" ] && cp "$LIVE/internal.xml" /etc/freeswitch/sip_profiles/
  [ -f "$LIVE/external.xml" ] && cp "$LIVE/external.xml" /etc/freeswitch/sip_profiles/
  for g in SIP.xml SIP2.xml SIP757.xml SIP758.xml SIP759.xml SIP8035.xml SIP8036.xml SIP8037.xml SIP8038.xml SIP8039.xml; do
    [ -f "$LIVE/$g" ] && cp "$LIVE/$g" /etc/freeswitch/sip_profiles/external/
  done
  [ -f "$LIVE/modules.conf.xml" ] && cp "$LIVE/modules.conf.xml" /etc/freeswitch/autoload_configs/
  [ -f "$LIVE/xml_cdr.conf.xml" ] && cp "$LIVE/xml_cdr.conf.xml" /etc/freeswitch/autoload_configs/
  [ -f "$LIVE/callcenter.conf.xml" ] && cp "$LIVE/callcenter.conf.xml" /etc/freeswitch/autoload_configs/
fi

echo "SkyKin FreeSWITCH starting"
echo "  ESL: ${ESL_LISTEN_IP}:8021"
echo "  WS:  :5066"
echo "  RTP: ${RTP_START}-${RTP_END}/udp"
[ -n "$FS_DOMAIN" ] && echo "  Domain: ${FS_DOMAIN}"
[ -n "$EXTERNAL_SIP_IP" ] && echo "  Ext SIP/RTP: ${EXTERNAL_SIP_IP} / ${EXTERNAL_RTP_IP}"
[ -n "$FS_QUEUE_AGENTS" ] && echo "  Queue: ${FS_QUEUE_EXT}@${FS_DOMAIN} agents=${FS_QUEUE_AGENTS}"

FS_BIN="$(command -v freeswitch || true)"
if [ -z "$FS_BIN" ]; then
  for c in /usr/bin/freeswitch /usr/local/bin/freeswitch /usr/local/freeswitch/bin/freeswitch; do
    if [ -x "$c" ]; then FS_BIN="$c"; break; fi
  done
fi
if [ -z "$FS_BIN" ]; then
  echo "ERROR: freeswitch binary not found in image" >&2
  ls -la /usr/bin/freeswitch /usr/local/bin/freeswitch 2>&1 || true
  exit 1
fi

# -nc = no console, -nf = no fork, -nonat = skip auto-NAT (Docker).
# SCHED_FIFO / nice warnings in Docker are expected and non-fatal.
#
# Deliberately NOT -nosql: that flag disables the core database, and every
# command that reads it answers "-ERR SQL disabled, no data available!". The
# supervisor dashboard is built on those commands (show registrations, show
# channels), so it showed no agents online, no active calls, and call monitoring
# could not find the channel to eavesdrop on. The core DB is a local sqlite file
# under /var/lib/freeswitch/db, so keeping it on costs nothing external.
exec "$FS_BIN" -nonat -nf -nc
