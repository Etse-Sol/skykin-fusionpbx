# SkyKin Call Center — Smooth Cloud Deployment Runbook

Follow this **in order**. Do not skip the ✅ checks at the end of each phase.  
If a check fails, fix it before continuing.

| | |
|---|---|
| **GitHub repo** | https://github.com/Skykin-Technologies/call-center |
| **Branch to deploy** | `main` only |
| **Preferred architecture** | One cloud VM: FusionPBX + FreeSWITCH + Nginx + PHP-FPM + PostgreSQL |
| **Full Docker** | Supported: web + DB + FreeSWITCH via `docker compose` (follow `DOCKER_DEPLOYMENT.md`) |
| **Safer Docker** | Hybrid: web/DB in Docker, FreeSWITCH on the VM (`docker-compose.hybrid.yml`) |

> Full Docker is doable when leadership requires containers. For live SIP trunks, prefer classic VM or hybrid until RTP/host-network is hardened.
>
> For the new `skykin-fusionpbx-web` and
> `skykin-fusionpbx-freeswitch` images, use the dedicated
> **`DOCKER_DEPLOYMENT.md`** manual. It covers cloud build and prebuilt-image
> TAR deployment, database restore, HTTPS/WSS, SIP/RTP, verification, backup
> and rollback.

---

## Fill this in before you start

Copy and keep these values handy:

```text
DOMAIN=pbx.yourcompany.com
PUBLIC_IP=x.x.x.x
TIMEZONE=Africa/Addis_Ababa
FUSION_DOMAIN=client1.yourcompany.com     # FusionPBX tenant domain name
GITHUB_REPO=https://github.com/Skykin-Technologies/call-center.git
BRANCH=main
```

Replace every `pbx.yourcompany.com` / `TIMEZONE` below with your values.

---

## Phase A — Prepare (laptop + local VM)

### A1. Lower DNS TTL (1 day before cutover)

If the production hostname already exists, set DNS TTL to **300 seconds** now.

### A2. Confirm local system is healthy

On the **local VM**, verify once:

```bash
fs_cli -x 'status'
fs_cli -x 'sofia status'
timedatectl
sudo -u postgres psql -d fusionpbx -c "SELECT NOW();"
php -r 'echo date_default_timezone_get(), " ", date("c"), "\n";'
ls /var/www/fusionpbx/app/agent_dashboard/js/sipjs.bundle.js
```

✅ Local softphones register, calls work, Reports show today’s calls.

### A3. Take a migration backup on the local VM

```bash
STAMP=$(date +%Y%m%d_%H%M)
mkdir -p /root/skykin_migrate_$STAMP
cd /root/skykin_migrate_$STAMP

# Database (custom format — best for restore)
sudo -u postgres pg_dump -Fc fusionpbx -f fusionpbx.dump

# Inventory for later checks
sudo -u postgres psql -d fusionpbx -c \
  "SELECT extension, effective_caller_id_name FROM v_extensions ORDER BY 1;" \
  > extensions.txt
sudo -u postgres psql -d fusionpbx -c \
  "SELECT username FROM v_users ORDER BY 1;" > users.txt

# Config + recordings + SkyKin overrides
tar -czf skykin_files.tgz \
  /etc/fusionpbx \
  /etc/freeswitch \
  /var/lib/freeswitch/recordings \
  /etc/skykin \
  /var/www/fusionpbx/app/agent_dashboard/skykin_local_config.php \
  2>/dev/null || true

# Checksums / sizes
ls -lh
sha256sum fusionpbx.dump skykin_files.tgz > SHA256SUMS
```

Copy `/root/skykin_migrate_$STAMP` off the VM (scp to laptop or object storage).

✅ You have `fusionpbx.dump`, `skykin_files.tgz`, and `SHA256SUMS` somewhere safe.

---

## Phase B — Cloud VM base install

### B1. Create VM + firewall

**Suggested size:** 4 vCPU / 8 GB RAM / 80+ GB disk, Ubuntu 22.04 or 24.04.

