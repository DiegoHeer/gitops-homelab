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

stack_containers() {
    docker ps -a --filter "label=com.docker.compose.project=$1" \
        --format '{{.Names}}' 2>/dev/null || true
}

container_exists() {
    local found
    found="$(docker ps -a --filter "name=^$1\$" --format '{{.Names}}' 2>/dev/null || true)"
    [ -n "$found" ]
}

all_names() {
    {
        docker ps -a --format '{{.Names}}' 2>/dev/null || true
        docker ps -a --format '{{.Label "com.docker.compose.project"}}' 2>/dev/null || true
    } | grep -v '^$' | sort -u
}

# Echoes "stack" or "container". Dies on ambiguity or no match.
resolve_kind() {
    local name="$1"

    if [ -n "$FORCE_KIND" ]; then
        printf '%s\n' "$FORCE_KIND"
        return 0
    fi

    local is_stack=0 is_container=0
    [ -n "$(stack_containers "$name")" ] && is_stack=1
    container_exists "$name" && is_container=1

    if [ "$is_stack" -eq 1 ] && [ "$is_container" -eq 1 ]; then
        die "'$name' is ambiguous - it is both a stack and a container. Use --stack or --container."
    fi

    if [ "$is_stack" -eq 1 ]; then
        printf 'stack\n'
        return 0
    fi

    if [ "$is_container" -eq 1 ]; then
        printf 'container\n'
        return 0
    fi

    local near
    near="$(all_names | grep -i -- "${name:0:4}" | head -5 | tr '\n' ' ' || true)"
    if [ -n "$near" ]; then
        die "no stack or container named '$name'. Did you mean: $near"
    fi
    die "no stack or container named '$name'"
}

containers_of() {
    local kind="$1" name="$2"
    case "$kind" in
        stack) stack_containers "$name" ;;
        container) printf '%s\n' "$name" ;;
        *) die "internal error: unknown kind '$kind'" ;;
    esac
}

do_lifecycle() {
    local verb="$1" name="$2" kind containers count
    kind="$(resolve_kind "$name")"

    mapfile -t containers < <(containers_of "$kind" "$name")
    count="${#containers[@]}"
    if [ "$count" -eq 0 ]; then
        die "'$name' resolved to $kind but has no containers"
    fi

    printf '%s%s%s → %s, %d container(s): %s\n' \
        "$BOLD" "$name" "$RESET" "$kind" "$count" \
        "$(printf '%s, ' "${containers[@]}" | sed 's/, $//')"

    if [ "$DRY_RUN" -eq 1 ]; then
        printf 'dry-run: would %s %d container(s)\n' "$verb" "$count"
        return 0
    fi

    if docker "$verb" "${containers[@]}" >/dev/null; then
        printf '%s %d/%d\n' "${verb}ed" "$count" "$count"
    else
        die "docker $verb failed for '$name'" 2
    fi
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

    local name
    for name in "${names[@]}"; do
        case "$verb" in
            restart|stop|start) do_lifecycle "$verb" "$name" ;;
            mounts) die "mounts is not implemented yet" ;;
        esac
    done
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi
