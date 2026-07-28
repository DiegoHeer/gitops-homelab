# Move Grist to `collaboration` — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Relocate the live Grist service from the `tools` stack to the `collaboration` stack, moving its on-disk data to the matching category path, without data loss and with a single contained downtime window.

**Architecture:** Grist's compose service definition is lifted verbatim from `services/tools/` into `services/collaboration/` (only the bind-mount path changes), its two SOPS secrets move with it, and its persist directory is relocated on the host. The cutover is two-phase across two PRs with a manual, verification-gated data move in between — because the host allows only one container named `grist`, so the old instance must be fully removed before the new one starts. Grist's identity (container name, hostname, OIDC client-id) and networking are unchanged, so the Cloudflare tunnel, Authelia OIDC, and DNS need no changes.

**Tech Stack:** Docker Compose, DocoCD (GitOps reconciler, `remove_orphans: true`), SOPS + age, git-cliff (auto CHANGELOG), yamllint.

## Global Constraints

- YAML: 160-char line limit (`uv run yamllint .`); `.yaml` extension.
- Docker Compose: `container_name` snake_case matching service key; all services on external `home_server_network`; healthcheck + `restart: unless-stopped` on every service.
- Runtime state → absolute bind mounts under `/home/diego/services_data/<category>/<service>/`.
- Put **final container env var names** directly into `secrets.enc.env`; edit only via `sops`. Never commit plaintext `.env`.
- Commit format: `Category|Action: description` (Categories: `Services`, `Ansible`, `Infrastructure`, `Config`; Actions include `Add`, `Refactor`, `Remove`, `Fix`, `Update`, `Migrate`). Atomic commits — one logical change each; never mix formatting with logic.
- **Do NOT edit `CHANGELOG.md`** — git-cliff regenerates it from commit messages on release.
- **Do NOT change** `container_name: grist`, `PORT=9999`, the `home_server_network`, any `GRIST_OIDC_*` env, `.doco-cd.yml`, the Authelia OIDC client, or the Cloudflare tunnel config.
- Grist is **live and multi-user**: respect the ordered runbook gates; never run the `mv` while the container exists.

**Reference — the current Grist service block** (from `services/tools/docker-compose.yaml`), for verbatim reuse:

```yaml
  grist:
    container_name: grist
    image: gristlabs/grist:1.7.16
    restart: unless-stopped
    env_file: secrets.enc.env
    environment:
      - PORT=9999
      - GRIST_FORCE_LOGIN=true
      - GRIST_OIDC_IDP_ISSUER=https://auth.dynabase.nl
      - GRIST_OIDC_IDP_CLIENT_ID=grist
      - GRIST_OIDC_SP_HOST=https://grist.dynabase.nl
      - GRIST_OIDC_IDP_SCOPES=openid email profile
      # Authelia's discovery omits end_session_endpoint; Grist won't start without this.
      - GRIST_OIDC_IDP_SKIP_END_SESSION_ENDPOINT=true
    volumes:
      - /home/diego/services_data/tools/grist/persist:/persist
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:9999/status"]
      interval: 30s
      retries: 3
      timeout: 5s
      start_period: 10s
      start_interval: 5s
```

The two secrets to move (in `services/tools/secrets.enc.env`):
`GRIST_DEFAULT_EMAIL`, `GRIST_OIDC_IDP_CLIENT_SECRET`.

---

## File structure

- `services/tools/docker-compose.yaml` — **modify**: remove the `grist:` block.
- `services/tools/secrets.enc.env` — **modify (via sops)**: remove the two `GRIST_*` keys.
- `services/collaboration/docker-compose.yaml` — **modify**: add the `grist:` block (volume path changed to the collaboration category).
- `services/collaboration/secrets.enc.env` — **modify (via sops)**: add the two `GRIST_*` keys.
- `README.md` — **modify**: drop Grist from the Tools row; add a Collaboration row.
- Host (not in repo): `mv /home/diego/services_data/tools/grist → /home/diego/services_data/collaboration/grist`.

The work splits into two PRs. PR 1 is Task 1. The manual data move is Gate A. PR 2 is Tasks 2–3. Final live verification is Gate B.

---

## PR 1 — remove Grist from the tools stack

### Task 1: Extract Grist from the `tools` stack

