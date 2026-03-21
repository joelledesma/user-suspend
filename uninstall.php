<?php
/**
 * Uninstall routine.
 *
 * Cleans up all plugin data when the plugin is deleted.
 * Only removes meta for users who actually have suspension data,
 * avoiding any risk of affecting unrelated meta keys.
 *
 * @package User_Suspend
 * @since   2.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$user_suspend_meta_keys = array(
	'us_suspended',
	'us_suspend_reason',
	'us_suspend_expiry',
	'us_suspend_date',
	'us_suspended_by',
);

// Only target users who actually have suspension data to avoid touching
// unrelated user meta that might share a key name with another plugin.
$user_suspend_users = get_users(
	array(
		'meta_key'   => 'us_suspended', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value' => '1',            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		'fields'     => 'ID',
		'number'     => -1,
	)
);

foreach ( $user_suspend_users as $user_suspend_uid ) {
	foreach ( $user_suspend_meta_keys as $user_suspend_key ) {
		delete_user_meta( $user_suspend_uid, $user_suspend_key );
	}
}

delete_option( 'user_suspend_audit_log' );
delete_option( 'user_suspend_migration_version' );
delete_option( 'user_suspend_migration_summary' );

$user_suspend_timestamp = wp_next_scheduled( 'user_suspend_expire_suspensions' );
if ( $user_suspend_timestamp ) {
	wp_unschedule_event( $user_suspend_timestamp, 'user_suspend_expire_suspensions' );
}
