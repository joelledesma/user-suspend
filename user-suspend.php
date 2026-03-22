<?php
/**
 * User Suspend
 *
 * @package   User_Suspend
 * @author    Joel Ledesma
 * @copyright 2026 Joel Ledesma
 * @license   GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       User Suspend
 * Description:       Suspend user accounts with timed or permanent bans, ban reasons, email notifications, audit logging, and bulk actions.
 * Version:           2.1.2
 * Author:            Joel Ledesma
 * Author URI:        https://joelledesma.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       user-suspend
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Tested up to:      6.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'USER_SUSPEND_VERSION', '2.1.2' );
define( 'USER_SUSPEND_PLUGIN_FILE', __FILE__ );
define( 'USER_SUSPEND_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'USER_SUSPEND_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once USER_SUSPEND_PLUGIN_DIR . 'includes/class-user-suspend-logger.php';
require_once USER_SUSPEND_PLUGIN_DIR . 'includes/class-user-suspend-migrator.php';
require_once USER_SUSPEND_PLUGIN_DIR . 'includes/class-user-suspend-core.php';
require_once USER_SUSPEND_PLUGIN_DIR . 'admin/class-user-suspend-admin.php';

register_activation_hook( __FILE__, array( 'User_Suspend_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'User_Suspend_Core', 'deactivate' ) );

add_action( 'plugins_loaded', 'user_suspend_init' );

/**
 * Boot the plugin after all plugins are loaded.
 *
 * @since 2.0.0
 */
function user_suspend_init() {
	User_Suspend_Core::get_instance();

	if ( is_admin() ) {
		User_Suspend_Admin::get_instance();
	}
}
