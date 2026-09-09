<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Integration;

use Templ\Headless\Tests\Shared\CleansUp;

/**
 * Exercises the WP-CLI commands for real, from the same container the tests run
 * in, so the command registration and output shape are covered end to end.
 */
final class CliTest extends IntegrationTestCase {

	use CleansUp;

	protected function tearDown(): void {
		$this->purge_posts( $this->subsite_id, [ 'templ_api_key' ] );
		parent::tearDown();
	}

	/**
	 * Runs a wp command against the sample subsite and returns stdout.
	 *
	 * @param string $args Command and flags after `wp`.
	 * @return array{code:int,out:string}
	 */
	private function wp( string $args ): array {
		$command = sprintf(
			'wp %s --url=%s --path=%s 2>/dev/null',
			$args,
			escapeshellarg( $this->subsite_url ),
			escapeshellarg( ABSPATH )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Shelling out to wp is the whole point of this test: it proves the commands work as a client would run them.
		exec( $command, $out, $code );

		return [
			'code' => (int) $code,
			'out'  => implode( "\n", $out ),
		];
	}

	public function test_key_create_prints_a_prefixed_key(): void {
		$result = $this->wp( 'templ-headless key create "cli test" --porcelain' );

		$this->assertSame( 0, $result['code'] );
		$this->assertMatchesRegularExpression( '/^thl_[0-9a-f]{64}$/', trim( $result['out'] ) );
	}

	public function test_key_list_never_prints_the_secret(): void {
		$key = trim( $this->wp( 'templ-headless key create "listed" --porcelain' )['out'] );

		$result = $this->wp( 'templ-headless key list --format=csv' );

		$this->assertSame( 0, $result['code'] );
		$this->assertStringContainsString( 'listed', $result['out'] );
		$this->assertStringNotContainsString( $key, $result['out'] );
	}

	public function test_key_revoke_marks_a_key_revoked(): void {
		$json = $this->wp( 'templ-headless key list --format=json' );
		$this->wp( 'templ-headless key create "to revoke" --porcelain' );

		$rows = json_decode( $this->wp( 'templ-headless key list --format=json' )['out'], true );
		$id   = (int) $rows[0]['id'];

		$result = $this->wp( 'templ-headless key revoke ' . $id );
		$this->assertSame( 0, $result['code'] );

		$after = json_decode( $this->wp( 'templ-headless key list --format=json' )['out'], true );
		$row   = array_values( array_filter( $after, static fn( $r ) => (int) $r['id'] === $id ) )[0];

		$this->assertSame( 'revoked', $row['status'] );
	}
}
