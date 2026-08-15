# DocoCD Debug Tooling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `scripts/doco.sh` and a thin `Makefile` front door so a DocoCD-managed stack or container can be restarted, stopped, started, or mount-inspected from a laptop with one short command.

**Architecture:** All logic lives in `scripts/doco.sh`, a standalone bash script that talks to the home server via `DOCKER_HOST=ssh://server` and needs no repo clone to run. The `Makefile` is a passthrough with no logic. Names are resolved against live Docker state rather than `.doco-cd.yml`. The script is sourceable so its functions can be unit-tested against a stubbed `docker` binary.

**Tech Stack:** bash 5, GNU Make, Docker CLI over SSH, shellcheck (existing pre-commit hook).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-08-15-dococd-debug-tooling-design.md`. Read it before starting.
- Every shell file starts `#!/usr/bin/env bash` then `set -euo pipefail`, matching `scripts/dockcheck.sh`.
- All shell must pass `shellcheck` — it is an existing pre-commit hook and will block commits.
- Every `source "$DOCO"` in the test suite needs a `# shellcheck source=/dev/null` comment
  directly above it, or shellcheck raises SC1090 ("can't follow non-constant source") and
  the hook fails.
- Commit format is `Category|Action: description` (see `CLAUDE.md`). Use `Config` for tooling and docs, `Infrastructure` for CI.
- YAML is 160-char max line length, `.yaml`/`.yml` per existing file's extension, and must pass `uv run yamllint .`.
- Verbs are exactly: `restart`, `stop`, `start`, `mounts`.
- Config env vars and defaults: `SERVER_HOSTNAME=home`, `SERVER_SSH_ALIAS=server`.
- Exit codes: `0` success, `1` usage/resolution error, `2` docker or ssh failure.
- No confirmation prompts anywhere, including stack-wide `stop`.
- Run `uv run pre-commit run --files <changed files>` before each commit. If `ansible-lint` fails with a missing `.vault_key`, run `ln -sf /home/diego/Projects/gitops-homelab/.vault_key .vault_key` once — it is gitignored and worktree-local.

---

## File Structure

| File | Responsibility |
|------|----------------|
| `scripts/doco.sh` | Create. All logic: arg parsing, name resolution, the four verbs. |
| `scripts/doco_test.sh` | Create. Self-contained test harness + `docker`/`ssh` stubs. No external deps. |
| `Makefile` | Create. Passthrough front door. No logic, ever. |
| `.github/workflows/quality-check.yml` | Modify. Add a `shell` job running shellcheck + the test suite. |
| `docs/adr/0021-ssh-over-dococd-api-for-debug-tooling.md` | Create. Records why the REST API was rejected. |
| `README.md` | Modify. Document the four verbs and the reconciliation caveat. |

---

### Task 1: Script skeleton, test harness, and CI wiring

Establishes the sourceable-script pattern and the stub-based test harness everything else builds on.

**Files:**
- Create: `scripts/doco.sh`
- Create: `scripts/doco_test.sh`
- Modify: `.github/workflows/quality-check.yml`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `usage()` — prints usage block to stdout, returns 0.
  - `die <msg> [code]` — prints `doco: <msg>` to stderr, exits `code` (default 1).
  - `main "$@"` — entry point. Dispatches on `$1`.
  - Globals set by `main`: `DRY_RUN` (`0`/`1`), `FORCE_KIND` (`""`/`stack`/`container`).
  - Colour constants `RED`, `YELLOW`, `BOLD`, `RESET` — empty strings when stdout is not a TTY.
  - Source guard, written as a full `if` block, **not** `[[ … ]] && main "$@"`. The `&&`
    form returns non-zero when the condition is false, which under the sourcing shell's
    `set -e` would kill the test suite the moment it sources the script.
  - Test harness in `scripts/doco_test.sh`: `assert_eq`, `assert_contains`, `assert_exit`, `stub_docker`, `run_test`, plus `STUB_STACKS` / `STUB_CONTAINERS` / `STUB_LOG` conventions.

- [ ] **Step 1: Write the failing test**

Create `scripts/doco_test.sh`:

