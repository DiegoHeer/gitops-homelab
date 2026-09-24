# 0035 — PriceBuddy switches from Postgres to MySQL

- **Status**: Accepted
- **Date**: 2026-09-24
- **Deciders**: Diego Heer

## Context

ADR 0034 ran PriceBuddy on Postgres. In production the admin UI returned HTTP
500 on every page: `SQLSTATE[42883]: operator does not exist: text ->> unknown`.
PriceBuddy (Filament/Laravel) queries JSON columns with the `->>` operator, but
Laravel's built-in migrations create those columns as `text` (confirmed:
`notifications.data` is `text`). MySQL tolerates `->>` on such columns; Postgres
requires `json`/`jsonb`, so the query fails. PriceBuddy's docs flag JSON columns
as a hard requirement and ship/test on MySQL. This is the "less-tested pgsql
path" risk that ADR 0034 explicitly accepted, now realised.

## Decision

Run PriceBuddy on `mysql:8.2` (`pricebuddy_mysql`) with `DB_CONNECTION=mysql`,
the database engine the project builds and tests against. Patching individual
columns to `jsonb` by hand was rejected: it is manual, non-reproducible (a fresh
deploy recreates `text` columns), and would recur on every other JSON column.

## Consequences

- `+` Admin UI works; removes this and every future `->>`-on-text 500.
- `+` Stays on PriceBuddy's supported, tested database.
- `−` Adds a MySQL to the stack — the thing ADR 0034 aimed to avoid.
- `−` The old Postgres data dir `/home/diego/services_data/tools/pricebuddy/postgresql`
  is now orphaned on the host (safe to delete; data was a fresh install).

## Evidence

- Supersedes ADR 0034.
- `services/tools/docker-compose.yaml` — `pricebuddy_mysql` service; app env
  `DB_CONNECTION=mysql`, `DB_HOST=pricebuddy_mysql`, `DB_PORT=3306`.
- `services/tools/pricebuddy.enc.env` — `MYSQL_PASSWORD` (= `DB_PASSWORD`),
  `MYSQL_ROOT_PASSWORD`; `POSTGRES_PASSWORD` removed.
- Production log: `SQLSTATE[42883] ... operator does not exist: text ->> unknown`
  on `select * from "notifications" ... "data"->>'format' = 'filament'`.
