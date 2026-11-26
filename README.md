# `vsl`

Sub-second local `.test` HTTPS preview environments for Git worktrees — powered by [Yerd](https://yerd.app/), Laravel Zero, and Bun + Vite HMR.

---

## Install

Install `vsl` globally to `/usr/local/bin/vsl`:

```bash
curl -fsSL https://raw.githubusercontent.com/novislab/vsl/main/install.sh | bash
```

Install `vsl` and enable the `systemd` reboot service (`vsl-resurrect.service`):

```bash
curl -fsSL https://raw.githubusercontent.com/novislab/vsl/main/install.sh | VSL_WITH_SERVICE=1 bash
```

Verify from anywhere:

```bash
vsl --version
# vsl 1.3
```

> **Versioning**: `install.sh` builds using the latest git tag (e.g. `v1.3`) or falls back to `'version'` in [`config/app.php`](config/app.php) (never a raw commit hash).

---

## Overview

When **T3 Code** creates a worktree, `vsl up` prepares a live local `.test` preview in [Yerd](https://yerd.app/) without blocking on dependencies, builds, or migrations:

- **Hydration**: hardlinks `vendor/` and `node_modules/` (`cp -al`), deep-copies `storage/`, and rewrites `.env` (`APP_URL` + isolated `DB_DATABASE`).
- **Yerd `.test` + HTTPS**: runs `yerd link <slug> <worktree>` and `yerd secure <slug>` to serve `https://<slug>.test` directly through Yerd's PHP-FPM daemon with its trusted local CA, automatically inheriting the parent site's pinned PHP version.
- **Vite HMR**: wraps your `vite.config.{ts,js,mts,mjs}` in `~/.vsl/vite/`, spawns `bun dev` in the background, and attaches Yerd path proxy rules (`yerd proxy add`) for Vite assets and `wss://` HMR on the same `.test` domain.
- **Database**: detects `mysql`, `mariadb`, `pgsql`, or `sqlite` in `.env`, creates an isolated database, runs `migrate --force --seed` out of the terminal (`~/.vsl/logs/<slug>-db.log`), and drops it in the background on `vsl down`.
- **Persistence**: stores active state atomically (`flock`) in `~/.vsl/state.json` for `vsl resurrect` across reboots.

---

## Commands

```bash
vsl up              # Hydrate worktree, link & secure <slug>.test in Yerd, start Vite + DB job
vsl down            # Unlink Yerd site & proxies, stop Vite, drop isolated DB (keeps worktree on disk)
vsl list            # Show tracked previews, Yerd LINKED/UNLINKED status, PHP version, Vite, and DB
vsl resurrect       # Restore saved previews after reboot (prunes missing dirs, re-links & restarts Vite)
vsl install-service # Install and enable /etc/systemd/system/vsl-resurrect.service
```

### Flags

| Command | Options |
| --- | --- |
| `vsl up` | `--path=`, `--no-qr`, `--no-db`, `--no-seed`, `--no-vite`, `--no-https`, `--php=`, `--json` |
| `vsl down` | `[target]`, `--path=`, `--keep-db`, `--json` |
| `vsl list` | `--prune`, `--json` |
| `vsl resurrect` | `--json` |
| `vsl install-service` | `--user=`, `--binary=`, `--dry-run` |

---

## Local Setup

### Yerd ([yerd.app](https://yerd.app/))

Ensure the Yerd daemon is running (`yerd status`) with `.test` DNS and CA trust enabled.

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
| `VSL_YERD_BIN` | Auto-detected | Path to the `yerd` CLI binary |
| `VSL_YERD_TLD` | From `yerd status` (`test`) | Local TLD override for preview domains |

---

## Tests

```bash
composer install
./vendor/bin/pest
```
