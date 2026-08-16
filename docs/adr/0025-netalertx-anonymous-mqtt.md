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

Because the override therefore holds **no secrets**, it lives in the compose
`environment:` block as plaintext rather than in `secrets.enc.env`. Encrypting it would
have made the only substantive part of the config unreviewable in a diff while
protecting nothing — the subnet and interface are already plaintext elsewhere in this
repo. `netalertx` consequently needs no `env_file`, so it no longer inherits the
unrelated Samba credential the stack's shared secrets file carries.

`arp-scan` is scheduled at `*/30` rather than the usual `*/5`. The host reaches the LAN
over WiFi (`wlp1s0`) on a driver that has already dropped its AP association once, and
the host is also the Tailscale subnet router — so if a sweep wedges the NIC, the entire
`192.168.1.0/24` appears down and recovery is physical, not a redeploy. The slow cadence
is a soak period, to be tightened once the link proves stable or the host moves to
ethernet.

## Consequences

- `+` No change to the running broker, so Home Assistant and `frigate` keep working
- `+` NetAlertX config stays fully declarative — no manual `mosquitto_passwd` step
- `+` The override is diffable in review, since it is plaintext and holds nothing secret
- `−` The broker remains unauthenticated on the LAN; NetAlertX adds another anonymous client
- `−` These 10 settings are re-applied with `forceDefault` on **every** container start
  (`server/initialise.py`, `entrypoint.d/35-apply-conf-override.sh` at `v26.8.5`), so a
  UI edit to any of them appears to work and is then silently reverted on the next
  restart. This partly retires 0023's *"app-level config lives in the persisted `/data`
  bind mount, not fully in git"* downside, which now holds only for settings **not**
  pinned here
- `−` Presence detection is coarse at a 30-minute scan interval until the cadence is tightened
- `−` A future move to authenticated MQTT must migrate every client at once, will need to
  revisit this setting plus the misleading healthcheck credentials, and would move the
  override back into `secrets.enc.env`
- `−` Corrects two consequences in [0023](0023-netalertx-presence-detection.md), which
  described a broker state that never existed:
  - the `−` *"the mosquitto `netalertx` user must be created on the broker by hand"* —
    there is no such user and none is needed
  - the `+` *"MQTT config (incl. broker password) stays in git via SOPS-encrypted
    `APP_CONF_OVERRIDE`"* — there is no broker password, and the override is neither
    SOPS-encrypted nor in that file

## Evidence

- `services/home_assistant/docker-compose.yaml` (`mosquitto`, `netalertx` `APP_CONF_OVERRIDE`)
- Broker config observed on the host: `allow_anonymous true`, no `password_file`
- Anonymous publish/subscribe confirmed against the live broker with no credentials

## Follow-ups

- `mosquitto.conf` lives under `/home/diego/services_data/home_assistant/mosquitto/config`,
  so the broker's auth posture cannot be checked from the repo — which is exactly how 0023
  came to assert a broker state that never existed. Per the static-config convention it
  belongs in git as a relative bind mount. Natural companion to the deferred broker-wide
  auth migration.