**Files:**
- Modify: `services/tools/docker-compose.yaml` (remove the `grist:` service block)
- Modify: `services/tools/secrets.enc.env` (remove `GRIST_DEFAULT_EMAIL`, `GRIST_OIDC_IDP_CLIENT_SECRET`)

**Interfaces:**
- Consumes: nothing.
- Produces: a `tools` stack with no Grist. The Grist container will be removed by DocoCD's next reconcile of `tools` (`remove_orphans: true`). The persist dir at `/home/diego/services_data/tools/grist/` is untouched and is what Gate A relocates.

- [ ] **Step 1: Remove the `grist:` service block from the tools compose**

Delete the entire `grist:` block (shown in Global Constraints above) from `services/tools/docker-compose.yaml`. Leave `it-tools`, `bentopdf`, `docuseal`, `changedetection`, `tandoor`, `tandoor_postgres`, and the `networks:` section intact.

- [ ] **Step 2: Verify the compose file still parses and lints**

Run:
```bash
uv run yamllint services/tools/docker-compose.yaml
docker compose -f services/tools/docker-compose.yaml config -q
```
Expected: no yamllint errors; `config -q` prints nothing (valid) and no longer lists a `grist` service. (`docker compose config` reads the plaintext compose only; it does not need the decrypted secrets.)

- [ ] **Step 3: Remove the two Grist keys from the tools secrets**

Run `sops services/tools/secrets.enc.env` and delete exactly these two lines:
```
GRIST_DEFAULT_EMAIL="diegojonathanheer@gmail.com"
GRIST_OIDC_IDP_CLIENT_SECRET=...
```
Leave all Tandoor/other keys intact. Save and exit — SOPS re-encrypts on write.

- [ ] **Step 4: Verify the secrets file re-encrypted correctly and no Grist keys remain**

Run:
```bash
sops -d services/tools/secrets.enc.env | grep -i grist || echo "OK: no grist keys"
git diff --stat services/tools/secrets.enc.env
```
Expected: prints `OK: no grist keys`; the diff shows the file changed (re-encrypted). Confirm the file is still valid SOPS output (has the `sops:` metadata block) — `sops -d` succeeding proves it.

- [ ] **Step 5: Commit**

```bash
git add services/tools/docker-compose.yaml services/tools/secrets.enc.env
git commit -m "Services|Refactor: remove grist from tools stack ahead of collaboration move"
```

- [ ] **Step 6: Push the branch and open PR 1 as a draft**

```bash
git push -u origin worktree-move-grist-collaboration
gh pr create --draft \
  --title "Services|Refactor: remove grist from tools stack ahead of collaboration move" \
  --body "Phase 1 of 2 of the Grist → collaboration migration (see docs/superpowers/specs/2026-07-28-move-grist-collaboration-design.md). Removes Grist from the tools stack. On merge, DocoCD reconciles tools with remove_orphans=true and stops+removes the grist container; its data at services_data/tools/grist is preserved for the Gate A move. No ADR required (re-categorisation only). Do not merge until ready to execute the cutover runbook — Grist goes down on merge."
```
Expected: PR URL printed. Watch the **Quality Check** CI workflow to green (`gh pr checks --watch`); auto-fix any yamllint failures.

---

### Gate A (human + server) — merge PR 1, verify, move the data

> This gate is executed by the operator on the server, not by the plan runner. It sits between PR 1 and PR 2. Do not start Task 2's *merge* until Gate A completes — but Task 2's repo edits (below) may be prepared in advance.

- [ ] **A1: Merge PR 1** (merge commit, per repo policy). DocoCD reconciles the `tools` stack. **Grist goes down here.**

- [ ] **A2: Verify the Grist container is gone** (SSH `server`):
```bash
docker ps -a --filter 'name=^/grist$'
```
Expected: header row only, no `grist` container. If it is still present, wait for the DocoCD reconcile to finish (check DocoCD/Apprise notifications) and re-run before proceeding — the `mv` must not run while the container exists.

- [ ] **A3: Move the persist directory** (SSH `server`):
```bash
mv /home/diego/services_data/tools/grist /home/diego/services_data/collaboration/grist
```
(`services_data/collaboration/` already exists from Mattermost; same filesystem, so `mv` is atomic and preserves ownership/permissions.)

