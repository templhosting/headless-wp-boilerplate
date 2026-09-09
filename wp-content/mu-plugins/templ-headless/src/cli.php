<?php
/**
 * WP-CLI commands for the key store.
 *
 * Keys are per site, and WP-CLI defaults to the main site of the network, so
 * every command here needs --url to reach a subsite:
 *
 *     wp templ-headless key create "Marketing site" --url=https://example.com/customer-one/
 *
 * Without it the key is minted on the main site and will 401 everywhere else,
 * which looks exactly like a broken key.
 *
 * @package Templ\Headless
 */

namespace Templ\Headless\Cli;

use Templ\Headless\Keys\Store;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the commands, if there is a WP-CLI to register them with.
 *
 * @return void
 */
function bootstrap(): void {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		return;
	}

	WP_CLI::add_command( 'templ-headless key create', __NAMESPACE__ . '\\create' );
	WP_CLI::add_command( 'templ-headless key list', __NAMESPACE__ . '\\list_keys' );
	WP_CLI::add_command( 'templ-headless key revoke', __NAMESPACE__ . '\\revoke' );
	WP_CLI::add_command( 'templ-headless key delete', __NAMESPACE__ . '\\delete' );
}

/**
 * Mints an API key for a site.
 *
 * ## OPTIONS
 *
 * <label>
 * : What the key is for, so the right one can be revoked later.
 *
 * [--porcelain]
 * : Print only the key, for piping into something else.
 *
 * ## EXAMPLES
 *
 *     wp templ-headless key create "Marketing site" --url=https://example.com/customer-one/
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Associative arguments.
 * @return void
 */
function create( array $args, array $assoc_args ): void {
	$key = Store\create( (string) $args[0] );

	if ( is_wp_error( $key ) ) {
		WP_CLI::error( $key->get_error_message() );
	}

	if ( ! empty( $assoc_args['porcelain'] ) ) {
		WP_CLI::line( $key['plaintext'] );
		return;
	}

	WP_CLI::success( sprintf( 'Key %d created for %s.', $key['id'], home_url( '/' ) ) );
	WP_CLI::line( $key['plaintext'] );
	WP_CLI::line( '' );
	WP_CLI::warning( 'Only the hash is stored. This is the one and only time the key is shown.' );
}

/**
 * Lists the API keys of a site.
 *
 * Never prints the keys themselves, because the site does not have them.
 *
 * ## OPTIONS
 *
 * [--format=<format>]
 * : Output format.
 * ---
 * default: table
 * options:
 *   - table
 *   - json
 *   - csv
 *   - yaml
 * ---
 *
 * ## EXAMPLES
 *
 *     wp templ-headless key list --url=https://example.com/customer-one/
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Associative arguments.
 * @return void
 */
function list_keys( array $args, array $assoc_args ): void {
	unset( $args );

	$rows = array_map( 'Templ\\Headless\\Keys\\Store\\to_array', Store\all() );

	WP_CLI\Utils\format_items(
		$assoc_args['format'] ?? 'table',
		$rows,
		[ 'id', 'label', 'prefix', 'status', 'created_at', 'last_used_at' ]
	);
}

/**
 * Revokes an API key, keeping the record of it.
 *
 * ## OPTIONS
 *
 * <id>
 * : The key ID, as shown by `wp templ-headless key list`.
 *
 * ## EXAMPLES
 *
 *     wp templ-headless key revoke 12 --url=https://example.com/customer-one/
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Associative arguments.
 * @return void
 */
function revoke( array $args, array $assoc_args ): void {
	unset( $assoc_args );

	$key_id = (int) $args[0];

	if ( ! Store\revoke( $key_id ) ) {
		WP_CLI::error( sprintf( 'No active key %d on %s.', $key_id, home_url( '/' ) ) );
	}

	WP_CLI::success( sprintf( 'Key %d revoked.', $key_id ) );
}

/**
 * Deletes an API key and its audit trail.
 *
 * ## OPTIONS
 *
 * <id>
 * : The key ID, as shown by `wp templ-headless key list`.
 *
 * [--yes]
 * : Skip the confirmation prompt.
 *
 * ## EXAMPLES
 *
 *     wp templ-headless key delete 12 --yes --url=https://example.com/customer-one/
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Associative arguments.
 * @return void
 */
function delete( array $args, array $assoc_args ): void {
	$key_id = (int) $args[0];

	WP_CLI::confirm( sprintf( 'Delete key %d and its usage history?', $key_id ), $assoc_args );

	if ( ! Store\delete( $key_id ) ) {
		WP_CLI::error( sprintf( 'No key %d on %s.', $key_id, home_url( '/' ) ) );
	}

	WP_CLI::success( sprintf( 'Key %d deleted.', $key_id ) );
}
