<?php
/** Exercise the installed exact ZIP in a disposable WordPress site. */

declare( strict_types=1 );

$port = getenv( 'DIGICYFR_TEST_PORT' );
$_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_PORT'] = $port;
$_SERVER['HTTPS'] = 'off';

require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
}

function source( string $title, string $content, string $status = 'publish', string $password = '' ): int {
	$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => $status, 'post_title' => $title, 'post_content' => $content, 'post_password' => $password ), true );
	check( is_int( $id ) && $id > 0, 'created source ' . $title );
	return $id;
}

function file_block( int $id, string $url, bool $two = true ): string {
	$attrs = wp_json_encode( array( 'id' => $id, 'href' => $url ) );
	return '<!-- wp:file ' . $attrs . ' --><div class="wp-block-file"><a href="' . esc_attr( $url ) . '">Text</a>' . ( $two ? '<a href="' . esc_attr( $url ) . '" class="wp-block-file__button">Download</a>' : '' ) . '</div><!-- /wp:file -->';
}

function finish( string $id, int $limit = 10 ): array {
	for ( $n = 0; $n < $limit; ++$n ) {
		$run = DPDR_Run::continue_scan( $id );
		check( is_array( $run ), 'scan batch succeeds' );
		if ( ! in_array( $run['status'], array( 'queued', 'running' ), true ) ) {
			return array( $run, $n + 1 );
		}
	}
	check( false, 'scan completes within bound' );
	return array();
}

$plugin = 'digicyfr-public-document-review/digicyfr-public-document-review.php';
check( is_plugin_active( $plugin ) && '0.1.1' === DIGICYFR_PUBLIC_DOCUMENT_REVIEW_VERSION, 'exact ZIP active/version' );
check( DPDR_Storage::transactional(), 'two InnoDB tables installed' );
$admin = get_user_by( 'login', 'digicyfr_test_admin' );
check( $admin instanceof WP_User, 'test admin' );
wp_set_current_user( $admin->ID );
global $wpdb;
$links_table = DPDR_Storage::tables()['links'];
check( false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN source_post_content_sha256', $links_table ) ), 'simulate previous local schema' );
update_option( 'dpdr_schema_version', '0.1.0', false );
DPDR_Storage::maybe_upgrade();
check( '0.1.0-2' === get_option( 'dpdr_schema_version' ) && 'source_post_content_sha256' === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $links_table, 'source_post_content_sha256' ) ), 'old schema gains content fingerprint column' );
check( load_textdomain( 'digicyfr-public-document-review', WP_PLUGIN_DIR . '/digicyfr-public-document-review/languages/digicyfr-public-document-review-pl_PL.mo' ), 'bundled Polish catalog' );
check( 'Przegląd dokumentów publicznych' === __( 'Public Document Review', 'digicyfr-public-document-review' ), 'Polish runtime label' );
unload_textdomain( 'digicyfr-public-document-review' );
check( DPDR_Review::valid_date( '2027-03-28' ) && ! DPDR_Review::valid_date( '2027-02-30' ) && ! DPDR_Review::valid_date( '2027-3-28' ), 'strict date validation' );
update_option( 'timezone_string', 'Europe/Warsaw' );
foreach ( array( '2027-03-28 00:30:00', '2027-03-28 03:30:00' ) as $local_clock ) {
	$today = ( new DateTimeImmutable( $local_clock, wp_timezone() ) )->format( 'Y-m-d' );
	check( 'overdue' === DPDR_Review::status( '2027-03-27', $today ) && 'due' === DPDR_Review::status( '2027-03-28', $today ) && 'upcoming' === DPDR_Review::status( '2027-03-29', $today ), 'Warsaw spring DST local-day statuses' );
}
check( "'=1" === DPDR_Admin::csv_safe( '=1' ) && "'  +1" === DPDR_Admin::csv_safe( '  +1' ) && "'\t@x" === DPDR_Admin::csv_safe( "\t@x" ), 'CSV formula guard' );
check( DPDR_Scanner::pdf_path( 'https://x.example/A.PDF?download=1' ), 'case-insensitive PDF path with query' );
check( 'https://example.org/A.PDF?download=1' === DPDR_Scanner::normalize( 'HTTPS://EXAMPLE.ORG/A.PDF?download=1#page=2' ), 'normalization preserves case and query, removes fragment' );
check( '' === DPDR_Scanner::normalize( 'javascript:alert(1)' ) && '' === DPDR_Scanner::normalize( 'https://user:pass@example.org/a.pdf' ), 'unsafe schemes and credentials rejected' );

