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
  /usr/share/freeswitch/scripts

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
    sed -i 's#</settings>#    <param name="xml-handler-script" value="app.lua xml_handler"/>\n    <param name="xml-handler-bindings" value="directory"/>\n  </settings>#' "$LUACONF" || true
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
      printf '    <agent name="%s" type="callback" contact="[leg_timeout=30,media_webrtc=true,rtp_secure_media=optional,rtp_advertise_ip=%s,include_external_ip=true]user/%s@%s" status="Available" max-no-answer="999" wrap-up-time="10" reject-delay-time="10" busy-delay-time="60"/>\n' \
        "$aname" "${EXTERNAL_RTP_IP:-196.189.236.140}" "$aext" "$FS_DOMAIN"
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
    BLEG_RTP="origination_uuid=\${bleg_uuid},absolute_codec_string=^^:AMR@8000h@20i:PCMA:PCMU,amr_octet_align=1,rtcp=-1,rtp_secure_media=false,media_webrtc=false,ignore_early_media=false,sip_session_expires=180,sip_force_session_timer=true"
    if [ -n "$FS_LAN_RTP_IP" ]; then
      BLEG_RTP="${BLEG_RTP},rtp_advertise_ip=${FS_LAN_RTP_IP},include_external_ip=false"
    fi
    if [ -n "$FS_OUTBOUND_CID" ]; then
      BLEG_RTP="${BLEG_RTP},origination_caller_id_number=${FS_OUTBOUND_CID},origination_caller_id_name=${FS_OUTBOUND_CID}"
    fi
    OUT_PRE="${RTP_ADV}
      <action application=\"set\" data=\"hangup_after_bridge=true\"/>
      <action application=\"set\" data=\"continue_on_fail=true\"/>
      <action application=\"set\" data=\"call_timeout=60\"/>
      <action application=\"pre_answer\"/>
      <action application=\"set\" data=\"record_stereo=true\"/>
${CDR_VARS}
      <action application=\"record_session\" data=\"\${record_path}/\${record_name}\"/>
      <action application=\"set\" data=\"bleg_uuid=\${create_uuid()}\"/>"
    SKYKIN_EXTENSIONS="${SKYKIN_EXTENSIONS}

  <extension name=\"skykin_outbound_et_zero\">
    <condition field=\"destination_number\" expression=\"^0([0-9]{9})\$\">
${OUT_PRE}
      <action application=\"bridge\" data=\"{${BLEG_RTP}}sofia/gateway/${FS_OUTBOUND_GATEWAY}/+251\$1\"/>
    </condition>
  </extension>

  <extension name=\"skykin_outbound_et_nozero\">
    <condition field=\"destination_number\" expression=\"^(9[0-9]{8})\$\">
${OUT_PRE}
      <action application=\"bridge\" data=\"{${BLEG_RTP}}sofia/gateway/${FS_OUTBOUND_GATEWAY}/+251\$1\"/>
    </condition>
  </extension>

  <extension name=\"skykin_outbound_et_e164\">
    <condition field=\"destination_number\" expression=\"^\\\\+?(251[0-9]{9})\$\">
${OUT_PRE}
      <action application=\"bridge\" data=\"{${BLEG_RTP}}sofia/gateway/${FS_OUTBOUND_GATEWAY}/+\$1\"/>
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

# Inbound DIDs from the SIP trunk land in context "public".
if [ -n "$FS_INBOUND_DID_REGEX" ] && [ -n "$FS_DOMAIN" ]; then
  mkdir -p /etc/freeswitch/dialplan/public
  cat > /etc/freeswitch/dialplan/public/01_skykin_did.xml <<DIDEOF
<include>
  <extension name="skykin_inbound_did">
    <condition field="destination_number" expression="${FS_INBOUND_DID_REGEX}">
      <action application="set" data="rtcp_audio_interval_msec=0"/>
      <action application="set" data="send_silence_when_idle=100"/>
      <action application="set" data="rtp_advertise_ip=${FS_LAN_RTP_IP}"/>
      <action application="set" data="include_external_ip=false"/>
      <action application="set" data="rtp_secure_media=false"/>
      <action application="set" data="media_webrtc=false"/>
      <action application="set" data="domain_name=${FS_DOMAIN}"/>
      <action application="export" data="domain_name=${FS_DOMAIN}"/>
      <action application="set" data="hangup_after_bridge=true"/>
      <action application="set" data="continue_on_fail=true"/>
      <action application="set" data="cc_moh_override="/>
      <action application="set" data="record_stereo=true"/>
      <action application="set" data="record_path=/var/lib/freeswitch/recordings/${FS_DOMAIN}/archive/\${strftime(%Y)}/\${strftime(%b)}/\${strftime(%d)}"/>
      <action application="set" data="record_name=\${uuid}.wav"/>
      <action application="set" data="execute_on_answer=record_session \${record_path}/\${record_name}"/>
      <action application="callcenter" data="${FS_QUEUE_EXT}@${FS_DOMAIN}"/>
    </condition>
  </extension>
</include>
DIDEOF
  echo "  Inbound DID ${FS_INBOUND_DID_REGEX} -> queue ${FS_QUEUE_EXT}@${FS_DOMAIN}"
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
  find "$REC_ROOT" -type d -exec chmod g+rxs {} + 2>/dev/null || true
  find "$REC_ROOT" -type f -exec chmod g+r {} + 2>/dev/null || true
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
