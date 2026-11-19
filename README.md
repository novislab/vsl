# `vsl` — Instant Git Worktree HTTPS Previews (`novislab.dev`)

Standalone CLI built with **Laravel Zero** + **Laravel Prompts** that turns every Git worktree created by **T3 Code** into a live `https://<slug>.<dashed-ipv4>.sslip.io` preview environment in sub-second time.

## Features

- **Zero-wait parallel hydration**: hardlinks `vendor/` & `node_modules/` (`cp -al`), deep-copies `storage/`, rewrites `.env` (`APP_URL` + isolated `DB_DATABASE`), and launches Octane, `bun dev`, and DB provisioning concurrently.
- **Instant dev reloading**: runs Laravel Octane (FrankenPHP) with `--watch` (when `chokidar` is installed) or `--max-requests=1` so PHP changes appear immediately without manual `octane:reload`.
- **Background `bun dev` + HTTPS Vite HMR**: wraps your `vite.config.{ts,js,mts,mjs}` in `~/.vsl/vite/` without dirtying Git and proxies Vite assets + `wss://` HMR WebSockets through Caddy on the same `https://` preview domain.
- **Background DB isolation**: scans `.env` for `mysql`, `mariadb`, `pgsql`, or `sqlite`, creates a per-worktree database, runs `migrate --force --seed` out of the terminal, and drops it in the background on `vsl down`.
- **Live `sslip.io` HTTPS + Terminal QR Code**: generates instant Let's Encrypt HTTPS certificates via Caddy Admin API (`127.0.0.1:2019`) and renders a scannable Unicode half-block QR code.
- **Reboot persistence**: persists active worktrees atomically (`flock`) in `~/.vsl/state.json` with `vsl resurrect` and `vsl install-service` (`systemd`).
- **Hook-safe CLI UI**: automatically disables raw `stty` / spinner animations when non-TTY (`!stream_isatty(STDOUT)`) or `--json` is passed.

---

## Quick Install (Run Anywhere)

```bash
curl -fsSL https://raw.githubusercontent.com/novislab/vsl/main/install.sh | bash
```

To also install and enable `vsl-resurrect.service` (`systemd`):

```bash
curl -fsSL https://raw.githubusercontent.com/novislab/vsl/main/install.sh | VSL_WITH_SERVICE=1 bash
```

---

## Commands

```bash
vsl up [--path=] [--no-qr] [--no-db] [--no-seed] [--no-vite] [--prod] [--json]
vsl down [target] [--path=] [--keep-db] [--json]
vsl list [--json] [--prune]
vsl resurrect [--json]
vsl install-service [--user=] [--binary=] [--dry-run]
```

## T3 Code Hooks & Caddy Bootstrap

- Caddy config: [`deploy/Caddyfile`](deploy/Caddyfile) → `/etc/caddy/Caddyfile`
- T3 Code hooks: [`deploy/t3-hooks.json`](deploy/t3-hooks.json) (`post-create` → `vsl up`, `post-remove` → `vsl down`)

## Standalone Static `linux-arm64` Binary (`spc`)

```bash
./build-arm64.sh
```

Builds `builds/vsl` PHAR, compiles PHP 8.3 `micro` SAPI via `static-php-cli` (`linux-aarch64`), combines into `./dist/vsl-linux-arm64`, installs to `/usr/local/bin/vsl`, and runs `/usr/local/bin/vsl install-service`.

## Tests

```bash
./vendor/bin/pest
```
