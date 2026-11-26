#!/usr/bin/env bash
set -euo pipefail

# Build a standalone static linux-arm64 (aarch64) ELF binary for vsl
# using Laravel Zero PHAR + Manticore / static-php-cli (spc) micro SAPI.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

VERSION="${VSL_VERSION:-1.3}"
PHP_VERSION="${VSL_PHP_VERSION:-8.3}"
EXTENSIONS="pcntl,posix,curl,openssl,mbstring,tokenizer,ctype,filter,json,sockets,phar,zlib"
SPC_DIR="${ROOT_DIR}/.spc"
DIST_DIR="${ROOT_DIR}/dist"
OUT_BIN="${DIST_DIR}/vsl-linux-arm64"
INSTALL_PATH="/usr/local/bin/vsl"
SERVICE_USER="${VSL_SERVICE_USER:-$(id -un)}"

ARCH="$(uname -m)"
if [[ "$ARCH" != "aarch64" && "$ARCH" != "arm64" ]]; then
  echo "Warning: expected linux-arm64 (aarch64) host, detected '${ARCH}'." >&2
fi

echo "==> [1/6] Installing production Composer dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> [2/6] Building Laravel Zero PHAR (builds/vsl)..."
php vsl app:build vsl --build-version="${VERSION}" --no-interaction

if [[ ! -f "${ROOT_DIR}/builds/vsl" ]]; then
  echo "Error: expected PHAR at ${ROOT_DIR}/builds/vsl" >&2
  exit 1
fi

mkdir -p "${SPC_DIR}" "${DIST_DIR}"

if [[ ! -x "${SPC_DIR}/spc" ]]; then
  echo "==> [3/6] Downloading static-php-cli (spc) for linux-aarch64..."
  SPC_URL="https://dl.static-php.dev/static-php-cli/spc-bin/nightly/spc-linux-aarch64.tar.gz"
  curl -fsSL "${SPC_URL}" -o "${SPC_DIR}/spc.tar.gz"
  tar -xzf "${SPC_DIR}/spc.tar.gz" -C "${SPC_DIR}"
  rm -f "${SPC_DIR}/spc.tar.gz"
  chmod +x "${SPC_DIR}/spc"
else
  echo "==> [3/6] Using cached spc binary at ${SPC_DIR}/spc"
fi

pushd "${SPC_DIR}" >/dev/null

echo "==> [4/6] Running spc doctor & downloading PHP ${PHP_VERSION} + extensions..."
./spc doctor --auto-fix
./spc download --with-php="${PHP_VERSION}" --for-extensions="${EXTENSIONS}"

echo "==> [5/6] Compiling static PHP micro SAPI (${EXTENSIONS})..."
./spc build --build-micro "${EXTENSIONS}"

echo "==> [6/6] Combining builds/vsl into ${OUT_BIN}..."
./spc micro:combine "${ROOT_DIR}/builds/vsl" -O "${OUT_BIN}"
chmod 0755 "${OUT_BIN}"

popd >/dev/null

echo "==> Restoring dev dependencies locally..."
composer install --no-interaction >/dev/null 2>&1 || true

echo "==> Verifying standalone binary..."
"${OUT_BIN}" --version

echo "==> Installing binary to ${INSTALL_PATH}..."
if [[ "$(id -u)" -eq 0 ]]; then
  install -m 0755 "${OUT_BIN}" "${INSTALL_PATH}"
else
  sudo install -m 0755 "${OUT_BIN}" "${INSTALL_PATH}"
fi

echo "==> Installing and enabling systemd resurrect service..."
"${INSTALL_PATH}" install-service --user="${SERVICE_USER}" --binary="${INSTALL_PATH}"

echo "Done! Standalone binary installed at ${INSTALL_PATH}"
