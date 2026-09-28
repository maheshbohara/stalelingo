#!/usr/bin/env bash
# Builds the development site. Runs inside the `wpcli` container (see `make setup`).
#
#   PROVIDER=polylang (default) -> Polylang with EN (default), FR and ES.
#   PROVIDER=wpml               -> WPML from ./private/wpml/*.zip or $WPML_ZIP_PATH, when available.
set -euo pipefail

PROVIDER="${PROVIDER:-polylang}"
WP_PORT="${WP_PORT:-8080}"
URL="http://localhost:${WP_PORT}"
PLUGIN_DIR="wp-content/plugins/translation-drift"

wp() { command wp --quiet "$@"; }

echo "==> Waiting for WordPress files"
for _ in $(seq 1 60); do
	[[ -f wp-config.php ]] && break
	sleep 2
done
[[ -f wp-config.php ]] || { echo "wp-config.php not found; is the wordpress service up?" >&2; exit 1; }

if [[ ! -f "$PLUGIN_DIR/vendor/autoload.php" ]]; then
	echo "vendor/autoload.php missing. Run 'make composer ARGS=install' first." >&2
	exit 1
fi

echo "==> Installing WordPress"
if ! wp core is-installed 2>/dev/null; then
	wp core install --url="$URL" --title="Translation Drift Dev" \
		--admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email
fi
wp rewrite structure '/%postname%/' --hard
wp option update timezone_string 'UTC'

echo "==> Installing supporting plugins"
wp plugin install advanced-custom-fields elementor --activate

case "$PROVIDER" in
	polylang)
		wp plugin is-active sitepress-multilingual-cms 2>/dev/null && wp plugin deactivate sitepress-multilingual-cms
		wp plugin install polylang --activate
		;;
	wpml)
		wp plugin is-active polylang 2>/dev/null && wp plugin deactivate polylang
		zips=()
		if [[ -n "${WPML_ZIP_PATH:-}" && -f "${WPML_ZIP_PATH}" ]]; then zips+=("$WPML_ZIP_PATH"); fi
		shopt -s nullglob
		for z in /private/wpml/*.zip; do zips+=("$z"); done
		shopt -u nullglob
		if [[ ${#zips[@]} -eq 0 ]]; then
			echo "WPML is commercial and no zips were found in ./private/wpml/ or WPML_ZIP_PATH." >&2
			echo "Skipping WPML setup. WPML integration tests will be skipped; unit tests use mocks." >&2
			exit 0
		fi
		for z in "${zips[@]}"; do wp plugin install "$z" --force; done
		wp plugin activate sitepress-multilingual-cms
		wp plugin activate wpml-string-translation 2>/dev/null || true
		;;
	*)
		echo "Unknown PROVIDER '$PROVIDER' (expected polylang or wpml)." >&2
		exit 1
		;;
esac

echo "==> Activating Translation Drift"
wp plugin activate translation-drift

echo "==> Configuring languages (EN default, FR, ES)"
wp eval-file "$PLUGIN_DIR/bin/dev/languages.php" "$PROVIDER"

echo "==> Creating translator users"
for lang in fr es; do
	user="translator-$lang"
	if ! wp user get "$user" --field=ID >/dev/null 2>&1; then
		wp user create "$user" "$user@example.test" --role=author --user_pass=password --display_name="Translator ${lang^^}"
	fi
done
if ! wp user get editor --field=ID >/dev/null 2>&1; then
	wp user create editor editor@example.test --role=editor --user_pass=password
fi

echo "==> Seeding content"
wp eval-file "$PLUGIN_DIR/bin/dev/seed.php" "$PROVIDER" "${SEED_N:-6}"

# Runs due WP-Cron events until no plugin job is left.
run_jobs() {
	for _ in $(seq 1 500); do
		command wp cron event run --due-now --quiet >/dev/null 2>&1 || true
		pending=$(command wp cron event list --fields=hook --format=csv 2>/dev/null | grep -cE '^tdrift_(baseline|recalc)' || true)
		[[ "$pending" == "0" ]] && return 0
	done
	echo "Jobs still pending after 500 runs." >&2
	return 1
}

echo "==> Building the baseline"
wp eval 'TranslationDrift\Plugin::container()->baseline()->start_baseline();'
run_jobs

echo "==> Editing some sources so their translations drift"
wp eval-file "$PLUGIN_DIR/bin/dev/make-drift.php"
run_jobs

command wp eval 'foreach ( TranslationDrift\Plugin::container()->sync_repository()->count_by_status() as $s => $n ) { WP_CLI::log( sprintf( "  %-10s %d", $s, $n ) ); }'

echo
echo "Ready: $URL/wp-admin (admin / password). Mailpit: http://localhost:${MAILPIT_PORT:-8025}"
