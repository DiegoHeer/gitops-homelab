# 0018 — Authelia OIDC for authenticating exposed services

- **Status**: Accepted
- **Date**: 2026-07-15
- **Deciders**: Diego

## Context

Tunnel-exposed services (Grist first) have no authentication — Grist runs in
its open single-user default, so anyone reaching `grist.dynabase.nl` gets full
access. Traefik is LAN-only (ADR 0014); internet traffic goes cloudflared →
container directly (ADR 0007), so there is no reverse-proxy in the tunnel path
to attach a forward-auth middleware to. Auth must therefore live at the
Cloudflare edge or inside each app. Options weighed: Cloudflare Access (policy
lives outside git), Traefik forward-auth (not in the tunnel path), Pocket-ID
(passkey-only OIDC, clients configured in its UI not git), Authelia.

## Decision

Run **Authelia** as a self-hosted OpenID Connect provider, and authenticate
exposed apps by their **native OIDC** client (not forward-auth). Grist is the
first consumer: exposed public-only via the tunnel with `GRIST_FORCE_LOGIN`,
its LAN route removed, delegating login to Authelia. Users authenticate with
**passwordless passkeys** (`enable_passkey_login`, client policy `one_factor`).
Authelia's provider config, clients (with *hashed* secrets), and access rules
live in committed YAML; only the raw signing secrets are held in SOPS / as a
mounted key file.

## Consequences

- `+` real per-user identity in Grist (ownership, sharing, roles) — the multi-user goal
- `+` config-as-code: clients, policies, WebAuthn settings all in git (Authelia's file model)
- `+` reusable IdP — future OIDC-capable exposed services connect the same way
- `+` phishing-resistant passkey login, no passwords to leak
- `−` Authelia's OIDC *provider* role is beta-labelled (OpenID-Certified, stable in practice)
- `−` Grist's server-side token exchange reaches `auth.dynabase.nl` out through the
  Cloudflare Tunnel (hairpin) — Traefik is not in the public path and holds no cert
  for `*.dynabase.nl`, so internal auth depends on the tunnel being up
- `−` the OIDC issuer signing key is a new secret to manage (mounted key file)
- `−` Cloudflare Zero Trust hostname mappings (`grist`/`auth.dynabase.nl` → containers)
  live in the CF dashboard, outside git
- `−` auth-less future services can't use native OIDC; they'd need Cloudflare Access at the edge

## Evidence

- `services/security/docker-compose.yaml` — authelia container
- `services/security/authelia/configuration.yml` — provider, clients, WebAuthn, access rules
- `services/tools/docker-compose.yaml` — Grist `GRIST_OIDC_*` + public-only router
- (to be filled with the implementing PR/commit refs on merge)
