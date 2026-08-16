# 0025 — NetAlertX publishes to mosquitto anonymously

- **Status**: Accepted
- **Date**: 2026-08-16
- **Deciders**: Diego

## Context

[0023](0023-netalertx-presence-detection.md) assumed NetAlertX would authenticate to
mosquitto as a dedicated `netalertx` broker user, created by hand with
`mosquitto_passwd` against `/mosquitto/config/pwfile`.

That premise was wrong. The broker runs `allow_anonymous true` and has **no password
file at all** — `/mosquitto/config/` contains only `mosquitto.conf`. Every current
client connects anonymously: Home Assistant, `frigate`, and the compose healthcheck.
The healthcheck passes `-u healthcheck_user -P healthcheck_password`, but those
credentials are accepted and ignored, which makes the broker look authenticated when
it is not.

Adding a password file for NetAlertX alone does not work: enabling `password_file`
means setting `allow_anonymous false`, which disconnects every existing client at once.

## Decision

Connect NetAlertX to mosquitto **anonymously**, leaving `MQTT_USER`/`MQTT_PASSWORD`
unset in `APP_CONF_OVERRIDE`. Broker-wide authentication is deferred to its own change
that migrates all clients together.

The broker is reachable at `127.0.0.1:1883` because NetAlertX runs `network_mode: host`
alongside the published mosquitto port, so no credentials cross a network boundary.

## Consequences

- `+` No change to the running broker, so Home Assistant and `frigate` keep working
- `+` NetAlertX config stays fully declarative — no manual `mosquitto_passwd` step
- `−` The broker remains unauthenticated on the LAN; NetAlertX adds another anonymous client
- `−` Corrects the "create the broker user by hand" consequence in [0023](0023-netalertx-presence-detection.md),
  which described a broker state that never existed
- `−` A future move to authenticated MQTT must migrate every client at once, and will
  need to revisit this setting plus the misleading healthcheck credentials

## Evidence

- `services/home_assistant/docker-compose.yaml` (`mosquitto`, `netalertx`)
- `services/home_assistant/secrets.enc.env` (`APP_CONF_OVERRIDE`, no MQTT credentials)
- Broker config observed on the host: `allow_anonymous true`, no `password_file`
