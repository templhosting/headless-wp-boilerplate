<?php
/**
 * Markup for the API keys screen.
 *
 * Rendering only. Everything that reads input or writes to the database lives
 * in admin.php, so that the security-relevant code is in one file rather than
 * threaded through the HTML.
 *
 * A plain table rather than WP_List_Table: that class is a semi-private API
 * with a lifecycle of its own, and this screen shows at most a couple of dozen
 * rows with no sorting, no bulk actions and no columns worth registering.
 *
 * @package Templ\Headless
 */

namespace Templ\Headless\Admin\Screen;

use Templ\Headless\Keys\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the whole screen.
 *
 * @param array $context {
 *     Everything the screen needs, gathered by the caller.
 *
 *     @type array       $keys      Key rows from Store\to_array().
 *     @type string|null $plaintext A key just minted, shown once, or null.
 *     @type string      $notice    A message to show, or an empty string.
 * }
 * @return void
 */
function render( array $context ): void {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'API keys', 'templ-headless' ); ?></h1>

		<p class="description">
			<?php esc_html_e( 'Keys authenticate REST requests to this site only. A key minted here does not work on any other site of the network.', 'templ-headless' ); ?>
		</p>

		<?php
		// These render here but do not stay here: core's admin JavaScript
		// moves any .notice that is a direct child of .wrap up to just below
		// the first heading. Worth knowing before trying to reorder them.
		if ( '' !== $context['notice'] ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $context['notice'] )
			);
		}

		if ( null !== $context['plaintext'] ) {
			render_new_key( $context['plaintext'] );
		}

		render_create_form();
		render_table( $context['keys'] );
		?>
	</div>
	<?php
}

/**
 * Shows a freshly minted key, for the only time it will ever be shown.
 *
 * @param string $plaintext The key.
 * @return void
 */
function render_new_key( string $plaintext ): void {
	?>
	<div class="notice notice-warning">
		<p><strong><?php esc_html_e( 'Copy this key now. It is not stored and cannot be shown again.', 'templ-headless' ); ?></strong></p>
		<p>
			<input
				type="text"
				readonly
				class="large-text code"
				onfocus="this.select();"
				value="<?php echo esc_attr( $plaintext ); ?>"
			/>
		</p>
	</div>
	<?php
}

/**
 * The form that mints a key.
 *
 * @return void
 */
function render_create_form(): void {
	?>
	<h2><?php esc_html_e( 'Create a key', 'templ-headless' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="templ_headless_create_key" />
		<?php wp_nonce_field( 'templ_headless_create_key' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="templ-headless-label"><?php esc_html_e( 'Label', 'templ-headless' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="templ-headless-label"
						name="label"
						class="regular-text"
						maxlength="100"
						required
					/>
					<p class="description">
						<?php esc_html_e( 'What this key is for, so the right one can be revoked later. For example: "Marketing site, production".', 'templ-headless' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Create key', 'templ-headless' ) ); ?>
	</form>
	<?php
}

/**
 * The list of existing keys.
 *
 * @param array $keys Key rows from Store\to_array().
 * @return void
 */
function render_table( array $keys ): void {
	?>
	<h2><?php esc_html_e( 'Existing keys', 'templ-headless' ); ?></h2>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Label', 'templ-headless' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Key', 'templ-headless' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'templ-headless' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Created', 'templ-headless' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Last used', 'templ-headless' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'templ-headless' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $keys ) : ?>
				<tr>
					<td colspan="6"><?php esc_html_e( 'No keys yet.', 'templ-headless' ); ?></td>
				</tr>
			<?php endif; ?>

			<?php foreach ( $keys as $key ) : ?>
				<tr>
					<td><?php echo esc_html( $key['label'] ); ?></td>
					<td><code><?php echo esc_html( $key['prefix'] ); ?>&hellip;</code></td>
					<td>
						<?php
						echo esc_html(
							'revoked' === $key['status']
								? __( 'Revoked', 'templ-headless' )
								: __( 'Active', 'templ-headless' )
						);
						?>
					</td>
					<td><?php echo esc_html( format_date( $key['created_at'] ) ); ?></td>
					<td><?php echo esc_html( format_date( $key['last_used_at'] ) ); ?></td>
					<td>
						<?php if ( 'revoked' !== $key['status'] ) : ?>
							<?php
							render_action_button(
								'templ_headless_revoke_key',
								$key['id'],
								__( 'Revoke', 'templ-headless' ),
								''
							);
							?>
						<?php endif; ?>
						<?php
						render_action_button(
							'templ_headless_delete_key',
							$key['id'],
							__( 'Delete', 'templ-headless' ),
							sprintf(
								/* translators: %s: the label of the key being deleted. */
								__( 'Delete the key "%s" and its usage history? Revoking is usually what you want: it stops the key working but keeps the record of it.', 'templ-headless' ),
								$key['label']
							)
						);
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * A one-button form, so that state-changing actions are POSTs.
 *
 * A link would let any page on the internet trigger the action by embedding an
 * image, nonce or no nonce, because browsers follow GETs without asking.
 *
 * @param string $action  admin-post action name.
 * @param int    $key_id  Key post ID.
 * @param string $label   Button text.
 * @param string $confirm Prompt to show before submitting, or '' for none.
 * @return void
 */
function render_action_button( string $action, int $key_id, string $label, string $confirm ): void {
	// Core styles the destructive red only on .button-link.button-link-delete,
	// its link-shaped button. Pairing it with .button instead leaves an
	// ordinary blue button that reads as safe, which is the opposite of what
	// deleting a key is.
	$classes = 'templ_headless_delete_key' === $action
		? 'button-link button-link-delete'
		: 'button button-small';
	?>
	<form
		method="post"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		style="display:inline"
		<?php if ( '' !== $confirm ) : ?>
			<?php
			// Inline rather than an enqueued file: one line of JavaScript does
			// not justify a script, a handle and a dependency on this screen
			// having assets at all. The server does not rely on it -- it is a
			// speed bump in front of an irreversible action, nothing more.
			?>
			onsubmit="return window.confirm(<?php echo esc_attr( wp_json_encode( $confirm ) ); ?>);"
		<?php endif; ?>
	>
		<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
		<input type="hidden" name="key_id" value="<?php echo esc_attr( (string) $key_id ); ?>" />
		<?php wp_nonce_field( $action . '_' . $key_id ); ?>
		<button type="submit" class="<?php echo esc_attr( $classes ); ?>"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
}

/**
 * Formats a GMT timestamp in the site's timezone, or a dash when there is none.
 *
 * @param string $gmt_date A MySQL datetime in GMT, possibly empty.
 * @return string
 */
function format_date( string $gmt_date ): string {
	if ( '' === $gmt_date || '0000-00-00 00:00:00' === $gmt_date ) {
		return '-';
	}

	$timestamp = strtotime( $gmt_date . ' UTC' );

	if ( false === $timestamp ) {
		return '-';
	}

	return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
}
