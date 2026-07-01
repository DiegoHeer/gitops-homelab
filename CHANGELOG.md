# Changelog

All notable changes to this homelab are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning uses CalVer (`YYYY.MM.N`).
## [2026.07.0] - 2026-06-29

### Changed

- Config|Update: regenerate CHANGELOG for 2026.06.0

### Removed

- Services|Remove: temporarily disabled hermes_agent to free resources

## [2026.06.0] - 2026-05-25

### Added

- Config|Add: included manual workflow to trigger DocoCD reconcile
- Services|Add: included mattermost for team communication
- Services|Add: enabled push notifications for mattermost mobile apps
- Services|Add: included hermes agent and webui for AI assistant
- Services|Add: included claudebox sidecar for Claude Code access via MCP
- Services|Add: included penpot for collaborative design

### Changed

- Config|Update: regenerate CHANGELOG for 2026.05.0
- Services|Update: switched mattermost to external access via Cloudflare Tunnel
- Config|Update: reduced Renovate frequency, disabled digests, isolated major PRs
- Config|Update: pinned latest-only images to versioned tags
- Config|Update: trigger doco-cd redeploy after n8n_postgres fix
- Services|Update: activated claudebox OAuth token for Claude subscription
- Config|Update: registered design stack in DocoCD and Renovate

### Fixed

- Config|Fix: added missing X-GitHub-Event header to DocoCD reconcile workflow
- Config|Fix: set before to null SHA so DocoCD skips commit diff on reconcile
- Services|Fix: use mmctl for mattermost healthcheck instead of curl
- Services|Fix: fixed hermes healthcheck and enabled gateway API server
- Services|Fix: added missing HERMES_WEBUI_STATE_DIR env var
- Config|Fix: corrected hermes-agent and hermes-webui version tags
- Services|Fix: updated n8n_postgres mount path for postgres 18+ layout
- Services|Fix: shared hermes-agent source with webui via named volume
- Services|Fix: added agent source volume for hermes webui connectivity
- Services|Fix: corrected claudebox API mode activation and volume path
- Services|Fix: corrected claudebox projects volume path to lowercase
- Services|Fix: added hostnames for penpot internal DNS resolution
- Services|Fix: resolved penpot startup dependency cycle and MCP healthcheck port
- Services|Fix: switched penpot from hostname to network aliases for DNS resolution
- Services|Fix: added MCP network alias, TCP healthcheck, and frontend dependency

### Removed

- Services|Remove: removed profilarr from media stack
- Services|Remove: removed paperclip from ai stack
- Config|Remove: stripped stale digest pins from all compose files
- Services|Remove: removed hermes webui service

## [2026.05.0] - 2026-04-30

### Added

- Config|Add: docs/ directory scaffolding with ADR and runbook templates
- Config|Add: backfill 17 ADRs documenting load-bearing homelab decisions
- Config|Add: adopter's getting-started guide covering fork-to-deploy steps (#45)
- Services|Add: included uptime kuma for monitoring uptime of certain services

### Changed

- Config|Update: regenerate CHANGELOG for 2026.04.1
- Config|Update: bump yamllint line-length to 160
- Config|Update: link docs/ from top-level README
- Config|Update: drop peer-repo references from ADRs
- Config|Update: drop Rook/Ceph from ADR 0003 and document ADR requirement in CLAUDE.md
- Config|Update: trigger doco-cd redeploy after tandoor pg16 rollback

### Fixed

