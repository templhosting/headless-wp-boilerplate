<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Integration;

use Templ\Headless\Tests\Shared\MakesRequests;

/**
 * The headless theme's job: no public frontend, an intact REST API, and no
 * author-archive leak.
 */
final class ThemeTest extends IntegrationTestCase {

	use MakesRequests;

	/**
	 * Requests a raw path (not under /wp-json) and returns status and headers.
	 *
	 * @param string $site_url Site home URL.
	 * @param string $path     Path from the site root, e.g. /?author=1.
	 * @return array{status:int,location:string}
	 */
	private function raw( string $site_url, string $path ): array {
		$internal = getenv( 'TEMPL_SITE_INTERNAL_URL' );
		$home     = (array) wp_parse_url( $site_url );
		$host     = $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );

		$url = $internal
			? untrailingslashit( $internal ) . rtrim( $home['path'], '/' ) . $path
			: untrailingslashit( $site_url ) . $path;

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => [ 'Host' => $host ],
			]
		);

		$this->assertFalse( is_wp_error( $response ), 'Could not reach ' . $url );

		return [
			'status'   => (int) wp_remote_retrieve_response_code( $response ),
			'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		];
	}

	public function test_the_frontend_redirects_to_login(): void {
		$response = $this->raw( $this->subsite_url, '/' );

		$this->assertSame( 302, $response['status'] );
		$this->assertStringContainsString( 'wp-login.php', $response['location'] );
	}

	public function test_author_enumeration_does_not_leak_an_author_archive(): void {
		$response = $this->raw( $this->subsite_url, '/?author=1' );

		$this->assertSame( 302, $response['status'] );
		$this->assertStringContainsString( 'wp-login.php', $response['location'] );
		$this->assertStringNotContainsString( '/author/', $response['location'] );
	}

	public function test_the_rest_api_is_unaffected(): void {
		$response = $this->get( $this->subsite_url, '/' );

		$this->assertSame( 200, $response['status'] );
		$this->assertArrayHasKey( 'namespaces', $response['body'] );
	}
}
