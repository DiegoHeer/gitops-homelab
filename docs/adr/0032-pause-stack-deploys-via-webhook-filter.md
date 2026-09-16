# 0032 — Pause stack deploys via `webhook_filter`

- **Status**: Accepted
- **Date**: 2026-09-16
- **Deciders**: Diego

## Context

Some stacks need to sit still for a while — an upgrade that wants a manual migration first, a
service being reworked — without being torn down. DocoCD has no `enabled`/`paused` key: the deploy
config struct (`internal/config/deploy/deploy.go` at v0.115.0) carries `enabled` only inside
`auto_discovery`, `destroy`, `reconciliation` and `swarm`, never at the top level. Deleting a
stack's document from `.doco-cd.yml` does pause it — obsolete-stack cleanup only ever touches
auto-discovered stacks (`internal/reconciliation/clean.go`), and auto-discovery is off here — but
it erases the entry and the reason along with it.

## Decision

Pause a stack by pointing its `webhook_filter` at `^refs/heads/__paused__$`, a ref that never
exists, with a dated comment above it. Rejected: a Compose-profile convention (`profiles: [<stack>]`
on every service, activated per stack from `.doco-cd.yml`), which gates every trigger path rather
than just webhooks but costs a `profiles:` line on all 61 services — Compose has no file-wide
default profile, a top-level `profiles:` key fails schema validation outright, and a YAML anchor
still needs a merge key per service. Profiles remain the escape hatch for pausing a *single*
service inside an otherwise-live stack, which `webhook_filter` cannot express.

## Consequences

- `+` A pause is one line, in one file, with the reason next to it in the diff
- `+` Nothing is stopped, removed or pruned — the filter is checked in stage 1 before the repo is
  even cloned (`internal/stages/stage_1_init.go`), so containers keep running at their current image
- `+` A skipped run is not a failed one: the webhook gets `202 Accepted`, the stage outcome is
  recorded as `skipped`, and no Apprise error notification fires while a stack sits paused
- `+` Compose files stay untouched, so a pause cannot break `depends_on` or netns wiring
- `+` `Reconcile DocoCD` honours it too — the workflow posts `refs/heads/main`, which the paused
  filter rejects like any other push
- `−` Only webhook triggers are gated. `MatchesWebhookEventFilter` returns true for any
  non-webhook trigger, so if polling is ever configured a paused stack would deploy again
- `−` Renovate keeps merging image bumps into a paused stack; resuming applies all of them in one
  deploy, so a long pause ends with a large, under-reviewed change set
- `−` Per-stack only. Holding back one service means a Compose profile on it, and that fails the
  whole project's load (`depends on undefined service`) if an active service still names it — in
  `services/media/` the five gluetun-netns dependents can only be paused alongside gluetun
- `−` The pattern is compiled with `regexp.MustCompile` at deploy time and is not validated at
  config load, so a malformed regex fails that deployment (the webhook handler recovers, so the
  container survives)
- `−` Nothing enforces that a pause is temporary; the dated comment is the only reminder

## Evidence

- `.doco-cd.yml` — the pause convention in the header comment; `ai`, `design` and `games` paused
- `README.md` — "Pausing a stack"
- doco-cd v0.115.0: `internal/stages/stage_1_init.go` (filter checked in stage 1, returns
  `ErrWebhookFilterMismatch`), `cmd/doco-cd/handler_webhook.go` (202 + skipped run),
  `internal/config/deploy/deploy.go` (no top-level enable/disable key)
- docker/compose v5.5.1 `pkg/compose/containers.go` — `isOrphaned` unions `ServiceNames()` with
  `DisabledServiceNames()`, which is why a profile-disabled service's container survives
  `remove_orphans: true`
- ADR 0001 (GitOps via DocoCD), whose push-based model this pauses without leaving
