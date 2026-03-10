#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="burrow-wordpress-plugin"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
STAGE_DIR="${DIST_DIR}/${PLUGIN_SLUG}"
ZIP_PATH="${DIST_DIR}/${PLUGIN_SLUG}.zip"

cd "${ROOT_DIR}"

composer install --no-dev --optimize-autoloader

rm -rf "${STAGE_DIR}" "${ZIP_PATH}"
mkdir -p "${STAGE_DIR}"

rsync -a \
  --exclude ".git/" \
  --exclude ".github/" \
  --exclude ".cursor/" \
  --exclude ".idea/" \
  --exclude "dist/" \
  --exclude "scripts/" \
  --exclude "tests/" \
  --exclude ".phpunit.result.cache" \
  --exclude ".DS_Store" \
  "${ROOT_DIR}/" "${STAGE_DIR}/"

( cd "${DIST_DIR}" && zip -qr "${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}" )

echo "Built release zip: ${ZIP_PATH}"
