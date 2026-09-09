<?php
/**
 * Turns an untrusted request body into a sanitised submission, or an error.
 *
 * Every function here is free of WordPress state, which is what lets the unit
 * suite cover the whole validation matrix without booting a site. It leans on
 * WordPress' own sanitisers and is_email(), which Brain Monkey stubs, but holds
 * no state of its own.
 *
 * @package Templ\Headless\ContactForm
 */

namespace Templ\Headless\ContactForm\Validation;

use WP_Error;

defined( 'ABSPATH' ) || exit;

const NAME_MAX    = 100;
const SUBJECT_MAX = 200;
const MESSAGE_MAX = 5000;

/**
 * Validates and sanitises a contact submission.
 *
 * Every field error is attached to a single WP_Error rather than returning on
 * the first one, so a client learns everything wrong with its request in one
 * round trip instead of one field at a time.
 *
 * @param array $input Raw request fields.
 * @return array|WP_Error Sanitised fields on success, or the collected errors.
 */
function validate( array $input ) {
	$error = new WP_Error();

	$name = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';
	if ( '' === $name ) {
		$error->add( 'templ_contact_form_name_required', __( 'A name is required.', 'templ-contact-form' ) );
	} elseif ( mb_strlen( $name ) > NAME_MAX ) {
		$error->add( 'templ_contact_form_name_too_long', __( 'The name is too long.', 'templ-contact-form' ) );
	}

	$email = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
	if ( '' === $email ) {
		$error->add( 'templ_contact_form_email_required', __( 'An email address is required.', 'templ-contact-form' ) );
	} elseif ( ! is_email( $email ) ) {
		$error->add( 'templ_contact_form_email_invalid', __( 'That email address is not valid.', 'templ-contact-form' ) );
	}

	$subject = isset( $input['subject'] ) ? sanitize_text_field( (string) $input['subject'] ) : '';
	if ( mb_strlen( $subject ) > SUBJECT_MAX ) {
		$error->add( 'templ_contact_form_subject_too_long', __( 'The subject is too long.', 'templ-contact-form' ) );
	}

	$message = isset( $input['message'] ) ? sanitize_textarea_field( (string) $input['message'] ) : '';
	if ( '' === $message ) {
		$error->add( 'templ_contact_form_message_required', __( 'A message is required.', 'templ-contact-form' ) );
	} elseif ( mb_strlen( $message ) > MESSAGE_MAX ) {
		$error->add( 'templ_contact_form_message_too_long', __( 'The message is too long.', 'templ-contact-form' ) );
	}

	if ( $error->has_errors() ) {
		return $error;
	}

	$clean = [
		'name'    => $name,
		'email'   => $email,
		'subject' => $subject,
		'message' => $message,
	];

	/**
	 * Filters a validated submission before it is stored.
	 *
	 * The hook an agency uses to add a field: validate the extra value against
	 * $input, add it to the returned array, and store it from the
	 * templ_contact_form_submission_created action. Returning a WP_Error here
	 * rejects the submission.
	 *
	 * @param array|WP_Error $clean Sanitised fields.
	 * @param array          $input Raw request fields.
	 */
	return apply_filters( 'templ_contact_form_validate', $clean, $input );
}