```bash
#!/usr/bin/env bash
# Self-contained test suite for scripts/doco.sh. No external dependencies:
# a fake `docker` (and later `ssh`) is placed on PATH per test.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOCO="$SCRIPT_DIR/doco.sh"

PASS=0
FAIL=0

# --- assertions -------------------------------------------------------------

assert_eq() {
    local expected="$1" actual="$2" msg="$3"
    if [ "$expected" = "$actual" ]; then
        PASS=$((PASS + 1))
    else
        FAIL=$((FAIL + 1))
        printf '  FAIL: %s\n    expected: %q\n    actual:   %q\n' \
            "$msg" "$expected" "$actual" >&2
    fi
}

assert_contains() {
    local haystack="$1" needle="$2" msg="$3"
    if [[ "$haystack" == *"$needle"* ]]; then
        PASS=$((PASS + 1))
    else
        FAIL=$((FAIL + 1))
        printf '  FAIL: %s\n    expected to contain: %q\n    actual: %q\n' \
            "$msg" "$needle" "$haystack" >&2
    fi
}

# assert_exit <expected-code> <msg> -- <command...>
assert_exit() {
    local expected="$1" msg="$2"
    shift 3  # drop expected, msg, and the literal --
    local actual=0
    "$@" >/dev/null 2>&1 || actual=$?
    assert_eq "$expected" "$actual" "$msg"
}

# --- stubs ------------------------------------------------------------------

# Creates a fake `docker` on PATH driven by two env vars:
#   STUB_STACKS="media:jellyfin,sonarr ai:n8n"   stack -> its containers
#   STUB_CONTAINERS="jellyfin sonarr n8n"        standalone container names
# Mutating calls are appended to $STUB_LOG instead of being executed.
stub_docker() {
    STUB_DIR="$(mktemp -d)"
    STUB_LOG="$STUB_DIR/calls.log"
    : > "$STUB_LOG"
    cat > "$STUB_DIR/docker" <<'STUB'
#!/usr/bin/env bash
args="$*"
case "$args" in
    *label=com.docker.compose.project=*)
        want="${args##*label=com.docker.compose.project=}"
        want="${want%% *}"
        for entry in ${STUB_STACKS:-}; do
            [ "${entry%%:*}" = "$want" ] || continue
            printf '%s\n' "${entry#*:}" | tr ',' '\n'
        done
        ;;
    *name=^*)
        want="${args##*name=^}"
        want="${want%%\$*}"
        for c in ${STUB_CONTAINERS:-}; do
            [ "$c" = "$want" ] && printf '%s\n' "$c"
        done
        ;;
    restart\ *|stop\ *|start\ *)
        printf '%s\n' "$args" >> "$STUB_LOG"
        ;;
esac
exit 0
STUB
    chmod +x "$STUB_DIR/docker"
    PATH="$STUB_DIR:$PATH"
    export PATH STUB_LOG
}

unstub() {
    [ -n "${STUB_DIR:-}" ] && rm -rf "$STUB_DIR"
    STUB_DIR=""
}

run_test() {
    printf '%s\n' "-- $1"
    "$1"
}

# --- tests ------------------------------------------------------------------

test_usage_lists_all_four_verbs() {
    local out
    out="$(bash "$DOCO" 2>&1)" || true
    assert_contains "$out" "restart" "usage mentions restart"
    assert_contains "$out" "stop" "usage mentions stop"
    assert_contains "$out" "start" "usage mentions start"
    assert_contains "$out" "mounts" "usage mentions mounts"
}

test_no_args_exits_1() {
    assert_exit 1 "no args is a usage error" -- bash "$DOCO"
}

test_unknown_verb_exits_1() {
    assert_exit 1 "unknown verb is a usage error" -- bash "$DOCO" frobnicate media
}

test_verb_without_name_exits_1() {
    assert_exit 1 "verb with no name is a usage error" -- bash "$DOCO" restart
}

run_test test_usage_lists_all_four_verbs
run_test test_no_args_exits_1
run_test test_unknown_verb_exits_1
run_test test_verb_without_name_exits_1

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
```

- [ ] **Step 2: Run test to verify it fails**

```bash
chmod +x scripts/doco_test.sh
./scripts/doco_test.sh
```

Expected: FAIL — `scripts/doco.sh` does not exist, so every assertion fails and the suite exits non-zero.

- [ ] **Step 3: Write minimal implementation**

Create `scripts/doco.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

# Debug helper for DocoCD-managed stacks. Restart/stop/start a whole stack or a
# single container, or inspect its mounts, without hand-running docker commands.
# Talks to the home server over DOCKER_HOST=ssh://$SERVER_SSH_ALIAS unless
# already running on the server itself.
#
# DocoCD remains the source of truth for stack *definitions*; this only acts on
# running state.

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

    # Verb dispatch is filled in by later tasks.
    printf 'verb=%s names=%s dry_run=%s force_kind=%s\n' \
        "$verb" "${names[*]}" "$DRY_RUN" "$FORCE_KIND"
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
chmod +x scripts/doco.sh
./scripts/doco_test.sh
```

Expected: PASS — `7 passed, 0 failed` (4 assertions in the usage test, 3 exit-code tests).

Also run: `uv run pre-commit run --files scripts/doco.sh scripts/doco_test.sh`
Expected: `shellcheck` and `check that executables have shebangs` both Passed.

- [ ] **Step 5: Add the CI job**

In `.github/workflows/quality-check.yml`, add a third job after `lint` (keep 2-space indent, stay under 160 chars):

```yaml
  shell:
    name: Shell
    runs-on: ubuntu-latest
    steps:
      - name: Checkout
        uses: actions/checkout@v5

      - name: Run shellcheck
        run: shellcheck scripts/*.sh

      - name: Run doco.sh test suite
        run: ./scripts/doco_test.sh
```

`shellcheck` is preinstalled on `ubuntu-latest` runners. This job needs no vault password, unlike `lint`.

- [ ] **Step 6: Verify the workflow is valid**

```bash
uv run yamllint .github/workflows/quality-check.yml
uv run pre-commit run actionlint --files .github/workflows/quality-check.yml
```
Expected: both pass.

- [ ] **Step 7: Commit**

```bash
git add scripts/doco.sh scripts/doco_test.sh .github/workflows/quality-check.yml
git commit -m "Config|Add: added doco.sh skeleton with test harness and CI job"
```

---

### Task 2: Name resolution

Resolves a bare name to either a stack or a container by asking live Docker.

**Files:**
- Modify: `scripts/doco.sh`
- Modify: `scripts/doco_test.sh`

**Interfaces:**
- Consumes: `die`, `FORCE_KIND` from Task 1.
- Produces:
  - `stack_containers <name>` — echoes newline-separated container names for a compose project; empty if none.
  - `container_exists <name>` — returns 0 if a container with exactly that name exists, else 1.
  - `all_names` — echoes every known stack and container name, one per line, deduped. Used for near-match hints.
  - `resolve_kind <name>` — echoes `stack` or `container`. Calls `die` on ambiguous or unknown. Honours `FORCE_KIND`.
  - `containers_of <kind> <name>` — echoes the container names to act on.

- [ ] **Step 1: Write the failing tests**

Append to the tests section of `scripts/doco_test.sh`, before the `run_test` calls:

