#!/usr/bin/env bash
# Installs the release zip on the clean `wordpress-zip` site (http://localhost:8081).
# Runs inside the `wpcli-zip` container (see `make zip-smoke`): WordPress, Polylang,
# EN/FR/ES, a few translated posts, then the plugin from dist/*.zip, activated and
# baselined through its own WP-CLI command.
set -euo pipefail

wp() { command wp --quiet "$@"; }

zip=$(ls -t /dist/stalelingo-*.zip 2>/dev/null | head -1)
[[ -n "$zip" ]] || { echo "No zip in dist/. Run 'make zip' first." >&2; exit 1; }

echo "==> Waiting for WordPress files"
for _ in $(seq 1 60); do
	[[ -f wp-config.php ]] && break
	sleep 2
done

echo "==> Fresh WordPress"
wp db reset --yes 2>/dev/null || wp db create
wp core install --url=http://localhost:8081 --title="Stalelingo zip smoke test" \
	--admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email
wp rewrite structure '/%postname%/' --hard

echo "==> Polylang with EN, FR and ES"
wp plugin install polylang --activate
wp eval-file /tools/dev/languages.php polylang

echo "==> Translated content"
wp user create translator-fr translator-fr@example.test --role=author --user_pass=password --display_name="Translator FR"
wp eval-file /tools/dev/seed.php polylang 3 post,page

echo "==> Installing $(basename "$zip")"
rm -rf wp-content/plugins/stalelingo
wp plugin install "$zip" --activate
wp plugin list --name=stalelingo --fields=name,status,version

echo "==> Baseline through the plugin's own WP-CLI command"
command wp stalelingo baseline
command wp stalelingo report --format=count

echo "==> Making one translation drift"
source_id=$(command wp post list --post_type=post --lang=en --meta_key=_stalelingo_seed --field=ID --posts_per_page=1 --orderby=ID --order=ASC)
wp post update "$source_id" --post_title="$(command wp post get "$source_id" --field=post_title) (zip smoke)"
command wp cron event run --due-now >/dev/null
outdated=$(command wp stalelingo report --status=outdated --format=count)
echo "Outdated translations after editing post $source_id: $outdated"
[[ "$outdated" -gt 0 ]] || { echo "Editing a source did not flag its translations." >&2; exit 1; }

echo "Zip smoke site ready: http://localhost:8081/wp-admin (admin / password)"
