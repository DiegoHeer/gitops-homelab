# 0027 — NetAlertX's plugin roster is pinned in git

- **Status**: Superseded by 0028
- **Date**: 2026-08-16
- **Deciders**: Diego

## Context

[0025](0025-netalertx-anonymous-mqtt.md) pinned NetAlertX's MQTT settings via
`APP_CONF_OVERRIDE`, on the assumption that the MQTT publisher ships enabled and only
its `MQTT_RUN` needed switching on. That was wrong.

NetAlertX loads plugins from a `LOADED_PLUGINS` roster, and MQTT was not in it. An
unloaded plugin never registers its settings, so every `MQTT_*` key in the override was
silently discarded — no error, no log, the keys simply absent from the settings table.
MQTT publishing did not work, while the ARP settings from the same override applied
correctly, which made the failure easy to miss.

The roster lives in the container's SQLite DB under the `/data` bind mount. Toggling
MQTT on in the UI would fix it, but leaves the roster as host state: lost on a `/data`
rebuild and absent from any fresh deploy.

## Decision

Pin the whole `LOADED_PLUGINS` roster in `APP_CONF_OVERRIDE` — the 18 plugins already
loaded, plus `MQTT`. The roster is enumerated in full because the setting is a
replacement, not a merge: naming only `MQTT` would unload everything else.

## Consequences

- `+` MQTT publishing works from a cold start with no UI step, so a rebuilt `/data` or a
  fresh deploy comes up correctly
- `+` The set of active plugins is reviewable in the compose file instead of being
  invisible host state
- `−` The roster must be maintained by hand: plugins added upstream will not load until
  added here, and `DISCOVER_PLUGINS` no longer has any practical effect
- `−` Enabling a plugin through the UI now appears to work and is silently reverted on
  the next restart, since the override is re-applied with `forceDefault` every boot
  (see [0025](0025-netalertx-anonymous-mqtt.md))
- `−` A dropped entry silently disables a plugin, and the failure mode is quiet — exactly
  how the MQTT gap went unnoticed

## Evidence

- `services/home_assistant/docker-compose.yaml` (`netalertx` `APP_CONF_OVERRIDE`)
- Upstream `docs/PLUGINS.md` at `v26.8.5`: plugins load via the `LOADED_PLUGINS` setting
- Observed before this change: `MQTT_*` absent from the settings table while
  `SCAN_SUBNETS` and `ARPSCAN_RUN_SCHD` from the same override applied
