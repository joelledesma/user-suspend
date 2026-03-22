<?php
/**
 * Admin UI for User Suspend.
 *
 * @package User_Suspend
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all admin-facing output:
 * - Suspend section on the user profile edit screen.
 * - Status column in the Users list table.
 * - Bulk suspend/unsuspend actions.
 * - Suspended Users admin page with audit log.
 */
class User_Suspend_Admin {

	/**
	 * Nonce action for the profile form.
	 *
	 * @var string
	 */
	const NONCE_PROFILE_ACTION = 'user_suspend_profile_action';

	/**
	 * Nonce field name for the profile form.
	 *
	 * @var string
	 */
	const NONCE_PROFILE_NAME = 'user_suspend_profile_nonce';

	/**
	 * Singleton instance.
	 *
	 * @var User_Suspend_Admin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since  2.0.0
	 * @return User_Suspend_Admin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Set up admin hooks.
	 *
	 * @since 2.0.0
	 */
	private function __construct() {
		add_action( 'edit_user_profile', array( $this, 'render_profile_suspend_section' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_suspend_section' ) );

		add_filter( 'manage_users_columns', array( $this, 'add_status_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_status_column' ), 10, 3 );
		add_filter( 'manage_users_sortable_columns', array( $this, 'make_status_column_sortable' ) );

		add_filter( 'bulk_actions-users', array( $this, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-users', array( $this, 'handle_bulk_actions' ), 10, 3 );

		add_action( 'admin_notices', array( $this, 'bulk_action_admin_notice' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
	}

	// -------------------------------------------------------------------------
	// User Profile Section
	// -------------------------------------------------------------------------

	/**
	 * Output the suspension section on the user profile edit screen.
	 *
	 * @param WP_User $user User being edited.
	 */
	public function render_profile_suspend_section( WP_User $user ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		if ( get_current_user_id() === $user->ID ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Account Status', 'user-suspend' ) . '</h2>';

		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			echo '<div class="us-profile-card">';
			echo '<p class="description">' . esc_html__( 'Administrator accounts cannot be suspended.', 'user-suspend' ) . '</p>';
			echo '</div>';
			return;
		}

		$is_suspended = User_Suspend_Core::is_user_suspended( $user->ID );
		$details      = $is_suspended ? User_Suspend_Core::get_suspension_details( $user->ID ) : null;
		$reason       = $details ? $details['reason'] : '';
		$expiry       = $details ? $details['expiry'] : 0;
		$expiry_val   = $expiry > 0 ? gmdate( 'Y-m-d\TH:i', $expiry ) : '';

		wp_nonce_field( self::NONCE_PROFILE_ACTION . '_' . $user->ID, self::NONCE_PROFILE_NAME );
		?>
		<div class="us-profile-card">
			<div class="us-profile-card__header">
				<p class="us-profile-card__title"><?php esc_html_e( 'Suspension Settings', 'user-suspend' ); ?></p>
				<?php if ( $is_suspended ) : ?>
					<span class="us-badge us-badge--suspended"><?php esc_html_e( 'Suspended', 'user-suspend' ); ?></span>
				<?php else : ?>
					<span class="us-badge us-badge--active"><?php esc_html_e( 'Active', 'user-suspend' ); ?></span>
				<?php endif; ?>
			</div>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="us_suspend_checkbox"><?php esc_html_e( 'Suspend Account', 'user-suspend' ); ?></label>
					</th>
					<td>
						<label>
							<input
								type="checkbox"
								name="us_suspend_checkbox"
								id="us_suspend_checkbox"
								value="1"
								<?php checked( $is_suspended ); ?>
							/>
							<?php esc_html_e( 'Prevent this user from logging in', 'user-suspend' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="us_suspend_reason"><?php esc_html_e( 'Reason', 'user-suspend' ); ?></label>
					</th>
					<td>
						<textarea
							name="us_suspend_reason"
							id="us_suspend_reason"
							rows="3"
							class="large-text"
						><?php echo esc_textarea( $reason ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Optional. Included in the notification email sent to the user.', 'user-suspend' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="us_suspend_expiry"><?php esc_html_e( 'Expiry', 'user-suspend' ); ?></label>
					</th>
					<td>
						<input
							type="datetime-local"
							name="us_suspend_expiry"
							id="us_suspend_expiry"
							value="<?php echo esc_attr( $expiry_val ); ?>"
						/>
						<p class="description"><?php esc_html_e( 'Leave blank for a permanent suspension. Set a future date/time to lift it automatically.', 'user-suspend' ); ?></p>
					</td>
				</tr>
			</table>

			<?php if ( $is_suspended && $details ) : ?>
				<div class="us-suspend-meta">
					<strong><?php esc_html_e( 'Suspended on:', 'user-suspend' ); ?></strong>
					<?php echo esc_html( $details['suspend_date'] ); ?>
					<?php
					if ( $details['suspended_by'] ) {
						$admin = get_userdata( $details['suspended_by'] );
						if ( $admin ) {
							echo ' &mdash; ' . sprintf(
								/* translators: %s: admin display name */
								esc_html__( 'by %s', 'user-suspend' ),
								esc_html( $admin->display_name )
							);
						}
					}
					?>
					<?php if ( $expiry > 0 ) : ?>
						&nbsp;&bull;&nbsp;
						<strong><?php esc_html_e( 'Expires:', 'user-suspend' ); ?></strong>
						<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expiry ) ); ?>
					<?php else : ?>
						&nbsp;&bull;&nbsp;
						<span class="us-badge us-badge--permanent"><?php esc_html_e( 'Permanent', 'user-suspend' ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save the suspension form on the user profile edit screen.
	 *
	 * @param int $user_id User being edited.
	 */
	public function save_profile_suspend_section( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		if ( get_current_user_id() === $user_id ) {
			return;
		}

		if (
			! isset( $_POST[ self::NONCE_PROFILE_NAME ] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST[ self::NONCE_PROFILE_NAME ] ) ),
				self::NONCE_PROFILE_ACTION . '_' . $user_id
			)
		) {
			wp_die( esc_html__( 'Security check failed.', 'user-suspend' ) );
		}

		$target_user = get_userdata( $user_id );
		if ( $target_user && in_array( 'administrator', (array) $target_user->roles, true ) ) {
			return;
		}

		$should_suspend = isset( $_POST['us_suspend_checkbox'] ) && '1' === $_POST['us_suspend_checkbox'];
		$reason         = isset( $_POST['us_suspend_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['us_suspend_reason'] ) ) : '';
		$expiry_input   = isset( $_POST['us_suspend_expiry'] ) ? sanitize_text_field( wp_unslash( $_POST['us_suspend_expiry'] ) ) : '';
		$expiry         = 0;

		if ( ! empty( $expiry_input ) ) {
			$dt         = DateTime::createFromFormat( 'Y-m-d\TH:i', $expiry_input, new DateTimeZone( wp_timezone_string() ) );
			$max_expiry = strtotime( '+10 years' );
			if (
				$dt &&
				$dt->format( 'Y-m-d\TH:i' ) === $expiry_input &&
				$dt->getTimestamp() > time() &&
				$dt->getTimestamp() <= $max_expiry
			) {
				$expiry = $dt->getTimestamp();
			}
		}

		$currently_suspended = User_Suspend_Core::is_user_suspended( $user_id );

		if ( $should_suspend ) {
			User_Suspend_Core::suspend_user( $user_id, $reason, $expiry, get_current_user_id() );
		} elseif ( $currently_suspended ) {
			User_Suspend_Core::unsuspend_user( $user_id, get_current_user_id() );
		}
	}

	// -------------------------------------------------------------------------
	// Users List Table Column
	// -------------------------------------------------------------------------

	/**
	 * Add a Status column to the Users list table.
	 *
	 * @param  string[] $columns Existing columns.
	 * @return string[]
	 */
	public function add_status_column( $columns ) {
		$columns['us_status'] = __( 'Status', 'user-suspend' );
		return $columns;
	}

	/**
	 * Render the Status column value for each user row.
	 *
	 * @param  string $output      Current column output.
	 * @param  string $column_name Column slug.
	 * @param  int    $user_id     User ID.
	 * @return string
	 */
	public function render_status_column( $output, $column_name, $user_id ) {
		if ( 'us_status' !== $column_name ) {
			return $output;
		}

		if ( User_Suspend_Core::is_user_suspended( $user_id ) ) {
			return '<span class="us-badge us-badge--suspended">' . esc_html__( 'Suspended', 'user-suspend' ) . '</span>';
		}

		return '<span class="us-badge us-badge--active">' . esc_html__( 'Active', 'user-suspend' ) . '</span>';
	}

	/**
	 * Make the Status column sortable.
	 *
	 * @param  string[] $columns Sortable columns.
	 * @return string[]
	 */
	public function make_status_column_sortable( $columns ) {
		$columns['us_status'] = 'us_status';
		return $columns;
	}

	// -------------------------------------------------------------------------
	// Bulk Actions
	// -------------------------------------------------------------------------

	/**
	 * Register bulk suspend/unsuspend actions on the Users list table.
	 *
	 * @param  string[] $actions Existing bulk actions.
	 * @return string[]
	 */
	public function register_bulk_actions( $actions ) {
		$actions['us_suspend']   = __( 'Suspend', 'user-suspend' );
		$actions['us_unsuspend'] = __( 'Unsuspend', 'user-suspend' );
		return $actions;
	}

	/**
	 * Process bulk suspend/unsuspend actions.
	 *
	 * @param  string $redirect_to Redirect URL.
	 * @param  string $action      Current action slug.
	 * @param  int[]  $user_ids    Selected user IDs.
	 * @return string
	 */
	public function handle_bulk_actions( $redirect_to, $action, $user_ids ) {
		if ( ! in_array( $action, array( 'us_suspend', 'us_unsuspend' ), true ) ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'edit_users' ) ) {
			return $redirect_to;
		}

		if (
			! isset( $_REQUEST['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'bulk-users' )
		) {
			return $redirect_to;
		}

		$user_ids  = array_map( 'absint', (array) $user_ids );
		$user_ids  = array_filter( $user_ids );
		$processed = 0;
		$current   = get_current_user_id();

		foreach ( $user_ids as $uid ) {
			$u = get_userdata( $uid );

			if ( ! $u || in_array( 'administrator', (array) $u->roles, true ) ) {
				continue;
			}

			if ( $uid === $current ) {
				continue;
			}

			if ( 'us_suspend' === $action ) {
				User_Suspend_Core::suspend_user( $uid, '', 0, $current );
			} else {
				User_Suspend_Core::unsuspend_user( $uid, $current );
			}

			++$processed;
		}

		$redirect_to = add_query_arg(
			array(
				'us_action'    => $action,
				'us_processed' => $processed,
				'us_nonce'     => wp_create_nonce( 'us_bulk_action_notice' ),
			),
			$redirect_to
		);

		return $redirect_to;
	}

	/**
	 * Show an admin notice after a bulk action completes.
	 *
	 * Verifies a nonce passed in the redirect URL to prevent spoofed notices.
	 */
	public function bulk_action_admin_notice() {
		if (
			empty( $_REQUEST['us_action'] ) ||
			! isset( $_REQUEST['us_processed'] ) ||
			! isset( $_REQUEST['us_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['us_nonce'] ) ), 'us_bulk_action_notice' )
		) {
			return;
		}

		$action    = sanitize_key( $_REQUEST['us_action'] );
		$processed = absint( $_REQUEST['us_processed'] );

		if ( 'us_suspend' === $action ) {
			$message = sprintf(
				/* translators: %d: number of users suspended */
				_n( '%d user suspended.', '%d users suspended.', $processed, 'user-suspend' ),
				$processed
			);
		} else {
			$message = sprintf(
				/* translators: %d: number of users unsuspended */
				_n( '%d user unsuspended.', '%d users unsuspended.', $processed, 'user-suspend' ),
				$processed
			);
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	// -------------------------------------------------------------------------
	// Admin Menu Page
	// -------------------------------------------------------------------------

	/**
	 * Register the Suspended Users page under the Users menu.
	 */
	public function register_admin_menu() {
		add_users_page(
			__( 'Suspended Users', 'user-suspend' ),
			__( 'Suspended Users', 'user-suspend' ),
			'edit_users',
			'user-suspend-list',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render the Suspended Users admin page.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'user-suspend' ) );
		}

		if (
			isset( $_POST['us_clear_log'] ) &&
			check_admin_referer( 'us_clear_log_action', 'us_clear_log_nonce' )
		) {
			User_Suspend_Logger::clear_log();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Audit log cleared.', 'user-suspend' ) . '</p></div>';
		}

		$suspended_users = get_users(
			array(
				'meta_key'   => User_Suspend_Core::META_SUSPENDED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '1',                                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => -1,
			)
		);

		$log             = User_Suspend_Logger::get_log();
		$total_suspended = count( $suspended_users );

		// Count suspensions and unsuspensions from the audit log.
		$total_suspensions   = 0;
		$total_unsuspensions = 0;
		foreach ( $log as $entry ) {
			if ( 'suspended' === $entry['action'] ) {
				++$total_suspensions;
			} else {
				++$total_unsuspensions;
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Suspended Users', 'user-suspend' ); ?></h1>

			<?php
			$migration = User_Suspend_Migrator::get_migration_summary();
			if ( $migration ) :
				?>
			<div class="notice notice-info">
				<p>
					<?php
					printf(
						/* translators: 1: number of users migrated, 2: migration date */
						esc_html__( 'Data migration completed: %1$d user record(s) carried forward on %2$s.', 'user-suspend' ),
						(int) $migration['users_migrated'],
						esc_html( $migration['migrated_at'] )
					);
					?>
				</p>
			</div>
			<?php endif; ?>

			<div class="us-stats-bar">
				<div class="us-stat-card <?php echo $total_suspended > 0 ? 'us-stat-card--alert' : 'us-stat-card--ok'; ?>">
					<span class="us-stat-card__number"><?php echo esc_html( $total_suspended ); ?></span>
					<span class="us-stat-card__label"><?php esc_html_e( 'Currently Suspended', 'user-suspend' ); ?></span>
				</div>
				<div class="us-stat-card">
					<span class="us-stat-card__number"><?php echo esc_html( $total_suspensions ); ?></span>
					<span class="us-stat-card__label"><?php esc_html_e( 'Total Suspensions (log)', 'user-suspend' ); ?></span>
				</div>
				<div class="us-stat-card us-stat-card--ok">
					<span class="us-stat-card__number"><?php echo esc_html( $total_unsuspensions ); ?></span>
					<span class="us-stat-card__label"><?php esc_html_e( 'Total Unsuspensions (log)', 'user-suspend' ); ?></span>
				</div>
			</div>

			<h2><?php esc_html_e( 'Currently Suspended Accounts', 'user-suspend' ); ?></h2>

			<?php if ( empty( $suspended_users ) ) : ?>
				<div class="us-empty-state">
					<p><?php esc_html_e( 'No users are currently suspended.', 'user-suspend' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped users us-users-table">
					<thead>
						<tr>
							<th style="width:220px;"><?php esc_html_e( 'User', 'user-suspend' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'user-suspend' ); ?></th>
							<th style="width:160px;"><?php esc_html_e( 'Suspended On', 'user-suspend' ); ?></th>
							<th style="width:140px;"><?php esc_html_e( 'Expiry', 'user-suspend' ); ?></th>
							<th style="width:140px;"><?php esc_html_e( 'Suspended By', 'user-suspend' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Actions', 'user-suspend' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $suspended_users as $user ) :
						$details      = User_Suspend_Core::get_suspension_details( $user->ID );
						$expiry       = $details && $details['expiry'] > 0
							? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $details['expiry'] )
							: __( 'Permanent', 'user-suspend' );
						$is_permanent = ! ( $details && $details['expiry'] > 0 );
						$suspended_by = $details && $details['suspended_by'] ? get_userdata( $details['suspended_by'] ) : null;
						$edit_url     = get_edit_user_link( $user->ID );
						$avatar       = get_avatar( $user->ID, 36 );
						?>
						<tr>
							<td>
								<div class="us-user-cell">
									<?php echo wp_kses_post( $avatar ); ?>
									<div>
										<a href="<?php echo esc_url( $edit_url ); ?>" class="us-user-name"><?php echo esc_html( $user->display_name ); ?></a>
										<div class="us-user-email"><?php echo esc_html( $user->user_email ); ?></div>
									</div>
								</div>
							</td>
							<td>
								<span class="us-reason-cell" title="<?php echo esc_attr( $details ? $details['reason'] : '' ); ?>">
									<?php echo esc_html( $details && $details['reason'] ? $details['reason'] : '—' ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $details ? $details['suspend_date'] : '—' ); ?></td>
							<td>
								<?php if ( $is_permanent ) : ?>
									<span class="us-badge us-badge--permanent"><?php esc_html_e( 'Permanent', 'user-suspend' ); ?></span>
								<?php else : ?>
									<?php echo esc_html( $expiry ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo $suspended_by ? esc_html( $suspended_by->display_name ) : esc_html__( 'System', 'user-suspend' ); ?></td>
							<td>
								<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
									<?php esc_html_e( 'Edit', 'user-suspend' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr class="us-divider" />

			<div class="us-section-header">
				<h2><?php esc_html_e( 'Audit Log', 'user-suspend' ); ?></h2>
				<?php if ( ! empty( $log ) ) : ?>
					<form method="post">
						<?php wp_nonce_field( 'us_clear_log_action', 'us_clear_log_nonce' ); ?>
						<input
							type="submit"
							name="us_clear_log"
							class="button button-secondary"
							value="<?php esc_attr_e( 'Clear Log', 'user-suspend' ); ?>"
							onclick="return confirm( '<?php esc_attr_e( 'Are you sure? This cannot be undone.', 'user-suspend' ); ?>' );"
						/>
					</form>
				<?php endif; ?>
			</div>

			<?php if ( empty( $log ) ) : ?>
				<div class="us-empty-state">
					<p><?php esc_html_e( 'No log entries yet.', 'user-suspend' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped us-log-table">
					<thead>
						<tr>
							<th style="width:160px;"><?php esc_html_e( 'Timestamp', 'user-suspend' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Action', 'user-suspend' ); ?></th>
							<th style="width:200px;"><?php esc_html_e( 'User', 'user-suspend' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'user-suspend' ); ?></th>
							<th style="width:160px;"><?php esc_html_e( 'Performed By', 'user-suspend' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $log as $entry ) :
						$affected  = get_userdata( $entry['user_id'] );
						$performer = $entry['performed_by'] ? get_userdata( $entry['performed_by'] ) : null;
						?>
						<tr>
							<td><?php echo esc_html( $entry['timestamp'] ); ?></td>
							<td>
								<span class="us-badge us-badge--<?php echo esc_attr( $entry['action'] ); ?>">
									<?php echo 'suspended' === $entry['action'] ? esc_html__( 'Suspended', 'user-suspend' ) : esc_html__( 'Unsuspended', 'user-suspend' ); ?>
								</span>
							</td>
							<td><?php echo $affected ? esc_html( $affected->display_name . ' (#' . $entry['user_id'] . ')' ) : esc_html( '#' . $entry['user_id'] ); ?></td>
							<td><?php echo esc_html( $entry['reason'] ? $entry['reason'] : '—' ); ?></td>
							<td><?php echo $performer ? esc_html( $performer->display_name ) : esc_html__( 'System', 'user-suspend' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Admin Styles
	// -------------------------------------------------------------------------

	/**
	 * Enqueue the admin stylesheet.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_styles( $hook ) {
		$relevant = array( 'users.php', 'user-edit.php', 'users_page_user-suspend-list' );
		if ( ! in_array( $hook, $relevant, true ) ) {
			return;
		}

		wp_enqueue_style(
			'user-suspend-admin',
			USER_SUSPEND_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			USER_SUSPEND_VERSION
		);
	}
}
