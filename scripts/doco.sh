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

# How long to wait for a namespace provider to come back healthy before giving
# up rather than orphaning its dependents.
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-90}"

DRY_RUN=0
FORCE_KIND=""

# Colour only when stdout is a terminal, so piped/CI output stays clean.
# Same constants as scripts/dockcheck.sh.
if [ -t 1 ]; then
    RED=$'\033[31m'; YELLOW=$'\033[33m'; BOLD=$'\033[1m'; RESET=$'\033[0m'
else
    RED=""; YELLOW=""; BOLD=""; RESET=""
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

# Every docker call over DOCKER_HOST=ssh:// is its own SSH handshake, which on a
# slow link costs tens of seconds. So the whole container inventory - names and
# their compose project - is fetched once, in one call, and answered from memory.
INV_LOADED=0
declare -a INV_NAMES=()
declare -A INV_PROJECT=()

load_inventory() {
    [ "$INV_LOADED" -eq 1 ] && return 0
    local n p
    while IFS=$'\t' read -r n p; do
        [ -n "$n" ] || continue
        INV_NAMES+=("$n")
        INV_PROJECT["$n"]="$p"
    done < <(docker ps -a \
        --format '{{.Names}}	{{.Label "com.docker.compose.project"}}' 2>/dev/null || true)
    INV_LOADED=1
    return 0
}

stack_containers() {
    local want="$1" n
    load_inventory
    for n in "${INV_NAMES[@]}"; do
        [ "${INV_PROJECT[$n]:-}" = "$want" ] && printf '%s\n' "$n"
    done
    return 0
}

container_exists() {
    local want="$1" n
    load_inventory
    for n in "${INV_NAMES[@]}"; do
        [ "$n" = "$want" ] && return 0
    done
    return 1
}

all_names() {
    local n
    load_inventory
    {
        for n in "${INV_NAMES[@]}"; do
            printf '%s\n' "$n"
            [ -n "${INV_PROJECT[$n]:-}" ] && printf '%s\n' "${INV_PROJECT[$n]}"
        done
    } | grep -v '^$' | sort -u
    return 0
}

no_match_die() {
    local name="$1" what="${2:-stack or container}" near
    # -F: the needle is a literal name, not a pattern. A container called
    # `foo.bar` would otherwise match far more than intended.
    near="$(all_names | grep -iF -- "${name:0:4}" | head -5 | tr '\n' ' ' || true)"
    if [ -n "$near" ]; then
        die "no $what named '$name'. Did you mean: $near"
    fi
    die "no $what named '$name'"
}

# Echoes "stack" or "container". Dies on ambiguity or no match.
resolve_kind() {
    local name="$1"
    local is_stack=0 is_container=0
    [ -n "$(stack_containers "$name")" ] && is_stack=1
    container_exists "$name" && is_container=1

    # --stack/--container break ties; they do not bypass existence checking.
    # Skipping the lookup meant a typo sailed through to `docker restart <typo>`
    # and lost the near-match hint exactly when it is most useful.
    case "$FORCE_KIND" in
        stack)
            [ "$is_stack" -eq 1 ] || no_match_die "$name" "stack"
            printf 'stack\n'
            return 0
            ;;
        container)
            [ "$is_container" -eq 1 ] || no_match_die "$name" "container"
            printf 'container\n'
            return 0
            ;;
    esac

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

    no_match_die "$name"
}

containers_of() {
    local kind="$1" name="$2"
    case "$kind" in
        stack) stack_containers "$name" ;;
        container) printf '%s\n' "$name" ;;
        *) die "internal error: unknown kind '$kind'" ;;
    esac
}

# Echoes the names among "$@" whose network namespace another of them shares.
# One level of nesting, which is what `network_mode: service:X` produces here.
namespace_providers() {
    local -A id_of=() mode_of=() is_provider=()
    local name id netmode

    while IFS=$'\t' read -r name id netmode; do
        [ -n "$name" ] || continue
        id_of["$name"]="$id"
        mode_of["$name"]="$netmode"
    done < <(docker inspect \
        --format '{{.Name}}	{{.Id}}	{{.HostConfig.NetworkMode}}' "$@" 2>/dev/null \
        | sed 's|^/||' || true)

    for name in "$@"; do
        case "${mode_of[$name]:-}" in
            container:*) is_provider["${mode_of[$name]#container:}"]=1 ;;
        esac
    done

    for name in "$@"; do
        [ -n "${is_provider[${id_of[$name]:-_none_}]:-}" ] && printf '%s\n' "$name"
    done
    return 0
}

