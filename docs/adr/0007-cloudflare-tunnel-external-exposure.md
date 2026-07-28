# 0007 — Cloudflare Tunnel over port-forward

- **Status**: Superseded by 0019
- **Date**: 2026-04-18
- **Deciders**: Diego

## Context

External access needed for some services (Home Assistant mobile app, webhooks).
Options: port-forward at the router + reverse proxy + Let's Encrypt + DynDNS (fragile, CGNAT-sensitive), site-to-site VPN, tunnel-based (Cloudflare Tunnel, Tailscale Funnel, frp).

## Decision

Cloudflare Tunnel (cloudflared container).
Zero exposed ports at the router; Cloudflare handles ingress and TLS.

## Consequences

- `+` ISP-proof (no static IP, works behind CGNAT)
- `+` no public IP attack surface
- `+` Cloudflare-native DDoS / WAF
- `−` dependency on Cloudflare (free tier today, could change)
- `−` tunnel token is a bootstrap-tier secret (see ADR 0006); rotation needs Ansible push

## Evidence

- `services/networking/docker-compose.yaml` — cloudflared container
- `9c91ee9` (2026-04-18) — tunnel token file handling
- `4a510da` (2026-04-18) — permissions fix for distroless image