$attachment = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Policy PDF', 'post_mime_type' => 'application/pdf', 'guid' => home_url( '/policy.pdf' ) ), true );
$other_attachment = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Second PDF', 'post_mime_type' => 'application/pdf', 'guid' => home_url( '/second.pdf' ) ), true );
check( is_int( $attachment ) && is_int( $other_attachment ), 'PDF attachments created' );
update_attached_file( $attachment, '2026/11/policy.pdf' );
update_attached_file( $other_attachment, '2026/11/second.pdf' );
$pdf_url = wp_get_attachment_url( $attachment );
$other_url = wp_get_attachment_url( $other_attachment );
check( is_string( $pdf_url ) && DPDR_Scanner::valid_pdf_id( $attachment ), 'PDF URL without requiring local file' );
$main = source( '=HYPERLINK("https://evil.invalid")', file_block( $attachment, $pdf_url ) );
$main_scan = DPDR_Scanner::scan_post( get_post( $main ) );
check( '' === $main_scan['skip'] && 1 === count( $main_scan['rows'] ) && 2 === $main_scan['rows'][0]['occurrence_count'] && 'file' === $main_scan['rows'][0]['source_kind'] && 'matched' === $main_scan['rows'][0]['match_state'], 'two File anchors one matched row and occurrence 2' );
$filter_offload = static function ( $url, $id ) use ( $attachment ) { return (int) $id === $attachment ? 'https://cdn.example.test/doc/no-extension' : $url; };
add_filter( 'wp_get_attachment_url', $filter_offload, 10, 2 );
$offload_post = get_post( $main );
$offload_post->post_content = '<!-- wp:file ' . wp_json_encode( array( 'id' => $attachment, 'href' => 'https://cdn.example.test/doc/no-extension' ) ) . ' /-->';
$offload_scan = DPDR_Scanner::scan_post( $offload_post );
remove_filter( 'wp_get_attachment_url', $filter_offload, 10 );
check( '' === $offload_scan['skip'] && 1 === count( $offload_scan['rows'] ) && 'matched' === $offload_scan['rows'][0]['match_state'], 'offloaded File ID can match filtered URL without suffix or local file' );
$long_attr = 'https://example.org/' . str_repeat( 'a', 2040 ) . '.pdf';
$long_post = get_post( $main );
$long_post->post_content = '<!-- wp:file ' . wp_json_encode( array( 'id' => $attachment, 'href' => $long_attr ) ) . ' /-->';
check( 'url_too_long' === DPDR_Scanner::scan_post( $long_post )['skip'], 'attr-only long File href explicitly incomplete' );
$expanded_post = get_post( $main );
$expanded_post->post_content = '<a href="/' . str_repeat( 'a', 2040 ) . '.pdf">Long</a>';
check( 'url_too_long' === DPDR_Scanner::scan_post( $expanded_post )['skip'], 'root-relative URL expansion over cap is incomplete' );
$too_many_post = get_post( $main );
$too_many_post->post_content = str_repeat( '<a href="https://example.org/a.pdf">PDF</a>', 301 );
check( 'too_many_links' === DPDR_Scanner::scan_post( $too_many_post )['skip'], '301 saved link occurrences mark source incomplete' );

$baseline = DPDR_Run::start();
check( is_array( $baseline ), 'first scan starts' );
list( $baseline_done ) = finish( $baseline['run_id'] );
check( 'complete' === $baseline_done['status'] && $baseline['run_id'] === DPDR_Run::pointers()['active_complete'], 'first clean complete snapshot' );
$tables = DPDR_Storage::tables();
global $wpdb;
$main_rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['links']} WHERE run_id = %s AND source_post_id = %d", $baseline['run_id'], $main ), ARRAY_A );
check( 1 === count( $main_rows ) && 2 === (int) $main_rows[0]['occurrence_count'], 'one persisted source row for two anchors' );
update_option( 'dpdr_active_complete_run_id', '', false );
check( $baseline['run_id'] === DPDR_Run::pointers()['active_complete'], 'post-commit pointer recovery' );

