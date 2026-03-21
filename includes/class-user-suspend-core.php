<?php
/**
 * Core suspension logic.
 *
 * @package User_Suspend
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles suspending and unsuspending users, blocking login,
 * expiring timed suspensions, and plugin lifecycle.
 */
class User_Suspend_Core {

	/**
	 * Meta key: suspension flag.
	 *
	 * @var string
	 */
	const META_SUSPENDED = 'us_suspended';

	/**
	 * Meta key: suspension reason.
	 *
	 * @var string
	 */
	const META_REASON = 'us_suspend_reason';

	/**
	 * Meta key: suspension expiry timestamp.
	 *
	 * @var string
	 */
	const META_EXPIRY = 'us_suspend_expiry';

	/**
	 * Meta key: date the suspension was issued.
	 *
	 * @var string
	 */
	const META_DATE = 'us_suspend_date';

	/**
	 * Meta key: admin who issued the suspension.
	 *
	 * @var string
	 */
	const META_BY = 'us_suspended_by';

	/**
	 * Object cache group.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'user_suspend';

	/**
	 * Singleton instance.
	 *
	 * @var User_Suspend_Core|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since  2.0.0
	 * @return User_Suspend_Core
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Set up hooks.
	 *
	 * @since 2.0.0
	 */
	private function __construct() {
		add_filter( 'wp_authenticate_user', array( $this, 'authenticate_user' ), 10, 2 );
		add_action( 'user_suspend_expire_suspensions', array( $this, 'expire_timed_suspensions' ) );
		add_action( 'update_user_meta', array( $this, 'flush_user_cache' ), 10, 2 );
		add_action( 'deleted_user_meta', array( $this, 'flush_user_cache' ), 10, 2 );

		// Defer migration to admin_init so it doesn't run during the activation
		// redirect — avoids timeouts on slower hosts.
		add_action( 'admin_init', array( $this, 'maybe_run_migration' ) );
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Suspend a user.
	 *
	 * @param int    $user_id      User to suspend.
	 * @param string $reason       Optional reason.
	 * @param int    $expiry       Unix timestamp; 0 for permanent.
	 * @param int    $suspended_by Admin user ID; defaults to current user.
	 * @return bool
	 */
	public static function suspend_user( $user_id, $reason = '', $expiry = 0, $suspended_by = 0 ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return false;
		}

		if ( 0 === $suspended_by ) {
			$suspended_by = get_current_user_id();
		}

		update_user_meta( $user_id, self::META_SUSPENDED, 1 );
		update_user_meta( $user_id, self::META_REASON, sanitize_textarea_field( $reason ) );
		update_user_meta( $user_id, self::META_EXPIRY, absint( $expiry ) );
		update_user_meta( $user_id, self::META_DATE, current_time( 'mysql' ) );
		update_user_meta( $user_id, self::META_BY, absint( $suspended_by ) );

		$sessions = WP_Session_Tokens::get_instance( $user_id );
		$sessions->destroy_all();

		User_Suspend_Logger::log( $user_id, 'suspended', $reason, $suspended_by );
		self::send_suspension_notification( $user_id, $reason );

		/**
		 * Fires after a user is suspended.
		 *
		 * @param int    $user_id      Suspended user ID.
		 * @param string $reason       Suspension reason.
		 * @param int    $expiry       Expiry timestamp (0 = permanent).
		 * @param int    $suspended_by Admin user ID.
		 */
		do_action( 'user_suspend_user_suspended', $user_id, $reason, $expiry, $suspended_by );

		return true;
	}

	/**
	 * Lift a suspension.
	 *
	 * @param int $user_id        User to unsuspend.
	 * @param int $unsuspended_by Admin user ID; defaults to current user.
	 * @return bool
	 */
	public static function unsuspend_user( $user_id, $unsuspended_by = 0 ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! self::is_user_suspended( $user_id ) ) {
			return false;
		}

		if ( 0 === $unsuspended_by ) {
			$unsuspended_by = get_current_user_id();
		}

		delete_user_meta( $user_id, self::META_SUSPENDED );
		delete_user_meta( $user_id, self::META_REASON );
		delete_user_meta( $user_id, self::META_EXPIRY );
		delete_user_meta( $user_id, self::META_DATE );
		delete_user_meta( $user_id, self::META_BY );

		User_Suspend_Logger::log( $user_id, 'unsuspended', '', $unsuspended_by );

		/**
		 * Fires after a suspension is lifted.
		 *
		 * @param int $user_id        Unsuspended user ID.
		 * @param int $unsuspended_by Admin user ID.
		 */
		do_action( 'user_suspend_user_unsuspended', $user_id, $unsuspended_by );