- [ ] **A4: Verify the move**:
```bash
ls -la /home/diego/services_data/collaboration/grist/persist   # data present
ls /home/diego/services_data/tools/grist 2>&1                  # should be: No such file or directory
```
Expected: the persist contents (Grist SQLite DBs / docs) are under the collaboration path; the old tools path no longer exists.

---

## PR 2 — add Grist to the collaboration stack

> Prepare these edits after PR 1 is merged. They target `main`'s post-PR-1 state; rebase the worktree branch on the latest `main` (or open PR 2 from a fresh branch off `main`) so the diff is clean.

### Task 2: Add Grist to the `collaboration` stack

**Files:**
- Modify: `services/collaboration/docker-compose.yaml` (add the `grist:` block, volume path changed)
- Modify: `services/collaboration/secrets.enc.env` (add `GRIST_DEFAULT_EMAIL`, `GRIST_OIDC_IDP_CLIENT_SECRET`)

**Interfaces:**
- Consumes: the relocated persist dir at `/home/diego/services_data/collaboration/grist/persist` (produced by Gate A).
- Produces: a running `grist` container owned by the `collaboration` compose project, mounting the moved data.

- [ ] **Step 1: Add the `grist:` service to the collaboration compose**

In `services/collaboration/docker-compose.yaml`, add the following service block (identical to the tools version except the volume path). Place it after `mattermost_postgres:` and before the `networks:` section:

```yaml
  grist:
    container_name: grist
    image: gristlabs/grist:1.7.16
    restart: unless-stopped
    env_file: secrets.enc.env
    environment:
      - PORT=9999
      - GRIST_FORCE_LOGIN=true
      - GRIST_OIDC_IDP_ISSUER=https://auth.dynabase.nl
      - GRIST_OIDC_IDP_CLIENT_ID=grist
      - GRIST_OIDC_SP_HOST=https://grist.dynabase.nl
      - GRIST_OIDC_IDP_SCOPES=openid email profile
      # Authelia's discovery omits end_session_endpoint; Grist won't start without this.
      - GRIST_OIDC_IDP_SKIP_END_SESSION_ENDPOINT=true
    volumes:
      - /home/diego/services_data/collaboration/grist/persist:/persist
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:9999/status"]
      interval: 30s
      retries: 3
      timeout: 5s
      start_period: 10s
      start_interval: 5s
```

- [ ] **Step 2: Verify the compose file parses and lints**

Run:
```bash
uv run yamllint services/collaboration/docker-compose.yaml
docker compose -f services/collaboration/docker-compose.yaml config -q
```
Expected: no yamllint errors; `config -q` valid and now lists `grist` alongside `mattermost` and `mattermost_postgres`. Confirm the volume line reads `services_data/collaboration/grist/persist` (not `tools`).

- [ ] **Step 3: Add the two Grist keys to the collaboration secrets**

Run `sops services/collaboration/secrets.enc.env` and add these two lines (values copied from what PR 1 removed):
```
GRIST_DEFAULT_EMAIL="diegojonathanheer@gmail.com"
GRIST_OIDC_IDP_CLIENT_SECRET=0903ffbcd6706c024e9e3a85813f2ad478536f12af5603276b5d93e43a988b2609b980dbe433122ae75f983b90f96f83
```
Leave the existing `POSTGRES_PASSWORD`, `MM_SQLSETTINGS_DATASOURCE`, `MM_EMAILSETTINGS_SMTPPASSWORD` keys intact. Save and exit.

- [ ] **Step 4: Verify the secrets decrypt and contain both key sets**

Run:
```bash
sops -d services/collaboration/secrets.enc.env | grep -iE 'grist|MM_SQLSETTINGS' 
```
Expected: shows both `GRIST_*` keys and the existing Mattermost key — proving the file re-encrypted with no collision or clobber.

- [ ] **Step 5: Commit**

```bash
git add services/collaboration/docker-compose.yaml services/collaboration/secrets.enc.env
git commit -m "Services|Migrate: move grist to collaboration stack"
```

---

### Task 3: Record Grist under Collaboration in the services overview

**Files:**
- Modify: `README.md` (services overview table, around line 42)

**Interfaces:**
- Consumes: nothing.
- Produces: accurate docs. No runtime effect.

- [ ] **Step 1: Update the Tools row — remove Grist**

