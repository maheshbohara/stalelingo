#!/usr/bin/env bash
# Runs the official Plugin Check against the packaged plugin (dist/translation-drift),
# i.e. exactly what ships to WordPress.org. Runs inside the `wpcli` container.
#
# Usage: plugin-check.sh [extra `wp plugin check` args]
set -euo pipefail

cd /var/www/html
pkg="wp-content/plugins/translation-drift/dist/translation-drift"

if [[ ! -f "$pkg/translation-drift.php" ]]; then
	echo "Package not found. Run 'make zip' first." >&2
	exit 1
fi

if ! wp plugin is-installed plugin-check; then
	wp plugin install plugin-check --quiet
fi
wp plugin activate plugin-check --quiet

# Fails (non-zero) on any error. Warnings are printed and reviewed; pass --ignore-warnings to hide them.
out=$(wp plugin check "$(pwd)/$pkg" --slug=translation-drift --format=json --include-experimental "$@" 2>&1) || true
echo "$out"

errors=$(printf '%s' "$out" | php -r '
	$errors = 0;
	foreach ( preg_split( "/\R/", stream_get_contents( STDIN ) ) as $line ) {
		$rows = json_decode( $line, true );
		if ( ! is_array( $rows ) ) { continue; }
		foreach ( $rows as $row ) { if ( "ERROR" === ( $row["type"] ?? "" ) ) { $errors++; } }
	}
	echo $errors;')

if [[ "$errors" != "0" ]]; then
	echo "Plugin Check: $errors error(s)." >&2
	exit 1
fi
if printf '%s' "$out" | grep -q "Checks complete. No errors found."; then
	echo "Plugin Check: no errors or warnings."
else
	echo "Plugin Check: no errors."
fi
