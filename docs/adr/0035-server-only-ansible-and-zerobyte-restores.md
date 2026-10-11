# 0035 — Server-only Ansible, Ansible starts DocoCD, restores via Zerobyte

- **Status**: Accepted
- **Date**: 2026-10-11
- **Deciders**: Diego

## Context

The homelab is moving to a new device, and the host's only jobs are running
services and storing backups. Ansible still carried workstation setup
(`projects` role cloning personal repos, Linuxbrew, tmux, oh-my-posh, flatpak,
snapd) and a `restore` role (ADR 0015) built to rebuild a host from offsite
snapshots. The move uses a plain copy instead. Ansible copied the DocoCD
compose file but never started it, so the live DocoCD drifted to 0.80.1 while
the repo pinned 0.123.0.

## Decision

Ansible keeps only server setup: the `system` role (user, base packages,
Tailscale installed and `tailscaled` enabled, but no login) and the
`docker_host` role. `docker_host` now also starts the `bootstrap/gitops`
stack. The `projects` and `restore` roles and the restore workflow are removed.
Restores go through Zerobyte, or through the restic CLI against the same repos
by hand. This supersedes ADR 0015.

## Consequences

- `+` less to maintain; a fresh host is Docker, secrets, Tailscale and DocoCD
- `+` the DocoCD version on the host matches the repo; merged `bootstrap/**`
  bumps (Renovate auto-merges minor and patch updates) now redeploy DocoCD within a minute
- `+` the Tailscale apt source uses `install.sh`'s paths, so hosts set up by hand
  converge without an apt `Signed-By` conflict
- `−` no automated restore path; restore verification stays manual (as in 0015)
- `−` a DocoCD redeploy can interrupt a stack deploy that is running at the same moment
- `−` `tailscale up` with `--advertise-routes` is a manual step on a new host

## Evidence

- `roles/system/tasks/tailscale.yml` — Tailscale repo, package, service
- `roles/docker_host/tasks/gitops.yml` — `Start the gitops stack`
- `molecule/system/prepare.yml` — install.sh-style apt source regression setup
- `molecule/docker_host/verify.yml` — DocoCD and apprise running after converge
