<div align="center">

<img src="art/vsl-lockup.svg" alt="vsl — Worktree Preview CLI" width="680">

<p>Sub-second HTTPS preview environments for Git worktrees — built with Laravel Zero, FrankenPHP Octane, Bun + Vite HMR, and Caddy.</p>

<p>
  <a href="#install">Install</a> ·
  <a href="#commands">Commands</a> ·
  <a href="#t3-code--server-setup">Setup</a> ·
  <a href="#linux-arm64-static-build">ARM64 Build</a> ·
  <a href="#art">Art</a>
</p>

</div>

---

## Install

Install `vsl` globally to `/usr/local/bin/vsl`:

```bash
curl -fsSL https://raw.githubusercontent.com/novislab/vsl/main/install.sh | bash
```

Install `vsl` and enable the `systemd` reboot service (`vsl-resurrect.service` + `caddy.service`):

```bash
curl -fsSL https://raw.githubusercontent.com/novislab/vsl/main/install.sh | VSL_WITH_SERVICE=1 bash
```

Verify from anywhere:

```bash
vsl --version
```

---

## Overview

When **T3 Code** creates a worktree, `vsl up` prepares a live preview without blocking on dependencies, builds, or migrations:

- **Hydration**: hardlinks `vendor/` and `node_modules/` (`cp -al`), deep-copies `storage/`, and rewrites `.env` (`APP_URL` + isolated `DB_DATABASE`).
- **App server**: launches detached Octane (FrankenPHP) with `--watch` (if `chokidar` is present) or `--max-requests=1` so PHP edits appear immediately without manual `octane:reload`.
- **Vite HMR**: wraps your `vite.config.{ts,js,mts,mjs}` in `~/.vsl/vite/`, spawns `bun dev` in the background, and proxies Vite assets and `wss://` HMR through Caddy on the same preview domain.
- **Database**: detects `mysql`, `mariadb`, `pgsql`, or `sqlite` in `.env`, creates an isolated database, runs `migrate --force --seed` out of the terminal (`~/.vsl/logs/<slug>-db.log`), and drops it in the background on `vsl down`.
- **HTTPS + QR**: registers `<slug>.<dashed-ipv4>.sslip.io` via Caddy's Admin API (`:2019`) with automatic Let's Encrypt TLS and prints a scannable terminal QR code.
- **Persistence**: stores active state atomically (`flock`) in `~/.vsl/state.json` for `vsl resurrect` across EC2 reboots.

---

## Commands

```bash
vsl up              # Hydrate worktree, start Octane + bun dev + DB job, register Caddy HTTPS
vsl down            # Stop workers, remove Caddy route, drop isolated DB (keeps worktree on disk)
vsl list            # Show active previews, ports, PIDs, DBs, and RUNNING/DEAD status
vsl resurrect       # Restore saved previews after reboot (prunes missing dirs, restarts workers)
vsl install-service # Install and enable /etc/systemd/system/vsl-resurrect.service
```

### Flags

| Command | Options |
| --- | --- |
| `vsl up` | `--path=`, `--no-qr`, `--no-db`, `--no-seed`, `--no-vite`, `--prod`, `--json` |
| `vsl down` | `[target]`, `--path=`, `--keep-db`, `--json` |
| `vsl list` | `--prune`, `--json` |
| `vsl resurrect` | `--json` |
| `vsl install-service` | `--user=`, `--binary=`, `--dry-run` |

---

## T3 Code & Server Setup

### Caddy (`/etc/caddy/Caddyfile`)

```bash
sudo cp deploy/Caddyfile /etc/caddy/Caddyfile
sudo systemctl enable --now caddy
```

```caddyfile
{
	admin localhost:2019
}
```

### T3 Code Hooks

Add [`deploy/t3-hooks.json`](deploy/t3-hooks.json) to your T3 Code configuration:

```json
{
  "worktree": {
    "hooks": {
      "post-create": "/usr/local/bin/vsl up --path=\"$WORKTREE_PATH\"",
      "post-remove": "/usr/local/bin/vsl down --path=\"$WORKTREE_PATH\""
    }
  }
}
```

### Environment Variables

| Variable | Default | Description |
| --- | --- | --- |
| `VSL_HOME` | `~/.vsl` | State, config, Vite wrappers, and log directory |
| `VSL_PUBLIC_IP` | EC2 IMDSv2 / cached | Public IPv4 used for `sslip.io` domains |
| `VSL_CADDY_ADMIN` | `http://127.0.0.1:2019` | Caddy Admin API base URL |

---

## `linux-arm64` Static Build

Build a standalone static ELF binary via `static-php-cli` (`spc`) on EC2 ARM64:

```bash
./build-arm64.sh
```

## Tests

```bash
composer install
./vendor/bin/pest
```

---

## Art

| Icon | Dark Mark | Light Mark |
| :---: | :---: | :---: |
| <img src="art/vsl-icon.png" alt="vsl icon" width="84"> | <img src="art/vsl-mark.png" alt="vsl dark mark" width="84"> | <img src="art/vsl-mark-light.png" alt="vsl light mark" width="84"> |

Regenerate SVGs and 1024×1024 PNGs:

```bash
python3 art/generate_logo.py
```
