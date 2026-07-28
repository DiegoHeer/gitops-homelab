# Cloudflare Tunnel — wildcard ingress via Traefik

- **Date**: 2026-07-28
- **Status**: Approved (design)
- **Author**: Diego (with Claude)

## Problem

Every externally-exposed service requires a **manual** entry in the Cloudflare
Zero Trust dashboard. The tunnel is *remotely-managed* (runs with `--token`), so
all 13 public hostnames and their origins live only in the dashboard, each
pointing directly at a container:

| Public hostname | Current tunnel origin |
|---|---|
| `immich.dynabase.nl` | `http://immich_server:2283` |
| `seerr.dynabase.nl` | `http://seerr:5055` |
| `nextcloud.dynabase.nl` | `http://nextcloud:80` |
| `audiobookshelf.dynabase.nl` | `http://audiobookshelf:80` |
| `tandoor.dynabase.nl` | `http://tandoor:80` |
| `homeassistant.dynabase.nl` | `http://192.168.1.229:8123` |
| `romm.dynabase.nl` | `http://romm:8080` |
| `n8n.dynabase.nl` | `http://n8n:5678` |
| `doco-cd.dynabase.nl` | `http://doco-cd:80` |
| `rustfs.dynabase.nl` | `http://rustfs:9000` |
| `mattermost.dynabase.nl` | `http://mattermost:8065` |
| `auth.dynabase.nl` | `http://authelia:9091` |
| `grist.dynabase.nl` | `http://grist:9999` |

There is no git-declared source of truth for *which* services are public, and
adding/removing exposure is a click-op.

## Goal

Make "is this service public or local-only?" a **declarative, git-controlled
decision** expressed through the existing hostname convention, with **no
per-service Cloudflare dashboard work** after the one-time migration.

Non-goals (explicit follow-ups, not this change):

- Putting Authelia in front of exposed services. Deferred.
- Switching the tunnel to locally-managed (`config.yml`) or introducing
  Terraform/DockFlare — rejected below.

## Decision

Replace the 13 per-service tunnel ingress rules with a **single wildcard rule**
`*.dynabase.nl → https://traefik:443`, making **Traefik the sole tunnel origin**.
Traefik routes to the correct backend by Host header, exactly as it already does
for `*.local.dynabase.nl`.

### Control mechanism

The router hostname in git is the switch. DNS scoping enforces the split:
`*.dynabase.nl` matches only single-label subdomains, so `foo.local.dynabase.nl`
(two labels) is never caught by the public wildcard.

| Traefik router rule | Result |
|---|---|
| `Host(\`foo.local.dynabase.nl\`)` only | Local-only (Pi-hole DNS; never traverses the tunnel) |
| add a `Host(\`foo.dynabase.nl\`)` router | Public via the tunnel |

- **Default is local** — the existing convention. A service is public *only* if
  someone commits a bare-hostname router.
- **Auditable** — public services are grep-able (`-external` routers / bare
  `dynabase.nl` hosts) in git.
- **Unmatched public hostnames 404 at Traefik** — no silent exposure.

## Architecture

```
Internet ──> Cloudflare edge (TLS) ──> Tunnel (*.dynabase.nl) ──> https://traefik:443
                                                                        │ routes by Host
                        ┌───────────────────────────────────────────────┤
                        ▼                        ▼                        ▼
                 immich_server:2283        n8n:5678           192.168.1.x:8123 (HA)
```

Local traffic is unchanged: Pi-hole resolves `*.local.dynabase.nl` to Traefik on
the LAN; those names never enter the tunnel.

## Components / changes

### 1. Traefik — wildcard cert

Add `*.dynabase.nl` as Traefik's default certificate via the existing
`cloudflare` DNS-01 resolver (a `tls.stores.default.defaultGeneratedCert` entry,
or equivalent). Every public router then serves a valid cert with **no
per-service cert config**. `caServer` stays Let's Encrypt production.

### 2. Traefik — per-service public routers

Each public service gets a `<svc>-external` router (`Host(\`svc.dynabase.nl\`)`,
`entrypoints=https`, `tls`), alongside its existing `-local` router — following
the established n8n pattern.

- 8 services already have a `-local` Traefik router (immich, seerr, nextcloud,
  audiobookshelf, tandoor, romm, rustfs, n8n) — routing their public hostname
  through Traefik is the same proven path, plus one Host rule.
- 5 services currently bypass Traefik (home_assistant, doco-cd, mattermost,
  authelia, grist) — their router is net-new. home_assistant uses the
  file-provider (like its local entry); the rest use compose labels on
  `home_server_network`.

### 3. Cloudflare (one-time, manual — remotely-managed tunnel)

After all services are migrated and verified:

1. Add wildcard public hostname `*.dynabase.nl → https://traefik:443`
   (No TLS Verify on, belt-and-suspenders even with the real cert).
2. Add a proxied wildcard `*.dynabase.nl` CNAME → the tunnel.
3. Remove the 13 per-service ingress rules and their specific CNAMEs (redundant).

## Migration plan (incremental, one service at a time)

Migrate each service's tunnel origin from `http://container` to
`https://traefik` individually, verifying before moving on, then collapse to the
wildcard at the end.

Per service:

1. Add the `<svc>-external` Traefik router in the repo → `git push` → DocoCD
   deploys it. Harmless until the tunnel sends bare-hostname traffic to Traefik.
2. **Pre-flip test on the server** (no public DNS change):
   `curl -k -H "Host: svc.dynabase.nl" https://<server>/` — confirms Traefik
   resolves the router and the backend responds.
3. **Repoint** that service's tunnel ingress `http://container:port →
   https://traefik:443` in the dashboard, then **test the live public URL**.
4. If a backend does not proxy cleanly (websockets, large uploads,
   Host-header/redirect quirks — likely: immich, nextcloud, mattermost,
   home_assistant), pause and handle it (Traefik middleware / headers) before
   continuing.

Finally, once all 13 point at Traefik and pass: collapse the specific rules into
the single `*.dynabase.nl` wildcard (Cloudflare step above).

### Service-specific notes

- **home_assistant** — the tunnel points at `http://192.168.1.229:8123`, but the
  existing Traefik file-provider `homeassistant` service points at
  `http://192.168.1.10:8123`. Confirm the correct IP during the HA phase. HA also
  needs Traefik's IP in its `trusted_proxies`.
- **doco-cd** — no UI, but it is the **GitHub webhook receiver** that triggers
  every deploy. It must stay publicly reachable. Migrate it **last** and verify
  the webhook still fires (push a trivial commit, confirm a deploy runs) before
  and after the change.

## Rejected alternatives

- **DockFlare** (label-driven reconciler syncing tunnel ingress + DNS + Access
  from Docker labels) — purpose-built and a good fit, but needs a broad
  Cloudflare API token (a new bootstrap-tier secret to rotate), adds a stateful
  service + Docker socket exposure, and keeps state in Cloudflare rather than
  git. Overkill while Authelia is deferred.
- **Terraform / OpenTofu Cloudflare provider** — most git-native, but introduces
  a whole new tool + state backend into an Ansible + Compose + DocoCD repo.
- **Locally-managed tunnel (`config.yml` in git)** — only covers ingress; DNS
  still needs the API, no Access policies, and Cloudflare recommends
  remotely-managed for most cases.

## Verification

- Per service: the curl-with-Host pre-flip check + live-URL check are the
  evidence gate before each dashboard repoint.
- doco-cd: a real webhook-triggered deploy must succeed after migration.

## Deliverables

- New ADR superseding **ADR 0007** documenting the wildcard-tunnel-via-Traefik
  model, the hostname-convention control, rejected alternatives, and the
  deferred-Authelia follow-up (same PR set, per `CLAUDE.md`).
- Traefik `traefik.yml` wildcard default cert.
- `<svc>-external` routers for the 13 public services (compose labels / file
  provider).
- Cloudflare dashboard changes are manual and tracked in the ADR/runbook, not
  code.

### PR structure (respecting the ~600-line soft cap, atomic commits)

1. **Foundation** — wildcard default cert in `traefik.yml` + superseding ADR +
   convention docs.
2. **Per-service routers** — the `-external` routers, grouped by change type.

The Cloudflare flip is executed manually alongside PR 2's rollout, per the
incremental migration plan.
