<?php
/**
 * Private attachment review metadata and object-level permission checks.
 *
 * @package Digicyfr_Public_Document_Review
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Current editorial state, with no automatic reminders or file edits. */
final class DPDR_Review {
	// Matching reads the plugin's private inventory table without a useful cache lifetime.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	private const KEYS = array( '_dpdr_owner_id', '_dpdr_due', '_dpdr_note', '_dpdr_reviewed_at_utc', '_dpdr_reviewed_by' );

	/**
	 * Strict site-local date or explicit empty value.
	 *
	 * @param string $date date input.
	 */
	public static function valid_date( string $date ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		return $parsed instanceof DateTimeImmutable && $parsed->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Classify against a site-local calendar day.
	 *
	 * @param string  $due due input.
	 * @param ?string $today today input.
	 */
	public static function status( string $due, ?string $today = null ): string {
		$today = $today ?? current_datetime()->format( 'Y-m-d' );
		if ( '' === $due ) {
			return 'unscheduled';
		}
		if ( $due < $today ) {
			return 'overdue';
		}
		return $due === $today ? 'due' : 'upcoming';
	}

	/**
	 * Resolve a deleted assignee without discarding its ID.
	 *
	 * @param int $id id input.
	 */
	public static function owner_name( int $id ): string {
		if ( $id <= 0 ) {
			return '';
		}
		$user = get_userdata( $id );
		if ( $user ) {
			return $user->display_name;
		}
		/* translators: %d is a former WordPress user ID. */
		return sprintf( __( 'Former user #%d', 'digicyfr-public-document-review' ), $id );
	}

	/**
	 * Return protected current state for an attachment.
	 *
	 * @param int $id id input.
	 */
	public static function get( int $id ): array {
		return array(
			'owner_id'        => (int) get_post_meta( $id, '_dpdr_owner_id', true ),
			'due'             => (string) get_post_meta( $id, '_dpdr_due', true ),
			'note'            => (string) get_post_meta( $id, '_dpdr_note', true ),
			'reviewed_at_utc' => (string) get_post_meta( $id, '_dpdr_reviewed_at_utc', true ),
			'reviewed_by'     => (int) get_post_meta( $id, '_dpdr_reviewed_by', true ),
		);
	}

	/**
	 * A review target must be proven in the preserved complete inventory.
	 *
	 * @param int $id id input.
	 */
	public static function is_matched( int $id ): bool {
		global $wpdb;
		$run_id = (string) get_option( 'dpdr_active_complete_run_id', '' );
		if ( '' === $run_id || ! DPDR_Scanner::valid_pdf_id( $id ) ) {
			return false;
		}
		$table = DPDR_Storage::tables()['links'];
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE run_id = %s AND attachment_id = %d AND match_state = 'matched' LIMIT 1", $table, $run_id, $id ) );
		return '' === $wpdb->last_error && null !== $found;
	}

	/**
	 * Check both primitive upload and mapped object permission.
	 *
	 * @param int $id id input.
	 */
	public static function can_edit( int $id ): bool {
		return current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * Save owner, strict local due date, note, and optional reviewed stamp.
	 *
	 * @param int   $id id input.
	 * @param array $input input input.
	 */
	public static function save( int $id, array $input ): array|WP_Error {
		if ( ! self::can_edit( $id ) || ! self::is_matched( $id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot review this PDF attachment.', 'digicyfr-public-document-review' ) );
		}
		$action = is_string( $input['review_action'] ?? null ) ? $input['review_action'] : 'save';
		if ( ! in_array( $action, array( 'save', 'mark' ), true ) ) {
			return new WP_Error( 'invalid_action', __( 'Unknown review action.', 'digicyfr-public-document-review' ) );
		}
		$owner = filter_var( $input['owner_id'] ?? '0', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $owner ) {
			return new WP_Error( 'invalid_owner', __( 'Enter a valid owner user ID.', 'digicyfr-public-document-review' ) );
		}
		if ( $owner > 0 ) {
			$user = get_userdata( $owner );
			if ( ! $user || ! user_can( $user, 'upload_files' ) || ! user_can( $user, 'edit_post', $id ) ) {
				return new WP_Error( 'invalid_owner', __( 'The selected owner cannot edit this attachment.', 'digicyfr-public-document-review' ) );
			}
		}
		$due        = is_string( $input['due'] ?? null ) ? trim( $input['due'] ) : '';
		$unschedule = '1' === (string) ( $input['unschedule'] ?? '0' );
		if ( $unschedule && '' !== $due ) {
			return new WP_Error( 'date_conflict', __( 'Clear the date before selecting Unscheduled.', 'digicyfr-public-document-review' ) );
		}
		if ( '' !== $due && ! self::valid_date( $due ) ) {
			return new WP_Error( 'invalid_date', __( 'Enter a real date in YYYY-MM-DD format.', 'digicyfr-public-document-review' ) );
		}
		if ( 'mark' === $action && ! $unschedule && ( '' === $due || $due <= current_datetime()->format( 'Y-m-d' ) ) ) {
			return new WP_Error( 'future_due', __( 'After marking reviewed, choose a future date or explicitly select Unscheduled.', 'digicyfr-public-document-review' ) );
		}
		$raw_note = $input['note'] ?? '';
		if ( ! is_string( $raw_note ) || false === preg_match_all( '/./us', $raw_note, $characters ) || count( $characters[0] ) > 500 ) {
			return new WP_Error( 'note_too_long', __( 'The note must be plain text of at most 500 characters.', 'digicyfr-public-document-review' ) );
		}
		$note   = sanitize_textarea_field( $raw_note );
		$old    = array();
		$values = array(
			'_dpdr_owner_id' => (string) $owner,
			'_dpdr_due'      => $unschedule ? '' : $due,
			'_dpdr_note'     => $note,
		);
		if ( 'mark' === $action ) {
			$values['_dpdr_reviewed_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
			$values['_dpdr_reviewed_by']     = (string) get_current_user_id();
		}
		foreach ( self::KEYS as $key ) {
			$old[ $key ] = array(
				'exists' => metadata_exists( 'post', $id, $key ),
				'value'  => (string) get_post_meta( $id, $key, true ),
			);
		}
		$changed = array();
		foreach ( $values as $key => $value ) {
			if ( $old[ $key ]['exists'] && $old[ $key ]['value'] === $value ) {
				continue;
			}
			if ( false === update_post_meta( $id, $key, wp_slash( $value ) ) ) {
				foreach ( array_reverse( $changed ) as $restore ) {
					if ( $old[ $restore ]['exists'] ) {
						update_post_meta( $id, $restore, wp_slash( $old[ $restore ]['value'] ) );
					} else {
						delete_post_meta( $id, $restore );
					}
				}
				return new WP_Error( 'write_failed', __( 'The review could not be saved; previous values were restored.', 'digicyfr-public-document-review' ) );
			}
			$changed[] = $key;
		}
		return self::get( $id );
	}
}
