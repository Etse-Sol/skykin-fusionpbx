#!/bin/bash
# Paste on ecs-cc as root — same curl deploy style as scripts/deploy_favicon.sh
set -eu

ROOT="${SKYKIN_DEPLOY_REF:-https://raw.githubusercontent.com/Etse-Sol/skykin-fusionpbx/5.5}"
APP="${SKYKIN_APP_ROOT:-/opt/skykin/app}"
DASH="$APP/app/agent_dashboard"
STAMP=$(date +%Y%m%d_%H%M%S)
BASE="$ROOT/app/agent_dashboard"

curl_get() {
  local url="$1" out="$2"
  if [ -n "${GITHUB_TOKEN:-}" ]; then
    curl -fSL -H "Authorization: Bearer ${GITHUB_TOKEN}" -o "$out" "$url"
  else
    curl -fSL -o "$out" "$url"
  fi
}

echo "==> Dashboard PHP from $ROOT"
for f in skykin_config.php supervisor.php reports.php index.php data.php; do
  [ -f "$DASH/$f" ] && cp -a "$DASH/$f" "$DASH/$f.bak-$STAMP"
  curl_get "$BASE/$f" "$DASH/$f"
  echo "  $f"
done

echo "==> .env inbound DID 035-039"
if grep -q '^FS_INBOUND_DID2_REGEX=' "$APP/.env"; then
  sed -i 's|^FS_INBOUND_DID2_REGEX=.*|FS_INBOUND_DID2_REGEX=^\\+?(?:251)?11619803[5-9]$|' "$APP/.env"
else
  echo 'FS_INBOUND_DID2_REGEX=^\+?(?:251)?11619803[5-9]$' >> "$APP/.env"
fi
grep FS_INBOUND_DID2_REGEX "$APP/.env"

echo "==> Ahununu dialplan +251 / landline (SIP8035)"
curl_get "$ROOT/add_e164_ahununu.py" /tmp/add_e164_ahununu.py
docker cp /tmp/add_e164_ahununu.py skykin-freeswitch:/tmp/add_e164_ahununu.py
docker exec skykin-freeswitch python3 /tmp/add_e164_ahununu.py
docker exec skykin-freeswitch fs_cli -x "reloadxml"

echo "==> Copy into skykin-web (if running)"
if docker ps --format '{{.Names}}' | grep -qx skykin-web; then
  for f in skykin_config.php supervisor.php reports.php index.php data.php; do
    docker cp "$DASH/$f" "skykin-web:/var/www/fusionpbx/app/agent_dashboard/$f"
  done
fi

echo "==> Verify"
grep -c skykinNormalizeEtDial "$DASH/skykin_config.php" && echo OK skykin_config
docker exec skykin-freeswitch grep -E 'normalize|et_land|et_e164|SIP8035' \
  /etc/freeswitch/dialplan/01_skykin_ahununu.xml | head -10
echo "Done. Hard-refresh dashboard Ctrl+Shift+R"