```bash
test_resolves_a_stack() {
    stub_docker
    export STUB_STACKS="media:jellyfin,sonarr" STUB_CONTAINERS=""
    local out
    out="$(source "$DOCO"; FORCE_KIND=""; resolve_kind media)"
    assert_eq "stack" "$out" "media resolves to a stack"
    unstub
}

test_resolves_a_container() {
    stub_docker
    export STUB_STACKS="" STUB_CONTAINERS="jellyfin"
    local out
    out="$(source "$DOCO"; FORCE_KIND=""; resolve_kind jellyfin)"
    assert_eq "container" "$out" "jellyfin resolves to a container"
    unstub
}

test_ambiguous_name_fails() {
    stub_docker
    export STUB_STACKS="media:jellyfin" STUB_CONTAINERS="media"
    local out rc=0
    out="$(source "$DOCO"; FORCE_KIND=""; resolve_kind media 2>&1)" || rc=$?
    assert_eq 1 "$rc" "ambiguous name exits 1"
    assert_contains "$out" "ambiguous" "ambiguous name explains itself"
    unstub
}

test_force_kind_breaks_ambiguity() {
    stub_docker
    export STUB_STACKS="media:jellyfin" STUB_CONTAINERS="media"
    local out
    out="$(source "$DOCO"; FORCE_KIND="container"; resolve_kind media)"
    assert_eq "container" "$out" "--container forces the container reading"
    unstub
}

test_unknown_name_suggests_near_match() {
    stub_docker
    export STUB_STACKS="media:jellyfin" STUB_CONTAINERS="jellyfin"
    local out rc=0
    out="$(source "$DOCO"; FORCE_KIND=""; resolve_kind jellyfn 2>&1)" || rc=$?
    assert_eq 1 "$rc" "unknown name exits 1"
    assert_contains "$out" "jellyfin" "unknown name suggests the near match"
    unstub
}

test_containers_of_stack_lists_members() {
    stub_docker
    export STUB_STACKS="media:jellyfin,sonarr" STUB_CONTAINERS=""
    local out
    out="$(source "$DOCO"; containers_of stack media | tr '\n' ' ')"
    assert_eq "jellyfin sonarr " "$out" "stack expands to its containers"
    unstub
}
```

Register them:

```bash
run_test test_resolves_a_stack
run_test test_resolves_a_container
run_test test_ambiguous_name_fails
run_test test_force_kind_breaks_ambiguity
run_test test_unknown_name_suggests_near_match
run_test test_containers_of_stack_lists_members
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./scripts/doco_test.sh`
Expected: FAIL — `resolve_kind: command not found` and `containers_of: command not found`.

- [ ] **Step 3: Write the implementation**

In `scripts/doco.sh`, insert these functions after `usage()` and before `main()`:

```bash
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
```

Note `container_exists` uses `--format '{{.Names}}'` rather than `-q` so the same
docker invocation shape serves both display and existence checks.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./scripts/doco_test.sh`
Expected: PASS — all Task 1 and Task 2 assertions green.

Run: `uv run pre-commit run --files scripts/doco.sh scripts/doco_test.sh`
Expected: shellcheck Passed.

- [ ] **Step 5: Commit**

```bash
git add scripts/doco.sh scripts/doco_test.sh
git commit -m "Config|Add: added stack and container name resolution to doco.sh"
```

---

### Task 3: Lifecycle verbs and `--dry-run`

**Files:**
- Modify: `scripts/doco.sh`
- Modify: `scripts/doco_test.sh`

**Interfaces:**
- Consumes: `resolve_kind`, `containers_of`, `die`, `DRY_RUN` from Tasks 1-2.
- Produces:
  - `do_lifecycle <verb> <name>` — resolves, prints the resolution line, then runs one batched `docker <verb>` over all containers. Honours `DRY_RUN`.
  - Resolution line format: `<name> → <kind>, <n> containers: <c1>, <c2>, …`
  - Result line format: `<past-tense-verb> <n>/<n>`

- [ ] **Step 1: Write the failing tests**

Append to `scripts/doco_test.sh`:

```bash
test_restart_stack_echoes_resolution() {
    stub_docker
    export STUB_STACKS="media:jellyfin,sonarr" STUB_CONTAINERS=""
    local out
    out="$(bash "$DOCO" restart media 2>&1)"
    assert_contains "$out" "media" "resolution line names the target"
    assert_contains "$out" "stack" "resolution line states the kind"
    assert_contains "$out" "jellyfin" "resolution line lists members"
    unstub
}

test_restart_stack_batches_one_docker_call() {
    stub_docker
    export STUB_STACKS="media:jellyfin,sonarr" STUB_CONTAINERS=""
    bash "$DOCO" restart media >/dev/null 2>&1
    local calls
    calls="$(wc -l < "$STUB_LOG")"
    assert_eq 1 "$calls" "one batched docker call, not one per container"
    assert_contains "$(cat "$STUB_LOG")" "restart jellyfin sonarr" "both containers in one call"
    unstub
}

