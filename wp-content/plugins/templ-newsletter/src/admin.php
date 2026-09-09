<?php
/**
 * The subscribers admin screen: menu, list with status tabs, delete, CSV export.
 *
 * Read, delete and export only. A subscriber is something a visitor becomes,
 * not something an editor writes, so there is no create form. Every
 * state-changing action is a POST to admin-post.php.
 *
 * A plain table rather than WP_List_Table, matching every other screen in this
 * repo.
 *
 * @package Templ\Headless\Newsletter
 */

namespace Templ\Headless\Newsletter\Admin;

use Templ\Headless\Newsletter\PostType;

defined( 'ABSPATH' ) || exit;

const PAGE_SLUG = 'templ-newsletter';

/**
 * Who may read the list. Editors, so a client's staff can work the list
 * without holding the keys to the site.
 */
const CAPABILITY = 'edit_posts';

const PER_PAGE = 20;

/**
 * Registers the hooks this module owns.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'admin_menu', __NAMESPACE__ . '\\register_page' );
	add_action( 'admin_post_templ_newsletter_delete', __NAMESPACE__ . '\\handle_delete' );
	add_action( 'admin_post_templ_newsletter_export', __NAMESPACE__ . '\\handle_export' );
}

/**
 * Adds the top-level menu, on every site of the network.
 *
 * @return void
 */
function register_page(): void {
	add_menu_page(
		__( 'Newsletter', 'templ-newsletter' ),
		__( 'Newsletter', 'templ-newsletter' ),
		CAPABILITY,
		PAGE_SLUG,
		__NAMESPACE__ . '\\render_page',
		'dashicons-email-alt',
		27
	);
}

/**
 * The subscriber list.
 *
 * @return void
 */
