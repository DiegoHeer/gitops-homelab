# Architectural Decision Records

Backfilled ADRs documenting the load-bearing decisions in this homelab.
All initial ADRs are `Accepted` as of 2026-04-18.

## Index

| # | Title | Status |
|---|---|---|
| [0001](0001-gitops-via-dococd.md) | GitOps via DocoCD (push-based) | Accepted |
| [0002](0002-docker-compose-over-k8s.md) | Docker Compose over Kubernetes | Accepted |
| [0003](0003-single-host-no-ha.md) | Single-host homelab, no HA | Accepted |
| [0004](0004-ansible-bootstrap-only.md) | Ansible reduced to bootstrap-only | Accepted |
| [0005](0005-sops-age-stack-secrets.md) | SOPS + age for stack secrets | Accepted |
| [0006](0006-ansible-vault-bootstrap-secrets.md) | Ansible Vault for bootstrap secrets | Accepted |
| [0007](0007-cloudflare-tunnel-external-exposure.md) | Cloudflare Tunnel over port-forward | Accepted |
| [0008](0008-uv-replaces-poetry.md) | uv replacing Poetry for Python deps | Accepted |
| [0009](0009-molecule-for-role-testing.md) | Molecule for Ansible role testing | Accepted |
| [0010](0010-precommit-hooks-as-lint-gate.md) | Pre-commit hooks as local lint gate | Accepted |
| [0011](0011-category-action-commit-format.md) | `Category\|Action:` commit format | Accepted |
| [0012](0012-absolute-bind-mounts-services-data.md) | Absolute bind mounts under `/home/diego/services_data/` | Accepted |
| [0013](0013-external-home-server-network.md) | External `home_server_network` Docker network | Accepted |
| [0014](0014-traefik-v3-replaces-nginx-proxy-manager.md) | Traefik v3 replacing Nginx Proxy Manager | Accepted |
| [0015](0015-restic-zerobyte-backup-strategy.md) | Restic + Zerobyte for 3-2-1 backups | Accepted |
| [0016](0016-tailscale-for-ci-access.md) | Tailscale for CI → homelab access | Accepted |
| [0017](0017-lightweight-observability.md) | Lightweight observability over Prometheus stack | Accepted |
| [0018](0018-authelia-oidc-for-exposed-services.md) | Authelia OIDC for authenticating exposed services | Proposed |

## Adding a new ADR

1. Copy `template.md` to the next number (`NNNN-kebab-title.md`)
2. Fill Context / Decision / Consequences / Evidence
3. Set `Status: Accepted` when the decision is in effect
4. Add a row to the index above
5. If the new ADR supersedes an older one, update the older one's
   `Status` to `Superseded by NNNN` — never edit the decision itself
