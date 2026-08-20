# Getting Started — Adopting This Repo For Your Own Home Server

This repo was built around one specific homelab (see the author-specific paths, domain, and LAN IPs baked into compose files and Ansible roles). If you want to fork it and run the same stack on your own hardware, the changes below are what you need to make before the first `git push` to your fork can trigger a DocoCD deploy.

If you're looking for *how the system works* (deploy flow, SOPS edit loop, Ansible role responsibilities), read the [README](../README.md) and the [ADRs](adr/) first — this guide assumes that context.

## Step 1 — Fork and clone

1. Fork `DiegoHeer/gitops-homelab` on GitHub to your own account.
2. Clone your fork locally. Keep `main` as the deploy branch — [`.github/workflows/configure-home-server.yml`](../.github/workflows/configure-home-server.yml) is wired to `push: branches: main`.
3. Install tooling:
   ```bash
   uv sync
   uv run ansible-galaxy install -r requirements.yml
   ```

## Step 2 — Hardware and OS prerequisites

- **Ubuntu 24.04** server (the only tested distro — other versions/distros are untested).
- A **non-root user account** matching whatever you substitute for `diego` in Step 4, with `sudo` + `docker` groups. The `system` role creates this, but SSH must already work as *some* user.
- Storage drives mounted at `/media/hd1`, `/media/hd2` (or remap — see Step 5).
- A **domain you control** with DNS managed in **Cloudflare** (required for Traefik ACME DNS-01 and Cloudflare Tunnel — see [ADR 0007](adr/0007-cloudflare-tunnel-external-exposure.md)).
- A **Tailscale tailnet** if you want the GitHub Actions workflow to reach your server (see [ADR 0016](adr/0016-tailscale-for-ci-access.md)).

## Step 3 — Generate your own keys and tokens

None of the author's keys are reusable. Generate fresh:

1. **Age keypair for SOPS**
   ```bash
   age-keygen -o ~/age_key.txt
   ```
   The **public** recipient (`age1…`) goes into [`.sops.yaml`](../.sops.yaml) in Step 4. The **private** key goes into the `vault_sops_age_key` vault var in Step 6.
2. **Ansible vault password** — any strong passphrase. Write it to `.vault_key` at the repo root (already gitignored) and also store it as the `VAULT_PASSWORD` GitHub Actions secret.
3. **SSH keypair** for the GitHub Actions runner → home server. Add the public key to the server user's `~/.ssh/authorized_keys`; the private key goes into the `SSH_PRIVATE_KEY` GitHub Actions secret.
4. **GitHub webhook secret** for DocoCD — any random string. It goes into both the `WEBHOOK_SECRET` key under `vault_docker_host_env.gitops` (Step 6) **and** the webhook you create in your fork's GitHub settings.
5. **Cloudflare Tunnel token** — create a tunnel in the Cloudflare dashboard, copy the token, put it in `vault_cloudflared_tunnel_token` (Step 6).
6. **Cloudflare DNS API token** — scoped for Traefik's ACME DNS-01 challenge. Goes into `services/networking/secrets.enc.env` as `CF_DNS_API_TOKEN`.

## Step 4 — Replace author-specific identity

Global find/replace across the repo. Verify each with `git grep` when you're done.

| Find | Replace with | Where it lives |
|---|---|---|
| `diego` (username, path segments) | your Linux username | Ansible roles, every `docker-compose.yaml` volume mount under `/home/diego/services_data/`, `roles/*/defaults/main.yml` |
| `/home/diego/` | `/home/<you>/` | ~70+ compose volume mounts; `services_data/` root; `~/.config/` paths |
| `Diego Heer` / `diegojonathanheer@gmail.com` | your name + email | `roles/projects/defaults/main.yml` (git config), [`services/networking/traefik/traefik.yml`](../services/networking/traefik/traefik.yml) (ACME email) |
| `DiegoHeer/gitops-homelab` | `<youruser>/gitops-homelab` | `.github/` workflows, README badges, DocoCD stack git URL in [`bootstrap/gitops/`](../bootstrap/gitops/) |
| `local.dynabase.nl` | your own domain | Traefik router labels across `services/**/docker-compose.yaml` (~35 labels), [`services/networking/traefik/traefik.yml`](../services/networking/traefik/traefik.yml) SANs, [`services/networking/traefik/config.yml`](../services/networking/traefik/config.yml) Host rules |
| Nextcloud backup path `…/nextcloud/diego/files` | `…/nextcloud/<you>/files` | `services/backups/docker-compose.yaml`, `roles/restore/defaults/main.yml` |
| Author's age public key in [`.sops.yaml`](../.sops.yaml) | your age public key (from Step 3) | `.sops.yaml` |

## Step 5 — Replace home-network-specific values

Edit [`services/networking/traefik/config.yml`](../services/networking/traefik/config.yml) — the static routes point at the author's LAN:

