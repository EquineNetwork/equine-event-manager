<?php
/**
 * Plugin Name:       Equine Event Manager
 * Plugin URI:        https://github.com/EquineNetwork/equine-event-manager
 * Description:       Event reservation management for stalls, RV spaces, and add-on bookings — multi-event with stall-chart visualization, payment processor support (Stripe + Authorize.net), and CSV / receipt export.
 * Version:           2.7.716
 * Requires at least: 6.0
 * Tested up to:      6.8
 * Requires PHP:      7.4
 * Author:            Equine Network
 * Author URI:        https://equinenetwork.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       equine-event-manager
 * Domain Path:       /languages
 * Update URI:        false
 *
 * @package           EEM_Plugin
 * @copyright         2024-2026 Equine Network
 *
 * Plugin URI points at the canonical GitHub repo; Author URI at the Equine
 * Network site. Confirm both are the intended public-facing URLs before any
 * external/wordpress.org distribution (was CLEANUP #23).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EQUINE_EVENT_MANAGER_VERSION', '2.7.716' );

define( 'EQUINE_EVENT_MANAGER_FILE', __FILE__ );

define( 'EQUINE_EVENT_MANAGER_PATH', plugin_dir_path( __FILE__ ) );

define( 'EQUINE_EVENT_MANAGER_URL', plugin_dir_url( __FILE__ ) );

if ( class_exists( 'EEM_Plugin' ) || class_exists( 'EEM_Activator' ) ) {
	return;
}

// Composer autoloader — provides Pelago\Emogrifier (CSS inlining for the
// transactional email templates, per CLAUDE.md "Email CSS inlining at
// send-time"). Guarded so the plugin still boots if vendor/ is absent; the
// mailer degrades to sending un-inlined HTML in that case.
if ( is_readable( EQUINE_EVENT_MANAGER_PATH . 'vendor/autoload.php' ) ) {
	require_once EQUINE_EVENT_MANAGER_PATH . 'vendor/autoload.php';
}

require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-activator.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-updater.php';

register_activation_hook( __FILE__, array( 'EEM_Activator', 'activate' ) );

// A8 — clear the expired-holds cron sweep on deactivation so we don't leave an
// orphaned scheduled event behind.
register_deactivation_hook( __FILE__, function () {
	if ( class_exists( 'EEM_Unit_Holds_Repo' ) ) {
		EEM_Unit_Holds_Repo::unschedule_cleanup();
	}
	// #23 — clear the daily payment-reminder sweep on deactivation too.
	if ( class_exists( 'EEM_Payment_Reminder' ) ) {
		EEM_Payment_Reminder::unschedule();
	}
} );

// GitHub-backed in-WordPress auto-updates: a push to `main` with a bumped
// Version: header surfaces "Update now" on the Plugins screen. The repo is
// public, so no auth token is required (see EEM_Updater).
EEM_Updater::init();

/**
 * Run Equine Event Manager.
 */
function equine_event_manager_run() {
	$plugin = new EEM_Plugin();
	$plugin->run();
}

equine_event_manager_run();
