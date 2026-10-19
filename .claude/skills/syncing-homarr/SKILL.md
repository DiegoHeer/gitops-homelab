---
name: syncing-homarr
description: Use when a service is added to, removed from, renamed in, or re-hosted (Traefik Host label changed) in services/*/docker-compose.yaml, or when asked to add, move or remove an app or tile on the Homarr dashboard.
---

# Syncing Homarr

## Overview

Homarr has two layers: **apps** (name, icon, URL) and **tiles** on boards that point at an app via `options.appId`. Apps are safe to change with the `mcp__homarr__app_*` tools. Tiles are not: `board_addItem` only drops tiles on the main canvas (never inside a section like *Productivity*), and `board_saveBoard` **deletes every item you leave out**. Use `scripts/homarr_board.py` for all tile work — it backs up, saves, and verifies item IDs.

Needs `HOMARR_API_KEY` in the environment (from `.claude/settings.local.json` `env`).

## Quick reference

| Step | How |
|------|-----|
| Board / section overview | `python3 .claude/skills/syncing-homarr/scripts/homarr_board.py sections` |
| Where an app's tiles are | `... homarr_board.py find <app-id>` |
| Add tile | `... homarr_board.py place <board> <app-id> [--section <container title>]` |
| Remove tiles | `... homarr_board.py remove <app-id> [<board>...]` |
| Undo a board save | `... homarr_board.py restore ~/.cache/homarr-sync/<board>-<ts>.json` |

## Adding a service

1. **App fields**, matching existing apps (`mcp__homarr__app_all`):
   - `name`: the product name in lowercase, as people write it (`price buddy`, `it-tools`). Never use the snake_case compose key.
   - `href`: `https://` + the Traefik `Host(...)` from the compose labels.
   - `iconUrl`: an exact URL from `mcp__homarr__icon_findIcons`. Prefer `homarr-labs/dashboard-icons`, `svg` over `png`, then `selfhst/icons`. Never hand-build an icon URL.
   - `description: null`, `pingUrl: null`.
2. Check `app_search` first, so you don't create a duplicate. Then `app_create`, and keep the returned id.
3. **Pick boards and section.** Run `sections` and ask the user. Offer the section where the most similar existing app sits (`find` its id). Default: `home` only.
4. Run `place` for each chosen board. The script copies the board's usual tile options and layouts. It does not copy any single tile, so don't hand-copy one.
5. Confirm with `find <app-id>`.

## Removing a service

1. `app_search` → app id.
2. `remove <app-id>` (all boards).
3. `mcp__homarr__app_delete`.
4. `app_search` again. It must return nothing.

Rename or new URL: `app_update` only. Tiles follow the app id.

## Common mistakes

- Calling `board_saveBoard` by hand with a pasted list of items. One missing item is deleted silently.
- Using `board_addItem` for a sectioned board. The tile lands outside every container.
- Copying a single existing tile's options or layouts. Odd tiles exist (for example, a home tile with an extra Mobile position).
- Reporting success without checking the result with `find` / `app_search`.
- If the script prints `VERIFY FAILED`, run the `restore` command it prints before you do anything else.
