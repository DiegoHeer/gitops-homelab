# 0028 — Docker log rotation via daemon.json

- **Status**: Accepted
- **Date**: 2026-08-16
- **Deciders**: Diego Heer

## Context

`/etc/docker/daemon.json` on the home server was literally `{}`, so the default
`json-file` driver ran with no `max-size`/`max-file` and every container log grew
without bound. On 2026-08-16 `/var/lib/docker/containers` held **22 GB** on the
466 GB root disk: `doorbell_samba` 13 GB, `openthread_border_router` 6.3 GB
(inflated by a crash loop restarting ~2,250×/day for over a week), `frigate` 1.3 GB.
A single crash-looping container silently converts itself into a disk-fill
incident, and filling `/` would take down all 61 containers at once.

## Decision

Manage `/etc/docker/daemon.json` from the `docker_host` role with
`log-driver: json-file` and `log-opts` of `max-size: "10m"` / `max-file: "3"`,
capping each container at 30 MB. A service that needs more history overrides the
default with its own `logging:` block in its compose file.

## Consequences

- `+` Bounded growth: a ~1.8 GB ceiling across 61 containers instead of unbounded.
- `+` One host-level default, so new stacks inherit rotation without anyone remembering to add it.
- `+` Dozzle and `docker logs` are unaffected — the driver is unchanged, only limits are added.
- `−` Applies only to containers created after the daemon restarts. The existing 61 keep their unbounded logs until recreated, so the 22 GB is reclaimed by recreation, not by this change alone.
- `−` Applying it restarts the Docker daemon, which bounces every container; it needs a maintenance window.
- `−` Per-container history is capped at 30 MB, so long-tail forensics on a chatty service is lost.
- `−` Ansible now owns `daemon.json` wholesale; a hand-edit on the host is overwritten on the next run.

Rejected alternatives: per-service `logging:` blocks in every compose file (30+
files of churn, and silently forgotten on each new stack); and the `local` log
driver, which rotates by default and stores more compactly but changes the
on-disk format for a fleet whose only log UI is Dozzle, with no capacity gain
over `json-file` once limits are set.

## Evidence

- `roles/docker_host/tasks/logging.yml`, `roles/docker_host/defaults/main.yml` — `docker_host_daemon_config`
- `roles/docker_host/handlers/main.yml` — `Restart Docker`
- Docker documents that changing the driver or its options "only affects containers that are
  created after the configuration is changed"; logging is absent from the set of options a
  SIGHUP reload applies ([docker/for-linux#771](https://github.com/docker/for-linux/issues/771)).
- Manual `truncate -s 0` was rejected as the cleanup path: it breaks `docker logs -f`
  ([moby#45728](https://github.com/moby/moby/issues/45728)), which is the follow API Dozzle uses.
  Recreating a container instead applies the limits and drops the old log file in one step.
