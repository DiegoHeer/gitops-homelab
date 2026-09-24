# 0034 — PriceBuddy runs on MySQL with env_file, not a mounted .env

- **Status**: Accepted
- **Date**: 2026-09-24
- **Deciders**: Diego Heer

## Context

PriceBuddy (a Laravel/Filament app) ships a `docker-compose.yml` that uses MySQL
and mounts a plaintext `./.env:/app/.env` into the app. This repo delivers
secrets only through SOPS-encrypted `env_file`, never a plaintext `.env`.

Postgres was tried first (to keep one DB family) but failed in production: the
admin UI 500'd on every page with `SQLSTATE[42883]: operator does not exist:
text ->> unknown`. PriceBuddy queries JSON columns with the `->>` operator, and
Laravel's migrations create those columns as `text` (e.g. `notifications.data`).
MySQL tolerates `->>` on such columns; Postgres requires `json`/`jsonb`. MySQL
is the engine PriceBuddy builds and tests against.

## Decision

Run PriceBuddy on `mysql:8.2` with `DB_CONNECTION=mysql`. Supply configuration
via inline `environment` (non-secrets) plus a dedicated
`services/tools/pricebuddy.enc.env` (secrets) — dropping the upstream mounted
`.env`. A dedicated env file is used, not the shared
`services/tools/secrets.enc.env`, because PriceBuddy needs `APP_KEY` with a
different value than speedtest_tracker already sets in that shared file.

## Consequences

- `+` Admin UI works; uses PriceBuddy's supported, tested database.
- `+` Secrets stay in the SOPS/age flow; no plaintext `.env` on the host.
- `+` Per-service env file avoids clobbering speedtest_tracker's `APP_KEY`.
- `−` Adds a MySQL to the stack (a second DB family alongside Postgres).
- `−` Deviates from upstream only in secret delivery; upgrades need a glance at
  upstream compose changes.

## Evidence

- `services/tools/docker-compose.yaml` — `pricebuddy`, `pricebuddy_mysql`,
  `pricebuddy_scraper` services.
- `services/tools/pricebuddy.enc.env` — SOPS-encrypted secrets (`APP_KEY`,
  `MYSQL_PASSWORD`/`DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, admin creds).
- Rejected Postgres: production log `SQLSTATE[42883] ... text ->> unknown` on
  `select * from "notifications" ... "data"->>'format' = 'filament'`.