		return true;
	}

	/**
	 * Check whether a user is currently suspended.
	 *
	 * Cached in the object cache for 5 minutes. If a cached result indicates
	 * the user is suspended, the expiry is re-checked in real time to avoid
	 * denying login after a timed suspension has already elapsed.
	 *
	 * @param int $user_id User ID to check.
	 * @return bool
	 */
	public static function is_user_suspended( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}

		$cache_key = 'is_suspended_' . $user_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		// If cached as suspended, still verify the expiry hasn't passed since
		// the cache was set to avoid false positives during the 5-minute window.
		if ( false !== $cached ) {
			if ( $cached ) {
				$expiry = (int) get_user_meta( $user_id, self::META_EXPIRY, true );
				if ( $expiry > 0 && $expiry < time() ) {
					self::unsuspend_user( $user_id, 0 );
					wp_cache_delete( $cache_key, self::CACHE_GROUP );
					return false;
				}
			}
			return (bool) $cached;
		}

		$suspended = (bool) get_user_meta( $user_id, self::META_SUSPENDED, true );

		if ( $suspended ) {
			$expiry = (int) get_user_meta( $user_id, self::META_EXPIRY, true );
			if ( $expiry > 0 && $expiry < time() ) {
				self::unsuspend_user( $user_id, 0 );
				$suspended = false;
			}
		}

		wp_cache_set( $cache_key, (int) $suspended, self::CACHE_GROUP, 300 );

		return $suspended;
	}

	/**
	 * Get suspension details for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array|null Suspension details, or null if not suspended.
	 */
	public static function get_suspension_details( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! self::is_user_suspended( $user_id ) ) {
			return null;
		}

		return array(
			'reason'       => get_user_meta( $user_id, self::META_REASON, true ),
			'expiry'       => (int) get_user_meta( $user_id, self::META_EXPIRY, true ),
			'suspend_date' => get_user_meta( $user_id, self::META_DATE, true ),
			'suspended_by' => (int) get_user_meta( $user_id, self::META_BY, true ),
		);
	}

	// -------------------------------------------------------------------------
	// Hook Callbacks
	// -------------------------------------------------------------------------

	/**
	 * Block suspended users at login.
	 *
	 * @param WP_User|WP_Error $user     User object or WP_Error from a prior filter.
	 * @param string           $password Submitted password (unused; required by filter).
	 * @return WP_User|WP_Error
	 */
	public function authenticate_user( $user, $password = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by wp_authenticate_user filter signature.
		if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) {
			return $user;
		}

		if ( self::is_user_suspended( $user->ID ) ) {
			$details = self::get_suspension_details( $user->ID );
			$reason  = ! empty( $details['reason'] ) ? $details['reason'] : '';

			$message = sprintf(
				/* translators: %s: optional suspension reason followed by a dash separator */
				__( '<strong>Account Suspended</strong>: This account has been disabled. %sPlease contact support if you believe this is an error.', 'user-suspend' ),
				! empty( $reason ) ? esc_html( $reason ) . ' &mdash; ' : ''
			);

			return new WP_Error( 'user_suspend_suspended', $message );
		}

		return $user;
	}

	/**
	 * Expire timed suspensions. Called by the hourly cron event.
	 *
	 * Uses get_users() with a meta_query to avoid direct database queries.
	 * Results are cached for 60 seconds to prevent redundant queries on
	 * sites where the cron fires multiple times in quick succession.
	 */
	public function expire_timed_suspensions() {
		$now       = time();
		$cache_key = 'expire_timed_suspensions_' . $now;

		$user_ids = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false === $user_ids ) {
			$expired_users = get_users(
				array(
					'fields'     => 'ID',
					'number'     => -1,
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => self::META_EXPIRY,
							'value'   => array( 1, $now ),
							'compare' => 'BETWEEN',
							'type'    => 'NUMERIC',
						),
					),
				)
			);

			$user_ids = array_map( 'absint', $expired_users );
			wp_cache_set( $cache_key, $user_ids, self::CACHE_GROUP, 60 );
		}

		foreach ( $user_ids as $user_id ) {
			self::unsuspend_user( $user_id, 0 );
		}
	}

	/**
	 * Clear the cached suspension status when user meta changes.
	 *
	 * @param int $meta_id Unused; passed by the hook.
	 * @param int $user_id User whose meta changed.
	 */
	public function flush_user_cache( $meta_id, $user_id ) {
		$user_id = absint( $user_id );
		wp_cache_delete( 'is_suspended_' . $user_id, self::CACHE_GROUP );
		wp_cache_delete( 'suspension_details_' . $user_id, self::CACHE_GROUP );
	}

	// -------------------------------------------------------------------------
	// Plugin Lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Schedule the expiry cron on activation.
	 */
	public static function activate() {
		if ( ! wp_next_scheduled( 'user_suspend_expire_suspensions' ) ) {
			wp_schedule_event( time(), 'hourly', 'user_suspend_expire_suspensions' );
		}
	}

	/**
	 * Run the v1.0 data migration on the first admin load after activation.
	 */
	public function maybe_run_migration() {
		User_Suspend_Migrator::maybe_migrate();
	}

	/**
	 * Clear the cron event on deactivation.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'user_suspend_expire_suspensions' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'user_suspend_expire_suspensions' );
		}
	}

	// -------------------------------------------------------------------------
	// Private Helpers
	// -------------------------------------------------------------------------

	/**
	 * Email the user when their account is suspended.
	 *
	 * @param int    $user_id User ID.
	 * @param string $reason  Suspension reason.
	 */
	private static function send_suspension_notification( $user_id, $reason ) {
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );

		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Your account has been suspended', 'user-suspend' ), $site_name );

		$body = sprintf(
			/* translators: 1: user display name, 2: site name, 3: optional reason line */
			__( "Hello %1\$s,\n\nYour account on %2\$s has been suspended.\n\n%3\$sIf you believe this is an error, please contact our support team.\n\nRegards,\n%2\$s", 'user-suspend' ),
			esc_html( $user->display_name ),
			$site_name,
			/* translators: %s: suspension reason */
			! empty( $reason ) ? sprintf( __( 'Reason: %s', 'user-suspend' ), $reason ) . "\n\n" : ''
		);

		wp_mail( $user->user_email, $subject, $body );
	}
}