$editor_id = wp_create_user( 'dpdr_editor', 'test-password-only', 'editor@example.invalid' );
$subscriber_id = wp_create_user( 'dpdr_subscriber', 'test-password-only', 'sub@example.invalid' );
check( is_int( $editor_id ) && is_int( $subscriber_id ), 'users created' );
( new WP_User( $editor_id ) )->set_role( 'editor' );
( new WP_User( $subscriber_id ) )->set_role( 'subscriber' );
$future = current_datetime()->modify( '+2 days' )->format( 'Y-m-d' );
$past = current_datetime()->modify( '-1 day' )->format( 'Y-m-d' );
$old_attachment_modified = get_post( $attachment )->post_modified_gmt;
$saved = DPDR_Review::save( $attachment, array( 'owner_id' => (string) $editor_id, 'due' => $past, 'note' => 'Sprawdź C:\\folder', 'review_action' => 'save' ) );
check( is_array( $saved ) && 'Sprawdź C:\\folder' === $saved['note'] && $past === $saved['due'] && $editor_id === $saved['owner_id'], 'owner/date/note saved with literal backslash' );
check( $old_attachment_modified === get_post( $attachment )->post_modified_gmt, 'review does not modify attachment post timestamp' );
check( is_wp_error( DPDR_Review::save( $attachment, array( 'owner_id' => (string) $subscriber_id, 'due' => $future, 'note' => '', 'review_action' => 'save' ) ) ), 'ineligible owner rejected' );
check( is_wp_error( DPDR_Review::save( $attachment, array( 'owner_id' => '0', 'due' => '2027-02-30', 'note' => '', 'review_action' => 'save' ) ) ), 'invalid date rejected' );
check( is_wp_error( DPDR_Review::save( $attachment, array( 'owner_id' => '0', 'due' => $past, 'note' => str_repeat( 'ą', 501 ), 'review_action' => 'save' ) ) ), '501-character note rejected' );
check( is_wp_error( DPDR_Review::save( $attachment, array( 'owner_id' => '0', 'due' => $past, 'note' => '', 'review_action' => 'mark' ) ) ), 'mark requires future date or explicit unschedule' );
$marked = DPDR_Review::save( $attachment, array( 'owner_id' => (string) $editor_id, 'due' => $future, 'note' => 'Aktualne', 'review_action' => 'mark' ) );
check( is_array( $marked ) && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $marked['reviewed_at_utc'] ) && $admin->ID === $marked['reviewed_by'], 'mark reviewed UTC stamp and actor' );
wp_set_current_user( $editor_id );
check( DPDR_Review::can_edit( $attachment ) && is_array( DPDR_Review::save( $attachment, array( 'owner_id' => (string) $editor_id, 'due' => $future, 'note' => 'Edytor', 'review_action' => 'save' ) ) ), 'editor can save matched PDF review' );
check( array( $attachment ) === DPDR_Admin::group_page( $baseline['run_id'], 'upcoming', 1 )['ids'], 'editor sees authorized matched PDF' );
check( is_wp_error( DPDR_Run::start() ), 'editor cannot start site-wide scan' );
wp_set_current_user( $subscriber_id );
check( is_wp_error( DPDR_Review::save( $attachment, array( 'owner_id' => '0', 'due' => $future, 'note' => 'forged', 'review_action' => 'save' ) ) ), 'noneditor cannot change attachment meta' );
check( false === DPDR_Admin::stream_csv( $baseline['run_id'], fopen( 'php://temp', 'w+' ) ), 'nonadmin cannot export' );
wp_set_current_user( $admin->ID );

