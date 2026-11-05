<?php
/**
 * Plugin-owned InnoDB tables and retention.
 *
 * @package Digicyfr_Public_Document_Review
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Versioned private scan storage. */
final class DPDR_Storage {
	// The plugin owns these versioned custom tables and cannot cache engine checks.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	/** Return fixed site-prefixed table names. */
	public static function tables(): array {
		global $wpdb;
		return array(
			'runs'  => $wpdb->prefix . 'dpdr_runs',
			'links' => $wpdb->prefix . 'dpdr_links',
		);
	}

	/** Create or upgrade plugin tables. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$tables  = self::tables();
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			array(
				"CREATE TABLE {$tables['runs']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id char(36) NOT NULL,
				status varchar(24) NOT NULL,
				started_at_utc datetime NOT NULL,
				finished_at_utc datetime NULL,
				max_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				cursor_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				scanned_count bigint(20) unsigned NOT NULL DEFAULT 0,
				skipped_count bigint(20) unsigned NOT NULL DEFAULT 0,
				link_count bigint(20) unsigned NOT NULL DEFAULT 0,
				error varchar(80) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY run_id (run_id),
				KEY status (status)
			) ENGINE=InnoDB $collate",
				"CREATE TABLE {$tables['links']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id char(36) NOT NULL,
				source_post_id bigint(20) unsigned NOT NULL,
				source_post_modified_gmt datetime NOT NULL,
				source_title_at_scan text NOT NULL,
				source_url_at_scan text NOT NULL,
				href text NOT NULL,
				normalized_url_sha256 char(64) NOT NULL,
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				hint_ids_json text NOT NULL,
				match_state varchar(24) NOT NULL,
				occurrence_count int(10) unsigned NOT NULL DEFAULT 1,
				source_kind varchar(8) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source (run_id,source_post_id,normalized_url_sha256),
				KEY source_post_id (source_post_id),
				KEY attachment_id (attachment_id),
				KEY run_state (run_id,match_state,id)
			) ENGINE=InnoDB $collate",
			)
		);
		if ( ! self::transactional() ) {
			wp_die( esc_html__( 'Public Document Review requires InnoDB tables.', 'digicyfr-public-document-review' ) );
		}
		update_option( 'dpdr_schema_version', DIGICYFR_PUBLIC_DOCUMENT_REVIEW_VERSION, false );
	}

	/** Require both tables to support rollback and row locking. */
	public static function transactional(): bool {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( 'InnoDB' !== $engine || '' !== $wpdb->last_error ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Keep the current run and previous complete snapshot only.
	 *
	 * @param string $current_id current id input.
	 */
	public static function prune( string $current_id ): void {
		global $wpdb;
		$keep = array_values( array_unique( array_filter( array( $current_id, (string) get_option( 'dpdr_active_complete_run_id', '' ) ) ) ) );
		if ( array() === $keep ) {
			return;
		}
		$tables = self::tables();
		foreach ( array( 'links', 'runs' ) as $key ) {
			$table = $tables[ $key ];
			if ( 1 === count( $keep ) ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE run_id <> %s', $table, $keep[0] ) );
			} else {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE run_id NOT IN (%s,%s)', $table, $keep[0], $keep[1] ) );
			}
		}
	}
}
