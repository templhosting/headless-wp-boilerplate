<?php
/**
 * Where subscribers live.
 *
 * A custom post type, per site for free, exactly like the key store and the
 * submission store. The subscriber's status rides in post_status through two
 * registered custom statuses rather than in meta, so that "how many people are
 * subscribed" is a WP_Query count core already indexes, not a meta scan.
 *
 * @package Templ\Headless\Newsletter
 */

namespace Templ\Headless\Newsletter\PostType;

use Templ\Headless\Newsletter\Token;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

const POST_TYPE = 'templ_subscriber';

const STATUS_SUBSCRIBED   = 'templ_subscribed';
const STATUS_UNSUBSCRIBED = 'templ_unsubscribed';

const META_NAME            = '_templ_nl_name';
const META_SOURCE_URL      = '_templ_nl_source_url';
const META_IP_HASH         = '_templ_nl_ip_hash';
const META_TOKEN           = '_templ_nl_token';
const META_SUBSCRIBED_AT   = '_templ_nl_subscribed_at';
const META_UNSUBSCRIBED_AT = '_templ_nl_unsubscribed_at';

/**
 * Most subscribers a single listing will return.
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
 * Declares the subscriber post type, its statuses and its meta.
 *
 * @return void
 */
function register(): void {
	register_post_type(
		POST_TYPE,
		[
			'labels'              => [
				'name'          => __( 'Subscribers', 'templ-newsletter' ),
				'singular_name' => __( 'Subscriber', 'templ-newsletter' ),
			],
			'public'              => false,
			// Not a preference. A subscriber post is an email address plus the
			// unsubscribe token, and the REST API is the surface this plugin
			// guards behind a key. Exposing it there would hand the whole list
			// and every unsubscribe token to anyone who reached /wp/v2.
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

	register_post_status(
		STATUS_SUBSCRIBED,
		[
			'label'                     => _x( 'Subscribed', 'subscriber status', 'templ-newsletter' ),
			'public'                    => false,
			'internal'                  => true,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => false,
		]
	);

	register_post_status(
		STATUS_UNSUBSCRIBED,
		[
			'label'                     => _x( 'Unsubscribed', 'subscriber status', 'templ-newsletter' ),
			'public'                    => false,
			'internal'                  => true,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => false,
		]
	);

	$meta = [
		META_NAME,
		META_SOURCE_URL,
		META_IP_HASH,
		META_TOKEN,
		META_SUBSCRIBED_AT,
		META_UNSUBSCRIBED_AT,
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
 * Finds the subscriber for an email on this site, whatever their status.
 *
 * The email is the post title, so an exact-title lookup is enough and avoids a
 * meta scan. Normalisation happens before this is called, so the stored title
 * and the lookup argument are both already lowercased and trimmed.
 *
 * @param string $email A normalised email address.
 * @return WP_Post|null
 */
function find_by_email( string $email ): ?WP_Post {
	$posts = get_posts(
		[
			'post_type'      => POST_TYPE,
			'post_status'    => [ STATUS_SUBSCRIBED, STATUS_UNSUBSCRIBED ],
			'title'          => $email,
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		]
	);

	return $posts ? $posts[0] : null;
}

/**
 * Subscribes an email, or re-activates an existing one.
 *
 * Idempotent on purpose. A headless frontend retries, a visitor double-clicks,
 * a form is submitted twice; none of that should create two records or return
 * an error. Re-subscribing an address that had unsubscribed flips it back to
 * subscribed and keeps the same record, so history and token survive.
 *
 * @param string $email A normalised email address.
 * @param array  $extra {
 *     Optional metadata.
 *
 *     @type string $name       Subscriber name.
 *     @type string $ip_hash    Hashed IP.
 *     @type string $source_url Page the signup came from.
 * }
 * @return array{id:int,status:string,created:bool}|WP_Error
 */
function subscribe( string $email, array $extra = [] ) {
	$existing = find_by_email( $email );
	$now      = current_time( 'mysql', true );

	if ( null !== $existing ) {
		if ( STATUS_SUBSCRIBED !== $existing->post_status ) {
			wp_update_post(
				[
					'ID'          => $existing->ID,
					'post_status' => STATUS_SUBSCRIBED,
				]
			);
			update_post_meta( $existing->ID, META_SUBSCRIBED_AT, $now );
			delete_post_meta( $existing->ID, META_UNSUBSCRIBED_AT );
		}

		return [
			'id'      => (int) $existing->ID,
			'status'  => STATUS_SUBSCRIBED,
			'created' => false,
		];
	}

	$post_id = wp_insert_post(
		[
			'post_type'   => POST_TYPE,
			'post_status' => STATUS_SUBSCRIBED,
			'post_title'  => $email,
			'meta_input'  => [
				META_NAME          => $extra['name'] ?? '',
				META_IP_HASH       => $extra['ip_hash'] ?? '',
				META_SOURCE_URL    => $extra['source_url'] ?? '',
				META_TOKEN         => Token\generate(),
				META_SUBSCRIBED_AT => $now,
			],
		],
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	return [
		'id'      => (int) $post_id,
		'status'  => STATUS_SUBSCRIBED,
		'created' => true,
	];
}

/**
 * Marks a subscriber as unsubscribed, keeping the record.
 *
 * @param WP_Post $post Subscriber post.
 * @return bool True when a change was made.
 */
function unsubscribe( WP_Post $post ): bool {
	if ( STATUS_UNSUBSCRIBED === $post->post_status ) {
		return false;
	}

	wp_update_post(
		[
			'ID'          => $post->ID,
			'post_status' => STATUS_UNSUBSCRIBED,
		]
	);
	update_post_meta( $post->ID, META_UNSUBSCRIBED_AT, current_time( 'mysql', true ) );

	return true;
}

/**
 * A page of subscribers, newest first, optionally filtered by status.
 *
 * @param array $args {
 *     Query controls.
 *
 *     @type int    $page     1-based page number.
 *     @type int    $per_page Items per page, capped at MAX_PER_PAGE.
 *     @type string $status   One of the STATUS_* constants, or '' for both.
 * }
 * @return array{items:WP_Post[],total:int,pages:int}
 */
function all( array $args = [] ): array {
	$per_page = min( MAX_PER_PAGE, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
	$status   = $args['status'] ?? '';

	$statuses = in_array( $status, [ STATUS_SUBSCRIBED, STATUS_UNSUBSCRIBED ], true )
		? [ $status ]
		: [ STATUS_SUBSCRIBED, STATUS_UNSUBSCRIBED ];

	$query = new \WP_Query(
		[
			'post_type'      => POST_TYPE,
			'post_status'    => $statuses,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		]
	);

	return [
		'items' => $query->posts,
		'total' => (int) $query->found_posts,
		'pages' => (int) $query->max_num_pages,
	];
}

/**
 * How many subscribers sit in each status, for the admin filter tabs.
 *
 * @return array{templ_subscribed:int,templ_unsubscribed:int}
 */
function counts(): array {
	$counts = (array) wp_count_posts( POST_TYPE );

	return [
		STATUS_SUBSCRIBED   => (int) ( $counts[ STATUS_SUBSCRIBED ] ?? 0 ),
		STATUS_UNSUBSCRIBED => (int) ( $counts[ STATUS_UNSUBSCRIBED ] ?? 0 ),
	];
}

/**
 * Loads one subscriber, refusing anything that is not one on this site.
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
 * Erases a subscriber. The GDPR erasure path; there is no soft version.
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
 * Shapes a subscriber for the REST response. The token stays out of it, because
 * a key holder reading the list has no business with individual unsubscribe
 * tokens; those belong only in the links a frontend embeds in its own email.
 *
 * @param WP_Post $post Subscriber post.
 * @return array{id:int,email:string,name:string,status:string,subscribed_at:string,unsubscribed_at:string}
 */
function to_array( WP_Post $post ): array {
	return [
		'id'              => (int) $post->ID,
		'email'           => $post->post_title,
		'name'            => (string) get_post_meta( $post->ID, META_NAME, true ),
		'status'          => $post->post_status,
		'subscribed_at'   => (string) get_post_meta( $post->ID, META_SUBSCRIBED_AT, true ),
		'unsubscribed_at' => (string) get_post_meta( $post->ID, META_UNSUBSCRIBED_AT, true ),
	];
}
