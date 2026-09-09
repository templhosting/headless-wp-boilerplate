<?php
/**
 * The unsubscribe token: the one credential in this repo that is not an API key.
 *
 * A subscriber gets a token when they sign up, and it goes into the unsubscribe
 * link a frontend embeds in the email it sends. That link is opened from an
 * email client, which cannot carry an API key, so the token authenticates the
 * one action it is for and nothing else: it unsubscribes exactly its own
 * subscriber and can do nothing more. generate() is free of WordPress state so
 * the unit suite covers it; find_subscriber() is the WordPress-backed lookup.
 *
 * @package Templ\Headless\Newsletter
 */

namespace Templ\Headless\Newsletter\Token;

use Templ\Headless\Newsletter\PostType;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Bytes of entropy behind a token. 32 bytes is 256 bits, so the token is not
 * guessable, which is what lets the unsubscribe route skip the API key.
 */
const BYTES = 32;

/**
 * Mints an unsubscribe token.
 *
 * @return string Lowercase hex, BYTES * 2 characters.
 */
function generate(): string {
	return bin2hex( random_bytes( BYTES ) );
}

/**
 * Whether a string could be a token at all, before touching the database.
 *
 * @param string $value Untrusted input.
 * @return bool
 */
function looks_like_token( string $value ): bool {
	return 1 === preg_match( '/^[0-9a-f]{' . ( BYTES * 2 ) . '}$/', $value );
}

/**
 * Finds the subscriber a token belongs to.
 *
 * The stored token is compared with hash_equals() rather than trusted from the
 * meta query alone: the habit of a timing-safe comparison on anything that
 * gates an action is worth keeping even where the lookup already matched.
 *
 * @param string $token A token as it arrived in the request.
 * @return WP_Post|null
 */
function find_subscriber( string $token ): ?WP_Post {
	if ( ! looks_like_token( $token ) ) {
		return null;
	}

	$posts = get_posts(
		[
			'post_type'        => PostType\POST_TYPE,
			'post_status'      => [ PostType\STATUS_SUBSCRIBED, PostType\STATUS_UNSUBSCRIBED ],
			'posts_per_page'   => 1,
			'no_found_rows'    => true,
			'suppress_filters' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The lookup is the point: the request arrives with a token and nothing else. postmeta.meta_key is indexed and the row count per site is the subscriber count.
			'meta_key'         => PostType\META_TOKEN,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
			'meta_value'       => $token,
		]
	);

	if ( ! $posts ) {
		return null;
	}

	$post = $posts[0];

	if ( ! hash_equals( (string) get_post_meta( $post->ID, PostType\META_TOKEN, true ), $token ) ) {
		return null;
	}

	return $post;
}
