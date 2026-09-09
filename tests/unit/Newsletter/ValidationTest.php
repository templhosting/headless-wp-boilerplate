<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Unit\Newsletter;

use Brain\Monkey\Functions;
use Templ\Headless\Newsletter\Validation;
use Templ\Headless\Tests\Unit\TestCase;
use WP_Error;

/**
 * Covers the newsletter validator, including email normalisation and the
 * honeypot, which the REST layer relies on to answer bots with a fake success.
 */
final class ValidationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	public function test_a_valid_subscribe_passes(): void {
		$result = Validation\validate(
			[
				'email' => 'sub@example.com',
				'name'  => 'Sub',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'sub@example.com', $result['email'] );
	}

	public function test_email_is_lowercased_and_trimmed(): void {
		$result = Validation\validate( [ 'email' => '  Sub@Example.COM  ' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'sub@example.com', $result['email'] );
	}

	public function test_missing_email_is_an_error(): void {
		$result = Validation\validate( [] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'templ_newsletter_email_required', $result->get_error_codes() );
	}

	public function test_invalid_email_is_an_error(): void {
		$result = Validation\validate( [ 'email' => 'nope' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'templ_newsletter_email_invalid', $result->get_error_codes() );
	}

	public function test_name_is_optional(): void {
		$result = Validation\validate( [ 'email' => 'sub@example.com' ] );

		$this->assertIsArray( $result );
		$this->assertSame( '', $result['name'] );
	}

	public function test_name_over_the_boundary_fails(): void {
		$result = Validation\validate(
			[
				'email' => 'sub@example.com',
				'name'  => str_repeat( 'a', Validation\NAME_MAX + 1 ),
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'templ_newsletter_name_too_long', $result->get_error_codes() );
	}

	public function test_a_filled_honeypot_is_rejected_before_any_other_check(): void {
		$result = Validation\validate(
			[
				'email'                   => 'sub@example.com',
				Validation\HONEYPOT_FIELD => 'http://spam',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'templ_newsletter_honeypot', $result->get_error_code() );
	}
}
