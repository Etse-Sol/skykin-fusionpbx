#!/bin/sh
# Stop xml_cdr 301s to hostname "web" after FreeSWITCH is on host net.
#
# The call already succeeded (NORMAL_CLEARING). This only fixes hangup
# records posting to FusionPBX. Does NOT restart Sofia — agent REGISTER
# stays in memory.
#
# Run on flipstar-app-server:
#   chmod +x docker/freeswitch/fix-cdr-url.sh
#   ./docker/freeswitch/fix-cdr-url.sh
set -eu

CONTAINER="${FREESWITCH_CONTAINER:-skykin-freeswitch}"
CDR_URL="${CDR_URL:-https://127.0.0.1:8088/app/xml_cdr/xml_cdr_import.php}"

if ! docker inspect "$CONTAINER" >/dev/null 2>&1; then
  echo "ERROR: container $CONTAINER is not running" >&2
  exit 1
fi

echo "=== current xml_cdr url ==="
docker exec -i "$CONTAINER" sh -c 'grep -n "name=\"url\"" /etc/freeswitch/autoload_configs/xml_cdr.conf.xml || true'

docker exec -i "$CONTAINER" sh -c "
f=/etc/freeswitch/autoload_configs/xml_cdr.conf.xml
cp -a \"\$f\" \"\$f.bak-cdr\"
# FusionPBX live file posts to http://web/... which 301s to HTTPS.
sed -i 's#http://web/app/xml_cdr/xml_cdr_import.php#${CDR_URL}#g' \"\$f\"
sed -i 's#http://web/#${CDR_URL}#g' \"\$f\"
if grep -q 'name=\"url\"' \"\$f\"; then
  sed -i 's#<param name=\"url\" value=\"[^\"]*\"#<param name=\"url\" value=\"${CDR_URL}\"#' \"\$f\"
  sed -i 's#<!--[[:space:]]*<param name=\"url\" value=\"[^\"]*\"/>[[:space:]]*-->#<param name=\"url\" value=\"${CDR_URL}\"/>#' \"\$f\"
fi
# Dashboard cert is self-signed; default cacert-check is already off.
grep -n 'name=\"url\"\\|cacert\\|ssl-verify' \"\$f\" || true
"

echo "=== reload xml_cdr (not Sofia) ==="
docker exec -i "$CONTAINER" fs_cli -x 'reloadxml'
docker exec -i "$CONTAINER" fs_cli -x 'reload mod_xml_cdr'

echo
echo "Sofia registrations were not touched."
echo "Next hangup should post to ${CDR_URL} without [ERR] Got error [301]."
echo "Old failed CDRs are under /var/log/freeswitch/xml_cdr/ (or err-log-dir)."
