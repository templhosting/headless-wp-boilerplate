<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Unit\ContactForm;

use Brain\Monkey\Functions;
use Templ\Headless\ContactForm\Validation;
use Templ\Headless\Tests\Unit\TestCase;
use WP_Error;

/**
 * Covers the contact form validator, field by field and in aggregate.
 */
final class ValidationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function valid_input(): array {
		return [
			'name'    => 'Ada Lovelace',
			'email'   => 'ada@example.com',
			'subject' => 'Hello',
			'message' => 'A message.',
		];
	}

	public function test_a_valid_submission_passes_and_is_sanitised(): void {
		$result = Validation\validate( $this->valid_input() );

		$this->assertIsArray( $result );
		$this->assertSame( 'ada@example.com', $result['email'] );
		$this->assertSame( 'A message.', $result['message'] );
	}

	public function test_subject_is_optional(): void {
		$input = $this->valid_input();
		unset( $input['subject'] );

		$result = Validation\validate( $input );

		$this->assertIsArray( $result );
		$this->assertSame( '', $result['subject'] );
	}

	public function test_missing_name_is_an_error(): void {
		$input = $this->valid_input();
		unset( $input['name'] );

		$result = Validation\validate( $input );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'templ_contact_form_name_required', $result->get_error_codes() );
	}

	public function test_missing_email_is_an_error(): void {
		$input = $this->valid_input();
		unset( $input['email'] );

		$result = Validation\validate( $input );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'templ_contact_form_email_required', $result->get_error_codes() );
	}

	public function test_invalid_email_is_an_error(): void {
		$input          = $this->valid_input();
		$input['email'] = 'not-an-email';

		$result = Validation\validate( $input );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'templ_contact_form_email_invalid', $result->get_error_codes() );
	}

	public function test_missing_message_is_an_error(): void {
		$input = $this->valid_input();
		unset( $input['message'] );

		$result = Validation\validate( $input );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'templ_contact_form_message_required', $result->get_error_codes() );
	}

	public function test_name_at_the_boundary_passes(): void {
		$input         = $this->valid_input();
		$input['name'] = str_repeat( 'a', Validation\NAME_MAX );

		$this->assertIsArray( Validation\validate( $input ) );
	}

	public function test_name_over_the_boundary_fails(): void {
		$input         = $this->valid_input();
		$input['name'] = str_repeat( 'a', Validation\NAME_MAX + 1 );

		$result = Validation\validate( $input );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'templ_contact_form_name_too_long', $result->get_error_codes() );
	}

	public function test_message_over_the_boundary_fails(): void {
		$input            = $this->valid_input();
		$input['message'] = str_repeat( 'a', Validation\MESSAGE_MAX + 1 );

		$result = Validation\validate( $input );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'templ_contact_form_message_too_long', $result->get_error_codes() );
	}

	public function test_all_field_errors_are_reported_together(): void {
		$result = Validation\validate( [] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$codes = $result->get_error_codes();
		$this->assertContains( 'templ_contact_form_name_required', $codes );
		$this->assertContains( 'templ_contact_form_email_required', $codes );
		$this->assertContains( 'templ_contact_form_message_required', $codes );
	}
}
