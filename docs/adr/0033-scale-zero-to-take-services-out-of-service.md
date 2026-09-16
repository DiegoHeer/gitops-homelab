# 0033 — `scale: 0` to take services out of service

- **Status**: Accepted
- **Date**: 2026-09-16
- **Deciders**: Diego

## Context

ADR 0032 read "disable a service" as "stop DocoCD reconciling it" and paused `ai`, `design`,
`games` and `frigate` with `webhook_filter` and a Compose profile. That worked exactly as
designed and was the wrong thing: every container kept running. The requirement was that the
services stop, which no deploy-gating mechanism can express — a paused stack is a stack DocoCD
does not touch, not a stack that is down.

## Decision

Take a service out of service with `scale: 0` on the service, leaving its definition, its stack
registration and its `webhook_filter` alone. Supersedes 0032: `webhook_filter` stays pinned to
`^refs/heads/main$` everywhere, and the profile on frigate is gone. Rejected: `doco.sh stop`,
which acts on runtime state only (ADR 0022) and would leave the host's real state unrecorded in
git, contradicting ADR 0001; and deleting the services outright, which loses the definitions.

## Consequences

- `+` The repo states what should be running, so the host matches git rather than drifting from it
- `+` Reconciliation stays on, so the stack still picks up config changes and the service comes
  back by deleting one line — no registry edit, no un-pausing first
- `+` Works per service without splitting a stack: frigate is at zero while authelia keeps
  deploying from the same compose file
- `+` Runtime state under `services_data/`, named volumes and the stack network all survive
- `−` The container is removed, not stopped, so anything not on a bind mount or named volume is
  lost; nothing here keeps state elsewhere, but a future service might
- `−` `prune_images: true` means the image goes once nothing uses it, so re-enabling re-pulls it
  — a slow first start for frigate and the penpot set
- `−` A stack with every service at zero still deploys on each push to `main`, so Renovate bumps
  and config changes land silently in a stack nobody is watching
- `−` Losing the pause loses its one real advantage: a `scale: 0` stack still reconciles, so a
  change pushed to it takes effect immediately rather than waiting for a deliberate resume

## Evidence

- `services/ai/`, `services/design/`, `services/games/` — every service at `scale: 0`
- `services/security/docker-compose.yaml` — frigate at `scale: 0`, authelia untouched
- `.doco-cd.yml` — back to a uniform `^refs/heads/main$`
- `README.md` — "Taking a service out of service"
- doco-cd's own recipe for this: "Removing a container service" in the Tips and Tricks wiki page,
  including the note that scaling every service to zero stops the project while volumes, configs,
  secrets and networks survive
- ADR 0032, superseded by this one
- ADR 0022 (SSH over the DocoCD API), which scopes `doco.sh` to runtime state and so rules it out
  as the recording mechanism
