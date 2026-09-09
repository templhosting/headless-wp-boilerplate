<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Integration;

use Templ\Headless\Tests\Shared\MakesRequests;

/**
 * The user routes must be gone for anonymous callers and present for logged-in
 * ones. The second half is the one that matters: without it the test would pass
 * just as well with the block editor's author dropdown broken.
 */
final class UserEnumerationTest extends IntegrationTestCase {

	use MakesRequests;

	public function test_the_users_route_is_absent_from_the_anonymous_index(): void {
		$response = $this->get( $this->subsite_url, '/wp/v2' );

		$this->assertSame( 200, $response['status'] );
		$this->assertArrayNotHasKey( '/wp/v2/users', $response['body']['routes'] );
	}

	public function test_the_users_endpoint_is_a_404_for_an_anonymous_caller(): void {
		$response = $this->get( $this->subsite_url, '/wp/v2/users' );

		$this->assertSame( 404, $response['status'] );
		$this->assertSame( 'rest_no_route', $response['body']['code'] );
	}

	public function test_a_logged_in_caller_keeps_the_users_route(): void {
		$admin = get_users(
			[
				'role'   => 'administrator',
				'number' => 1,
			]
		);
		$this->assertNotEmpty( $admin, 'The dev network must have an administrator.' );

		wp_set_current_user( (int) $admin[0]->ID );

		// The plugin filters rest_endpoints on who is logged in at the moment
		// the route map is built, so applying it here with an admin current
		// user proves the logged-in half without a browser session and nonce.
		$endpoints = apply_filters( 'rest_endpoints', rest_get_server()->get_routes() );

		$this->assertArrayHasKey( '/wp/v2/users', $endpoints );

		wp_set_current_user( 0 );
	}
}
