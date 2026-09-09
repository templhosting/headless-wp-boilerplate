<?php
/**
 * The one endpoint the key store owns.
 *
 * @package Templ\Headless
 */

namespace Templ\Headless\Rest;

use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

const NAMESPACE_V1 = 'templ-headless/v1';

/**
 * Registers the hooks this module owns.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_routes' );
}

/**
 * Declares the routes.
 *
 * @return void
 */
function register_routes(): void {
	register_rest_route(
		NAMESPACE_V1,
		'/ping',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\ping',
			'permission_callback' => 'Templ\\Headless\\Keys\\permission_callback',
		]
	);
}

/**
 * Confirms a key works, and says which site it works on.
 *
 * Exists so that an integration can check its credentials without writing
 * anything, and so the test suite can exercise the auth layer on its own
 * rather than through a feature plugin that might be at fault instead.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response
 */
function ping( WP_REST_Request $request ): WP_REST_Response {
	unset( $request );

	return new WP_REST_Response(
		[
			'ok'       => true,
			'site_id'  => get_current_blog_id(),
			'site_url' => home_url( '/' ),
		],
		200
	);
}