$stream = fopen( 'php://temp', 'w+' );
check( DPDR_Admin::stream_csv( $baseline['run_id'], $stream ), 'complete snapshot exports' );
rewind( $stream );
$header = fgetcsv( $stream, 0, ',', '"', '' );
$data = fgetcsv( $stream, 0, ',', '"', '' );
check( 13 === count( $header ) && str_starts_with( $data[2], "'=HYPERLINK(" ) && 'matched' === $data[6] && $future === $data[9], 'CSV header, formula guard and review fields' );
fclose( $stream );

$paragraph = source( 'Paragraph', '<!-- wp:paragraph --><p><a href="' . esc_attr( $pdf_url ) . '#page=2">PDF</a></p><!-- /wp:paragraph -->' );
$classic = source( 'Classic', '<a href="' . esc_attr( $pdf_url ) . '">Classic PDF</a>' );
$nested = source( 'Nested', '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p><a href="' . esc_attr( $pdf_url ) . '">Nested</a></p><!-- /wp:paragraph --></div><!-- /wp:group -->' );
$query_pdf = source( 'Query PDF', '<a href="' . esc_attr( str_replace( '.pdf', '.PDF?download=1', $pdf_url ) ) . '">Query</a>' );
$mismatch = source( 'Mismatch', file_block( $attachment, 'https://external.example/mismatch.pdf', false ) );
$attachment_page = source( 'Attachment page', file_block( $attachment, get_attachment_link( $attachment ), false ) );
$ambiguous = source( 'Ambiguous', file_block( $attachment, $other_url, false ) );
$external = source( 'External', '<a href="https://example.org/external.pdf">External PDF</a>' );
$removed_attachment = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Removed', 'post_mime_type' => 'application/pdf', 'guid' => home_url( '/gone.pdf' ) ), true );
update_attached_file( $removed_attachment, '2026/11/gone.pdf' );
$removed_url = wp_get_attachment_url( $removed_attachment );
$removed = source( 'Removed', '<a href="' . esc_attr( $removed_url ) . '">Gone</a>' );
wp_delete_attachment( $removed_attachment, true );
$draft = source( 'Draft', '<a href="' . esc_attr( $pdf_url ) . '">Draft</a>', 'draft' );
$private = source( 'Private', '<a href="' . esc_attr( $pdf_url ) . '">Private</a>', 'private' );
$password = source( 'Password', '<a href="' . esc_attr( $pdf_url ) . '">Protected</a>', 'publish', 'secret' );
for ( $n = 0; $n < 26; ++$n ) {
	source( 'Batch ' . $n, '<p>No PDF here.</p>' );
}
$second = DPDR_Run::start();
check( is_array( $second ) && $baseline['run_id'] === DPDR_Run::pointers()['active_complete'], 'new scan preserves prior complete snapshot' );
$first = DPDR_Run::continue_scan( $second['run_id'] );
check( is_array( $first ) && 'running' === $first['status'] && (int) $first['scanned_count'] <= 25, 'first of two batches remains running' );
$cursor = (int) $first['cursor_post_id'];
check( $cursor === (int) DPDR_Run::get( $second['run_id'] )['cursor_post_id'], 'interrupted cursor persisted' );
$not_complete = fopen( 'php://temp', 'w+' );
check( false === DPDR_Admin::stream_csv( $second['run_id'], $not_complete ), 'in-progress run cannot export' );
fclose( $not_complete );
list( $second_done, $remaining_batches ) = finish( $second['run_id'] );
check( 'complete' === $second_done['status'] && $remaining_batches >= 1 && $cursor < (int) $second_done['cursor_post_id'], 'resumed run completes without mixing IDs' );
check( $second['run_id'] === DPDR_Run::pointers()['active_complete'], 'complete pointer switches only after clean finish' );
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT source_post_id, match_state, occurrence_count, source_kind, attachment_id, hint_ids_json FROM {$tables['links']} WHERE run_id = %s", $second['run_id'] ), ARRAY_A );
$by_source = array();
foreach ( $rows as $row ) { $by_source[ $row['source_post_id'] ] = $row; }
check( 'matched' === $by_source[ $paragraph ]['match_state'] && 'matched' === $by_source[ $classic ]['match_state'] && 'matched' === $by_source[ $nested ]['match_state'], 'paragraph/classic/nested saved links matched' );
check( 'unmatched_local' === $by_source[ $query_pdf ]['match_state'] && 'id_url_mismatch' === $by_source[ $mismatch ]['match_state'] && 'attachment_page' === $by_source[ $attachment_page ]['match_state'], 'query mismatch, File ID mismatch and attachment-page states' );
check( 'ambiguous' === $by_source[ $ambiguous ]['match_state'] && 'unmatched_external' === $by_source[ $external ]['match_state'] && 'unmatched_local' === $by_source[ $removed ]['match_state'], 'ambiguous/external/removed attachment states' );
check( ! isset( $by_source[ $draft ] ) && ! isset( $by_source[ $private ] ) && ! isset( $by_source[ $password ] ), 'draft/private/password-protected sources excluded' );
check( 2 === (int) $by_source[ $main ]['occurrence_count'] && 0 === (int) $by_source[ $mismatch ]['attachment_id'], 'dedup and uncertain row not editable' );

