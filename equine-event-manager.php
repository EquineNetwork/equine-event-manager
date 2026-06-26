<?php
/**
 * Plugin Name:       Equine Event Manager
 * Plugin URI:        https://github.com/EquineNetwork/equine-event-manager
 * Description:       Event reservation management for stalls, RV spaces, and add-on bookings — multi-event with stall-chart visualization, payment processor support (Stripe + Authorize.net), and CSV / receipt export.
 * Version:           2.7.641
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

define( 'EQUINE_EVENT_MANAGER_VERSION', '2.7.641' );

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

// ============================================================================
// TEMPORARY READ-ONLY DIAGNOSTIC — order line-item dump. REMOVE after debugging
// the #00002 balance-due discrepancy. Admin-only; changes nothing in the DB.
// Usage: /wp-admin/?eem_diag_order=<order_key>
// ============================================================================
add_action( 'admin_init', function () {
	if ( empty( $_GET['eem_diag_order'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$key  = sanitize_text_field( wp_unslash( $_GET['eem_diag_order'] ) );
	$repo = new EEM_Orders_Repository();
	$order = $repo->get_order( $key );
	header( 'Content-Type: text/plain; charset=utf-8' );
	if ( ! is_array( $order ) ) {
		echo "ORDER NOT FOUND for key: {$key}\n";
		exit;
	}
	echo "=== ORDER ROLLUP ===\n";
	foreach ( array( 'stall_subtotal', 'rv_subtotal', 'fees', 'tax', 'tax_rate', 'total', 'amount_paid', 'required_shavings_qty', 'additional_shavings_qty', 'stall_quantity', 'rv_quantity', 'payment_status' ) as $k ) {
		echo str_pad( $k, 26 ) . ': ' . ( isset( $order[ $k ] ) ? $order[ $k ] : '(unset)' ) . "\n";
	}
	echo "\n=== COMPONENT ROWS ===\n";
	foreach ( (array) ( isset( $order['components'] ) ? $order['components'] : array() ) as $c ) {
		$raw = $repo->get_component_row( (string) $c['table'], (int) $c['row_id'] );
		echo "\n--- {$c['table']} row id={$c['row_id']} ---\n";
		if ( ! is_array( $raw ) ) {
			echo "(missing)\n";
			continue;
		}
		foreach ( array( 'id', 'stall_qty', 'tack_stall_qty', 'rv_qty', 'required_shavings_qty', 'additional_shavings_qty', 'unit_price', 'subtotal', 'convenience_fee', 'tax', 'tax_rate', 'total', 'amount_paid', 'payment_status', 'arrival_date', 'departure_date', 'stay_type', 'notes' ) as $k ) {
			if ( array_key_exists( $k, $raw ) ) {
				$v = is_scalar( $raw[ $k ] ) ? $raw[ $k ] : wp_json_encode( $raw[ $k ] );
				echo str_pad( $k, 26 ) . ': ' . $v . "\n";
			}
		}
	}
	exit;
} );
