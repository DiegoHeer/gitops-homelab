# DocoCD Debug Tooling — Design

- **Date**: 2026-08-15
- **Status**: Approved, not yet implemented
- **Author**: Diego (with Claude)

## Context

Every Docker Compose stack on the home server is reconciled by DocoCD, which has
no UI. Debugging a misbehaving container therefore means either pushing a commit
to `main` (heavyweight, and pollutes history with debug churn) or hand-running a
string of `docker` commands against the server.

The immediate trigger is a volume-mount problem: containers are not seeing the
data they should. The debug loop needs to be *bounce a thing and look at its
mounts*, repeatedly, with low friction.

Two transports were considered. The DocoCD REST API was rejected — see
[Rejected alternatives](#rejected-alternatives). This design uses
`DOCKER_HOST=ssh://server`, which needs no server-side change and adds no
attack surface.

## Goals

- Restart, stop, and start a **stack** or a **single container** by name, from a
  laptop, without remembering container names or docker flags.
- Inspect the resolved mounts of a stack or container, and surface the failure
  mode that silently breaks bind mounts.
- Match the conventions already set by `scripts/dockcheck.sh`.

## Non-goals

- Triggering a DocoCD redeploy. That stays with the existing
  `Reconcile DocoCD` GitHub Actions workflow (`workflow_dispatch`), which posts
  a signed synthetic push and reconciles every stack from `main`.
- Log tailing and stack health overview. `docker logs` is already ergonomic, and
  `scripts/dockcheck.sh` already covers health and crash-loop detection.
- Any mutation of stack *definitions*. This tool only acts on running state;
  git remains the source of truth.

## Architecture

Two files, each usable independently:

| File | Responsibility |
|------|----------------|
| `scripts/doco.sh` | All logic. Standalone bash, no repo clone required. |
| `Makefile` | Thin front door. Passthrough only, no logic. |

```
$ make restart media          ≡    $ ./scripts/doco.sh restart media
$ make mounts jellyfin        ≡    $ ./scripts/doco.sh mounts jellyfin
```

The Makefile exists purely for discoverability (`make help`, `make` with no
args). It must never accumulate logic; anything worth doing belongs in the
script, so that the script stays usable on a host with no checkout.

## Component: `scripts/doco.sh`

### Invocation

```
doco.sh <verb> <name>... [--dry-run] [--stack|--container]

verbs:  restart | stop | start | mounts
name:   a stack name or a container name (resolved automatically)
```

Multiple names are accepted and processed in order.

`--stack` / `--container` are mutually exclusive and force the interpretation of
every name in the invocation, bypassing auto-resolution. They exist solely to
break the ambiguous case below; they are not needed in normal use.

### Configuration

Environment variables with defaults, mirroring `scripts/dockcheck.sh`:

| Variable | Default | Purpose |
|----------|---------|---------|
| `SERVER_HOSTNAME` | `home` | If `hostname` matches, act locally. |
| `SERVER_SSH_ALIAS` | `server` | SSH alias used to reach the Docker host. |
| `DOCKER_HOST` | *(derived)* | Set to `ssh://$SERVER_SSH_ALIAS` unless already set or running on the server. |

Header boilerplate is `#!/usr/bin/env bash` + `set -euo pipefail`, as in
`dockcheck.sh`.

### Name resolution

For each name, ask Docker — not `.doco-cd.yml`:

```sh
docker ps -aq --filter label=com.docker.compose.project=<name>   # stack?
docker ps -aq --filter name=^<name>$                             # container?
```

`-a` is required: a container that failed to start because of a bad mount is in
`created`/`exited` state and would be invisible to a bare `docker ps`.

Resolution outcomes:

- **Stack only** → operate on all its containers.
- **Container only** → operate on that container.
- **Both** → error. Disambiguate with `--stack` / `--container`.
- **Neither** → error, listing near-matches from the union of known stack and
  container names.

Rationale for querying live state rather than parsing `.doco-cd.yml`: the script
works standalone from any host with no clone (the `dockcheck.sh` design goal),
one mechanism covers both stacks and containers, and live state is what actually
matters while debugging — including stacks present on the box but absent from the
registry.

### Verbs: `restart`, `stop`, `start`

Resolve, echo the resolution, then act. **No confirmation prompt** — including
for stacks. The echo is the safety mechanism:

```
$ make stop media
media → stack, 12 containers: jellyfin, sonarr, radarr, prowlarr, …
stopped 12/12
```

Stack operations issue one `docker <verb>` with all container IDs rather than
looping, so partial failures are reported as a count.

Interaction with DocoCD reconciliation: reconciliation is enabled by default
with `events: ["unhealthy"]` only. `stop` is therefore *not* a reconciliation
trigger and will not be reverted. A container that flaps unhealthy while you
debug it, however, will be auto-restarted up to `restart_limit: 5` times per
`restart_window: 300` seconds. This is documented in the README section rather
than warned about at runtime, since it cannot be detected reliably from the
client side.

### Verb: `mounts`

Read-only. For every resolved container, print each mount as
`type  source → target  mode`, sourced from `docker inspect`.

Beyond listing, it flags the failure mode that motivated this tool. Compose
defaults to `create_host_path: true` for bind mounts, so a typo'd or
not-yet-provisioned host path does **not** error — dockerd silently creates an
empty directory and the container starts up looking healthy while its data is
missing. The check therefore flags bind sources that are **missing or empty**,
not merely missing:

```
$ make mounts immich_server
immich_server → container, 3 mounts
  bind    /home/diego/services_data/photos/immich/upload → /usr/src/app/upload   rw
  bind    /home/diego/services_data/photos/immich/thumbs → /usr/src/app/thumbs   rw   ⚠ empty
  volume  immich_model_cache                             → /cache               rw
```

Because `DOCKER_HOST=ssh://` gives paths as strings but cannot stat them, the
existence/emptiness check runs over `ssh "$SERVER_SSH_ALIAS"` when remote and
locally when on the box. All paths for an invocation are batched into a **single**
SSH call to avoid N round-trips.

`⚠ empty` is a hint, not a verdict — a legitimately empty mount is possible.

### Output and exit codes

Colour constants and column widths follow `dockcheck.sh`. Colour is suppressed
when stdout is not a TTY.

| Code | Meaning |
|------|---------|
| `0` | Success |
| `1` | Usage error, or a name that could not be resolved / was ambiguous |
| `2` | Docker or SSH failure |

### `--dry-run`

Resolves names and prints what would be acted on, without mutating anything.
This is the seam that makes resolution logic testable without a live server, and
doubles as a safety check before a stack-wide operation.

## Component: `Makefile`

Mechanism validated in a sandbox against GNU Make before being written into this
design:

```make
VERBS := restart stop start mounts
FIRST := $(firstword $(MAKECMDGOALS))

ifneq ($(FIRST),)
ifeq ($(filter $(FIRST),$(VERBS) help),)
$(error unknown command '$(FIRST)' - try: make help)
endif
endif

ARGS := $(filter-out $(VERBS),$(MAKECMDGOALS))

.PHONY: help $(VERBS)

help:
	@echo "usage: make {restart|stop|start|mounts} <stack-or-container>"

$(VERBS):
	@./scripts/doco.sh $@ $(ARGS)

%:
	@:
```

Make cannot take positional arguments without a catch-all `%:` rule, and a bare
catch-all normally makes *any* typo a silent no-op. The parse-time `$(error …)`
guard on `MAKECMDGOALS` restores that safety. Verified behaviour:

| Invocation | Result |
|-----------|--------|
| `make restart media` | `verb=restart names=media` |
| `make restart jellyfin` | `verb=restart names=jellyfin` |
| `make stop media ai` | `verb=stop names=media ai` |
| `make mounts jellyfin` | `verb=mounts names=jellyfin` |
| `make mounts media ai` | `verb=mounts names=media ai` |
| `make mount jellyfin` | `*** unknown command 'mount'` — exit 2 |
| `make restrat media` | `*** unknown command 'restrat'` — exit 2 |
| `make` / `make help` | usage |

The `mount` / `mounts` near-miss is the most likely typo of the four verbs, and
is caught rather than silently no-oped.

### Known edge

A name matching an existing path in the repo root (e.g. `docs`) makes Make append
a cosmetic `make: 'docs' is up to date.` line. The script still runs correctly.
None of the 14 stack names collide with a repo-root entry.

A `%: FORCE` catch-all was tried to suppress this and **rejected** — `FORCE`
matches its own pattern rule, producing a `Circular FORCE <- FORCE dependency
dropped.` warning on *every* invocation, which is worse than the rare cosmetic
line.

## Testing

- `shellcheck` already runs on `scripts/*.sh` via the existing pre-commit hook —
  no new tooling.
- `--dry-run` exercises name resolution (the only branch-heavy logic) without a
  server.
- Molecule is not applicable; it is scoped to Ansible roles.

## Documentation

- A short section in `README.md` next to the existing command docs, covering the
  four verbs and the reconciliation caveat.
- **ADR 0021** (next free number; 0020 is `cloudflare-dns-companion`). Required
  by `CLAUDE.md` on two counts: it rejects a load-bearing alternative (the DocoCD
  REST API) and introduces a task-runner convention to a repo that has none. The
  ADR is arguably the more durable artefact here — the "why not the API" record
  outlives the script.

## Rejected alternatives

**DocoCD REST API.** v0.103.0 exposes `/v1/api/project/{name}/{start,stop,restart}`,
`DELETE /v1/api/project/{name}`, and `POST /v1/api/poll/run` (which accepts inline
deploy configs overriding `.doco-cd.yml`, enabling a single-stack `force_recreate`
redeploy without a commit). Genuinely more capable than SSH.

Rejected because the cost is disproportionate for a debug tool:

- It is disabled unless `API_SECRET` is set, and `gitops/` is Ansible-managed
  (absent from `.doco-cd.yml` to avoid self-deploy recursion), so enabling it is
  a vault change plus a playbook run, not a git push.
- `doco-cd.dynabase.nl` is a bare single-label hostname and therefore matches the
  `*.dynabase.nl` tunnel wildcard per ADR 0019 — it is internet-facing, confirmed
  by public DNS resolving to Cloudflare edge. It must stay public for GitHub
  webhooks.
- `internal/restapi/api.go` compares the key with `==`, not
  `subtle.ConstantTimeCompare`, and there is no rate limiting on the API path.
  Exposing a `DELETE …?volumes=true` endpoint on that footing would first require
  scoping the public Traefik router to `PathPrefix(/v1/webhook)`.

Revisit if a need arises that SSH genuinely cannot serve — a commit-free
single-stack `force_recreate` being the obvious candidate.

**Makefile-only, with generated per-stack targets.** Generating
`restart-<stack>` targets from `.doco-cd.yml` would give free `make <TAB>`
completion for all 14 stacks. Rejected because it yields two inconsistent
grammars (stacks as target suffixes, containers as variables), containers can
never be enumerated statically so the half you type most gets no completion, and
all logic would then live in Make — unusable from a host without a checkout.

**Live-enumerated Make targets for containers too.** Would complete everything,
but costs an SSH round-trip on *every* `make` invocation including `make help`
and every tab press, and breaks entirely when off-network.

## Open items

None. Scope is fixed at four verbs; log tailing and health overview are
explicitly out (see Non-goals).
