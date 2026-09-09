<?php
/**
 * Turns the frontend off.
 *
 * The network serves an API, not pages. Everything a headless frontend needs
 * is under /wp-json/, and everything an editor needs is under /wp-admin/, so
 * the public side of WordPress is closed rather than left rendering an empty
 * theme.
 *
 * @package Templ\Headless\Theme
 */

namespace Templ\Headless\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every hook this theme owns.
 *
 * @return void
 */
function bootstrap(): void {
	// Priority 1, ahead of core's redirect_canonical at 10. Both answer
	// template_redirect, and core registers first, so at equal priority
	// canonical wins -- which turns /?author=1 into a 301 to /author/admin/
	// and hands out the login name of every user, one integer at a time.
	// Going first means the request never reaches canonical at all.
	add_action( 'template_redirect', __NAMESPACE__ . '\\redirect_frontend', 1 );
	add_action( 'init', __NAMESPACE__ . '\\remove_frontend_cruft' );
}

/**
 * Sends any frontend request somewhere useful.
 *
 * REST requests never arrive here: core short-circuits them in
 * `rest_api_loaded()`, well before the template loader runs. wp-login.php and
 * wp-admin do not load a template at all. What is left is exactly the public
 * side: the home page, single views, archives, search, feeds and 404s.
 *
 * @return void
 */
function redirect_frontend(): void {
	// robots.txt and the favicon are served by core from this same hook, and
	// both are legitimate on a headless site: one keeps crawlers off it, the
	// other stops browsers requesting it on every admin page load.
	if ( is_robots() || is_favicon() ) {
		return;
	}

	// A logged-in user is somebody who followed a stale link or clicked
	// "Visit Site". Bouncing them to the login form they have already passed
	// is a dead end, so send them where they were going.
	$destination = is_user_logged_in() ? admin_url() : wp_login_url();

	// 302 rather than 301: a permanent redirect would be cached by browsers
	// and intermediaries, and would outlive any decision to serve a real
	// frontend from this domain later.
	wp_safe_redirect( $destination, 302 );
	exit;
}

/**
 * Strips the markup core emits for the benefit of a frontend that is not there.
 *
 * @return void
 */
function remove_frontend_cruft(): void {
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'feed_links', 2 );
	remove_action( 'wp_head', 'feed_links_extra', 3 );
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );

	// The emoji filters run on the admin side too, where nothing is being
	// rendered for a visitor either.
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
}

bootstrap();
