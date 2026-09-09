#!/bin/sh
# Provisions the local headless dev site. Safe to re-run: every step is
# guarded, so a second `up` changes nothing.
#
# Single-site by default. Set TEMPL_HEADLESS_MULTISITE=1 to stand up the
# subdirectory network instead, which adds the sample subsite and the
# network-flavour rewrite rules. Switching mode needs an empty database, so
# reprovision with `composer dev:reset` (single) or `dev:reset:multisite`.
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
MULTISITE=${TEMPL_HEADLESS_MULTISITE:-0}

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

# The Authorization rewrite is shared by both modes. Apache does not hand the
# Authorization header to PHP, so without this every `Authorization: Bearer`
# request arrives looking as though it carried no key at all. It has to come
# before the WordPress rules, because their [L] flags end the round of
# rewriting this env var is set in.
AUTH_REWRITE='	RewriteCond %{HTTP:Authorization} .
	RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]'

if [ "$MULTISITE" = "1" ]; then
	if wp core is-installed --network >/dev/null 2>&1; then
		echo "Network already installed."
	elif wp core is-installed >/dev/null 2>&1; then
		echo "A single site is already installed here." >&2
		echo "Switching to multisite needs an empty database: composer dev:reset:multisite." >&2
		exit 1
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
	# single-site block, because core's mod_rewrite_rules() never emits the
	# network flavour -- the Network Setup screen tells you to paste it in by
	# hand. Left alone, every subsite URL that is not a real file falls through
	# to index.php, which answers a request for /customer-one/wp-admin/ with a
	# redirect to itself.
	cat > "$WP_PATH/.htaccess" <<-HTACCESS
		# BEGIN WordPress
		<IfModule mod_rewrite.c>
		RewriteEngine On
		RewriteBase /

		$AUTH_REWRITE

		RewriteRule ^index\.php\$ - [L]
		RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin\$ \$1wp-admin/ [R=301,L]
		RewriteCond %{REQUEST_FILENAME} -f [OR]
		RewriteCond %{REQUEST_FILENAME} -d
		RewriteRule ^ - [L]
		RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) \$2 [L]
		RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\.php)\$ \$2 [L]
		RewriteRule . index.php [L]
		</IfModule>
		# END WordPress
		HTACCESS

	# One sample subsite, so that per-site behaviour is visible from the first
	# boot rather than only under the multisite suite.
	if ! wp site list --field=url | grep -q "$SITE_URL/$SAMPLE_SITE_SLUG/"; then
		echo "Creating the sample subsite..."
		wp site create --slug="$SAMPLE_SITE_SLUG" --title="Customer One" >/dev/null
	fi
else
	if wp core is-installed --network >/dev/null 2>&1; then
		echo "A multisite network is already installed here." >&2
		echo "Switching to single-site needs an empty database: composer dev:reset." >&2
		exit 1
	elif wp core is-installed >/dev/null 2>&1; then
		echo "Site already installed."
	else
		echo "Installing the site..."
		wp core install \
			--url="$SITE_URL" \
			--title="Templ Headless" \
			--admin_user="$ADMIN_USER" \
			--admin_password="$ADMIN_PASSWORD" \
			--admin_email="$ADMIN_EMAIL" \
			--skip-email
	fi

	wp core update-db >/dev/null

	wp rewrite structure '/%postname%/' >/dev/null

	# Single-site rewrites. The Authorization block is prepended to the standard
	# WordPress rules; core would otherwise emit the same body without it.
	cat > "$WP_PATH/.htaccess" <<-HTACCESS
		# BEGIN WordPress
		<IfModule mod_rewrite.c>
		RewriteEngine On
		RewriteBase /

		$AUTH_REWRITE

		RewriteRule ^index\.php\$ - [L]
		RewriteCond %{REQUEST_FILENAME} !-f
		RewriteCond %{REQUEST_FILENAME} !-d
		RewriteRule . index.php [L]
		</IfModule>
		# END WordPress
		HTACCESS
fi

# Guarded on the file rather than the directory: compose creates the bind mount
# source on the host if it is missing, so an absent theme still has a directory.
if [ -f "$WP_PATH/wp-content/themes/$THEME/style.css" ]; then
	echo "Activating the headless theme..."
	if [ "$MULTISITE" = "1" ]; then
		wp theme enable "$THEME" --network >/dev/null

		# Per site rather than network-wide: multisite has no "activate this
		# theme everywhere" switch, only an allowlist. WP_DEFAULT_THEME in
		# wp-config covers sites created later, which is why this usually has
		# nothing to do.
		for url in $(wp site list --field=url); do
			if [ "$(wp option get stylesheet --url="$url")" != "$THEME" ]; then
				wp theme activate "$THEME" --url="$url" >/dev/null
			fi
		done
	elif [ "$(wp option get stylesheet)" != "$THEME" ]; then
		wp theme activate "$THEME" >/dev/null
	fi

	# The bundled themes are dead weight on a site that never renders a
	# frontend, and leaving them installed means one more thing to keep patched.
	for bundled in $(wp theme list --field=name --status=inactive); do
		case "$bundled" in
			twenty*) wp theme delete "$bundled" >/dev/null ;;
		esac
	done
fi

for plugin in templ-contact-form templ-newsletter; do
	if [ -f "$WP_PATH/wp-content/plugins/$plugin/$plugin.php" ]; then
		if [ "$MULTISITE" = "1" ]; then
			echo "Network-activating $plugin..."
			wp plugin activate "$plugin" --network >/dev/null
		else
			echo "Activating $plugin..."
			wp plugin activate "$plugin" >/dev/null
		fi
	fi
done

echo
echo "Ready:"
if [ "$MULTISITE" = "1" ]; then
	echo "  Network admin:  $SITE_URL/wp-admin/network/"
	echo "  Main site:      $SITE_URL/wp-admin/"
	echo "  Sample subsite: $SITE_URL/$SAMPLE_SITE_SLUG/wp-admin/"
else
	echo "  Admin:          $SITE_URL/wp-admin/"
	echo "  REST base:      $SITE_URL/wp-json/"
fi
echo "  Login:          $ADMIN_USER / $ADMIN_PASSWORD"