// A report read error is never presented as zero matched PDFs.
$break_report = static function ( string $sql ) use ( $tables ): string { return str_contains( $sql, 'SELECT * FROM `' . $tables['runs'] . '` WHERE run_id =' ) ? 'SELECT nonexistent_column FROM wp_dpdr_runs' : $sql; };
$old_suppress = $wpdb->suppress_errors( true );
add_filter( 'query', $break_report );
check( is_wp_error( DPDR_Admin::group_page( $second['run_id'], 'all', 1 ) ), 'report run-read SQL failure surfaces error' );
remove_filter( 'query', $break_report );
$wpdb->suppress_errors( $old_suppress );

$stored_paragraph = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['links']} WHERE run_id = %s AND source_post_id = %d", $second['run_id'], $paragraph ), ARRAY_A );
check( hash( 'sha256', get_post( $paragraph )->post_content ) === $stored_paragraph['source_post_content_sha256'], 'stored source content fingerprint' );
$before_edit = get_post( $paragraph );
wp_update_post( array( 'ID' => $paragraph, 'post_content' => '<p>Edited</p>' ) );
// Simulate two edits within WordPress's one-second timestamp resolution.
check( false !== $wpdb->update( $wpdb->posts, array( 'post_modified' => $before_edit->post_modified, 'post_modified_gmt' => $stored_paragraph['source_post_modified_gmt'] ), array( 'ID' => $paragraph ) ), 'same-second timestamp fixture' );
clean_post_cache( $paragraph );
check( $stored_paragraph['source_post_modified_gmt'] === get_post( $paragraph )->post_modified_gmt && '<p>Edited</p>' === get_post( $paragraph )->post_content, 'source changed while timestamp stayed equal' );
check( 'stale' === DPDR_Admin::source_status( $stored_paragraph ), 'edited source marked stale until rescan' );
wp_delete_post( $paragraph, true );
check( 'deleted' === DPDR_Admin::source_status( $stored_paragraph ), 'deleted source marked deleted' );