test_stop_single_container() {
    stub_docker
    export STUB_STACKS="" STUB_CONTAINERS="jellyfin"
    bash "$DOCO" stop jellyfin >/dev/null 2>&1
    assert_contains "$(cat "$STUB_LOG")" "stop jellyfin" "container stop issued"
    unstub
}

test_dry_run_changes_nothing() {
    stub_docker
    export STUB_STACKS="media:jellyfin,sonarr" STUB_CONTAINERS=""
    local out
    out="$(bash "$DOCO" restart media --dry-run 2>&1)"
    assert_eq "" "$(cat "$STUB_LOG")" "dry-run issues no docker mutation"
    assert_contains "$out" "dry-run" "dry-run says so"
    unstub
}

test_unknown_name_exits_1() {
    stub_docker
    export STUB_STACKS="media:jellyfin" STUB_CONTAINERS="jellyfin"
    assert_exit 1 "unknown name exits 1" -- bash "$DOCO" restart nosuchthing
    unstub
}
```

Register:

```bash
run_test test_restart_stack_echoes_resolution
run_test test_restart_stack_batches_one_docker_call
run_test test_stop_single_container
run_test test_dry_run_changes_nothing
run_test test_unknown_name_exits_1
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./scripts/doco_test.sh`
Expected: FAIL — `main` still prints its Task 1 placeholder line and never calls docker, so `STUB_LOG` is empty and the resolution assertions miss.

- [ ] **Step 3: Write the implementation**

Add to `scripts/doco.sh` after `containers_of`:

```bash
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
```

Then replace the placeholder line at the end of `main()`:

```bash
    local name
    for name in "${names[@]}"; do
        case "$verb" in
            restart|stop|start) do_lifecycle "$verb" "$name" ;;
            mounts) die "mounts is not implemented yet" ;;
        esac
    done
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./scripts/doco_test.sh`
Expected: PASS.

Run: `uv run pre-commit run --files scripts/doco.sh scripts/doco_test.sh`
Expected: shellcheck Passed.

- [ ] **Step 5: Verify against the real server**

```bash
./scripts/doco.sh restart doco-cd --dry-run
```
Expected: resolves `doco-cd` to a container and reports it would restart 1 container, without restarting anything. This is the first real-server check — if `DOCKER_HOST` handling is wrong, it surfaces here.

- [ ] **Step 6: Commit**

```bash
git add scripts/doco.sh scripts/doco_test.sh
git commit -m "Config|Add: added restart, stop and start verbs to doco.sh"
```

---

### Task 4: The `mounts` verb

**Files:**
- Modify: `scripts/doco.sh`
- Modify: `scripts/doco_test.sh`

**Interfaces:**
- Consumes: `resolve_kind`, `containers_of`, `die` from Task 2.
- Produces:
  - `probe_paths <path>...` — echoes one status line per input path, in order: `missing`, `empty`, or `ok`. Runs remotely over ssh when `DOCKER_HOST` is set, locally otherwise. Exactly one round trip.
  - `do_mounts <name>` — resolves, then prints a mount table per container.
  - Table row format: `  <type>  <source> → <target>  <mode>` with ` ⚠ empty` or ` ⚠ missing` appended for flagged bind sources.

- [ ] **Step 1: Write the failing tests**

Append to `scripts/doco_test.sh`. This adds an `inspect` branch to the docker stub, so update `stub_docker`'s case statement by inserting this branch **before** the `restart\ *|stop\ *|start\ *)` branch:

```bash
    inspect\ *)
        printf '%s\n' "${STUB_MOUNTS:-}"
        ;;
```

Then add the tests:

```bash
test_mounts_lists_rows() {
    stub_docker
    export STUB_STACKS="" STUB_CONTAINERS="jellyfin"
    export STUB_MOUNTS=$'bind\t/srv/media\t/data\trw\nvolume\tjf_cache\t/cache\trw'
    local out
    out="$(PROBE_OVERRIDE="ok" bash "$DOCO" mounts jellyfin 2>&1)"
    assert_contains "$out" "/srv/media" "bind source shown"
    assert_contains "$out" "/data" "bind target shown"
    assert_contains "$out" "jf_cache" "named volume shown"
    unstub
}

