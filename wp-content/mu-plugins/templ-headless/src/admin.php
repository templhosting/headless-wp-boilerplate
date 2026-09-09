<?php
/**
 * The API keys admin screen: menu, request handling, redirects.
 *
 * Every handler here is a POST to admin-post.php that redirects back to the
 * screen. Post/redirect/get, so that a browser refresh after minting a key
 * does not mint a second one.
 *
 * @package Templ\Headless
 */

namespace Templ\Headless\Admin;

use Templ\Headless\Admin\Screen;
use Templ\Headless\Keys\Store;

defined( 'ABSPATH' ) || exit;

const PAGE_SLUG = 'templ-headless-keys';

/**
 * Who may see and manage the keys of a site. Site administrators, by design:
 * the network exists so that an agency can hand a subsite to a customer, and
 * the customer's own integrations should not need the network owner.
 */
const CAPABILITY = 'manage_options';

/**
 * Prefix of the transient that carries a new key from the handler to the
 * screen. Per user, and short lived.
 */
const NEW_KEY_TRANSIENT = 'templ_headless_new_key_';

/**
 * How long a newly minted key stays retrievable for display.
 */
const NEW_KEY_TTL = 60;

/**
 * Registers the hooks this module owns.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'admin_menu', __NAMESPACE__ . '\\register_page' );
	add_action( 'admin_post_templ_headless_create_key', __NAMESPACE__ . '\\handle_create' );
	add_action( 'admin_post_templ_headless_revoke_key', __NAMESPACE__ . '\\handle_revoke' );
	add_action( 'admin_post_templ_headless_delete_key', __NAMESPACE__ . '\\handle_delete' );
}

/**
 * Adds the screen under Settings, on every site of the network.
 *
 * @return void
 */
function register_page(): void {
	add_options_page(
		__( 'API keys', 'templ-headless' ),
		__( 'API keys', 'templ-headless' ),
		CAPABILITY,
		PAGE_SLUG,
		__NAMESPACE__ . '\\render_page'
	);
}

/**
 * Gathers what the screen needs and hands off to the renderer.
 *
 * @return void
 */
function render_page(): void {
	if ( ! current_user_can( CAPABILITY ) ) {
		wp_die( esc_html__( 'You do not have permission to manage API keys on this site.', 'templ-headless' ) );
	}

	$keys = array_map( __NAMESPACE__ . '\\to_row', Store\all() );

	Screen\render(
		[
			'keys'      => $keys,
			'plaintext' => take_new_key(),
			'notice'    => current_notice(),
		]
	);
}

/**
 * Adapter so the renderer never touches the store directly.
 *
 * @param \WP_Post $post Key post.
 * @return array
 */
function to_row( \WP_Post $post ): array {
	return Store\to_array( $post );
}

/**
 * Mints a key.
 *
 * @return void
 */
function handle_create(): void {
	require_capability();
	check_admin_referer( 'templ_headless_create_key' );

	$label = isset( $_POST['label'] )
		? sanitize_text_field( wp_unslash( $_POST['label'] ) )
		: '';

	$key = Store\create( $label );

	if ( is_wp_error( $key ) ) {
		redirect_back( 'error' );
	}

	// The plaintext travels in a transient rather than a query argument: a URL
	// ends up in browser history, in the Referer of the next request, and in
	// the access log of anything in front of the site. A transient is read
	// once, by this user, and then deleted.
	set_transient( NEW_KEY_TRANSIENT . get_current_user_id(), $key['plaintext'], NEW_KEY_TTL );

	redirect_back( 'created' );
}

/**
 * Revokes a key.
 *
 * @return void
 */
function handle_revoke(): void {
	require_capability();
	$key_id = requested_key_id();
	check_admin_referer( 'templ_headless_revoke_key_' . $key_id );

	Store\revoke( $key_id );

	redirect_back( 'revoked' );
}

/**
 * Deletes a key outright.
 *
 * @return void
 */
function handle_delete(): void {
	require_capability();
	$key_id = requested_key_id();
	check_admin_referer( 'templ_headless_delete_key_' . $key_id );

	Store\delete( $key_id );

	redirect_back( 'deleted' );
}

/**
 * Refuses anybody without the capability.
 *
 * The nonce check stays at the call sites rather than moving in here with it.
 * Hiding check_admin_referer() behind a helper is how a handler ends up
 * unprotected without anybody noticing, and it is the one thing the WPCS nonce
 * sniff is looking for.
 *
 * @return void
 */
function require_capability(): void {
	if ( ! current_user_can( CAPABILITY ) ) {
		wp_die( esc_html__( 'You do not have permission to manage API keys on this site.', 'templ-headless' ), '', [ 'response' => 403 ] );
	}
}

/**
 * The key ID a request is asking about.
 *
 * Read before the nonce is checked because the nonce action contains the ID.
 * It is cast to an integer and used for nothing until the nonce has passed.
 *
 * @return int
 */
function requested_key_id(): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verifies the nonce immediately after this, and it needs this value to know which nonce to verify.
	return isset( $_POST['key_id'] ) ? absint( wp_unslash( $_POST['key_id'] ) ) : 0;
}

/**
 * Reads and consumes the plaintext of a key just minted.
 *
 * @return string|null
 */
function take_new_key(): ?string {
	$transient = NEW_KEY_TRANSIENT . get_current_user_id();
	$plaintext = get_transient( $transient );

	if ( ! is_string( $plaintext ) || '' === $plaintext ) {
		return null;
	}

	delete_transient( $transient );

	return $plaintext;
}

/**
 * The message for whatever just happened, if anything did.
 *
 * @return string
 */
function current_notice(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a status word this file put in its own redirect URL. It selects a message and nothing else.
	$result = isset( $_GET['result'] ) ? sanitize_key( wp_unslash( $_GET['result'] ) ) : '';

	$messages = [
		'created' => __( 'Key created.', 'templ-headless' ),
		'revoked' => __( 'Key revoked. It no longer authenticates requests.', 'templ-headless' ),
		'deleted' => __( 'Key deleted.', 'templ-headless' ),
		'error'   => __( 'That key could not be created. A label is required.', 'templ-headless' ),
	];

	return $messages[ $result ] ?? '';
}

/**
 * Sends the browser back to the screen with a result to report.
 *
 * @param string $result One of the keys of the message list in current_notice().
 * @return void
 */
function redirect_back( string $result ): void {
	wp_safe_redirect(
		add_query_arg(
			[
				'page'   => PAGE_SLUG,
				'result' => $result,
			],
			admin_url( 'options-general.php' )
		)
	);
	exit;
}
