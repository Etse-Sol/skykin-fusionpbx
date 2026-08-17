# SkyKin Docker

Full stack by default: **web + Postgres + FreeSWITCH** in Docker.

Hybrid mode (FreeSWITCH on a host/VM) is still available for safer production SIP.

## Services

| Service | Container | Ports |
|---------|-----------|-------|
| FusionPBX + SkyKin dashboards | `skykin-web` | http://localhost:8080 |
| PostgreSQL | `skykin-db` | localhost:5433 |
| FreeSWITCH | `skykin-freeswitch` | SIP 5060, WS 5066, ESL 127.0.0.1:8021, RTP 16384–16584/udp |

## Quick start (full Docker)

```bash
# from repo root
cp .env.docker.example .env
# edit DB_PASSWORD / ESL_PASSWORD

docker compose up --build -d
docker compose ps
```

Open:

- Agent: http://localhost:8080/app/agent_dashboard/index.php  
- Supervisor: http://localhost:8080/app/agent_dashboard/supervisor.php  

Softphone WS path inside Docker: `ws://localhost:8080/wss/` (nginx → `freeswitch:5066`).

Stop:

```bash
docker compose down
```

## Hybrid mode (recommended for live SIP trunks)

Keep FreeSWITCH on the Debian/Ubuntu VM; only web + DB in Docker:

```bash
docker compose -f docker-compose.yml -f docker-compose.hybrid.yml up --build -d
```

Set in `.env`:

```env
ESL_HOST=host.docker.internal
FREESWITCH_WS_UPSTREAM=host.docker.internal:5066
```

On Linux, allow Docker → host ESL/WS, or set the VM IP explicitly.

## Production notes (important)

1. **Fresh Postgres is empty** — import a FusionPBX dump from your working VM for agents/queues/CDRs.  
2. **RTP in compose uses a narrow range** (`16384–16584`) so port publishing stays workable. Real trunks often need `16384–32768` and **`network_mode: host`** for the FreeSWITCH service on Linux.  
3. **HTTPS** — browsers need a trusted cert for mic/WSS. Put nginx/Caddy or a cloud LB in front with Let’s Encrypt; keep `/wss/` proxied to the web container (or directly to FreeSWITCH WS).  
4. **Full Docker ≠ zero FreeSWITCH ops** — dialplan, gateways, NAT, and codecs still need FusionPBX/FreeSWITCH tuning after DB restore.  
5. Do **not** expose ESL (`8021`) on a public interface.
6. **ecs-cc backup compose** — `docker-compose.ecs-cc.yml` + `.env.ecs-cc.example` freeze the working host-network layout (HTTPS 8188, WS-SIP on 18081, Ethio on `FS_LAN_RTP_IP`). Rebuild the FreeSWITCH image before recreating that container or the runtime dialplan is wiped.

### Linux host-network FreeSWITCH (best call quality)

In `docker-compose.yml`, for the `freeswitch` service:

```yaml
network_mode: host
# remove the ports: block when using host networking
```

Then set web env to reach FreeSWITCH on the host bridge IP / `172.17.0.1`, or run web also with appropriate wiring. Prefer hybrid mode if this gets messy.

## Update code

```bash
git pull origin main
docker compose up --build -d
```

Agent dashboard files are bind-mounted (`./app/agent_dashboard`), so many UI edits apply without rebuild. Core PHP/nginx image changes need `--build`.

## Import a DB dump

```bash
docker compose exec -T db pg_restore -U fusionpbx -d fusionpbx --clean --if-exists < fusionpbx.dump
```

## Troubleshooting

| Symptom | Check |
|---------|--------|
| Web up, phone never registers | `docker compose logs freeswitch` and `web`; confirm `/wss/` → `freeswitch:5066` |
| ESL / agent status stuck | `ESL_HOST=freeswitch`, password matches, `docker compose exec freeswitch fs_cli -x status` |
| One-way audio | RTP ports not published / NAT; use host networking or hybrid |
| Empty dashboards | DB not restored / wrong domain |

```bash
docker compose logs -f web freeswitch
docker compose exec freeswitch fs_cli -x 'sofia status'
docker compose exec freeswitch fs_cli -x 'status'
```
