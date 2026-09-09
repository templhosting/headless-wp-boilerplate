<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Multisite;

use Templ\Headless\Tests\Shared\MakesRequests;

/**
 * A network administrator's power over a subsite does not extend to its API.
 * The endpoints answer to keys, not to WordPress roles, so a super admin with
 * no key is as anonymous to them as anyone else. Without this, "authenticated"
 * would quietly mean "authenticated as a WordPress user", which is not the
 * contract a headless frontend is written against.
 */
final class NetworkAdminTest extends MultisiteTestCase {

	use MakesRequests;

	public function test_an_anonymous_request_is_refused_even_though_a_super_admin_exists(): void {
		$supers = get_super_admins();
		$this->assertNotEmpty( $supers, 'The dev network must have a super admin.' );

		$response = $this->get( $this->subsite_url, '/templ-headless/v1/ping' );

		$this->assertSame( 401, $response['status'] );
		$this->assertSame( 'templ_headless_missing_key', $response['body']['code'] );
	}
}
