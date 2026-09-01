#!/bin/bash
# Restore agent_dashboard PHP to pre-deploy backups on ecs-cc.
# Run as root. Does NOT touch FreeSWITCH dialplan unless RESTORE_DIALPLAN=1.
set -eu

DASH="/opt/skykin/app/app/agent_dashboard"
ENV="/opt/skykin/app/.env"

echo "=== Backups found ==="
ls -lt "$DASH"/*.bak-* 2>/dev/null | head -20 || echo "(none)"

restored=0
for f in skykin_config.php supervisor.php reports.php index.php data.php; do
  BAK=$(ls -t "$DASH/${f}.bak-"* 2>/dev/null | head -1 || true)
  if [ -z "$BAK" ]; then
    echo "SKIP $f — no .bak file"
    continue
  fi
  cp -a "$BAK" "$DASH/$f"
  if docker ps --format '{{.Names}}' | grep -qx skykin-web; then
    docker cp "$DASH/$f" "skykin-web:/var/www/fusionpbx/app/agent_dashboard/$f"
  fi
  echo "OK  $f  <=  $BAK"
  restored=$((restored + 1))
done

if [ "$restored" -eq 0 ]; then
  echo "No backups restored. Try git commit rollback (see below)."
  exit 1
fi

# Optional: revert .env inbound regex only if you had 757-759 before (NOT recommended on 035-039 trunks)
if [ "${RESTORE_ENV_REGEX:-0}" = "1" ]; then
  sed -i 's|^FS_INBOUND_DID2_REGEX=.*|FS_INBOUND_DID2_REGEX=^\\+?(?:251)?0?11113875[789]$|' "$ENV"
  echo "WARN: .env regex reverted to old 757-759 — wrong if you use 035-039 DIDs"
fi

# Optional: restore ahununu outbound dialplan from fs backup
if [ "${RESTORE_DIALPLAN:-0}" = "1" ]; then
  LIVE=$(ls -td /opt/skykin/backups/fs-*/live/01_skykin_ahununu.xml 2>/dev/null | head -1 || true)
  if [ -n "$LIVE" ] && [ -f "$LIVE" ]; then
    docker cp "$LIVE" skykin-freeswitch:/etc/freeswitch/dialplan/01_skykin_ahununu.xml
    PW=$(grep -E '^ESL_PASSWORD=' "$ENV" | cut -d= -f2-)
    docker exec skykin-freeswitch fs_cli -H 127.0.0.1 -P 8021 -p "$PW" -x "reloadxml"
    echo "OK dialplan from $LIVE"
  else
    echo "No fs backup dialplan found under /opt/skykin/backups/"
  fi
fi

echo "=== Re-sync blacklist hash (clears stale blocks) ==="
if docker ps --format '{{.Names}}' | grep -qx skykin-web; then
  docker exec skykin-web php /var/www/fusionpbx/app/agent_dashboard/skykin_bl_sync.php 2>/dev/null || true
fi

echo "DONE — hard-refresh dashboard Ctrl+Shift+R"
echo "Test inbound: agent 201 Ready, call 8414 or 251116198035"