- `192.168.1.1` → your router
- `192.168.1.10` → your home server's LAN IP
- `192.168.1.11` → your Pi-hole (or delete the block if you're not running one)
- `192.168.1.100` → your Proxmox host (or delete)

If your disks aren't at `/media/hd1..hd2`, edit those bind mount sources in `services/media/`, `services/storage/`, `services/games/`, `services/photos/`, and `services/backups/docker-compose.yaml`.

## Step 6 — Populate Ansible vault files

Four vault files, re-created from scratch with your values. Edit each with `uv run ansible-vault edit <file>` (it'll read the password from `.vault_key`):

| File | Required keys |
|---|---|
| `roles/system/vars/main/vault.yml` | `vault_password` — the Linux user account password Ansible will set |
| `roles/projects/vars/main/vault.yml` | Git/SSH credentials per the role's defaults |
| `roles/docker_host/vars/main/env_vault.yml` | `vault_sops_age_key` (full private age key), `vault_cloudflared_tunnel_token`, `vault_docker_host_env.gitops` (map containing `WEBHOOK_SECRET`, `SOPS_AGE_KEY`, `APPRISE_NOTIFY_URLS`, `APPRISE_NOTIFY_LEVEL`) |
| `roles/restore/vars/main/vault.yml` | Restic repo URL + password + credentials for your 3-2-1 targets (see [ADR 0015](adr/0015-restic-zerobyte-backup-strategy.md)) |

## Step 7 — Re-encrypt the per-stack SOPS secrets

Because you replaced the recipient in `.sops.yaml`, every `services/**/*.enc.env` currently in the repo is still encrypted to the original key and **unreadable to you**. Two options:

- **Easiest**: delete every `services/**/*.enc.env`, then run `sops services/<category>/secrets.enc.env` for each category and paste your own values. The env var names each service expects are in its `docker-compose.yaml` (`environment:` + `env_file:` sections).
- **Template-assisted**: before deleting, scan each `docker-compose.yaml` to list the var names you need — so you don't forget any (Cloudflare DNS token, Pi-hole password, SteamGridDB/RetroAchievements, Immich, Nextcloud admin, etc.).

See [ADR 0005](adr/0005-sops-age-stack-secrets.md) for why secrets live in git encrypted instead of an external vault.

## Step 8 — GitHub Actions secrets

In your fork → Settings → Secrets and variables → Actions, add:

- `VAULT_PASSWORD` (same value as `.vault_key`)
- `SSH_PRIVATE_KEY`
- `SERVER_USER`
- `TAILSCALE_OAUTH_CLIENT_ID`, `TAILSCALE_OAUTH_CLIENT_SECRET`, `TAILSCALE_SERVER_IP` — or rip the Tailscale step out of `.github/actions/setup-ansible/` and use a reachable public IP instead.

## Step 9 — First bootstrap run

From your workstation, with SSH working to the server:

```bash
uv run ansible-playbook playbooks/update_home_server.yml
```

This runs `system` → `projects` → `docker_host`, which creates the user, installs Docker, creates the external `home_server_network` bridge, plants the age key and tunnel token, creates `/home/<you>/services_data/`, and brings up the DocoCD stack in `~/bootstrap/gitops/`.

## Step 10 — Point the GitHub webhook at DocoCD

In your fork → Settings → Webhooks, add a webhook pointing to your DocoCD endpoint (exposed via the Cloudflare Tunnel), using the `WEBHOOK_SECRET` you set in Step 6. After the first push to `main`, DocoCD takes over and reconciles every stack in [`.doco-cd.yml`](../.doco-cd.yml).

---

## Verifying it worked

Run these checks in order — each one confirms the prior step:

1. **Ansible dry run** — `uv run ansible-playbook playbooks/update_home_server.yml --check` produces no undefined-vault-var errors.
2. **SOPS round-trip** — `sops -d services/networking/secrets.enc.env` succeeds on your workstation (proves `.sops.yaml` + your local age key align).
3. **Molecule converges** — `uv run molecule converge -s docker_host` runs clean against a disposable container.
4. **DocoCD is up** — on the server, `docker ps | grep doco-cd` shows the container; its logs show a successful repo clone.
5. **First deploy** — push a trivial change (bump a compose label) to `main`; DocoCD logs show the webhook received and `docker compose up -d` for the affected stack.
6. **Service reachable externally** — hit `https://<service>.<yourdomain>` from off your LAN (proves Cloudflare Tunnel + Traefik + ACME + DNS all line up).
7. **Restore drill** — run `uv run ansible-playbook playbooks/restore_home_server.yml` against a scratch host to confirm the `restore` role's vault values work *before* you need them (see [ADR 0015](adr/0015-restic-zerobyte-backup-strategy.md)).

## Files an adopter ends up editing

- [`.sops.yaml`](../.sops.yaml) — age recipient
- [`services/networking/traefik/traefik.yml`](../services/networking/traefik/traefik.yml) — ACME email + domain SANs
- [`services/networking/traefik/config.yml`](../services/networking/traefik/config.yml) — LAN IPs + Host rules
- `services/**/docker-compose.yaml` — domain labels + volume paths
- `services/**/*.enc.env` — all re-encrypted against your age key
- `roles/*/vars/main/vault.yml` (four files) — regenerated with your vault password
- `roles/*/defaults/main.yml` — username, git identity, media paths
- `.vault_key` — new file, your vault password
- GitHub Actions repository secrets — via repo settings UI, not a file
