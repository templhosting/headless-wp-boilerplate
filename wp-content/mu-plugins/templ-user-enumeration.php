<?php
/**
 * Plugin Name: Templ Headless User Enumeration
 * Description: Hides the core /wp/v2/users REST routes from anonymous callers.
 * Version: 0.1.0
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Author: Templ
 * Author URI: https://templ.io/
 * License: MIT
 *
 * Core answers GET /wp/v2/users to anybody who asks, with the display name,
 * the slug and the avatar of every user who has published a post. The slug is
 * the login name on most sites, so the route hands a stranger half of every
 * credential on the network and a list of which accounts are worth trying.
 * Nothing here needs that to be public: this network serves an API to a
 * frontend that already knows what it wants, and the authors of posts are not
 * part of the deal.
 *
 * The routes are removed for anonymous callers only, rather than removed
 * outright. Logged-in users keep them, still behind core's own permission
 * callbacks, because the block editor reads /wp/v2/users to populate the
 * author dropdown, and an editor who cannot set an author is a broken admin.
 *
 * Removing rather than refusing is deliberate: an anonymous caller gets 404
 * rest_no_route, which is what a route that does not exist looks like, instead
 * of a 401 that confirms there is something there to come back for.
 *
 * REST is only one of the two doors. The other is /?author=1, which core
 * answers with a 301 to /author/<login>/ and so leaks the same names one
 * integer at a time. That one is closed in the headless theme, which redirects
 * on template_redirect at priority 1 specifically to get there before
 * redirect_canonical. Replacing the theme reopens it.
 *
 * Consequence worth knowing: ?_embed on a post no longer resolves the author,
 * because core resolves embedded links by dispatching the users route
 * internally. A headless frontend that needs author names has to be given them
 * another way -- a REST field on the post, or an endpoint of its own that
 * returns only what it is meant to.
 *
 * @package Templ\Headless
 */

namespace Templ\Headless\UserEnumeration;

defined( 'ABSPATH' ) || exit;

/**
 * Every route under this prefix goes.
 *
 * Matched as a prefix rather than listed one by one, so that the sub-routes
 * core hangs off users -- application passwords today, whatever it adds next
 * release -- cannot quietly reopen the hole this plugin exists to close.
 */
const ROUTE_PREFIX = '/wp/v2/users';

/**
 * Registers every hook this plugin owns.
 *
 * @return void
 */
function bootstrap(): void {
	add_filter( 'rest_endpoints', __NAMESPACE__ . '\\remove_user_routes' );
}

/**
 * Drops the user routes for callers WordPress does not recognise.
 *
 * Runs late enough to know who is asking: core resolves the current user while
 * authenticating the request, which happens before it builds the route map
 * this filter is applied to.
 *
 * An API key is not a WordPress user, so a key holder is anonymous here and is
 * refused too. That is the intent. Keys exist to reach the endpoints this repo
 * ships, and none of them are about users.
 *
 * @param array $endpoints Route map, keyed by route pattern.
 * @return array
 */
function remove_user_routes( array $endpoints ): array {
	if ( is_user_logged_in() ) {
		return $endpoints;
	}

	foreach ( array_keys( $endpoints ) as $route ) {
		if ( str_starts_with( (string) $route, ROUTE_PREFIX ) ) {
			unset( $endpoints[ $route ] );
		}
	}

	return $endpoints;
}

bootstrap();
