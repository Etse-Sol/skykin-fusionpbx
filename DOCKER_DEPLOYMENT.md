# SkyKin Call Center — Docker Deployment Manual

This manual deploys the SkyKin call center as one Docker Compose project with
three services:

| Service | Image | Purpose |
|---|---|---|
| `web` | `skykin-fusionpbx-web:latest` | FusionPBX UI, SkyKin dashboards, Nginx and PHP |
| `freeswitch` | `skykin-fusionpbx-freeswitch:latest` | SIP, RTP, softphone WebSocket and ESL |
| `db` | `postgres:16-alpine` | FusionPBX database |

Do not start the images separately. `docker compose` creates their shared
network, volumes, environment variables and startup order.

> Important: the current full-Docker stack has passed container, HTTP,
> FreeSWITCH, WebSocket and ESL connectivity checks. Before production calls,
> restore/configure FusionPBX data, extensions, queues and trunks, then complete
> an end-to-end inbound/outbound call test. SIP NAT, persistent FreeSWITCH
> configuration and the FusionPBX-to-FreeSWITCH configuration workflow must be
> validated for the selected cloud environment.

## 1. Requirements

- Ubuntu 22.04 or 24.04 cloud VM
- Recommended minimum: 4 vCPU, 8 GB RAM, 80 GB disk
- Static public IP
- DNS name such as `pbx.example.com`
- Docker Engine with the Compose plugin
- Git
- A FusionPBX database backup if migrating an existing installation

Record these values:

```text
DOMAIN=pbx.example.com
PUBLIC_IP=x.x.x.x
REPOSITORY=https://github.com/Skykin-Technologies/call-center.git
BRANCH=main
INSTALL_DIR=/opt/skykin-call-center
```

## 2. Firewall and DNS

Allow these inbound ports in the cloud firewall:

| Port | Protocol | Purpose |
|---|---|---|
| 22 | TCP | SSH |
| 80 | TCP | Certificate issuance and HTTP redirect |
| 443 | TCP | HTTPS and WSS softphone |
| 5060 | UDP/TCP | SIP |
| 16384–16584 | UDP | Current Compose RTP range |

Do not expose `5432/5433` (Postgres), `8021` (ESL), or `5066` (plain
WebSocket) publicly. The Compose file binds ESL to host loopback. Restrict the
other debug ports with the host/cloud firewall.

Point the DNS A record to the static public IP:

```text
pbx.example.com -> PUBLIC_IP
```

Verify:

```bash
dig +short pbx.example.com
```

## 3. Install Docker

Use Docker's official Ubuntu installation instructions. Verify:

```bash
docker version
docker compose version
```

Enable Docker after reboot:

```bash
sudo systemctl enable --now docker
```

## 4. Deployment method A — Build on the cloud VM

This is the normal method. The repository contains both Dockerfiles and the
Compose definition.

```bash
sudo mkdir -p /opt
cd /opt
sudo git clone --branch main \
  https://github.com/Skykin-Technologies/call-center.git \
  skykin-call-center
cd /opt/skykin-call-center
sudo cp .env.docker.example .env
```

Edit `.env`:

```bash
sudo nano .env
```

Use strong, unique passwords. Keep the internal Docker service names:

```env
WEB_PORT=8080
DB_PUBLISH_PORT=5433
DB_NAME=fusionpbx
DB_USER=fusionpbx
DB_PASSWORD=REPLACE_WITH_STRONG_PASSWORD

ESL_PASSWORD=REPLACE_WITH_DIFFERENT_STRONG_PASSWORD
ESL_HOST=freeswitch
ESL_PORT=8021
ESL_PUBLISH_PORT=8021

FREESWITCH_WS_UPSTREAM=freeswitch:5066
FS_WS_PORT=5066
SIP_PORT=5060

RTP_START_PORT=16384
RTP_END_PORT=16584
```

Protect the environment file:

```bash
sudo chmod 600 .env
```

Build and start all services:

```bash
sudo docker compose up --build -d
sudo docker compose ps
```

Expected containers:

```text
skykin-web
skykin-db
skykin-freeswitch
```

## 5. Deployment method B — Transfer prebuilt images

Use this method when the cloud VM cannot build images. The Compose file is
still required on the cloud VM.

On the build computer:

```powershell
cd C:\Users\hp\skykin-fusionpbx
docker compose build
docker save -o skykin-call-center-images.tar `
  skykin-fusionpbx-web:latest `
  skykin-fusionpbx-freeswitch:latest `
  postgres:16-alpine
```

Copy these items to the cloud VM:

- `skykin-call-center-images.tar`
- the repository directory, or at minimum `docker-compose.yml` and `.env`

On the cloud VM:

```bash
docker load -i skykin-call-center-images.tar
cd /opt/skykin-call-center
docker compose up -d --no-build
docker compose ps
```

The TAR archive is only a transport package. After `docker load`, Docker stores
the three images separately and Compose runs them together.

## 6. Restore or initialize the database

A fresh Postgres volume is empty. For migration, copy `fusionpbx.dump` to the
cloud VM and restore it:

```bash
cd /opt/skykin-call-center
docker compose exec -T db pg_restore \
  -U fusionpbx \
  -d fusionpbx \
  --clean \
  --if-exists \
  < /path/to/fusionpbx.dump
