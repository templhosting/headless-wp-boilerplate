<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Multisite;

use Templ\Headless\Tests\Shared\CleansUp;
use Templ\Headless\Tests\Shared\MakesRequests;
use Templ\Headless\Tests\Shared\MintsKeys;

/**
 * Content created on one site never appears in another's list endpoints. A key
 * being per-site is only half the promise; the data behind it has to be too.
 */
final class DataIsolationTest extends MultisiteTestCase {

	use MakesRequests;
	use MintsKeys;
	use CleansUp;

	private string $main_key;
	private string $subsite_key;

	protected function setUp(): void {
		parent::setUp();
		$this->purge_posts( $this->main_id, [ 'templ_submission', 'templ_subscriber' ] );
		$this->purge_posts( $this->subsite_id, [ 'templ_submission', 'templ_subscriber' ] );
		$this->reset_rate_limits( $this->main_id );
		$this->reset_rate_limits( $this->subsite_id );
		$this->main_key    = $this->mint_key( $this->main_id );
		$this->subsite_key = $this->mint_key( $this->subsite_id );
	}

	protected function tearDown(): void {
		$this->purge_posts( $this->main_id, [ 'templ_submission', 'templ_subscriber' ] );
		$this->purge_posts( $this->subsite_id, [ 'templ_submission', 'templ_subscriber' ] );
		$this->reset_rate_limits( $this->main_id );
		$this->reset_rate_limits( $this->subsite_id );
		$this->clean_up_keys();
		parent::tearDown();
	}

	public function test_a_submission_on_one_site_is_invisible_on_another(): void {
		$this->post_json(
			$this->subsite_url,
			'/templ-contact-form/v1/submissions',
			[
				'name'    => 'Subsite Only',
				'email'   => 'subsite@example.com',
				'message' => 'A message on the subsite.',
			],
			$this->subsite_key
		);

		$main_list = $this->get( $this->main_url, '/templ-contact-form/v1/submissions', $this->main_key );

		$this->assertSame( '0', (string) $main_list['headers']['x-wp-total'] );
	}

	public function test_a_subscriber_on_one_site_is_invisible_on_another(): void {
		$this->post_json(
			$this->subsite_url,
			'/templ-newsletter/v1/subscribers',
			[ 'email' => 'subsite@example.com' ],
			$this->subsite_key
		);

		$main_list = $this->get( $this->main_url, '/templ-newsletter/v1/subscribers', $this->main_key );

		$this->assertSame( '0', (string) $main_list['headers']['x-wp-total'] );
	}
}
