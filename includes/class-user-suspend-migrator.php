<?php
/**
 * Data migration from v1.0.
 *
 * @package User_Suspend
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time migration of ban data from the original v1.0 plugin.
 *
 * The old plugin stored bans via update_user_option(), which prefixes the
 * meta key with the table prefix (e.g. wp_bu_banned). This class reads
 * those records and writes them to the new meta keys used in v2.0.
 *
 * Safe to run multiple times — skips users that are already migrated.
 */
class User_Suspend_Migrator {

	/**
	 * Option key used to track migration completion.
	 *
	 * @var string
	 */
	const MIGRATION_FLAG = 'user_suspend_migration_version';

	/**
	 * The version number that marks the migration as complete.
	 *
	 * @var string
	 */
	const CURRENT_MIGRATION_VERSION = '2.0.0';

	/**
	 * Legacy meta key suffix used by the v1.0 plugin.
	 *
	 * @var string
	 */
	const LEGACY_META_KEY_SUFFIX = 'bu_banned';

	/**
	 * Run the migration once, then mark it done.
	 */
	public static function maybe_migrate() {
		$completed = get_option( self::MIGRATION_FLAG, '0' );

		if ( version_compare( $completed, self::CURRENT_MIGRATION_VERSION, '>=' ) ) {
			return;
		}

		self::migrate_v1_to_v2();

		update_option( self::MIGRATION_FLAG, self::CURRENT_MIGRATION_VERSION, false );
	}

	/**
	 * Copy v1.0 ban records to the v2.0 meta keys.
	 */
	private static function migrate_v1_to_v2() {
		global $wpdb;

		$legacy_key = $wpdb->prefix . self::LEGACY_META_KEY_SUFFIX;
		$cache_key  = 'us_legacy_' . md5( $legacy_key );

		$legacy_suspended = wp_cache_get( $cache_key, User_Suspend_Core::CACHE_GROUP );

		if ( false === $legacy_suspended ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$legacy_suspended = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, meta_value
					 FROM {$wpdb->usermeta}
					 WHERE meta_key = %s
					   AND meta_value != ''
					   AND meta_value != '0'",
					$legacy_key
				)
			);
			wp_cache_set( $cache_key, $legacy_suspended, User_Suspend_Core::CACHE_GROUP, 300 );
		}

		if ( empty( $legacy_suspended ) ) {
			return;
		}

		$migrated = 0;

		foreach ( $legacy_suspended as $row ) {
			$user_id = absint( $row->user_id );
			if ( ! $user_id ) {
				continue;
			}

			// Skip users already migrated.
			if ( '' !== get_user_meta( $user_id, User_Suspend_Core::META_SUSPENDED, true ) ) {
				continue;
			}

			update_user_meta( $user_id, User_Suspend_Core::META_SUSPENDED, 1 );
			update_user_meta( $user_id, User_Suspend_Core::META_DATE, current_time( 'mysql' ) );
			update_user_meta( $user_id, User_Suspend_Core::META_BY, 0 );

			User_Suspend_Logger::log( $user_id, 'suspended', 'Migrated from v1.0', 0 );

			++$migrated;
		}

		update_option(
			'user_suspend_migration_summary',
			array(
				'migrated_at'    => current_time( 'mysql' ),
				'users_migrated' => $migrated,
			),
			false
		);
	}

	/**
	 * Return the migration summary, or null if it hasn't run yet.
	 *
	 * @return array|null
	 */
	public static function get_migration_summary() {
		return get_option( 'user_suspend_migration_summary', null );
	}
}
