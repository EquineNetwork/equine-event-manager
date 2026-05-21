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

if ( class_exists( 'Equine_Event_Manager' ) || class_exists( 'Equine_Event_Manager_Activator' ) ) {
	return;
}

require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-activator.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager.php';

register_activation_hook( __FILE__, array( 'Equine_Event_Manager_Activator', 'activate' ) );

/**
 * Start the plugin.
 */
if ( ! function_exists( 'equine_event_manager_run' ) ) {
	/**
	 * Run Equine Event Manager.
	 */
	function equine_event_manager_run() {
		$plugin = new Equine_Event_Manager();
		$plugin->run();
	}
}

equine_event_manager_run();