```

If the dump ownership differs, add `--no-owner --no-privileges`.

Confirm the data:

```bash
docker compose exec -T db psql \
  -U fusionpbx \
  -d fusionpbx \
  -c "SELECT domain_name FROM v_domains;"

docker compose exec -T db psql \
  -U fusionpbx \
  -d fusionpbx \
  -c "SELECT extension FROM v_extensions ORDER BY extension;"
```

For a new installation, complete the FusionPBX initialization and create:

1. Domain
2. Users and agent roles
3. Extensions and SIP credentials
4. Call-center agents and queues
5. Gateways/trunks and inbound routes
6. Supervisor account and permissions

The dashboard application is already included. Agent, extension, queue and
trunk records must still be configured or restored.

## 7. HTTPS and public access

Do not expose port `8080` as the final agent URL. Put a trusted HTTPS reverse
proxy or cloud load balancer in front of it:

```text
https://pbx.example.com -> http://127.0.0.1:8080
```

The proxy must support WebSocket upgrades for `/wss/`. Browsers require trusted
HTTPS/WSS for microphone access.

After HTTPS is configured, agents use:

```text
https://pbx.example.com/login.php
https://pbx.example.com/app/agent_dashboard/index.php
```

Supervisors use:

```text
https://pbx.example.com/app/agent_dashboard/supervisor.php
```

The images do not contain a fixed cloud IP. The cloud provider assigns the
static public IP, DNS points to it, and the dashboards derive the hostname from
the browser request.

## 8. SIP, RTP and NAT

Before production:

1. Set the SIP provider allowlist to the cloud static public IP.
2. Configure the gateway/trunk with the provider credentials.
3. Confirm FreeSWITCH advertises the public IP for external SIP and RTP.
4. Confirm the RTP range matches `.env`, Compose and both firewalls.
5. Test inbound, outbound, transfer, hold, recording and two-way audio.

The current Compose range is `16384–16584/udp`. If the provider or expected
concurrency needs a wider range, change the start/end values and firewall rules
together.

For demanding production SIP traffic, Linux host networking or hybrid mode may
be more reliable than Docker port publishing. Do not switch networking modes
without updating the web-to-FreeSWITCH ESL/WSS routing and retesting.

## 9. Verification

Container state:

```bash
docker compose ps
```

FreeSWITCH:

```bash
docker compose exec freeswitch fs_cli -x status
docker compose exec freeswitch fs_cli -x "sofia status"
```

HTTP from the VM:

```bash
curl -I http://127.0.0.1:8080/login.php
```

Logs:

```bash
docker compose logs --tail=100 web
docker compose logs --tail=100 freeswitch
docker compose logs --tail=100 db
```

Required acceptance tests:

- All three containers stay up; DB and FreeSWITCH report healthy.
- HTTPS login page opens with no certificate warning.
- Agent and supervisor can log in with correct role routing.
- Softphone registers over WSS.
- Inbound and outbound calls have two-way audio.
- Agent state updates through ESL.
- Queue routing, transfer, hold and logout work.
- Recordings play from the dashboard.
- Reports and evaluation pages show restored/new calls.
- Containers remain healthy after `docker compose restart`.

Do not declare production ready until all acceptance tests pass.

## 10. Operations

Start:

```bash
docker compose up -d
```

Stop without deleting data:

```bash
docker compose down
```

Update:

```bash
cd /opt/skykin-call-center
git pull origin main
docker compose up --build -d
docker compose ps
```

Follow logs:

```bash
docker compose logs -f web freeswitch
```

Never use `docker compose down -v` in production unless intentionally deleting
the database and recording volumes.

## 11. Backup

Database:

```bash
docker compose exec -T db pg_dump \
  -U fusionpbx \
  -Fc fusionpbx \
  > fusionpbx_$(date +%Y%m%d_%H%M).dump
```

List volumes:

```bash
docker volume ls | grep skykin
```

Back up both named volumes:

- `skykin_pgdata`
- `skykin_recordings`

Store backups outside the VM and test restoration regularly.

## 12. Rollback

Before updating, record the working commit and image IDs:

```bash
git rev-parse HEAD
docker images --digests | grep skykin
```

To roll back code:

```bash
git checkout WORKING_COMMIT
docker compose up --build -d
```

For a failed migration, restore the previous DB dump and DNS/trunk routing.

## 13. Troubleshooting

| Problem | Check |
|---|---|
| Web does not open | `docker compose ps`, then `docker compose logs web` |
| Empty dashboards | Database was not restored, or the FusionPBX domain differs |
| Agent state is stuck | `ESL_HOST=freeswitch`; verify ESL password and FreeSWITCH logs |
| Softphone does not register | HTTPS certificate, `/wss/` proxy, extension credentials |
| No inbound calls | SIP provider allowlist, gateway registration, public SIP IP |
| One-way/no audio | Public RTP IP, UDP range and NAT/firewall |
| FreeSWITCH unhealthy | `docker compose logs freeswitch`; verify its config bootstrap |
| Data disappeared | Wrong Compose project/volume; do not recreate with `down -v` |

