<?php
/**
 * Equine Event Manager — PWA support.
 *
 * DEFERRED TO v2 (2026-06-23, Whitney). The plugin is nowhere near PWA-ready, so
 * the install banner, Web App Manifest, and service worker are all DISABLED.
 *
 * Because a service worker registered on a previous version persists in the
 * browser until explicitly unregistered, init() now outputs a one-time cleanup
 * script that unregisters any lingering EEM service worker instead of registering
 * one. The manifest link and the "Install Equine Event Manager" banner are no
 * longer emitted at all. When PWA work resumes in v2, restore the manifest +
 * service-worker registration + install-prompt logic from git history.
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EEM_PWA {

	/**
	 * Hook the cleanup script into the admin + front-end footers. No manifest
	 * link, no service-worker registration, no install banner — PWA is v2.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_footer', array( __CLASS__, 'unregister_service_worker' ) );
		add_action( 'admin_footer', array( __CLASS__, 'unregister_service_worker' ) );
	}

	/**
	 * Unregister any service worker left over from the pre-v2 PWA build so
	 * browsers that already installed it stop serving cached content and stop
	 * firing the install prompt. Safe to run on every page load — a no-op once
	 * no EEM service worker remains.
	 *
	 * @return void
	 */
	public static function unregister_service_worker(): void {
		$sw_url = EQUINE_EVENT_MANAGER_URL . 'assets/js/eem-sw.js';
		?>
		<script>
		if ('serviceWorker' in navigator) {
			var eemSwUrl = <?php echo wp_json_encode( $sw_url ); ?>;
			navigator.serviceWorker.getRegistrations().then(function(regs){
				regs.forEach(function(r){
					var w = r.active || r.installing || r.waiting;
					if (w && w.scriptURL === eemSwUrl) { r.unregister(); }
				});
			}).catch(function(){});
		}
		</script>
		<?php
	}
}
