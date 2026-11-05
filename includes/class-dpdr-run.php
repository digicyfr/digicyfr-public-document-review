<?php
/**
 * Transactional manual scan coordinator.
 *
 * @package Digicyfr_Public_Document_Review
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** One bounded batch per administrator request. */
final class DPDR_Run {
	// Transactional, cursor-locked scans require direct access to the plugin tables.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
	public const MAX_POSTS_PER_BATCH = 25;
	public const MAX_STORED_LINKS    = 50000;

	/**
	 * Read a retained run by UUID.
	 *
	 * @param string $run_id run id input.
	 */
	public static function get( string $run_id ): ?array {
		global $wpdb;
		if ( ! wp_is_uuid( $run_id ) ) {
			return null;
		}
		$table = DPDR_Storage::tables()['runs'];
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %s', $table, $run_id ), ARRAY_A );
		return '' === $wpdb->last_error && is_array( $row ) ? $row : null;
	}

	/** Repair the non-autoloaded state and complete pointer after a terminal commit. */
	public static function reconcile(): bool {
		global $wpdb;
		$table  = DPDR_Storage::tables()['runs'];
		$latest = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', $table ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			return false;
		}
		$complete = (string) $wpdb->get_var( $wpdb->prepare( "SELECT run_id FROM %i WHERE status = 'complete' ORDER BY id DESC LIMIT 1", $table ) );
		if ( '' !== $wpdb->last_error ) {
			return false;
		}
		$state = is_array( $latest ) ? array(
			'schema_version'  => DIGICYFR_PUBLIC_DOCUMENT_REVIEW_VERSION,
			'run_id'          => $latest['run_id'],
			'status'          => $latest['status'],
			'started_at_utc'  => $latest['started_at_utc'],
			'finished_at_utc' => $latest['finished_at_utc'],
			'max_post_id'     => (int) $latest['max_post_id'],
			'cursor_post_id'  => (int) $latest['cursor_post_id'],
			'scanned_count'   => (int) $latest['scanned_count'],
			'skipped_count'   => (int) $latest['skipped_count'],
			'error'           => $latest['error'],
		) : array();
		update_option( 'dpdr_scan_state', $state, false );
		update_option( 'dpdr_active_complete_run_id', $complete, false );
		return true;
	}

	/** Return persisted pointers plus a read-error flag. */
	public static function pointers(): array {
		$error = ! self::reconcile();
		return array(
			'state'           => get_option( 'dpdr_scan_state', array() ),
			'active_complete' => (string) get_option( 'dpdr_active_complete_run_id', '' ),
			'storage_error'   => $error,
		);
	}

	/** Start a new high-water scan, retaining the last complete report. */
	public static function start(): array|WP_Error {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot start a document scan.', 'digicyfr-public-document-review' ) );
		}
		if ( ! DPDR_Storage::transactional() ) {
			return new WP_Error( 'storage', __( 'Scan tables must use InnoDB.', 'digicyfr-public-document-review' ) );
		}
		if ( ! add_option( 'dpdr_start_lock', (string) time(), '', false ) ) {
			$old = (int) get_option( 'dpdr_start_lock', 0 );
			if ( $old > 0 && $old < time() - 120 ) {
				delete_option( 'dpdr_start_lock' );
			}
			if ( ! add_option( 'dpdr_start_lock', (string) time(), '', false ) ) {
				return new WP_Error( 'busy', __( 'Another scan action is in progress.', 'digicyfr-public-document-review' ) );
			}
		}
		try {
			if ( ! self::reconcile() ) {
				return new WP_Error( 'storage', __( 'Could not read scan state.', 'digicyfr-public-document-review' ) );
			}
			$state = get_option( 'dpdr_scan_state', array() );
			if ( is_array( $state ) && in_array( $state['status'] ?? '', array( 'queued', 'running' ), true ) ) {
				return new WP_Error( 'running', __( 'Finish or cancel the current scan first.', 'digicyfr-public-document-review' ) );
			}
			$max = $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status = 'publish' AND post_password = ''" );
			if ( '' !== $wpdb->last_error ) {
				return new WP_Error( 'storage', __( 'Could not read eligible posts.', 'digicyfr-public-document-review' ) );
			}
			$id    = wp_generate_uuid4();
			$now   = current_time( 'mysql', true );
			$table = DPDR_Storage::tables()['runs'];
			if ( false === $wpdb->insert(
				$table,
				array(
					'run_id'         => $id,
					'status'         => 'queued',
					'started_at_utc' => $now,
					'max_post_id'    => (int) $max,
				)
			) ) {
				return new WP_Error( 'storage', __( 'Could not create the scan.', 'digicyfr-public-document-review' ) );
			}
			self::reconcile();
			DPDR_Storage::prune( $id );
			return self::get( $id ) ?? new WP_Error( 'storage', __( 'Could not read scan state.', 'digicyfr-public-document-review' ) );
		} finally {
			delete_option( 'dpdr_start_lock' );
		}
	}

	/**
	 * Replace one source's rows within a surrounding InnoDB transaction.
	 *
	 * @param string   $run_id run id input.
	 * @param int      $post_id post id input.
	 * @param ?WP_Post $post post input.
	 * @param array    $rows rows input.
	 * @throws RuntimeException On database failure.
	 */
	private static function replace_post( string $run_id, int $post_id, ?WP_Post $post, array $rows ): void {
		global $wpdb;
		$table = DPDR_Storage::tables()['links'];
		if ( false === $wpdb->delete(
			$table,
			array(
				'run_id'         => $run_id,
				'source_post_id' => $post_id,
			)
		) ) {
			throw new RuntimeException( 'Could not replace source links.' );
		}
		if ( ! $post ) {
			return;
		}
		$source = array(
			'run_id'                   => $run_id,
			'source_post_id'           => $post_id,
			'source_post_modified_gmt' => $post->post_modified_gmt,
			'source_title_at_scan'     => $post->post_title,
			'source_url_at_scan'       => (string) get_permalink( $post ),
		);
		foreach ( $rows as $row ) {
			if ( false === $wpdb->insert( $table, array_merge( $source, $row ) ) ) {
				throw new RuntimeException( 'Could not store a source link.' );
			}
		}
	}

	/**
	 * Process up to 25 source posts while locking the run row and cursor.
	 *
	 * @param string $run_id run id input.
	 * @throws RuntimeException On database failure.
	 */
	public static function continue_scan( string $run_id ): array|WP_Error {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot continue this scan.', 'digicyfr-public-document-review' ) );
		}
		if ( ! DPDR_Storage::transactional() || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'storage', __( 'Could not begin a safe scan transaction.', 'digicyfr-public-document-review' ) );
		}
		$tables = DPDR_Storage::tables();
		try {
			$run = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %s FOR UPDATE', $tables['runs'], $run_id ), ARRAY_A );
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Could not lock scan.' );
			}
			if ( ! is_array( $run ) || ! in_array( $run['status'], array( 'queued', 'running' ), true ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'not_running', __( 'This scan is no longer running.', 'digicyfr-public-document-review' ) );
			}
			$cursor = (int) $run['cursor_post_id'];
			$max    = (int) $run['max_post_id'];
			$batch  = $wpdb->get_results( $wpdb->prepare( "SELECT ID, OCTET_LENGTH(post_content) AS bytes FROM {$wpdb->posts} WHERE ID > %d AND ID <= %d AND post_type IN ('post','page') AND post_status = 'publish' AND post_password = '' ORDER BY ID ASC LIMIT %d", $cursor, $max, self::MAX_POSTS_PER_BATCH ), ARRAY_A );
			if ( '' !== $wpdb->last_error || ! is_array( $batch ) ) {
				throw new RuntimeException( 'Could not read the next source batch.' );
			}
			$row_cap = false;
			foreach ( $batch as $candidate ) {
				$post_id = (int) $candidate['ID'];
				$cursor  = $post_id;
				$post    = null;
				if ( (int) $candidate['bytes'] > DPDR_Scanner::MAX_POST_BYTES ) {
					$result = array(
						'rows' => array(),
						'skip' => 'post_too_large',
					);
				} else {
					$post = get_post( $post_id );
					if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
						$result = array(
							'rows' => array(),
							'skip' => 'source_changed',
						);
						$post   = null;
					} else {
						$result = DPDR_Scanner::scan_post( $post );
					}
				}
				if ( '' === $result['skip'] && (int) $run['link_count'] + count( $result['rows'] ) > self::MAX_STORED_LINKS ) {
					$result  = array(
						'rows' => array(),
						'skip' => 'row_cap',
					);
					$row_cap = true;
				}
				self::replace_post( $run_id, $post_id, $post, $result['rows'] );
				if ( '' === $result['skip'] ) {
					++$run['scanned_count'];
					$run['link_count'] += count( $result['rows'] );
				} else {
					++$run['skipped_count'];
					$run['error'] = $result['skip'];
				}
				if ( $row_cap ) {
					break;
				}
			}
			$remaining = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND ID <= %d AND post_type IN ('post','page') AND post_status = 'publish' AND post_password = '' ORDER BY ID ASC LIMIT 1", $cursor, $max ) );
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Could not verify remaining sources.' );
			}
			$done                   = $row_cap || null === $remaining;
			$run['status']          = $done ? ( (int) $run['skipped_count'] > 0 ? 'incomplete' : 'complete' ) : 'running';
			$run['cursor_post_id']  = $cursor;
			$run['finished_at_utc'] = $done ? current_time( 'mysql', true ) : null;
			$updated                = $wpdb->update(
				$tables['runs'],
				array(
					'status'          => $run['status'],
					'cursor_post_id'  => $run['cursor_post_id'],
					'scanned_count'   => $run['scanned_count'],
					'skipped_count'   => $run['skipped_count'],
					'link_count'      => $run['link_count'],
					'finished_at_utc' => $run['finished_at_utc'],
					'error'           => $run['error'],
				),
				array( 'run_id' => $run_id )
			);
			if ( false === $updated || false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'Could not commit scan batch.' );
			}
			self::reconcile();
			if ( $done ) {
				DPDR_Storage::prune( $run_id );
			}
			return self::get( $run_id ) ?? new WP_Error( 'storage', __( 'Could not read scan state.', 'digicyfr-public-document-review' ) );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			$wpdb->update(
				$tables['runs'],
				array(
					'status'          => 'incomplete',
					'finished_at_utc' => current_time( 'mysql', true ),
					'skipped_count'   => (int) ( $run['skipped_count'] ?? 0 ) + 1,
					'error'           => 'scan_failed',
				),
				array( 'run_id' => $run_id )
			);
			self::reconcile();
			return new WP_Error( 'scan_failed', __( 'The scan is incomplete. The previous complete report remains available.', 'digicyfr-public-document-review' ) );
		}
	}

	/**
	 * Cancel one queued or running scan under the same row lock.
	 *
	 * @param string $run_id run id input.
	 */
	public static function cancel( string $run_id ): array|WP_Error {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) || ! DPDR_Storage::transactional() || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot cancel this scan.', 'digicyfr-public-document-review' ) );
		}
		$table = DPDR_Storage::tables()['runs'];
		$run   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %s FOR UPDATE', $table, $run_id ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $run ) || ! in_array( $run['status'], array( 'queued', 'running' ), true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'not_running', __( 'This scan is no longer running.', 'digicyfr-public-document-review' ) );
		}
		$updated = $wpdb->update(
			$table,
			array(
				'status'          => 'cancelled',
				'finished_at_utc' => current_time( 'mysql', true ),
			),
			array( 'run_id' => $run_id )
		);
		if ( false === $updated || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'storage', __( 'Could not cancel the scan.', 'digicyfr-public-document-review' ) );
		}
		self::reconcile();
		return self::get( $run_id ) ?? new WP_Error( 'storage', __( 'Could not read scan state.', 'digicyfr-public-document-review' ) );
	}
}
