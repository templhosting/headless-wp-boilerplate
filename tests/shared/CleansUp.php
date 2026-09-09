<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Shared;

/**
 * Deletes the content a test creates, so a re-run against a long-lived dev
 * database is deterministic rather than accumulating rows every pass.
 */
trait CleansUp {

	/**
	 * Deletes every post of the given types on a site.
	 *
	 * @param int      $blog_id    Site to clean.
	 * @param string[] $post_types Post types to purge.
	 * @return void
	 */
	protected function purge_posts( int $blog_id, array $post_types ): void {
		switch_to_blog( $blog_id );

		foreach ( $post_types as $post_type ) {
			$ids = get_posts(
				[
					'post_type'      => $post_type,
					// 'any' excludes statuses registered with exclude_from_search,
					// which the subscriber statuses are, so they have to be named
					// explicitly or a purge silently leaves every subscriber behind.
					'post_status'    => array_keys( get_post_stati() ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]
			);

			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
			}
		}

		restore_current_blog();
	}

	/**
	 * Clears the contact form rate-limit counters on a site, so a test that
	 * spent a sender's window does not starve the next test. Every HTTP request
	 * in the suite arrives from the same container IP and so shares one bucket,
	 * which makes this reset load-bearing rather than tidy-up.
	 *
	 * @param int $blog_id Site whose counters to clear.
	 * @return void
	 */
	protected function reset_rate_limits( int $blog_id ): void {
		switch_to_blog( $blog_id );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Clearing test-created transients; there is no options API call for "delete every transient matching a prefix", and $wpdb->options is now the switched site's table.
		$rows = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_templ\\_cf\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_templ\\_cf\\_rl\\_%'" );

		foreach ( $rows as $option_name ) {
			// delete_option() rather than a bare DELETE, so the object cache
			// entry goes too and a cached counter cannot outlive the row.
			delete_option( $option_name );
		}

		restore_current_blog();
	}
}
