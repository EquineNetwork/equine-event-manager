<?php
/**
 * Plugin Name:       Equine Event Manager
 * Plugin URI:        https://example.com/equine-event-manager
 * Description:       Event reservation management for stalls, RV spaces, and add-on bookings — multi-event with stall-chart visualization, payment processor support (Stripe + Authorize.net), and CSV / receipt export.
 * Version:           2.3.0
 * Requires at least: 6.0
 * Tested up to:      6.8
 * Requires PHP:      7.4
 * Author:            Whitney Mitchell
 * Author URI:        https://example.com/equine-event-manager
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       equine-event-manager
 * Domain Path:       /languages
 * Update URI:        false
 *
 * @package           EEM_Plugin
 * @copyright         2024-2026 Whitney Mitchell
 *
 * Plugin URI + Author URI are placeholders pending external release. See
 * CLEANUP entry #23 for the to-do before publication / distribution.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EQUINE_EVENT_MANAGER_VERSION', '2.3.0' );

define( 'EQUINE_EVENT_MANAGER_FILE', __FILE__ );

define( 'EQUINE_EVENT_MANAGER_PATH', plugin_dir_path( __FILE__ ) );

define( 'EQUINE_EVENT_MANAGER_URL', plugin_dir_url( __FILE__ ) );

if ( class_exists( 'EEM_Plugin' ) || class_exists( 'EEM_Activator' ) ) {
	return;
}

require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-activator.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager.php';

register_activation_hook( __FILE__, array( 'EEM_Activator', 'activate' ) );

/**
 * Run Equine Event Manager.
 */
function equine_event_manager_run() {
	$plugin = new EEM_Plugin();
	$plugin->run();
}

equine_event_manager_run();
