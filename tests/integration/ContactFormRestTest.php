<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Integration;

use Templ\Headless\Tests\Shared\CleansUp;
use Templ\Headless\Tests\Shared\MakesRequests;
use Templ\Headless\Tests\Shared\MintsKeys;

/**
 * The contact form CRUD and abuse-control matrix, over real HTTP.
 */
final class ContactFormRestTest extends IntegrationTestCase {

	use MakesRequests;
	use MintsKeys;
	use CleansUp;

	private string $key;

	protected function setUp(): void {
		parent::setUp();
		$this->purge_posts( $this->subsite_id, [ 'templ_submission' ] );
		$this->reset_rate_limits( $this->subsite_id );
		$this->key = $this->mint_key( $this->subsite_id );
	}

	protected function tearDown(): void {
		$this->purge_posts( $this->subsite_id, [ 'templ_submission' ] );
		$this->reset_rate_limits( $this->subsite_id );
		$this->clean_up_keys();
		parent::tearDown();
	}

	/**
	 * @return array<string, string>
	 */
	private function valid_body(): array {
		return [
			'name'    => 'Ada Lovelace',
			'email'   => 'ada@example.com',
			'subject' => 'Hello',
			'message' => 'A message.',
		];
	}

	public function test_a_valid_submission_is_created(): void {
		$response = $this->post_json( $this->subsite_url, '/templ-contact-form/v1/submissions', $this->valid_body(), $this->key );

		$this->assertSame( 201, $response['status'] );
		$this->assertIsInt( $response['body']['id'] );
		$this->assertGreaterThan( 0, $response['body']['id'] );
	}

	public function test_a_submission_without_a_key_is_rejected(): void {
		$response = $this->post_json( $this->subsite_url, '/templ-contact-form/v1/submissions', $this->valid_body() );

		$this->assertSame( 401, $response['status'] );
	}

	public function test_a_submission_missing_message_is_a_400(): void {
		$body = $this->valid_body();
		unset( $body['message'] );

		$response = $this->post_json( $this->subsite_url, '/templ-contact-form/v1/submissions', $body, $this->key );

		$this->assertSame( 400, $response['status'] );
	}

	public function test_a_filled_honeypot_returns_201_but_stores_nothing(): void {
		$body            = $this->valid_body();
		$body['website'] = 'http://spam.example';

		$response = $this->post_json( $this->subsite_url, '/templ-contact-form/v1/submissions', $body, $this->key );

		$this->assertSame( 201, $response['status'] );

		$list = $this->get( $this->subsite_url, '/templ-contact-form/v1/submissions', $this->key );
		$this->assertSame( '0', (string) $list['headers']['x-wp-total'] );
	}

	public function test_the_request_over_the_rate_limit_is_a_429(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->post_json( $this->subsite_url, '/templ-contact-form/v1/submissions', $this->valid_body(), $this->key );
		}

		$response = $this->post_json( $this->subsite_url, '/templ-contact-form/v1/submissions', $this->valid_body(), $this->key );

		$this->assertSame( 429, $response['status'] );
	}

	public function test_the_list_carries_pagination_headers(): void {
		$this->post_json( $this->subsite_url, '/templ-contact-form/v1/submissions', $this->valid_body(), $this->key );

		$response = $this->get( $this->subsite_url, '/templ-contact-form/v1/submissions', $this->key );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( '1', (string) $response['headers']['x-wp-total'] );
		$this->assertArrayHasKey( 'x-wp-totalpages', $response['headers'] );
	}

	public function test_a_single_submission_can_be_read_and_deleted(): void {
		$created = $this->post_json( $this->subsite_url, '/templ-contact-form/v1/submissions', $this->valid_body(), $this->key );
		$id      = (int) $created['body']['id'];

		$read = $this->get( $this->subsite_url, "/templ-contact-form/v1/submissions/{$id}", $this->key );
		$this->assertSame( 200, $read['status'] );
		$this->assertSame( 'ada@example.com', $read['body']['email'] );

		$deleted = $this->delete( $this->subsite_url, "/templ-contact-form/v1/submissions/{$id}", $this->key );
		$this->assertSame( 200, $deleted['status'] );

		$gone = $this->get( $this->subsite_url, "/templ-contact-form/v1/submissions/{$id}", $this->key );
		$this->assertSame( 404, $gone['status'] );
	}

	public function test_a_script_tag_is_stripped_before_storage(): void {
		$body            = $this->valid_body();
		$body['message'] = 'Before <script>alert(1)</script> after';

		$created = $this->post_json( $this->subsite_url, '/templ-contact-form/v1/submissions', $body, $this->key );
		$id      = (int) $created['body']['id'];

		$read = $this->get( $this->subsite_url, "/templ-contact-form/v1/submissions/{$id}", $this->key );

		// sanitize_textarea_field strips the tag on the way in, so the stored
		// value never carries executable markup for the admin screen to render.
		$this->assertStringNotContainsString( '<script>', $read['body']['message'] );
		$this->assertStringContainsString( 'Before', $read['body']['message'] );
		$this->assertStringContainsString( 'after', $read['body']['message'] );
	}
}
