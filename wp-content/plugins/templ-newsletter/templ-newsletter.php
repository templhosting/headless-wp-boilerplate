<?php
/**
 * Plugin Name: Templ Newsletter
 * Description: Single opt-in list capture, a private subscriber store and a reader screen with CSV export. Authenticates through the templ-headless MU plugin.
 * Version: 0.1.0
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Network: true
 * Author: Templ
 * Author URI: https://templ.io/
 * License: MIT
 * Text Domain: templ-newsletter
 *
 * @package Templ\Headless\Newsletter
 */

namespace Templ\Headless\Newsletter;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';

define( 'TEMPL_NEWSLETTER_DIR', __DIR__ );

/*
 * Required explicitly rather than autoloaded, matching every other package in
 * this repo: no classes, so nothing for a PSR-4 autoloader to trigger on. The
 * list below is the load order.
 */
require_once TEMPL_NEWSLETTER_DIR . '/src/post-type.php';
require_once TEMPL_NEWSLETTER_DIR . '/src/token.php';
require_once TEMPL_NEWSLETTER_DIR . '/src/validation.php';
require_once TEMPL_NEWSLETTER_DIR . '/src/rest.php';
require_once TEMPL_NEWSLETTER_DIR . '/src/admin.php';

/**
 * The one function this plugin needs from the MU plugin.
 */
const REQUIRED_AUTH_CALLBACK = 'Templ\\Headless\\Keys\\permission_callback';

/**
 * Registers every hook the plugin owns, once the MU plugin is confirmed present.
 *
 * A missing MU plugin degrades to an admin notice rather than a fatal, for the
 * same reason as the contact form: a white screen is a worse failure than a
 * feature that says why it is off.
 *
 * @return void
 */
function bootstrap(): void {
	if ( ! function_exists( REQUIRED_AUTH_CALLBACK ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_dependency_notice' );
		add_action( 'network_admin_notices', __NAMESPACE__ . '\\render_missing_dependency_notice' );
		return;
	}

	PostType\bootstrap();
	Rest\bootstrap();
	Admin\bootstrap();
}

/**
 * Explains why the plugin is inert until the MU plugin is in place.
 *
 * @return void
 */
function render_missing_dependency_notice(): void {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Templ Newsletter needs the Templ Headless must-use plugin to authenticate requests, and it is not installed. Deploy wp-content/mu-plugins before activating this plugin.', 'templ-newsletter' )
	);
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );
