# Grist multi-user auth via Authelia OIDC — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give self-hosted Grist real multi-user authentication by standing up Authelia as an OIDC provider and having Grist delegate login to it, exposed publicly via the Cloudflare Tunnel.

**Architecture:** Authelia runs in the existing `security` stack as a self-hosted OIDC provider, reachable only at `auth.dynabase.nl` via the Cloudflare Tunnel (no Traefik route — a LAN hostname would break the WebAuthn RP and the `dynabase.nl`-scoped session cookie). Grist uses its *native* OIDC client (no forward-auth — the tunnel bypasses Traefik) to authenticate against Authelia. Users log in with passwordless passkeys. Delivered as two standalone-testable PRs: (1) the Authelia IdP foundation, (2) migrating Grist onto it.

**Tech Stack:** Docker Compose, Authelia 4.39.20, Grist 1.7.15, Cloudflare Tunnel (cloudflared, direct to containers), SOPS+age, DocoCD (push-based deploy).

## Implementation notes (PR 1 as-built — corrections to original tasks)

Verified against `authelia/authelia:4.39.20`; the following supersede the matching
details in the tasks below (Task 4's compose snippet and Task 5 especially):

- **Issuer key is NOT a Docker secret / env `_FILE`.** Authelia cannot populate
  list-of-objects sections (`identity_providers.oidc.jwks`) from env or secrets.
  Instead the key lives in a second, non-git config file `oidc-jwks.yml`
  (generated server-side under `services_data/security/authelia/secrets/`,
  embedding the PEM) mounted read-only, and Authelia loads both files via
  `command: [--config, …/configuration.yml, --config, …/oidc-jwks.yml]`.
- **No `authelia healthcheck` subcommand** in 4.39.20 — healthcheck is
  `wget --spider http://localhost:9091/api/health` (image also ships
  `/app/healthcheck.sh`; `/api/health` needs no Host header).
- **Container runs as `user: "1000:1000"`** so the entrypoint skips its
  root-only chown path; the `/data` dir is pre-created owned by 1000:1000.
- The client secret is PBKDF2-hashed in `configuration.yml`; plaintext is
  stashed server-side at `…/secrets/grist_client_secret.txt` for PR 2.
- **No Traefik labels on the Authelia service.** It is reached only via the
  Cloudflare Tunnel at `auth.dynabase.nl`; a LAN route would break the
  `dynabase.nl`-scoped session cookie and the `auth.dynabase.nl` WebAuthn RP.
- **No Pi-hole split-DNS.** Grist's server-side token exchange (PR 2) reaches
  the issuer by hairpinning out through the tunnel — Traefik is not in the
  public path and has no `*.dynabase.nl` cert to point a local record at.

## Global Constraints

- YAML: 160-char line limit (yamllint); `.yaml` extension for compose, `.yml` accepted for third-party config files (Authelia expects `configuration.yml`).
- Container names snake_case matching the service key; every service on external `home_server_network`; healthcheck + `restart: unless-stopped` on every service.
- Runtime state → absolute bind mounts under `/home/diego/services_data/<category>/<service>/`. Static config → relative bind mounts from the compose dir.
- Final container env var names go directly into `secrets.enc.env` (SOPS); Compose `${VAR}` interpolation does NOT read `env_file`.
- Never commit plaintext `.env`, secrets, or unhashed credentials. Argon2id/PBKDF2 *hashes* and OIDC *hashed* client secrets MAY be committed (irreversible, like `/etc/shadow`).
- Commit format: `Category|Action: description` (Categories: Services, Ansible, Infrastructure, Config; Actions: Add, Refactor, Remove, Fix, Update, Migrate). Group commits by change type. Atomic commits; working state after each.
- Any subsystem-changing PR must ship its ADR — done: `docs/adr/0018-authelia-oidc-for-exposed-services.md` (ships with PR 1).
- The `security` stack is already registered in `.doco-cd.yml` (`webhook_filter: "^refs/heads/main$"`) — no registry change needed.
- Pin exact image tags (no `latest`); Renovate manages bumps afterward.
- Public domain is `dynabase.nl` (tunnel); LAN domain is `local.dynabase.nl` (Traefik + wildcard cert `*.local.dynabase.nl`).

---

## File structure

**PR 1 — Authelia IdP foundation**
- Create `services/security/authelia/configuration.yml` — Authelia main config (server, session, storage, notifier, WebAuthn, OIDC provider + Grist client, access control). Committed; secrets referenced, not inlined.
- Create `services/security/authelia/users_database.yml` — file backend, one user with an argon2id password hash (bootstrap credential; passkeys enrolled after first login).
- Modify `services/security/docker-compose.yaml` — add the `authelia` service (Traefik labels for LAN, Docker secret for the OIDC key, bind mounts) and the top-level `secrets:` block.
- Modify `services/security/secrets.enc.env` (SOPS) — add `AUTHELIA_*` signing secrets.
- Host (out of git): generate `oidc-jwks.yml` (embedding the issuer key) under `services_data/security/authelia/secrets/`; add the Cloudflare Zero Trust hostname for `auth.dynabase.nl`.

**PR 2 — Grist onto OIDC**
- Modify `services/security/authelia/configuration.yml` — (already contains the Grist client from PR 1; verify redirect URI).
- Modify `services/tools/docker-compose.yaml` — swap Grist's Traefik router to remove LAN exposure, keep it healthcheck-only internally; the tunnel handles public ingress.
- Modify `services/tools/secrets.enc.env` (SOPS) — add `GRIST_OIDC_*` vars incl. the plaintext client secret matching PR 1's hash.
- Host (out of git): Cloudflare Zero Trust hostname for `grist.dynabase.nl` → grist container.

---

## PR 1 — Authelia IdP foundation

Goal: a working, independently verifiable OIDC provider, with the Grist client pre-declared so PR 2 is a low-risk flip.

### Task 1: Generate signing secrets and the OIDC issuer key

**Files:**
- Host secret file: `/home/diego/services_data/security/authelia/secrets/oidc.pem` (git-ignored; mounted as Docker secret)
- Modify: `services/security/secrets.enc.env` (SOPS)

**Interfaces:**
- Produces: `AUTHELIA_SESSION_SECRET`, `AUTHELIA_STORAGE_ENCRYPTION_KEY`, `AUTHELIA_IDENTITY_VALIDATION_RESET_PASSWORD_JWT_SECRET`, `AUTHELIA_IDENTITY_PROVIDERS_OIDC_HMAC_SECRET` (all 64+ char random) in the SOPS env; an RSA private key PEM at the host path; a PBKDF2 hash of the Grist client secret (used in Task 3); the plaintext Grist client secret (used in PR 2).

- [ ] **Step 1: Generate the random signing secrets**

```bash
# Run inside a throwaway authelia container so the binary matches the version
docker run --rm authelia/authelia:4.39.20 authelia crypto rand --length 64 --charset alphanumeric   # run 4x, one per secret
```

- [ ] **Step 2: Generate the OIDC issuer RSA key pair**

```bash
mkdir -p /home/diego/services_data/security/authelia/secrets
docker run --rm -v /home/diego/services_data/security/authelia/secrets:/keys \
  authelia/authelia:4.39.20 authelia crypto pair rsa generate --bits 4096 --directory /keys
mv /home/diego/services_data/security/authelia/secrets/private.pem \
   /home/diego/services_data/security/authelia/secrets/oidc.pem
chmod 600 /home/diego/services_data/security/authelia/secrets/oidc.pem
```

- [ ] **Step 3: Generate the Grist client secret + its hash**

```bash
docker run --rm authelia/authelia:4.39.20 authelia crypto rand --length 72 --charset rfc3986   # = plaintext client secret (save for PR 2)
docker run --rm authelia/authelia:4.39.20 authelia crypto hash generate pbkdf2 --variant sha512 --password '<plaintext-from-above>'   # = hash for config
```

- [ ] **Step 4: Write the SOPS secrets**

Add to `services/security/secrets.enc.env` via `sops services/security/secrets.enc.env`:

```
AUTHELIA_SESSION_SECRET=<rand#1>
AUTHELIA_STORAGE_ENCRYPTION_KEY=<rand#2>
AUTHELIA_IDENTITY_VALIDATION_RESET_PASSWORD_JWT_SECRET=<rand#3>
AUTHELIA_IDENTITY_PROVIDERS_OIDC_HMAC_SECRET=<rand#4>
```

- [ ] **Step 5: Verify the key and hash are usable**

Run: `docker run --rm -v /home/diego/services_data/security/authelia/secrets:/k authelia/authelia:4.39.20 authelia crypto pair rsa --help >/dev/null && head -1 /home/diego/services_data/security/authelia/secrets/oidc.pem`
Expected: prints `-----BEGIN RSA PRIVATE KEY-----` (or `-----BEGIN PRIVATE KEY-----`).

- [ ] **Step 6: Commit** (secrets file only; the PEM is git-ignored host state)

```bash
git add services/security/secrets.enc.env
git commit -m "Services|Add: Authelia signing secrets for OIDC provider"
```

### Task 2: Authelia users database

**Files:**
- Create: `services/security/authelia/users_database.yml`

**Interfaces:**
- Consumes: nothing.
- Produces: a `diego` user (email `diego.heer@odido.nl`, display name, argon2id password hash) referenced by the file authentication backend in Task 3.

- [ ] **Step 1: Generate the argon2id password hash**

```bash
docker run --rm authelia/authelia:4.39.20 authelia crypto hash generate argon2 --password '<bootstrap-password>'
```

- [ ] **Step 2: Write the users database**

```yaml
# services/security/authelia/users_database.yml
users:
  diego:
    disabled: false
    displayname: 'Diego Heer'
    password: '<argon2id-hash-from-step-1>'
    email: 'diego.heer@odido.nl'
    groups:
      - admins
```

- [ ] **Step 3: Lint**

Run: `uv run yamllint services/security/authelia/users_database.yml`
Expected: no errors (mind the 160-char limit).

- [ ] **Step 4: Commit**

```bash
git add services/security/authelia/users_database.yml
git commit -m "Services|Add: Authelia file user backend with bootstrap admin"
```

### Task 3: Authelia configuration.yml

**Files:**
- Create: `services/security/authelia/configuration.yml`

**Interfaces:**
- Consumes: `AUTHELIA_*` env secrets (Task 1), `users_database.yml` (Task 2), the Docker-secret key file at `/run/secrets/authelia_oidc_key` (Task 4), the Grist client hash (Task 1).
- Produces: an OIDC provider issuing at `https://auth.dynabase.nl` with discovery at `/.well-known/openid-configuration`, and a client `grist` with redirect URI `https://grist.dynabase.nl/oauth2/callback` — consumed by PR 2.

- [ ] **Step 1: Write the configuration**

```yaml
# services/security/authelia/configuration.yml
theme: 'auto'

server:
  address: 'tcp://0.0.0.0:9091'

log:
  level: 'info'

authentication_backend:
  file:
    path: '/config/users_database.yml'
    password:
      algorithm: 'argon2'

session:
  name: 'authelia_session'
  cookies:
    - domain: 'dynabase.nl'
      authelia_url: 'https://auth.dynabase.nl'
      default_redirection_url: 'https://dynabase.nl'
      expiration: '1 hour'
      inactivity: '15 minutes'

storage:
  local:
    path: '/data/db.sqlite3'

notifier:
  filesystem:
    filename: '/data/notification.txt'

webauthn:
  disable: false
  enable_passkey_login: true
  display_name: 'Dynabase Homelab'

access_control:
  default_policy: 'one_factor'

identity_providers:
  oidc:
    issuer_private_keys:
      - key_id: 'primary'
        algorithm: 'RS256'
        use: 'sig'
        path: '/run/secrets/authelia_oidc_key'
    clients:
      - client_id: 'grist'
        client_name: 'Grist'
        client_secret: '<pbkdf2-hash-from-task-1>'
        public: false
        authorization_policy: 'one_factor'
        require_pkce: true
        pkce_challenge_method: 'S256'
        redirect_uris:
          - 'https://grist.dynabase.nl/oauth2/callback'
        scopes:
          - 'openid'
          - 'profile'
          - 'email'
        userinfo_signed_response_alg: 'none'
```

Note: `hmac_secret` and the issuer key are supplied by env/secret (`AUTHELIA_IDENTITY_PROVIDERS_OIDC_HMAC_SECRET`, and the `path:` above). If the running 4.39.20 schema rejects `issuer_private_keys[].path`, fall back to `AUTHELIA_IDENTITY_PROVIDERS_OIDC_ISSUER_PRIVATE_KEYS_0_KEY_FILE=/run/secrets/authelia_oidc_key` in `secrets.enc.env` and drop the `path:` line — verify against `authelia validate-config`.

- [ ] **Step 2: Lint**

Run: `uv run yamllint services/security/authelia/configuration.yml`
Expected: no errors.

- [ ] **Step 3: Validate the config against the Authelia schema**

Run:
```bash
docker run --rm \
  -v $PWD/services/security/authelia:/config \
  -v /home/diego/services_data/security/authelia/secrets/oidc.pem:/run/secrets/authelia_oidc_key:ro \
  -e AUTHELIA_SESSION_SECRET=x -e AUTHELIA_STORAGE_ENCRYPTION_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx \
  -e AUTHELIA_IDENTITY_VALIDATION_RESET_PASSWORD_JWT_SECRET=x -e AUTHELIA_IDENTITY_PROVIDERS_OIDC_HMAC_SECRET=x \
  authelia/authelia:4.39.20 authelia validate-config --config /config/configuration.yml
```
Expected: `Configuration parsed and loaded successfully without errors.` (warnings about test-length secrets are fine here.)

- [ ] **Step 4: Commit**

```bash
git add services/security/authelia/configuration.yml
git commit -m "Services|Add: Authelia OIDC provider config with Grist client"
```

### Task 4: Authelia service in the security stack

**Files:**
- Modify: `services/security/docker-compose.yaml`

**Interfaces:**
- Consumes: everything from Tasks 1–3.
- Produces: a running `authelia` container on `home_server_network`, port 9091, no Traefik route, ready for the tunnel to map `auth.dynabase.nl` → `http://authelia:9091`.

- [ ] **Step 1: Add the service block**

```yaml
  authelia:
    container_name: authelia
    image: authelia/authelia:4.39.20
    restart: unless-stopped
    env_file: secrets.enc.env
    volumes:
      - ./authelia/configuration.yml:/config/configuration.yml:ro
      - ./authelia/users_database.yml:/config/users_database.yml:ro
      - /home/diego/services_data/security/authelia/data:/data
    secrets:
      - authelia_oidc_key
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.authelia.rule=Host(`auth.local.dynabase.nl`)"
      - "traefik.http.routers.authelia.entrypoints=https"
      - "traefik.http.routers.authelia.tls=true"
      - "traefik.http.services.authelia.loadbalancer.server.port=9091"
    healthcheck:
      test: ["CMD", "authelia", "healthcheck"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 15s
```

- [ ] **Step 2: Add the top-level secrets block** (append near the `networks:` footer)

```yaml
secrets:
  authelia_oidc_key:
    file: /home/diego/services_data/security/authelia/secrets/oidc.pem
```

- [ ] **Step 3: Lint**

Run: `uv run yamllint services/security/docker-compose.yaml`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add services/security/docker-compose.yaml
git commit -m "Services|Add: Authelia service to security stack"
```

### Task 5: Out-of-git wiring (Cloudflare Tunnel)

**Files:** none (Cloudflare dashboard). Record steps in the PR description.

- [ ] **Step 1:** Cloudflare Zero Trust → Networks → Tunnels → the existing tunnel → add public hostname `auth.dynabase.nl` → service `http://authelia:9091` (cloudflared reaches the container by name on `home_server_network`).
- [ ] **Step 2:** Confirm `https://auth.dynabase.nl` resolves and serves the Authelia portal publicly.
- [ ] No split-DNS: Grist's server-side token exchange in PR 2 reaches the issuer by hairpinning out through the tunnel (Traefik holds no `*.dynabase.nl` cert, so there is nothing valid to point a local record at).

### Task 6: Deploy and verify PR 1 standalone

- [ ] **Step 1: Merge to main** so DocoCD reconciles the `security` stack (per repo workflow; PRs use merge commits).
- [ ] **Step 2: Container health**

Run: `ssh server 'docker ps --filter name=authelia --format "{{.Status}}"'`
Expected: `Up … (healthy)`.

- [ ] **Step 3: OIDC discovery served**

Run: `curl -fsS https://auth.dynabase.nl/.well-known/openid-configuration | jq '.issuer, .authorization_endpoint, .token_endpoint'`
Expected: issuer `https://auth.dynabase.nl` and valid endpoint URLs.

- [ ] **Step 4: Login + passkey enrollment**

Manual: browse `https://auth.dynabase.nl`, log in as `diego` with the bootstrap password, go to Settings → Security → add a passkey, log out, log back in **with the passkey only** (passwordless). Expected: successful passwordless login.

- [ ] **Step 5: Client wiring dry-run**

Run: `curl -sS -o /dev/null -w '%{http_code} %{redirect_url}\n' "https://auth.dynabase.nl/api/oidc/authorization?client_id=grist&response_type=code&scope=openid&redirect_uri=https://grist.dynabase.nl/oauth2/callback&state=x&code_challenge=x&code_challenge_method=S256"`
Expected: a 302 toward the Authelia login portal (not a client/redirect-uri error) — proves the `grist` client and its redirect URI are accepted before Grist exists on the other end.

**PR 1 done when Steps 2–5 pass.**

---

## PR 2 — Grist onto OIDC

Goal: Grist delegates login to Authelia; the old LAN route is gone; two distinct logins produce two distinct Grist accounts.

### Task 7: Grist OIDC secrets

**Files:**
- Modify: `services/tools/secrets.enc.env` (SOPS)

**Interfaces:**
- Consumes: the plaintext Grist client secret from Task 1 (its hash is already in Authelia's config).
- Produces: `GRIST_OIDC_*` env vars read by the Grist container.

- [ ] **Step 1: Add the OIDC vars** via `sops services/tools/secrets.enc.env`

```
GRIST_OIDC_IDP_ISSUER=https://auth.dynabase.nl
GRIST_OIDC_IDP_CLIENT_ID=grist
GRIST_OIDC_IDP_CLIENT_SECRET=<plaintext-client-secret-from-task-1>
GRIST_OIDC_SP_HOST=https://grist.dynabase.nl
GRIST_OIDC_IDP_SCOPES=openid email profile
GRIST_FORCE_LOGIN=true
```

Note: the existing `GRIST_DEFAULT_EMAIL` stays (it names the first/admin account). Keep `GRIST_SINGLE_ORG` behaviour unchanged for a single team site.

- [ ] **Step 2: Confirm decryption round-trips**

Run: `sops -d services/tools/secrets.enc.env | grep -c GRIST_OIDC_IDP_ISSUER`
Expected: `1`.

- [ ] **Step 3: Commit**

```bash
git add services/tools/secrets.enc.env
git commit -m "Services|Add: Grist OIDC client credentials for Authelia"
```

### Task 8: Remove Grist's LAN route

**Files:**
- Modify: `services/tools/docker-compose.yaml` (the `grist` service block)

**Interfaces:**
- Consumes: nothing new.
- Produces: Grist reachable only via the tunnel (`grist.dynabase.nl`); no Traefik `Host(grist.local.dynabase.nl)` router.

- [ ] **Step 1: Remove the Traefik labels from the `grist` service**

Delete the five `traefik.*` labels and the `traefik.enable=true` label (the tunnel routes to the container directly; Traefik no longer needs to expose Grist). Keep `container_name`, `image`, `env_file`, `environment: PORT=9999`, the persist volume, and the healthcheck.

- [ ] **Step 2: Lint**

Run: `uv run yamllint services/tools/docker-compose.yaml`
Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add services/tools/docker-compose.yaml
git commit -m "Services|Refactor: remove Grist LAN route; public via tunnel only"
```

### Task 9: Tunnel mapping + deploy + verify PR 2 standalone

- [ ] **Step 1:** Cloudflare Zero Trust → the tunnel → add/confirm public hostname `grist.dynabase.nl` → service `http://grist:9999`.
- [ ] **Step 2: Merge to main** so DocoCD reconciles the `tools` stack.
- [ ] **Step 3: Login redirect**

Run: `curl -sS -o /dev/null -w '%{http_code}\n' -L https://grist.dynabase.nl`
Expected: ends at the Authelia login page (302 chain into `auth.dynabase.nl`), not the open Grist UI.

- [ ] **Step 4: End-to-end login**

Manual: browse `https://grist.dynabase.nl` → redirected to Authelia → passkey login → land back in Grist **as `diego`** (top-right shows the account, not anonymous).

- [ ] **Step 5: Multi-user proof**

Manual: add a second user to `users_database.yml` (repeat Task 2 hash step, new PR), or invite via Grist sharing; confirm a second passkey login shows up in Grist as a *distinct* account with its own documents/permissions.

- [ ] **Step 6: LAN route gone**

Run: `curl -sS -o /dev/null -w '%{http_code}\n' -k https://grist.local.dynabase.nl`
Expected: 404 / no route (Traefik no longer serves Grist).

**PR 2 done when Steps 3, 4, and 6 pass.**

---

## Self-review

**Spec coverage:**
- Authelia as OIDC provider → Tasks 3, 4, 6. ✓
- Grist native OIDC (not forward-auth) → Tasks 7, 9. ✓
- Passwordless passkeys → `enable_passkey_login: true` (Task 3), verified Task 6 Step 4. ✓
- Login everywhere / LAN route removed → `GRIST_FORCE_LOGIN=true` (Task 7), Task 8, verified Task 9 Step 6. ✓
- Multi-user identity → verified Task 9 Step 5. ✓
- Split-DNS for server-side token exchange → Task 5 Step 1. ✓
- Tunnel bypasses Traefik → tunnel maps containers directly (Tasks 5, 9); Authelia LAN route is admin-only. ✓
- ADR shipped → `0018` created, ships with PR 1. ✓
- SOPS for secrets, hashes-may-be-committed → Tasks 1–3, 7. ✓
- `.doco-cd.yml` unchanged (security already registered) → Global Constraints. ✓

**Open items to confirm at implementation time (not placeholders — explicit verification points):**
- Exact 4.39.20 schema for supplying the issuer key (`issuer_private_keys[].path` vs `..._KEY_FILE` env) — Task 3 Step 1 note + `validate-config` gate covers it.
- Whether the Grist OIDC issuer var wants the bare issuer URL or the full `.well-known` URL — `validate` by observing Grist's discovery call in logs on first login (Task 9 Step 3).
- Cloudflare origin service scheme (`http` vs `https`) for both hostnames — Tasks 5/9.

**Type/name consistency:** client_id `grist`, redirect `https://grist.dynabase.nl/oauth2/callback`, issuer `https://auth.dynabase.nl`, and the client secret (hash in Authelia config Task 1/3, plaintext in Grist env Task 7) are consistent across tasks. ✓
