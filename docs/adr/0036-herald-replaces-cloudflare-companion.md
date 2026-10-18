# 0036 — Herald replaces traefik-cloudflare-companion

- **Status**: Accepted
- **Date**: 2026-10-18
- **Deciders**: Diego

## Context

ADR 0020 picked `tiredofit/traefik-cloudflare-companion` to create a proxied
Cloudflare CNAME for each public `*.dynabase.nl` Traefik router. Upstream renamed
the project to [Herald](https://github.com/nfrastack/herald) (`nfrastack/herald`)
and deleted the old Docker Hub repository. DocoCD pulls every image before a
deploy, so the whole `networking` stack failed with `pull access denied` and
blocked the Traefik and cloudflared updates from PR #280. Pinning the cached image
(`pull_policy: never`) was rejected: it gets no updates and breaks as soon as the
image cache is lost, for example on the new server.

## Decision

Run `nfrastack/herald` in the `networking` stack in its place. It is configured
from a git-tracked `services/networking/herald/herald.yaml` with
`HERALD_SETUP_TYPE=manual`, because the container's auto mode always enables the
Docker input and we do not mount the Docker socket. The behaviour from ADR 0020
stays the same: Traefik poll only, proxied CNAME → the `home_server` tunnel,
`*.local.dynabase.nl` excluded, and the same `CF_TOKEN` (read via `env://CF_TOKEN`).

## Consequences

- `+` The stack deploys again, and the DNS sidecar is back on a maintained image
  that Renovate can update.
- `+` The config is plain YAML in git, so changes are reviewed like any other file.
- `−` Herald 2.3.4 parses `exclude_subdomains` but does not apply it, so the
  `*.local` exclusion is a negated Traefik `rule` filter instead. That filter is a
  glob match on the whole rule, and `*` does not cross a `/`; a `*.local` router
  with a path in its rule (for example `PathPrefix`) would not be excluded.
- `−` As with the companion, records are not deleted when a router goes away
  (`record_remove_on_stop: false`); stale CNAMEs are pruned by hand.

## Relationship to ADR 0020

Supersedes ADR 0020. Its reasoning (Free plan, no proxied wildcard, reuse of the
DNS:Edit token) still holds; only the tool changes.

## Evidence

- `services/networking/docker-compose.yaml` — `herald` service
  (image `nfrastack/herald:2.3.4`)
- `services/networking/herald/herald.yaml` — Traefik input, Cloudflare output,
  `dynabase.nl` domain
- DocoCD job `01a14c8d-d676-7275-b906-7d28e3ac6808` — the failed pull of
  `tiredofit/traefik-cloudflare-companion` at `ae15bc1`
