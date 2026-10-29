#!/usr/bin/env bash
set -euo pipefail

# Runs the weather-calendar LaraPaper recipe inside the larapaper container on
# the home server. Recipes live in LaraPaper's database, not in a compose file,
# so DocoCD does not deploy them; this script does.
#
#   apply.sh test              run the recipe's PHP tests
#   apply.sh render [outdir]   render the fixture scenarios to PNGs (live screen untouched)
#   apply.sh setup [outdir]    install/update the recipe on the TRMNL and fetch the live screen
#
# Talks to the server's Docker over DOCKER_HOST=ssh://$SERVER_SSH_ALIAS unless
# already running on the server itself, like scripts/doco.sh.

SERVER_HOSTNAME="${SERVER_HOSTNAME:-home}"
SERVER_SSH_ALIAS="${SERVER_SSH_ALIAS:-server}"
if [ -z "${DOCKER_HOST:-}" ] && [ "$(hostname)" != "$SERVER_HOSTNAME" ]; then
    export DOCKER_HOST="ssh://$SERVER_SSH_ALIAS"
fi
CONTAINER="larapaper"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RECIPE_DIR="$SCRIPT_DIR/weather-calendar"
REMOTE_DIR="/tmp/trmnl-recipe"
GENERATED_DIR="/var/www/html/storage/app/public/images/generated"

usage() {
    echo "Usage: $0 {test|render [outdir]|setup [outdir]}" >&2
    exit 2
}

push_recipe() {
    docker exec -u root "$CONTAINER" rm -rf "$REMOTE_DIR"
    docker cp -q "$RECIPE_DIR" "$CONTAINER:$REMOTE_DIR"
    trap 'docker exec -u root "$CONTAINER" rm -rf "$REMOTE_DIR"' EXIT
}

# Reads "<name>=<uuid>" lines; copies each generated PNG to $1/<name>.png.
# Fixture renders are removed from the server; the live screen ($2 = keep) is not.
fetch_images() {
    local outdir="$1" keep="${2:-}" name uuid
    mkdir -p "$outdir"
    while IFS='=' read -r name uuid; do
        docker cp -q "$CONTAINER:$GENERATED_DIR/$uuid.png" "$outdir/$name.png"
        [ "$keep" = keep ] || docker exec -u root "$CONTAINER" rm -f "$GENERATED_DIR/$uuid.png"
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
        # Only "<name>=<uuid>" lines; a PHP notice on stdout must not be read as an image.
        docker exec "$CONTAINER" php "$REMOTE_DIR/render-fixtures.php" \
            | grep -E '^[a-z_]+=[0-9a-f-]+$' | fetch_images "${2:-${TMPDIR:-/tmp}/trmnl-render}"
        ;;
    setup)
        ICS_URL="$(sops -d "$SCRIPT_DIR/secrets.enc.env" | sed -n 's/^TRMNL_ICS_URL=//p')"
        export ICS_URL
        push_recipe
        # -e ICS_URL passes the value from this shell's environment, never on a command line.
        docker exec -e ICS_URL "$CONTAINER" php "$REMOTE_DIR/setup.php" \
            | tee /dev/stderr | grep '^live=' | fetch_images "${2:-${TMPDIR:-/tmp}/trmnl-render}" keep
        ;;
    *)
        usage
        ;;
esac
