#!/usr/bin/env bash
# ==============================================================================
# IT Toolbox CLI - Standalone Native Binary Builder (Zero-Dependency)
# ==============================================================================
# Packages the compiled Laravel Zero PHAR ('builds/it-toolbox') together with
# the pre-compiled PHP 8.3 'micro.sfx' self-extracting runtime from
# crazywhalecc/static-php-cli, producing zero-dependency native executables:
#   - builds/it-toolbox-linux-x64
#   - builds/it-toolbox-windows-x64.exe
#
# Usage:
#   ./scripts/build-standalone.sh [version]
# ==============================================================================

set -euo pipefail

VERSION="${1:-1.0.0}"
BUILD_DIR="builds"
CACHE_DIR=".spc-cache"
PHAR_FILE="${BUILD_DIR}/it-toolbox"

echo "==> [1/4] Building portable PHAR (${PHAR_FILE}) v${VERSION}..."
mkdir -p "${BUILD_DIR}" "${CACHE_DIR}"
php -d phar.readonly=0 it-toolbox app:build it-toolbox --build-version="${VERSION}" --no-interaction

if [ ! -f "${PHAR_FILE}" ]; then
    echo "Error: PHAR file was not generated at ${PHAR_FILE}." >&2
    exit 1
fi

echo "==> [2/4] Downloading PHP 8.3 micro.sfx runtimes (Linux x64 & Windows x64)..."
LINUX_URL="https://dl.static-php.dev/static-php-cli/common/php-8.3.14-micro-linux-x86_64.tar.gz"
WIN_URL="https://dl.static-php.dev/static-php-cli/windows/spc-max/php-8.3.13-micro-win.zip"

curl -fL --retry 3 -o "${CACHE_DIR}/micro-linux.tar.gz" "${LINUX_URL}"
tar -xzf "${CACHE_DIR}/micro-linux.tar.gz" -C "${CACHE_DIR}"
mv "${CACHE_DIR}/micro.sfx" "${CACHE_DIR}/micro-linux.sfx"

curl -fL --retry 3 -o "${CACHE_DIR}/micro-win.zip" "${WIN_URL}"
unzip -q -o "${CACHE_DIR}/micro-win.zip" -d "${CACHE_DIR}"
mv "${CACHE_DIR}/micro.sfx" "${CACHE_DIR}/micro-windows.sfx"

echo "==> [3/4] Combining micro.sfx + PHAR with SHA512 signature recalculation..."
php scripts/combine-micro.php "${CACHE_DIR}/micro-linux.sfx" "${PHAR_FILE}" "${BUILD_DIR}/it-toolbox-linux-x64"
php scripts/combine-micro.php "${CACHE_DIR}/micro-windows.sfx" "${PHAR_FILE}" "${BUILD_DIR}/it-toolbox-windows-x64.exe"

echo "==> [4/4] Cleaning temporary build cache..."
rm -rf "${CACHE_DIR}"

echo ""
echo "Standalone native binaries built successfully:"
ls -lh "${BUILD_DIR}/it-toolbox" "${BUILD_DIR}/it-toolbox-linux-x64" "${BUILD_DIR}/it-toolbox-windows-x64.exe"
