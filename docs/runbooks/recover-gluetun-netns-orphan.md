# Runbook: Recover gluetun-routed services from netns orphaning

**When to use this**: `sonarr`, `radarr`, `prowlarr`, `sabnzbd` or `qbittorrent` return HTTP 502
through Traefik, while `jellyfin` and `seerr` are fine. Since 2026-08-16 the affected containers
also report `unhealthy`; before that change they misleadingly reported `healthy`.

**Estimated time**: 2 minutes.

**Risks**: Low. Restarting the dependents interrupts in-flight downloads briefly. Never restart
`gluetun` *on its own* — that gives it a fresh sandbox and re-orphans every dependent. Restarting
gluetun is only safe when the dependents are restarted after it comes back healthy, which is
exactly what `make restart` does for you.

## Prerequisites

- [ ] SSH access to the host (`ssh server`)
- [ ] A checkout of this repo, for the `make` front door

## Steps

1. Confirm the diagnosis by comparing network namespace inodes. All six must match:

   ```bash
   ssh server 'for c in gluetun sonarr radarr prowlarr sabnzbd qbittorrent; do
     pid=$(docker inspect -f "{{.State.Pid}}" $c)
     printf "%-12s %s\n" "$c" "$(sudo readlink /proc/$pid/ns/net)"
   done'
   ```

   If the dependents share an inode that differs from gluetun's, they are orphaned.

2. Restart the stack with the repo's tooling, which knows about this failure mode:

   ```bash
   make restart media
   ```

   `scripts/doco.sh` restarts namespace providers (`gluetun`) first, waits for them to become
   healthy, and only then restarts the dependents. If gluetun does not come back healthy within
   `HEALTH_TIMEOUT` (90s default), it deliberately leaves the dependents alone rather than
   rejoining them to a dead namespace. Preview with `make restart media DRY=1`.

### Fallback: recover by hand

Only if `make` is unavailable. Check gluetun is already healthy first — restarting dependents onto
a broken tunnel achieves nothing:

```bash
ssh server 'docker inspect -f "{{.State.Health.Status}}" gluetun'
ssh server 'docker restart sonarr radarr prowlarr sabnzbd qbittorrent'
```

Restart **only** the five dependents here, never gluetun.

## Verification

1. All six inodes now match — re-run the command from step 1.

2. The services answer through Traefik (302 or 200, not 502):

   ```bash
   for u in sonarr radarr prowlarr sabnzbd qbittorrent; do
     printf "%-12s -> " $u
     curl -s -k -o /dev/null -w "%{http_code}\n" --max-time 10 https://$u.local.dynabase.nl/
   done
   ```

3. Traffic still leaves via the VPN, not your home connection:

   ```bash
   ssh server 'docker exec sonarr curl -s --max-time 20 https://ipinfo.io/ip'
   curl -s https://ipinfo.io/ip
   ```

   The first must be the Surfshark IP and must differ from the second.

## Rollback

None needed — the procedure only restarts containers. If a service fails to come back, check its
logs with `docker logs <name> --tail 50`; `restart: unless-stopped` will keep retrying.

## Background

Root cause and the reasoning behind detecting this rather than auto-healing it are recorded in
[ADR 0024](../adr/0024-gluetun-netns-orphan-detection.md).
