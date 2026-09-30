#!/usr/bin/env bash
# Builds the WordPress.org package in dist/. Runs inside the `php` container
# after `npm run build` (see `make zip`).
#
# Output: dist/stalelingo/ (staging, used by `make plugin-check`) and
#         dist/stalelingo-<version>.zip
set -euo pipefail

cd "$(dirname "$0")/.."
root="$(pwd)"
slug="stalelingo"
stage="$root/dist/$slug"

if [[ ! -f build/dashboard/index.js ]]; then
	echo "build/ is missing. Run 'make build' first." >&2
	exit 1
fi

version=$(sed -n "s/^ \* Version: *//p" "$slug.php" | tr -d '[:space:]')

rm -rf "$stage" "$root/dist/$slug-"*.zip
mkdir -p "$stage"

# Allowlist: only these paths ship. Anything else in the checkout (tool output,
# scratch files, dotfolders) stays out without having to be listed anywhere.
include=(
	stalelingo.php
	uninstall.php
	readme.txt
	includes
	languages
	build
	# Human-readable source of build/ (WordPress.org guideline 4). Development
	# files (build configs, Composer and npm manifests, project docs) stay out.
	src
)
for path in "${include[@]}"; do
	rsync -aR --exclude='*.map' --exclude='.DS_Store' "./$path" "$stage/"
done

# The generated build/*.asset.php files only return an array; give them the same
# direct-access guard as every other PHP file anyway.
for asset in "$stage"/build/*/*.asset.php; do
	sed -i 's/^<?php /<?php defined( '"'"'ABSPATH'"'"' ) || exit; /' "$asset"
done

# Production autoloader only: no dev dependencies in vendor/. composer.json stays,
# because Plugin Check and the review team expect it next to vendor/; the lock
# file is only needed for the install.
cp composer.json composer.lock "$stage/"
composer install --working-dir="$stage" --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
rm -f "$stage/composer.lock"

# Fail on anything the directory review would question: dotfiles, or file types
# outside what the plugin needs (source, built assets, the .pot and licences).
unexpected=$(cd "$stage" && find . -type f \( -name '.*' -o ! \( -name '*.php' -o -name '*.js' -o -name '*.css' -o -name '*.json' -o -name '*.txt' -o -name '*.md' -o -name '*.pot' -o -name '*.ts' -o -name '*.tsx' -o -name '*.scss' -o -name 'LICENSE' \) \) | sort)
if [[ -n "$unexpected" ]]; then
	echo "Unexpected files in the package:" >&2
	echo "$unexpected" >&2
	exit 1
fi

(cd "$root/dist" && zip -qr "$slug-$version.zip" "$slug")

echo "Built dist/$slug-$version.zip"
unzip -l "$root/dist/$slug-$version.zip" | tail -1
