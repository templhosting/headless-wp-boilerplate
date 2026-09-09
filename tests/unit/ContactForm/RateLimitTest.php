<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Unit\ContactForm;

use Brain\Monkey\Functions;
use Templ\Headless\ContactForm\RateLimit;
use Templ\Headless\Tests\Unit\TestCase;
use WP_Error;

/**
 * Covers the rate-limit window arithmetic against stubbed transient storage.
 */
final class RateLimitTest extends TestCase {

	/**
	 * Stand-in transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	protected function setUp(): void {
		parent::setUp();

		$this->transients = [];

		Functions\when( 'get_transient' )->alias(
			fn( $key ) => $this->transients[ $key ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	public function test_the_first_request_passes(): void {
		$this->assertTrue( RateLimit\check( 'hash' ) );
	}

	public function test_requests_up_to_the_limit_pass(): void {
		for ( $i = 0; $i < RateLimit\MAX_PER_WINDOW; $i++ ) {
			$this->assertTrue( RateLimit\check( 'hash' ), "request $i should pass" );
		}
	}

	public function test_the_request_over_the_limit_is_rejected(): void {
		for ( $i = 0; $i < RateLimit\MAX_PER_WINDOW; $i++ ) {
			RateLimit\check( 'hash' );
		}

		$result = RateLimit\check( 'hash' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'templ_contact_form_rate_limited', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	public function test_separate_senders_have_separate_windows(): void {
		for ( $i = 0; $i < RateLimit\MAX_PER_WINDOW; $i++ ) {
			RateLimit\check( 'sender-a' );
		}

		$this->assertTrue( RateLimit\check( 'sender-b' ) );
	}

	public function test_a_filter_can_lower_the_limit(): void {
		Functions\when( 'apply_filters' )->justReturn(
			[
				'max'    => 1,
				'window' => HOUR_IN_SECONDS,
			]
		);

		$this->assertTrue( RateLimit\check( 'hash' ) );
		$this->assertInstanceOf( WP_Error::class, RateLimit\check( 'hash' ) );
	}
}
