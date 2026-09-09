<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Integration;

use Templ\Headless\Tests\Shared\MakesRequests;
use Templ\Headless\Tests\Shared\MintsKeys;

/**
 * The full auth matrix against the trivial /ping endpoint, so the auth layer is
 * proven on its own before any feature plugin is in the picture.
 */
final class KeysRestTest extends IntegrationTestCase {

	use MakesRequests;
	use MintsKeys;

	protected function tearDown(): void {
		$this->clean_up_keys();
		parent::tearDown();
	}

	public function test_a_valid_key_pings_its_own_site(): void {
		$key = $this->mint_key( $this->subsite_id );

		$response = $this->get( $this->subsite_url, '/templ-headless/v1/ping', $key );

		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['body']['ok'] );
		$this->assertSame( $this->subsite_id, $response['body']['site_id'] );
	}

	public function test_no_key_is_rejected(): void {
		$response = $this->get( $this->subsite_url, '/templ-headless/v1/ping' );

		$this->assertSame( 401, $response['status'] );
		$this->assertSame( 'templ_headless_missing_key', $response['body']['code'] );
	}

	public function test_a_garbage_key_is_rejected(): void {
		$response = $this->get( $this->subsite_url, '/templ-headless/v1/ping', 'thl_not_a_real_key' );

		$this->assertSame( 401, $response['status'] );
		$this->assertSame( 'templ_headless_invalid_key', $response['body']['code'] );
	}

	public function test_a_revoked_key_is_rejected(): void {
		$key = $this->mint_key( $this->subsite_id );
		$this->revoke_key( $this->subsite_id, $key );

		$response = $this->get( $this->subsite_url, '/templ-headless/v1/ping', $key );

		$this->assertSame( 401, $response['status'] );
		$this->assertSame( 'templ_headless_revoked_key', $response['body']['code'] );
	}

	public function test_the_key_post_type_is_not_rest_exposed(): void {
		$response = $this->get( $this->subsite_url, '/wp/v2/templ_api_key' );

		$this->assertSame( 404, $response['status'] );
	}
}
