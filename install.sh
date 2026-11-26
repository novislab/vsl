#!/usr/bin/env bash
set -euo pipefail

# VSL (novislab.dev) one-line installer
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/novislab/vsl/main/install.sh | bash
#
# Environment overrides:
#   VSL_REPO         GitHub owner/repo (default: novislab/vsl)
#   VSL_BRANCH       Git branch to build from when no release binary matches (default: main)
#   VSL_INSTALL_DIR  Target bin directory (default: /usr/local/bin)
#   VSL_WITH_SERVICE Set to 1 to also run `vsl install-service` after installing

VSL_REPO="${VSL_REPO:-novislab/vsl}"
VSL_BRANCH="${VSL_BRANCH:-main}"
VSL_INSTALL_DIR="${VSL_INSTALL_DIR:-/usr/local/bin}"
VSL_BIN="${VSL_INSTALL_DIR}/vsl"
VSL_WITH_SERVICE="${VSL_WITH_SERVICE:-0}"

log() {
  printf '\033[1;36m==>\033[0m %s\n' "$*"
}

warn() {
  printf '\033[1;33mWarning:\033[0m %s\n' "$*" >&2
}

die() {
  printf '\033[1;31mError:\033[0m %s\n' "$*" >&2
  exit 1
}

install_binary() {
  local src="$1"
  mkdir -p "$VSL_INSTALL_DIR" 2>/dev/null || sudo mkdir -p "$VSL_INSTALL_DIR"
  if [[ -w "$VSL_INSTALL_DIR" ]]; then
    install -m 0755 "$src" "$VSL_BIN"
  else
    sudo install -m 0755 "$src" "$VSL_BIN"
  fi
}

ARCH_RAW="$(uname -m)"
case "$ARCH_RAW" in
  aarch64|arm64) ARCH="arm64" ;;
  x86_64|amd64)  ARCH="x86_64" ;;
  *)             ARCH="$ARCH_RAW" ;;
esac

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

RELEASE_BASE="https://github.com/${VSL_REPO}/releases/latest/download"
INSTALLED_FROM_RELEASE=0

if command -v curl >/dev/null 2>&1; then
  for ASSET in "vsl" "vsl-linux-${ARCH}"; do
    RELEASE_URL="${RELEASE_BASE}/${ASSET}"
    log "Checking for prebuilt release (${RELEASE_URL})..."
    if curl -fsSL -o "${TMP_DIR}/vsl" "$RELEASE_URL" 2>/dev/null; then
      chmod +x "${TMP_DIR}/vsl"
      if "${TMP_DIR}/vsl" --version >/dev/null 2>&1; then
        log "Installing prebuilt vsl (${ASSET}) to ${VSL_BIN}..."
        install_binary "${TMP_DIR}/vsl"
        INSTALLED_FROM_RELEASE=1
        break
      else
        warn "Downloaded release asset (${ASSET}) failed verification; trying next option."
        rm -f "${TMP_DIR}/vsl"
      fi
    fi
  done
fi

if [[ "$INSTALLED_FROM_RELEASE" != "1" ]]; then
  command -v git >/dev/null 2>&1 || die "git is required to install from source."
  command -v php >/dev/null 2>&1 || die "php 8.3+ is required to build vsl PHAR."
  command -v composer >/dev/null 2>&1 || die "composer is required to build vsl PHAR."

  log "Cloning https://github.com/${VSL_REPO}.git (${VSL_BRANCH})..."
  git clone --depth 1 --branch "$VSL_BRANCH" "https://github.com/${VSL_REPO}.git" "${TMP_DIR}/src" >/dev/null 2>&1

  pushd "${TMP_DIR}/src" >/dev/null
  log "Installing Composer dependencies..."
  composer install --no-dev --optimize-autoloader --no-interaction --quiet

  VERSION="$(git describe --tags --abbrev=0 2>/dev/null || true)"
  VERSION="${VERSION#v}"
  if [[ -z "$VERSION" ]]; then
    VERSION="$(php -r '$c = require "config/app.php"; echo is_string($c["version"] ?? null) ? $c["version"] : "1.3";')"
  fi
  log "Building standalone vsl PHAR (${VERSION})..."
  php vsl app:build vsl --build-version="${VERSION}" --no-interaction >/dev/null

  [[ -f "${TMP_DIR}/src/builds/vsl" ]] || die "PHAR build did not produce builds/vsl"
  log "Installing vsl to ${VSL_BIN}..."
  install_binary "${TMP_DIR}/src/builds/vsl"
  popd >/dev/null
fi

if [[ ":${PATH}:" != *":${VSL_INSTALL_DIR}:"* ]]; then
  warn "${VSL_INSTALL_DIR} is not in your PATH. Add: export PATH=\"${VSL_INSTALL_DIR}:\$PATH\""
fi

log "Installed: $(command -v vsl || echo "$VSL_BIN") ($("$VSL_BIN" --version))"

if [[ "$VSL_WITH_SERVICE" == "1" ]] && command -v systemctl >/dev/null 2>&1; then
  log "Installing systemd resurrect service..."
  "$VSL_BIN" install-service --binary="$VSL_BIN"
fi

log "Done! Run 'vsl' from anywhere."
