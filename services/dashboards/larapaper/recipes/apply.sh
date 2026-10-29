#!/usr/bin/env bash
set -euo pipefail

# Runs the weather-calendar LaraPaper recipe inside the larapaper container on
# the home server. Recipes live in LaraPaper's database, not in a compose file,
# so DocoCD does not deploy them; this script does.
#
#   apply.sh test              run the recipe's PHP tests
#
# Talks to the server's Docker over DOCKER_HOST=ssh://$SERVER_SSH_ALIAS, like
# scripts/doco.sh.

SERVER_SSH_ALIAS="${SERVER_SSH_ALIAS:-server}"
export DOCKER_HOST="${DOCKER_HOST:-ssh://$SERVER_SSH_ALIAS}"
CONTAINER="larapaper"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RECIPE_DIR="$SCRIPT_DIR/weather-calendar"
REMOTE_DIR="/tmp/trmnl-recipe"

usage() {
    echo "Usage: $0 {test}" >&2
    exit 2
}

push_recipe() {
    docker exec -u root "$CONTAINER" rm -rf "$REMOTE_DIR"
    docker cp -q "$RECIPE_DIR" "$CONTAINER:$REMOTE_DIR"
    trap 'docker exec -u root "$CONTAINER" rm -rf "$REMOTE_DIR"' EXIT
}

case "${1:-}" in
    test)
        push_recipe
        docker exec "$CONTAINER" php "$REMOTE_DIR/tests/run.php"
        ;;
    *)
        usage
        ;;
esac
