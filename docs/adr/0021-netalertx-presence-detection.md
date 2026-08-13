# 0021 — NetAlertX for network presence detection into Home Assistant

- **Status**: Accepted
- **Date**: 2026-08-13
- **Deciders**: Diego

## Context

Home Assistant needs reliable device presence for automations. HA's built-in `ping`/`nmap`
trackers are per-device polling that misses briefly-connected devices and grows unwieldy as
the LAN changes. A dedicated L2 scanner tracks the whole subnet and surfaces new/unknown
devices, which is also a security signal.

## Decision

Run NetAlertX in the `home_assistant` stack with `network_mode: host` (required for its
`arp-scan` L2 discovery) and let it publish presence + new-device events to the existing
mosquitto broker via MQTT auto-discovery. Expose the UI at `netalertx.local.dynabase.nl`
through the Traefik file provider — the same host-network exposure pattern as Home Assistant
itself — rather than Docker-label discovery, which cannot see host-network containers.

## Consequences

- `+` Whole-subnet presence + "unknown device joined" alerts with zero HA-side YAML (MQTT discovery)
- `+` Co-located with mosquitto, so the MQTT hop is localhost and stays inside one stack
- `+` MQTT config (incl. broker password) stays in git via SOPS-encrypted `APP_CONF_OVERRIDE`
- `−` `network_mode: host` + `NET_ADMIN`/`NET_RAW` — a broader privilege surface than a bridge service
- `−` The mosquitto `netalertx` user must be created on the broker by hand (password file is host state, not git)
- `−` App-level config lives in the persisted `/data` bind mount, not fully in git (NetAlertX is UI-configured)

## Evidence

- `services/home_assistant/docker-compose.yaml` (`netalertx` service)
- `services/networking/traefik/config.yml` (`netalertx` router + service)
- `services/home_assistant/secrets.enc.env` (`APP_CONF_OVERRIDE` with MQTT settings)
