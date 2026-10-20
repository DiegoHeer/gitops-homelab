# 0037 — ha-mcp in read-only mode for AI clients

- **Status**: Superseded by 0038
- **Date**: 2026-10-20
- **Deciders**: Diego

## Context

AI clients such as Claude Code need MCP access to Home Assistant, mainly to debug
automations from their traces, history and logs. HA's built-in `mcp_server`
integration only exposes the Assist API: device control and live state of exposed
entities, with no automation, trace, log or dashboard tools. The community server
[ha-mcp](https://github.com/homeassistant-ai/ha-mcp) covers all of that, but it
authenticates to HA with an admin long-lived token, and in standalone HTTP mode the
only auth on its endpoint is a secret URL path. `voska/hass-mcp` was rejected
because it has a smaller tool set and its HTTP mode has no auth at all.

## Decision

Run `ghcr.io/homeassistant-ai/ha-mcp` as the `ha_mcp` service in the
`home_assistant` stack, reachable only on the LAN at `ha-mcp.local.dynabase.nl`
behind a high-entropy `MCP_SECRET_PATH`, with `READ_ONLY_MODE=true` forced
through the environment. Write tools are enabled later, by a separate change, only
after read-only use has been verified.

## Consequences

- `+` Automation traces, history, logs and config become readable to AI clients.
- `+` The env var beats ha-mcp's settings UI, so write tools stay blocked until a
  commit removes it. The block applies to every URL, not only the `/readonly` alias.
- `−` The admin token in `secrets.enc.env` grants full HA API access if the
  container is compromised. Read-only mode limits what the MCP server does with it,
  not what the token can do.
- `−` Anyone who learns the secret path on the LAN gets MCP access. The path is
  stored only in SOPS and in the client's MCP config.
- `−` Tool output (entity names, logs, calendar items) can carry prompt injection.
  This matters more once write tools are on.

## Evidence

- `services/home_assistant/docker-compose.yaml` (`ha_mcp` service)
- `services/home_assistant/secrets.enc.env` (`HOMEASSISTANT_TOKEN`, `MCP_SECRET_PATH`)
