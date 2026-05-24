<?php
/**
 * Create Order admin page stub (DS-1.A scaffold; functional implementation in C13).
 *
 * Renders the canonical `.mockups/create_order_page.html` markup with a
 * "Coming in C13" preview banner overlaid at the top. The mockup HTML
 * gives Whitney + browser visual-verify a full preview of the page
 * shape; functional implementation (Customer Search typeahead,
 * Reservation picker, Custom Line Items save, Discount handling, Send
 * Link / Charge Card payment dispatch) lands in C13.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 2.2.0
 */
class EEM_Create_Order_Page {

	/**
	 * Menu slug used for the route. Wired in
	 * `EEM_Admin::register_admin_pages` via `add_submenu_page`.
	 */
	const MENU_SLUG = 'equine-event-manager-create-order';

	/**
	 * Render the stub page. Outputs an info banner explaining the C13
	 * deferral, followed by the inline-extracted canonical mockup HTML
	 * (just the page body — `<html>`, `<head>`, `<body>` and the WP
	 * shell stub from the mockup are NOT included since this page renders
	 * inside the real WP admin chrome).
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		?>
		<div class="wrap">
			<div class="eem-page">
				<div class="eem-page-wrap">
					<header class="eem-page-header">
						<div class="eem-page-header-left">
							<h1 class="eem-page-title"><?php esc_html_e( 'Create Order', 'equine-event-manager' ); ?></h1>
							<p class="eem-page-subtitle">
								<?php esc_html_e( 'Manually create a new order on behalf of a customer — phone orders, walk-ins, or anything not coming through the customer-facing reservation form.', 'equine-event-manager' ); ?>
							</p>
						</div>
					</header>

					<div class="eem-page-body">
						<div class="eem-info-banner eem-info-banner--preview">
							<strong><?php esc_html_e( 'Visual preview only — Coming in C13.', 'equine-event-manager' ); ?></strong>
							<?php
							printf(
								esc_html__( 'The layout below is rendered from the canonical mockup at %1$s for visual reference. Functional implementation (Customer Search, Reservation picker, Custom Line Items, Discount handling, payment dispatch) lands in C13.', 'equine-event-manager' ),
								'<code>.mockups/create_order_page.html</code>'
							);
							?>
						</div>

						<?php self::render_mockup_preview(); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the canonical mockup inside an isolated `<iframe srcdoc>`.
	 *
	 * DS-1.A shipped this as an inline DOM injection of the mockup's
	 * `<body>` contents, but the mockup carries its own `<style>` block
	 * targeting generic selectors (`body`, `.wp-content`, etc.) that
	 * cascaded out and broke the surrounding WP admin chrome. DS-1.A.1
	 * swaps to `srcdoc` so the iframe gives a hard CSS/JS boundary —
	 * future mockup re-imports are zero-touch on this stub.
	 *
	 * @return void
	 */
	private static function render_mockup_preview() {
		$mockup_path = EQUINE_EVENT_MANAGER_PATH . '.mockups/create_order_page.html';
		if ( ! file_exists( $mockup_path ) ) {
			echo '<p>' . esc_html__( 'Mockup preview unavailable.', 'equine-event-manager' ) . '</p>';
			return;
		}
		$contents = (string) file_get_contents( $mockup_path );
		printf(
			'<iframe class="eem-mockup-preview" sandbox="allow-same-origin" title="%s" srcdoc="%s"></iframe>',
			esc_attr__( 'Create Order — canonical mockup preview', 'equine-event-manager' ),
			esc_attr( $contents )
		);
	}
}