- Config|Fix: correct ADR evidence citations and cross-reference
- Config|Fix: block legacy Ubuntu-date tags for linuxserver qbittorrent
- Config|Fix: restrict DocoCD deploys to main-branch pushes only (#44)
- Services|Fix: roll back tandoor_postgres to 16.13 after failed pg18 migration (#47)
- Services|Fix: include missing traefik label to uptime-kuma docker compose service

## [2026.04.1] - 2026-04-18

### Changed

- Config|Update: regenerate CHANGELOG for 2026.04.0

### Fixed

- Config|Fix: invoke Release workflow via workflow_call from auto-tag

## [2026.04.0] - 2026-04-18

### Added

- Tests/Terraform|Add: test ansible configuration management with terraform provisioned resources
- Passlib/Package|Add: included passlib package so that Ansible can handle passwords
- Playbook/Run|Add: updated playbook with standard host name of home server, and included missing roles
- Task/Terraform|Add: added task to install terraform globally
- Task/Poetry|Add: added task to install and configure poetry
- Ansible/Local|Add: added option to run ansible playbooks locally using the github repo together with the ansible-pull command
- Docs/Ansible-Pull|Add: further instructions on how to use ansible-pull
- AnsibleGalaxy|Add: installed ansible pip role
- Roles/Projects|Add: created custom role to git clone project repo's with ansible
- GitIgnore|Add: ignore terraform lock file
- Role/Services|Add: initial setup for Services role
- Playbooks|Add: moved playbooks to separate folder and added the `laptop` playbook
- Lint|Add: added a lint configuration file for Ansible
- Development/Role|Add: added tasks to autocomplete terraform commands, and to install npm, nodejs and aws-cli packages
- Gui/Solaar/Surfshark|Add: added more packages for GUI role
- Projects/Git|Add: added additional ansible configuration for git global settings
- System/oh-my-posh|Add: added theming configuration for oh-my-posh
- Lint/Ignore|Add: ignore ansible lint warning
- System/tmux|Add: added installation and configuration for tmux
- Services/Docker|Add: included all docker compose files for home server services
- Services/DockerCompose/Network|Add: created a default docker network for all services
- VScode/Settings|Add: don't consider docker compose files as ansible files
- Readme|Add: included requirements section
- Restic/Role|Add: included ansible galaxy restic role
- ResticProfile/Config|Add: included the resticprofile configuration file for managing all the backups
- GItignore|Add: ignore env files
- Roles/Restore|Add: initial setup of the Restore role
- Services/Tools|Add: included Stirling PDF as tooling for manipulating PDF files
- Services/Tailscale|Add: included a tailscale container sidecar for routing nginx
- Tests/Molecule|Add: included the molecule package for testing ansible roles and playbooks
- Tests/Molecule|Add: included molecule test scenarios for all ansible roles
- Docs/Testing|Add: included some explanations on how to use molecule for testing purposes
- VSCode/Settings|Add: consider all molecule yaml files as ansible files
- Services/Bazarr|Add: added bazarr service for automatic subtitle download
- Services/Linkwarden|Add: added linkwarden service for saving relevant weblinks
- Backup/Config|Add: exclude cache, venv, and log folders from being backed up
- Services/Healthchecks|Add: added healthchecks for almost all docker services
- Ansible/Tailscale|Add: created ansible tasks for installing and configuring Tailscale
- Services/Olivetin|Add: included a healthcheck
- Services/Tools|Add: included it-tools service
- Services|Add: included service "cup"for checking new docker image releases for services stack
- Services/Pinchflat|Add: included Pinchflat service to download youtube videos automatically
- Services|Add: included Grist app for spreadsheet (excel) functionality
- Services/Tools|Add: included Home Assistant service
- Services/Personal|Add: included personal apps for docker deployment
- Services/AI|Add: included Open WebUI service, with Tailscale sidecar
- Services/HomeAssistant|Add: created a separate file specifically for home assistant and its add-ons
- Services/Tools|Add: included ntfy as notification tool
- Services/MoneyMetrics|Add: created a separate docker compose file for Money Metrics services
- Services/Dozzle|Add: included new service Dozzle, to easily check docker logs
- Services/Mealie|Add: included new service Mealie, for managing recipes
- Services/Healthchecks|Add: included missing healthchecks
- Services/Tools|Add: included portracker as service to monitor active ports on the server
- Services/Tools|Add: included a service to convert images
- Services/Tools|Add: included a tool to create automated workflows
- Services/Tools|Add: service to fill and sign PDF documents
- Services/Tools|Add: included a healthcheck for N8N container
- Services/Media|Add: included navidrome, to handle audio clips for morning routines
- Services/Tools|Add: included ConvertX as tool for converting multiple files to different file formats
- Services|Add: updated services
- Services|Add: allow home assistant to access USB devices from the server
- Services|Add: included ironmount for server backups using restic backend
- Services|Add: allow webhooks with N8N
- Services|Add: included healthcheck for Navidrome
- Services|Add: included a postgres database for N8N
- Services|Add: always run ironmount, even after server restart
- Services|Add: included rclone to be used by Ironmount for multibackup options
- Services|Add: included Tandoor as the main recipe service
- Services|Add: included a Romm as game management application
- Services|Add: included a samba container for doorbell video recordings
- Services|Add: included portracker back again
- Services|Add: added termix for remotely ssh'ing to servers
- Services|Add: added Adguardhome for DNS filtering
- Services|Add: added Changedetection for monitoring price changes of retail products
- Services|Add: included healthcheck to Tandoor container. Also, always restart the container unless stopped
- Services|Add: included karakeep for quickly saving links, and automatically categorizing them with AI
- Services|Add: added pocket id for OIDC authentication using passkeys
- Services|Add: included a dashboard to manage services through Tailscale DNS
- Services|Add: replaced transmission openvpn for gluetun + qbittorrent
- Services|Add: included flaresolverr for solving Cloudflare captcha on certain indexers
- Services|Add: included the sabnzbd download client
- Services|Add: included an MQTT service for home assistant. This is useful for the HASS.Agent
- Services|Add: use Pangolin connector to create a tunnel for connecting externally to services
- Services|Add: added Norish as meal planner
- Service|Add: included a service to automatically update DNS mapping in Cloudflare
- Services|Add: included databasus for backing up databases
- Services|Add: OpenCloud setup
- Services|Add: included transmission again as a secondary torrent app
- Services|Add: included Cloudflare tunnel connector for remote access of services
- Services|Add: included audiobookshelf for audiobooks and podcasts
- Services|Add: included Go Access for network traffic monitoring
- Services|Add: included frigate as NVR
- Services|Add: replaced Dozzle for Dockhand
- Services|Add: included dash for system monitoring as integration inside Homarr
- Services|Add: included booklore for book management
- Config|Add: included CLAUDE.md with project conventions and commands
- Services|Add: included homepage dashboard service
- Services|Add: included openthread border router and matter server in home assistant stack
- Config|Add: added GitHub Actions CI pipeline for Ansible linting and Molecule testing
- Config|Add: added Claude Code project settings
- Services|Add: added healthchecks to all services missing them
- Molecule|Add: added prepare playbooks for projects and restore scenarios
- Services|Add: added Docker network creation and automated Compose stack startup
- Config|Add: added interactive new server bootstrap script
- Infrastructure|Add: GitHub Actions workflow for Ansible deployment via Tailscale
- Config|Add: design spec for setup-ansible-deps composite action
- Config|Add: implementation plan for setup-ansible-deps extraction
- Infrastructure|Add: create setup-ansible-deps composite action
- Ansible|Add: included linuxbrew installation and brew packages
- Config|Add: added Traefik migration design spec
- Services|Add: added traefik file provider route for home assistant
- Config|Add: added traefik migration design spec and implementation plan
- Services|Add: included PaperClip AI for agent orchestration
- Services|Add: included dockcheck CLI tool for container status overview
- Ansible|Add: included tree in linuxbrew packages
- Services|Add: re-added n8n workflow automation to ai stack
- Services|Add: updated proxmox configuration with routing and load balancing details
- Services|Add: introduced rustfs service with S3 API and console support
- Config|Add: introduced pre-commit hooks for code quality enforcement
- Config|Add: introduced vault-to-env sync script for local .env restoration
- CD|Add: included doco CD deploy settings file
- Infrastructure|Add: DocoCD stack + SOPS age secrets bootstrap (Phase 0)
- Infrastructure|Add: standalone dockcheck.sh for DocoCD-managed stacks
- Config|Add: DocoCD deploy notifications via Apprise/ntfy
- Config|Add: hide tool-generated cache folders from VSCode Explorer
- Config|Add: Renovate bot configuration for Docker Compose image updates
- Config|Add: CI, Renovate, and activity badges to README
- Config|Add: CalVer tagging with git-cliff CHANGELOG generation
- Config|Add: monthly auto-tag workflow for CalVer releases

### Changed

- Terraform/Files|Refactor: moved files to separate folder
- Terraform/SSH|Refactor: use local ssh key in place of creating one
- Terraform/Tests|Updated terraform code to facilitate the creation of a test environment
- AnsibleGalaxy/Roles|Update: use only docker_ce and poetry roles
- Role/GUI|Update: finished updating & validating the new GUI role
- Role/System|Update: finished updating the new System role
- Vars|Refactor: reuse vars across multiple custom roles
- Inventory|Refactor: standardize file with host data, and commit the standard one
- Docs|Update: updated documentation with new way of running ansible playbooks with vault password file
- System/oh-my-posh|Refactor: placed oh-my-posh code into separate task file
- System/Packages|Refactor: explicitely list packages to install, in place of using a variable
- Vars|Refactor: removed unnecessary shell variable
- Roles/Dependencies|Refactor: made the dependencies between local roles more explicit
- Services/Monitoring|Refactor: included a new monitoring solution (Beszel) in place of the Grafana & Prometheus & Node-Exporter & Cadvisor solution
- Services/Homarr|Update: updated homarr service to 1.6.0
- Services/Ports|Refactor: removed all the exposed ports, since nginx reverse proxy is the main entry point for all the services
- Tests/Docker|Refactor: removed AWS infrastructure setup to enable ansible playbook testing with local docker containers since they are cheaper, faster, and less overhead to manage
- Backup/ResticProfile|Refactor: use Cloudflare R2 in place of Backblaze B2, because of increased free bandwith
- Readme/Secrets|Refactor: removed secrets from readme file
- Docker/Secrets|Refactor: get secrets from .env file
- Role/Restore/Secrets|Refactor: create the password file in the same directory as where the original restic profile config file is
- Role/Restore|Refactor: made username and bucket url into environment variables
- Role/Services|Refactor: use the env variables for pulling and starting docker compose services
- Docker/Envs|Refactor: made the username into an environment variable
- Variables/Username|Refactor: standardized usage of the `username` variable
- Readme/Docs|Update: updated the readme with up-to-date setup for the repo
- Roles/Projects|Refactor: moved money-metrics repo from Gitlab to Github
- Services/Versions|Update: updated versions of multiple services
- Services|Update: updated images versions
- Services|Refactor: removed unused stack
- Ansible/Role/Projects|Refactor: removed gitlab related tasks, since last gitlab repo was migrated to github
- Services|Update: updated multiple images
- Services/Database|Refactor: updated names of money metrics container services
- Services/IFO|Refactor: updated docker file referring to personal project Financial Organizer
- Services|Update: updated main docker compose file with links to personal projects docker compose files
- Services/Homarr|Update: updated homarr dashboard service to 1.25
- Services/MoneyMetrics|Refactor: use env variables to get required mongodb credentials
- Services|Refactor: modified configuration of services files in a more standardized format
- Services/Tools|Update: updated portracker to newer version
- Services/Tools|Refactor: included some environment variables to improve user experience of ConvertX service
- Services|Refactor: keep main services running, even after a server restart
- Services|Refactor: deleted unused services
- Services|Refactor: changed filebrowser to filebrowser quantum
- Services|Refactor: use OIDC with Pocket-ID on services that support it
- Services|Refactor: removed autodetect feature from Home Assistant by not exposing it anymore
- Services|Refactor: replaced stirling-pdf for bentopdf, since it's more modern and lightweight
- Services|Refactor: updated media services to use new folder structure for downloaded media
- Services|Refactor: make all the Arr stack services use the gluetun network, for VPN privacy
- Services|Refactor: use new disk for storing media
- Services|Update: updated glance
- Services|Update: updated Beszel agent to latest version
- Services|Refactor: moved networking related docker containers to separate folder
- Services|Refactor: moved Zerobyte to separate docker compose stack
- Services|Refactor: made Home Assistant capable of automatically discovering devices again on the local network, to access Sonos devices
- Services|Refactor: moved filebrowser container setup to storage stack, and renamed general stack to dashboards
- Services|Refactor: updated the domain name ending used for all services
- Services|Refactor: removed opencloud
- Services|Refactor: removed pangolin connector and cloudflare ddns, since they are not required anymore
- Services|Refactor: allow pulling of latest images for games
- Services|Update: updated multiple services to latest versions
- Services|Refactor: removed transmission as torrent client
- Services|Refactor: migrated jellyseerr to seerr
- Config|Refactor: restructured README with services overview and streamlined instructions
- Services|Update: pinned glance image to v0.8.4
- Services|Refactor: moved dash to dashboards stack
- Services|Update: updated nginx-proxy-manager to 2.14
- Ansible|Update: re-encrypted system role vault variables
- Config|Migrate: migrated package manager from Poetry to uv
- Services|Refactor: manage .env files through Ansible vault with pre-commit sync
- Ansible|Refactor: moved role orchestration to playbook level
- Ansible|Update: split apt cache update and upgrade into separate tasks
- Projects|Update: added zeta repository
- Ansible|Update: restrict Molecule tests to manual workflow triggers
- Ansible|Refactor: migrated restore role to use raw restic and added nextcloud data restore
- Config|Update: migrate to Python 3.14
- Config|Update: bump ansible to v13 (ansible-core 2.20.3) for Python 3.14 compatibility
- Infrastructure|Refactor: extract shared composite action for Ansible workflow setup
- Infrastructure|Refactor: rename CI workflow to Quality Check
- Infrastructure|Refactor: split deploy into separate update and restore workflows
- Ansible|Refactor: use dedicated SSH key path for GitHub access
- Ansible|Update: update vault variables
- Config|Update: enable superpowers plugin in Claude settings
- Ansible|Update: skip Docker installation when already present
- Config|Update: update README and CLAUDE.md to reflect CI-based deployments
- Config|Update: add verbose logging to CI playbook workflows
- Config|Update: enable colored Ansible output for CI logs
- Ansible|Refactor: separate docker compose pull and up into distinct tasks
- Config|Update: enable YAML stdout callback for readable Ansible output
- Config|Update: address spec review feedback for setup-ansible-deps
- Config|Update: document secret management workflow in README
- Infrastructure|Refactor: slim setup-ansible to deployment connectivity only
- Infrastructure|Refactor: use setup-ansible-deps in quality check pipeline
- Infrastructure|Refactor: use setup-ansible-deps in deployment workflows
- Infrastructure|Update: run molecule scenarios in parallel with lint on push and dispatch
- Themes|Update: updated old oh-my-posh theme config
- Ansible|Update: skip SSH key authorization when key already exists
- Themes|Update: changed server oh-my-posh accent color to blue
- Theme|Update: updated the oh-my-posh theme to the original theme used on the server
- Ansible|Refactor: sync docker compose files directly instead of relying on cloned repo
- Services|Refactor: updated path of where services data is stored
- Playbook|Refactor: removed unnecessary configuration of repositories on server
- Ansible|Refactor: removed unused env variables from vault
- Config|Update: corrected outdated information in README and CLAUDE docs
- Ansible|Refactor: simplified failure reporting in compose tasks and removed verbose flag from deploy workflow
- Services|Refactor: replaced adguardhome with dozzle for container log monitoring
- Services|Update: added memory limits to booklore and filebrowser
- Service|Update: grist to latest version
- Services|Update: added memory limit to qbittorrent
- Services|Refactor: replaced nginx proxy manager with traefik
- Services|Update: added traefik labels to dashboard services
- Services|Update: added traefik labels to monitoring services
- Services|Update: added traefik labels to media services
- Services|Update: added traefik labels to immich
- Services|Update: added traefik labels to frigate
- Services|Update: added traefik labels to storage services
- Services|Update: added traefik labels to tool services
- Services|Update: added traefik labels to backup services
- Services|Update: added traefik labels to romm
- Ansible|Update: added traefik env vars to vault
- Services|Update: updated homeassistant router rule to use correct host
- Infrastructure|Update: bumped GitHub Actions to Node.js 24-compatible versions
- Services|Update: pinned all Docker images to specific version tags
- Services|Migrate: replaced Booklore with Grimmory for book management
- Services|Update: renamed Cloudflare API token env var for Traefik
- Services|Refactor: migrated Traefik to file-based config with local domain and pihole DNS sync
- Services|Migrate: updated all services to local.dynabase.nl domain and https entrypoint
- Ansible|Update: re-encrypted vault with new networking variables
- Services|Refactor: moved PIHOLE_URL to env vars and updated traefik config
- Services|Update: bumped grist to 1.7.12
- Roles|Refactor: updated ip address of home server in variables
- Services|Refactor: added Traefik reverse proxy and vault-managed credentials to rustfs
- Config|Update: disabled automatic Python venv activation in VS Code terminal
- Config|Update: replaced sensitive data in env_vault.yml with new encrypted values
- Config|Update: added push trigger for main branch in update-home-server workflow
- Services|Update: bumped services to latest patch versions
- Services|Update: bumped services with reviewed minor version changes
- Services|Migrate: bumped zerobyte to v0.34.0 with new APP_SECRET + BASE_URL env vars
- Services|Update: bumped tandoor 2.3.6 -> 2.6.9
- Services|Migrate: immich v2.4.1 -> v2.7.5 with VectorChord DB swap
- Services|Migrate: bentopdf to ghcr.io/alam00000/bentopdf v2.8.2
- Services|Migrate: monitoring/ to DocoCD-managed (Phase 1 pilot)
- Services|Migrate: dashboards/ to DocoCD-managed
- Services|Migrate: tools/ to DocoCD-managed
- Services|Migrate: backups/ to DocoCD-managed
- Services|Migrate: ai/ to DocoCD-managed (per-service enc files due to pg password collision)
- Services|Migrate: storage/ to DocoCD-managed
- Services|Migrate: networking/ to DocoCD-managed (final Phase 2 step)
- Services|Migrate: games/ to DocoCD-managed
- Services|Migrate: photos/ to DocoCD-managed (immich hwaccel config committed)
- Services|Migrate: security/ to DocoCD-managed
- Services|Migrate: home_assistant/ to DocoCD-managed
- Services|Migrate: media/ to DocoCD-managed (final migration)
- Ansible|Refactor: retire Ansible deploy tasks, consolidate gitops bootstrap
- Infrastructure|Refactor: move gitops stack from services/ to bootstrap/
- Ansible|Refactor: renamed services role to docker_host
- Config|Update: retitled README to GitOps Homelab
- Config|Refactor: rename project to gitops-homelab
- Config|Update: ignored .worktrees/ directory
- Config|Refactor: scoped configure-home-server workflow to bootstrap paths
- Config|Refactor: slimmed CLAUDE.md to a thin pointer to README
- Ansible|Refactor: inlined restore role setup.yml into main.yml
- Ansible|Refactor: cleaned up molecule converge boilerplate
- Infrastructure|Update: auto-set DOCKER_HOST when not on the home server
- Infrastructure|Update: batch docker inspect calls in dockcheck.sh
- Infrastructure|Update: parallel-fetch compose files in dockcheck.sh
- Infrastructure|Refactor: simplify dockcheck.sh
- Config|Update: trigger deploy to smoke-test DocoCD ntfy notifications
- Revert "Config|Update: trigger deploy to smoke-test DocoCD ntfy notifications"

### Fixed

- Poetry/Version|Fix: repair incompatilibilties
- Loops|Fix: avoid conflicts due to naming of loop variables
- Vault/Secrets|Fix: place the file with secrets in the correct place inside the role subdirectory
- Tasks|Flow|Fix: small corrections
- Local/AnsibleGalaxy|Fix: install Ansible Galaxy packages before executing roles
- Role/System|Fix: fixed bug where ansible variable couldn't be found
- Playbook/Run|Fix: facts need to be gathered for ansible variables to be used properly
- Linting|Fix: multiple linting corrections
- System/oh-my-posh|Fix: fixed installation of ob-my-posh package
- Ansible/Linting|Fix
- Tmux|Fix: don't try to install tmux again if already on the system
- ResticProfile/Env|Fix: corrected setting of env variables for accessing remote repository
- Role/Services/Checks|Fix: corrected usage of register stat check
- Roles|Fixes: solved multiple small bugs still present in local roles
- Roles/Restore|Fix: don't become sudo when restoring restic backups
- Ansible/Linting|Fix: standardized ansible linting
- Linting|Fix: removed dashes from vault files, since it conflicts with Molecule testing
- Linting|Fix
- Linting|Fix: corrected line spacings
- VSCode/Config|Fix: don't mark yaml files outside of the workspace as Ansible files
- Text|Fix
- Services/Photos|Fix: corrected naming of Immich services, since they are fixed
- Ansible/Vault/Projects|Fix: updated Github access key
- Services|Fix: removed secrets
- Services|Fix: added additional trusted domain to avoid conflicts
- Services|Fix: corrected healthcheck of gluetun
- Services|Fix: added missing restart policies to mosquitto, cloudflare_tunnel, and tandoor
- Services|Fix: moved hardcoded credentials to .env files across all service stacks
- Services|Fix: added missing restart policies to romm and romm_mariadb
- Ansible|Fix: pinned Galaxy role versions in requirements.yml
- Services|Fix: added DNS fallback servers to NPM and fixed cloudflare_tunnel indentation
- Services|Fix: fixed healthchecks for audiobookshelf, beszel, and changedetection
- Ansible|Fix: add default molecule scenario to fix CRITICAL glob error
- Ansible|Fix: resolve all yamllint errors and warnings
- Ansible|Fix: prefix register variables with role name and add missing become
- Ansible|Fix: update ssh_key references to projects_ssh_key in github tasks
- Ansible|Fix: add prepare playbook to generate SSH key for system molecule test
- Ansible|Fix: set changed_when false on restic restore tasks to pass idempotence test
- Ansible|Fix: create .ssh directory before generating SSH keypair in system prepare
- Config|Fix: bump ansible to v12 for Python 3.14 compatibility
- Config|Fix: update uv.lock for ansible 12.x
- Infrastructure|Fix: pin Python interpreter in deploy inventory to suppress warning
- Ansible|Fix: add timeout and error handling to docker compose task
- Services|Fix: correct nginx-proxy-manager image tag to full semver
- Config|Fix: resolve yamllint and ansible-lint warnings
- Config|Fix: resolve molecule galaxy dependency and removed yaml callback plugin
- Ansible|Fix: add projects role to update playbook for repo sync
- Ansible|Fix: use user-level SSH key instead of per-repo deploy keys
- Ansible|Fix: always reauthorize SSH key to prevent molecule/server key conflicts
- Ansible|Fix: corrected restic restore target paths and tolerate xattr errors
- Services|Fix: corrected mount volume for databusus container
- Ansible|Fix: fixed line length violations in compose tasks
- Ansible|Fix: fixed indentation in services env vault
- Services|Fix: set Docker API version for Traefik compatibility
- Services|Fix: corrected Grimmory database env var names to match expected format
- Services|Fix: upgraded Traefik to v3.6.10 for Docker API version auto-negotiation
- Services|Fix: corrected Grimmory env var names for user/group ID and removed unused DISK_TYPE
- Services|Fix: added explicit router-service linking for VPN-routed Traefik services
- Services|Fix: switched paperclip healthcheck from wget to curl
- Ansible|Fix: re-encrypted vault to fix YAML parsing in CI
- Ansible|Fix: corrected vault indentation for photos key and updated homepage domain
- Services|Fix: corrected n8n external domain from dynbase.nl to dynabase.nl
- Services|Fix: updated some settings
- Services|Fix: corrected syntax for proxmox host rule in Traefik configuration
- Config|Fix: preserved existing vault entries when syncing partial .env files
- Config|Fix: added missing trailing newlines to JSON files
- Services|Fix: added explicit Traefik service labels to RustFS for correct multi-port routing
- Services|Fix: pinned paperclip and rustfs to prevent :latest drift
- Config|Fix: added missing trailing newline to .doco-cd.yml
- Services|Fix: corrected invalid image tags from earlier bumps
- Ansible|Fix: install sops from upstream .deb (not in Ubuntu apt)
- Ansible|Fix: install sops via Linuxbrew instead of upstream .deb
- Services|Fix: drop custom healthcheck from doco-cd compose
- Config|Fix: scope .doco-cd.yml to gitops only (disable auto_discover)
- Config|Fix: remove gitops from .doco-cd.yml (self-deploy recursion)
- Services|Fix: pass SOPS_AGE_KEY to doco-cd via env, not _FILE mount
- Services|Fix: put final env-var names in secrets.enc.env (drop ${} indirection)
- Services|Fix: cloudflare_tunnel uses --token-file (TUNNEL_TOKEN env rejected)
- Ansible|Fix: tunnel_token mode 0644 so distroless cloudflared uid 65532 can read it
- Infrastructure|Fix: point restore role and zerobyte at ~/services_data
- Services|Fix: set ALLOWED_HOSTS/CSRF_TRUSTED_ORIGINS for tandoor 2.6.9
- Config|Fix: updated roles/ comment in README after docker_host rename
- Config|Fix: add sidecar Apprise API container so DocoCD can notify
- Config|Fix: trigger Configure Home Server workflow on bootstrap/** changes
- Ansible|Fix: persisted projects test SSH key across molecule runs
- Ansible|Fix: switched projects molecule test to vault-encrypted SSH key

### Removed

- GroupVars|Remove: deleted group vars folder, since it's not really that useful for now
- Tailscale|Remove: remove placeholder configuration for installing tailscale, since other solution (docker-based) will be used
- Tests/Terraform|Remove: deleted the test setup using terraform, since it's way more overhead than using a standard ansible testing solution like Molecule
- Services/Tailscale|Remove: removed tailscale docker sidecar for nginx, since it doesn't work properly
- Services/Pinchflat|Remove: deleted service since a better solution `Freetube` was found
- Services|Remove: removed Bazarr, since it doesn't work properly to get subtitles for movies
- Services/HomeAssistant|Remove: removed home assistant services related to voice assistant
- Services/NTFY|Remove: removed ntfy service, since it is not being used
- Services/Tools|Remove: deleted linkwarden, due to low usage
- Services|Remove: deleted Mealie, since it is not being used anymore
- Services|Remove: deleted Pocket-ID service and all related OIDC configuration of self hosted services. The authentication solution is not ideal when dealing with multiple DNS for single services (local & tailscale)
- Services|Remove: deleted Norish
- Services|Remove: removed Go Access from networking stack
- Services|Remove: removed flaresolverr from media stack
- Services|Remove: removed termix from monitoring stack
- Services|Remove: removed n8n, karakeep, and dash from tools stack
- Ansible|Remove: removed development and gui roles, laptop playbook, and unused galaxy dependencies
- Ansible|Remove: removed unused laptop and wsl oh-my-posh themes
- Ansible|Remove: deleted backup/ folder and updated references
- Ansible|Remove: deleted unused local.yml playbook
- Ansible|Remove: remove Tailscale setup and SSH key copy from system role
- Infrastructure|Remove: remove local deployment files superseded by CI workflows
- Cleanup|Remove: deleted superpowers skill docs
- Services|Remove: removed dockhand from tools stack
- Services|Remove: deleted adguardhome, since it appears to interfere with network stability
- Services|Remove: deleted Traefik migration design and implementation plan documents
- Config|Remove: removed sync-env-to-vault pre-commit hook
- Config|Remove: deleted docker compose tasks from Ansible playbook
- Config|Remove: deleted compose.yml from docker services setup tasks
- Config|Remove: delete .doco-cd.yml until Phase 1 migration
- Config|Remove: dropped historical env/vault sync scripts
- Ansible|Remove: removed placeholder molecule/default scenario


