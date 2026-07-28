# Design: Move Grist to the `collaboration` category

**Date:** 2026-07-28
**Status:** Approved (brainstorming) — pending spec review

## Context

Grist currently lives in the `tools` stack (`services/tools/docker-compose.yaml`).
It should move to the `collaboration` category (alongside Mattermost). Grist is
**live and in use by other users**, so the migration must avoid data loss and
minimise/contain downtime.

Grist is exposed at `https://grist.dynabase.nl` via the Cloudflare tunnel
(dashboard-managed ingress → container `grist:9999` on `home_server_network`) and
authenticates via Authelia OIDC (`client_id: grist`).

### Facts established during discovery

- **Deploy model:** DocoCD reconciles each stack registered in `.doco-cd.yml` on a
  push to `main`, running `docker compose up -d` per stack. Both `tools` and
  `collaboration` are already registered — **no `.doco-cd.yml` change needed**.
- **`remove_orphans` defaults to `true`** in doco-cd (`internal/config/deploy/deploy.go`).
  When grist is removed from the tools compose, DocoCD's reconcile of the tools stack
  stops and removes the orphaned `grist` container automatically.
- **Downtime is inherent:** the host allows only one container named `grist`. The old
  instance must be removed before the new one can start, so there is one unavoidable
  downtime window (a few minutes). This is why the cutover is two-phase.
- **Runtime state** is at `/home/diego/services_data/tools/grist/persist` (SQLite +
  uploaded docs). Convention (README, CLAUDE.md) is
  `services_data/<category>/<service>/`.
- **Secrets:** `GRIST_DEFAULT_EMAIL` and `GRIST_OIDC_IDP_CLIENT_SECRET` are in
  `services/tools/secrets.enc.env`. The other `GRIST_OIDC_*` values and `PORT` are
  plain `environment:` entries in the compose file.
- **`CHANGELOG.md` is auto-generated** by git-cliff from conventional commit messages
  on release tag (`.github/workflows/release.yml`) — do **not** hand-edit it.

### Decisions taken

1. **Data path — move it.** Physically relocate
   `services_data/tools/grist` → `services_data/collaboration/grist` so runtime state
   follows the category convention; no orphaned `tools/grist` path is left behind.
2. **Cutover — two-phase PRs.** Remove-from-tools and add-to-collaboration are split
   across two merges with a manual data move between them. Fully controlled, zero
   name-conflict risk. (A single atomic PR was rejected: it relies on undocumented
   per-push stack ordering and can leave the new grist failing on a name conflict if
   `collaboration` reconciles before `tools`.)

### Not changing (verified safe to leave identical)

`container_name: grist`, `PORT=9999`, `home_server_network`, all `GRIST_OIDC_*` env,
the Cloudflare tunnel ingress, the Authelia OIDC client (`client_id: grist`,
redirect `https://grist.dynabase.nl/oauth2/callback`), DNS, and `.doco-cd.yml`.
Because Grist's identity (name, hostname, client-id) and networking are unchanged,
nothing external re-points.

### Not required

**No ADR.** This is a re-categorisation, not a change to a load-bearing subsystem
(GitOps model, secrets scheme, network topology, orchestrator, backup strategy are all
unchanged). It will be noted in the PR descriptions regardless.

## The migration

Grist's service definition is lifted verbatim from the tools stack into the
collaboration stack, with exactly one line changed — the bind-mount path.

### Repo changes, by phase

**Phase 1 (PR 1) — remove from tools**

- `services/tools/docker-compose.yaml`: delete the `grist:` service block.
- `services/tools/secrets.enc.env` (via `sops`): remove `GRIST_DEFAULT_EMAIL` and
  `GRIST_OIDC_IDP_CLIENT_SECRET`.

**Phase 2 (PR 2) — add to collaboration**

- `services/collaboration/docker-compose.yaml`: add the `grist:` service block,
  identical to the original except the volume line:
  `/home/diego/services_data/collaboration/grist/persist:/persist`.
- `services/collaboration/secrets.enc.env` (via `sops`): add `GRIST_DEFAULT_EMAIL` and
  `GRIST_OIDC_IDP_CLIENT_SECRET` (no collision with the Mattermost keys).
- `README.md`: drop Grist from the **Tools** row; add a **Collaboration** row
  (`Mattermost + PostgreSQL, Grist`).

> The README services table currently has no Collaboration row at all (pre-existing
> gap). Adding it here keeps the table accurate for this change. The missing **Design**
> row is out of scope and left untouched.

### Commits (feed git-cliff — follow `Category|Action:`)

- PR 1:
  - `Services|Refactor: remove grist from tools stack ahead of collaboration move`
    (compose + secrets as one atomic change, since they are the same logical removal)
- PR 2:
  - `Services|Migrate: move grist to collaboration stack` (compose + secrets)
  - `Config|Update: record grist under collaboration in services overview` (README)

## Cutover runbook (ordered, with verification gates)

1. **Merge PR 1.** DocoCD reconciles `tools` → `grist` container stopped & removed.
   *Grist goes down here.*
2. **Verify container is gone** on the server:
   `docker ps -a --filter name=^/grist$` → no rows.
   (Gate: the `mv` must not run while the container holds the persist dir open.)
3. **Move the data** on the server:
   `mv /home/diego/services_data/tools/grist /home/diego/services_data/collaboration/grist`
   - Target parent `services_data/collaboration/` already exists (Mattermost).
   - `mv` within the same filesystem is atomic and preserves ownership/permissions.
   - Verify: `ls /home/diego/services_data/collaboration/grist/persist` shows the data;
     `ls /home/diego/services_data/tools/grist` no longer exists.
4. **Merge PR 2.** DocoCD reconciles `collaboration` → `grist` recreated, mounting the
   relocated data. *Grist comes back up.*
5. **Verify:**
   - `docker ps` shows `grist` healthy (its `/status` healthcheck passes).
   - `https://grist.dynabase.nl` loads and an OIDC login round-trip succeeds.
   - Existing documents/workspaces are present (confirms the data move worked).

## Error handling & rollback

Data is never mutated by the migration (only relocated), so rollback is clean at any
point:

- **PR 1 merged, problem before/at the `mv`:** revert PR 1 (re-adds grist to tools);
  data is still at `services_data/tools/grist`. Grist returns to the tools stack.
- **Data moved, PR 2 not yet merged:** `mv` the dir back to `services_data/tools/grist`,
  then (if needed) revert PR 1.
- **PR 2 merged but grist unhealthy / data missing:** revert PR 2, `mv` data back to
  `services_data/tools/grist`, revert PR 1. Investigate before retrying.

The only irreversible-feeling risk (data loss) is mitigated because `mv` on the same
filesystem neither copies nor deletes content, and the container is confirmed stopped
before the move.

## Testing / verification

There is no unit-testable surface here — this is an infra re-categorisation. Verification
is the runbook's gates in the live environment:

- Container-absent check before the `mv` (step 2).
- Data-present check after the `mv` (step 3).
- Health + OIDC-login + documents-present check after PR 2 (step 5).

Repo-level checks before each PR: `uv run yamllint .` on the changed compose files.
