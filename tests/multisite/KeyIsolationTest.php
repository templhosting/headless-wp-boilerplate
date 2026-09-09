<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Multisite;

use Templ\Headless\Tests\Shared\MakesRequests;
use Templ\Headless\Tests\Shared\MintsKeys;

/**
 * The single most important test in the repo: a key minted on one site does not
 * authenticate against another. This is the promise the agency model rests on,
 * so it is checked against every protected endpoint of every plugin, not just
 * the ping route.
 */
final class KeyIsolationTest extends MultisiteTestCase {

	use MakesRequests;
	use MintsKeys;

	protected function tearDown(): void {
		$this->clean_up_keys();
		parent::tearDown();
	}

	public function test_a_subsite_key_does_not_work_on_the_main_site(): void {
		$key = $this->mint_key( $this->subsite_id );

		$response = $this->get( $this->main_url, '/templ-headless/v1/ping', $key );

		$this->assertSame( 401, $response['status'] );
		$this->assertSame( 'templ_headless_invalid_key', $response['body']['code'] );
	}

	public function test_a_main_site_key_does_not_work_on_a_subsite(): void {
		$key = $this->mint_key( $this->main_id );

		$response = $this->get( $this->subsite_url, '/templ-headless/v1/ping', $key );

		$this->assertSame( 401, $response['status'] );
	}

	/**
	 * @dataProvider protected_write_endpoints
	 *
	 * @param string $path REST path of a key-protected write endpoint.
	 * @param array  $body A body that would be valid if the key were accepted.
	 */
	public function test_a_foreign_key_is_refused_by_every_protected_endpoint( string $path, array $body ): void {
		$key = $this->mint_key( $this->main_id );

		$response = $this->post_json( $this->subsite_url, $path, $body, $key );

		$this->assertSame(
			401,
			$response['status'],
			"A key from another site reached {$path}. Per-site isolation is broken."
		);
	}

	/**
	 * @return array<string, array{0:string,1:array}>
	 */
	public static function protected_write_endpoints(): array {
		return [
			'contact submissions'    => [
				'/templ-contact-form/v1/submissions',
				[
					'name'    => 'Ada',
					'email'   => 'ada@example.com',
					'message' => 'Hi.',
				],
			],
			'newsletter subscribers' => [
				'/templ-newsletter/v1/subscribers',
				[ 'email' => 'sub@example.com' ],
			],
		];
	}
}
