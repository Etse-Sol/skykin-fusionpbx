#!/bin/bash
# Revert ecs-cc to state BEFORE the curl deploy (skykin_config + add_e164 + .env sed).
# Run as root on ecs-cc.
set -eu

APP="/opt/skykin/app"
DASH="$APP/app/agent_dashboard"
PW=$(grep -E '^ESL_PASSWORD=' "$APP/.env" | cut -d= -f2-)
FS() { docker exec skykin-freeswitch fs_cli -H 127.0.0.1 -P 8021 -p "$PW" -x "$1"; }

echo "=== 1) Restore dashboard PHP from .bak (newest backup per file) ==="
for f in skykin_config.php supervisor.php reports.php index.php data.php; do
  BAK=$(ls -t "$DASH/${f}.bak-"* 2>/dev/null | head -1 || true)
  if [ -z "$BAK" ]; then
    echo "WARN: no backup for $f — use git fallback below"
    continue
  fi
  cp -a "$BAK" "$DASH/$f"
  docker cp "$DASH/$f" "skykin-web:/var/www/fusionpbx/app/agent_dashboard/$f"
  echo "  restored $f <= $(basename "$BAK")"
done

echo "=== 2) Revert .env inbound regex (pre-deploy was 757-759 line) ==="
if grep -q '^FS_INBOUND_DID2_REGEX=' "$APP/.env"; then
  sed -i 's|^FS_INBOUND_DID2_REGEX=.*|FS_INBOUND_DID2_REGEX=^\\+?(?:251)?0?11113875[789]$|' "$APP/.env"
else
  echo 'FS_INBOUND_DID2_REGEX=^\+?(?:251)?0?11113875[789]$' >> "$APP/.env"
fi
grep FS_INBOUND_DID2_REGEX "$APP/.env"
echo "NOTE: live inbound uses 02_skykin_did_ahununu.xml (035-039). .env only matters on FS recreate."

echo "=== 3) Revert ahununu outbound dialplan (remove +251/landline patch) ==="
RESTORED=0
for CAND in \
  /opt/skykin/backups/fs-*/live/01_skykin_ahununu.xml \
  /opt/skykin/fs-live/01_skykin_ahununu.xml; do
  if [ -f "$CAND" ]; then
    docker cp "$CAND" skykin-freeswitch:/etc/freeswitch/dialplan/01_skykin_ahununu.xml
    echo "  dialplan from $CAND"
    RESTORED=1
    break
  fi
done

if [ "$RESTORED" -eq 0 ]; then
  echo "  no fs backup — stripping normalize/land/e164 via python"
  docker cp skykin-freeswitch:/etc/freeswitch/dialplan/01_skykin_ahununu.xml /tmp/01_skykin_ahununu.xml
  python3 << 'PY'
import re
from pathlib import Path
p = Path("/tmp/01_skykin_ahununu.xml")
t = p.read_text()
for name in (
    "skykin_outbound_et_normalize_mobile",
    "skykin_outbound_et_normalize_land",
    "skykin_outbound_et_land",
    "skykin_outbound_et_e164",
):
    t2, n = re.subn(
        rf'\s*<extension name="{name}">.*?</extension>\s*',
        '\n',
        t,
        count=1,
        flags=re.DOTALL,
    )
    if n:
        t = t2
        print("removed", name)
p.write_text(t)
PY
  docker cp /tmp/01_skykin_ahununu.xml skykin-freeswitch:/etc/freeswitch/dialplan/01_skykin_ahununu.xml
fi

FS "reloadxml"

echo "=== 4) Re-sync blacklist (clear stale hash blocks) ==="
docker exec skykin-web php /var/www/fusionpbx/app/agent_dashboard/skykin_bl_sync.php 2>/dev/null || true

echo "=== 5) Verify ==="
grep -c skykinNormalizeEtDial "$DASH/skykin_config.php" 2>/dev/null || echo "skykinNormalizeEtDial: 0 (gone)"
docker exec skykin-freeswitch grep -c normalize /etc/freeswitch/dialplan/01_skykin_ahununu.xml || echo "normalize: 0"
FS "sofia_contact */201@ahununu" | head -3

echo "DONE — Ctrl+Shift+R on dashboard. Test inbound: 201 Ready, call 251116198035"
