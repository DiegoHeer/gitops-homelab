"""Place, find and remove Homarr app tiles via the Homarr MCP endpoint.

Board saves replace the whole board, so every write is backed up first and verified after.
Needs HOMARR_API_KEY (set in .claude/settings.local.json env). HOMARR_URL overrides the endpoint.
"""

import argparse
import collections
import json
import os
import secrets
import string
import sys
import time
import urllib.request
from pathlib import Path

HOMARR_URL = os.environ.get("HOMARR_URL", "https://homarr.local.dynabase.nl/api/mcp")
BACKUP_DIR = Path.home() / ".cache" / "homarr-sync"


def call(tool, arguments):
    body = json.dumps(
        {"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": tool, "arguments": arguments}}
    )
    request = urllib.request.Request(
        HOMARR_URL,
        data=body.encode(),
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "ApiKey": os.environ["HOMARR_API_KEY"],
        },
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        raw = response.read().decode()
    payload = next((line[6:] for line in raw.splitlines() if line.startswith("data: ")), raw)
    result = json.loads(payload)
    if "error" in result or result["result"].get("isError"):
        sys.exit(f"{tool} failed: {json.dumps(result)[:500]}")
    return json.loads(result["result"]["content"][0]["text"] or "null")


def board_names():
    return [board["name"] for board in call("board_getAllBoards", {})]


def read_board(name):
    return call("board_getBoardByName", {"name": name})


def backup(board):
    BACKUP_DIR.mkdir(parents=True, exist_ok=True)
    path = BACKUP_DIR / f"{board['name']}-{time.strftime('%Y%m%d-%H%M%S')}.json"
    path.write_text(json.dumps(board))
    return path


def save_payload(board, items):
    def section(s):
        keys = (
            ("id", "kind", "xOffset", "yOffset")
            if s["kind"] == "empty"
            else ("id", "kind", "collapsed", "layouts", "options")
        )
        return {k: s[k] for k in keys}

    item_keys = ("id", "kind", "options", "layouts", "integrationIds", "advancedOptions")
    return {
        "id": board["id"],
        "sections": [section(s) for s in board["sections"]],
        "items": [{k: i[k] for k in item_keys} for i in items],
    }


def save_verified(board, items):
    """Back up, save, and confirm the board now holds exactly `items`."""
    backup_path = backup(board)
    call("board_saveBoard", save_payload(board, items))
    saved_ids = {i["id"] for i in read_board(board["name"])["items"]}
    expected_ids = {i["id"] for i in items}
    if saved_ids != expected_ids:
        sys.exit(f"VERIFY FAILED on {board['name']}: restore with `restore {backup_path}`")
    print(f"{board['name']}: saved {len(items)} items (backup {backup_path})")


def app_tiles(board, app_id):
    return [i for i in board["items"] if i["kind"] == "app" and i["options"].get("appId") == app_id]


def section_title(board, section_id):
    section = next(s for s in board["sections"] if s["id"] == section_id)
    return (section.get("options") or {}).get("title") or "(main)"


def describe_tile(board, tile):
    layout_names = {layout["id"]: layout["name"] for layout in board["layouts"]}
    spots = [
        f"{layout_names[pos['layoutId']]}:{section_title(board, pos['sectionId'])}@{pos['xOffset']},{pos['yOffset']}"
        for pos in tile["layouts"]
    ]
    return " ".join(spots)


def usual_layout_ids(board):
    """Layouts that most app tiles on this board are positioned in."""
    apps = [i for i in board["items"] if i["kind"] == "app"]
    counts = collections.Counter(pos["layoutId"] for i in apps for pos in i["layouts"])
    return [layout["id"] for layout in board["layouts"] if counts[layout["id"]] * 2 >= len(apps)]


def usual_options(board):
    apps = [i for i in board["items"] if i["kind"] == "app"]
    if not apps:
        return {}
    counts = collections.Counter(
        json.dumps({k: v for k, v in i["options"].items() if k != "appId"}, sort_keys=True) for i in apps
    )
    return json.loads(counts.most_common(1)[0][0])


def target_section(board, layout_id, title):
    """The titled container if it exists in this layout, else the main (empty) section."""
    for s in board["sections"]:
        in_layout = any(pos["layoutId"] == layout_id for pos in s.get("layouts") or [])
        if title and in_layout and (s.get("options") or {}).get("title", "").lower() == title.lower():
            return s
    return next(s for s in board["sections"] if s["kind"] == "empty")


