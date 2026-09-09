<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Unit\Newsletter;

use Templ\Headless\Newsletter\Token;
use Templ\Headless\Tests\Unit\TestCase;

/**
 * Covers the unsubscribe token's shape and uniqueness, the properties that let
 * the unsubscribe route stay unauthenticated.
 */
final class TokenTest extends TestCase {

	public function test_a_token_has_the_expected_length(): void {
		$this->assertSame( Token\BYTES * 2, strlen( Token\generate() ) );
	}

	public function test_a_token_is_lowercase_hex(): void {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]+$/', Token\generate() );
	}

	public function test_tokens_are_unique(): void {
		$seen = [];

		for ( $i = 0; $i < 1000; $i++ ) {
			$seen[ Token\generate() ] = true;
		}

		$this->assertCount( 1000, $seen );
	}

	public function test_looks_like_token_accepts_a_generated_token(): void {
		$this->assertTrue( Token\looks_like_token( Token\generate() ) );
	}

	/**
	 * @dataProvider malformed_tokens
	 *
	 * @param string $value Malformed input.
	 */
	public function test_looks_like_token_rejects_malformed_input( string $value ): void {
		$this->assertFalse( Token\looks_like_token( $value ) );
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function malformed_tokens(): array {
		return [
			'empty'     => [ '' ],
			'too short' => [ 'abc' ],
			'non-hex'   => [ str_repeat( 'z', Token\BYTES * 2 ) ],
			'uppercase' => [ str_repeat( 'A', Token\BYTES * 2 ) ],
		];
	}
}
