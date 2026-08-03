# SkyKin Docker

Hybrid setup: **web + Postgres in Docker**, **FreeSWITCH on your Debian VM**.

## Why not FreeSWITCH in Docker?

FreeSWITCH needs many UDP/RTP ports, low-latency media, and SIP/WSS networking that is hard to get right in containers. Keep FreeSWITCH on the VM you already run (`fusionbpx`).

## What this runs

| Service | Container | Port |
|---------|-----------|------|
| Agent dashboard + FusionPBX PHP | `skykin-web` | http://localhost:8080 |
| PostgreSQL | `skykin-db` | localhost:5433 |

## Quick start

```bash
# from repo root
cp .env.docker.example .env
docker compose up --build -d
```

Open:

- Agent dashboard: http://localhost:8080/app/agent_dashboard/index.php
- Supervisor: http://localhost:8080/app/agent_dashboard/supervisor.php

Stop:

```bash
docker compose down
```

## Connect to FreeSWITCH (VM)

1. Start your FusionPBX VM so FreeSWITCH is listening on `5066` (ws) and `8021` (ESL).
2. In `.env` set the VM IP if `host.docker.internal` cannot reach it:

```env
ESL_HOST=192.168.243.129
FREESWITCH_WS_UPSTREAM=192.168.243.129:5066
```

3. Allow the Docker host IP in FreeSWITCH ESL ACL if needed (`event_socket.conf.xml`).

4. Softphone WSS path inside Docker is `ws://localhost:8080/wss/` (proxied to FreeSWITCH). For HTTPS on the VM you already use nginx `/wss/` there — that path is unchanged.

## Notes

- Fresh Postgres is empty — full FusionPBX schema is **not** auto-imported. Tickets/ACW can still use SQLite (`skykin_local.db`). Call-center agent status needs FusionPBX tables / a DB dump from the VM if you want full parity.
- To import a dump from the VM later:

```bash
docker compose exec -T db psql -U fusionpbx fusionpbx < fusionpbx_dump.sql
```

- Agent dashboard files are bind-mounted so UI edits apply without rebuild.
- SQLite DB permissions are fixed on container start (writable by `www-data`).

## Rebuild

```bash
docker compose up --build -d
```
