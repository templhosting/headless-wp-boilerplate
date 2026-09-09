<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Shared;

/**
 * Guards the multisite site-switching functions so the same test helpers run
 * unchanged on a single site.
 *
 * The switch_to_blog() and restore_current_blog() functions are defined by
 * core only when multisite is active. The integration suite runs against a
 * single site by default and a network when TEMPL_HEADLESS_MULTISITE=1;
 * calling the bare functions would fatal in the former. On a single site there
 * is only ever one blog, so switching to it is a no-op and these wrappers
 * simply do nothing.
 */
trait SwitchesSites {

	/**
	 * Switches to a site if the install is a network; a no-op otherwise.
	 *
	 * @param int $blog_id Site to switch to.
	 * @return void
	 */
	protected function switch_to_site( int $blog_id ): void {
		if ( is_multisite() ) {
			switch_to_blog( $blog_id );
		}
	}

	/**
	 * Restores the site switched away from; a no-op on a single site.
	 *
	 * @return void
	 */
	protected function restore_site(): void {
		if ( is_multisite() ) {
			restore_current_blog();
		}
	}
}
