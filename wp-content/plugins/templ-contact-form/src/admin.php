<?php
/**
 * The submissions admin screen: menu, list, single view, delete.
 *
 * Read and delete only. Nothing here creates a submission, because a submission
 * is a thing a visitor sends, not a thing an editor writes. Everything that
 * writes to the database is a POST to admin-post.php that redirects back, so a
 * refresh never repeats a delete.
 *
 * A plain table rather than WP_List_Table, matching the keys screen: that class
 * is a semi-private API with a lifecycle of its own, and this list has one
 * pagination control and one action.
 *
 * @package Templ\Headless\ContactForm
 */

namespace Templ\Headless\ContactForm\Admin;

use Templ\Headless\ContactForm\PostType;

defined( 'ABSPATH' ) || exit;

const PAGE_SLUG = 'templ-contact-form';

/**
 * Who may read submissions. Editors, not just administrators: an agency hands a
 * subsite to a client whose staff need to read the inbox without holding the
 * keys to the site.
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
	add_action( 'admin_post_templ_contact_form_delete', __NAMESPACE__ . '\\handle_delete' );
}

/**
 * Adds the top-level menu, on every site of the network.
 *
 * @return void
 */
function register_page(): void {
	add_menu_page(
		__( 'Contact Form', 'templ-contact-form' ),
		__( 'Contact Form', 'templ-contact-form' ),
		CAPABILITY,
		PAGE_SLUG,
		__NAMESPACE__ . '\\render_page',
		'dashicons-email',
		26
	);
}

/**
 * Routes to the single view or the list, whichever the request asks for.
 *
 * @return void
 */
