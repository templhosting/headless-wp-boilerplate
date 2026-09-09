<?php
/**
 * The authentication contract for the whole network.
 *
 * This file is a public API. templ-contact-form, templ-newsletter and anything
 * an agency adds later reach for permission_callback() here and nothing else,
 * so a change to a signature in this file is a change to every plugin on the
 * network. Treat it accordingly.
 *
 * Usage from a feature plugin:
 *
 *     register_rest_route(
 *         'templ-contact-form/v1',
 *         '/submissions',
 *         [
 *             'methods'             => 'POST',
 *             'callback'            => __NAMESPACE__ . '\\create',
 *             'permission_callback' => 'Templ\\Headless\\Keys\\permission_callback',
 *         ]
 *     );
 *
 * @package Templ\Headless
 */

namespace Templ\Headless\Keys;

use Templ\Headless\Keys\Hash;
use Templ\Headless\Keys\Store;
use WP_Error;
use WP_Post;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Header a client is expected to authenticate with.
 */
const AUTH_HEADER = 'Authorization';

/**
 * Accepted for clients that cannot set an Authorization header, which in
 * practice means a proxy or a CDN that has claimed it for itself.
 */
const ALT_HEADER = 'X-Templ-Api-Key';

/**
 * Registers the hooks this module owns.
 *
 * @return void
 */
function bootstrap(): void {
	Store\bootstrap();
}

/**
 * Pulls the key out of a request.
 *
 * Only headers. A key in the query string ends up in the access log of every
 * server and proxy between the client and here, in browser history, and in any
 * Referer the site emits, and there is no way to un-leak it afterwards.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return string|null The plaintext key, or null if the request carried none.
 */
function extract_from_request( WP_REST_Request $request ): ?string {
	$authorization = $request->get_header( AUTH_HEADER );

	if ( is_string( $authorization ) && preg_match( '/^Bearer\s+(\S+)$/i', $authorization, $matches ) ) {
		return $matches[1];
	}

	$alternative = $request->get_header( ALT_HEADER );

	if ( is_string( $alternative ) && '' !== trim( $alternative ) ) {
		return trim( $alternative );
	}

	return null;
}

/**
 * Resolves a plaintext key to the key post it belongs to on this site.
 *
 * @param string $plaintext A key as the client sent it.
 * @return WP_Post|null The key post, revoked or not, or null if unknown.
 */
function find( string $plaintext ): ?WP_Post {
	if ( ! Hash\looks_like_key( $plaintext ) ) {
		return null;
	}

	$post = Store\find_by_digest( Hash\digest( $plaintext ) );

	if ( null === $post ) {
		return null;
	}

	// The lookup already matched on the digest, so this compares two values
	// that are equal or the query is broken. It is here because a timing-safe
	// comparison of secrets is the habit worth keeping, not because this
	// particular call site is at risk.
	if ( ! hash_equals( (string) get_post_meta( $post->ID, Store\META_HASH, true ), Hash\digest( $plaintext ) ) ) {
		return null;
	}

	return $post;
}

/**
 * Decides whether a request may proceed.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return true|WP_Error True when the request carried a valid key.
 */
function authenticate( WP_REST_Request $request ) {
	$result = check( $request );

	/**
	 * Filters the outcome of key authentication.
	 *
	 * The hook an agency uses to bolt on an IP allowlist, a second key scheme
	 * or a bypass for an internal network, without forking this plugin.
	 * Returning anything other than true refuses the request.
	 *
	 * @param true|WP_Error   $result  Outcome so far.
	 * @param WP_REST_Request $request Incoming request.
	 */
	return apply_filters( 'templ_headless_authenticate', $result, $request );
}

/**
 * The unfiltered half of authenticate().
 *
 * @param WP_REST_Request $request Incoming request.
 * @return true|WP_Error
 */
function check( WP_REST_Request $request ) {
	$plaintext = extract_from_request( $request );

	if ( null === $plaintext ) {
		return new WP_Error(
			'templ_headless_missing_key',
			__( 'This endpoint requires an API key. Send it as an Authorization: Bearer header.', 'templ-headless' ),
			[ 'status' => 401 ]
		);
	}

	$key = find( $plaintext );

	if ( null === $key ) {
		return new WP_Error(
			'templ_headless_invalid_key',
			__( 'That API key is not valid for this site. Keys are per site and do not carry across the network.', 'templ-headless' ),
			[ 'status' => 401 ]
		);
	}

	if ( Store\is_revoked( $key ) ) {
		return new WP_Error(
			'templ_headless_revoked_key',
			__( 'That API key has been revoked.', 'templ-headless' ),
			[ 'status' => 401 ]
		);
	}

	Store\touch_last_used( (int) $key->ID );

	return true;
}

/**
 * The callable feature plugins hand to register_rest_route().
 *
 * A named function rather than a closure factory, so that a route definition
 * can reference it by name and stay readable in a var_dump of the route list.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return true|WP_Error
 */
function permission_callback( WP_REST_Request $request ) {
	return authenticate( $request );
}
