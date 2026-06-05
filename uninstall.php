<?php
/**
 * Uninstall handler for Equine Event Manager.
 *
 * Runs when the user DELETES the plugin from the Plugins screen. It removes all
 * plugin data ONLY if the site opted in via Settings → Danger Zone ("Also delete
 * all data when the plugin is deleted"). Default is to KEEP data, so an
 * accidental delete + reinstall — or deleting to troubleshoot — is non-destructive.
 *
 * For an immediate in-place wipe without deleting the plugin, use the
 * Settings → Danger Zone "Erase all data & start fresh" button instead.
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 */

// Exit if not called by WordPress during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Respect the opt-in. If the site never enabled data deletion on uninstall,
// leave every reservation, order, event, setting, and table exactly as-is.
if ( ! get_option( 'equine_event_manager_delete_data_on_uninstall' ) ) {
	return;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-eem-uninstaller.php';

if ( class_exists( 'EEM_Uninstaller' ) ) {
	EEM_Uninstaller::purge_all_data( true );
}