Open these ports on the cloud security group / firewall:

| Port | Proto | Purpose |
|------|-------|---------|
| 22 | TCP | SSH |
| 80 | TCP | HTTP (certbot + redirect) |
| 443 | TCP | HTTPS + WSS softphone |
| 5060 | UDP/TCP | SIP trunk / peers |
| 16384–32768 | UDP | RTP media (confirm in FreeSWITCH if different) |

**Do not expose publicly:** `8021` (ESL), `5432` (Postgres), `5066`/`7443` (WS — use nginx `/wss/` on 443 instead).

Attach a **static public IP**. Write it into `PUBLIC_IP` above.

### B2. Point DNS

Create:

```text
A  pbx.yourcompany.com  ->  PUBLIC_IP
```

Wait until:

```bash
dig +short pbx.yourcompany.com
# must print PUBLIC_IP
```

✅ DNS resolves to the cloud IP from your laptop.

### B3. Install FusionPBX stack

Use the **official FusionPBX installer** for your Ubuntu version so FreeSWITCH, Nginx, PHP, and Postgres are wired correctly.

After install finishes:

```bash
systemctl is-active nginx php*-fpm postgresql freeswitch
fs_cli -x 'status'
curl -I http://127.0.0.1/
```

✅ All four services are `active`. FreeSWITCH answers `fs_cli`.

> Tip: complete the FusionPBX web setup wizard once so `/etc/fusionpbx/config.conf` exists. You will overwrite DB contents later with the local dump.

---

## Phase D — Deploy SkyKin code from `main`

Use the **overlay method** (safest on top of an official install).

### D1. Fetch the repo beside the web root

```bash
cd /opt
git clone --branch main --depth 1 \
  https://github.com/Skykin-Technologies/call-center.git \
  skykin-call-center
cd /opt/skykin-call-center
git rev-parse --short HEAD
git log -1 --oneline
```

### D2. Overlay SkyKin files into FusionPBX

```bash
WEB=/var/www/fusionpbx
SRC=/opt/skykin-call-center

# Core SkyKin dashboard app
rsync -a --delete \
  "$SRC/app/agent_dashboard/" \
  "$WEB/app/agent_dashboard/"

# Auth / landing redirects used by SkyKin
for f in \
  login.php \
  logout.php \
  index.php \
  core/dashboard/index.php \
  resources/check_auth.php \
  resources/require.php \
  resources/skykin_session_log.php
do
  if [ -f "$SRC/$f" ]; then
    mkdir -p "$(dirname "$WEB/$f")"
    cp -a "$SRC/$f" "$WEB/$f"
    echo "copied $f"
  fi
done

chown -R www-data:www-data "$WEB/app/agent_dashboard"
chown www-data:www-data \
  "$WEB/login.php" "$WEB/logout.php" "$WEB/index.php" \
  "$WEB/core/dashboard/index.php" \
  "$WEB/resources/check_auth.php" "$WEB/resources/require.php" \
  "$WEB/resources/skykin_session_log.php" 2>/dev/null || true
```

### D3. Verify critical files

```bash
test -f /var/www/fusionpbx/app/agent_dashboard/index.php
test -f /var/www/fusionpbx/app/agent_dashboard/supervisor.php
test -f /var/www/fusionpbx/app/agent_dashboard/play_recording.php
test -f /var/www/fusionpbx/app/agent_dashboard/js/sipjs.bundle.js
test -f /var/www/fusionpbx/app/agent_dashboard/skykin_config.php
php -l /var/www/fusionpbx/app/agent_dashboard/index.php
php -l /var/www/fusionpbx/app/agent_dashboard/supervisor.php
php -l /var/www/fusionpbx/app/agent_dashboard/reports.php
php -l /var/www/fusionpbx/app/agent_dashboard/play_recording.php
```

✅ All `test` commands succeed and `php -l` reports no syntax errors.

---

## Phase E — Restore local data onto cloud

Upload `fusionpbx.dump` and `skykin_files.tgz` to the cloud (e.g. `/root/migrate/`).

### E1. Restore PostgreSQL

```bash
systemctl stop nginx php*-fpm   # quiet writers during restore

# Optional safety dump of empty/wizard DB
sudo -u postgres pg_dump -Fc fusionpbx -f /root/fusionpbx_pre_restore.dump

sudo -u postgres pg_restore \
  --clean --if-exists \
  -d fusionpbx \
  /root/migrate/fusionpbx.dump

systemctl start php*-fpm nginx
```

If `pg_restore` prints non-fatal “errors ignoring drops”, that is usually OK. Confirm data:

```bash
sudo -u postgres psql -d fusionpbx -c "SELECT COUNT(*) FROM v_extensions;"
sudo -u postgres psql -d fusionpbx -c "SELECT COUNT(*) FROM v_users;"
sudo -u postgres psql -d fusionpbx -c "SELECT domain_name FROM v_domains;"
```

✅ Extension/user counts look like the local inventory files.

### E2. Restore recordings + config snippets

```bash
# Extract carefully — prefer recordings first
mkdir -p /root/migrate/extract
tar -tzf /root/migrate/skykin_files.tgz | head
tar -xzf /root/migrate/skykin_files.tgz -C /

chown -R www-data:www-data /var/lib/freeswitch/recordings
# FreeSWITCH may need freeswitch ownership on some installs:
chown -R freeswitch:freeswitch /var/lib/freeswitch/recordings 2>/dev/null || true
```

> After restore, open FusionPBX **Advanced → Default Settings** / Domains and confirm the tenant domain matches what agents will use. Update gateway hostnames if they still point at the old LAN IP.

### E3. Reload FreeSWITCH config from FusionPBX

In FusionPBX UI: **Status → SIP Status → Flush cache / Reload XML**  
Or:

```bash
fs_cli -x 'reloadxml'
fs_cli -x 'sofia profile internal restart'
fs_cli -x 'sofia profile external restart'
```

✅ `sofia status` shows profiles running.

---

## Phase F — SkyKin server settings (do exactly once)

### F1. `/etc/skykin/config.php`

```bash
mkdir -p /etc/skykin
cat >/etc/skykin/config.php <<'PHP'
<?php
return [
    'timezone'       => 'Africa/Addis_Ababa',
    'ahununu_url'    => 'https://ahununu.com/',
    'seed_demo_data' => false,
    // Leave empty unless you run the helper on :8001
    'recordings_api_base' => '',
    'socket_io_url'       => '',
];
PHP
chmod 640 /etc/skykin/config.php
chown root:www-data /etc/skykin/config.php
```

Change `timezone` to your `TIMEZONE` value.

### F2. OS + PHP + FreeSWITCH clocks (prevents “Today Calls = 00”)

```bash
timedatectl set-timezone Africa/Addis_Ababa
timedatectl set-ntp true
timedatectl status

# PHP timezone (FPM)
PHP_INI=$(ls /etc/php/*/fpm/php.ini | head -1)
sed -i 's#^;date.timezone =.*#date.timezone = Africa/Addis_Ababa#' "$PHP_INI"
sed -i 's#^date.timezone =.*#date.timezone = Africa/Addis_Ababa#' "$PHP_INI"
grep ^date.timezone "$PHP_INI"

# Sessions: 8 hours + strict mode
sed -i 's/^session.gc_maxlifetime.*/session.gc_maxlifetime = 28800/' "$PHP_INI"
sed -i 's/^session.use_strict_mode.*/session.use_strict_mode = 1/' "$PHP_INI"
grep -E 'session.gc_maxlifetime|session.use_strict_mode' "$PHP_INI"
```

**FreeSWITCH wall clock** (important on VMs):

```bash
# Prefer wall clock over monotonic timing
CONF=/etc/freeswitch/autoload_configs/switch.conf.xml
if grep -q 'enable-monotonic-timing' "$CONF"; then
  sed -i 's/enable-monotonic-timing" value="true"/enable-monotonic-timing" value="false"/' "$CONF"
fi
grep monotonic "$CONF" || true
systemctl restart freeswitch
```

### F3. PHP-FPM capacity (dashboards poll often)

```bash
POOL=$(ls /etc/php/*/fpm/pool.d/www.conf | head -1)
sed -i 's/^pm.max_children = .*/pm.max_children = 25/' "$POOL"
grep ^pm.max_children "$POOL"
systemctl restart php*-fpm
```

### F4. ESL must be localhost

```bash
# Remove stale LAN ESL_HOST if present
if [ -f /var/www/fusionpbx/.env ]; then
  sed -i 's/^ESL_HOST=.*/# ESL_HOST=127.0.0.1/' /var/www/fusionpbx/.env || true
  grep ESL /var/www/fusionpbx/.env || true
fi
fs_cli -x 'status'   # proves ESL/local control works
```

✅ `timedatectl` NTP active, PHP timezone set, FreeSWITCH restarted, `pm.max_children = 25`.

---

## Phase G — HTTPS + WSS (required for microphone)

Softphones **will not** work reliably on HTTP or with a self-signed cert mismatch.

### G1. Install certbot and get a certificate

```bash
apt-get update
apt-get install -y certbot python3-certbot-nginx
certbot --nginx -d pbx.yourcompany.com
```

Follow prompts. Choose redirect HTTP → HTTPS.

### G2. Add `/wss/` proxy (same certificate as the site)

Edit the nginx site for FusionPBX (often under `/etc/nginx/sites-enabled/`) and add **inside the HTTPS server block**:

```nginx
location /wss/ {
    proxy_pass http://127.0.0.1:5066;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 86400;
}
```

Confirm FreeSWITCH WS port:

```bash
ss -lntp | grep -E '5066|7443' || true
fs_cli -x 'sofia status'
```

If WS listens on another port/IP, change `proxy_pass` accordingly.

```bash
nginx -t && systemctl reload nginx
curl -Ik https://pbx.yourcompany.com/login.php | head
```

✅ Browser opens login on **https://** with a trusted padlock.  
✅ Certificate subject matches `DOMAIN`.

---

## Phase H — Pre-production smoke test (before SIP trunk)

Use a real browser (Chrome). Allow microphone when prompted.

| # | Test | Expected |
|---|------|----------|
| 1 | Login as agent | Lands on agent dashboard |
| 2 | Login as supervisor (other browser/profile) | Lands on supervisor dashboard |
| 3 | Agent softphone | Status **Registered (ext)** |
| 4 | Supervisor softphone | Status **Registered (ext)** |
| 5 | Agent A calls Agent B | Answer works; timer counts |
| 6 | Transfer to supervisor | Supervisor phone rings; can answer |
| 7 | Live Agent Status | Shows Available / On Call correctly |
| 8 | Break request | Stays Available until supervisor approves |
| 9 | Recording | REC shows; Play works after hangup |
| 10 | Reports | Today KPIs non-zero after test calls; Excel exports |
| 11 | Sidebar | Reports / Evaluation / CRM open; **Supervisor** returns home |

If softphone fails with WSS/mic issues, see **Troubleshooting** below — do not proceed to cutover.

✅ All rows above pass with internal extensions.

---

## Phase I — SIP trunk (when delivered)

Do this only after Phase H is green.

1. Ask the provider to allowlist **`PUBLIC_IP`**.  
2. In FusionPBX: **Accounts → Gateways** — create trunk with provider credentials.  
3. **Dialplan → Outbound Routes** — patterns to that gateway.  
4. **Inbound Destinations** — DID → IVR / queue.  
5. Open SIP + RTP ports if not already open.  
6. Test: inbound DID, outbound PSTN, transfer to supervisor.

```bash
fs_cli -x 'sofia status gateway'
fs_cli -x 'sofia status'
```

✅ Inbound and outbound PSTN calls succeed.

---

## Phase J — Cutover day (shortest downtime)

