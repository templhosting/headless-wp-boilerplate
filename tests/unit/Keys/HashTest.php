<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Unit\Keys;

use Templ\Headless\Keys\Hash;
use Templ\Headless\Tests\Unit\TestCase;

/**
 * Covers key material: shape, hashing, and the cheap pre-flight check.
 */
final class HashTest extends TestCase {

	public function test_generate_carries_the_prefix(): void {
		$this->assertStringStartsWith( Hash\PREFIX, Hash\generate() );
	}

	public function test_generate_has_the_expected_length(): void {
		$key = Hash\generate();

		$this->assertSame( strlen( Hash\PREFIX ) + Hash\SECRET_BYTES * 2, strlen( $key ) );
	}

	public function test_generated_keys_are_unique(): void {
		$seen = [];

		for ( $i = 0; $i < 1000; $i++ ) {
			$seen[ Hash\generate() ] = true;
		}

		$this->assertCount( 1000, $seen );
	}

	public function test_digest_is_stable(): void {
		$this->assertSame( Hash\digest( 'thl_abc' ), Hash\digest( 'thl_abc' ) );
	}

	public function test_digest_differs_by_input(): void {
		$this->assertNotSame( Hash\digest( 'thl_a' ), Hash\digest( 'thl_b' ) );
	}

	public function test_digest_is_a_sha256_hex(): void {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', Hash\digest( 'anything' ) );
	}

	public function test_display_prefix_length(): void {
		$this->assertSame( Hash\DISPLAY_LENGTH, strlen( Hash\display_prefix( Hash\generate() ) ) );
	}

	public function test_looks_like_key_accepts_a_generated_key(): void {
		$this->assertTrue( Hash\looks_like_key( Hash\generate() ) );
	}

	/**
	 * @dataProvider malformed_keys
	 *
	 * @param string $value Malformed input.
	 */
	public function test_looks_like_key_rejects_malformed_input( string $value ): void {
		$this->assertFalse( Hash\looks_like_key( $value ) );
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function malformed_keys(): array {
		return [
			'empty'          => [ '' ],
			'no prefix'      => [ str_repeat( 'a', 64 ) ],
			'wrong prefix'   => [ 'sk_' . str_repeat( 'a', 64 ) ],
			'too short'      => [ Hash\PREFIX . 'abc' ],
			'too long'       => [ Hash\PREFIX . str_repeat( 'a', 65 ) ],
			'non-hex'        => [ Hash\PREFIX . str_repeat( 'z', 64 ) ],
			'uppercase hex'  => [ Hash\PREFIX . str_repeat( 'A', 64 ) ],
			'trailing space' => [ Hash\generate() . ' ' ],
		];
	}
}
