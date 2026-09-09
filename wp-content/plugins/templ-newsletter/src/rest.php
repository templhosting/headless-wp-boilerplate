<?php
/**
 * The newsletter REST surface.
 *
 * Every route is key protected except GET /unsubscribe, which is the one
 * deliberate unauthenticated endpoint in the whole repo. See its callback for
 * why, and see AGENTS.md: adding another unauthenticated route requires an
 * explicit note there.
 *
 * @package Templ\Headless\Newsletter
 */

namespace Templ\Headless\Newsletter\Rest;

use Templ\Headless\Newsletter\PostType;
use Templ\Headless\Newsletter\Token;
use Templ\Headless\Newsletter\Validation;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

const NAMESPACE_V1 = 'templ-newsletter/v1';

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
	$auth = 'Templ\\Headless\\Keys\\permission_callback';

	register_rest_route(
		NAMESPACE_V1,
		'/subscribers',
		[
			[
				'methods'             => 'POST',
				'callback'            => __NAMESPACE__ . '\\create',
				'permission_callback' => $auth,
			],
			[
				'methods'             => 'GET',
				'callback'            => __NAMESPACE__ . '\\index',
				'permission_callback' => $auth,
				'args'                => index_args(),
			],
		]
	);

	register_rest_route(
		NAMESPACE_V1,
		'/subscribers/unsubscribe',
		[
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\unsubscribe_by_email',
			'permission_callback' => $auth,
		]
	);

	register_rest_route(
		NAMESPACE_V1,
		'/subscribers/(?P<id>\d+)',
		[
			'methods'             => 'DELETE',
			'callback'            => __NAMESPACE__ . '\\destroy',
			'permission_callback' => $auth,
		]
	);

	register_rest_route(
		NAMESPACE_V1,
		'/unsubscribe',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\unsubscribe_by_token',
			// Deliberately open. The token is 256 bits from random_bytes, is
			// single-purpose (it only ever unsubscribes its own subscriber),
			// and is opened from an email client that cannot send an API key.
			// This is the one exception to the key-required rule, and the only
			// one; anything else unauthenticated has to earn its own note in
			// AGENTS.md.
			'permission_callback' => '__return_true',
			'args'                => [
				'token' => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		]
	);
}

/**
 * The argument schema for a list request.
 *
 * @return array
 */
function index_args(): array {
	return [
		'page'     => [
			'type'              => 'integer',
			'default'           => 1,
			'sanitize_callback' => 'absint',
		],
		'per_page' => [
			'type'              => 'integer',
			'default'           => 20,
			'minimum'           => 1,
			'maximum'           => PostType\MAX_PER_PAGE,
			'sanitize_callback' => 'absint',
		],
		'status'   => [
			'type'              => 'string',
			'required'          => false,
			'enum'              => [ PostType\STATUS_SUBSCRIBED, PostType\STATUS_UNSUBSCRIBED ],
			'sanitize_callback' => 'sanitize_key',
		],
	];
}

/**
 * Subscribes an email.
 *
 * A tripped honeypot is answered with the same 201 a real signup gets and
 * nothing is stored, so a bot learns nothing. A new subscriber returns 201, an
 * existing one re-activated returns 200, so a caller can tell the two apart.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|WP_Error
 */
function create( WP_REST_Request $request ) {
	$fields = Validation\validate(
		[
			'email'                   => $request->get_param( 'email' ),
			'name'                    => $request->get_param( 'name' ),
			Validation\HONEYPOT_FIELD => $request->get_param( Validation\HONEYPOT_FIELD ),
		]
	);

	if ( is_wp_error( $fields ) ) {
		if ( $fields->get_error_code() === 'templ_newsletter_honeypot' ) {
			return new WP_REST_Response( [ 'ok' => true ], 201 );
		}

		$fields->add_data( [ 'status' => 400 ] );
		return $fields;
	}

	$result = PostType\subscribe(
		$fields['email'],
		[
			'name'       => $fields['name'],
			'ip_hash'    => hash_ip( client_ip() ),
			'source_url' => esc_url_raw( (string) $request->get_header( 'referer' ) ),
		]
	);

	if ( is_wp_error( $result ) ) {
		$result->add_data( [ 'status' => 500 ] );
		return $result;
	}

	if ( $result['created'] ) {
		/**
		 * Fires when a new subscriber is added.
		 *
		 * The documented place to bolt on double opt-in: send the confirmation
		 * email from here and flip the record to confirmed on the click. The
		 * boilerplate is single opt-in and sends nothing.
		 *
		 * @param int $subscriber_id The new subscriber ID.
		 */
		do_action( 'templ_newsletter_subscribed', $result['id'] );
	}

	return new WP_REST_Response(
		[
			'id'     => $result['id'],
			'status' => $result['status'],
		],
		$result['created'] ? 201 : 200
	);
}