function render_page(): void {
	if ( ! current_user_can( CAPABILITY ) ) {
		wp_die( esc_html__( 'You do not have permission to read subscribers on this site.', 'templ-newsletter' ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation state that only shapes the query.
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
	$page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;

	$counts = PostType\counts();
	$result = PostType\all(
		[
			'page'     => $page,
			'per_page' => PER_PAGE,
			'status'   => $status,
		]
	);

	$notice = current_notice();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Subscribers', 'templ-newsletter' ); ?></h1>

		<?php if ( '' !== $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<?php render_status_tabs( $status, $counts ); ?>

		<p>
			<?php render_export_button( $status ); ?>
		</p>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Email', 'templ-newsletter' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Name', 'templ-newsletter' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'templ-newsletter' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Subscribed', 'templ-newsletter' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'templ-newsletter' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $result['items'] ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No subscribers yet.', 'templ-newsletter' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $result['items'] as $post ) : ?>
					<?php $row = PostType\to_array( $post ); ?>
					<tr>
						<td><?php echo esc_html( $row['email'] ); ?></td>
						<td><?php echo esc_html( $row['name'] ); ?></td>
						<td><?php echo esc_html( status_label( $row['status'] ) ); ?></td>
						<td><?php echo esc_html( format_date( $row['subscribed_at'] ) ); ?></td>
						<td><?php render_delete_button( $row['id'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php render_pagination( $page, $result['pages'], $status ); ?>
	</div>
	<?php
}

/**
 * The all / subscribed / unsubscribed filter tabs, with live counts.
 *
 * @param string $current The active status filter, or '' for all.
 * @param array  $counts  Per-status counts from PostType\counts().
 * @return void
 */
function render_status_tabs( string $current, array $counts ): void {
	$total = $counts[ PostType\STATUS_SUBSCRIBED ] + $counts[ PostType\STATUS_UNSUBSCRIBED ];

	$tabs = [
		''                           => [ __( 'All', 'templ-newsletter' ), $total ],
		PostType\STATUS_SUBSCRIBED   => [ __( 'Subscribed', 'templ-newsletter' ), $counts[ PostType\STATUS_SUBSCRIBED ] ],
		PostType\STATUS_UNSUBSCRIBED => [ __( 'Unsubscribed', 'templ-newsletter' ), $counts[ PostType\STATUS_UNSUBSCRIBED ] ],
	];

	echo '<ul class="subsubsub">';
	$i    = 0;
	$last = count( $tabs ) - 1;
	foreach ( $tabs as $status => $tab ) {
		$args = [ 'page' => PAGE_SLUG ];
		if ( '' !== $status ) {
			$args['status'] = $status;
		}
		printf(
			'<li><a href="%s"%s>%s <span class="count">(%d)</span></a>%s</li>',
			esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ),
			$status === $current ? ' class="current"' : '',
			esc_html( $tab[0] ),
			(int) $tab[1],
			$i < $last ? ' | ' : ''
		);
		++$i;
	}
	echo '</ul>';
}

/**
 * Deletes a subscriber and returns to the list.
 *
 * @return void
 */
function handle_delete(): void {
	if ( ! current_user_can( CAPABILITY ) ) {
		wp_die( esc_html__( 'You do not have permission to delete subscribers on this site.', 'templ-newsletter' ), '', [ 'response' => 403 ] );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce action contains this ID, so it is read to know which nonce to check, then verified on the next line.
	$subscriber_id = isset( $_POST['subscriber_id'] ) ? absint( wp_unslash( $_POST['subscriber_id'] ) ) : 0;
	check_admin_referer( 'templ_newsletter_delete_' . $subscriber_id );

	PostType\delete( $subscriber_id );

	wp_safe_redirect(
		add_query_arg(
			[
				'page'   => PAGE_SLUG,
				'result' => 'deleted',
			],
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Streams the subscriber list as CSV.
 *
 * The one export every agency asks for on day one. It streams rather than
 * building a string in memory, so a long list does not exhaust the request's
 * memory limit.
 *
 * @return void
 */
function handle_export(): void {
	if ( ! current_user_can( CAPABILITY ) ) {
		wp_die( esc_html__( 'You do not have permission to export subscribers on this site.', 'templ-newsletter' ), '', [ 'response' => 403 ] );
	}

	check_admin_referer( 'templ_newsletter_export' );

	$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

	$filename = sprintf( 'subscribers-%s.csv', gmdate( 'Y-m-d' ) );

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing CSV straight to the response body is the point of a streamed export; the VFS wrappers do not apply to php://output.
	$out = fopen( 'php://output', 'w' );

	// The empty escape string is PHP 8.4's coming default, passed explicitly
	// so the export is byte-stable across the version bump rather than
	// switching escaping behaviour under us when the floor moves.
	write_csv_row( $out, [ 'email', 'name', 'status', 'subscribed_at', 'unsubscribed_at' ] );

	$page = 1;
	do {
		$result = PostType\all(
			[
				'page'     => $page,
				'per_page' => PostType\MAX_PER_PAGE,
				'status'   => $status,
			]
		);

		foreach ( $result['items'] as $post ) {
			$row = PostType\to_array( $post );
			write_csv_row( $out, [ $row['email'], $row['name'], $row['status'], $row['subscribed_at'], $row['unsubscribed_at'] ] );
		}

		++$page;
	} while ( $page <= $result['pages'] );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See the fopen note above.
	fclose( $out );
	exit;
}

/**
 * Writes one CSV row with the escaping made explicit.
 *
 * @param resource $handle Open output stream.
 * @param array    $fields Row values.
 * @return void
 */
function write_csv_row( $handle, array $fields ): void {
	fputcsv( $handle, $fields, ',', '"', '' );
}

/**
 * The CSV export button, carrying the active status filter.
 *
 * @param string $status The active status filter, or ''.
 * @return void
 */
function render_export_button( string $status ): void {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
		<input type="hidden" name="action" value="templ_newsletter_export" />
		<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
		<?php wp_nonce_field( 'templ_newsletter_export' ); ?>
		<button type="submit" class="button"><?php esc_html_e( 'Export CSV', 'templ-newsletter' ); ?></button>
	</form>
	<?php
}

/**
 * The delete button, a one-button POST form.
 *
 * @param int $subscriber_id Subscriber post ID.
 * @return void
 */
function render_delete_button( int $subscriber_id ): void {
	?>
	<form
		method="post"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		style="display:inline"
		onsubmit="return window.confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete this subscriber? This erases the record and cannot be undone.', 'templ-newsletter' ) ) ); ?>);"
	>
		<input type="hidden" name="action" value="templ_newsletter_delete" />
		<input type="hidden" name="subscriber_id" value="<?php echo esc_attr( (string) $subscriber_id ); ?>" />
		<?php wp_nonce_field( 'templ_newsletter_delete_' . $subscriber_id ); ?>
		<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'templ-newsletter' ); ?></button>
	</form>
	<?php
}

/**
 * Prev/next pagination, shown only when there is more than one page.
 *
 * @param int    $page   Current page.
 * @param int    $pages  Total pages.
 * @param string $status Active status filter, to carry across pages.
 * @return void
 */
function render_pagination( int $page, int $pages, string $status ): void {
	if ( $pages < 2 ) {
		return;
	}

	$base = [ 'page' => PAGE_SLUG ];
	if ( '' !== $status ) {
		$base['status'] = $status;
	}
	?>
	<div class="tablenav bottom">
		<div class="tablenav-pages">
			<span class="displaying-num">
				<?php
				printf(
					/* translators: 1: current page, 2: total pages. */
					esc_html__( 'Page %1$d of %2$d', 'templ-newsletter' ),
					(int) $page,
					(int) $pages
				);
				?>
			</span>
			<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( $base + [ 'paged' => $page - 1 ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Previous', 'templ-newsletter' ); ?></a>
			<?php endif; ?>
			<?php if ( $page < $pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( $base + [ 'paged' => $page + 1 ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Next', 'templ-newsletter' ); ?></a>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * The human label for a subscriber status.
 *
 * @param string $status One of the STATUS_* constants.
 * @return string
 */
function status_label( string $status ): string {
	return PostType\STATUS_UNSUBSCRIBED === $status
		? __( 'Unsubscribed', 'templ-newsletter' )
		: __( 'Subscribed', 'templ-newsletter' );
}

/**
 * The message for whatever just happened, if anything did.
 *
 * @return string
 */
function current_notice(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a status word this file put in its own redirect URL, to pick a message.
	$result = isset( $_GET['result'] ) ? sanitize_key( wp_unslash( $_GET['result'] ) ) : '';

	return 'deleted' === $result ? __( 'Subscriber deleted.', 'templ-newsletter' ) : '';
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