In the `### Services overview` table, change the Tools row from:
```
| **Tools** | IT-Tools, BentoPDF, Grist, Docuseal, Changedetection, Tandoor + PostgreSQL |
```
to:
```
| **Tools** | IT-Tools, BentoPDF, Docuseal, Changedetection, Tandoor + PostgreSQL |
```

- [ ] **Step 2: Add a Collaboration row**

Immediately after the Tools row, add:
```
| **Collaboration** | Mattermost + PostgreSQL, Grist |
```

- [ ] **Step 3: Verify the table renders and Grist appears once under Collaboration**

Run:
```bash
grep -nE 'Tools|Collaboration|Grist' README.md
```
Expected: `Grist` appears only in the Collaboration row; the Tools row no longer lists it.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "Config|Update: record grist under collaboration in services overview"
```

- [ ] **Step 5: Push and open PR 2 as a draft**

```bash
git push -u origin <pr2-branch>
gh pr create --draft \
  --title "Services|Migrate: move grist to collaboration stack" \
  --body "Phase 2 of 2 of the Grist → collaboration migration (see docs/superpowers/specs/2026-07-28-move-grist-collaboration-design.md). Adds Grist to the collaboration stack pointing at the relocated data dir (services_data/collaboration/grist), moves its two SOPS secrets, and updates the README services overview. Requires Gate A (data move) to be complete before merge. No ADR required."
```
Expected: PR URL; **Quality Check** CI green (`gh pr checks --watch`).

---

### Gate B (human + server) — merge PR 2, verify live

- [ ] **B1: Merge PR 2** (merge commit). DocoCD reconciles `collaboration` → creates the `grist` container mounting the relocated data. **Grist comes back up.**

- [ ] **B2: Verify the container is healthy** (SSH `server`):
```bash
docker ps --filter 'name=^/grist$'
```
Expected: `grist` present with status `healthy` (its `/status` healthcheck). If `unhealthy`/restarting, check `docker logs grist` — a fresh/empty DB would indicate the volume path is wrong (see rollback).

- [ ] **B3: Verify the volume is the collaboration path**:
```bash
docker inspect grist --format '{{range .Mounts}}{{.Source}} -> {{.Destination}}{{"\n"}}{{end}}'
```
Expected: `/home/diego/services_data/collaboration/grist/persist -> /persist`.

- [ ] **B4: Verify externally** — browse to `https://grist.dynabase.nl`:
  - Page loads (Cloudflare tunnel still routes to `grist:9999`).
  - OIDC login round-trip via Authelia succeeds (`client_id: grist` unchanged).
  - **Existing documents/workspaces are present** — confirms the data move worked end-to-end.

- [ ] **B5: Clean up** — after confirming healthy, remove the worktree and merged branches per the repo workflow.

---

## Rollback

Data is only relocated, never mutated, so rollback is clean at any point:

- **PR 1 merged, problem before/at Gate A `mv`:** revert PR 1 (re-adds Grist to tools); data still at `services_data/tools/grist`. Grist returns to the tools stack on the next reconcile.
- **Data moved, PR 2 not yet merged:** `mv /home/diego/services_data/collaboration/grist /home/diego/services_data/tools/grist`, then revert PR 1 if you want Grist back up immediately.
- **PR 2 merged but Grist unhealthy / documents missing:** revert PR 2, `mv` the data back to `services_data/tools/grist`, revert PR 1. Investigate the volume path before retrying.

---

## Self-review

- **Spec coverage:** Every spec change maps to a task — tools compose removal + secrets (Task 1), collaboration compose add + secrets (Task 2), README (Task 3), data move (Gate A), live verification (Gate B), rollback (Rollback section). ✅
- **Unchanged-items guard:** Global Constraints explicitly forbids touching `container_name`, port, network, `.doco-cd.yml`, Authelia, tunnel, and `CHANGELOG.md`. ✅
- **Consistency:** The `grist:` block in Task 2 is byte-identical to the Global Constraints reference except the single volume path line; the secret values in Task 2 Step 3 match those removed in Task 1 Step 3. ✅
- **No placeholders:** every step has concrete commands, file content, and expected output. The only intentional late-bound value is `<pr2-branch>` (branch name depends on how PR 2 is cut off post-merge `main`). ✅
