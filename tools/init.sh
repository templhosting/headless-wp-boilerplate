#!/bin/sh
# Provisions the local headless dev network. Safe to re-run: every step is
# guarded, so a second `up` changes nothing.
#
# Runs against the container's WordPress install, not the repo. The repo only
# supplies wp-content, so anything this script needs from core is already in
# the named volume by the time it starts.
set -eu

WP_PATH=/var/www/html
SITE_URL=${SITE_URL:-http://localhost:8080}
SAMPLE_SITE_SLUG=${SAMPLE_SITE_SLUG:-customer-one}
ADMIN_USER=${ADMIN_USER:-admin}
ADMIN_PASSWORD=${ADMIN_PASSWORD:-password}
ADMIN_EMAIL=${ADMIN_EMAIL:-dev@templ.test}

THEME=templ-headless-theme

cd "$WP_PATH"

echo "Waiting for WordPress files..."
i=0
while [ ! -f "$WP_PATH/wp-settings.php" ] || [ ! -f "$WP_PATH/wp-config.php" ]; do
	i=$((i + 1))
	if [ "$i" -gt 60 ]; then
		echo "WordPress files never appeared." >&2
		exit 1
	fi
	sleep 2
done

echo "Waiting for database..."
i=0
until wp db check >/dev/null 2>&1; do
	i=$((i + 1))
	if [ "$i" -gt 60 ]; then
		echo "Database never became reachable." >&2
		exit 1
	fi
	sleep 2
done

if wp core is-installed --network >/dev/null 2>&1; then
	echo "Network already installed."
else
	echo "Installing the network..."
	wp core multisite-install \
		--url="$SITE_URL" \
		--title="Templ Headless Network" \
		--admin_user="$ADMIN_USER" \
		--admin_password="$ADMIN_PASSWORD" \
		--admin_email="$ADMIN_EMAIL" \
		--skip-email
fi

wp core update-db --network >/dev/null

wp rewrite structure '/%postname%/' >/dev/null

# Subdirectory multisite needs its own rewrite rules, and they have to be
# written unconditionally: `wp rewrite structure` above has just written the
# single-site block, because core's mod_rewrite_rules() never emits the network
# flavour -- the Network Setup screen tells you to paste it in by hand. Left
# alone, every subsite URL that is not a real file falls through to index.php,
# which answers a request for /customer-one/wp-admin/ with a redirect to itself.
cat > "$WP_PATH/.htaccess" <<-'HTACCESS'
	# BEGIN WordPress
	<IfModule mod_rewrite.c>
	RewriteEngine On
	RewriteBase /

	# Apache does not hand the Authorization header to PHP, so without this
	# every `Authorization: Bearer` request arrives looking as though it
	# carried no key at all. It has to come before the rules below, because
	# their [L] flags end the round of rewriting this env var is set in.
	RewriteCond %{HTTP:Authorization} .
	RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

	RewriteRule ^index\.php$ - [L]
	RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ $1wp-admin/ [R=301,L]
	RewriteCond %{REQUEST_FILENAME} -f [OR]
	RewriteCond %{REQUEST_FILENAME} -d
	RewriteRule ^ - [L]
	RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]
	RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\.php)$ $2 [L]
	RewriteRule . index.php [L]
	</IfModule>
	# END WordPress
	HTACCESS

# One sample subsite, so that per-site behaviour is visible from the first boot
# rather than only under the multisite suite.
if ! wp site list --field=url | grep -q "$SITE_URL/$SAMPLE_SITE_SLUG/"; then
	echo "Creating the sample subsite..."
	wp site create --slug="$SAMPLE_SITE_SLUG" --title="Customer One" >/dev/null
fi

# Guarded on the file rather than the directory: compose creates the bind mount
# source on the host if it is missing, so an absent theme still has a directory.
if [ -f "$WP_PATH/wp-content/themes/$THEME/style.css" ]; then
	echo "Activating the headless theme..."
	wp theme enable "$THEME" --network >/dev/null

	# Per site rather than network-wide: multisite has no "activate this theme
	# everywhere" switch, only an allowlist. WP_DEFAULT_THEME in wp-config
	# covers sites created later, which is why this usually has nothing to do.
	for url in $(wp site list --field=url); do
		if [ "$(wp option get stylesheet --url="$url")" != "$THEME" ]; then
			wp theme activate "$THEME" --url="$url" >/dev/null
		fi
	done

	# The bundled themes are dead weight on a network that never renders a
	# frontend, and leaving them installed means one more thing to keep patched.
	for bundled in $(wp theme list --field=name --status=inactive); do
		case "$bundled" in
			twenty*) wp theme delete "$bundled" >/dev/null ;;
		esac
	done
fi

for plugin in templ-contact-form templ-newsletter; do
	if [ -f "$WP_PATH/wp-content/plugins/$plugin/$plugin.php" ]; then
		echo "Network-activating $plugin..."
		wp plugin activate "$plugin" --network >/dev/null
	fi
done

echo
echo "Ready:"
echo "  Network admin: $SITE_URL/wp-admin/network/"
echo "  Main site:     $SITE_URL/wp-admin/"
echo "  Sample subsite: $SITE_URL/$SAMPLE_SITE_SLUG/wp-admin/"
echo "  Login:         $ADMIN_USER / $ADMIN_PASSWORD"
