<?php
/*
Plugin Name: Equine Event Manager
Description: Event reservation management for stalls and RV spaces.
Version: 2.1.57
Author: Whitney Mitchell
Text Domain: equine-event-manager
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EQUINE_EVENT_MANAGER_VERSION', '2.1.57' );

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
