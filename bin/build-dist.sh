#!/usr/bin/env bash
#
# Builds the WordPress.org distribution of the plugin:
#
#   wordpress/build/dist/lunixi/            the plugin folder (SVN trunk content)
#   wordpress/build/dist/lunixi-x.y.z.zip   the same folder, zipped for upload
#
# The plugin's tracked files are copied without development files, and the
# Lunixi PHP SDK is installed from Packagist into vendor/ (no dev packages,
# authoritative class map). Run from anywhere: wordpress/bin/build-dist.sh

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="$(git -C "$PLUGIN_DIR" rev-parse --show-toplevel)"
SLUG="lunixi"
VERSION="$(sed -nE 's/^ \* Version: +([0-9.]+)$/\1/p' "${PLUGIN_DIR}/lunixi.php")"
[[ -n "$VERSION" ]] || { echo "build-dist: no Version in lunixi.php" >&2; exit 1; }

DIST="${PLUGIN_DIR}/build/dist"
OUT="${DIST}/${SLUG}"
rm -rf "$DIST"
mkdir -p "$OUT"

# Tracked files only, without what only development needs.
git -C "$ROOT" ls-files -z -- wordpress \
    | rsync -a --from0 --files-from=- "$ROOT/" "${DIST}/stage/"
rsync -a \
    --exclude '/tests/' \
    --exclude '/bin/' \
    --exclude '/phpunit.xml.dist' \
    --exclude '/scoper.inc.php' \
    --exclude '/README.md' \
    --exclude '/.gitignore' \
    --exclude '/composer.lock' \
    "${DIST}/stage/wordpress/" "${OUT}/"
rm -rf "${DIST}/stage"

# The SDK comes from Packagist, not from the monorepo path repository.
php -r '
    $file = $argv[1];
    $json = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    unset($json["repositories"], $json["scripts"], $json["require-dev"]);
    file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
' "${OUT}/composer.json"
composer install --working-dir="$OUT" --no-dev --no-interaction --no-progress \
    --prefer-dist --optimize-autoloader --classmap-authoritative --quiet
rm -f "${OUT}/composer.lock"

(cd "$DIST" && zip -qr "${SLUG}-${VERSION}.zip" "$SLUG")
echo "Built ${DIST}/${SLUG}-${VERSION}.zip ($(du -h "${DIST}/${SLUG}-${VERSION}.zip" | cut -f1))"
