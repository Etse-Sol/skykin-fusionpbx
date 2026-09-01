#!/bin/bash
# Run on ecs-cc as root. Deploys dashboard + ahununu +251/landline dialplan from CC branch 5.5.
set -euo pipefail

BRANCH=5.5
REPO_BASE="https://raw.githubusercontent.com/Etse-Sol/skykin-fusionpbx/${BRANCH}"
DASH=/opt/skykin/app/app/agent_dashboard
ENV=/opt/skykin/app/.env
STAMP=$(date +%Y%m%d_%H%M%S)

curl_get() {
  local url="$1" out="$2"
  if [ -n "${GITHUB_TOKEN:-}" ]; then
    curl -fsSL -H "Authorization: Bearer ${GITHUB_TOKEN}" -o "$out" "$url"
  else
    curl -fsSL -o "$out" "$url"
  fi
}

echo "=== 1) Dashboard PHP ==="
for f in skykin_config.php supervisor.php reports.php index.php data.php; do
  [ -f "$DASH/$f" ] && cp -a "$DASH/$f" "$DASH/$f.bak-$STAMP"
  curl_get "$REPO_BASE/app/agent_dashboard/$f" "$DASH/$f"
  echo "  $f"
done

echo "=== 2) .env — ahununu inbound DID 035-039 ==="
if grep -q '^FS_INBOUND_DID2_REGEX=' "$ENV"; then
  sed -i 's|^FS_INBOUND_DID2_REGEX=.*|FS_INBOUND_DID2_REGEX=^\\+?(?:251)?11619803[5-9]$|' "$ENV"
else
  echo 'FS_INBOUND_DID2_REGEX=^\+?(?:251)?11619803[5-9]$' >> "$ENV"
fi
grep FS_INBOUND_DID2_REGEX "$ENV"

echo "=== 3) Dialplan patch (+251 / landline → SIP8035) ==="
curl_get "$REPO_BASE/add_e164_ahununu.py" /tmp/add_e164_ahununu.py
docker cp /tmp/add_e164_ahununu.py skykin-freeswitch:/tmp/add_e164_ahununu.py
docker exec skykin-freeswitch python3 /tmp/add_e164_ahununu.py
docker exec skykin-freeswitch fs_cli -x "reloadxml"

echo "=== 4) Verify ==="
docker exec skykin-freeswitch grep -E 'normalize|et_land|et_e164|SIP8035' \
  /etc/freeswitch/dialplan/01_skykin_ahununu.xml | head -20

echo "=== DONE ==="
echo "Hard-refresh dashboard (Ctrl+Shift+R). Test outbound: 0912..., 251912..., 0111234567"
