# 0039 — TRMNL screen as one LaraPaper recipe, applied by script

- **Status**: Accepted
- **Date**: 2026-10-29
- **Deciders**: Diego

## Context

The TRMNL kit needs weather on 3/4 of the screen and the calendar on 1/4, but
LaraPaper mashups only split the screen into equal parts. The screen combines
five sources (Buienradar 5-minute and hourly rain, Open-Meteo, MeteoAlarm, the
Google Calendar iCal link). A small data service in the dashboards stack, which
would pre-compute everything into one JSON, was considered and rejected for now:
it adds a service to run for one screen. LaraPaper keeps recipes in its database,
so DocoCD cannot deploy them.

## Decision

Render the screen as one LaraPaper Blade recipe that polls all five URLs. Keep
its source in `services/dashboards/larapaper/recipes/` and apply it with
`apply.sh setup`, which builds the markup, writes it into LaraPaper and sets the
device's refresh and night sleep. The iCal URL lives in its own SOPS file there,
not in `services/dashboards/secrets.enc.env`: that file is an `env_file` for the
stack's containers, and the URL must not reach Homarr's or LaraPaper's environment.

## Consequences

- `+` No new service: the whole screen is one recipe in an existing container.
- `+` The logic is pure PHP closures with tests (`apply.sh test`) and fixture
  renders (`apply.sh render`) that never touch the live screen.
- `−` A merge to `main` does not change the screen; someone must run
  `apply.sh setup`. The README says so.
- `−` The logic runs inside Blade, so it is limited to what a template can do
  (no measuring; the calendar fit uses estimated pixel heights).
- `−` If the template grows too complex or the payload nears LaraPaper's 5 MB
  limit, the data-service approach is the fallback.

## Evidence

- `services/dashboards/larapaper/recipes/` (recipe, tests, `apply.sh`)
- `README.md` (deploy exception note)
