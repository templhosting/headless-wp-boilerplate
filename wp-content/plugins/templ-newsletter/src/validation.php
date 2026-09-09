<?php
/**
 * Turns an untrusted subscribe request into a normalised record, or an error.
 *
 * Free of WordPress state, so the unit suite covers the whole matrix. It leans
 * on is_email() and the sanitisers, which Brain Monkey stubs.
 *
 * @package Templ\Headless\Newsletter
 */

namespace Templ\Headless\Newsletter\Validation;

use WP_Error;

defined( 'ABSPATH' ) || exit;

const NAME_MAX = 100;

/**
 * The honeypot field. A real client leaves it empty; a bot filling every field
 * trips it.
 */
const HONEYPOT_FIELD = 'website';

/**
 * Validates and normalises a subscribe request.
 *
 * The email is lowercased and trimmed so that the same address in different
 * casing is one subscriber, not several. That normalised form is what
 * PostType uses as the post title, which is what makes find_by_email() a title
 * lookup and subscribe() idempotent.
 *
 * @param array $input Raw request fields.
 * @return array|WP_Error {
 *     Normalised fields on success.
 *
 *     @type string $email Normalised email.
 *     @type string $name  Sanitised name, or ''.
 * }
 */
function validate( array $input ) {
	$error = new WP_Error();

	if ( '' !== trim( (string) ( $input[ HONEYPOT_FIELD ] ?? '' ) ) ) {
		// A tripped honeypot is a validation failure to the internals; the REST
		// layer decides how to answer it, so a bot is not told what happened.
		$error->add( 'templ_newsletter_honeypot', __( 'Rejected.', 'templ-newsletter' ) );
		return $error;
	}

	$email = isset( $input['email'] )
		? strtolower( trim( sanitize_email( (string) $input['email'] ) ) )
		: '';

	if ( '' === $email ) {
		$error->add( 'templ_newsletter_email_required', __( 'An email address is required.', 'templ-newsletter' ) );
	} elseif ( ! is_email( $email ) ) {
		$error->add( 'templ_newsletter_email_invalid', __( 'That email address is not valid.', 'templ-newsletter' ) );
	}

	$name = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';
	if ( mb_strlen( $name ) > NAME_MAX ) {
		$error->add( 'templ_newsletter_name_too_long', __( 'The name is too long.', 'templ-newsletter' ) );
	}

	if ( $error->has_errors() ) {
		return $error;
	}

	$clean = [
		'email' => $email,
		'name'  => $name,
	];

	/**
	 * Filters a validated subscribe request before it is stored.
	 *
	 * The hook for adding a field. Returning a WP_Error rejects the request.
	 *
	 * @param array|WP_Error $clean Normalised fields.
	 * @param array          $input Raw request fields.
	 */
	return apply_filters( 'templ_newsletter_validate', $clean, $input );
}
