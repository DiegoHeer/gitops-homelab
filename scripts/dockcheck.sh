#!/usr/bin/env bash
set -euo pipefail

# Standalone container health checker for DocoCD-managed stacks. Runs on the
# home server (local docker) or any other host on the network (auto-tunnels
# via ssh://$SERVER_SSH_ALIAS). Stack list and compose files are fetched from
# the public repo at runtime — no repo clone needed on the host.

usage() {
    cat <<'EOF'
usage: dockcheck.sh [<name>...] [--help]

Prints a status table for every container in every DocoCD-managed stack.
With no arguments the whole homelab is checked.

name:
  a stack name (e.g. media) or a container name (e.g. jellyfin).
  Resolved against the stack list and compose files in the repo, not against
  live Docker state — so a container that has disappeared entirely is still
  reported as "missing" when you filter by its stack.

  Unlike doco.sh there is no --stack/--container disambiguation: this command
  only reads, so a name that is both matches both rather than erroring.

exit status:
  0  every checked container is healthy
  1  at least one is not
  2  the check could not run (fetch failed, docker unreachable, unknown name)

Config via env: REPO_RAW_URL (default the public repo on main),
SERVER_HOSTNAME (default home), SERVER_SSH_ALIAS (default server).
EOF
}

REPO_RAW_URL="${REPO_RAW_URL:-https://raw.githubusercontent.com/DiegoHeer/gitops-homelab/main}"
REPO_RAW_URL="${REPO_RAW_URL%/}"
SERVER_HOSTNAME="${SERVER_HOSTNAME:-home}"
SERVER_SSH_ALIAS="${SERVER_SSH_ALIAS:-server}"
if [ -z "${DOCKER_HOST:-}" ] && [ "$(hostname)" != "$SERVER_HOSTNAME" ]; then
    export DOCKER_HOST="ssh://$SERVER_SSH_ALIAS"
fi

CRASH_LOOP_RESTARTS=3
CRASH_LOOP_WINDOW_SECONDS=600

GREEN=$'\033[32m'; RED=$'\033[31m'; YELLOW=$'\033[33m'; BOLD=$'\033[1m'; RESET=$'\033[0m'
STACK_W=20; CONTAINER_W=28; STATUS_W=16

fetch() {
    local url="$REPO_RAW_URL/$1"
    curl --fail --silent --show-error --location "$url" || {
        echo "Error: failed to fetch $url" >&2
        return 2
    }
}

# Emit sorted stack|container lines. Fetches .doco-cd.yml sequentially
# (needed to learn the stack list), then parallel-fetches every compose file
# in one curl call.
discover_containers() {
    local doco_cd_yml stacks stack tmpdir path matches
    doco_cd_yml=$(fetch .doco-cd.yml) || return 2
    stacks=$(echo "$doco_cd_yml" \
        | awk '/^name:[[:space:]]+/ { gsub(/["'\'']/, "", $2); print $2 }')
    [ -n "$stacks" ] || {
        echo "Error: no 'name:' entries in $REPO_RAW_URL/.doco-cd.yml" >&2
        return 2
    }
    stacks+=$'\n'"gitops"

    tmpdir=$(mktemp -d)
    # shellcheck disable=SC2064  # expand tmpdir now, not at trap time
    trap "rm -rf '$tmpdir'" RETURN

    local curl_args=(--fail --silent --show-error --location --parallel)
    while IFS= read -r stack; do
        [ -n "$stack" ] || continue
        if [ "$stack" = "gitops" ]; then
            path="bootstrap/gitops/docker-compose.yaml"
        else
            path="services/$stack/docker-compose.yaml"
        fi
        curl_args+=(-o "$tmpdir/$stack" "$REPO_RAW_URL/$path")
    done <<< "$stacks"

    curl "${curl_args[@]}" || {
        echo "Error: one or more compose files failed to fetch from $REPO_RAW_URL" >&2
        return 2
    }

    while IFS= read -r stack; do
        [ -n "$stack" ] || continue
        matches=$(awk -v stack="$stack" \
            '/^[[:space:]]+container_name:[[:space:]]+/ { gsub(/["'\'']/, "", $2); print stack "|" $2 }' \
            "$tmpdir/$stack")
        if [ -z "$matches" ]; then
            echo "Warning: no container_name entries for stack '$stack'" >&2
        else
            echo "$matches"
        fi
    done <<< "$stacks" | sort -u
}

# Reduce sorted stack|container lines to those matching the requested names.
# Filtering happens here rather than at fetch time because a container name
# cannot be mapped to its stack without parsing every compose file first, and
# the fetch is already a single parallel curl. The batched docker inspect —
# the call that actually costs SSH round trips — shrinks accordingly.
filter_containers() {
    local containers="$1"; shift
    local name matched all_matches=""
    local -a unknown=()

    for name in "$@"; do
        matched=$(echo "$containers" | awk -F'|' -v n="$name" '$1 == n || $2 == n')
        if [ -z "$matched" ]; then
            unknown+=("$name")
        else
            all_matches+="$matched"$'\n'
        fi
    done

    # An unmatched name must be fatal. Filtering it away silently would print
    # an empty table and "0 healthy, 0 problems" — which reads as success.
    if [ "${#unknown[@]}" -gt 0 ]; then
        echo "Error: unknown stack or container: ${unknown[*]}" >&2
        echo "Stacks: $(echo "$containers" | cut -d'|' -f1 | sort -u | tr '\n' ' ')" >&2
        echo "Run without arguments to check everything." >&2
        return 2
    fi

    printf '%s' "$all_matches" | sort -u
}

# One batched docker inspect across every container name. Emit name|status
# lines. Containers absent from docker simply produce no output line — the
# caller maps that to "missing".
classify() {
    local now name state health restart started started_epoch status
    now=$(date -u +%s)
    docker inspect \
        --format '{{.Name}}|{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}|{{.RestartCount}}|{{.State.StartedAt}}' \
        "$@" 2>/dev/null \
    | while IFS='|' read -r name state health restart started; do
        [ -n "$name" ] || continue
        name="${name#/}"
        if [ "$state" != "running" ]; then
            case "$state" in
                exited|stopped|created) status="inactive" ;;
                *)                      status="$state" ;;
            esac
        # Crash-loop takes precedence over health — a container that restarts
        # every few seconds may briefly report healthy. Known false-positive:
        # a long-lived container whose cumulative restart count exceeds the
        # threshold and is manually restarted within the window will misread
        # as crash-loop until the window lapses.
        elif [ "$restart" -gt "$CRASH_LOOP_RESTARTS" ] \
            && started_epoch=$(date -d "$started" +%s 2>/dev/null) \
            && (( now - started_epoch < CRASH_LOOP_WINDOW_SECONDS )); then
            status="crash-loop"
        else
            case "$health" in
                unhealthy|starting) status="$health" ;;
                *)                  status="healthy" ;;
            esac
        fi
        echo "$name|$status"
    done
}

main() {
    local arg
    local -a filters=()
    for arg in "$@"; do
        case "$arg" in
            -h|--help) usage; exit 0 ;;
            -*)        echo "Error: unknown option '$arg'" >&2; usage >&2; exit 2 ;;
            *)         filters+=("$arg") ;;
        esac
    done

    local containers
    containers=$(discover_containers) || exit 2
    [ -n "$containers" ] || {
        echo "No containers discovered from $REPO_RAW_URL" >&2
        exit 2
    }

    if [ "${#filters[@]}" -gt 0 ]; then
        containers=$(filter_containers "$containers" "${filters[@]}") || exit 2
    fi

    local -a names=()
    local stack container status color
    while IFS='|' read -r _ container; do
        [ -n "$container" ] && names+=("$container")
    done <<< "$containers"

    local -A status_by_name=()
    while IFS='|' read -r name status; do
        status_by_name["$name"]="$status"
    done < <(classify "${names[@]}")

    # No rows back from docker inspect used to be read as "daemon unreachable" —
    # a safe inference over the full sweep, but wrong once a filter can narrow
    # the set to containers that are all genuinely absent, which is exactly when
    # you reach for one. Probe the daemon instead of inferring; the extra round
    # trip only happens on this already-degenerate path.
    if [ "${#status_by_name[@]}" -eq 0 ] && [ "${#names[@]}" -gt 0 ] \
        && ! docker info >/dev/null 2>&1; then
        echo "Error: docker daemon not reachable (DOCKER_HOST=${DOCKER_HOST:-<local>})." >&2
        echo "Check connectivity and that docker is running on the target host." >&2
        exit 2
    fi

    local sep
    sep=$(printf "%-${STACK_W}s %-${CONTAINER_W}s %-${STATUS_W}s" \
        "$(printf '─%.0s' $(seq 1 $((STACK_W - 1))))" \
        "$(printf '─%.0s' $(seq 1 $((CONTAINER_W - 1))))" \
        "$(printf '─%.0s' $(seq 1 $((STATUS_W - 1))))")

    printf "${BOLD}%-${STACK_W}s %-${CONTAINER_W}s %-${STATUS_W}s${RESET}\n" "Stack" "Container" "Status"
    echo "$sep"

    local prev_stack="" healthy_count=0 problem_count=0
    while IFS='|' read -r stack container; do
        [ -n "$stack" ] && [ -n "$container" ] || continue
        if [ -n "$prev_stack" ] && [ "$stack" != "$prev_stack" ]; then
            echo "$sep"
        fi
        prev_stack="$stack"
        status="${status_by_name[$container]:-missing}"
        case "$status" in
            healthy)  color="$GREEN" ;;
            starting) color="$YELLOW" ;;
            *)        color="$RED" ;;
        esac
        printf "%-${STACK_W}s %-${CONTAINER_W}s ${color}● %-${STATUS_W}s${RESET}\n" \
            "$stack" "$container" "$status"
        if [ "$status" = "healthy" ]; then
            healthy_count=$((healthy_count + 1))
        else
            problem_count=$((problem_count + 1))
        fi
    done <<< "$containers"

    echo
    local word=problems
    [ "$problem_count" = 1 ] && word=problem
    printf "%d healthy, %d %s\n" "$healthy_count" "$problem_count" "$word"

    [ "$problem_count" -eq 0 ] || exit 1
}

main "$@"
