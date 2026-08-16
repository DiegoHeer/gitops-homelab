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
- `+` The probe tests the route table, not egress, so a routine VPN reconnect does not flap it
- `+` `doco.sh`'s `wait_healthy` now gets a truthful signal from the dependents it restarts
- `−` Recovery stays manual — see `docs/runbooks/recover-gluetun-netns-orphan.md`
- `−` `depends_on` is honoured only by Compose, so a raw `docker restart gluetun` can still orphan;
  this change makes that visible rather than preventing it
- `−` gluetun's healthcheck now runs every 10s instead of every 60s to keep the new gate from
  stalling a deploy into a DocoCD rollback
- `−` Host-reboot ordering remains unverified

## Evidence

- `services/media/docker-compose.yaml` — orphan probe, `service_healthy` gates, tuned gluetun healthcheck
- `docs/runbooks/recover-gluetun-netns-orphan.md`
- `scripts/doco.sh` — `namespace_providers()` / `wait_healthy()`, the provider-first restart ordering
- ADR 0017 (lightweight observability), whose stance ruled out the autoheal sidecar
- ADR 0022 (SSH over the DocoCD API for debug tooling), which introduced `doco.sh`
