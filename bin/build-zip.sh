#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="burrow"
VERSION=$(sed -n 's/.*Version:[[:space:]]*\([0-9.]*\).*/\1/p' burrow.php | head -1)
DIST_DIR="dist"
BUILD_DIR="${DIST_DIR}/${PLUGIN_SLUG}"
ZIP_FILE="${DIST_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"

echo "Building ${PLUGIN_SLUG} v${VERSION}..."

rm -rf "${DIST_DIR}"
mkdir -p "${BUILD_DIR}"

composer install --no-dev --optimize-autoloader --no-interaction --quiet 2>/dev/null || {
    echo "Warning: composer install --no-dev failed. Using existing vendor/."
}

INCLUDE_FILES=(
    burrow.php
    readme.txt
    LICENSE
    uninstall.php
)

INCLUDE_DIRS=(
    admin
    includes
    languages
    public
    src
    vendor
)

for f in "${INCLUDE_FILES[@]}"; do
    [ -f "$f" ] && cp "$f" "${BUILD_DIR}/"
done

for d in "${INCLUDE_DIRS[@]}"; do
    [ -d "$d" ] && cp -r "$d" "${BUILD_DIR}/"
done

# Remove dev/build artifacts that shouldn't ship
find "${BUILD_DIR}" -name ".DS_Store" -delete 2>/dev/null || true
find "${BUILD_DIR}" -name ".gitignore" -delete 2>/dev/null || true
find "${BUILD_DIR}" -name ".gitattributes" -delete 2>/dev/null || true
find "${BUILD_DIR}" -name "*.md" -not -name "README.md" -delete 2>/dev/null || true
rm -rf "${BUILD_DIR}/vendor/phpunit" 2>/dev/null || true
rm -rf "${BUILD_DIR}/vendor/bin" 2>/dev/null || true

# Strip nested vendor dirs from path-based packages (local SDK dev copies)
find "${BUILD_DIR}/vendor" -mindepth 3 -maxdepth 3 -type d -name "vendor" -exec rm -rf {} + 2>/dev/null || true

# Strip test directories and dev files from vendor packages
find "${BUILD_DIR}/vendor" -type d -name "tests" -exec rm -rf {} + 2>/dev/null || true
find "${BUILD_DIR}/vendor" -type d -name "test" -exec rm -rf {} + 2>/dev/null || true
find "${BUILD_DIR}/vendor" -type d -name ".git" -exec rm -rf {} + 2>/dev/null || true
find "${BUILD_DIR}/vendor" -name "phpunit.xml" -delete 2>/dev/null || true
find "${BUILD_DIR}/vendor" -name "phpunit.xml.dist" -delete 2>/dev/null || true
find "${BUILD_DIR}/vendor" -name ".travis.yml" -delete 2>/dev/null || true
find "${BUILD_DIR}/vendor" -name ".phpunit.result.cache" -delete 2>/dev/null || true
find "${BUILD_DIR}/vendor" -name ".github" -type d -exec rm -rf {} + 2>/dev/null || true

cd "${DIST_DIR}"
zip -qr "../${ZIP_FILE}" "${PLUGIN_SLUG}"
cd ..

rm -rf "${BUILD_DIR}"

SIZE=$(du -h "${ZIP_FILE}" | cut -f1)
echo "Created ${ZIP_FILE} (${SIZE})"
echo ""
echo "To create a GitHub release:"
echo "  git tag v${VERSION}"
echo "  git push origin v${VERSION}"
echo "  gh release create v${VERSION} ${ZIP_FILE} --title \"${PLUGIN_SLUG} v${VERSION}\" --notes \"Release v${VERSION}\""