function render_page(): void {
	if ( ! current_user_can( CAPABILITY ) ) {
		wp_die( esc_html__( 'You do not have permission to read submissions on this site.', 'templ-contact-form' ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading an ID to decide which read-only view to render. The delete action it might lead to carries its own nonce.
	$view_id = isset( $_GET['submission'] ) ? absint( wp_unslash( $_GET['submission'] ) ) : 0;

	if ( $view_id > 0 ) {
		render_single( $view_id );
		return;
	}

	render_list();
}

/**
 * The paginated list.
 *
 * @return void
 */
function render_list(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation state (which page, what search) that only shapes a query.
	$page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

	$result = PostType\all(
		[
			'page'     => $page,
			'per_page' => PER_PAGE,
			'search'   => $search,
		]
	);

	$notice = current_notice();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Submissions', 'templ-contact-form' ); ?></h1>

		<?php if ( '' !== $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( PAGE_SLUG ); ?>" />
			<p class="search-box">
				<label class="screen-reader-text" for="templ-cf-search"><?php esc_html_e( 'Search submissions', 'templ-contact-form' ); ?></label>
				<input type="search" id="templ-cf-search" name="s" value="<?php echo esc_attr( $search ); ?>" />
				<?php submit_button( __( 'Search', 'templ-contact-form' ), '', '', false ); ?>
			</p>
		</form>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'templ-contact-form' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Name', 'templ-contact-form' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Email', 'templ-contact-form' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Subject', 'templ-contact-form' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'templ-contact-form' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'templ-contact-form' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $result['items'] ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No submissions yet.', 'templ-contact-form' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $result['items'] as $post ) : ?>
					<?php $row = PostType\to_array( $post ); ?>
					<tr>
						<td><?php echo esc_html( format_date( $row['created_at'] ) ); ?></td>
						<td><?php echo esc_html( $row['name'] ); ?></td>
						<td><?php echo esc_html( $row['email'] ); ?></td>
						<td><?php echo esc_html( $row['subject'] ); ?></td>
						<td><?php echo esc_html( truncate( $row['message'] ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( view_url( $row['id'] ) ); ?>"><?php esc_html_e( 'View', 'templ-contact-form' ); ?></a>
							&nbsp;
							<?php render_delete_button( $row['id'] ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php render_pagination( $page, $result['pages'], $search ); ?>
	</div>
	<?php
}

/**
 * The full view of one submission.
 *
 * @param int $submission_id Submission post ID.
 * @return void
 */
function render_single( int $submission_id ): void {
	$post = PostType\find( $submission_id );

	if ( null === $post ) {
		wp_die( esc_html__( 'No such submission on this site.', 'templ-contact-form' ) );
	}

	$row = PostType\to_array( $post );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Submission', 'templ-contact-form' ); ?></h1>
		<p><a href="<?php echo esc_url( menu_page_url( PAGE_SLUG, false ) ); ?>">&larr; <?php esc_html_e( 'Back to submissions', 'templ-contact-form' ); ?></a></p>

		<table class="form-table" role="presentation">
			<tr><th scope="row"><?php esc_html_e( 'Date', 'templ-contact-form' ); ?></th><td><?php echo esc_html( format_date( $row['created_at'] ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Name', 'templ-contact-form' ); ?></th><td><?php echo esc_html( $row['name'] ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Email', 'templ-contact-form' ); ?></th><td><a href="<?php echo esc_url( 'mailto:' . $row['email'] ); ?>"><?php echo esc_html( $row['email'] ); ?></a></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Subject', 'templ-contact-form' ); ?></th><td><?php echo esc_html( $row['subject'] ); ?></td></tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Message', 'templ-contact-form' ); ?></th>
				<td><?php echo nl2br( esc_html( $row['message'] ) ); ?></td>
			</tr>
		</table>

		<?php render_delete_button( $row['id'] ); ?>
	</div>
	<?php
}

/**
 * Deletes a submission and returns to the list.
 *
 * @return void
 */
function handle_delete(): void {
	if ( ! current_user_can( CAPABILITY ) ) {
		wp_die( esc_html__( 'You do not have permission to delete submissions on this site.', 'templ-contact-form' ), '', [ 'response' => 403 ] );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce action contains this ID, so it is read to know which nonce to check, then verified on the next line before use.
	$submission_id = isset( $_POST['submission_id'] ) ? absint( wp_unslash( $_POST['submission_id'] ) ) : 0;
	check_admin_referer( 'templ_contact_form_delete_' . $submission_id );

	PostType\delete( $submission_id );

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
 * The delete button, a one-button POST form so the action cannot be triggered
 * by a GET an image tag could smuggle.
 *
 * @param int $submission_id Submission post ID.
 * @return void
 */
function render_delete_button( int $submission_id ): void {
	?>
	<form
		method="post"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		style="display:inline"
		onsubmit="return window.confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete this submission? This cannot be undone.', 'templ-contact-form' ) ) ); ?>);"
	>
		<input type="hidden" name="action" value="templ_contact_form_delete" />
		<input type="hidden" name="submission_id" value="<?php echo esc_attr( (string) $submission_id ); ?>" />
		<?php wp_nonce_field( 'templ_contact_form_delete_' . $submission_id ); ?>
		<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'templ-contact-form' ); ?></button>
	</form>
	<?php
}

/**
 * Prev/next pagination, shown only when there is more than one page.
 *
 * @param int    $page   Current page.
 * @param int    $pages  Total pages.
 * @param string $search Active search term, to carry across pages.
 * @return void
 */
function render_pagination( int $page, int $pages, string $search ): void {
	if ( $pages < 2 ) {
		return;
	}

	$base = [ 'page' => PAGE_SLUG ];
	if ( '' !== $search ) {
		$base['s'] = $search;
	}
	?>
	<div class="tablenav bottom">
		<div class="tablenav-pages">
			<span class="displaying-num">
				<?php
				printf(
					/* translators: 1: current page, 2: total pages. */
					esc_html__( 'Page %1$d of %2$d', 'templ-contact-form' ),
					(int) $page,
					(int) $pages
				);
				?>
			</span>
			<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( $base + [ 'paged' => $page - 1 ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Previous', 'templ-contact-form' ); ?></a>
			<?php endif; ?>
			<?php if ( $page < $pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( $base + [ 'paged' => $page + 1 ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Next', 'templ-contact-form' ); ?></a>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * The URL of one submission's single view.
 *
 * @param int $submission_id Submission post ID.
 * @return string
 */
function view_url( int $submission_id ): string {
	return add_query_arg(
		[
			'page'       => PAGE_SLUG,
			'submission' => $submission_id,
		],
		admin_url( 'admin.php' )
	);
}

/**
 * The message for whatever just happened, if anything did.
 *
 * @return string
 */
function current_notice(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a status word this file put in its own redirect URL, to pick a message.
	$result = isset( $_GET['result'] ) ? sanitize_key( wp_unslash( $_GET['result'] ) ) : '';

	return 'deleted' === $result ? __( 'Submission deleted.', 'templ-contact-form' ) : '';
}

/**
 * Shortens a message for the list column without cutting a multibyte character.
 *
 * @param string $message Full message.
 * @return string
 */
function truncate( string $message ): string {
	$flat = trim( preg_replace( '/\s+/', ' ', $message ) );

	if ( mb_strlen( $flat ) <= 80 ) {
		return $flat;
	}

	return mb_substr( $flat, 0, 80 ) . '…';
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
