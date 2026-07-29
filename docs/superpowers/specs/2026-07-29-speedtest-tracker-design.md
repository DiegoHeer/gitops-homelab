# Speedtest Tracker — Design

**Date:** 2026-07-29
**Status:** Approved

## Context

Add [Speedtest Tracker](https://docs.speedtest-tracker.dev/) (self-hosted internet
speed monitoring) to the homelab as a new service under the existing `tools` stack.

## Decision

A single service appended to `services/tools/docker-compose.yaml`. No new category,
so `.doco-cd.yml` is unchanged (the `tools` stack is already registered).

### Image

`lscr.io/linuxserver/speedtest-tracker:v1.14.6-ls164` — the LinuxServer.io image used
throughout the official docs. Internal HTTP port `80`. Chosen over the author's native
`ghcr.io/alexjustesen/speedtest-tracker` (faster API but non-canonical) for
documentation alignment and Renovate tracking.

### Database

SQLite (`DB_CONNECTION=sqlite`). No sidecar DB container — the database lives inside the
`/config` bind mount. Appropriate for homelab scale; Postgres/MySQL would be YAGNI.

### Service definition

```yaml
  speedtest_tracker:
    container_name: speedtest_tracker
    image: lscr.io/linuxserver/speedtest-tracker:v1.14.6-ls164
    restart: unless-stopped
    env_file: secrets.enc.env
    environment:
      - PUID=1000
      - PGID=1000
      - TZ=Europe/Amsterdam
      - APP_URL=https://speedtest.local.dynabase.nl
      - DB_CONNECTION=sqlite
      - SPEEDTEST_SCHEDULE=*/30 * * * *
      - DISPLAY_TIMEZONE=Europe/Amsterdam
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.speedtest-tracker.rule=Host(`speedtest.local.dynabase.nl`)"
      - "traefik.http.routers.speedtest-tracker.entrypoints=https"
      - "traefik.http.routers.speedtest-tracker.tls=true"
      - "traefik.http.services.speedtest-tracker.loadbalancer.server.port=80"
    volumes:
      - /home/diego/services_data/tools/speedtest_tracker:/config
    healthcheck:
      test: curl -fSs http://localhost:80/api/healthcheck | jq -r .message || exit 1
      interval: 10s
      timeout: 10s
      retries: 3
      start_period: 30s
```

### Key decisions

- **No `ports:` block.** Traefik reaches the container over `home_server_network` on
  port 80, matching every other tools service. The official compose's `8080:80` /
  `8443:443` host mappings are dropped.
- **`APP_KEY` → `secrets.enc.env`.** The Laravel encryption key is a secret, so it goes
  in the SOPS-encrypted shared env file (referenced via `env_file`), alongside the
  existing `SECRET_KEY` / `POSTGRES_PASSWORD`. Generated with
  `echo "base64:$(openssl rand -base64 32)"` — the `base64:` prefix is mandatory.
  Cross-injection into other tools services is harmless (unknown vars are ignored).
- **`PUID/PGID=1000`** matches `diego` so the container can write the `/config` mount.
- **`TZ` / `DISPLAY_TIMEZONE = Europe/Amsterdam`** for local UI timestamps and cron.
- **Exposure: local-only** — `speedtest.local.dynabase.nl` via Traefik + Pi-hole, no
  public `*.dynabase.nl` router.
- **Schedule: every 30 minutes** (`*/30 * * * *`); adjustable later in the web UI.

## Consequences

- Runtime state persists at `/home/diego/services_data/tools/speedtest_tracker/`.
- README services table (Tools row) gains "Speedtest Tracker".
- No ADR: adding one service to an existing stack is routine and does not replace a
  load-bearing tool or change a subsystem.
- Deploy: commit `Services|Add: …` → push to `main` → DocoCD reconciles the tools stack.

## Evidence

- Official install docs: <https://docs.speedtest-tracker.dev/getting-started/installation/using-docker-compose>
- LinuxServer.io image reference: <https://docs.linuxserver.io/images/docker-speedtest-tracker/>
- Health-check endpoint: <https://docs.speedtest-tracker.dev/other/health-check>
