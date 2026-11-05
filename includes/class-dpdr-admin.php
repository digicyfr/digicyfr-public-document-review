<?php
/**
 * Private inventory, review forms, scan actions and guarded CSV.
 *
 * @package Digicyfr_Public_Document_Review
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Accessible administrator and editor interface. */
final class DPDR_Admin {
	// Custom inventory tables require uncached, permission-filtered SQL reads.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	private const PAGE      = 'digicyfr-public-document-review';
	private const PAGE_SIZE = 20;
	private const CSV_BATCH = 500;

	/** Register private WordPress hooks only. */
	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'translations' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'start', 'continue', 'cancel', 'save', 'export' ) as $action ) {
			add_action( 'admin_post_dpdr_' . $action, array( __CLASS__, 'handle' ) );
		}
	}

	/** Load self-hosted Polish translations. */
	public static function translations(): void {
		load_plugin_textdomain( 'digicyfr-public-document-review', false, dirname( plugin_basename( DIGICYFR_PUBLIC_DOCUMENT_REVIEW_PATH . 'digicyfr-public-document-review.php' ) ) . '/languages' );
	}

	/** Give attachment editors a private inventory without exposing scan/export controls. */
	public static function menu(): void {
		add_menu_page( __( 'Public Document Review', 'digicyfr-public-document-review' ), __( 'PDF Reviews', 'digicyfr-public-document-review' ), 'upload_files', self::PAGE, array( __CLASS__, 'screen' ), 'dashicons-media-document', 82 );
	}

	/** Load only local page assets. */
	public static function assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_' . self::PAGE !== $screen->id ) {
			return;
		}
		wp_enqueue_style( 'dpdr-admin', DIGICYFR_PUBLIC_DOCUMENT_REVIEW_URL . 'assets/admin.css', array(), DIGICYFR_PUBLIC_DOCUMENT_REVIEW_VERSION );
		wp_enqueue_script( 'dpdr-admin', DIGICYFR_PUBLIC_DOCUMENT_REVIEW_URL . 'assets/admin.js', array(), DIGICYFR_PUBLIC_DOCUMENT_REVIEW_VERSION, true );
	}

	/**
	 * Stable localized scan state.
	 *
	 * @param string $status status input.
	 */
	private static function scan_label( string $status ): string {
		return match ( $status ) {
			'queued' => __( 'Queued', 'digicyfr-public-document-review' ),
			'running' => __( 'Scan in progress', 'digicyfr-public-document-review' ),
			'complete' => __( 'Complete scan', 'digicyfr-public-document-review' ),
			'incomplete' => __( 'Incomplete scan', 'digicyfr-public-document-review' ),
			'cancelled' => __( 'Cancelled scan', 'digicyfr-public-document-review' ),
			default => __( 'No scan', 'digicyfr-public-document-review' ),
		};
	}

	/**
	 * Stable localized match state; no uncertain row is presented as matched.
	 *
	 * @param string $state state input.
	 */
	private static function match_label( string $state ): string {
		return match ( $state ) {
			'matched' => __( 'Matched PDF attachment', 'digicyfr-public-document-review' ),
			'id_url_mismatch' => __( 'Uncertain: changed or offloaded URL', 'digicyfr-public-document-review' ),
			'attachment_page' => __( 'Indirect attachment page link', 'digicyfr-public-document-review' ),
			'unmatched_local' => __( 'Unmatched local PDF link', 'digicyfr-public-document-review' ),
			'unmatched_external' => __( 'Unmatched external PDF link', 'digicyfr-public-document-review' ),
			'ambiguous' => __( 'Ambiguous attachment hints', 'digicyfr-public-document-review' ),
			default => __( 'Uncertain link', 'digicyfr-public-document-review' ),
		};
	}

	/**
	 * Localized editorial due state.
	 *
	 * @param string $status status input.
	 */
	private static function due_label( string $status ): string {
		return match ( $status ) {
			'due' => __( 'Due today', 'digicyfr-public-document-review' ),
			'overdue' => __( 'Overdue', 'digicyfr-public-document-review' ),
			'upcoming' => __( 'Upcoming', 'digicyfr-public-document-review' ),
			default => __( 'Unscheduled', 'digicyfr-public-document-review' ),
		};
	}

	/**
	 * Source status based on saved scan fields and current post.
	 *
	 * @param array $row row input.
	 */
	public static function source_status( array $row ): string {
		$post = get_post( (int) $row['source_post_id'] );
		if ( ! $post ) {
			return 'deleted';
		}
		return 'publish' === $post->post_status
			&& '' === $post->post_password
			&& in_array( $post->post_type, array( 'post', 'page' ), true )
			&& $post->post_modified_gmt === $row['source_post_modified_gmt']
			&& hash( 'sha256', $post->post_content ) === ( $row['source_post_content_sha256'] ?? '' )
			&& $post->post_title === $row['source_title_at_scan']
			&& (string) get_permalink( $post ) === $row['source_url_at_scan'] ? 'current' : 'stale';
	}

	/**
	 * Localized current, changed, or deleted source state.
	 *
	 * @param string $status status input.
	 */
	private static function source_label( string $status ): string {
		return match ( $status ) {
			'current' => __( 'Current source', 'digicyfr-public-document-review' ),
			'stale' => __( 'Changed source — rescan needed', 'digicyfr-public-document-review' ),
			default => __( 'Deleted source', 'digicyfr-public-document-review' ),
		};
	}

	/**
	 * Explain stored skip codes without showing raw identifiers to editors.
	 *
	 * @param string $reason reason input.
	 */
	private static function reason_label( string $reason ): string {
		return match ( $reason ) {
			'post_too_large' => __( 'Source content exceeds 1 MiB', 'digicyfr-public-document-review' ),
			'source_changed' => __( 'Source changed during the scan', 'digicyfr-public-document-review' ),
			'row_cap' => __( '50,000 stored links limit reached', 'digicyfr-public-document-review' ),
			'url_too_long' => __( 'A link exceeds 2048 bytes', 'digicyfr-public-document-review' ),
			'too_many_links' => __( 'More than 300 saved links in one source', 'digicyfr-public-document-review' ),
			'too_many_hints' => __( 'More than 10 competing File block IDs', 'digicyfr-public-document-review' ),
			'blocks_too_deep' => __( 'Block nesting exceeds the safety limit', 'digicyfr-public-document-review' ),
			'parse_failed' => __( 'Saved content could not be parsed', 'digicyfr-public-document-review' ),
			default => __( 'Scan storage or source error', 'digicyfr-public-document-review' ),
		};
	}

	/** One private page with admin-only scan/export and object-filtered review groups. */
	public static function screen(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You cannot view PDF reviews.', 'digicyfr-public-document-review' ) );
		}
		$pointers = DPDR_Run::pointers();
		$state    = is_array( $pointers['state'] ) ? $pointers['state'] : array();
		$run      = ! empty( $state['run_id'] ) ? DPDR_Run::get( (string) $state['run_id'] ) : null;
		$complete = '' !== $pointers['active_complete'] ? DPDR_Run::get( $pointers['active_complete'] ) : null;
		if ( ( ! empty( $state['run_id'] ) && ! $run ) || ( '' !== $pointers['active_complete'] && ! $complete ) ) {
			wp_die( esc_html__( 'Could not read the saved inventory. Retry later.', 'digicyfr-public-document-review' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only allowlisted filter.
		$filter = isset( $_GET['dpdr_filter'] ) && is_string( $_GET['dpdr_filter'] ) ? sanitize_key( wp_unslash( $_GET['dpdr_filter'] ) ) : 'all';
		$filter = in_array( $filter, array( 'all', 'due', 'overdue', 'upcoming', 'unscheduled', 'uncertain' ), true ) ? $filter : 'all';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page number.
		$page = isset( $_GET['dpdr_page'] ) ? max( 1, min( 1000, absint( $_GET['dpdr_page'] ) ) ) : 1;
		?>
		<div class="wrap dpdr-wrap">
			<h1><?php echo esc_html__( 'Public Document Review', 'digicyfr-public-document-review' ); ?></h1>
			<p><?php echo esc_html__( 'This private inventory checks saved PDF links in public posts and pages. It does not read PDFs, check download availability or legal validity, send reminders, or change files.', 'digicyfr-public-document-review' ); ?></p>
			<?php self::notice(); ?>
			<?php
			if ( $pointers['storage_error'] ) :
				?>
				<div class="notice notice-error dpdr-inline" role="alert"><p><?php echo esc_html__( 'Scan storage could not be read; the previous report pointer was retained.', 'digicyfr-public-document-review' ); ?></p></div><?php endif; ?>
			<?php if ( $run ) : ?>
				<section aria-labelledby="dpdr-scan-heading"><h2 id="dpdr-scan-heading"><?php echo esc_html__( 'Latest scan', 'digicyfr-public-document-review' ); ?></h2>
					<p><strong><?php echo esc_html( self::scan_label( $run['status'] ) ); ?></strong> · <?php echo esc_html__( 'Started UTC:', 'digicyfr-public-document-review' ); ?> <?php echo esc_html( $run['started_at_utc'] ); ?> · <?php echo esc_html__( 'Scanned:', 'digicyfr-public-document-review' ); ?> <?php echo esc_html( $run['scanned_count'] ); ?> · <?php echo esc_html__( 'Skipped:', 'digicyfr-public-document-review' ); ?> <?php echo esc_html( $run['skipped_count'] ); ?> · <?php echo esc_html__( 'Links:', 'digicyfr-public-document-review' ); ?> <?php echo esc_html( $run['link_count'] ); ?></p>
					<p><?php echo esc_html__( 'ID cursor / high-water:', 'digicyfr-public-document-review' ); ?> <?php echo esc_html( $run['cursor_post_id'] . ' / ' . $run['max_post_id'] ); ?>
					<?php
					if ( $run['error'] ) :
						?>
						· <?php echo esc_html__( 'Coverage reason:', 'digicyfr-public-document-review' ); ?> <?php echo esc_html( self::reason_label( $run['error'] ) ); ?><?php endif; ?></p>
					<?php
					if ( 'complete' !== $run['status'] ) :
						?>
						<p class="notice notice-warning dpdr-inline" role="status"><?php echo esc_html__( 'This scan is in progress or incomplete. Its rows cannot be exported as a complete inventory.', 'digicyfr-public-document-review' ); ?></p><?php endif; ?>
					<?php
					if ( 'row_cap' === $run['error'] ) :
						?>
						<p class="notice notice-warning dpdr-inline" role="status"><?php echo esc_html__( 'The 50,000-link limit stopped this scan; later source IDs were not scanned.', 'digicyfr-public-document-review' ); ?></p><?php endif; ?>
				</section>
			<?php endif; ?>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<?php if ( $run && in_array( $run['status'], array( 'queued', 'running' ), true ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="dpdr_continue" /><input type="hidden" name="run_id" value="<?php echo esc_attr( $run['run_id'] ); ?>" /><?php wp_nonce_field( 'dpdr_continue_' . $run['run_id'] ); ?><?php submit_button( __( 'Continue one batch', 'digicyfr-public-document-review' ), 'primary', 'submit', false ); ?></form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="dpdr_cancel" /><input type="hidden" name="run_id" value="<?php echo esc_attr( $run['run_id'] ); ?>" /><?php wp_nonce_field( 'dpdr_cancel_' . $run['run_id'] ); ?><?php submit_button( __( 'Cancel scan', 'digicyfr-public-document-review' ), 'secondary', 'submit', false ); ?></form>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="dpdr_start" /><?php wp_nonce_field( 'dpdr_start' ); ?><?php submit_button( __( 'Start a new scan', 'digicyfr-public-document-review' ) ); ?></form>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( $complete ) : ?>
				<section aria-labelledby="dpdr-report-heading"><h2 id="dpdr-report-heading"><?php echo esc_html__( 'Latest complete inventory', 'digicyfr-public-document-review' ); ?></h2>
					<p><?php echo esc_html__( 'Completed UTC:', 'digicyfr-public-document-review' ); ?> <?php echo esc_html( $complete['finished_at_utc'] ); ?>. <?php echo esc_html__( 'This is a saved snapshot; source edits and new uploads require a rescan.', 'digicyfr-public-document-review' ); ?></p>
					<?php self::filters( $filter ); ?>
					<?php
					if ( 'uncertain' === $filter && current_user_can( 'manage_options' ) ) :
						self::uncertain_rows( $complete['run_id'], $page );
						?>
						<?php
					elseif ( 'uncertain' === $filter ) :
						?>
						<p><?php echo esc_html__( 'Uncertain links are visible to administrators.', 'digicyfr-public-document-review' ); ?></p>
						<?php
					else :
						self::matched_groups( $complete['run_id'], $filter, $page );
						?>
						<?php endif; ?>
					<?php
					if ( current_user_can( 'manage_options' ) ) :
						?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="dpdr_export" /><input type="hidden" name="run_id" value="<?php echo esc_attr( $complete['run_id'] ); ?>" /><?php wp_nonce_field( 'dpdr_export_' . $complete['run_id'] ); ?><?php submit_button( __( 'Export complete inventory CSV', 'digicyfr-public-document-review' ), 'secondary', 'submit', false ); ?></form><?php endif; ?>
				</section>
				<?php
			elseif ( ! $pointers['storage_error'] ) :
				?>
				<p><?php echo esc_html__( 'No complete inventory is available yet.', 'digicyfr-public-document-review' ); ?></p><?php endif; ?>
		</div>
		<?php
	}

	/** Show action result and move focus after redirect. */
	private static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect result flag.
		$notice = isset( $_GET['dpdr_notice'] ) && is_string( $_GET['dpdr_notice'] ) ? sanitize_key( wp_unslash( $_GET['dpdr_notice'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect error flag.
		$error = isset( $_GET['dpdr_error'] ) && is_string( $_GET['dpdr_error'] ) ? sanitize_key( wp_unslash( $_GET['dpdr_error'] ) ) : '';
		if ( $notice ) {
			echo '<div class="notice notice-success dpdr-result" role="status" tabindex="-1"><p>' . esc_html__( 'Action saved.', 'digicyfr-public-document-review' ) . '</p></div>';
		}
		if ( $error ) {
			echo '<div class="notice notice-error dpdr-result" role="alert" tabindex="-1"><p>' . esc_html__( 'Action failed. Check the form and permissions.', 'digicyfr-public-document-review' ) . '</p></div>';
		}
	}

	/**
	 * Render labelled status filters.
	 *
	 * @param string $selected selected input.
	 */
	private static function filters( string $selected ): void {
		$labels = array(
			'all'         => __( 'All matched PDFs', 'digicyfr-public-document-review' ),
			'due'         => __( 'Due today', 'digicyfr-public-document-review' ),
			'overdue'     => __( 'Overdue', 'digicyfr-public-document-review' ),
			'upcoming'    => __( 'Upcoming', 'digicyfr-public-document-review' ),
			'unscheduled' => __( 'Unscheduled', 'digicyfr-public-document-review' ),
		);
		if ( current_user_can( 'manage_options' ) ) {
			$labels['uncertain'] = __( 'Uncertain links', 'digicyfr-public-document-review' );
		}
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" /><label for="dpdr-filter"><?php echo esc_html__( 'Inventory filter', 'digicyfr-public-document-review' ); ?></label><select id="dpdr-filter" name="dpdr_filter">
		<?php
		foreach ( $labels as $key => $label ) :
			?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $selected ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><?php submit_button( __( 'Apply filter', 'digicyfr-public-document-review' ), 'secondary', 'submit', false ); ?></form>
		<?php
	}

	/**
	 * Build one private filter/page URL.
	 *
	 * @param string $filter filter input.
	 * @param int    $page page input.
	 */
	private static function page_url( string $filter, int $page ): string {
		return add_query_arg(
			array(
				'page'        => self::PAGE,
				'dpdr_filter' => $filter,
				'dpdr_page'   => $page,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Return 20 authorized attachment IDs after filtering before pagination.
	 *
	 * @param string $run_id run id input.
	 * @param string $filter filter input.
	 * @param int    $page page input.
	 */
	public static function group_page( string $run_id, string $filter, int $page ): array|WP_Error {
		global $wpdb;
		if ( ! current_user_can( 'upload_files' ) ) {
			return array(
				'ids'      => array(),
				'has_next' => false,
			);
		}
		$run = DPDR_Run::get( $run_id );
		if ( ! $run || 'complete' !== $run['status'] || get_option( 'dpdr_active_complete_run_id', '' ) !== $run_id ) {
			return new WP_Error( 'storage', __( 'Could not read the complete inventory.', 'digicyfr-public-document-review' ) );
		}
		$filter   = in_array( $filter, array( 'all', 'due', 'overdue', 'upcoming', 'unscheduled' ), true ) ? $filter : 'all';
		$table    = DPDR_Storage::tables()['links'];
		$cursor   = 0;
		$skip     = ( max( 1, min( 1000, $page ) ) - 1 ) * self::PAGE_SIZE;
		$ids      = array();
		$id_count = 0;
		while ( $id_count <= self::PAGE_SIZE ) {
			$batch = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT attachment_id FROM %i WHERE run_id = %s AND match_state = 'matched' AND attachment_id > %d ORDER BY attachment_id ASC LIMIT 200", $table, $run_id, $cursor ) );
			if ( '' !== $wpdb->last_error || ! is_array( $batch ) ) {
				return new WP_Error( 'storage', __( 'Could not read the inventory.', 'digicyfr-public-document-review' ) );
			}
			foreach ( $batch as $candidate ) {
				$cursor = (int) $candidate;
				if ( ! current_user_can( 'manage_options' ) && ! DPDR_Review::can_edit( $cursor ) ) {
					continue;
				}
				$review = DPDR_Review::get( $cursor );
				if ( 'all' !== $filter && DPDR_Review::status( $review['due'] ) !== $filter ) {
					continue;
				}
				if ( $skip > 0 ) {
					--$skip;
					continue;
				}
				$ids[] = $cursor;
				if ( count( $ids ) > self::PAGE_SIZE ) {
					break;
				}
			}
			$id_count = count( $ids );
			if ( count( $batch ) < 200 || $id_count > self::PAGE_SIZE ) {
				break;
			}
		}
		return array(
			'ids'      => array_slice( $ids, 0, self::PAGE_SIZE ),
			'has_next' => count( $ids ) > self::PAGE_SIZE,
		);
	}

	/**
	 * Show grouped PDF attachments with references and review controls.
	 *
	 * @param string $run_id run id input.
	 * @param string $filter filter input.
	 * @param int    $page page input.
	 */
	private static function matched_groups( string $run_id, string $filter, int $page ): void {
		global $wpdb;
		$groups = self::group_page( $run_id, $filter, $page );
		if ( is_wp_error( $groups ) ) {
			wp_die( esc_html( $groups->get_error_message() ) );
		}
		if ( array() === $groups['ids'] ) {
			echo '<p>' . esc_html__( 'No matched PDFs in this filter. The inventory does not certify document validity.', 'digicyfr-public-document-review' ) . '</p>';
		}
		$table = DPDR_Storage::tables()['links'];
		foreach ( $groups['ids'] as $id ) {
			$review = DPDR_Review::get( $id );
			$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE run_id = %s AND attachment_id = %d AND match_state = 'matched' ORDER BY source_post_id ASC,id ASC LIMIT 11", $table, $run_id, $id ), ARRAY_A );
			if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
				wp_die( esc_html__( 'Could not read PDF references.', 'digicyfr-public-document-review' ) );
			}
			$more  = count( $rows ) > 10;
			$rows  = array_slice( $rows, 0, 10 );
			$title = get_the_title( $id );
			?>
			<section class="dpdr-card" aria-labelledby="dpdr-attachment-<?php echo esc_attr( (string) $id ); ?>">
				<h3 id="dpdr-attachment-<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html( $title ); ?> <small>#<?php echo esc_html( (string) $id ); ?></small></h3>
				<p><strong><?php echo esc_html( self::due_label( DPDR_Review::status( $review['due'] ) ) ); ?></strong> · <?php echo esc_html__( 'Owner:', 'digicyfr-public-document-review' ); ?> <?php echo esc_html( '' !== DPDR_Review::owner_name( $review['owner_id'] ) ? DPDR_Review::owner_name( $review['owner_id'] ) : __( 'None', 'digicyfr-public-document-review' ) ); ?> · <?php echo esc_html__( 'Next review:', 'digicyfr-public-document-review' ); ?> <?php echo esc_html( '' !== $review['due'] ? $review['due'] : '—' ); ?></p>
				<div class="dpdr-table-scroll"><table class="widefat striped"><thead><tr><th scope="col"><?php echo esc_html__( 'Source page', 'digicyfr-public-document-review' ); ?></th><th scope="col"><?php echo esc_html__( 'PDF link', 'digicyfr-public-document-review' ); ?></th><th scope="col"><?php echo esc_html__( 'Occurrences', 'digicyfr-public-document-review' ); ?></th><th scope="col"><?php echo esc_html__( 'Source status', 'digicyfr-public-document-review' ); ?></th></tr></thead><tbody>
				<?php
				foreach ( $rows as $row ) :
					?>
					<tr><th scope="row"><?php echo esc_html( $row['source_title_at_scan'] ); ?> (#<?php echo esc_html( $row['source_post_id'] ); ?>) <a href="<?php echo esc_url( $row['source_url_at_scan'] ); ?>"><?php echo esc_html__( 'Open source', 'digicyfr-public-document-review' ); ?></a></th><td class="dpdr-url"><a href="<?php echo esc_url( $row['href'] ); ?>"><?php echo esc_html( $row['href'] ); ?></a></td><td><?php echo esc_html( $row['occurrence_count'] ); ?></td><td><?php echo esc_html( self::source_label( self::source_status( $row ) ) ); ?></td></tr><?php endforeach; ?>
				</tbody></table></div>
				<?php
				if ( $more ) :
					?>
					<p><?php echo esc_html__( 'More references exist in this snapshot and CSV.', 'digicyfr-public-document-review' ); ?></p><?php endif; ?>
				<?php
				if ( DPDR_Review::can_edit( $id ) ) :
					self::review_form( $id, $review );
					?>
					<?php endif; ?>
			</section>
			<?php
		}
		self::pagination( $filter, $page, $groups['has_next'] );
	}

	/**
	 * Render an object-checked review form for one matched PDF.
	 *
	 * @param int   $id id input.
	 * @param array $review review input.
	 */
	private static function review_form( int $id, array $review ): void {
		?>
		<form class="dpdr-review-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="dpdr_save" /><input type="hidden" name="attachment_id" value="<?php echo esc_attr( (string) $id ); ?>" /><?php wp_nonce_field( 'dpdr_save_' . $id ); ?>
			<label><?php echo esc_html__( 'Owner user ID (0 for none)', 'digicyfr-public-document-review' ); ?> <input type="number" name="owner_id" min="0" value="<?php echo esc_attr( (string) $review['owner_id'] ); ?>" /></label>
			<label><?php echo esc_html__( 'Next review date (site local)', 'digicyfr-public-document-review' ); ?> <input type="date" name="due" value="<?php echo esc_attr( $review['due'] ); ?>" /></label>
			<label><input type="checkbox" name="unschedule" value="1" /> <?php echo esc_html__( 'Mark reviewed without a next date (Unscheduled)', 'digicyfr-public-document-review' ); ?></label>
			<label><?php echo esc_html__( 'Private note (500 characters maximum)', 'digicyfr-public-document-review' ); ?> <textarea name="note" maxlength="500" rows="2"><?php echo esc_textarea( $review['note'] ); ?></textarea></label>
			<button class="button" type="submit" name="review_action" value="save"><?php echo esc_html__( 'Save review details', 'digicyfr-public-document-review' ); ?></button>
			<button class="button button-primary" type="submit" name="review_action" value="mark"><?php echo esc_html__( 'Mark reviewed', 'digicyfr-public-document-review' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Show uncertain links only to administrators, without review controls.
	 *
	 * @param string $run_id run id input.
	 * @param int    $page page input.
	 */
	private static function uncertain_rows( string $run_id, int $page ): void {
		global $wpdb;
		$table = DPDR_Storage::tables()['links'];
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE run_id = %s AND match_state <> 'matched' ORDER BY id ASC LIMIT %d OFFSET %d", $table, $run_id, self::PAGE_SIZE + 1, ( $page - 1 ) * self::PAGE_SIZE ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			wp_die( esc_html__( 'Could not read uncertain links.', 'digicyfr-public-document-review' ) );
		}
		$more = count( $rows ) > self::PAGE_SIZE;
		$rows = array_slice( $rows, 0, self::PAGE_SIZE );
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No uncertain PDF links in this complete snapshot.', 'digicyfr-public-document-review' ) . '</p>';
		}
		?>
		<div class="dpdr-table-scroll"><table class="widefat striped"><thead><tr><th scope="col"><?php echo esc_html__( 'Source page', 'digicyfr-public-document-review' ); ?></th><th scope="col"><?php echo esc_html__( 'PDF link', 'digicyfr-public-document-review' ); ?></th><th scope="col"><?php echo esc_html__( 'Match state', 'digicyfr-public-document-review' ); ?></th><th scope="col"><?php echo esc_html__( 'File block ID hints', 'digicyfr-public-document-review' ); ?></th></tr></thead><tbody>
		<?php
		foreach ( $rows as $row ) :
			?>
			<tr><th scope="row"><?php echo esc_html( $row['source_title_at_scan'] ); ?> (#<?php echo esc_html( $row['source_post_id'] ); ?>)</th><td class="dpdr-url"><a href="<?php echo esc_url( $row['href'] ); ?>"><?php echo esc_html( $row['href'] ); ?></a></td><td><?php echo esc_html( self::match_label( $row['match_state'] ) ); ?></td><td><?php echo esc_html( implode( ', ', (array) json_decode( $row['hint_ids_json'], true ) ) ); ?></td></tr><?php endforeach; ?>
		</tbody></table></div>
		<?php self::pagination( 'uncertain', $page, $more ); ?>
		<?php
	}

	/**
	 * Page links are ordinary keyboard-accessible anchors.
	 *
	 * @param string $filter filter input.
	 * @param int    $page page input.
	 * @param bool   $has_next has next input.
	 */
	private static function pagination( string $filter, int $page, bool $has_next ): void {
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url( self::page_url( $filter, $page - 1 ) ) . '">' . esc_html__( 'Previous page', 'digicyfr-public-document-review' ) . '</a> ';
		}
		if ( $has_next ) {
			echo '<a class="button" href="' . esc_url( self::page_url( $filter, $page + 1 ) ) . '">' . esc_html__( 'Next page', 'digicyfr-public-document-review' ) . '</a>';
		}
	}

	/**
	 * Neutralize spreadsheet formulas in every CSV field.
	 *
	 * @param mixed $value value input.
	 */
	public static function csv_safe( $value ): string {
		$text = (string) $value;
		return preg_match( '/^(?:[\x00-\x20]*[=+\-@]|[\t\r])/u', $text ) ? "'" . $text : $text;
	}

	/**
	 * Spool a complete snapshot in bounded database chunks.
	 *
	 * @param string $run_id run id input.
	 * @param mixed  $stream stream input.
	 */
	public static function stream_csv( string $run_id, $stream ): bool {
		global $wpdb;
		$run = DPDR_Run::get( $run_id );
		if ( ! current_user_can( 'manage_options' ) || ! $run || 'complete' !== $run['status'] || get_option( 'dpdr_active_complete_run_id', '' ) !== $run_id || ! is_resource( $stream ) ) {
			return false;
		}
		$header = array( 'scan_completed_at_utc', 'source_post_id', 'source_title', 'source_url', 'pdf_url', 'attachment_id', 'match_state', 'owner_id', 'owner_name', 'due_date', 'review_status', 'last_reviewed_at_utc', 'note' );
		if ( false === fputcsv( $stream, $header, ',', '"', '' ) ) {
			return false;
		}
		$table  = DPDR_Storage::tables()['links'];
		$cursor = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %s AND id > %d ORDER BY id ASC LIMIT %d', $table, $run_id, $cursor, self::CSV_BATCH ), ARRAY_A );
			if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
				return false;
			}
			foreach ( $rows as $row ) {
				$cursor  = (int) $row['id'];
				$matched = 'matched' === $row['match_state'];
				$review  = $matched ? DPDR_Review::get( (int) $row['attachment_id'] ) : array();
				$fields  = array(
					$run['finished_at_utc'],
					$row['source_post_id'],
					$row['source_title_at_scan'],
					$row['source_url_at_scan'],
					$row['href'],
					$matched ? $row['attachment_id'] : '',
					$row['match_state'],
					$matched ? $review['owner_id'] : '',
					$matched ? DPDR_Review::owner_name( $review['owner_id'] ) : '',
					$matched ? $review['due'] : '',
					$matched ? DPDR_Review::status( $review['due'] ) : '',
					$matched ? $review['reviewed_at_utc'] : '',
					$matched ? $review['note'] : '',
				);
				if ( false === fputcsv( $stream, array_map( array( __CLASS__, 'csv_safe' ), $fields ), ',', '"', '' ) ) {
					return false;
				}
			}
			$count = count( $rows );
		} while ( self::CSV_BATCH === $count );
		return true;
	}

	/** Nonce and capability guarded admin POST dispatcher. */
	public static function handle(): void {
		$raw_action = isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		$action     = str_starts_with( $raw_action, 'dpdr_' ) ? substr( $raw_action, 5 ) : '';
		if ( ! in_array( $action, array( 'start', 'continue', 'cancel', 'save', 'export' ), true ) ) {
			wp_die( esc_html__( 'Unknown action.', 'digicyfr-public-document-review' ) );
		}
		$id            = isset( $_POST['run_id'] ) && is_string( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		check_admin_referer( 'dpdr_' . $action . ( 'start' === $action ? '' : ( 'save' === $action ? '_' . $attachment_id : '_' . $id ) ) );
		if ( 'save' === $action ) {
			$input  = array(
				'owner_id'      => isset( $_POST['owner_id'] ) && is_scalar( $_POST['owner_id'] ) ? sanitize_text_field( wp_unslash( $_POST['owner_id'] ) ) : '0',
				'due'           => isset( $_POST['due'] ) && is_string( $_POST['due'] ) ? sanitize_text_field( wp_unslash( $_POST['due'] ) ) : '',
				'unschedule'    => isset( $_POST['unschedule'] ) ? '1' : '0',
				'note'          => isset( $_POST['note'] ) && is_string( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
				'review_action' => isset( $_POST['review_action'] ) && is_string( $_POST['review_action'] ) ? sanitize_key( wp_unslash( $_POST['review_action'] ) ) : 'save',
			);
			$result = DPDR_Review::save( $attachment_id, $input );
		} else {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Only administrators can scan or export.', 'digicyfr-public-document-review' ) );
			}
			if ( 'export' === $action ) {
				$stream = fopen( 'php://temp/maxmemory:2097152', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded-memory spool avoids partial CSV downloads.
				if ( ! is_resource( $stream ) || ! self::stream_csv( $id, $stream ) ) {
					if ( is_resource( $stream ) ) {
						fclose( $stream ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Temporary spool.
					wp_die( esc_html__( 'A complete CSV could not be generated. Retry later.', 'digicyfr-public-document-review' ) );
				}
				rewind( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Temporary spool.
				nocache_headers();
				header( 'Content-Type: text/csv; charset=UTF-8' );
				header( 'Content-Disposition: attachment; filename="digicyfr-public-document-review.csv"' );
				fpassthru( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fpassthru -- Send fully validated CSV.
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Temporary spool.
				exit;
			}
			$result = match ( $action ) {
				'start' => DPDR_Run::start(),
				'continue' => DPDR_Run::continue_scan( $id ),
				'cancel' => DPDR_Run::cancel( $id ),
			};
		}
		$target = admin_url( 'admin.php?page=' . self::PAGE );
		$target = add_query_arg( is_wp_error( $result ) ? 'dpdr_error' : 'dpdr_notice', $action, $target );
		wp_safe_redirect( $target );
		exit;
	}
}
