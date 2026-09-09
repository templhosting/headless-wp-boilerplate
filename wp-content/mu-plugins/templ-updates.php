<?php
/**
 * Plugin Name: Templ Headless Updates
 * Description: Restores core automatic updates on a site whose wp-content is a git checkout.
 * Version: 0.1.0
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Author: Templ
 * Author URI: https://templ.io/
 * License: MIT
 *
 * WordPress refuses to update itself in the background when it finds a .git,
 * .svn, .hg or .bzr directory at or above the directory being updated. The
 * reasoning is sound: an update that rewrites files somebody has under version
 * control produces a working tree nobody asked for, and possibly a merge
 * conflict on a production site.
 *
 * The check is a directory scan, not a question git is asked. It walks up from
 * the target directory to the filesystem root and stops at the first VCS
 * directory it sees. A repository like this one, which tracks wp-content and
 * sits one level below the site root, therefore switches off core updates as a
 * side effect of existing -- including security releases.
 *
 * This file narrows the check rather than removing it:
 *
 *   - Core updates are allowed. Core is not in this repository. WordPress
 *     replacing wp-admin, wp-includes and the loader files touches nothing git
 *     is tracking, so there is no working tree to damage.
 *
 *   - Plugin and theme updates stay blocked, untouched. wp-content/plugins and
 *     wp-content/themes are in this repository, and core's check is exactly
 *     right about them: an automatic update there would overwrite files that
 *     are supposed to arrive by deploy. They are updated by changing the
 *     repository, not by the site changing itself.
 *
 * Fork this file if you also track WordPress core in git. The whole argument
 * above rests on core being outside the repository, and it is not something
 * this plugin can verify for itself.
 *
 * @package Templ\Headless
 */

namespace Templ\Headless\Updates;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every hook this plugin owns.
 *
 * @return void
 */
function bootstrap(): void {
	add_filter( 'automatic_updates_is_vcs_checkout', __NAMESPACE__ . '\\allow_core_updates', 10, 2 );
}

/**
 * Tells core that the site root is not a checkout, and says nothing about
 * anywhere else.
 *
 * The context is the directory an update is about to write to: ABSPATH for
 * core, WP_PLUGIN_DIR for a plugin, the theme root for a theme. Only the first
 * is answered here, so the two that live in this repository keep the answer
 * core worked out for itself.
 *
 * @param bool   $is_checkout Whether core found a version control directory.
 * @param string $context     Absolute path of the directory being updated.
 * @return bool
 */
function allow_core_updates( bool $is_checkout, string $context ): bool {
	if ( untrailingslashit( $context ) !== untrailingslashit( ABSPATH ) ) {
		return $is_checkout;
	}

	return false;
}

bootstrap();
