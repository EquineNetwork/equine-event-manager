<?php
/**
 * Plugin Name: EN Event Manager
 * Description: Event reservation management for stalls and RV spaces.
 * Version: 1.4.0
 * Author: Whitney Mitchell
 * Text Domain: en-event-manager
 *
 * @package EN_Event_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EN_EVENT_MANAGER_VERSION', '1.4.0' );
define( 'EN_EVENT_MANAGER_FILE', __FILE__ );
define( 'EN_EVENT_MANAGER_PATH', plugin_dir_path( __FILE__ ) );
define( 'EN_EVENT_MANAGER_URL', plugin_dir_url( __FILE__ ) );

require_once EN_EVENT_MANAGER_PATH . 'includes/class-en-event-manager-activator.php';
require_once EN_EVENT_MANAGER_PATH . 'includes/class-en-event-manager.php';

register_activation_hook( __FILE__, array( 'EN_Event_Manager_Activator', 'activate' ) );

/**
 * Start the plugin.
 */
function en_event_manager_run() {
	$plugin = new EN_Event_Manager();
	$plugin->run();
}

en_event_manager_run();
