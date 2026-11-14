# 0040 — RustFS runs in single-disk mode on the OS SSD

- **Status**: Accepted
- **Date**: 2026-11-15
- **Deciders**: Diego

## Context

RustFS spread `RUSTFS_VOLUMES=/data/rustfs{0..3}` over four directories on one
physical disk (ADR 0030 already noted that this erasure coding buys nothing
against disk failure). From 1.0.x, RustFS refuses this layout at startup unless
`RUSTFS_UNSAFE_BYPASS_DISK_CHECK=true` is set, a flag documented as for testing
only. The four-way split also multiplies every write into shard and parity
files. On 2026-11-14 the host stalled under memory and swap pressure; RustFS on
`1.0.0-alpha.94` had grown to ~5 GB and its nightly Postgres backup writes added
I/O. An existing erasure set cannot be converted in place (`format.json` fixes
the drive count), so the only route is a new deployment and an S3-level copy.
Moving the data to hd2 was rejected: hd2 is a USB hard disk, sda is an SSD,
and only `/home/diego/services_data` is in Zerobyte's offsite backup (ADR 0030),
so the Obsidian bucket would lose its backup unless Zerobyte were changed by
hand outside git.

## Decision

Run RustFS in single-node single-disk mode (`RUSTFS_VOLUMES=/data`, one volume)
at the same bind path under `services_data`, drop the disk-check bypass, and
cap the container at 2G memory. Data was migrated by starting a second
single-disk instance, importing IAM and bucket metadata with `rc admin iam` and
`rc admin bucket-metadata`, and copying objects with `rclone copy -M`.

## Consequences

- `+` no testing-only bypass flag; the layout is the one RustFS documents for one disk
- `+` one data file per object instead of four shards, so less write I/O on the OS disk
- `+` the 2G cap stops RustFS from pushing the host into swap again
- `+` the bucket stays inside the existing daily offsite backup
- `−` no parity: bitrot is detected but cannot be repaired locally; the offsite backup is the repair path (in practice the same as before, since all shards shared one disk)
- `−` single-disk mode cannot be expanded in place; adding disks later needs another S3-level migration
- `−` the object copy rewrites Last-Modified to the migration time; rclone keeps the original mtime in `X-Amz-Meta-Mtime`
- `−` an open upstream report (rustfs#9145) describes memory growth in single-drive mode; the memory cap limits the blast radius but RustFS may be OOM-restarted

## Evidence

- `services/storage/docker-compose.yaml` — `RUSTFS_VOLUMES=/data`, `deploy.resources.limits.memory`
- rustfs 1.0.5 `crates/ecstore/src/layout/endpoints.rs` — single path selects the single-drive layout and skips the distinct-disk check
- rustfs 1.0.5 `crates/ecstore/src/store/init_format.rs` — topology mismatch on a changed drive count
- Migration copy kept at `/media/hd2/backups/rustfs-alpha94-20261114/` until verified
