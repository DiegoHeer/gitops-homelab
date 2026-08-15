#!/usr/bin/env bash
set -euo pipefail

# Debug helper for DocoCD-managed stacks. Restart/stop/start a whole stack or a
# single container, or inspect its mounts, without hand-running docker commands.
# Talks to the home server over DOCKER_HOST=ssh://$SERVER_SSH_ALIAS unless
# already running on the server itself.
#
# DocoCD remains the source of truth for stack *definitions*; this only acts on
# running state. See docs/adr/0021-ssh-over-dococd-api-for-debug-tooling.md.

SERVER_HOSTNAME="${SERVER_HOSTNAME:-home}"
SERVER_SSH_ALIAS="${SERVER_SSH_ALIAS:-server}"
if [ -z "${DOCKER_HOST:-}" ] && [ "$(hostname)" != "$SERVER_HOSTNAME" ]; then
    export DOCKER_HOST="ssh://$SERVER_SSH_ALIAS"
fi

VERBS="restart stop start mounts"

DRY_RUN=0
FORCE_KIND=""

# Colour only when stdout is a terminal, so piped/CI output stays clean.
# Same constants as scripts/dockcheck.sh.
if [ -t 1 ]; then
    RED=$'\033[31m'; BOLD=$'\033[1m'; RESET=$'\033[0m'
else
    RED=""; BOLD=""; RESET=""
fi

die() {
    printf '%sdoco:%s %s\n' "$RED" "$RESET" "$1" >&2
    exit "${2:-1}"
}

usage() {
    cat <<'EOF'
usage: doco.sh <verb> <name>... [--dry-run] [--stack|--container]

verbs:
  restart   restart a stack or container
  stop      stop a stack or container
  start     start a stack or container
  mounts    list mounts, flagging missing or empty bind sources

name:
  a stack name (e.g. media) or a container name (e.g. jellyfin).
  Resolved automatically against live Docker state.

options:
  --dry-run     resolve and print what would happen, change nothing
  --stack       force every name to be treated as a stack
  --container   force every name to be treated as a container

Config via env: SERVER_HOSTNAME (default home), SERVER_SSH_ALIAS (default server).
EOF
}

main() {
    local verb="" names=()

    if [ $# -eq 0 ]; then
        usage
        exit 1
    fi

    verb="$1"
    shift

    case " $VERBS " in
        *" $verb "*) ;;
        *)
            usage
            die "unknown verb '$verb'"
            ;;
    esac

    while [ $# -gt 0 ]; do
        case "$1" in
            --dry-run) DRY_RUN=1 ;;
            --stack) FORCE_KIND="stack" ;;
            --container) FORCE_KIND="container" ;;
            -*) die "unknown option '$1'" ;;
            *) names+=("$1") ;;
        esac
        shift
    done

    if [ "${#names[@]}" -eq 0 ]; then
        usage
        die "verb '$verb' needs at least one name"
    fi

    # Verb dispatch is filled in by later commits.
    local name
    for name in "${names[@]}"; do
        printf '%s%s%s verb=%s dry_run=%s force_kind=%s\n' \
            "$BOLD" "$name" "$RESET" "$verb" "$DRY_RUN" "$FORCE_KIND"
    done
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi
