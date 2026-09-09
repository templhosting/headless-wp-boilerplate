<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Integration;

use Templ\Headless\Tests\Shared\CleansUp;
use Templ\Headless\Tests\Shared\MakesRequests;
use Templ\Headless\Tests\Shared\MintsKeys;

/**
 * The newsletter matrix: idempotent subscribe, keyless token unsubscribe, and
 * the status filter, over real HTTP.
 */
final class NewsletterRestTest extends IntegrationTestCase {

	use MakesRequests;
	use MintsKeys;
	use CleansUp;

	private string $key;

	protected function setUp(): void {
		parent::setUp();
		$this->purge_posts( $this->subsite_id, [ 'templ_subscriber' ] );
		$this->key = $this->mint_key( $this->subsite_id );
	}

	protected function tearDown(): void {
		$this->purge_posts( $this->subsite_id, [ 'templ_subscriber' ] );
		$this->clean_up_keys();
		parent::tearDown();
	}

	public function test_a_new_subscribe_is_a_201(): void {
		$response = $this->post_json(
			$this->subsite_url,
			'/templ-newsletter/v1/subscribers',
			[ 'email' => 'sub@example.com' ],
			$this->key
		);

		$this->assertSame( 201, $response['status'] );
		$this->assertSame( 'templ_subscribed', $response['body']['status'] );
	}

	public function test_re_subscribing_is_a_200_and_does_not_duplicate(): void {
		$first  = $this->post_json( $this->subsite_url, '/templ-newsletter/v1/subscribers', [ 'email' => 'sub@example.com' ], $this->key );
		$second = $this->post_json( $this->subsite_url, '/templ-newsletter/v1/subscribers', [ 'email' => 'sub@example.com' ], $this->key );

		$this->assertSame( 201, $first['status'] );
		$this->assertSame( 200, $second['status'] );
		$this->assertSame( $first['body']['id'], $second['body']['id'] );

		$list = $this->get( $this->subsite_url, '/templ-newsletter/v1/subscribers', $this->key );
		$this->assertSame( '1', (string) $list['headers']['x-wp-total'] );
	}

	public function test_casing_and_whitespace_are_normalised(): void {
		$first  = $this->post_json( $this->subsite_url, '/templ-newsletter/v1/subscribers', [ 'email' => 'sub@example.com' ], $this->key );
		$second = $this->post_json( $this->subsite_url, '/templ-newsletter/v1/subscribers', [ 'email' => '  SUB@Example.com ' ], $this->key );

		$this->assertSame( $first['body']['id'], $second['body']['id'] );
	}

	public function test_a_filled_honeypot_returns_201_but_stores_nothing(): void {
		$response = $this->post_json(
			$this->subsite_url,
			'/templ-newsletter/v1/subscribers',
			[
				'email'   => 'bot@example.com',
				'website' => 'http://spam.example',
			],
			$this->key
		);

		$this->assertSame( 201, $response['status'] );

		$list = $this->get( $this->subsite_url, '/templ-newsletter/v1/subscribers', $this->key );
		$this->assertSame( '0', (string) $list['headers']['x-wp-total'] );
	}

	public function test_unsubscribe_by_token_needs_no_key(): void {
		$this->post_json( $this->subsite_url, '/templ-newsletter/v1/subscribers', [ 'email' => 'sub@example.com' ], $this->key );

		switch_to_blog( $this->subsite_id );
		$post  = \Templ\Headless\Newsletter\PostType\find_by_email( 'sub@example.com' );
		$token = get_post_meta( $post->ID, \Templ\Headless\Newsletter\PostType\META_TOKEN, true );
		restore_current_blog();

		$response = $this->get( $this->subsite_url, '/templ-newsletter/v1/unsubscribe?token=' . rawurlencode( $token ) );

		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['body']['unsubscribed'] );
	}

	public function test_a_bad_token_is_a_404_not_a_500(): void {
		$response = $this->get( $this->subsite_url, '/templ-newsletter/v1/unsubscribe?token=deadbeef' );

		$this->assertSame( 404, $response['status'] );
	}

	public function test_re_subscribing_after_unsubscribe_reactivates_the_same_record(): void {
		$first = $this->post_json( $this->subsite_url, '/templ-newsletter/v1/subscribers', [ 'email' => 'sub@example.com' ], $this->key );

		$this->post_json( $this->subsite_url, '/templ-newsletter/v1/subscribers/unsubscribe', [ 'email' => 'sub@example.com' ], $this->key );

		$again = $this->post_json( $this->subsite_url, '/templ-newsletter/v1/subscribers', [ 'email' => 'sub@example.com' ], $this->key );

		$this->assertSame( $first['body']['id'], $again['body']['id'] );
		$this->assertSame( 'templ_subscribed', $again['body']['status'] );
	}

	public function test_the_status_filter_only_returns_matching_records(): void {
		$this->post_json( $this->subsite_url, '/templ-newsletter/v1/subscribers', [ 'email' => 'active@example.com' ], $this->key );
		$this->post_json( $this->subsite_url, '/templ-newsletter/v1/subscribers', [ 'email' => 'gone@example.com' ], $this->key );
		$this->post_json( $this->subsite_url, '/templ-newsletter/v1/subscribers/unsubscribe', [ 'email' => 'gone@example.com' ], $this->key );

		$unsubscribed = $this->get( $this->subsite_url, '/templ-newsletter/v1/subscribers?status=templ_unsubscribed', $this->key );

		$this->assertSame( '1', (string) $unsubscribed['headers']['x-wp-total'] );
		$this->assertSame( 'gone@example.com', $unsubscribed['body'][0]['email'] );
	}

	public function test_a_subscriber_can_be_deleted(): void {
		$created = $this->post_json( $this->subsite_url, '/templ-newsletter/v1/subscribers', [ 'email' => 'sub@example.com' ], $this->key );
		$id      = (int) $created['body']['id'];

		$deleted = $this->delete( $this->subsite_url, "/templ-newsletter/v1/subscribers/{$id}", $this->key );
		$this->assertSame( 200, $deleted['status'] );

		$list = $this->get( $this->subsite_url, '/templ-newsletter/v1/subscribers', $this->key );
		$this->assertSame( '0', (string) $list['headers']['x-wp-total'] );
	}
}
