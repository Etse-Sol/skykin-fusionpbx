#!/bin/bash
# Deploy SkyKin favicon site-wide on webcc (run as root on ecs-cc)
set -eu

ROOT="${SKYKIN_FAVICON_REF:-https://raw.githubusercontent.com/Etse-Sol/skykin-fusionpbx/5.5}"
APP="${SKYKIN_APP_ROOT:-/opt/skykin/app}"
BASE="$ROOT/app/agent_dashboard"

echo "==> Deploying favicon assets to $APP"

mkdir -p "$APP/app/agent_dashboard/assets"

curl -fSL -o "$APP/favicon.png" "$ROOT/favicon.png"
curl -fSL -o "$APP/favicon.ico" "$ROOT/favicon.ico"
curl -fSL -o "$APP/app/agent_dashboard/assets/apple-touch-icon.png" "$BASE/assets/apple-touch-icon.png"
curl -fSL -o "$APP/app/agent_dashboard/assets/skykin-favicon.png" "$BASE/assets/skykin-favicon.png"

curl -fSL -o "$APP/resources/skykin_favicon.php" "$ROOT/resources/skykin_favicon.php"
curl -fSL -o "$APP/resources/require.php" "$ROOT/resources/require.php"
curl -fSL -o "$APP/resources/footer.php" "$ROOT/resources/footer.php"
curl -fSL -o "$APP/app/agent_dashboard/session_bootstrap.php" "$BASE/session_bootstrap.php"
curl -fSL -o "$APP/app/agent_dashboard/skykin_config.php" "$BASE/skykin_config.php"

for f in index.php supervisor.php crm.php reports.php evaluation.php blacklist.php supervisor_tools.php billing.php tickets.php; do
  curl -fSL -o "$APP/app/agent_dashboard/$f" "$BASE/$f"
done

curl -fSL -o "$APP/themes/default/template.php" "$ROOT/themes/default/template.php"
curl -fSL -o "$APP/themes/skykin/template.php" "$ROOT/themes/skykin/template.php"
curl -fSL -o "$APP/core/authentication/resources/views/login.htm" "$ROOT/core/authentication/resources/views/login.htm"

echo "==> Verify"
test -s "$APP/favicon.png" && echo "OK favicon.png ($(wc -c < "$APP/favicon.png") bytes)"
test -s "$APP/favicon.ico" && echo "OK favicon.ico ($(wc -c < "$APP/favicon.ico") bytes)"
grep -c skykin_favicon_tag "$APP/app/agent_dashboard/index.php" || true
grep -c SKYKIN_FAVICON_HOOK "$APP/resources/skykin_favicon.php" || true

echo "Done. Hard-refresh browser (Ctrl+Shift+R) or use Incognito."
