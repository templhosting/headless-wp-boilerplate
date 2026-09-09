<?php
/**
 * Integration test bootstrap: boots the dev site with everything active.
 *
 * Runs inside the container stack from compose.yaml:
 *   podman compose run --rm tests
 *
 * It fails loudly when the stack is down or a component is missing, because a
 * suite that skips itself when its subject is absent is worse than one that
 * fails: it turns "nothing is running" into a green tick.
 *
 * @package Templ\Headless\Tests
 */

require_once __DIR__ . '/../vendor/autoload.php';

$templ_wp_load = getenv( 'TEMPL_WP_LOAD' ) ?: '/var/www/html/wp-load.php';

if ( ! file_exists( $templ_wp_load ) ) {
	fwrite( STDERR, "WordPress not found at {$templ_wp_load}. Start the dev stack first: composer dev:up\n" );
	exit( 1 );
}

define( 'WP_USE_THEMES', false );

require_once $templ_wp_load;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! function_exists( 'Templ\\Headless\\Keys\\permission_callback' ) ) {
	fwrite( STDERR, "The templ-headless MU plugin is not loaded on the dev site.\n" );
	exit( 1 );
}

foreach ( [ 'templ-contact-form/templ-contact-form.php', 'templ-newsletter/templ-newsletter.php' ] as $templ_plugin ) {
	if ( ! is_plugin_active_for_network( $templ_plugin ) && ! is_plugin_active( $templ_plugin ) ) {
		fwrite( STDERR, "The plugin {$templ_plugin} is not active on the dev site.\n" );
		exit( 1 );
	}
}