test_mounts_flags_empty_bind_source() {
    stub_docker
    export STUB_STACKS="" STUB_CONTAINERS="jellyfin"
    export STUB_MOUNTS=$'bind\t/srv/media\t/data\trw'
    local out
    out="$(PROBE_OVERRIDE="empty" bash "$DOCO" mounts jellyfin 2>&1)"
    assert_contains "$out" "empty" "empty bind source is flagged"
    unstub
}

test_mounts_flags_missing_bind_source() {
    stub_docker
    export STUB_STACKS="" STUB_CONTAINERS="jellyfin"
    export STUB_MOUNTS=$'bind\t/srv/nope\t/data\trw'
    local out
    out="$(PROBE_OVERRIDE="missing" bash "$DOCO" mounts jellyfin 2>&1)"
    assert_contains "$out" "missing" "missing bind source is flagged"
    unstub
}

test_probe_paths_classifies_local_dirs() {
    local tmp empty_dir full_dir out
    tmp="$(mktemp -d)"
    empty_dir="$tmp/empty"; mkdir -p "$empty_dir"
    full_dir="$tmp/full"; mkdir -p "$full_dir"; touch "$full_dir/f"
    # doco.sh auto-exports DOCKER_HOST=ssh://... unless it believes it is already
    # on the server. Pin SERVER_HOSTNAME to this box so probe_paths stays local;
    # otherwise this test would silently try to ssh to the real server.
    out="$(
        unset DOCKER_HOST
        SERVER_HOSTNAME="$(hostname)"
        # shellcheck source=/dev/null
        source "$DOCO"
        probe_paths "$tmp/nope" "$empty_dir" "$full_dir" | tr '\n' ' '
    )"
    assert_eq "missing empty ok " "$out" "probe_paths classifies all three cases"
    rm -rf "$tmp"
}
```

Register:

```bash
run_test test_mounts_lists_rows
run_test test_mounts_flags_empty_bind_source
run_test test_mounts_flags_missing_bind_source
run_test test_probe_paths_classifies_local_dirs
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./scripts/doco_test.sh`
Expected: FAIL — `mounts is not implemented yet` from Task 3's stub, and `probe_paths: command not found`.

- [ ] **Step 3: Write the implementation**

Add to `scripts/doco.sh` after `do_lifecycle`. The probe body is a single string so
the identical logic runs locally and remotely:

```bash
# Classify each path as missing / empty / ok, one line per input, in order.
# One round trip: paths go in on stdin.
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

    if [ -n "${DOCKER_HOST:-}" ]; then
        printf '%s\n' "$@" | ssh "$SERVER_SSH_ALIAS" "$PROBE_BODY" || die "ssh probe failed" 2
    else
        printf '%s\n' "$@" | bash -c "$PROBE_BODY"
    fi
}