# Block until every named container is running and, if it declares a
# healthcheck, healthy. Bounded; returns non-zero on timeout.
wait_healthy() {
    local deadline=$(( SECONDS + HEALTH_TIMEOUT )) state health settled
    while :; do
        settled=1
        # One inspect for every provider, both fields at once - two calls per
        # poll turn into one, which matters when each is an SSH handshake.
        while IFS=$'\t' read -r state health; do
            [ -n "$state" ] || continue
            if [ "$state" != "running" ] || \
               { [ "$health" != "healthy" ] && [ "$health" != "none" ]; }; then
                settled=0
            fi
        done < <(docker inspect \
            --format '{{.State.Status}}	{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' \
            "$@" 2>/dev/null || true)

        [ "$settled" -eq 1 ] && return 0
        [ "$SECONDS" -ge "$deadline" ] && return 1
        sleep 2
    done
}

do_lifecycle() {
    local verb="$1" name="$2" kind containers count
    # Prime the inventory here, in the main shell: the command substitutions
    # below run in subshells and would each pay for their own docker call.
    load_inventory
    kind="$(resolve_kind "$name")"

    mapfile -t containers < <(containers_of "$kind" "$name")
    count="${#containers[@]}"
    if [ "$count" -eq 0 ]; then
        die "'$name' resolved to $kind but has no containers"
    fi

    printf '%s%s%s → %s, %d container(s): %s\n' \
        "$BOLD" "$name" "$RESET" "$kind" "$count" \
        "$(printf '%s, ' "${containers[@]}" | sed 's/, $//')"

    # Split off namespace providers. Restarting one gives it a fresh network
    # sandbox; anything on `network_mode: service:<it>` stays bound to the old,
    # now-stripped namespace - no eth0, no DNS - without exiting. Worse, those
    # containers' healthchecks only probe localhost, so docker keeps reporting
    # them healthy through a total outage. So providers must be restarted first
    # and be healthy again before their dependents rejoin.
    local -a providers=() rest=()
    if [ "$count" -gt 1 ] && [ "$verb" != "stop" ]; then
        mapfile -t providers < <(namespace_providers "${containers[@]}")
    fi

    if [ "${#providers[@]}" -gt 0 ]; then
        local c p keep
        for c in "${containers[@]}"; do
            keep=1
            for p in "${providers[@]}"; do
                [ "$c" = "$p" ] && { keep=0; break; }
            done
            [ "$keep" -eq 1 ] && rest+=("$c")
        done
    fi

    if [ "$DRY_RUN" -eq 1 ]; then
        if [ "${#providers[@]}" -gt 0 ]; then
            printf 'dry-run: would %s %s first, wait for healthy, then %s the remaining %d\n' \
                "$verb" "$(printf '%s, ' "${providers[@]}" | sed 's/, $//')" \
                "$verb" "${#rest[@]}"
        else
            printf 'dry-run: would %s %d container(s)\n' "$verb" "$count"
        fi
        return 0
    fi

    if [ "${#providers[@]}" -eq 0 ]; then
        if docker "$verb" "${containers[@]}" >/dev/null; then
            printf '%s %d/%d\n' "${verb}ed" "$count" "$count"
        else
            die "docker $verb failed for '$name'" 2
        fi
        return 0
    fi

    printf '  namespace provider(s) first: %s\n' \
        "$(printf '%s, ' "${providers[@]}" | sed 's/, $//')"
    docker "$verb" "${providers[@]}" >/dev/null \
        || die "docker $verb failed for provider(s) of '$name'" 2

    if ! wait_healthy "${providers[@]}"; then
        die "provider(s) not healthy within ${HEALTH_TIMEOUT}s - dependents left alone to avoid orphaning them into a dead namespace" 2
    fi

    if [ "${#rest[@]}" -gt 0 ]; then
        docker "$verb" "${rest[@]}" >/dev/null \
            || die "docker $verb failed for dependents of '$name'" 2
    fi
    printf '%s %d/%d\n' "${verb}ed" "$count" "$count"
}

# Classify each path as missing / empty / ok, one line per input, in order.
# One round trip: paths go in on stdin. Compose defaults to create_host_path:
# true, so a typo'd bind source is silently created as an empty directory and
# the container starts up healthy with no data - hence "empty" is flagged too.
# shellcheck disable=SC2016  # single quotes are deliberate: $p must expand on
# the remote side, not here. This string is script text, not a command.
PROBE_BODY='while IFS= read -r p; do
    if [ ! -e "$p" ]; then
        echo missing
    elif [ -d "$p" ] && [ -z "$(find "$p" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
        echo empty
    else
        echo ok
    fi
done'

probe_paths() {
    [ $# -eq 0 ] && return 0

    # Test seam: force a single classification for every path.
    if [ -n "${PROBE_OVERRIDE:-}" ]; then
        local _p
        for _p in "$@"; do printf '%s\n' "$PROBE_OVERRIDE"; done
        return 0
    fi

    # The probe must run on the same host `docker` is talking to. DOCKER_HOST is
    # deliberately respected if pre-set, so blindly ssh-ing to SERVER_SSH_ALIAS
    # could classify paths on a different machine - and a confident but wrong
    # missing/empty verdict is worse than no verdict at all. Refuse rather than
    # guess when the target is not reachable over ssh.
    case "${DOCKER_HOST:-}" in
        "")
            printf '%s\n' "$@" | bash -c "$PROBE_BODY"
            ;;
        ssh://*)
            # shellcheck disable=SC2029  # expanding PROBE_BODY client-side is the point:
            # we are shipping the script text to the remote shell to run.
            printf '%s\n' "$@" | ssh "${DOCKER_HOST#ssh://}" "$PROBE_BODY" \
                || die "ssh probe failed" 2
            ;;
        *)
            die "cannot probe bind paths over non-ssh DOCKER_HOST '$DOCKER_HOST'" 2
            ;;
    esac
}

do_mounts() {
    local name="$1" kind
    load_inventory
    kind="$(resolve_kind "$name")"

    local -a containers=()
    mapfile -t containers < <(containers_of "$kind" "$name")
    if [ "${#containers[@]}" -eq 0 ]; then
        die "'$name' resolved to $kind but has no containers"
    fi

    printf '%s%s%s → %s, %d container(s)\n' \
        "$BOLD" "$name" "$RESET" "$kind" "${#containers[@]}"

    # One inspect for the whole stack and one probe for every bind source in it.
    # Over DOCKER_HOST=ssh:// each docker call is its own handshake, so doing
    # this per container turned `mounts media` into ~24 round trips.
    local -a rows=() owners=() types=() sources=() targets=() modes=() binds=() statuses=()
    mapfile -t rows < <(docker inspect --format \
        '{{range .Mounts}}{{$.Name}}	{{.Type}}	{{.Source}}	{{.Destination}}	{{if .RW}}rw{{else}}ro{{end}}
{{end}}' "${containers[@]}" 2>/dev/null | grep -v '^$' || true)

    local r o t s d m
    for r in "${rows[@]}"; do
        IFS=$'\t' read -r o t s d m <<< "$r"
        owners+=("${o#/}"); types+=("$t"); sources+=("$s"); targets+=("$d"); modes+=("$m")
        [ "$t" = "bind" ] && binds+=("$s")
    done

    if [ "${#binds[@]}" -gt 0 ]; then
        mapfile -t statuses < <(probe_paths "${binds[@]}")
    fi

    # Pin each row's probe verdict to its row index, so printing order cannot
    # desynchronise the flags from the paths they describe.
    local -a row_status=()
    local i k=0
    for i in "${!types[@]}"; do
        if [ "${types[$i]}" = "bind" ]; then
            row_status[i]="${statuses[$k]:-ok}"
            k=$((k + 1))
        else
            row_status[i]="none"
        fi
    done

    local c flag printed
    for c in "${containers[@]}"; do
        printed=0
        for i in "${!owners[@]}"; do
            [ "${owners[$i]}" = "$c" ] || continue
            [ "$printed" -eq 0 ] && { printf '  %s\n' "$c"; printed=1; }
            case "${row_status[i]}" in
                missing) flag="  ${YELLOW}⚠ missing${RESET}" ;;
                empty) flag="  ${YELLOW}⚠ empty${RESET}" ;;
                *) flag="" ;;
            esac
            printf '    %-7s %s → %s  %s%s\n' \
                "${types[$i]}" "${sources[$i]}" "${targets[$i]}" "${modes[$i]}" "$flag"
        done
        [ "$printed" -eq 0 ] && printf '  %s: no mounts\n' "$c"
    done
    return 0
}

main() {
    local verb="" names=()

    if [ $# -eq 0 ]; then
        usage >&2
        exit 1
    fi

    verb="$1"
    shift

    # Asking for help is not an error: usage goes to stdout and exits 0. Every
    # other path below prints it to stderr, so a failed invocation never splits
    # its output across two streams.
    case "$verb" in
        -h|--help|help)
            usage
            exit 0
            ;;
    esac

    case " $VERBS " in
        *" $verb "*) ;;
        *)
            usage >&2
            die "unknown verb '$verb'"
            ;;
    esac

    while [ $# -gt 0 ]; do
        case "$1" in
            -h|--help) usage; exit 0 ;;
            --dry-run) DRY_RUN=1 ;;
            --stack) FORCE_KIND="stack" ;;
            --container) FORCE_KIND="container" ;;
            -*) die "unknown option '$1'" ;;
            *) names+=("$1") ;;
        esac
        shift
    done

    if [ "${#names[@]}" -eq 0 ]; then
        usage >&2
        die "verb '$verb' needs at least one name"
    fi

    local name
    for name in "${names[@]}"; do
        case "$verb" in
            restart|stop|start) do_lifecycle "$verb" "$name" ;;
            mounts) do_mounts "$name" ;;
        esac
    done
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi
