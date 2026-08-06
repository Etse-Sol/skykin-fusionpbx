# SkyKin Call Center — Cloud Deployment Procedure

Use this when moving from the local VM to a cloud server.  
**Source of truth:** GitHub branch `main`  
**Repo:** https://github.com/Skykin-Technologies/call-center

> Recommended architecture: **one cloud VM** with FusionPBX + FreeSWITCH + Nginx + PostgreSQL (same as local).  
> The Docker compose setup in this repo is for **local/hybrid development only**, not production telephony.

---

## 0. Prerequisites

Before you start, have:

| Item | Notes |
|------|--------|
| Cloud VM | Ubuntu 22.04/24.04, 4+ GB RAM, public IP |
| Domain | e.g. `pbx.yourdomain.com` pointed to the VM |
| SIP trunk | Provider credentials (DID, SIP username/password, IP allowlist) |
| Access | SSH root/sudo to the new VM + old local VM |
| GitHub access | Membership in `Skykin-Technologies` |

Open / allow these ports on the cloud firewall:

- `22` TCP — SSH  
- `80` / `443` TCP — HTTP / HTTPS  
- `5060` UDP/TCP — SIP (or provider’s ports)  
- `5066` / `7443` TCP — WebSocket SIP (internal; prefer nginx `/wss/` on 443)  
- `8021` TCP — ESL (localhost only; do **not** expose publicly)  
- RTP range — typically `16384–32768` UDP (confirm FreeSWITCH `rtp-start-port` / `rtp-end-port`)

---

## 1. Backup the local VM (do this first)

On the **local** FusionPBX VM:

```bash
# Database
sudo -u postgres pg_dump -Fc fusionpbx > /root/fusionpbx_$(date +%Y%m%d).dump

# Config + recordings (adjust paths if different)
tar -czf /root/skykin_backup_$(date +%Y%m%d).tgz \
  /etc/fusionpbx \
  /etc/freeswitch \
  /var/lib/freeswitch/recordings \
  /var/www/fusionpbx/app/agent_dashboard/skykin_local_config.php \
  /etc/skykin 2>/dev/null

# Optional: list extensions / agents for verification later
sudo -u postgres psql -d fusionpbx -c \
  "SELECT extension, effective_caller_id_name FROM v_extensions ORDER BY extension;"
```

Copy backups to a safe place (laptop or object storage).

---

## 2. Provision the cloud server

1. Create the VM and attach a static public IP.  
2. Point DNS `A` record for your domain to that IP.  
3. Wait for DNS to resolve:

```bash
dig +short pbx.yourdomain.com
```

4. Install FusionPBX using the official installer (or clone your hardened image), so you have:

- FreeSWITCH  
- FusionPBX  
- Nginx  
- PHP-FPM 8.x  
- PostgreSQL  

Official path is preferred so FreeSWITCH/FusionPBX wiring is correct.

---

## 3. Deploy the SkyKin code (`main`)

On the cloud VM:

```bash
cd /var/www/fusionpbx

# If FusionPBX was installed by the official installer, pull SkyKin on top:
git remote add skykin https://github.com/Skykin-Technologies/call-center.git
git fetch skykin
git checkout -B main skykin/main

# Or, if this directory is already a clone of call-center:
# git pull origin main

chown -R www-data:www-data /var/www/fusionpbx/app/agent_dashboard
```

Confirm softphone bundle exists:

```bash
ls -la /var/www/fusionpbx/app/agent_dashboard/js/sipjs.bundle.js
```

---

## 4. Restore data from the local VM

```bash
# Restore DB (example with custom format dump)
sudo -u postgres pg_restore -d fusionpbx --clean --if-exists /root/fusionpbx_YYYYMMDD.dump

# Restore recordings
tar -xzf /root/skykin_backup_YYYYMMDD.tgz -C /
chown -R www-data:www-data /var/lib/freeswitch/recordings
```

After restore, in FusionPBX UI verify:

- Domains  
- Extensions (agents + supervisor)  
- Call Center queues / agents / tiers  
- Gateways (update for new SIP trunk)

---

## 5. Server config (SkyKin)

Create `/etc/skykin/config.php` (preferred over committing secrets):

```php
<?php
return [
    'timezone'     => 'Africa/Addis_Ababa',   // or your production zone
    'ahununu_url'  => 'https://ahununu.com/',
    'seed_demo_data' => false,
    // Leave helper APIs empty unless that service is running:
    // 'recordings_api_base' => '',
    // 'socket_io_url' => '',
];
```

```bash
mkdir -p /etc/skykin
chmod 640 /etc/skykin/config.php
chown root:www-data /etc/skykin/config.php
```

Optional local file (not in git):

```bash
cp /var/www/fusionpbx/app/agent_dashboard/skykin_local_config.php.example \
   /var/www/fusionpbx/app/agent_dashboard/skykin_local_config.php
```

### Timezone / clock (critical for “Today Calls” and reports)

```bash
timedatectl set-timezone Africa/Addis_Ababa   # your zone
timedatectl set-ntp true
# Disable FreeSWITCH monotonic timing if the host can suspend / clock-jump
# (same fix as local VM) in switch.conf.xml, then:
systemctl restart freeswitch
```

### PHP sessions

```bash
# 8-hour sessions (example)
sed -i 's/^session.gc_maxlifetime.*/session.gc_maxlifetime = 28800/' /etc/php/*/fpm/php.ini
# Ensure session.use_strict_mode = 1
systemctl restart php*-fpm
```

Raise PHP-FPM children if two dashboards poll heavily (local used ~25).

### ESL

Keep ESL on `127.0.0.1`. Do **not** put a stale LAN IP in `/var/www/fusionpbx/.env`.  
SkyKin already falls back to localhost when ESL is misconfigured.

---

## 6. HTTPS + WSS (required for softphone mic)

1. Issue Let’s Encrypt cert for the domain (certbot + nginx).  
2. Serve FusionPBX only over HTTPS.  
3. Proxy WebSocket SIP through nginx on the same cert:

```nginx
location /wss/ {
    proxy_pass http://127.0.0.1:5066;   # or FreeSWITCH WS listen address
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 86400;
}
```

Softphones register to: `wss://pbx.yourdomain.com/wss/`  
Do **not** rely on FreeSWITCH’s self-signed cert on port 7443 in browsers.

Reload nginx after changes:

```bash
nginx -t && systemctl reload nginx
```

---

## 7. SIP trunk (when it arrives)

In FusionPBX:

1. **Accounts → Gateways** — add provider SIP trunk.  
2. Set outbound proxy / realm / username / password as provider documents.  
3. **Dialplan → Outbound routes** — dial patterns to the gateway.  
4. **Inbound destinations** — map DID → IVR / queue (e.g. 9000).  
5. Ask provider to allowlist your **cloud public IP**.  
6. Open RTP + SIP ports on the cloud firewall to match FreeSWITCH.

Test:

- Extension-to-extension softphone call  
- Inbound DID → queue → agent  
- Outbound via trunk  
- Transfer to supervisor extension  

---

## 8. Roles & users smoke check

| Role | Login should land on |
|------|----------------------|
| Agent | `/app/agent_dashboard/index.php` |
| Supervisor | `/app/agent_dashboard/supervisor.php` |
| Admin | FusionPBX admin / redirected by groups |

Verify:

- [ ] Agent softphone shows **Registered**  
- [ ] Supervisor softphone shows **Registered** (for transfers)  
- [ ] Live agent status updates  
- [ ] Break request + supervisor approve  
- [ ] Auto recording + Play works  
- [ ] Reports KPIs + Excel export  
- [ ] Evaluation / CRM open from management sidebar  

---

## 9. Cutover checklist

1. Lower DNS TTL a day before cutover (if reusing domain).  
2. Final DB + recordings dump from local VM.  
3. Restore on cloud, `git pull origin main`.  
4. Switch SIP trunk IP allowlist / DID routing to cloud.  
5. Point DNS to cloud IP.  
6. Confirm HTTPS cert renews (`certbot renew --dry-run`).  
7. Keep local VM powered for 48h as rollback.  

Rollback: point DNS back to local IP; revert trunk allowlist.

---

## 10. Day-2 operations

```bash
# Update dashboards from GitHub
cd /var/www/fusionpbx
git pull origin main
chown -R www-data:www-data app/agent_dashboard
systemctl reload php*-fpm   # if needed

# Logs
tail -f /var/log/nginx/error.log
fs_cli -x 'sofia status'
journalctl -u freeswitch -f
```

Backups (cron daily):

- `pg_dump` of `fusionpbx`  
- `/var/lib/freeswitch/recordings`  
- `/etc/fusionpbx`, `/etc/freeswitch`, `/etc/skykin`

---

## Quick reference URLs

After DNS + HTTPS:

- Login: `https://pbx.yourdomain.com/login.php`  
- Agent: `https://pbx.yourdomain.com/app/agent_dashboard/index.php`  
- Supervisor: `https://pbx.yourdomain.com/app/agent_dashboard/supervisor.php`  
- Reports: `https://pbx.yourdomain.com/app/agent_dashboard/reports.php`

---

## Do not forget

- Use **`main`** only for production deploys.  
- Never commit `skykin_local_config.php`, `.env`, or PATs.  
- Softphone **requires trusted HTTPS**.  
- Keep ESL and FreeSWITCH WS internal; expose SIP/RTP carefully.  
- Timezone + NTP must match between OS, PHP, Postgres, FreeSWITCH.