do_mounts() {
    local name="$1" kind containers c
    kind="$(resolve_kind "$name")"
    mapfile -t containers < <(containers_of "$kind" "$name")

    printf '%s → %s, %d container(s)\n' "$name" "$kind" "${#containers[@]}"

    for c in "${containers[@]}"; do
        local rows=() types=() sources=() targets=() modes=() binds=() statuses=()
        local t s d m
        mapfile -t rows < <(docker inspect --format \
            '{{range .Mounts}}{{.Type}}	{{.Source}}	{{.Destination}}	{{if .RW}}rw{{else}}ro{{end}}
{{end}}' "$c" 2>/dev/null | grep -v '^$' || true)

        if [ "${#rows[@]}" -eq 0 ]; then
            printf '  %s: no mounts\n' "$c"
            continue
        fi

        local r
        for r in "${rows[@]}"; do
            IFS=$'\t' read -r t s d m <<< "$r"
            types+=("$t"); sources+=("$s"); targets+=("$d"); modes+=("$m")
            [ "$t" = "bind" ] && binds+=("$s")
        done

        if [ "${#binds[@]}" -gt 0 ]; then
            mapfile -t statuses < <(probe_paths "${binds[@]}")
        fi

        printf '  %s\n' "$c"
        local i bi=0 flag
        for i in "${!types[@]}"; do
            flag=""
            if [ "${types[$i]}" = "bind" ]; then
                case "${statuses[$bi]:-ok}" in
                    missing) flag="  ${YELLOW}⚠ missing${RESET}" ;;
                    empty) flag="  ${YELLOW}⚠ empty${RESET}" ;;
                esac
                bi=$((bi + 1))
            fi
            printf '    %-7s %s → %s  %s%s\n' \
                "${types[$i]}" "${sources[$i]}" "${targets[$i]}" "${modes[$i]}" "$flag"
        done
    done
}
```

Then replace the `mounts) die ...` line in `main()`:

```bash
            mounts) do_mounts "$name" ;;
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./scripts/doco_test.sh`
Expected: PASS.

Run: `uv run pre-commit run --files scripts/doco.sh scripts/doco_test.sh`
Expected: shellcheck Passed. If shellcheck flags the literal tabs inside the
`--format` string, keep them — Go templates need real tabs — and confirm the
warning is not an error.

- [ ] **Step 5: Verify against the real server**

```bash
./scripts/doco.sh mounts doco-cd
```
Expected: lists `/var/run/docker.sock` (bind) and `doco-cd-data` (volume), with
the socket reported `ok`. This exercises the real ssh probe path.

- [ ] **Step 6: Commit**

```bash
git add scripts/doco.sh scripts/doco_test.sh
git commit -m "Config|Add: added mounts verb flagging missing and empty bind sources"
```

---

### Task 5: The Makefile front door

Mechanism already validated against GNU Make; see the spec's Component: Makefile section.

**Files:**
- Create: `Makefile`

**Interfaces:**
- Consumes: `scripts/doco.sh` from Tasks 1-4.
- Produces: `make {restart,stop,start,mounts} <name>...`, `make help`.

- [ ] **Step 1: Write the Makefile**

```make
VERBS := restart stop start mounts
FIRST := $(firstword $(MAKECMDGOALS))

# Make needs a catch-all rule to accept bare names as positional arguments,
# but a bare catch-all would turn any typo into a silent no-op. This parse-time
# guard rejects an unknown first goal before the catch-all can swallow it.
ifneq ($(FIRST),)
ifeq ($(filter $(FIRST),$(VERBS) help),)
$(error unknown command '$(FIRST)' - try: make help)
endif
endif

ARGS := $(filter-out $(VERBS),$(MAKECMDGOALS))

.PHONY: help $(VERBS)

help:
	@echo "usage: make {restart|stop|start|mounts} <stack-or-container>"
	@echo ""
	@echo "  make restart media          restart a whole stack"
	@echo "  make restart jellyfin       restart a single container"
	@echo "  make mounts immich_server   list mounts, flag empty bind sources"
	@echo ""
	@echo "See ./scripts/doco.sh --help for options (--dry-run, --stack, --container)."

$(VERBS):
	@./scripts/doco.sh $@ $(ARGS)

# Absorbs bare names so Make does not try to build them as targets.
%:
	@:
```

- [ ] **Step 2: Verify each invocation shape**

```bash
make help
make
make restart doco-cd --dry-run
make mounts doco-cd
make mount doco-cd
```

Expected, in order: usage; usage; dry-run resolution for one container; the mount
table; and `*** unknown command 'mount' - try: make help.  Stop.` with exit 2.

- [ ] **Step 3: Commit**

```bash
git add Makefile
git commit -m "Config|Add: added Makefile front door for doco.sh"
```

---

### Task 6: ADR 0021 and README section

**Files:**
- Create: `docs/adr/0021-ssh-over-dococd-api-for-debug-tooling.md`
- Modify: `README.md`

**Interfaces:**
- Consumes: nothing.
- Produces: documentation only.

- [ ] **Step 1: Write the ADR**

Follow `docs/adr/template.md` exactly. Create `docs/adr/0021-ssh-over-dococd-api-for-debug-tooling.md`:

```markdown
# 0021 — SSH Over The DocoCD API For Debug Tooling

