<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Integration;

use Templ\Headless\Keys;
use Templ\Headless\Keys\Store;

/**
 * The key store against the real database: create, verify, revoke, and the
 * guarantee that the plaintext is never handed back after creation.
 */
final class KeysTest extends IntegrationTestCase {

	/**
	 * @var int[]
	 */
	private array $created = [];

	protected function setUp(): void {
		parent::setUp();
		switch_to_blog( $this->subsite_id );
	}

	protected function tearDown(): void {
		foreach ( $this->created as $id ) {
			Store\delete( $id );
		}
		$this->created = [];
		restore_current_blog();
		parent::tearDown();
	}

	public function test_a_created_key_verifies(): void {
		$key             = Store\create( 'verify me' );
		$this->created[] = $key['id'];

		$post = Keys\find( $key['plaintext'] );

		$this->assertNotNull( $post );
		$this->assertSame( $key['id'], (int) $post->ID );
	}

	public function test_the_stored_row_holds_a_hash_not_the_plaintext(): void {
		$key             = Store\create( 'no plaintext' );
		$this->created[] = $key['id'];

		$stored = get_post_meta( $key['id'], Store\META_HASH, true );

		$this->assertNotSame( $key['plaintext'], $stored );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $stored );
	}

	public function test_a_revoked_key_no_longer_authenticates(): void {
		$key             = Store\create( 'to revoke' );
		$this->created[] = $key['id'];

		Store\revoke( $key['id'] );

		$post = Keys\find( $key['plaintext'] );
		$this->assertNotNull( $post, 'find() still resolves a revoked key so the error code can differ.' );
		$this->assertTrue( Store\is_revoked( $post ) );
	}

	public function test_an_unknown_key_resolves_to_null(): void {
		$this->assertNull( Keys\find( 'thl_' . str_repeat( '0', 64 ) ) );
	}
}
