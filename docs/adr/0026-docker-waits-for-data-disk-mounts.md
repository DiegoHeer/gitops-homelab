# 0026 — Docker waits for data disk mounts, in two tiers

- **Status**: Accepted
- **Date**: 2026-08-16
- **Deciders**: Diego Heer

## Context

After a reboot on 2026-08-16, Docker started before `/media/*` was mounted. Every
bind mount under those paths resolved to the empty mountpoint on the root disk, so
six containers served from the wrong directory with no error and no restart loop —
including `zerobyte`, whose backup sources pointed at empty directories, and
`nextcloud`, which returned 503 while writing into the root filesystem. Nothing in
the stack detected it. Requiring all three disks would make any single disk failure
keep the entire host down, which is unacceptable while hd3 is failing (ADR-less;
see the disk's SMART history).

## Decision

Order `docker.service` after the data disk mounts via an Ansible-managed systemd
drop-in, splitting the disks into two tiers: `hd1`/`hd2` use `RequiresMountsFor=`
so Docker refuses to start without them, while `hd3` gets `After=` only so a
failing disk delays Docker without keeping the host down. Bind mounts into hd3
subdirectories additionally set `create_host_path: false`, turning a missing mount
into a startup failure rather than writes landing on the root disk.

## Consequences

- `+` Containers can no longer silently bind a pre-mount directory on hd1/hd2.
- `+` A missing hd3 fails Frigate and RustFS loudly instead of filling the root disk with video and object data.
- `+` Mount ordering is declarative and reproducible on a rebuild, not hand-configured.
- `−` If hd1 or hd2 fails to mount, Docker does not start at all — every service is down, by design.
- `−` hd3 consumers that bind the mountpoint itself (`/media/hd3:/hd3` in `zerobyte`, `filebrowser`) cannot be guarded this way, since the directory exists mounted or not.
- `−` Molecule cannot exercise the `RequiresMountsFor` path; a hard requirement on a mount unit absent from the test container would stop Docker starting, so CI covers the ordering-only branch.

## Evidence

- `roles/docker_host/tasks/storage.yml`, `roles/docker_host/templates/docker-wait-for-mounts.conf.j2`
- `roles/docker_host/defaults/main.yml` — `docker_host_required_mounts`, `docker_host_ordered_mounts`
- `services/security/docker-compose.yaml`, `services/storage/docker-compose.yaml` — `create_host_path: false`
- Guard verified on Docker Compose 5.4.0: a missing bind source errors with
  `invalid mount config for type "bind": bind source path does not exist` and does not create the directory.
