<?php
/**
 * Shared unit test setup.
 *
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Boots Brain Monkey and stubs the handful of WordPress functions the pure
 * modules reach for. The stubs are intentionally faithful: sanitisers return
 * their input, is_email() applies a real check, so a test that passes proves
 * the module's own logic rather than a lenient stub.
 */
abstract class TestCase extends PHPUnitTestCase {

	/**
	 * Sets up Brain Monkey and common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();

		Functions\when( 'sanitize_text_field' )->alias(
			static fn( $value ) => trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $value ) )
		);
		Functions\when( 'sanitize_textarea_field' )->alias(
			static fn( $value ) => trim( (string) $value )
		);
		Functions\when( 'sanitize_email' )->alias(
			static fn( $value ) => trim( (string) $value )
		);
		Functions\when( 'is_email' )->alias(
			static fn( $value ) => false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : false
		);
	}

	/**
	 * Tears Brain Monkey down.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
