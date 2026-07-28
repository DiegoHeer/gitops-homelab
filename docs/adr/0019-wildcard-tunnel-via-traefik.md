# 0019 — Wildcard Cloudflare Tunnel via Traefik

- **Status**: Accepted
- **Date**: 2026-07-28
- **Deciders**: Diego

## Context

ADR 0007 adopted a remotely-managed Cloudflare Tunnel. Its ingress rules live
only in the Cloudflare dashboard: 13 per-service public hostnames, each pointing
directly at a container. Exposing or unexposing a service is a manual click-op
with no git-declared source of truth.

All local services already route through Traefik as `*.local.dynabase.nl`
(docker labels + a file provider), and Pi-hole resolves those names on the LAN.

## Decision

Route all external traffic through Traefik. The tunnel gets a single ingress
rule `*.dynabase.nl → https://traefik:443` (No TLS Verify), and Traefik routes
by Host header.

Whether a service is public is a git-declared decision: a service is public iff
it has a `Host(\`svc.dynabase.nl\`)` Traefik router. DNS scoping enforces the
split — the public wildcard `*.dynabase.nl` matches only single-label
subdomains, so `svc.local.dynabase.nl` never traverses the tunnel.

Traefik mints a `*.dynabase.nl` wildcard cert via the existing `cloudflare`
DNS-01 resolver, so public routers need only `tls=true`.

Authentication posture (Authelia in front of exposed services) is intentionally
out of scope and deferred to a later ADR.

## Consequences

- `+` No dashboard work to expose/unexpose a service — one commit.
- `+` Public surface is auditable in git (grep for bare `dynabase.nl` hosts).
- `+` Default is local; public is an explicit opt-in.
- `+` Future Authelia becomes a single Traefik middleware at one choke point.
- `−` Traefik is now a hard dependency for all external access (it already was
  for local). A Traefik outage takes down public services.
- `−` The `*.dynabase.nl` wildcard means any new bare-hostname router is
  immediately public — exposure must be reviewed at PR time.
- `−` One-time manual dashboard migration (inherent to a remotely-managed
  tunnel).

## Rejected alternatives

- **DockFlare** (label-driven reconciler for tunnel ingress + DNS + Access):
  needs a broad Cloudflare API token (new bootstrap-tier secret), adds a
  stateful service + docker-socket exposure, keeps state in Cloudflare not git.
  Overkill while Authelia is deferred.
- **Terraform/OpenTofu Cloudflare provider**: most git-native, but adds a new
  tool + state backend to an Ansible + Compose + DocoCD repo.
- **Locally-managed tunnel (`config.yml` in git)**: covers ingress only; DNS
  still needs the API; Cloudflare recommends remotely-managed for most cases.

## Evidence

- `docs/superpowers/specs/2026-07-28-cloudflare-wildcard-tunnel-design.md`
- `services/networking/docker-compose.yaml` — wildcard cert + tunnel
- `services/networking/traefik/config.yml` — home_assistant external route
- Supersedes ADR 0007.
