<?php
/**
 * Plugin Name: Templ Contact Form
 * Description: A fixed-schema contact submission endpoint, a private submission store and a reader screen. Authenticates through the templ-headless MU plugin.
 * Version: 0.1.0
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Network: true
 * Author: Templ
 * Author URI: https://templ.io/
 * License: MIT
 * Text Domain: templ-contact-form
 *
 * @package Templ\Headless\ContactForm
 */

namespace Templ\Headless\ContactForm;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';

define( 'TEMPL_CONTACT_FORM_DIR', __DIR__ );

/*
 * Required explicitly rather than autoloaded. PSR-4 maps class names to files
 * and this codebase has no classes, only namespaced functions, so there is
 * nothing for an autoloader to trigger on. The list below is the load order.
 */
require_once TEMPL_CONTACT_FORM_DIR . '/src/post-type.php';
require_once TEMPL_CONTACT_FORM_DIR . '/src/validation.php';
require_once TEMPL_CONTACT_FORM_DIR . '/src/rate-limit.php';
require_once TEMPL_CONTACT_FORM_DIR . '/src/rest.php';
require_once TEMPL_CONTACT_FORM_DIR . '/src/admin.php';

/**
 * The one function this plugin needs from the MU plugin. If it is absent the
 * whole feature has no way to authenticate a request, so bootstrapping it would
 * only stand up endpoints that reject everything.
 */
const REQUIRED_AUTH_CALLBACK = 'Templ\\Headless\\Keys\\permission_callback';

/**
 * Registers every hook the plugin owns, once the MU plugin is confirmed present.
 *
 * A missing MU plugin degrades to an admin notice rather than a fatal. A white
 * screen on every admin page is a worse failure than a feature that politely
 * says why it is off, and an agency that forgets to deploy the MU plugin should
 * be told, not shown a stack trace.
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
 * Explains why the plugin is inert, on every admin screen, until the MU plugin
 * is in place.
 *
 * @return void
 */
function render_missing_dependency_notice(): void {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Templ Contact Form needs the Templ Headless must-use plugin to authenticate requests, and it is not installed. Deploy wp-content/mu-plugins before activating this plugin.', 'templ-contact-form' )
	);
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );
