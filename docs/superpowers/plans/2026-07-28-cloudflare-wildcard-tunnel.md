# Cloudflare Wildcard Tunnel via Traefik — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace 13 manually-managed per-service Cloudflare tunnel ingress rules with a single wildcard `*.dynabase.nl → https://traefik:443`, so that whether a service is public or local-only becomes a git-declared decision (a bare-hostname Traefik router) with no dashboard work.

**Architecture:** Traefik becomes the tunnel's sole origin and routes by Host header, exactly as it already does for `*.local.dynabase.nl`. A service is public iff it has a `Host(\`svc.dynabase.nl\`)` router in git; DNS scoping keeps `*.local.dynabase.nl` (two labels) out of the public `*.dynabase.nl` wildcard (one label). No Authelia changes — deferred.

**Tech Stack:** Docker Compose, Traefik v3 (docker + file providers), cloudflared (remotely-managed tunnel), DocoCD (GitOps reconciler), Cloudflare DNS-01 (Let's Encrypt via existing `cloudflare` resolver).

## Global Constraints

- YAML: 160-char line limit (yamllint); `.yaml` extension for compose files.
- Container names snake_case matching the service key; every service on external `home_server_network`; healthcheck + `restart: unless-stopped` (unchanged here — we only add labels).
- Commit format: `Category|Action: description` (Categories: `Services`, `Ansible`, `Infrastructure`, `Config`; Actions: `Add`, `Refactor`, `Remove`, `Fix`, `Update`, `Migrate`). Group commits by change type; atomic; working state after each.
- Any change to how a subsystem works ships with an ADR in the same PR. Once `Accepted`, an ADR is superseded (status `Superseded by NNNN`), never rewritten.
- PR soft cap ~600 LoC (source and tests counted separately). This plan is well under.
- Repo lint gate (CI `quality-check.yml`): `uv run yamllint .` and `uv run ansible-lint`.
- Traefik static config: `services/networking/traefik/traefik.yml`. Traefik dynamic file config: `services/networking/traefik/config.yml`. Both bind-mounted read-only; edits reload via file provider without container recreate (`cd.doco.deployment.recreate.ignore` label).
- Deploys happen only on push to `main` (DocoCD webhook). **DNS-sync caveat:** the `traefik-pihole-dns-sync` sidecar (`services/networking/docker-compose.yaml:40`) auto-syncs *every* Traefik router host into Pi-hole with **no filter** (confirmed: the tool exposes no include/exclude option). So a new bare-hostname router starts resolving to Traefik **on the LAN and for internal container-to-container calls** the moment it deploys — before any Cloudflare change. Router additions are inert on the *public* (WAN) path only. See the DNS-sync note under PR 2.
- Server reachable via `ssh server` (192.168.1.10, user diego) for runtime verification.

## Inventory (current state, verified)

13 public hostnames, each a dashboard ingress rule pointing directly at a container:

| Hostname | Current tunnel origin | Repo router today | Action needed in repo |
|---|---|---|---|
| immich.dynabase.nl | http://immich_server:2283 | `immich` (local only) | add `immich-external` |
| seerr.dynabase.nl | http://seerr:5055 | `seerr` (local only) | add `seerr-external` |
| nextcloud.dynabase.nl | http://nextcloud:80 | `nextcloud` (local only) | add `nextcloud-external` |
| audiobookshelf.dynabase.nl | http://audiobookshelf:80 | `audiobookshelf` (local only) | add `audiobookshelf-external` |
| tandoor.dynabase.nl | http://tandoor:80 | `tandoor` (local only) | add `tandoor-external` |
| romm.dynabase.nl | http://romm:8080 | `romm` (local only) | add `romm-external` |
| rustfs.dynabase.nl | http://rustfs:9000 | `rustfs-api` (local only; container has 2 services) | add `rustfs-external` **with explicit `service=rustfs-api`** |
| n8n.dynabase.nl | http://n8n:5678 | `n8n-external` **already exists** | none |
| doco-cd.dynabase.nl | http://doco-cd:80 | `doco-cd` (bare host) **already exists** | none |
| grist.dynabase.nl | http://grist:9999 | none (bypasses Traefik) | add `grist` router |
| mattermost.dynabase.nl | http://mattermost:8065 | none (bypasses Traefik) | add `mattermost` router |
| auth.dynabase.nl | http://authelia:9091 | none (bypasses Traefik) | add `authelia` router |
| homeassistant.dynabase.nl | http://192.168.1.229:8123 | file-provider `homeassistant` → 192.168.1.10:8123 (local) | add `homeassistant-external` file router (+ IP decision) |

Notes:
- All target compose stacks are on external `home_server_network` (verified: tools, collaboration, security, photos, media, storage, games, ai).
- Traefik's `cloudflare` DNS-01 resolver already mints `*.local.dynabase.nl`; `dynabase.nl` is the same Cloudflare zone, so the same token/resolver mints `*.dynabase.nl`.
- `grist` already runs OIDC against `https://auth.dynabase.nl` and assumes `GRIST_OIDC_SP_HOST=https://grist.dynabase.nl` — both already live; routing through Traefik does not touch OIDC (grist talks to authelia directly).

---

## PR 1 — Foundation (wildcard cert + ADR + docs)

### Task 1: Mint the `*.dynabase.nl` wildcard certificate

**Files:**
- Modify: `services/networking/docker-compose.yaml` (traefik service labels, after line 29)

**Interfaces:**
- Produces: a stored ACME wildcard cert for `*.dynabase.nl`, served by SNI to every `Host(\`*.dynabase.nl\`)` router with `tls=true`. Consumed by all routers added in PR 2.

Rationale: the existing `traefik-secure` router already carries `tls.certresolver=cloudflare` and `tls.domains[0]` for `*.local.dynabase.nl`. Adding a second `tls.domains[1]` entry triggers Traefik to obtain the `*.dynabase.nl` wildcard via the same DNS-01 resolver. No per-service cert config is then needed anywhere.

- [ ] **Step 1: Add the wildcard cert domains**

Insert immediately after line 29 (`...tls.domains[0].sans=*.local.dynabase.nl`), before `...service=api@internal`:

```yaml
      - "traefik.http.routers.traefik-secure.tls.domains[1].main=dynabase.nl"
      - "traefik.http.routers.traefik-secure.tls.domains[1].sans=*.dynabase.nl"
```

- [ ] **Step 2: Lint**

Run: `uv run yamllint services/networking/docker-compose.yaml`
Expected: no errors (lines are ~90 chars, under the 160 limit).

- [ ] **Step 3: Commit**

```bash
git add services/networking/docker-compose.yaml
git commit -m "Infrastructure|Add: request *.dynabase.nl wildcard cert on Traefik"
```

- [ ] **Step 4: Post-deploy verification (after this PR merges to main and DocoCD recreates Traefik)**

Changing the traefik container labels triggers a recreate (labels are container config, not covered by the `recreate.ignore` for bind mounts). After deploy:

Run: `ssh server "sudo grep -c '\*\.dynabase\.nl' /home/diego/services_data/networking/traefik/acme.json"`
Expected: count ≥ 1 (the `*.dynabase.nl` SAN is present). Match the wildcard SAN specifically — grepping the bare string `dynabase.nl` would false-positive on the existing `*.local.dynabase.nl` cert. If 0 after ~2 min, check `ssh server "docker logs traefik 2>&1 | grep -i acme | tail -20"` for DNS-01 errors.

Run: `ssh server "curl -sk --resolve immich.dynabase.nl:443:127.0.0.1 https://immich.dynabase.nl -o /dev/null -w '%{ssl_verify_result}\n' -v 2>&1 | grep -i 'subject\|CN'"`
Expected: presents a Let's Encrypt cert whose SAN covers `*.dynabase.nl` (not the self-signed Traefik default).

---

### Task 2: Add ADR 0019 and supersede ADR 0007

**Files:**
- Create: `docs/adr/0019-wildcard-tunnel-via-traefik.md`
- Modify: `docs/adr/0007-cloudflare-tunnel-external-exposure.md:3` (status line)
- Modify: `docs/adr/README.md` (index table — add 0019 row, update 0007 status)

**Interfaces:** none (documentation).

- [ ] **Step 1: Write ADR 0019**

Create `docs/adr/0019-wildcard-tunnel-via-traefik.md`:

```markdown
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
```

- [ ] **Step 2: Mark ADR 0007 superseded**

In `docs/adr/0007-cloudflare-tunnel-external-exposure.md`, change line 3 from:

```markdown
- **Status**: Accepted
```

to:

```markdown
- **Status**: Superseded by 0019
```

- [ ] **Step 3: Update the ADR index**

In `docs/adr/README.md`, find the table row for 0007 and set its status column to `Superseded by 0019`, then add a new row after the 0018 row:

```markdown
| [0019](0019-wildcard-tunnel-via-traefik.md) | Wildcard Cloudflare Tunnel via Traefik | Accepted |
```

(Match the exact column layout of the existing rows — verify with `sed -n '1,40p' docs/adr/README.md` first and copy the format.)

- [ ] **Step 4: Lint**

Run: `uv run yamllint docs/adr/`
Expected: no errors. (Markdown isn't yamllinted, but this catches any stray YAML; the real check is that the files render as intended.)

- [ ] **Step 5: Commit**

```bash
git add docs/adr/0019-wildcard-tunnel-via-traefik.md docs/adr/0007-cloudflare-tunnel-external-exposure.md docs/adr/README.md
git commit -m "Infrastructure|Add: ADR 0019 wildcard tunnel via Traefik, supersede 0007"
```

---

### Task 3: Document the "expose a service publicly" convention

**Files:**
- Modify: `README.md` (near the existing "Adding a stack" / service section)

**Interfaces:** none (documentation).

- [ ] **Step 1: Locate the docs section**

Run: `grep -n "Adding a stack\|webhook_filter\|Removing a stack\|## " README.md | head -40`
Identify the section that documents adding/removing a stack (around the `.doco-cd.yml` instructions).

- [ ] **Step 2: Add an "Exposing a service publicly" subsection**

Insert after the add/remove-stack instructions:

```markdown
### Exposing a service publicly (Cloudflare Tunnel)

External access is controlled entirely in git by the service's Traefik router
hostname — no Cloudflare dashboard work (see [ADR 0019](docs/adr/0019-wildcard-tunnel-via-traefik.md)):

- **Local-only** (default): the service's Traefik router matches
  `svc.local.dynabase.nl`. Resolved by Pi-hole on the LAN; never traverses the tunnel.
- **Public**: add a second router matching `svc.dynabase.nl` with
  `entrypoints=https` and `tls=true` (follow the `*-external` pattern, e.g. n8n).
  The wildcard tunnel `*.dynabase.nl → Traefik` routes it automatically.

Because `*.dynabase.nl` is a catch-all to Traefik, **any** bare-hostname router is
immediately public — review exposure at PR time. Unmatched public hostnames 404.
```

- [ ] **Step 3: Lint**

Run: `uv run yamllint README.md` (no-op for prose, but confirms no accidental YAML block breakage) and visually confirm the backticks render.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "Infrastructure|Add: document public-exposure convention in README"
```

- [ ] **Step 5: Open PR 1**

```bash
git push -u origin worktree-cloudflare-wildcard-tunnel
gh pr create --draft --title "Infrastructure: wildcard Cloudflare tunnel — foundation (cert + ADR)" --body "Foundation for ADR 0019. Adds the *.dynabase.nl wildcard cert and documents the exposure convention. Inert until PR 2 routers + the manual tunnel cutover. Spec: docs/superpowers/specs/2026-07-28-cloudflare-wildcard-tunnel-design.md"
```

---

## PR 2 — Per-service public routers

> **DNS-sync interaction (read before merging).** The `traefik-pihole-dns-sync` sidecar syncs every Traefik router host into Pi-hole with no filter, so each bare-hostname router below becomes LAN-resolvable to Traefik **at deploy time**, not at the manual cutover. The **public/WAN** path is unchanged until the cutover (the tunnel still points at direct container origins), but LAN clients and internal containers flip immediately. This is already true and healthy for the existing `n8n.dynabase.nl` and `doco-cd.dynabase.nl` routers.
>
> Consequence for sequencing: **`auth.dynabase.nl` is consumed server-side by Grist's OIDC** (`GRIST_OIDC_IDP_ISSUER`). Adding the `authelia` router reroutes Grist's login discovery/token calls through Traefik→Authelia the moment it deploys. To keep that change inside a tested gate, this PR is split:
>
> - **PR 2a** (Task 4, Task 5, Task 6): all routers whose bare host has **no internal consumer** — the 7 `-external` routers, `mattermost`, and `home_assistant`. LAN shift here is benign (parallel to each service's existing `.local` route).
> - **PR 2b** (Task 7): `grist` + `authelia` together, merged **only after PR 2a is deployed and the LAN + Grist-OIDC verification (Task 7 Step 1) passes**.
>
> **Pre-check before PR 2a** (proves the pattern is healthy): `ssh server "docker logs traefik-pihole-dns-sync --since 10m 2>&1 | tail -20"` and confirm `n8n.dynabase.nl` / `doco-cd.dynabase.nl` already resolve on the LAN to Traefik (`ssh server "nslookup n8n.dynabase.nl 192.168.1.11"`). If the sidecar is erroring or those hosts are broken locally, STOP and resolve that first.

### Task 4: Add `-external` routers to the 7 Traefik-backed services

**Files:**
- Modify: `services/photos/docker-compose.yaml` (immich_server labels, after line 22)
- Modify: `services/media/docker-compose.yaml` (seerr labels after line 37; audiobookshelf labels after line 243)
- Modify: `services/storage/docker-compose.yaml` (nextcloud labels after line 52; rustfs labels after line 98)
- Modify: `services/tools/docker-compose.yaml` (tandoor labels after line 107)
- Modify: `services/games/docker-compose.yaml` (romm labels after line 25)

**Interfaces:**
- Consumes: the `*.dynabase.nl` wildcard cert from Task 1.
- Produces: public routers `immich-external`, `seerr-external`, `audiobookshelf-external`, `nextcloud-external`, `rustfs-external`, `tandoor-external`, `romm-external`.

Each container defines exactly one Traefik service (so the router auto-binds) **except rustfs**, whose container defines `rustfs-api` and `rustfs-console` — its external router must set `service=rustfs-api` explicitly.

- [ ] **Step 1: immich** — append after the `immich` service-port label (line 22) in `services/photos/docker-compose.yaml`:

```yaml
      - "traefik.http.routers.immich-external.rule=Host(`immich.dynabase.nl`)"
      - "traefik.http.routers.immich-external.entrypoints=https"
      - "traefik.http.routers.immich-external.tls=true"
```

- [ ] **Step 2: seerr** — append after the `seerr` service-port label (line 37) in `services/media/docker-compose.yaml`:

```yaml
      - "traefik.http.routers.seerr-external.rule=Host(`seerr.dynabase.nl`)"
      - "traefik.http.routers.seerr-external.entrypoints=https"
      - "traefik.http.routers.seerr-external.tls=true"
```

- [ ] **Step 3: audiobookshelf** — append after the `audiobookshelf` service-port label (line 243) in `services/media/docker-compose.yaml`:

```yaml
      - "traefik.http.routers.audiobookshelf-external.rule=Host(`audiobookshelf.dynabase.nl`)"
      - "traefik.http.routers.audiobookshelf-external.entrypoints=https"
      - "traefik.http.routers.audiobookshelf-external.tls=true"
```

- [ ] **Step 4: nextcloud** — append after the `nextcloud` service-port label (line 52) in `services/storage/docker-compose.yaml`:

```yaml
      - "traefik.http.routers.nextcloud-external.rule=Host(`nextcloud.dynabase.nl`)"
      - "traefik.http.routers.nextcloud-external.entrypoints=https"
      - "traefik.http.routers.nextcloud-external.tls=true"
```

- [ ] **Step 5: rustfs** — append after the `rustfs-console` service-port label (line 98) in `services/storage/docker-compose.yaml` (note the explicit `service`):

```yaml
      - "traefik.http.routers.rustfs-external.rule=Host(`rustfs.dynabase.nl`)"
      - "traefik.http.routers.rustfs-external.entrypoints=https"
      - "traefik.http.routers.rustfs-external.tls=true"
      - "traefik.http.routers.rustfs-external.service=rustfs-api"
```

- [ ] **Step 6: tandoor** — append after the `tandoor` service-port label (line 107) in `services/tools/docker-compose.yaml`:

```yaml
      - "traefik.http.routers.tandoor-external.rule=Host(`tandoor.dynabase.nl`)"
      - "traefik.http.routers.tandoor-external.entrypoints=https"
      - "traefik.http.routers.tandoor-external.tls=true"
```

- [ ] **Step 7: romm** — append after the `romm` service-port label (line 25) in `services/games/docker-compose.yaml`:

```yaml
      - "traefik.http.routers.romm-external.rule=Host(`romm.dynabase.nl`)"
      - "traefik.http.routers.romm-external.entrypoints=https"
      - "traefik.http.routers.romm-external.tls=true"
```

- [ ] **Step 8: Lint all changed stacks**

Run: `uv run yamllint services/photos/docker-compose.yaml services/media/docker-compose.yaml services/storage/docker-compose.yaml services/tools/docker-compose.yaml services/games/docker-compose.yaml`
Expected: no errors.

- [ ] **Step 9: Commit**

```bash
git add services/photos/docker-compose.yaml services/media/docker-compose.yaml services/storage/docker-compose.yaml services/tools/docker-compose.yaml services/games/docker-compose.yaml
git commit -m "Services|Add: external Traefik routers for immich, seerr, audiobookshelf, nextcloud, rustfs, tandoor, romm"
```

---

### Task 5: Add the Mattermost router (bypasses Traefik today)

**Files:**
- Modify: `services/collaboration/docker-compose.yaml` (mattermost service — add a `labels:` block)

**Interfaces:**
- Consumes: the `*.dynabase.nl` wildcard cert from Task 1.
- Produces: router `mattermost` (mattermost.dynabase.nl → mattermost:8065).

> `grist` and `authelia` also bypass Traefik today but are deferred to **Task 7 / PR 2b** because `auth.dynabase.nl` is consumed server-side by Grist — see the DNS-sync note under PR 2. Mattermost has no internal consumer, so it is safe in PR 2a.

Mattermost currently has **no** Traefik labels (the tunnel hit it directly). Add a full `labels:` block. The container has one Traefik service, so no explicit `service=` is needed. The router name has no `-external` suffix because it is public-only (no `.local` counterpart), matching the existing `doco-cd` pattern.

- [ ] **Step 1: Confirm insertion point**

Run: `grep -n "container_name: mattermost" services/collaboration/docker-compose.yaml`
Add the `labels:` block inside the `mattermost` service definition (sibling to `container_name`/`image`/`environment`).

- [ ] **Step 2: mattermost labels** — add to the `mattermost` service in `services/collaboration/docker-compose.yaml`:

```yaml
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.mattermost.rule=Host(`mattermost.dynabase.nl`)"
      - "traefik.http.routers.mattermost.entrypoints=https"
      - "traefik.http.routers.mattermost.tls=true"
      - "traefik.http.services.mattermost.loadbalancer.server.port=8065"
```

- [ ] **Step 3: Lint**

Run: `uv run yamllint services/collaboration/docker-compose.yaml`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add services/collaboration/docker-compose.yaml
git commit -m "Services|Add: Traefik router for mattermost (route via Traefik)"
```

---

### Task 6: Add the Home Assistant external file-provider route

**Files:**
- Modify: `services/networking/traefik/config.yml` (add `homeassistant-external` router)

**Interfaces:**
- Consumes: the existing `homeassistant` file-provider service and the `*.dynabase.nl` wildcard cert.
- Produces: router `homeassistant-external` (homeassistant.dynabase.nl → the `homeassistant` service).

HA is `network_mode: host`, so it isn't a docker-label candidate — it uses the file provider. There is an IP discrepancy to resolve: the current tunnel origin is `192.168.1.229:8123`, but the file-provider `homeassistant` service points at `192.168.1.10:8123`.

- [ ] **Step 1: DECISION — confirm the correct HA backend**

Run: `ssh server "curl -sf -o /dev/null -w 'server(.10): %{http_code}\n' http://192.168.1.10:8123/ ; curl -sf -o /dev/null -w 'other(.229): %{http_code}\n' http://192.168.1.229:8123/"`
Also test the existing local route end-to-end: `ssh server "curl -sk -H 'Host: homeassistant.local.dynabase.nl' https://127.0.0.1/ -o /dev/null -w 'local-route: %{http_code}\n'"`

- If `.10` responds and the local route returns 200/302 → the file-provider service is correct; **reuse it** (the tunnel's `.229` was stale). Proceed to Step 2.
- If the public HA is genuinely a different device at `.229` → STOP and confirm with Diego whether public and local HA should be the same instance. If a separate backend is intended, add a distinct file-provider service pointing at `.229` and reference it from the router below instead of `homeassistant`.

- [ ] **Step 2: Add the external router**

In `services/networking/traefik/config.yml`, under `http.routers:`, add (after the existing `homeassistant` router; reuse the existing `homeassistant` service):

```yaml
    homeassistant-external:
      rule: "Host(`homeassistant.dynabase.nl`)"
      entryPoints:
        - "https"
      service: homeassistant
      tls: {}
```

- [ ] **Step 3: Verify HA trusted_proxies (do not change unless the test fails)**

The existing `homeassistant.local.dynabase.nl` route already proxies through Traefik, so HA's `configuration.yaml` should already trust Traefik's source IP. Confirm the local route worked in Step 1. If it returned 400/403 ("400: Bad Request" is HA's trusted-proxy rejection), flag to Diego that HA's `http.trusted_proxies` needs Traefik's `home_server_network` gateway IP added — that is HA-host config outside this repo, so note it in the PR and do not block the other services.

- [ ] **Step 4: Lint**

Run: `uv run yamllint services/networking/traefik/config.yml`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add services/networking/traefik/config.yml
git commit -m "Services|Add: Home Assistant external Traefik route (homeassistant.dynabase.nl)"
```

- [ ] **Step 6: Open PR 2**

```bash
git push
gh pr create --draft --title "Services: wildcard Cloudflare tunnel — public routers (PR 2a)" --body "Adds bare-hostname Traefik routers for the public services with no internal consumer: immich, seerr, audiobookshelf, nextcloud, rustfs, tandoor, romm, mattermost, home_assistant (n8n and doco-cd already had theirs; grist+authelia are PR 2b). WAN path unchanged until the manual cutover; LAN resolves these to Traefik at deploy via traefik-pihole-dns-sync. Depends on PR 1 (wildcard cert). Plan: docs/superpowers/plans/2026-07-28-cloudflare-wildcard-tunnel.md"
```

---

## PR 2b — Grist + Authelia routers (deploy after PR 2a verified)

> Split from PR 2a because `auth.dynabase.nl` is consumed server-side by Grist's OIDC; deploying it shifts Grist's live login path to Traefik→Authelia on the LAN. Merge this only after PR 2a is deployed and Step 1 below passes.

### Task 7: Add grist + authelia routers and verify Grist OIDC

**Files:**
- Modify: `services/tools/docker-compose.yaml` (grist service — add a `labels:` block)
- Modify: `services/security/docker-compose.yaml` (authelia service — add a `labels:` block)

**Interfaces:**
- Consumes: the `*.dynabase.nl` wildcard cert from Task 1; the authelia router is consumed by Grist (`GRIST_OIDC_IDP_ISSUER=https://auth.dynabase.nl`).
- Produces: routers `grist` (grist.dynabase.nl → grist:9999), `authelia` (auth.dynabase.nl → authelia:9091).

- [ ] **Step 1: Confirm PR 2a is healthy first**

Run: `for h in immich mattermost homeassistant; do ssh server "curl -sk -H 'Host: $h.dynabase.nl' https://127.0.0.1/ -o /dev/null -w '$h: %{http_code}\n'"; done`
Expected: each returns an app code (not 404). Only proceed if PR 2a's routers resolve through Traefik.

- [ ] **Step 2: grist labels** — add to the `grist` service in `services/tools/docker-compose.yaml`:

```yaml
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.grist.rule=Host(`grist.dynabase.nl`)"
      - "traefik.http.routers.grist.entrypoints=https"
      - "traefik.http.routers.grist.tls=true"
      - "traefik.http.services.grist.loadbalancer.server.port=9999"
```

- [ ] **Step 3: authelia labels** — add to the `authelia` service in `services/security/docker-compose.yaml` (note host is `auth`, not `authelia`):

```yaml
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.authelia.rule=Host(`auth.dynabase.nl`)"
      - "traefik.http.routers.authelia.entrypoints=https"
      - "traefik.http.routers.authelia.tls=true"
      - "traefik.http.services.authelia.loadbalancer.server.port=9091"
```

- [ ] **Step 4: Lint**

Run: `uv run yamllint services/tools/docker-compose.yaml services/security/docker-compose.yaml`
Expected: no errors.

- [ ] **Step 5: Commit + open PR 2b**

```bash
git add services/tools/docker-compose.yaml services/security/docker-compose.yaml
git commit -m "Services|Add: Traefik routers for grist and authelia (route via Traefik)"
git push
gh pr create --draft --title "Services: wildcard Cloudflare tunnel — grist + authelia routers (PR 2b)" --body "Routes grist.dynabase.nl and auth.dynabase.nl through Traefik. auth is consumed server-side by Grist OIDC, so this is split from PR 2a and verified separately. Depends on PR 2a deployed. Plan: docs/superpowers/plans/2026-07-28-cloudflare-wildcard-tunnel.md"
```

- [ ] **Step 6: Post-deploy verification — Grist OIDC on the LAN**

After PR 2b deploys, `auth.dynabase.nl` resolves to Traefik→Authelia for internal calls. Verify:
- `ssh server "curl -sk -H 'Host: auth.dynabase.nl' https://127.0.0.1/api/health -o /dev/null -w 'authelia: %{http_code}\n'"` → expect 200.
- From a LAN browser, log in to `https://grist.dynabase.nl` and complete the Authelia OIDC round-trip. If login fails, check `ssh server "docker logs grist --since 3m 2>&1 | grep -i oidc | tail"` and Authelia's X-Forwarded handling; if blocking, revert PR 2b (remove the two label blocks, push) — Grist's OIDC returns to the direct-tunnel path.

---

## PR-independent — Manual Cloudflare cutover runbook (executed by Diego)

> Prerequisite: PR 1, PR 2a, and PR 2b merged and deployed; Task 1 verification confirms the `*.dynabase.nl` cert is issued. This is dashboard work (remotely-managed tunnel), tracked here, not code.

### Phase A — Per-service incremental repoint (one at a time)

For each hostname **except doco-cd** (do it last), in any order:

- [ ] **A1. Pre-flip test on the server** (validates Traefik routing before touching DNS):

```bash
ssh server "curl -sk -H 'Host: SVC.dynabase.nl' https://127.0.0.1/ -o /dev/null -w '%{http_code}\n'"
```

Expected: an application response code (200/302/401 — anything but 404, which means the router isn't matching). Replace `SVC` with each hostname (e.g. `immich`, `auth`, `homeassistant`).

- [ ] **A2. Repoint the tunnel origin** in Cloudflare Zero Trust → Networks → Tunnels → (your tunnel) → Public Hostnames → edit `SVC.dynabase.nl`:
  - Service: change from `http://<container>:<port>` to `https://traefik:443`
  - Additional application settings → TLS → **No TLS Verify: On**
  - (Leave HTTP Host Header default — cloudflared preserves the request Host, which is what Traefik routes on.)

- [ ] **A3. Live test:**

```bash
curl -sI https://SVC.dynabase.nl | head -5
```

Expected: 200/302 and a valid edge cert. Exercise the app briefly (login, a page load). Watch the known-tricky ones: **nextcloud** (trusted_domains / overwrite-host redirects), **immich** (large uploads / websockets), **mattermost** (websockets), **home_assistant** (trusted_proxies), **grist** (OIDC round-trip to Authelia — verify `X-Forwarded-*` and the full login/callback flow, since it also moves from direct-tunnel to behind-Traefik).

- [ ] **A4.** If a service breaks, revert its origin to the direct `http://<container>:<port>` and investigate (Traefik middleware / headers) before retrying. Do not proceed to Phase B until all 12 (non-doco-cd) pass.

- [ ] **A5. doco-cd last** — repoint `doco-cd.dynabase.nl` to `https://traefik:443` (No TLS Verify), then verify the GitHub webhook still fires: push a trivial commit to `main` and confirm DocoCD runs a deploy (`ssh server "docker logs doco-cd --since 2m 2>&1 | tail -30"`).

### Phase B — Collapse to the wildcard

Only after all 13 origins point at `https://traefik:443` and are verified:

- [ ] **B1.** Add a proxied DNS record: `*.dynabase.nl` CNAME → `<tunnel-id>.cfargotunnel.com` (proxied / orange cloud). Find the tunnel target from any existing service's CNAME.
- [ ] **B2.** Add a wildcard public hostname to the tunnel: `*.dynabase.nl` → `https://traefik:443`, No TLS Verify On. Ensure it is the **last** ingress rule (catch-all).
- [ ] **B3.** Delete the 13 specific public-hostname rules and their specific CNAME records (now redundant).
- [ ] **B4.** Re-test a sample: `for h in immich n8n grist auth homeassistant doco-cd; do curl -sI https://$h.dynabase.nl | head -1; done`. Confirm the doco-cd webhook once more.

### Phase C — Ongoing convention (no dashboard work)

- Expose a service: add a `Host(\`svc.dynabase.nl\`)` router in git (the `*-external` pattern), commit, push. Live on deploy.
- Make a service local-only: remove/omit its bare-hostname router. It stays reachable only at `svc.local.dynabase.nl`.

---

## Self-Review

**Spec coverage:**
- Wildcard tunnel → Traefik sole origin → Phase B (runbook) + Task 1 cert. ✓
- Hostname-convention control → README Task 3, ADR Task 2, Phase C. ✓
- `*.dynabase.nl` wildcard cert via existing resolver → Task 1. ✓
- `<svc>-external` routers for the 8 Traefik-backed (7 need changes; n8n already done) → Task 4. ✓
- Routers for the 5 bypassers: mattermost → Task 5 (PR 2a); home_assistant → Task 6 (PR 2a); grist + authelia → Task 7 (PR 2b, split out because auth is consumed by Grist OIDC); doco-cd already has one (documented in inventory, repointed in A5). ✓
- DNS-sync sidecar makes bare routers LAN-live at deploy (not inert) → flagged in Global Constraints + PR 2 note; risky internal dependency (grist→auth) isolated to PR 2b with dedicated verification (Task 7 Steps 1 & 6). ✓
- Incremental, one-at-a-time-with-tests migration → Phase A. ✓
- doco-cd webhook care → A5. ✓
- home_assistant IP discrepancy → Task 6 Step 1 decision. ✓
- ADR superseding 0007 → Task 2. ✓
- PR split → PR 1 (foundation) / PR 2a (safe routers) / PR 2b (grist+authelia). ✓
- Rejected alternatives (DockFlare / Terraform / local-managed) → ADR 0019. ✓

**Placeholder scan:** `SVC`/`<container>`/`<port>`/`<tunnel-id>` in the runbook are deliberate per-iteration substitutions with explicit instructions, not unfilled plan gaps. No TBD/TODO. Every code step shows exact content.

**Type/name consistency:** router names used consistently — `immich-external`, `seerr-external`, `audiobookshelf-external`, `nextcloud-external`, `rustfs-external` (service=`rustfs-api`), `tandoor-external`, `romm-external`, `grist`, `mattermost`, `authelia`; hosts match the inventory table (`auth.dynabase.nl` for the authelia router). ADR number 0019 consistent throughout. Cert domains `dynabase.nl` + `*.dynabase.nl` consistent between Task 1 and Task 4/5 consumption.
