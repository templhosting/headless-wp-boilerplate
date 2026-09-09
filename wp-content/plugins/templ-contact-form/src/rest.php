<?php
/**
 * The contact form REST surface. Every route is key protected.
 *
 * Route paths are composed from the namespace constant so that the name lives
 * in one place; the literal path segments appear in the integration tests,
 * because a test asserting the literal is what proves the route answers under
 * the name a client will actually call.
 *
 * @package Templ\Headless\ContactForm
 */

namespace Templ\Headless\ContactForm\Rest;

use Templ\Headless\ContactForm\PostType;
use Templ\Headless\ContactForm\RateLimit;
use Templ\Headless\ContactForm\Validation;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

const NAMESPACE_V1 = 'templ-contact-form/v1';

/**
 * The name of the honeypot field. A real client leaves it empty; a bot filling
 * every field it sees trips it.
 */
const HONEYPOT_FIELD = 'website';

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
		'/submissions',
		[
			[
				'methods'             => 'POST',
				'callback'            => __NAMESPACE__ . '\\create',
				'permission_callback' => $auth,
				'args'                => create_args(),
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
		'/submissions/(?P<id>\d+)',
		[
			[
				'methods'             => 'GET',
				'callback'            => __NAMESPACE__ . '\\show',
				'permission_callback' => $auth,
			],
			[
				'methods'             => 'DELETE',
				'callback'            => __NAMESPACE__ . '\\destroy',
				'permission_callback' => $auth,
			],
		]
	);
}

/**
 * The argument schema for a create request. WordPress runs the sanitisers and
 * validators declared here before the callback, so the callback only ever sees
 * strings, and Validation covers the field rules on top.
 *
 * @return array
 */
function create_args(): array {
	return [
		'name'    => [
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
		],
		'email'   => [
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_email',
		],
		'subject' => [
			'type'              => 'string',
			'required'          => false,
			'sanitize_callback' => 'sanitize_text_field',
		],
		'message' => [
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_textarea_field',
		],
	];
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
		'search'   => [
			'type'              => 'string',
			'required'          => false,
			'sanitize_callback' => 'sanitize_text_field',
		],
	];
}

/**
 * Records a submission.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|WP_Error
 */
function create( WP_REST_Request $request ) {
	// A filled honeypot is answered with the same 201 a real submission gets,
	// and nothing is stored. Telling a bot it was caught only teaches it to
	// stop filling the field; a fake success teaches it nothing.
	if ( '' !== trim( (string) $request->get_param( HONEYPOT_FIELD ) ) ) {
		return new WP_REST_Response(
			[
				'id'         => 0,
				'created_at' => current_time( 'mysql', true ),
			],
			201
		);
	}

	$ip_hash    = hash_ip( client_ip( $request ) );
	$rate_check = RateLimit\check( $ip_hash );

	if ( is_wp_error( $rate_check ) ) {
		return $rate_check;
	}

	$fields = Validation\validate(
		[
			'name'    => $request->get_param( 'name' ),
			'email'   => $request->get_param( 'email' ),
			'subject' => $request->get_param( 'subject' ),
			'message' => $request->get_param( 'message' ),
		]
	);

	if ( is_wp_error( $fields ) ) {
		$fields->add_data( [ 'status' => 400 ] );
		return $fields;
	}

	$fields['ip_hash']    = $ip_hash;
	$fields['user_agent'] = substr( (string) $request->get_header( 'user_agent' ), 0, 255 );
	$fields['source_url'] = esc_url_raw( (string) $request->get_header( 'referer' ) );

	$post_id = PostType\create( $fields );

	if ( is_wp_error( $post_id ) ) {
		$post_id->add_data( [ 'status' => 500 ] );
		return $post_id;
	}

	/**
	 * Fires after a submission is stored.
	 *
	 * The documented place to send a notification email. The boilerplate
	 * never sends mail; wiring wp_mail() to this hook is the whole of it.
	 *
	 * @param int $post_id The new submission ID.
	 */
	do_action( 'templ_contact_form_submission_created', $post_id );

	return new WP_REST_Response(
		[
			'id'         => (int) $post_id,
			'created_at' => get_post_field( 'post_date_gmt', $post_id ),
		],
		201
	);
}

/**
 * Lists submissions, with core's pagination headers.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response
 */
function index( WP_REST_Request $request ): WP_REST_Response {
	$result = PostType\all(
		[
			'page'     => (int) $request->get_param( 'page' ),
			'per_page' => (int) $request->get_param( 'per_page' ),
			'search'   => (string) $request->get_param( 'search' ),
		]
	);

	$items = array_map( 'Templ\\Headless\\ContactForm\\PostType\\to_array', $result['items'] );

	$response = new WP_REST_Response( $items, 200 );
	$response->header( 'X-WP-Total', (string) $result['total'] );
	$response->header( 'X-WP-TotalPages', (string) $result['pages'] );

	return $response;
}

/**
 * Returns one submission.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|WP_Error
 */
function show( WP_REST_Request $request ) {
	$post = PostType\find( (int) $request->get_param( 'id' ) );

	if ( null === $post ) {
		return not_found();
	}

	return new WP_REST_Response( PostType\to_array( $post ), 200 );
}

/**
 * Deletes one submission.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|WP_Error
 */
function destroy( WP_REST_Request $request ) {
	$id = (int) $request->get_param( 'id' );

	if ( null === PostType\find( $id ) ) {
		return not_found();
	}

	PostType\delete( $id );

	return new WP_REST_Response( [ 'deleted' => true ], 200 );
}

/**
 * The 404 every single-resource route returns for an unknown or wrong-type ID.
 *
 * @return WP_Error
 */
function not_found(): WP_Error {
	return new WP_Error(
		'templ_contact_form_not_found',
		__( 'No such submission on this site.', 'templ-contact-form' ),
		[ 'status' => 404 ]
	);
}

/**
 * The best guess at the caller's IP.
 *
 * REMOTE_ADDR only, deliberately. A forwarded-for header is caller-supplied and
 * trivially spoofed, so trusting it would let one sender defeat the rate limit
 * by rotating a header. An agency terminating behind a trusted proxy sets the
 * real IP into REMOTE_ADDR at that proxy, which is where that trust belongs.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return string
 */
function client_ip( WP_REST_Request $request ): string {
	unset( $request );

	$remote = isset( $_SERVER['REMOTE_ADDR'] )
		? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
		: '';

	return $remote;
}

/**
 * Hashes an IP for storage and rate limiting.
 *
 * The clear IP is never written. Salting with wp_salt() means the hash cannot
 * be reversed by hashing every possible address, so abuse from one source is
 * still countable while no personal data is retained. This is a GDPR-motivated
 * choice, not an optimisation.
 *
 * @param string $ip A raw IP, possibly empty.
 * @return string A hex digest, or '' when there was no IP.
 */
function hash_ip( string $ip ): string {
	if ( '' === $ip ) {
		return '';
	}

	return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
}
