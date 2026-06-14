<?php
/**
 * Equine Event Manager — PWA support.
 *
 * Outputs the Web App Manifest link and registers the service worker on both
 * admin and front-end pages so the site is installable as "Equine Event Manager."
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EEM_PWA {

	public static function init(): void {
		add_action( 'wp_head', array( __CLASS__, 'output_manifest_link' ) );
		add_action( 'admin_head', array( __CLASS__, 'output_manifest_link' ) );
		add_action( 'wp_footer', array( __CLASS__, 'register_service_worker' ) );
		add_action( 'admin_footer', array( __CLASS__, 'register_service_worker' ) );
	}

	public static function output_manifest_link(): void {
		$manifest_url = EQUINE_EVENT_MANAGER_URL . 'assets/manifest.json';
		printf(
			'<link rel="manifest" href="%s">' . "\n" .
			'<meta name="theme-color" content="#0a1628">' . "\n",
			esc_url( $manifest_url )
		);
	}

	public static function register_service_worker(): void {
		$sw_url = EQUINE_EVENT_MANAGER_URL . 'assets/js/eem-sw.js';
		?>
		<script>
		if ('serviceWorker' in navigator) {
			navigator.serviceWorker.register(<?php echo wp_json_encode( $sw_url ); ?>, {scope: '/'}).catch(function(){});
		}
		</script>
		<?php
	}
}
