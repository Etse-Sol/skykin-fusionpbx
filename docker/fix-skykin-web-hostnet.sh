#!/bin/sh
# Recreate skykin-web so nginx can resolve hostname "freeswitch" after
# FreeSWITCH moved to --network host (no Docker DNS name anymore).
#
# Run on flipstar-app-server. Does not touch skykin-freeswitch or skykin-db.
#
#   chmod +x docker/fix-skykin-web-hostnet.sh
#   FS_IP=10.0.0.93 ./docker/fix-skykin-web-hostnet.sh
#
# Why 10.0.0.93 (not docker-bridge / host-gateway): WSS is bound on
# 10.0.0.93:7443. ESL 8021 is 0.0.0.0 so it is reachable there too.
set -eu

WEB="${WEB_CONTAINER:-skykin-web}"
FS_IP="${FS_IP:-10.0.0.93}"
BACKUP="${WEB}-broken-$(date +%Y%m%d%H%M%S)"

if ! docker inspect "$WEB" >/dev/null 2>&1; then
  echo "ERROR: container $WEB not found" >&2
  exit 1
fi

echo "=== current $WEB ==="
docker inspect "$WEB" --format 'Image={{.Config.Image}} Restart={{.HostConfig.RestartPolicy.Name}} Network={{.HostConfig.NetworkMode}}'
docker inspect "$WEB" --format 'Ports={{json .HostConfig.PortBindings}}'
docker inspect "$WEB" --format '{{range .Config.Env}}{{println .}}{{end}}' | grep -E 'ESL_|FREESWITCH_|DB_' || true

python3 - "$WEB" "$FS_IP" "$BACKUP" <<'PY'
import json, os, shlex, subprocess, sys

name, fs_ip, backup = sys.argv[1], sys.argv[2], sys.argv[3]
info = json.loads(subprocess.check_output(["docker", "inspect", name]))[0]
cfg, hc = info["Config"], info["HostConfig"]

args = ["docker", "run", "-d", "--name", name]
rp = (hc.get("RestartPolicy") or {}).get("Name") or "unless-stopped"
if rp and rp != "no":
    args += ["--restart", rp]

args += ["--add-host", "freeswitch:%s" % fs_ip]
seen = {"freeswitch"}
for extra in hc.get("ExtraHosts") or []:
    host = extra.split(":", 1)[0]
    if host in seen:
        continue
    args += ["--add-host", extra]
    seen.add(host)

for env in cfg.get("Env") or []:
    args += ["-e", env]

for m in info.get("Mounts") or []:
    dest = m.get("Destination")
    if not dest:
        continue
    mode = "ro" if m.get("RW") is False else "rw"
    if m.get("Type") == "bind" and m.get("Source"):
        args += ["-v", "%s:%s:%s" % (m["Source"], dest, mode)]
    elif m.get("Type") == "volume" and m.get("Name"):
        args += ["-v", "%s:%s:%s" % (m["Name"], dest, mode)]

for cport, hosts in (hc.get("PortBindings") or {}).items():
    for h in hosts or []:
        hp = (h or {}).get("HostPort") or ""
        if not hp:
            continue
        hip = (h or {}).get("HostIp") or ""
        spec = "%s:%s:%s" % (hip, hp, cport) if hip else "%s:%s" % (hp, cport)
        args += ["-p", spec]

netmode = hc.get("NetworkMode") or ""
extra_nets = []
nets = list((info.get("NetworkSettings") or {}).get("Networks") or {})
if netmode.startswith("container:") or netmode in ("host", "none"):
    args += ["--network", netmode]
elif netmode and netmode not in ("default", "bridge"):
    args += ["--network", netmode]
    extra_nets = [n for n in nets if n != netmode]
elif nets:
    args += ["--network", nets[0]]
    extra_nets = nets[1:]

if cfg.get("Hostname"):
    args += ["--hostname", cfg["Hostname"]]
if hc.get("Privileged"):
    args.append("--privileged")
for cap in hc.get("CapAdd") or []:
    args += ["--cap-add", cap]

args.append(cfg["Image"])

print("Recreating %s with --add-host freeswitch:%s" % (name, fs_ip))
print("Backup name: %s" % backup)
print("Run: %s" % " ".join(shlex.quote(a) for a in args))

subprocess.check_call(["docker", "stop", name])
subprocess.check_call(["docker", "rename", name, backup])
try:
    subprocess.check_call(args)
except subprocess.CalledProcessError:
    print("ERROR: docker run failed; restoring %s" % name, file=sys.stderr)
    subprocess.call(["docker", "rename", backup, name])
    subprocess.call(["docker", "start", name])
    sys.exit(1)

for net in extra_nets:
    subprocess.check_call(["docker", "network", "connect", net, name])

open(os.environ.get("BACKUP_NAME_FILE", "/tmp/skykin-web-backup-name"), "w").write(backup)
PY

echo
echo "=== new $WEB ==="
sleep 3
docker ps -a --filter "name=$WEB" --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
echo
docker logs --tail 20 "$WEB" || true
echo
echo "Host listeners (expect 8088 if that was the old mapping):"
ss -lntp | grep -E '8088|8080|:80 |:443 ' || true
echo
echo "If STATUS is Up and nginx no longer says host not found:"
echo "  open https://196.189.236.140:8088/app/agent_dashboard/index.php?agent=Agent1&domain=client1.skykin.local"
echo "  (http:// on 8088 is fine if this container only serves HTTP)"
echo
echo "Rollback: docker stop $WEB && docker rm $WEB && docker rename \$(cat /tmp/skykin-web-backup-name) $WEB && docker start $WEB"
