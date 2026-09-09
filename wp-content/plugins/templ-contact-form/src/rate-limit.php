<?php
/**
 * A per-sender ceiling on how often the submission endpoint may be hit.
 *
 * Counters live in transients keyed on the hashed sender IP. A transient over a
 * table is the deliberate choice for a boilerplate: on any real host an object
 * cache backs transients, so this is a memory increment rather than a write,
 * and the worst failure mode is a counter dropped when the cache evicts, which
 * only ever lets a sender through, never locks a legitimate one out.
 *
 * @package Templ\Headless\ContactForm
 */

namespace Templ\Headless\ContactForm\RateLimit;

use WP_Error;

defined( 'ABSPATH' ) || exit;

const MAX_PER_WINDOW = 5;
const WINDOW         = HOUR_IN_SECONDS;
const TRANSIENT      = 'templ_cf_rl_';

/**
 * Whether a sender is under the limit, counting this request against it.
 *
 * @param string $ip_hash Hashed sender IP, as PostType stores it.
 * @return true|WP_Error A 429 error when the window is exhausted.
 */
function check( string $ip_hash ) {
	$limits = limits();
	$key    = TRANSIENT . $ip_hash;
	$count  = (int) get_transient( $key );

	if ( $count >= $limits['max'] ) {
		return new WP_Error(
			'templ_contact_form_rate_limited',
			__( 'Too many submissions. Try again later.', 'templ-contact-form' ),
			[ 'status' => 429 ]
		);
	}

	// Re-setting the transient keeps the window fixed to the first request in
	// it rather than sliding forward on every hit, so a burst cannot hold the
	// window open indefinitely. On the first request $count is 0 and the TTL
	// is the full window; later ones only bump the counter.
	set_transient( $key, $count + 1, $limits['window'] );

	return true;
}

/**
 * The active limits, after the filter.
 *
 * @return array{max:int,window:int}
 */
function limits(): array {
	/**
	 * Filters the submission rate limit.
	 *
	 * @param array{max:int,window:int} $limits Requests allowed per window, in seconds.
	 */
	$limits = apply_filters(
		'templ_contact_form_rate_limit',
		[
			'max'    => MAX_PER_WINDOW,
			'window' => WINDOW,
		]
	);

	return [
		'max'    => max( 1, (int) $limits['max'] ),
		'window' => max( 1, (int) $limits['window'] ),
	];
}
