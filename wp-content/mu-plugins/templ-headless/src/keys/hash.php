<?php
/**
 * Key material: generating it, hashing it, and recognising its shape.
 *
 * Every function here is free of WordPress and free of side effects, which is
 * what lets the unit suite cover the security-critical half of the key store
 * without booting a site.
 *
 * @package Templ\Headless
 */

namespace Templ\Headless\Keys\Hash;

defined( 'ABSPATH' ) || exit;

/**
 * Marks a string as one of ours on sight, in a log or a support ticket.
 */
const PREFIX = 'thl_';

/**
 * Bytes of entropy behind every key. See digest() for why this number is what
 * makes a fast hash the right choice.
 */
const SECRET_BYTES = 32;

/**
 * Characters of a key kept in the clear so a human can tell two keys apart.
 * Short enough to be useless to an attacker, long enough to be unambiguous.
 */
const DISPLAY_LENGTH = 12;

/**
 * Mints a new key.
 *
 * @return string The plaintext key. It is never recoverable after this.
 */
function generate(): string {
	return PREFIX . bin2hex( random_bytes( SECRET_BYTES ) );
}

/**
 * Hashes a key for storage and lookup.
 *
 * A plain SHA-256 rather than a password hash, deliberately. bcrypt and its
 * relatives are slow on purpose, because a human-chosen password has so little
 * entropy that guessing it is feasible. A key from generate() carries 256 bits
 * from random_bytes, so guessing it is not, and the only thing a slow hash
 * would buy is a deliberate cost on every single API request.
 *
 * @param string $plaintext A key as the client sent it.
 * @return string Lowercase hex digest.
 */
function digest( string $plaintext ): string {
	return \hash( 'sha256', $plaintext );
}

/**
 * The identifying fragment of a key, shown in admin lists and CLI output.
 *
 * @param string $plaintext A key as generate() returned it.
 * @return string The first characters of the key.
 */
function display_prefix( string $plaintext ): string {
	return substr( $plaintext, 0, DISPLAY_LENGTH );
}

/**
 * Whether a string could be a key at all.
 *
 * Cheap enough to run before touching the database, which keeps malformed
 * input, and the noise of scanners, off the query log.
 *
 * @param string $value Untrusted input.
 * @return bool
 */
function looks_like_key( string $value ): bool {
	return 1 === preg_match( '/^' . PREFIX . '[0-9a-f]{' . ( SECRET_BYTES * 2 ) . '}$/', $value );
}
