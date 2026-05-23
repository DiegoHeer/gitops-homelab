# GitOps Homelab

[![Quality Check](https://github.com/DiegoHeer/gitops-homelab/actions/workflows/quality-check.yml/badge.svg)](https://github.com/DiegoHeer/gitops-homelab/actions/workflows/quality-check.yml)
[![Renovate enabled](https://img.shields.io/badge/renovate-enabled-brightgreen.svg)](https://developer.mend.io/github/DiegoHeer/gitops-homelab)
[![Last commit](https://img.shields.io/github/last-commit/DiegoHeer/gitops-homelab/main)](https://github.com/DiegoHeer/gitops-homelab/commits/main)

Personal homelab deployed GitOps-style on Ubuntu. Three pillars:

- **Ansible playbooks** for one-time host bootstrap (Docker, shared network, SOPS age key, cloudflared tunnel token, the DocoCD stack itself)
- **Docker Compose services** deployed **GitOps-style** by [DocoCD](https://github.com/kimdre/doco-cd) — a `git push` to `main` is the deploy
- **Restic backups** implementing the 3-2-1 backup rule (managed by the Ansible restore role)

## Documentation

- **[docs/getting-started.md](docs/getting-started.md)** — Adopting this repo for your own home server: what to fork, what to replace, what to generate.
- **[docs/adr/](docs/adr/)** — Architectural Decision Records. Why DocoCD, why SOPS+age, why no Kubernetes. The rationale record.
- **[docs/runbooks/](docs/runbooks/)** — Operational runbooks (written reactively).

## Project Structure

```
.doco-cd.yml        # Registry of DocoCD-managed stacks (one YAML doc per stack)
.sops.yaml          # age recipient for services/**/*.enc.env
bootstrap/gitops/   # DocoCD itself — Ansible-managed (can't redeploy itself)
services/           # Docker Compose stacks (12 DocoCD-managed categories)
roles/              # Ansible roles: system, projects, docker_host, restore
playbooks/          # Playbooks: update_home_server.yml, restore_home_server.yml
molecule/           # Ansible role testing (one scenario per role)
.github/            # CI workflows and shared composite actions
```

### Services overview

| Category | Services |
|---|---|
| **Home Assistant** | Home Assistant, Mosquitto (MQTT), OpenThread Border Router, Matter Server, Doorbell Samba |
| **Media** | Jellyfin, Seerr, Gluetun (VPN), qBittorrent, Prowlarr, Sonarr, Radarr, SABnzbd, Navidrome, Audiobookshelf, Grimmory + MariaDB |
| **Networking** | Traefik, Cloudflare Tunnel, Traefik–Pi-hole DNS sync |
| **Monitoring** | Beszel (+ agent), Dozzle, Portracker |
| **Storage** | Filebrowser, Nextcloud + MariaDB, Rustfs |
| **Photos** | Immich (server + ML), Redis, PostgreSQL (VectorChord) |
| **Tools** | IT-Tools, BentoPDF, Grist, Docuseal, Changedetection, Tandoor + PostgreSQL |
| **Dashboards** | Homarr, Glance, Dashdot, Homepage |
| **AI** | n8n + PostgreSQL |
| **Security** | Frigate (NVR) |
| **Games** | RomM + MariaDB |
| **Backups** | Zerobyte, Databasus |


The DocoCD container itself lives at `bootstrap/gitops/` (not under `services/`) because DocoCD can't redeploy its own container — that stack is Ansible-managed.

---

## How deploys work

1. Edit a `services/<category>/docker-compose.yaml` (or its SOPS-encrypted `secrets.enc.env`).
2. Commit + push to `main`.
3. GitHub webhook → DocoCD on the home server.
4. DocoCD clones the repo, decrypts any `*.enc.env` files with the host's age key, and runs `docker compose up -d` for each stack registered in `.doco-cd.yml`.

Only pushes to `main` trigger a reconcile — every stack in `.doco-cd.yml` pins `webhook_filter: "^refs/heads/main$"`. Pushes to feature or Renovate branches are ignored.

Runtime state (SQLite DBs, uploaded files, app config) lives at absolute host paths under `/home/diego/services_data/<category>/<service>/`, so stacks can be torn down and recreated without touching user data. Compose bind mounts reference those absolute paths.

### Adding a new stack

1. Create `services/<new>/docker-compose.yaml`. Use absolute paths under `/home/diego/services_data/<new>/` for runtime state.
2. Add secrets as `services/<new>/secrets.enc.env` (see SOPS section below).
3. Append to `.doco-cd.yml`:
   ```yaml
   ---
   name: <new>
   working_dir: services/<new>
   webhook_filter: "^refs/heads/main$"
   ```
4. Commit + push. DocoCD reconciles.

### Removing a stack

Delete the `---` block from `.doco-cd.yml` and the `services/<name>/` tree. Commit + push. DocoCD stops and removes the containers. Named volumes and absolute-path bind mounts are preserved by default.

---

## Ansible

Used for host bootstrap only. After the host is set up, Ansible is not in the service-deploy loop (except for the `bootstrap/gitops/` stack itself).

### Requirements

- Ubuntu 24.04 (other versions/distros untested)
- Python >= 3.14
- uv >= 0.6.0

### Setup

1. Install packages and Ansible Galaxy roles:
   ```bash
   uv sync
   uv run ansible-galaxy install -r requirements.yml
   ```
2. Create a `.vault_key` file in the repo root with your Ansible vault password.

### What the `docker_host` role does

- Installs Docker (`packages.yml`).
- Creates the external `home_server_network` bridge (`network.yml`).
- Plants the SOPS age key at `~/.config/sops/age/keys.txt` from `vault_sops_age_key` (`gitops.yml`).
- Plants the cloudflared tunnel token at `~/.config/cloudflared/tunnel_token` from `vault_cloudflared_tunnel_token`.
- Creates `/home/diego/services_data/`.
- Renders the `bootstrap/gitops/` stack's `.env` file from `vault_docker_host_env.gitops` and syncs its compose file to `~/bootstrap/gitops/`.

Everything else (12 other categories) is DocoCD's job.

### Playbooks

- **update_home_server.yml** — runs `system` + `projects` + `docker_host` roles against the home server. Deployed via the **Update Home Server** GitHub Actions workflow on every push to `main`, and available via `workflow_dispatch`.
- **restore_home_server.yml** — runs `system` + `projects` + `restore` + `docker_host` roles. Deployed via the **Restore Home Server** workflow (manual only).

Both workflows share a composite action (`.github/actions/setup-ansible/`) that handles Python/uv setup, Galaxy roles, vault key, SSH, and Tailscale connectivity.

### Testing

Testing is done with [Molecule](https://ansible.readthedocs.io/projects/molecule/). Available scenarios: `system`, `projects`, `restore`, `docker_host`.

```bash
molecule test -s <role>        # full sequence
molecule converge -s <role>    # apply only
molecule login -s <role>       # shell into test container
molecule destroy -s <role>     # tear down
```

### Linting

```bash
uv run yamllint .
uv run ansible-lint
```

---

## Secret management

There are two classes of secret with different handling.

### Per-stack service env — SOPS + age (committed to git)

Each DocoCD-managed stack has a `services/<cat>/secrets.enc.env` (or per-service split like `services/ai/paperclip.enc.env`). It's encrypted with the age recipient in [`.sops.yaml`](.sops.yaml); only someone with the secret age key can decrypt. The host has that key because Ansible plants it from vault on bootstrap.

Edit directly with SOPS:

```bash
sops services/<category>/secrets.enc.env
```

Put **final container env var names** in these files. Compose's `${VAR}` interpolation does not read `env_file` contents, so `environment: - X=${Y}` with `Y` in env_file resolves to empty.

### Host-level bootstrap secrets — Ansible vault

Used only for secrets that must be present **before** DocoCD can run:

| Vault file | Contents |
|---|---|
| `roles/system/vars/main/vault.yml` | User password (`vault_password`) |
| `roles/projects/vars/main/vault.yml` | Project-specific secrets |
| `roles/restore/vars/main/vault.yml` | Backup/restore credentials |
| `roles/docker_host/vars/main/env_vault.yml` | `vault_sops_age_key`, `vault_cloudflared_tunnel_token`, `vault_docker_host_env.gitops` (only the `gitops/` stack's env) |

Editing:

```bash
uv run ansible-vault view roles/<role>/vars/main/vault.yml
uv run ansible-vault edit roles/<role>/vars/main/vault.yml
```

---

## Docker Compose conventions

- Files at `services/<category>/docker-compose.yaml`; container names snake_case matching the service key.
- All services on the external `home_server_network` bridge.
- **Runtime state** uses absolute bind mounts under `/home/diego/services_data/<category>/<service>/`.
- **Static config checked into git** uses relative bind mounts from the compose dir (e.g. `./traefik/traefik.yml:/traefik.yml:ro`); DocoCD serves these from its clone. Consider the `cd.doco.deployment.recreate.ignore` label where a SIGHUP reload is nicer than a container recreate.
- Media volumes at `/media/hd1-3/`.
- VPN-routed services use `network_mode: service:gluetun`.
- Healthchecks on every service (curl/wget, 30s interval, 10s timeout).
- Restart policy: `unless-stopped`.
- Env from `env_file: secrets.enc.env` (or per-service `.enc.env`).
