<?php
/**
 * Multisite test bootstrap: boots the dev network with everything active.
 *
 * Runs inside the container stack from compose.yaml:
 *   podman compose run --rm tests-multisite
 *
 * @package Templ\Headless\Tests
 */

require_once __DIR__ . '/../vendor/autoload.php';

$templ_wp_load = getenv( 'TEMPL_WP_LOAD' ) ?: '/var/www/html/wp-load.php';

if ( ! file_exists( $templ_wp_load ) ) {
	fwrite( STDERR, "WordPress not found at {$templ_wp_load}. Start the network first: composer dev:up:multisite\n" );
	exit( 1 );
}

define( 'WP_USE_THEMES', false );

require_once $templ_wp_load;

if ( ! is_multisite() ) {
	fwrite( STDERR, "This suite only means anything on a network, and this install is a single site.\n" );
	exit( 1 );
}

if ( ! function_exists( 'Templ\\Headless\\Keys\\permission_callback' ) ) {
	fwrite( STDERR, "The templ-headless MU plugin is not loaded on the dev network.\n" );
	exit( 1 );
}

$templ_sample_slug = getenv( 'TEMPL_SAMPLE_SITE_SLUG' ) ?: 'customer-one';

if ( ! get_sites(
	[
		'path'   => '/' . $templ_sample_slug . '/',
		'number' => 1,
	]
) ) {
	fwrite( STDERR, "The sample subsite '{$templ_sample_slug}' does not exist. Reprovision with composer dev:reset:multisite.\n" );
	exit( 1 );
}
