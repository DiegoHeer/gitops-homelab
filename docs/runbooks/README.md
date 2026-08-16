# Runbooks

Operational guides written reactively. If a procedure was painful
enough to need a second try, document it here.

## Conventions

- One procedure per file
- Filenames are kebab-case, action-oriented: `restore-nextcloud.md`,
  `rotate-sops-key.md`
- Use the template at `template.md`

## Contents

- [recover-gluetun-netns-orphan.md](recover-gluetun-netns-orphan.md) — media services 502 through
  Traefik after a gluetun restart stranded them in a dead network namespace
