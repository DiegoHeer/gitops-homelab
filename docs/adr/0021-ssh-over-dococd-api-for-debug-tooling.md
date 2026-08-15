# 0021 — SSH Over The DocoCD API For Debug Tooling

- **Status**: Accepted
- **Date**: 2026-08-15
- **Deciders**: Diego

## Context

DocoCD has no UI, so debugging a container meant pushing a commit to `main` or
hand-running docker commands against the server. DocoCD v0.103.0 does expose a
REST API (`/v1/api/project/{name}/{start,stop,restart}`, and `POST /v1/api/poll/run`,
which accepts inline deploy configs and can therefore force-recreate a single
stack without a commit) — genuinely more capable than plain docker.

## Decision

Drive debug tooling over `DOCKER_HOST=ssh://server` and leave the DocoCD REST API
disabled. Redeploys stay with the existing `Reconcile DocoCD` workflow.

## Consequences

- `+` No server-side change: `API_SECRET` stays unset, so `/v1/api` stays unrouted.
- `+` No new internet-facing surface. `doco-cd.dynabase.nl` is a bare single-label
  host and therefore public via the ADR 0019 tunnel wildcard; it must stay public
  for GitHub webhooks. Enabling the API there would expose
  `DELETE /v1/api/project/{name}?volumes=true` behind a static header that
  `internal/restapi/api.go` compares with `==` rather than a constant-time
  compare, with no rate limiting.
- `+` Works from any host with SSH access and no repo clone.
- `−` No commit-free single-stack `force_recreate`. A full reconcile via the
  GitHub Actions workflow is the only redeploy path.
- `−` Tooling depends on SSH access to the server, so it is unusable from CI.

## Evidence

- `docs/superpowers/specs/2026-08-15-dococd-debug-tooling-design.md` — full analysis
- `scripts/doco.sh`, `Makefile` — the implementation
- ADR 0019 — establishes that bare `*.dynabase.nl` hosts are internet-facing
