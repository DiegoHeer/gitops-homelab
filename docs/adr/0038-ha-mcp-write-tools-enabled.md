# 0038 — ha-mcp write tools enabled for AI clients

- **Status**: Accepted
- **Date**: 2026-10-20
- **Deciders**: Diego

## Context

ADR 0037 ran ha-mcp read-only until read access had been verified. Read access
now works: debugging the leave-home automation found a light group that holds a
dead entity and the wrong bedroom LED, but the fix could not be applied through
MCP and had to be done by hand. The read-only phase has served its purpose.

## Decision

Remove `READ_ONLY_MODE` from the `ha_mcp` service and point the client config at
the full endpoint instead of the `/readonly` alias, so AI clients can edit
automations, scripts, scenes, helpers and dashboards.

## Consequences

- `+` AI clients can apply the fixes they find, not only report them.
- `−` A prompt injection in tool output (entity names, logs, calendar items) can
  now make the client change HA config or control devices. Claude Code's
  per-call permission prompts are the remaining guard.
- `−` A wrong edit changes the live house. Recovery is HA's backups and the
  automation/script edit history, not git.
- `−` Write access is no longer forced by the environment: ha-mcp's settings UI
  can turn read-only mode back on, but nothing in git records that it did.

## Evidence

- `services/home_assistant/docker-compose.yaml` (`ha_mcp` service)
- `.mcp.json` (`home-assistant` server URL)
- Supersedes [0037](0037-ha-mcp-read-only-for-ai-clients.md)
