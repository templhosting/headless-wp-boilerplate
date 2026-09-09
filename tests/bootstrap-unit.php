<?php
/**
 * Unit test bootstrap: loads the pure modules with WordPress mocked away.
 *
 * Only side-effect-free modules are required here. Anything that calls
 * add_action() or register_post_type() at include time belongs to the
 * integration suite, because the point of this suite is that it never boots a
 * site: it covers the security-critical arithmetic (key hashing, token shape,
 * validation, rate-limit windows) in milliseconds.
 *
 * @package Templ\Headless\Tests
 */

namespace {
	require_once __DIR__ . '/../vendor/autoload.php';

	define( 'ABSPATH', '/wordpress/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );

	/**
	 * A stand-in for WP_Error good enough for the collect-then-return shape the
	 * validators use. Brain Monkey does not ship one, and the real class pulls
	 * in half of wp-includes.
	 */
	class WP_Error {

		/**
		 * Error codes to messages.
		 *
		 * @var array<string, string[]>
		 */
		private array $errors = [];

		/**
		 * Per-code data payloads.
		 *
		 * @var array<string, mixed>
		 */
		private array $error_data = [];

		/**
		 * @param string $code    Error code.
		 * @param string $message Human message.
		 * @param mixed  $data    Optional payload.
		 */
		public function __construct( string $code = '', string $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->add( $code, $message, $data );
			}
		}

		/**
		 * Records an error.
		 *
		 * @param string $code    Error code.
		 * @param string $message Human message.
		 * @param mixed  $data    Optional payload.
		 * @return void
		 */
		public function add( string $code, string $message = '', $data = '' ): void {
			$this->errors[ $code ][] = $message;

			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		/**
		 * Attaches data to the most recent code.
		 *
		 * @param mixed  $data Payload.
		 * @param string $code Optional code; defaults to the first.
		 * @return void
		 */
		public function add_data( $data, string $code = '' ): void {
			if ( '' === $code ) {
				$code = (string) array_key_first( $this->errors );
			}

			$this->error_data[ $code ] = $data;
		}

		/**
		 * Whether any error has been recorded.
		 *
		 * @return bool
		 */
		public function has_errors(): bool {
			return [] !== $this->errors;
		}

		/**
		 * The first error code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return (string) array_key_first( $this->errors );
		}

		/**
		 * Every recorded code.
		 *
		 * @return string[]
		 */
		public function get_error_codes(): array {
			return array_keys( $this->errors );
		}

		/**
		 * The first message for a code, or the first overall.
		 *
		 * @param string $code Optional code.
		 * @return string
		 */
		public function get_error_message( string $code = '' ): string {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			return $this->errors[ $code ][0] ?? '';
		}

		/**
		 * The data payload for a code.
		 *
		 * @param string $code Optional code.
		 * @return mixed
		 */
		public function get_error_data( string $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			return $this->error_data[ $code ] ?? null;
		}
	}
}

namespace Templ\Headless\Tests {
	// The modules under test call is_wp_error(); it is not one of Brain
	// Monkey's stubbed core functions, so it is defined once here against the
	// stub above rather than in every test.
	require_once __DIR__ . '/../vendor/autoload.php';
}

namespace {
	if ( ! function_exists( 'is_wp_error' ) ) {
		/**
		 * Whether a value is a WP_Error.
		 *
		 * @param mixed $thing Value to test.
		 * @return bool
		 */
		function is_wp_error( $thing ): bool {
			return $thing instanceof WP_Error;
		}
	}

	$templ_headless_root = dirname( __DIR__ ) . '/wp-content';

	require_once $templ_headless_root . '/mu-plugins/templ-headless/src/keys/hash.php';
	require_once $templ_headless_root . '/plugins/templ-contact-form/src/validation.php';
	require_once $templ_headless_root . '/plugins/templ-contact-form/src/rate-limit.php';
	require_once $templ_headless_root . '/plugins/templ-newsletter/src/validation.php';
	require_once $templ_headless_root . '/plugins/templ-newsletter/src/token.php';
}
