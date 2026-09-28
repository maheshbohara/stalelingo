#!/usr/bin/env bash
# Installs the WordPress core test library and a matching WordPress core into
# $WP_TESTS_DIR and $WP_CORE_DIR (a Docker volume), plus the plugins the
# integration suite loads. Idempotent: skips work already done for the version.
set -euo pipefail

WP_VERSION="${WP_VERSION:-latest}"
WP_TESTS_DIR="${WP_TESTS_DIR:-/opt/wp-tests/lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/opt/wp-tests/core}"

if [[ "$WP_VERSION" == "latest" || "$WP_VERSION" == "latest-1" ]]; then
	offers=$(curl -fsSL https://api.wordpress.org/core/version-check/1.7/)
	# Offers are sorted newest first; collect distinct major.minor branches.
	versions=$(printf '%s' "$offers" | php -r '
		$d = json_decode( stream_get_contents( STDIN ), true );
		$seen = array();
		foreach ( $d["offers"] as $o ) {
			$branch = implode( ".", array_slice( explode( ".", $o["version"] ), 0, 2 ) );
			if ( ! isset( $seen[ $branch ] ) ) { $seen[ $branch ] = $o["version"]; }
		}
		echo implode( " ", array_values( $seen ) );')
	read -r -a list <<<"$versions"
	if [[ "$WP_VERSION" == "latest" ]]; then WP_VERSION="${list[0]}"; else WP_VERSION="${list[1]}"; fi
fi

marker="$WP_TESTS_DIR/.version"
if [[ -f "$marker" && "$(cat "$marker")" == "$WP_VERSION" && -f "$WP_CORE_DIR/wp-settings.php" ]]; then
	echo "WordPress test suite $WP_VERSION already installed."
else
	echo "Installing WordPress $WP_VERSION and its test suite..."
	rm -rf "$WP_TESTS_DIR" "$WP_CORE_DIR"
	mkdir -p "$WP_TESTS_DIR" "$WP_CORE_DIR"

	curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" | tar -xz --strip-components=1 -C "$WP_CORE_DIR"

	tag="tags/${WP_VERSION}"
	# x.y.0 releases are tagged as x.y.
	[[ "$WP_VERSION" =~ ^[0-9]+\.[0-9]+\.0$ ]] && tag="tags/${WP_VERSION%.0}"
	svn export -q --ignore-externals "https://develop.svn.wordpress.org/${tag}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
	svn export -q --ignore-externals "https://develop.svn.wordpress.org/${tag}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
	svn export -q "https://develop.svn.wordpress.org/${tag}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
	echo "$WP_VERSION" >"$marker"
fi

config="$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}/':" "$config"
sed -i "s:__DIR__ . '/src/':'${WP_CORE_DIR}/':" "$config"
sed -i "s/youremptytestdbnamehere/${WP_TESTS_DB_NAME:-wordpress_test}/" "$config"
sed -i "s/yourusernamehere/${WP_TESTS_DB_USER:-wordpress}/" "$config"
sed -i "s/yourpasswordhere/${WP_TESTS_DB_PASS:-wordpress}/" "$config"
sed -i "s|localhost|${WP_TESTS_DB_HOST:-db}|" "$config"

# Plugins the integration suite loads. WPML is commercial and only installed from private zips.
install_org_plugin() {
	local slug="$1"
	if [[ ! -d "$WP_CORE_DIR/wp-content/plugins/$slug" ]]; then
		echo "Installing $slug for the test suite..."
		curl -fsSL "https://downloads.wordpress.org/plugin/${slug}.latest-stable.zip" -o "/tmp/${slug}.zip"
		unzip -q "/tmp/${slug}.zip" -d "$WP_CORE_DIR/wp-content/plugins/"
		rm "/tmp/${slug}.zip"
	fi
}
install_org_plugin polylang
install_org_plugin advanced-custom-fields
install_org_plugin elementor

wpml_dir="${WPML_ZIP_DIR:-/private/wpml}"
if [[ -d "$wpml_dir" ]] && compgen -G "$wpml_dir/*.zip" >/dev/null; then
	for zip in "$wpml_dir"/*.zip; do
		unzip -qo "$zip" -d "$WP_CORE_DIR/wp-content/plugins/"
	done
	echo "WPML installed for the test suite from $wpml_dir."
fi

echo "WordPress test suite ready: $WP_VERSION"
