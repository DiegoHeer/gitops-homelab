# 0034 — PriceBuddy uses Postgres and env_file, not upstream MySQL + mounted .env

- **Status**: Accepted
- **Date**: 2026-09-24
- **Deciders**: Diego Heer

## Context

PriceBuddy (a Laravel app) ships a `docker-compose.yml` that uses MySQL 8.2 and
mounts a plaintext `./.env:/app/.env` into the app. This repo standardises on
Postgres for app databases and delivers secrets only through SOPS-encrypted
`env_file`, never a plaintext `.env`. PriceBuddy's docs confirm it also supports
the `pgsql` driver and reads standard Laravel environment variables.

## Decision

Run PriceBuddy on `postgres:16.14-alpine` with `DB_CONNECTION=pgsql`, and supply
all configuration via inline `environment` (non-secrets) plus a per-service
`services/tools/pricebuddy.enc.env` (secrets) — dropping the upstream mounted
`.env`. A dedicated env file is used, not the shared `services/tools/secrets.enc.env`,
because PriceBuddy needs `POSTGRES_PASSWORD` and `APP_KEY` with different values
than tandoor and speedtest_tracker already set in that shared file.

## Consequences

- `+` One database family to maintain; matches every other Postgres stack.
- `+` Secrets stay in the SOPS/age flow; no plaintext `.env` on the host.
- `+` Per-service env file avoids clobbering tandoor's DB and speedtest's APP_KEY.
- `−` Deviates from the upstream-documented setup; upgrades need a glance at
  upstream compose changes.
- `−` Small risk a future PriceBuddy release assumes a mounted `.env`; mitigation
  is to generate `/app/.env` at deploy only if env-var config stops working.

## Evidence

- `services/tools/docker-compose.yaml` — `pricebuddy`, `pricebuddy_postgres`,
  `pricebuddy_scraper` services.
- `services/tools/pricebuddy.enc.env` — SOPS-encrypted secrets (`APP_KEY`,
  `POSTGRES_PASSWORD`, `DB_PASSWORD`, `APP_USER_EMAIL`, `APP_USER_PASSWORD`).
- Upstream `jez500/pricebuddy` `docker-compose.yml` (MySQL + `./.env:/app/.env`).
- CLAUDE.md: final container var names in `.enc.env`; per-service split allowed
  when the same var name needs different values in one stack.
