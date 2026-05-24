<?php
/**
 * Collect Payment admin page stub (DS-1.A scaffold; functional implementation in C14).
 *
 * Renders the canonical `.mockups/collect_payment_page.html` markup
 * with a "Coming in C14" preview banner overlaid at the top. Accepts
 * `?order_key=<key>` URL param per the DS-1.A scope decision (Q6):
 * routes by `order_key` (shipped convention), not `order_id`
 * (mockup convention).
 *
 * Reached from:
 *   - Orders list "Collect" pill on unpaid/invoice-sent rows
 *     (`EEM_Orders_List_Page::collect_payment_url($order_key)`)
 *   - Order Detail page's "Payment Outstanding" banner button
 *
 * Real Stripe + Authorize.net charge dispatch + Send Link + Discount
 * handling lands in C14.
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
class EEM_Collect_Payment_Page {

	/**
	 * Menu slug used for the route. Hidden submenu (parent='') — reached
	 * only via direct URL navigation from Orders list / Order Detail.
	 */
	const MENU_SLUG = 'equine-event-manager-collect-payment';

	/**
	 * Render the stub page. The `?order_key=<key>` param is read but not
	 * yet used to look up the order; C14 wires the real lookup. For
	 * DS-1.A the mockup HTML renders with its own seeded sample data.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';

		?>
		<div class="wrap">
			<div class="eem-page">
				<div class="eem-page-wrap">
					<header class="eem-page-header">
						<div class="eem-page-header-left">
							<h1 class="eem-page-title"><?php esc_html_e( 'Collect Payment', 'equine-event-manager' ); ?></h1>
							<p class="eem-page-subtitle">
								<?php esc_html_e( 'Process payment for an existing order. The order details are locked here — to amend the order, return to the order detail page.', 'equine-event-manager' ); ?>
							</p>
						</div>
					</header>

					<div class="eem-page-body">
						<div class="eem-info-banner eem-info-banner--preview">
							<strong><?php esc_html_e( 'Visual preview only — Coming in C14.', 'equine-event-manager' ); ?></strong>
							<?php
							printf(
								esc_html__( 'The layout below is rendered from the canonical mockup at %1$s for visual reference. Functional implementation (Stripe + Authorize.net charge dispatch, Send Link, Discount handling) lands in C14. The %2$s URL param is wired and received correctly; downstream lookup happens in C14.', 'equine-event-manager' ),
								'<code>.mockups/collect_payment_page.html</code>',
								'<code>order_key</code>'
							);
							?>
							<?php if ( '' !== $order_key ) : ?>
								<br><small><?php
									printf(
										esc_html__( 'Received order_key: %s', 'equine-event-manager' ),
										'<code>' . esc_html( $order_key ) . '</code>'
									);
								?></small>
							<?php endif; ?>
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
	 * See `EEM_Create_Order_Page::render_mockup_preview` for the DS-1.A.1
	 * rationale (mockup's inline `<style>` block was cascading out and
	 * breaking surrounding admin chrome; iframe boundary fixes it).
	 *
	 * @return void
	 */
	private static function render_mockup_preview() {
		$mockup_path = EQUINE_EVENT_MANAGER_PATH . '.mockups/collect_payment_page.html';
		if ( ! file_exists( $mockup_path ) ) {
			echo '<p>' . esc_html__( 'Mockup preview unavailable.', 'equine-event-manager' ) . '</p>';
			return;
		}
		$contents = (string) file_get_contents( $mockup_path );
		printf(
			'<iframe class="eem-mockup-preview" sandbox="allow-same-origin" title="%s" srcdoc="%s"></iframe>',
			esc_attr__( 'Collect Payment — canonical mockup preview', 'equine-event-manager' ),
			esc_attr( $contents )
		);
	}
}
