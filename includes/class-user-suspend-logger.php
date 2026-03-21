<?php
/**
 * Audit logger.
 *
 * Keeps a rolling log of suspend/unsuspend actions in a site option,
 * capped at 500 entries.
 *
 * @package User_Suspend
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records and retrieves suspension audit log entries.
 */
class User_Suspend_Logger {

	/**
	 * Option key used to store the audit log.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'user_suspend_audit_log';

	/**
	 * Maximum number of log entries to retain.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 500;

	/**
	 * Add a log entry.
	 *
	 * @param int    $user_id      Affected user ID.
	 * @param string $action       Action taken (e.g. 'suspended', 'unsuspended').
	 * @param string $reason       Optional reason.
	 * @param int    $performed_by Admin user ID; 0 for system/cron.
	 */
	public static function log( $user_id, $action, $reason = '', $performed_by = 0 ) {
		$entry = array(
			'timestamp'    => current_time( 'mysql' ),
			'user_id'      => absint( $user_id ),
			'action'       => sanitize_key( $action ),
			'reason'       => sanitize_textarea_field( $reason ),
			'performed_by' => absint( $performed_by ),
		);

		$log = self::get_log();
		array_unshift( $log, $entry );

		/**
		 * Filters the maximum number of audit log entries to retain.
		 *
		 * @since 2.0.4
		 * @param int $max_entries Maximum entries. Default 500.
		 */
		$max_entries = (int) apply_filters( 'user_suspend_max_log_entries', self::MAX_ENTRIES );
		if ( count( $log ) > $max_entries ) {
			$log = array_slice( $log, 0, $max_entries );
		}

		update_option( self::OPTION_KEY, $log, false );
	}

	/**
	 * Get the full log, newest first.
	 *
	 * @return array
	 */
	public static function get_log() {
		$log = get_option( self::OPTION_KEY, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Get log entries for a specific user.
	 *
	 * @param int $user_id User ID to filter by.
	 * @return array
	 */
	public static function get_log_for_user( $user_id ) {
		$user_id = absint( $user_id );
		return array_filter(
			self::get_log(),
			function ( $entry ) use ( $user_id ) {
				return isset( $entry['user_id'] ) && (int) $entry['user_id'] === $user_id;
			}
		);
	}

	/**
	 * Wipe the audit log.
	 */
	public static function clear_log() {
		update_option( self::OPTION_KEY, array(), false );
	}
}
