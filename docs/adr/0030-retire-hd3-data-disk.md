# 0030 — Retire the hd3 data disk

- **Status**: Accepted
- **Date**: 2026-08-19
- **Deciders**: Diego

## Context

`/media/hd3` (`/dev/sdd1`, Seagate ST1000LM049) is failing: reallocated sectors
grew 282 → 290 over ~82 power-on hours to 2026-08-19, with 24 current-pending,
14 reported-uncorrectable, and an extended self-test ending in a read failure.
Its mount is intermittent — fsck refused it on 2026-08-16 and passed on
2026-08-19. It is the only disk in the host with any reallocations. Inventorying
its 139G surfaced two backup gaps: the RustFS `obsidian-notes` bucket had no
backup at all, and the sole local photo repository lived on this disk with no
mirror while the offsite albums job had been failing since 2026-08-16.

## Decision

Retire `/media/hd3` entirely and pull the drive for reuse in the ProDesk.
RustFS object data moves to `/home/diego/services_data/storage/rustfs/`, which
enrolls it in Zerobyte's existing "Docker Services Backup". The photo restic
repository is recreated fresh on hd2 and re-backed-up from source rather than
copied, so no byte is read off the failing disk. Frigate recording moves to hd2,
the `money_metrics` dump is archived to hd2, and the premigration dumps, frigate
clips and beszel marker are deleted. Implemented across two PRs; the second
completes the retirement and empties `docker_host_ordered_mounts`.

## Consequences

- `+` The Obsidian vault gets daily encrypted offsite backup for the first time,
  reusing an existing schedule rather than adding one.
- `+` No data remains on a disk with known uncorrectable sectors.
- `+` `docker_host_ordered_mounts` becomes empty and every remaining data disk
  is `Required`, collapsing 0026's two tiers back into one.
- `+` Frigate recordings land on a surveillance-class drive with 5.0T free.
- `−` Zerobyte's repository paths live in its SQLite database, not in git. The
  most consequential part of this migration — where backups actually point — is
  invisible to the GitOps model and is not restored by redeploying the stack.
- `−` RustFS still spreads `RUSTFS_VOLUMES=/data/rustfs{0..3}` across four
  directories on one physical disk, so erasure coding buys nothing against disk
  failure. The daily backup, not the EC, is the mitigation.
- `−` The full photo library remains local-only; only archived albums go
  offsite. Mirroring 127G over a home connection was judged out of scope.
- `−` Between the two PRs, 0026 is marked superseded while
  `docker_host_ordered_mounts` is still populated.

## Evidence

- `services/storage/docker-compose.yaml` — RustFS binds under `services_data`
- `services/backups/docker-compose.yaml` — Zerobyte's hd2 bind
- `roles/docker_host/defaults/main.yml` — `docker_host_ordered_mounts` emptied (PR2)
- Supersedes [0026](0026-docker-waits-for-data-disk-mounts.md)
