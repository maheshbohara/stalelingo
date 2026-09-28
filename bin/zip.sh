#!/usr/bin/env bash
# Builds the WordPress.org package in dist/. Runs inside the `php` container
# after `npm run build` (see `make zip`).
#
# Output: dist/translation-drift/ (staging, used by `make plugin-check`) and
#         dist/translation-drift-<version>.zip
set -euo pipefail

cd "$(dirname "$0")/.."
root="$(pwd)"
slug="translation-drift"
stage="$root/dist/$slug"

if [[ ! -f build/dashboard/index.js ]]; then
	echo "build/ is missing. Run 'make build' first." >&2
	exit 1
fi

version=$(sed -n "s/^ \* Version: *//p" "$slug.php" | tr -d '[:space:]')

rm -rf "$stage" "$root/dist/$slug-"*.zip
mkdir -p "$stage"

# Copy everything not listed in .distignore.
rsync -a --delete --exclude-from=.distignore ./ "$stage/"

# Production autoloader only: no dev dependencies in vendor/.
composer install --working-dir="$stage" --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
rm -f "$stage/composer.lock"
cp composer.lock "$stage/composer.lock"

(cd "$root/dist" && zip -qr "$slug-$version.zip" "$slug")

echo "Built dist/$slug-$version.zip"
unzip -l "$root/dist/$slug-$version.zip" | tail -1
