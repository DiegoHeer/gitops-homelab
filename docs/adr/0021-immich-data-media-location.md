# 0021 — Immich media mounted at `/data` (upstream default)

- **Status**: Accepted
- **Date**: 2026-08-06
- **Deciders**: Diego Heer

## Context

The photos stack mounted `/media/hd1/photo_library` at `/usr/src/app/upload`,
the pre-v1.137 Immich media path. Upstream moved the default media location to
`/data` (`IMMICH_MEDIA_LOCATION`, unset here so it defaults), and the release
compose now ships `${UPLOAD_LOCATION}:/data`. Immich has carried a startup shim
since v1.137.1 that detects the legacy mount and adapts, which is the only
reason the stack kept working — a compatibility path with no stated support
horizon. Leaving it also meant the image's declared `VOLUME /data` bound a
fresh anonymous volume on every container recreation.

## Decision

Mount the photo library at `/data` and rewrite the stored absolute paths with
`immich-admin change-media-location`, rather than continue relying on the
legacy-path shim.

## Consequences

- `+` Matches the upstream compose, so future release notes and support threads apply directly.
- `+` Removes dependence on a compatibility shim that upstream may drop without a migration path.
- `+` Ends the orphaned anonymous `/data` volume created on each recreation.
- `−` One-time rewrite of ~64k absolute paths across four tables; assets are broken in the UI between the redeploy and the rewrite.
- `−` The rewrite is a manual post-merge step, so the deploy is not purely GitOps for this one change.
- `−` Rollback needs both a compose revert and a reverse path rewrite.

## Evidence

- `services/photos/docker-compose.yaml` — `/media/hd1/photo_library:/data`
- Upstream compose: <https://github.com/immich-app/immich/releases/latest/download/docker-compose.yml>
- Legacy-path shim: <https://github.com/immich-app/immich/discussions/20488>
- Command reference: <https://docs.immich.app/administration/server-commands/>
- External library paths (`/external_libraries/*`, ADR-less, see PR #185) sit outside
  the media location and are deliberately untouched by the rewrite.
