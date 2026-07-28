# 0020 — Cloudflare DNS sync via traefik-cloudflare-companion

- **Status**: Accepted
- **Date**: 2026-07-29
- **Deciders**: Diego

## Context

ADR 0019 routes all external traffic through a single wildcard tunnel ingress
rule (`*.dynabase.nl → https://traefik:443`) and — in its rejected alternatives —
declined DockFlare, on the assumption that a single proxied `*.dynabase.nl` DNS
record could deliver every hostname to the tunnel with no per-service DNS work.

During rollout that assumption broke: the `dynabase.nl` zone is on Cloudflare's
**Free** plan, and Cloudflare only proxies **wildcard** DNS records on
**Enterprise**. A Cloudflare Tunnel requires the DNS record to be *proxied*
(orange cloud), so a proxied `*.dynabase.nl` → tunnel is not possible here — the
existing wildcard is DNS-only and points at the apex. The only option on Free is
a **per-service proxied CNAME → tunnel**, which is manual dashboard work — the
toil ADR 0019 set out to remove.

## Decision

Run [tiredofit/traefik-cloudflare-companion](https://github.com/tiredofit/docker-traefik-cloudflare-companion)
as a sidecar in the `networking` stack, in **Traefik poll mode** (reads the
Traefik API; no Docker socket). It auto-creates/refreshes a **proxied CNAME →
the `home_server` tunnel** for every public `*.dynabase.nl` Traefik router,
excluding the LAN-only `*.local.dynabase.nl` names (those stay Pi-hole's job via
`traefik-pihole-dns-sync`). It authenticates with a **Zone:DNS:Edit-scoped**
Cloudflare token stored in SOPS (`services/networking/secrets.enc.env` as
`CF_TOKEN`).

This restores git-only exposure: add a `-external` Traefik router → the companion
creates the DNS record → the wildcard tunnel ingress routes it to Traefik.

## Consequences

- `+` Exposing a service stays a single git change (the Traefik router); the
  public DNS record is created automatically — no dashboard, no manual CNAME.
- `+` Narrow blast radius: a **DNS:Edit-only** token, versus DockFlare's
  Tunnel + Access + DNS scope — so the broad-token objection that led ADR 0019
  to reject DockFlare does not apply here.
- `+` Mirrors the existing `traefik-pihole-dns-sync` sidecar (LAN DNS); this is
  its public/Cloudflare analogue, same "read Traefik → write DNS" pattern.
- `−` A stored Cloudflare token (bootstrap-tier secret) now lives in the
  networking stack; compromise means DNS-record tampering on the zone, and
  rotation is a manual SOPS edit.
- `−` The companion does not delete a CNAME when a route is removed; a stale
  record just 404s at Traefik and must be pruned manually.
- `−` The root cause is the Free-plan wildcard-proxy limit; on Enterprise a
  single proxied `*.dynabase.nl` CNAME would remove the need for the companion.

## Relationship to ADR 0019

Complements — does **not** supersede — ADR 0019. The wildcard-tunnel-via-Traefik
decision stands; this ADR only revises 0019's DNS premise (a proxied wildcard
record), which the Free plan does not allow.

## Evidence

- `services/networking/docker-compose.yaml` — `traefik_cloudflare_companion`
  service (image `tiredofit/traefik-cloudflare-companion:7.4.0`, poll mode)
- `services/networking/secrets.enc.env` — `CF_TOKEN` (Zone:DNS:Edit)
