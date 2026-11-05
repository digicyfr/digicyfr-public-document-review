<?php
/**
 * Remove plugin-owned inventory and review metadata on uninstall only.
 *
 * @package Digicyfr_Public_Document_Review
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
foreach ( array( 'dpdr_links', 'dpdr_runs' ) as $dpdr_suffix ) {
	$dpdr_table = $wpdb->prefix . $dpdr_suffix;
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $dpdr_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit uninstall of fixed plugin-owned tables.
}
foreach ( array( 'dpdr_schema_version', 'dpdr_scan_state', 'dpdr_active_complete_run_id', 'dpdr_start_lock' ) as $dpdr_option ) {
	delete_option( $dpdr_option );
}
foreach ( array( '_dpdr_owner_id', '_dpdr_due', '_dpdr_note', '_dpdr_reviewed_at_utc', '_dpdr_reviewed_by' ) as $dpdr_key ) {
	delete_post_meta_by_key( $dpdr_key );
}