Do this in one sitting:

1. **Announce maintenance** (15–30 minutes).  
2. On local VM: final dump (repeat Phase A3 with new stamp).  
3. Copy final dump to cloud; restore DB (Phase E1).  
4. Update code:

```bash
cd /opt/skykin-call-center
git fetch origin
git checkout main
git pull --ff-only origin main
# re-run Phase D2 overlay commands
systemctl reload php*-fpm
```

5. Provider: switch trunk / DID routing to cloud IP (if not already).  
6. DNS: point `DOMAIN` A record to `PUBLIC_IP` (if not already).  
7. Wait for DNS; re-run Phase H quick checks.  
8. Keep local VM on for **48 hours** as rollback.

**Rollback:** point DNS back to local IP; move trunk allowlist back; users log into old system.

---

## Phase K — Day-2 (updates & backups)

### Update dashboards later

```bash
cd /opt/skykin-call-center
git pull --ff-only origin main
# re-run Phase D2 rsync/cp block
systemctl reload php*-fpm
```

### Daily backup cron (example)

```bash
cat >/etc/cron.daily/skykin-backup <<'EOF'
#!/bin/bash
STAMP=$(date +%Y%m%d)
DIR=/var/backups/skykin/$STAMP
mkdir -p "$DIR"
sudo -u postgres pg_dump -Fc fusionpbx -f "$DIR/fusionpbx.dump"
tar -czf "$DIR/recordings.tgz" /var/lib/freeswitch/recordings
tar -czf "$DIR/config.tgz" /etc/fusionpbx /etc/freeswitch /etc/skykin
find /var/backups/skykin -mindepth 1 -maxdepth 1 -mtime +14 -exec rm -rf {} \;
EOF
chmod +x /etc/cron.daily/skykin-backup
```

---

## Troubleshooting (fast)

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Softphone never registers / WSS 1006 | Cert mismatch or no `/wss/` proxy | Use HTTPS domain cert; proxy `/wss/` → `5066` |
| Answer failed / mic blocked | Browser distrusts cert | Let’s Encrypt on real domain; allow mic |
| Today Calls = 00 | TZ drift / FreeSWITCH monotonic clock | Phase F2; restart FreeSWITCH |
| All agents Offline on supervisor | ESL down / wrong ESL_HOST | ESL on `127.0.0.1`; remove stale `.env` ESL_HOST |
| Session logout on tab switch | PHP-FPM starvation / short GC | `pm.max_children=25`, `gc_maxlifetime=28800` |
| Play recording fails | Missing `play_recording.php` or file path | Confirm Phase D files; check recordings dir permissions |
| Reports empty after restore | Wrong domain filter / empty CDR | Confirm `v_domains` name matches login domain |

Useful commands:

```bash
tail -f /var/log/nginx/error.log
journalctl -u freeswitch -f
fs_cli -x 'sofia status'
fs_cli -x 'callcenter_config agent list'
php -i | grep -E 'date.timezone|session.gc_maxlifetime'
```

---

## Final sign-off checklist

Print / tick before calling the migration “done”:

- [ ] DNS + HTTPS padlock OK  
- [ ] `main` code overlaid; `sipjs.bundle.js` present  
- [ ] DB restored; extensions/users match inventory  
- [ ] Timezone aligned (OS / PHP / FreeSWITCH)  
- [ ] Agent + supervisor softphones **Registered**  
- [ ] Internal call + transfer to supervisor OK  
- [ ] Recordings play  
- [ ] Reports KPIs + Excel OK  
- [ ] SIP trunk inbound/outbound OK (when available)  
- [ ] Daily backup cron installed  
- [ ] Local VM kept 48h for rollback  

---

## Quick URLs (production)

- Login: `https://DOMAIN/login.php`  
- Agent: `https://DOMAIN/app/agent_dashboard/index.php`  
- Supervisor: `https://DOMAIN/app/agent_dashboard/supervisor.php`  
- Reports: `https://DOMAIN/app/agent_dashboard/reports.php`
