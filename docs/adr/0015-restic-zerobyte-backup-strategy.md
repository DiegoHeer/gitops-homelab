# 0015 — Restic + Zerobyte for 3-2-1 backups

- **Status**: Accepted
- **Date**: 2026-04-18
- **Deciders**: Diego

## Context

Homelab holds irreplaceable data (photos via Immich, documents, Nextcloud).
Need 3 copies, 2 media types, 1 offsite.
Restic is battle-tested dedup/encrypted backup.
Zerobyte is an S3-backed restic frontend UI that simplifies scheduling/monitoring.

## Decision

Zerobyte handles scheduled backups to S3 via rclone.
Ansible `restore` role talks directly to restic (bypassing Zerobyte) to pull `latest` snapshots on recovery.

## Consequences

- `+` familiar Zerobyte UI for day-to-day monitoring
- `+` restic on-disk format means the restore path isn't dependent on Zerobyte
- `−` two tools to understand (Zerobyte UX + restic CLI)
- `−` restore verification is not yet automated

## Evidence

- `services/backups/docker-compose.yaml` — Zerobyte stack
- `roles/restore/tasks/restore.yml` — direct `restic restore latest`
- `1880d61` (2026-04-18) — backups/ migrated to DocoCD
