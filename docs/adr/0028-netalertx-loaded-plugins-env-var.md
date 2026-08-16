# 0028 — NetAlertX's plugin roster is pinned via the `LOADED_PLUGINS` env var

- **Status**: Accepted
- **Date**: 2026-08-16
- **Deciders**: Diego

Supersedes [0027](0027-netalertx-pinned-plugin-roster.md).

## Context

[0027](0027-netalertx-pinned-plugin-roster.md) pinned the roster by adding
`LOADED_PLUGINS` to `APP_CONF_OVERRIDE`. That shipped and did not work: MQTT still
logged `⛔ Unloading MQTT` on every boot and its settings never registered.

The two mechanisms write to different places at different times:

- `entrypoint.d/35-apply-conf-override.sh` writes `APP_CONF_OVERRIDE` to
  `app_conf_override.json`, which `server/initialise.py` applies to the **settings
  database** — *after* the plugin loader has already decided what to load.
- The plugin loader reads the roster from **`app.conf`**, which `APP_CONF_OVERRIDE`
  never rewrites.

So the override did update `LOADED_PLUGINS` in the database — the value was visibly
correct there — while `app.conf` still held the old 17-entry roster and the loader kept
unloading MQTT. Checking the database made the change look applied when it was not.

`entrypoint.d/36-override-individual-settings.sh` exists for exactly this case: a
top-level `LOADED_PLUGINS` environment variable, `sed`-ed straight into `app.conf` before
the application starts. It is the only setting given this treatment.

## Decision

Set the roster as a **top-level `LOADED_PLUGINS` environment variable** on the container,
formatted as the Python list literal `app.conf` expects, and remove the key from
`APP_CONF_OVERRIDE`.

The remaining `MQTT_*` keys stay in `APP_CONF_OVERRIDE`: once the plugin actually loads,
it registers its settings and the override applies to them normally.

## Consequences

- `+` The roster reaches the plugin loader, so MQTT loads and its settings register
- `+` Uses the mechanism upstream provides rather than one that silently no-ops
- `−` NetAlertX config now spans two env vars with different semantics — `LOADED_PLUGINS`
  edits `app.conf` pre-boot, `APP_CONF_OVERRIDE` edits the settings DB post-boot
- `−` The roster is still a full replacement, so the maintenance obligations from
  [0027](0027-netalertx-pinned-plugin-roster.md) carry over unchanged: upstream plugins
  will not load until added here, and a dropped entry silently disables a plugin
- `−` Verifying a settings change requires reading `app.conf`, not just the settings
  table — the database can show a value the running application is not using

## Evidence

- `services/home_assistant/docker-compose.yaml` (`netalertx` `LOADED_PLUGINS`)
- `entrypoint.d/36-override-individual-settings.sh` at `v26.8.5`
- Observed under 0027: DB roster contained `MQTT` while `app.conf` did not, and the
  loader logged `Plugins to load: 18` without it on two consecutive boots
