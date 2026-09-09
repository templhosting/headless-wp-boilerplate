<?php
/**
 * Where contact submissions live.
 *
 * A custom post type rather than a table of its own, for the same reason the
 * key store is: post types are per site without anyone having to remember they
 * are, so a submission made on one subsite stays on that subsite and never
 * leaks into another's admin list.
 *
 * @package Templ\Headless\ContactForm
 */

namespace Templ\Headless\ContactForm\PostType;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

const POST_TYPE = 'templ_submission';

const META_NAME       = '_templ_cf_name';
const META_EMAIL      = '_templ_cf_email';
const META_SUBJECT    = '_templ_cf_subject';
const META_MESSAGE    = '_templ_cf_message';
const META_IP_HASH    = '_templ_cf_ip_hash';
const META_USER_AGENT = '_templ_cf_user_agent';
const META_SOURCE_URL = '_templ_cf_source_url';

/**
 * Most submissions a single listing will return.
 */
const MAX_PER_PAGE = 100;

/**
 * Registers the hooks this module owns.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'init', __NAMESPACE__ . '\\register' );
}

/**
 * Declares the submission post type and its meta.
 *
 * @return void
 */
function register(): void {
	register_post_type(
		POST_TYPE,
		[
			'labels'              => [
				'name'          => __( 'Submissions', 'templ-contact-form' ),
				'singular_name' => __( 'Submission', 'templ-contact-form' ),
			],
			'public'              => false,
			// Not a preference. The submission carries a visitor's name, email
			// and message, and the REST API is exactly the surface this
			// plugin guards behind a key. Exposing the post type there would
			// hand the whole inbox to anyone who could reach /wp/v2.
			'show_in_rest'        => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => [ 'title' ],
			'delete_with_user'    => false,
		]
	);

	$meta = [
		META_NAME,
		META_EMAIL,
		META_SUBJECT,
		META_MESSAGE,
		META_IP_HASH,
		META_USER_AGENT,
		META_SOURCE_URL,
	];

	foreach ( $meta as $key ) {
		register_post_meta(
			POST_TYPE,
			$key,
			[
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => '__return_false',
			]
		);
	}
}

/**
 * Stores a submission.
 *
 * The only writer. Everything reaches this after Validation\validate() has
 * already sanitised the visitor-supplied fields; the request metadata is
 * sanitised here because it comes from the server, not the caller.
 *
 * @param array $fields {
 *     Sanitised submission data.
 *
 *     @type string $name       Sender name.
 *     @type string $email      Sender email.
 *     @type string $subject    Optional subject.
 *     @type string $message    Message body.
 *     @type string $ip_hash    Hashed sender IP, or ''.
 *     @type string $user_agent Sender user agent, or ''.
 *     @type string $source_url Page the submission came from, or ''.
 * }
 * @return int|WP_Error The new post ID, or an error from wp_insert_post().
 */
function create( array $fields ) {
	$title = sprintf(
		/* translators: 1: sender name, 2: subject. */
		__( '%1$s: %2$s', 'templ-contact-form' ),
		$fields['name'],
		'' !== $fields['subject'] ? $fields['subject'] : __( '(no subject)', 'templ-contact-form' )
	);

	return wp_insert_post(
		[
			'post_type'   => POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $title,
			'meta_input'  => [
				META_NAME       => $fields['name'],
				META_EMAIL      => $fields['email'],
				META_SUBJECT    => $fields['subject'],
				META_MESSAGE    => $fields['message'],
				META_IP_HASH    => $fields['ip_hash'] ?? '',
				META_USER_AGENT => $fields['user_agent'] ?? '',
				META_SOURCE_URL => $fields['source_url'] ?? '',
			],
		],
		true
	);
}

/**
 * A page of submissions, newest first.
 *
 * @param array $args {
 *     Query controls.
 *
 *     @type int    $page     1-based page number.
 *     @type int    $per_page Items per page, capped at MAX_PER_PAGE.
 *     @type string $search   Free-text search over the stored fields.
 * }
 * @return array{items:WP_Post[],total:int,pages:int}
 */
function all( array $args = [] ): array {
	$per_page = min( MAX_PER_PAGE, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

	$query_args = [
		'post_type'      => POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => 'date',
		'order'          => 'DESC',
	];

	if ( ! empty( $args['search'] ) ) {
		$query_args['s'] = (string) $args['search'];
	}

	$query = new \WP_Query( $query_args );

	return [
		'items' => $query->posts,
		'total' => (int) $query->found_posts,
		'pages' => (int) $query->max_num_pages,
	];
}

/**
 * Loads one submission, refusing anything that is not one on this site.
 *
 * @param int $post_id Post ID.
 * @return WP_Post|null
 */
function find( int $post_id ): ?WP_Post {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post || POST_TYPE !== $post->post_type ) {
		return null;
	}

	return $post;
}

/**
 * Erases a submission.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function delete( int $post_id ): bool {
	if ( null === find( $post_id ) ) {
		return false;
	}

	return (bool) wp_delete_post( $post_id, true );
}

/**
 * Shapes a submission for the REST response.
 *
 * The response schema lives here and nowhere else, so that the list endpoint
 * and the single endpoint can never drift apart. The IP hash, user agent and
 * source URL stay out of it: they are for abuse investigation from the admin,
 * not for the integration that reads its own inbox back.
 *
 * @param WP_Post $post Submission post.
 * @return array{id:int,name:string,email:string,subject:string,message:string,created_at:string}
 */
function to_array( WP_Post $post ): array {
	return [
		'id'         => (int) $post->ID,
		'name'       => (string) get_post_meta( $post->ID, META_NAME, true ),
		'email'      => (string) get_post_meta( $post->ID, META_EMAIL, true ),
		'subject'    => (string) get_post_meta( $post->ID, META_SUBJECT, true ),
		'message'    => (string) get_post_meta( $post->ID, META_MESSAGE, true ),
		'created_at' => $post->post_date_gmt,
	];
}