def section_box(board, section, layout_id):
    """(width, height) of the section in this layout; height None means unbounded."""
    if section["kind"] == "empty":
        layout = next(layout for layout in board["layouts"] if layout["id"] == layout_id)
        return layout["columnCount"], None
    pos = next(pos for pos in section["layouts"] if pos["layoutId"] == layout_id)
    return pos["width"], pos["height"]


def occupied_cells(board, section_id, layout_id):
    rects = [
        pos
        for i in board["items"]
        for pos in i["layouts"]
        if pos["layoutId"] == layout_id and pos["sectionId"] == section_id
    ]
    rects += [
        pos
        for s in board["sections"]
        for pos in s.get("layouts") or []
        if pos["layoutId"] == layout_id and pos["parentSectionId"] == section_id
    ]
    return {
        (x, y)
        for r in rects
        for x in range(r["xOffset"], r["xOffset"] + r["width"])
        for y in range(r["yOffset"], r["yOffset"] + r["height"])
    }


def first_free_cell(width, taken):
    y = 0
    while True:
        for x in range(width):
            if (x, y) not in taken:
                return x, y
        y += 1


def new_tile_position(board, layout_id, section_title_hint):
    section = target_section(board, layout_id, section_title_hint)
    width, height = section_box(board, section, layout_id)
    x, y = first_free_cell(width, occupied_cells(board, section["id"], layout_id))
    if height is not None and y >= height:
        print(
            f"WARNING: {section_title(board, section['id'])} is full; tile lands below it — resize the container in the UI"
        )
    return {"layoutId": layout_id, "sectionId": section["id"], "xOffset": x, "yOffset": y, "width": 1, "height": 1}


def cmd_sections(args):
    for name in args.boards or board_names():
        board = read_board(name)
        layouts = {layout["id"]: layout["name"] for layout in board["layouts"]}
        usual = [layouts[layout_id] for layout_id in usual_layout_ids(board)]
        print(f"{name}: tiles usually in layouts {usual}")
        for s in board["sections"]:
            tiles = sum(1 for i in board["items"] for pos in i["layouts"] if pos["sectionId"] == s["id"])
            print(f"  {s['kind']:9} {section_title(board, s['id']):15} {tiles} tile positions")


def cmd_find(args):
    for name in board_names():
        board = read_board(name)
        for tile in app_tiles(board, args.app_id):
            print(f"{name}: tile {tile['id']} {describe_tile(board, tile)}")


def cmd_place(args):
    board = read_board(args.board)
    if app_tiles(board, args.app_id):
        sys.exit(f"{args.board} already has a tile for {args.app_id}")
    tile = {
        "id": "".join(secrets.choice(string.ascii_lowercase + string.digits) for _ in range(24)),
        "kind": "app",
        "options": {**usual_options(board), "appId": args.app_id},
        "layouts": [new_tile_position(board, layout_id, args.section) for layout_id in usual_layout_ids(board)],
        "integrationIds": [],
        "advancedOptions": {"title": None, "customCssClasses": [], "borderColor": ""},
    }
    save_verified(board, board["items"] + [tile])
    placed = next(iter(app_tiles(read_board(args.board), args.app_id)))
    print(f"{args.board}: placed {describe_tile(board, placed)}")


def cmd_remove(args):
    for name in args.boards or board_names():
        board = read_board(name)
        doomed = {tile["id"] for tile in app_tiles(board, args.app_id)}
        if doomed:
            save_verified(board, [i for i in board["items"] if i["id"] not in doomed])


def cmd_restore(args):
    snapshot = json.loads(Path(args.backup).read_text())
    save_verified(read_board(snapshot["name"]), snapshot["items"])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(required=True)

    sections = sub.add_parser("sections", help="list boards, their sections, and which layouts tiles use")
    sections.add_argument("boards", nargs="*")
    sections.set_defaults(run=cmd_sections)

    find = sub.add_parser("find", help="list every tile of an app across all boards")
    find.add_argument("app_id")
    find.set_defaults(run=cmd_find)

    place = sub.add_parser("place", help="add a 1x1 tile for an app at the first free cell")
    place.add_argument("board")
    place.add_argument("app_id")
    place.add_argument("--section", help="container title (e.g. Productivity); default is the main section")
    place.set_defaults(run=cmd_place)

    remove = sub.add_parser("remove", help="remove every tile of an app (all boards unless named)")
    remove.add_argument("app_id")
    remove.add_argument("boards", nargs="*")
    remove.set_defaults(run=cmd_remove)

    restore = sub.add_parser("restore", help="put a board back from a backup file")
    restore.add_argument("backup")
    restore.set_defaults(run=cmd_restore)

    args = parser.parse_args()
    args.run(args)


if __name__ == "__main__":
    main()
