# 0035 — Server-only Ansible that installs Tailscale and starts DocoCD

- **Status**: Accepted
- **Date**: 2026-10-11
- **Deciders**: Diego

## Context

The homelab is moving to a new device on Ubuntu 26.04, so the playbook must
bring a fresh host to the same state as the current one. The host's only jobs
are running services and storing backups, but Ansible still carried
workstation setup: the `projects` role (cloning personal repos) and Linuxbrew,
tmux, oh-my-posh, flatpak and snapd in the `system` role. Two parts of the real
server state lived outside Ansible. Ansible copied the DocoCD compose file but
never started it, so the live stack still ran floating `:latest` images (DocoCD
0.80.1, Apprise an April `edge` build) while the repo pinned DocoCD 0.123.0 and
Apprise v1.5.4. Tailscale, which CI needs to reach the host (ADR 0016), was installed
by hand with its `install.sh`.

## Decision

Ansible keeps only server setup. The `projects` role and the workstation
extras are removed. The `restore` role and its workflow stay (ADR 0015). The
`docker_host` role starts the `bootstrap/gitops` stack after it syncs the
compose file. The `system` role installs Tailscale and enables `tailscaled`,
using the same apt source paths as `install.sh`. Logging in (`tailscale up`
with `--advertise-routes`) stays manual. Molecule runs on Ubuntu 26.04 and,
until the current host is retired, 24.04.

## Consequences

- `+` less to maintain; a fresh host gets Docker, secrets, Tailscale and a running DocoCD from one playbook run
- `+` the DocoCD and Apprise versions on the host match the repo; merged `bootstrap/**`
  bumps (Renovate auto-merges minor and patch updates) now redeploy DocoCD
- `+` hosts set up by hand with `install.sh` converge without an apt `Signed-By` conflict
- `−` the removed extras stay installed on the current host, unmanaged; the new host does not get them.
  This includes the `system` role's `settings.yml`: `~/.hushlogin` and a `~/.local/bin` PATH line (which
  it wrote to root's `.bashrc`, not the user's). Ubuntu's default `~/.profile` still puts `~/.local/bin`
  on PATH for login shells
- `−` a DocoCD redeploy can interrupt a stack deploy that is running at the same moment. DocoCD cancels
  in-flight deploys on SIGTERM (a longer `stop_grace_period` does not help) and forgets the failure on
  restart, so a later webhook or Reconcile DocoCD run for the same commit likely skips the half-deployed
  stack. Fix it by redeploying that stack by hand (e.g. a one-off `force_recreate: true` in its
  `.doco-cd.yml` entry). Waiting for DocoCD to be idle (its
  `/v1/api/runs` API, behind `API_SECRET`) was rejected as not worth the extra secret and code for a rare
  overlap
- `−` `tailscale up` is a manual step on a new host, so its first playbook run cannot come from CI

## Evidence

- `roles/system/tasks/tailscale.yml` — Tailscale repo, package, service
- `roles/docker_host/tasks/gitops.yml` — `Start the gitops stack`
- `molecule/system/prepare.yml` — install.sh-style apt source regression setup
- `molecule/system/verify.yml` — `tailscaled` enabled, workstation extras absent
- `molecule/docker_host/verify.yml` — DocoCD running and apprise healthy after converge, log rotation and mount order intact