- **Status**: Accepted
- **Date**: 2026-08-15
- **Deciders**: Diego

## Context

DocoCD has no UI, so debugging a container meant pushing a commit to `main` or
hand-running docker commands against the server. DocoCD v0.103.0 does expose a
REST API (`/v1/api/project/{name}/{start,stop,restart}`, and `POST /v1/api/poll/run`,
which accepts inline deploy configs and can therefore force-recreate a single
stack without a commit) — genuinely more capable than plain docker.

## Decision

Drive debug tooling over `DOCKER_HOST=ssh://server` and leave the DocoCD REST API
disabled. Redeploys stay with the existing `Reconcile DocoCD` workflow.

## Consequences

- `+` No server-side change: `API_SECRET` stays unset, so `/v1/api` stays unrouted.
- `+` No new internet-facing surface. `doco-cd.dynabase.nl` is a bare single-label
  host and therefore public via the ADR 0019 tunnel wildcard; it must stay public
  for GitHub webhooks. Enabling the API there would expose
  `DELETE /v1/api/project/{name}?volumes=true` behind a static header that
  `internal/restapi/api.go` compares with `==` rather than a constant-time
  compare, with no rate limiting.
- `+` Works from any host with SSH access and no repo clone.
- `−` No commit-free single-stack `force_recreate`. A full reconcile via the
  GitHub Actions workflow is the only redeploy path.
- `−` Tooling depends on SSH access to the server, so it is unusable from CI.

## Evidence

- `docs/superpowers/specs/2026-08-15-dococd-debug-tooling-design.md` — full analysis
- `scripts/doco.sh`, `Makefile` — the implementation
- ADR 0019 — establishes that bare `*.dynabase.nl` hosts are internet-facing
```

- [ ] **Step 2: Add the README section**

Add to `README.md` beside the existing command documentation:

```markdown
### Debugging a stack or container

`scripts/doco.sh` (and the `Makefile` wrapping it) drives the home server over
`DOCKER_HOST=ssh://server`. No DocoCD API needed — see ADR 0021.

```bash
make restart media          # restart a whole stack
make restart jellyfin       # restart a single container
make stop media ai          # several at once
make mounts immich_server   # list mounts, flagging missing/empty bind sources
```

Names resolve automatically against live Docker state; pass `--stack` or
`--container` if a name is both. `--dry-run` resolves without changing anything.

`mounts` flags bind sources that are **empty**, not just missing: Compose defaults
to `create_host_path: true`, so a typo'd host path is silently created as an empty
directory and the container starts up looking healthy with no data.

**Caveat:** DocoCD reconciliation is enabled by default with `events: ["unhealthy"]`.
A container that fails its healthcheck while you debug it will be auto-restarted up
to 5 times per 300 seconds. `stop` is not a reconciliation trigger and will not be
reverted. Set `reconciliation: false` on the stack in `.doco-cd.yml` to debug in peace.
```

- [ ] **Step 3: Verify**

```bash
uv run pre-commit run --files README.md docs/adr/0021-ssh-over-dococd-api-for-debug-tooling.md
```
Expected: all hooks pass.

Confirm the ADR renders and the number is unused: `ls docs/adr/ | grep 0021`
Expected: only the new file.

- [ ] **Step 4: Commit**

```bash
git add README.md docs/adr/0021-ssh-over-dococd-api-for-debug-tooling.md
git commit -m "Config|Add: added ADR 0021 and README docs for doco.sh debug tooling"
```

---

## Final Verification

- [ ] `./scripts/doco_test.sh` — all tests pass
- [ ] `uv run pre-commit run --all-files` — passes (link `.vault_key` first if ansible-lint complains)
- [ ] `make help`, `make mounts doco-cd`, `make restart doco-cd --dry-run` all behave as documented
- [ ] `make mount doco-cd` errors rather than silently no-oping
- [ ] Spec's four verbs, `--dry-run`, `--stack`/`--container`, and the exit-code table are all implemented