/**
 * Lists subscribers, with core's pagination headers.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response
 */
function index( WP_REST_Request $request ): WP_REST_Response {
	$result = PostType\all(
		[
			'page'     => (int) $request->get_param( 'page' ),
			'per_page' => (int) $request->get_param( 'per_page' ),
			'status'   => (string) $request->get_param( 'status' ),
		]
	);

	$items = array_map( 'Templ\\Headless\\Newsletter\\PostType\\to_array', $result['items'] );

	$response = new WP_REST_Response( $items, 200 );
	$response->header( 'X-WP-Total', (string) $result['total'] );
	$response->header( 'X-WP-TotalPages', (string) $result['pages'] );

	return $response;
}

/**
 * Unsubscribes by email, for a frontend acting on its own list.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|WP_Error
 */
function unsubscribe_by_email( WP_REST_Request $request ) {
	$email = strtolower( trim( sanitize_email( (string) $request->get_param( 'email' ) ) ) );

	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error(
			'templ_newsletter_email_invalid',
			__( 'A valid email address is required.', 'templ-newsletter' ),
			[ 'status' => 400 ]
		);
	}

	$post = PostType\find_by_email( $email );

	if ( null === $post ) {
		return new WP_Error(
			'templ_newsletter_not_found',
			__( 'No such subscriber on this site.', 'templ-newsletter' ),
			[ 'status' => 404 ]
		);
	}

	unsubscribe_post( $post );

	return new WP_REST_Response(
		[
			'id'     => (int) $post->ID,
			'status' => PostType\STATUS_UNSUBSCRIBED,
		],
		200
	);
}

/**
 * Unsubscribes by token, from an email link, without a key.
 *
 * A bad token answers 404, never 500: an unauthenticated route handed garbage
 * must fail as an ordinary not-found, not leak a stack trace.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|WP_Error
 */
function unsubscribe_by_token( WP_REST_Request $request ) {
	$post = Token\find_subscriber( (string) $request->get_param( 'token' ) );

	if ( null === $post ) {
		return new WP_Error(
			'templ_newsletter_invalid_token',
			__( 'That unsubscribe link is not valid.', 'templ-newsletter' ),
			[ 'status' => 404 ]
		);
	}

	unsubscribe_post( $post );

	return new WP_REST_Response(
		[
			'unsubscribed' => true,
			'email'        => $post->post_title,
		],
		200
	);
}

/**
 * Deletes a subscriber outright, for a GDPR erasure request.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|WP_Error
 */
function destroy( WP_REST_Request $request ) {
	$id = (int) $request->get_param( 'id' );

	if ( null === PostType\find( $id ) ) {
		return new WP_Error(
			'templ_newsletter_not_found',
			__( 'No such subscriber on this site.', 'templ-newsletter' ),
			[ 'status' => 404 ]
		);
	}

	PostType\delete( $id );

	return new WP_REST_Response( [ 'deleted' => true ], 200 );
}

/**
 * Flips a subscriber off and fires the unsubscribed action, once.
 *
 * @param \WP_Post $post Subscriber post.
 * @return void
 */
function unsubscribe_post( \WP_Post $post ): void {
	if ( PostType\unsubscribe( $post ) ) {
		/**
		 * Fires when a subscriber unsubscribes.
		 *
		 * @param int $subscriber_id The subscriber ID.
		 */
		do_action( 'templ_newsletter_unsubscribed', (int) $post->ID );
	}
}

/**
 * The best guess at the caller's IP. REMOTE_ADDR only; a forwarded header is
 * caller-supplied and spoofable.
 *
 * @return string
 */
function client_ip(): string {
	return isset( $_SERVER['REMOTE_ADDR'] )
		? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
		: '';
}

/**
 * Hashes an IP for storage, salted so it cannot be reversed. GDPR-motivated:
 * the clear IP is never written.
 *
 * @param string $ip A raw IP, possibly empty.
 * @return string
 */
function hash_ip( string $ip ): string {
	if ( '' === $ip ) {
		return '';
	}

	return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
}