$oversize = source( 'Oversize', str_repeat( 'X', 1048577 ) );
$incomplete = DPDR_Run::start();
check( is_array( $incomplete ), 'new scan starts after complete' );
list( $incomplete_done ) = finish( $incomplete['run_id'] );
check( 'incomplete' === $incomplete_done['status'] && (int) $incomplete_done['skipped_count'] >= 1 && $second['run_id'] === DPDR_Run::pointers()['active_complete'], 'oversize post keeps previous complete snapshot' );
$partial_csv = fopen( 'php://temp', 'w+' );
check( false === DPDR_Admin::stream_csv( $incomplete['run_id'], $partial_csv ), 'incomplete rows cannot export' );
fclose( $partial_csv );
$cancel_test = DPDR_Run::start();
check( is_array( $cancel_test ), 'cancel test run starts' );
$break_final_read = static function ( string $sql ) use ( $tables ): string { return str_contains( $sql, 'SELECT * FROM `' . $tables['runs'] . '` WHERE run_id =' ) && ! str_contains( $sql, 'FOR UPDATE' ) ? 'SELECT nonexistent_column FROM wp_dpdr_runs' : $sql; };
$old_suppress = $wpdb->suppress_errors( true );
add_filter( 'query', $break_final_read );
check( is_wp_error( DPDR_Run::cancel( $cancel_test['run_id'] ) ), 'post-commit read failure returns error instead of fatal' );
remove_filter( 'query', $break_final_read );
$wpdb->suppress_errors( $old_suppress );
check( 'cancelled' === DPDR_Run::get( $cancel_test['run_id'] )['status'], 'cancel commit survives final read failure' );
$batch_failure = DPDR_Run::start();
check( is_array( $batch_failure ), 'batch SQL failure run starts' );
$break_batch = static function ( string $sql ): string { return str_contains( $sql, 'SELECT ID, OCTET_LENGTH(post_content) AS bytes FROM' ) ? 'SELECT nonexistent_column FROM wp_posts' : $sql; };
$old_suppress = $wpdb->suppress_errors( true );
add_filter( 'query', $break_batch );
check( is_wp_error( DPDR_Run::continue_scan( $batch_failure['run_id'] ) ), 'batch get_results SQL failure returns an error' );
remove_filter( 'query', $break_batch );
$wpdb->suppress_errors( $old_suppress );
check( 'incomplete' === DPDR_Run::get( $batch_failure['run_id'] )['status'] && $second['run_id'] === DPDR_Run::pointers()['active_complete'], 'batch SQL failure cannot replace complete snapshot' );
$remaining_failure = DPDR_Run::start();
check( is_array( $remaining_failure ), 'remaining SQL failure run starts' );
$break_remaining = static function ( string $sql ): string { return str_contains( $sql, 'SELECT ID FROM' ) && str_contains( $sql, 'ORDER BY ID ASC LIMIT 1' ) ? 'SELECT nonexistent_column FROM wp_posts' : $sql; };
$old_suppress = $wpdb->suppress_errors( true );
add_filter( 'query', $break_remaining );
check( is_wp_error( DPDR_Run::continue_scan( $remaining_failure['run_id'] ) ), 'remaining get_var SQL failure returns an error' );
remove_filter( 'query', $break_remaining );
$wpdb->suppress_errors( $old_suppress );
check( 'incomplete' === DPDR_Run::get( $remaining_failure['run_id'] )['status'] && $second['run_id'] === DPDR_Run::pointers()['active_complete'], 'remaining SQL failure cannot replace complete snapshot' );
$cap_run = DPDR_Run::start();
check( is_array( $cap_run ), 'row-cap test run starts' );
$wpdb->update( $tables['runs'], array( 'link_count' => DPDR_Run::MAX_STORED_LINKS ), array( 'run_id' => $cap_run['run_id'] ) );
$cap_result = DPDR_Run::continue_scan( $cap_run['run_id'] );
check( is_array( $cap_result ) && 'incomplete' === $cap_result['status'] && 'row_cap' === $cap_result['error'] && (int) $cap_result['cursor_post_id'] < (int) $cap_result['max_post_id'] && $second['run_id'] === DPDR_Run::pointers()['active_complete'], '50,000-link cap stops with unscanned source IDs and preserves prior complete report' );
wp_delete_user( $editor_id, $admin->ID );
check( 'Former user #' . $editor_id === DPDR_Review::owner_name( $editor_id ), 'deleted owner displayed by stable ID' );
deactivate_plugins( $plugin );
check( DPDR_Storage::transactional() && '' !== get_post_meta( $attachment, '_dpdr_due', true ), 'deactivation retains inventory and review state' );
activate_plugin( $plugin );
check( DPDR_Storage::transactional(), 'reactivation retains InnoDB tables' );
define( 'WP_UNINSTALL_PLUGIN', $plugin );
require WP_PLUGIN_DIR . '/digicyfr-public-document-review/uninstall.php';
check( ! DPDR_Storage::transactional() && false === get_option( 'dpdr_schema_version', false ) && '' === get_post_meta( $attachment, '_dpdr_due', true ), 'uninstall drops tables/options/protected review meta' );
printf( "PASS: exact ZIP document review integration (WP %s, PHP %s, 26-post two-batch fixture)\n", get_bloginfo( 'version' ), PHP_VERSION );
