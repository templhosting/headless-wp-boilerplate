<?php
/**
 * Where keys live.
 *
 * A custom post type rather than a table of its own. Post types are per site
 * without anyone having to remember they are, which is the whole promise this
 * network makes: a key minted on one subsite unlocks that subsite and nothing
 * else. A table would need a blog_id column and a WHERE clause that somebody
 * eventually forgets.
 *
 * The cost is a postmeta lookup on every authenticated request. See AGENTS.md
 * for the point at which that stops being a good trade and what to do then.
 *
 * @package Templ\Headless
 */

namespace Templ\Headless\Keys\Store;

use Templ\Headless\Keys\Hash;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

const POST_TYPE = 'templ_api_key';

/**
 * The SHA-256 of the key. The only copy of it the site ever holds.
 */
const META_HASH = '_templ_headless_key_hash';

/**
 * The leading characters of the key, in the clear, for display.
 */
const META_PREFIX = '_templ_headless_key_prefix';

const META_LAST_USED = '_templ_headless_last_used_at';
const META_REVOKED   = '_templ_headless_revoked_at';

/**
 * An active key.
 */
const STATUS_ACTIVE = 'publish';

/**
 * A key that has been revoked but is kept for the audit trail. Revocation is
 * soft so that "who called this endpoint last March" stays answerable.
 */
const STATUS_REVOKED = 'draft';

/**
 * How often a key's last-used stamp is allowed to hit the database. Without
 * this a busy endpoint writes postmeta on every single request to record
 * something nobody reads more than once a day.
 */
const LAST_USED_THROTTLE = HOUR_IN_SECONDS;

/**
 * Most keys any listing will show.
 */
const LIST_LIMIT = 100;

/**
 * Registers the post type on every site of the network.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'init', __NAMESPACE__ . '\\register' );
}

/**
 * Declares the key post type and its meta.
 *
 * @return void
 */
function register(): void {
	register_post_type(
		POST_TYPE,
		[
			'labels'              => [
				'name'          => __( 'API keys', 'templ-headless' ),
				'singular_name' => __( 'API key', 'templ-headless' ),
			],
			'public'              => false,
			// Not a preference. A key post carries the hash in meta and the
			// label in the title, and the REST API is exactly the surface
			// these keys guard. Exposing the post type there would let a
			// valid key enumerate every other key on the site.
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
		META_HASH      => 'string',
		META_PREFIX    => 'string',
		META_LAST_USED => 'string',
		META_REVOKED   => 'string',
	];

	foreach ( $meta as $key => $type ) {
		register_post_meta(
			POST_TYPE,
			$key,
			[
				'type'          => $type,
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => '__return_false',
			]
		);
	}
}

/**
 * Mints a key and stores its hash.
 *
 * The only function in the codebase that returns plaintext. Whatever calls it
 * has one chance to show the key to a human; there is no second copy.
 *
 * @param string $label Human-readable name for the key.
 * @return array{id:int,label:string,plaintext:string,prefix:string}|WP_Error
 */
function create( string $label ) {
	$label = trim( $label );

	if ( '' === $label ) {
		return new WP_Error(
			'templ_headless_missing_label',
			__( 'A key needs a label, so that revoking the right one later is possible.', 'templ-headless' )
		);
	}

	$plaintext = Hash\generate();
	$prefix    = Hash\display_prefix( $plaintext );

	$post_id = wp_insert_post(
		[
			'post_type'   => POST_TYPE,
			'post_title'  => $label,
			'post_status' => STATUS_ACTIVE,
			'meta_input'  => [
				META_HASH   => Hash\digest( $plaintext ),
				META_PREFIX => $prefix,
			],
		],
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	return [
		'id'        => (int) $post_id,
		'label'     => $label,
		'plaintext' => $plaintext,
		'prefix'    => $prefix,
	];
}

/**
 * Finds the key a hash belongs to, revoked or not.
 *
 * Revoked keys are returned so that the caller can tell "this key was turned
 * off" apart from "this key never existed". Both answer 401 to the client;
 * only the error code differs, and only to somebody already holding the key.
 *
 * @param string $digest Lowercase hex SHA-256 of a key.
 * @return WP_Post|null
 */
function find_by_digest( string $digest ): ?WP_Post {
	$posts = get_posts(
		[
			'post_type'        => POST_TYPE,
			'post_status'      => [ STATUS_ACTIVE, STATUS_REVOKED ],
			'posts_per_page'   => 1,
			'no_found_rows'    => true,
			'suppress_filters' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The lookup is the point: a request arrives with a key and nothing else, so there is no post ID to fetch by. postmeta.meta_key is indexed, and the number of keys on one site is measured in tens.
			'meta_key'         => META_HASH,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
			'meta_value'       => $digest,
		]
	);

	return $posts ? $posts[0] : null;
}

/**
 * Every key on the current site, newest first, up to LIST_LIMIT.
 *
 * The limit is not pagination in disguise. A site with a hundred live API keys
 * has an operational problem that neither this screen nor this storage shape
 * is the right answer to; see the upgrade path in AGENTS.md.
 *
 * @return WP_Post[]
 */
function all(): array {
	return get_posts(
		[
			'post_type'      => POST_TYPE,
			'post_status'    => [ STATUS_ACTIVE, STATUS_REVOKED ],
			'posts_per_page' => LIST_LIMIT,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		]
	);
}

/**
 * Loads one key, refusing anything that is not a key post on this site.
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
 * Whether a key has been revoked.
 *
 * @param WP_Post $post Key post.
 * @return bool
 */
function is_revoked( WP_Post $post ): bool {
	return STATUS_REVOKED === $post->post_status;
}

/**
 * Turns a key off without losing the record of it.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function revoke( int $post_id ): bool {
	$post = find( $post_id );

	if ( null === $post || is_revoked( $post ) ) {
		return false;
	}

	$updated = wp_update_post(
		[
			'ID'          => $post_id,
			'post_status' => STATUS_REVOKED,
		],
		true
	);

	if ( is_wp_error( $updated ) ) {
		return false;
	}

	update_post_meta( $post_id, META_REVOKED, current_time( 'mysql', true ) );

	return true;
}

/**
 * Erases a key and its audit trail.
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
 * Records that a key was used, at most once per LAST_USED_THROTTLE.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function touch_last_used( int $post_id ): void {
	$guard = 'templ_headless_touched_' . $post_id;

	if ( get_transient( $guard ) ) {
		return;
	}

	set_transient( $guard, 1, LAST_USED_THROTTLE );
	update_post_meta( $post_id, META_LAST_USED, current_time( 'mysql', true ) );
}

/**
 * Shapes a key post for display. The hash is deliberately not in here.
 *
 * @param WP_Post $post Key post.
 * @return array{id:int,label:string,prefix:string,status:string,created_at:string,last_used_at:string,revoked_at:string}
 */
function to_array( WP_Post $post ): array {
	return [
		'id'           => (int) $post->ID,
		'label'        => $post->post_title,
		'prefix'       => (string) get_post_meta( $post->ID, META_PREFIX, true ),
		'status'       => is_revoked( $post ) ? 'revoked' : 'active',
		'created_at'   => $post->post_date_gmt,
		'last_used_at' => (string) get_post_meta( $post->ID, META_LAST_USED, true ),
		'revoked_at'   => (string) get_post_meta( $post->ID, META_REVOKED, true ),
	];
}
