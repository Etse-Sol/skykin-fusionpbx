#!/bin/bash
# Deploy SkyKin favicon on ecs-cc (agent_dashboard is bind-mounted; login needs docker cp or compose volumes)
set -eu

ROOT="${SKYKIN_FAVICON_REF:-https://raw.githubusercontent.com/Etse-Sol/skykin-fusionpbx/5.5}"
APP="${SKYKIN_APP_ROOT:-/opt/skykin/app}"
BASE="$ROOT/app/agent_dashboard"
VIEWS="$APP/core/authentication/resources/views"

echo "==> Deploy favicon assets to $APP (bind-mounted agent_dashboard)"

mkdir -p "$APP/app/agent_dashboard/assets" "$VIEWS"

curl -fSL -o "$APP/app/agent_dashboard/assets/skykin-favicon.png" "$BASE/assets/skykin-favicon.png"
curl -fSL -o "$APP/app/agent_dashboard/assets/favicon.ico" "$BASE/assets/favicon.ico"
curl -fSL -o "$APP/app/agent_dashboard/assets/apple-touch-icon.png" "$BASE/assets/apple-touch-icon.png"
curl -fSL -o "$APP/favicon.png" "$ROOT/favicon.png"
curl -fSL -o "$APP/favicon.ico" "$ROOT/favicon.ico"

curl -fSL -o "$APP/app/agent_dashboard/skykin_config.php" "$BASE/skykin_config.php"
curl -fSL -o "$APP/app/agent_dashboard/session_bootstrap.php" "$BASE/session_bootstrap.php"
curl -fSL -o "$APP/app/agent_dashboard/index.php" "$BASE/index.php"
curl -fSL -o "$APP/login.php" "$ROOT/login.php"
curl -fSL -o "$VIEWS/login.htm" "$ROOT/core/authentication/resources/views/login.htm"
curl -fSL -o "$APP/resources/skykin_favicon.php" "$ROOT/resources/skykin_favicon.php"
curl -fSL -o "$APP/resources/require.php" "$ROOT/resources/require.php"

echo "==> Copy into skykin-web container (until compose volumes are added)"
if docker ps --format '{{.Names}}' | grep -qx skykin-web; then
  docker cp "$APP/app/agent_dashboard/assets/skykin-favicon.png" skykin-web:/var/www/fusionpbx/app/agent_dashboard/assets/skykin-favicon.png
  docker cp "$APP/app/agent_dashboard/assets/favicon.ico" skykin-web:/var/www/fusionpbx/app/agent_dashboard/assets/favicon.ico
  docker cp "$APP/app/agent_dashboard/assets/apple-touch-icon.png" skykin-web:/var/www/fusionpbx/app/agent_dashboard/assets/apple-touch-icon.png
  docker cp "$APP/favicon.png" skykin-web:/var/www/fusionpbx/favicon.png
  docker cp "$APP/favicon.ico" skykin-web:/var/www/fusionpbx/favicon.ico
  docker cp "$VIEWS/login.htm" skykin-web:/var/www/fusionpbx/core/authentication/resources/views/login.htm
  docker cp "$APP/login.php" skykin-web:/var/www/fusionpbx/login.php
  docker cp "$APP/resources/skykin_favicon.php" skykin-web:/var/www/fusionpbx/resources/skykin_favicon.php
  docker cp "$APP/resources/require.php" skykin-web:/var/www/fusionpbx/resources/require.php
  docker cp "$APP/app/agent_dashboard/skykin_config.php" skykin-web:/var/www/fusionpbx/app/agent_dashboard/skykin_config.php
  docker cp "$APP/app/agent_dashboard/index.php" skykin-web:/var/www/fusionpbx/app/agent_dashboard/index.php
fi

echo "==> Verify"
test -s "$APP/app/agent_dashboard/assets/skykin-favicon.png" && echo "OK skykin-favicon.png"
docker exec skykin-web test -s /var/www/fusionpbx/app/agent_dashboard/assets/skykin-favicon.png && echo "OK in container"
grep -c 'skykin-favicon.png?v=5' "$VIEWS/login.htm" && echo "OK login.htm"

echo "Done. Open Incognito and test login + agent tabs."
