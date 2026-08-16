# 0024 — Detect gluetun netns orphaning rather than self-heal it

- **Status**: Accepted
- **Date**: 2026-08-16
- **Deciders**: Diego

## Context

Five media services run inside gluetun's network namespace via `network_mode: service:gluetun`.
Restarting gluetun gives it a new network sandbox; the dependents keep a handle on the old one,
which the kernel strips to bare loopback. On 2026-08-15 all five served HTTP 502 through Traefik
while Docker reported every one `healthy`, because each healthcheck only probed `localhost` —
which still answers inside the dead namespace. Only ad-hoc restarts orphan: `docker compose up -d`
recreates dependents correctly, and a crash handled by the restart policy reuses the sandbox.

## Decision

Add a route-table probe (`ip route show default | grep -q .`) to each dependent's healthcheck so
the orphaned state reports `unhealthy`, gate the dependents on `service_healthy`, and recover with
`make restart media` per a runbook. Rejected: an `autoheal` sidecar (a permanent
Docker-socket-privileged container for a once-seen failure, against ADR 0017's lightweight stance)
and re-architecting gluetun as a routed gateway (large rework of a working stack, with leak risk
the shared namespace prevents today).

This complements `scripts/doco.sh` (ADR 0022), which already restarts namespace providers before
their dependents. That tooling prevents the orphaning it causes itself; this ADR covers detection
when something else causes it, and makes `doco.sh`'s post-restart health wait meaningful for the
dependents rather than a check that always passes.

## Consequences

- `+` The failure is visible in `docker ps`, Beszel and Uptime Kuma instead of silent
- `+` No new containers; probe is instant and does no network I/O
- `+` Dependents now wait for a working tunnel, not just a started container
- `+` The probe tests the route table rather than egress. What `ip route show default` matches is
  the Docker bridge route (`default via 172.18.0.1 dev eth0`); OpenVPN uses the `def1` split
  (`0.0.0.0/1` + `128.0.0.0/1` via tun0) specifically so it never replaces that entry, so a tunnel
  reconnect should not clear it — and the dependents' 90s retry budget (`retries: 3` ×
  `interval: 30s`) absorbs the window if it ever does
- `+` `doco.sh`'s `wait_healthy` now gets a truthful signal from the dependents it restarts
- `−` A VPN outage now fails the whole media stack's deploy, not just the five dependents: compose
  aborts on the gate with `dependency failed to start: container gluetun is unhealthy` and exits 1,
  so DocoCD rolls the stack back — including jellyfin, seerr, navidrome, audiobookshelf and
  grimmory, which have nothing to do with the VPN
- `−` Recovery stays manual — see `docs/runbooks/recover-gluetun-netns-orphan.md`
- `−` `depends_on` is honoured only by Compose, so a raw `docker restart gluetun` can still orphan;
  this change makes that visible rather than preventing it
- `−` gluetun's healthcheck now runs every 10s instead of every 60s to keep the new gate from
  stalling a deploy into a DocoCD rollback; `ping -W 5` bounds a failing probe so convergence to
  `unhealthy` is ~120s rather than the ~150s an unbounded `ping` would take
- `−` The probe depends on `ip` being present in each dependent image. If a base-image bump ever
  drops iproute2, `CMD-SHELL` returns 127 and every dependent goes permanently unhealthy — which
  looks identical to a real orphaning event and blocks deploys on the gate
- `+` A host reboot does not orphan: observed on 2026-08-16 after an unattended kernel upgrade
  (6.8.0-107 → 6.8.0-137) rebooted the host, when all six containers came back sharing one
  namespace and every service returned 200/302 through Traefik

## Evidence

- `services/media/docker-compose.yaml` — orphan probe, `service_healthy` gates, tuned gluetun healthcheck
- `docs/runbooks/recover-gluetun-netns-orphan.md`
- `scripts/doco.sh` — `namespace_providers()` / `wait_healthy()`, the provider-first restart ordering
- ADR 0017 (lightweight observability), whose stance ruled out the autoheal sidecar
- ADR 0022 (SSH over the DocoCD API for debug tooling), which introduced `doco.sh`
