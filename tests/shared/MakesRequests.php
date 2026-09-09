<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Shared;

/**
 * Requests the REST API of the site under test over real HTTP.
 *
 * Real HTTP rather than rest_do_request(), on purpose: the auth layer reads the
 * Authorization header, and the .htaccess rewrite that hands that header to PHP
 * is part of what is under test. An in-process dispatch would pass while a real
 * client's header was being dropped at the web server.
 */
trait MakesRequests {

	/**
	 * The base REST URL of a site, reached over the compose network.
	 *
	 * The request goes to the internal address so it never leaves the network,
	 * but the Host header is forced to whatever the site thinks its address is,
	 * so WordPress routes to the right subsite instead of issuing a canonical
	 * redirect.
	 *
	 * @param string $site_url The public home URL of the site.
	 * @param string $path     REST path, e.g. /templ-headless/v1/ping.
	 * @param array  $args     wp_remote_request() arguments.
	 * @return array{status:int,body:mixed,headers:array<string,string>}
	 */
	protected function request( string $site_url, string $path, array $args = [] ): array {
		$internal = getenv( 'TEMPL_SITE_INTERNAL_URL' );
		$home     = (array) wp_parse_url( $site_url );
		$host     = $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );

		$url = $internal
			? untrailingslashit( $internal ) . $home['path'] . 'wp-json' . $path
			: untrailingslashit( $site_url ) . '/wp-json' . $path;

		$defaults = [
			'method'      => 'GET',
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => [],
		];

		$args                      = array_merge( $defaults, $args );
		$args['headers']['Host']   = $host;
		$args['headers']['Accept'] = 'application/json';

		$response = wp_remote_request( $url, $args );

		$this->assertFalse( is_wp_error( $response ), 'Could not reach ' . $url . ': ' . ( is_wp_error( $response ) ? $response->get_error_message() : '' ) );

		$body = wp_remote_retrieve_body( $response );

		return [
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => json_decode( $body, true ),
			'headers' => wp_remote_retrieve_headers( $response )->getAll(),
		];
	}

	/**
	 * A JSON POST helper.
	 *
	 * @param string      $site_url Site home URL.
	 * @param string      $path     REST path.
	 * @param array       $body     Request body.
	 * @param string|null $key      Optional API key.
	 * @return array{status:int,body:mixed,headers:array<string,string>}
	 */
	protected function post_json( string $site_url, string $path, array $body, ?string $key = null ): array {
		$headers = [ 'Content-Type' => 'application/json' ];

		if ( null !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		return $this->request(
			$site_url,
			$path,
			[
				'method'  => 'POST',
				'headers' => $headers,
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Building a JSON request body; wp_json_encode adds no value over the request being sent to a real endpoint.
				'body'    => json_encode( $body ),
			]
		);
	}

	/**
	 * A GET helper, optionally authenticated.
	 *
	 * @param string      $site_url Site home URL.
	 * @param string      $path     REST path.
	 * @param string|null $key      Optional API key.
	 * @return array{status:int,body:mixed,headers:array<string,string>}
	 */
	protected function get( string $site_url, string $path, ?string $key = null ): array {
		$headers = [];

		if ( null !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		return $this->request( $site_url, $path, [ 'headers' => $headers ] );
	}

	/**
	 * A DELETE helper.
	 *
	 * @param string      $site_url Site home URL.
	 * @param string      $path     REST path.
	 * @param string|null $key      Optional API key.
	 * @return array{status:int,body:mixed,headers:array<string,string>}
	 */
	protected function delete( string $site_url, string $path, ?string $key = null ): array {
		$headers = [];

		if ( null !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		return $this->request(
			$site_url,
			$path,
			[
				'method'  => 'DELETE',
				'headers' => $headers,
			]
		);
	}
}
