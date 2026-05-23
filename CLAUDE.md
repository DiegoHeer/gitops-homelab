# CLAUDE.md

Project overview, architecture, deploy flow, and secret management → **[README.md](README.md)**.

This file holds only the conventions Claude needs in-session.

## Common Commands

```bash
# Dependencies
uv sync
uv run ansible-galaxy install -r requirements.yml

# Run a playbook (host bootstrap)
uv run ansible-playbook playbooks/update_home_server.yml

# Molecule (one scenario per role)
uv run molecule test -s <role>        # full sequence
uv run molecule converge -s <role>    # apply only
uv run molecule login -s <role>       # shell into test container
uv run molecule destroy -s <role>     # teardown

# SOPS (edit encrypted per-stack env)
sops services/<category>/secrets.enc.env

# Linting
uv run yamllint .
uv run ansible-lint
```

## Git commit format

`Category|Action: description`

- **Categories**: `Services`, `Ansible`, `Infrastructure`, `Config`
- **Actions**: `Add`, `Refactor`, `Remove`, `Fix`, `Update`, `Migrate`

Group commits by change type, not by file. Examples:

```
Services|Add: included grimmory for book management
Services|Fix: added missing restart policies to mosquitto, cloudflare_tunnel, tandoor
Ansible|Refactor: renamed services role to docker_host
Config|Update: updated multiple services to latest versions
```

## Editing conventions

- YAML: 160-char line limit (yamllint); `.yaml` extension, not `.yml`.
- Ansible variable naming: `role_function_detail`; vault vars prefixed `vault_`.
- Docker Compose: container names snake_case matching the service key; all services on external `home_server_network`; healthcheck + `restart: unless-stopped` on every service.
- Runtime state → absolute bind mounts under `/home/diego/services_data/<category>/<service>/`. Static config → relative bind mounts from the compose file's dir.
- Put **final container env var names** directly into `secrets.enc.env`. Compose's `${VAR}` interpolation does not read `env_file` contents.
- Per-service split `.enc.env` files are allowed only when two services in the same stack need the same env var name with different values (see `services/ai/n8n.enc.env`).
- Never commit plaintext `.env` or vault passwords.

## Architectural Decision Records

Load-bearing decisions live under [`docs/adr/`](docs/adr/). Any PR that rejects an alternative, replaces a load-bearing tool, or changes how a subsystem works (GitOps model, secrets, orchestrator, network topology, backup strategy, commit convention, etc.) **must** include a new ADR in the same PR.

- Template: [`docs/adr/template.md`](docs/adr/template.md) — Nygard format (Context / Decision / Consequences / Evidence)
- Procedure: [`docs/adr/README.md`](docs/adr/README.md) — "Adding a new ADR"
- Once `Accepted`, an ADR is never edited — supersede it with a new ADR (status `Superseded by NNNN`).
