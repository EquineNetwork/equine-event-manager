<?php
/**
 * Orders list page controller (C5 — replaces legacy
 * EEM_Admin::render_orders_page with a mockup-faithful page).
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom admin page that lists customer orders per the mockup at
 * .mockups/orders_page.html.
 *
 * Architecture (per CLAUDE.md layout-shell verification step 4):
 *   Bordered list-card containing five stacked internal sections —
 *   page-header (inside the card per step 4.5; reuses C4 .eem-page-header
 *   primitive via `wrap=true` on the shell), toolbar (#fafafa with two
 *   horizontal rows + a bulk-action row), desktop table, mobile cards
 *   (hidden on desktop), pagination footer (#fafafa).
 *
 * Inherited constraints (do not modify in C5):
 *   - Shared C4 primitives — .eem-page-header / .eem-page-title /
 *     .eem-page-subtitle / .eem-page-actions / .eem-search-input —
 *     are READ-ONLY. New page-unique chrome lives under .eem-orders-*.
 *   - Search-pair visual seam is CLEANUP #13, deferred to C13. C5
 *     does not attempt to fix it.
 *
 * Sub-chunks land here in order:
 *   C5.A — Scaffold + repo wiring + smoke (this file)
 *   C5.B — Full render body (toolbar / table / mobile / footer / CSS)
 *   C5.C — Row actions + Collect button + Print Receipt
 *   C5.D — Toolbar dispatcher + bulk Refund Selected stub
 */
class EEM_Orders_List_Page {

	const MENU_SLUG = 'equine-event-manager-orders';

	/**
	 * Build the admin URL for this page with optional query args.
	 *
	 * @param array<string, string|int> $args
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::MENU_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render the page. C5.A renders the shell + an empty .eem-list-card
	 * stub so the smoke can exercise wiring before C5.B fills in the
	 * toolbar / table / footer.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		eem_render_page_open(
			array(
				'title'      => __( 'Orders', 'equine-event-manager' ),
				'subtitle'   => __( 'View and manage all customer orders. Filter by event or billing status, search orders, and take quick actions.', 'equine-event-manager' ),
				'breadcrumb' => array(
					array( 'label' => __( 'Orders', 'equine-event-manager' ) ),
				),
				'actions'    => sprintf(
					'<a class="eem-btn eem-btn-electric" href="%s">+ %s</a>',
					esc_url( admin_url( 'admin.php?page=equine-event-manager-invoicing' ) ),
					esc_html__( 'Create Order', 'equine-event-manager' )
				),
				'wrap'       => true,
			)
		);

		?>
		<div class="eem-list-card eem-orders-list" data-eem-orders-list>
			<?php /* C5.B fills toolbar + table + mobile + footer here. */ ?>
		</div>
		<?php

		eem_render_page_close( array( 'wrap' => true ) );
	}
}
