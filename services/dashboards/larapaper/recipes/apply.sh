#!/usr/bin/env bash
set -euo pipefail

# Runs the weather-calendar LaraPaper recipe inside the larapaper container on
# the home server. Recipes live in LaraPaper's database, not in a compose file,
# so DocoCD does not deploy them; this script does.
#
#   apply.sh test              run the recipe's PHP tests
#   apply.sh render [outdir]   render the fixture scenarios to PNGs (live screen untouched)
#
# Talks to the server's Docker over DOCKER_HOST=ssh://$SERVER_SSH_ALIAS, like
# scripts/doco.sh.

SERVER_SSH_ALIAS="${SERVER_SSH_ALIAS:-server}"
export DOCKER_HOST="${DOCKER_HOST:-ssh://$SERVER_SSH_ALIAS}"
CONTAINER="larapaper"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RECIPE_DIR="$SCRIPT_DIR/weather-calendar"
REMOTE_DIR="/tmp/trmnl-recipe"
GENERATED_DIR="/var/www/html/storage/app/public/images/generated"

usage() {
    echo "Usage: $0 {test|render [outdir]}" >&2
    exit 2
}

push_recipe() {
    docker exec -u root "$CONTAINER" rm -rf "$REMOTE_DIR"
    docker cp -q "$RECIPE_DIR" "$CONTAINER:$REMOTE_DIR"
    trap 'docker exec -u root "$CONTAINER" rm -rf "$REMOTE_DIR"' EXIT
}

# Reads "<name>=<uuid>" lines; copies each generated PNG to $1/<name>.png and
# removes it from the server.
fetch_images() {
    local outdir="$1" name uuid
    mkdir -p "$outdir"
    while IFS='=' read -r name uuid; do
        docker cp -q "$CONTAINER:$GENERATED_DIR/$uuid.png" "$outdir/$name.png"
        docker exec -u root "$CONTAINER" rm -f "$GENERATED_DIR/$uuid.png"
        echo "$outdir/$name.png"
    done
}

case "${1:-}" in
    test)
        push_recipe
        docker exec "$CONTAINER" php "$REMOTE_DIR/tests/run.php"
        ;;
    render)
        push_recipe
        docker exec "$CONTAINER" php "$REMOTE_DIR/render-fixtures.php" | fetch_images "${2:-${TMPDIR:-/tmp}/trmnl-render}"
        ;;
    *)
        usage
        ;;
esac
