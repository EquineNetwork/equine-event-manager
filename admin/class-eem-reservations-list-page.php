<?php
/**
 * Reservations list page controller (C4 — replaces WP-native
 * edit.php?post_type=en_reservation with a custom mockup-faithful page).
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom admin page that lists reservations per the mockup at
 * .mockups/reservations_page.html.
 *
 * Architecture (per CLAUDE.md layout-shell verification step 4):
 *   Bordered list-card with five stacked internal sections divided by
 *   horizontal borders — status tabs → toolbar (#fafafa) → desktop
 *   table → mobile cards (hidden on desktop) → pagination footer
 *   (#fafafa). Sits below a `.eem-page-header` row (title + "+ New
 *   Reservation" CTA). Container chrome comes from the shared
 *   `.eem-page` shell (`templates/admin/_page_shell.php`).
 *
 * Departure from WordPress admin convention: this page does NOT extend
 * `WP_List_Table` — the mockup's chrome (custom status-tabs strip,
 * #fafafa toolbar with two filter groups, distinct mobile-card mode,
 * bespoke pagination footer) is too far from the WP-native list-table
 * defaults to be worth fighting the framework. Same Path B pattern we
 * used for the Settings page port in C3.
 *
 * C4 sub-chunks land here in order:
 *   C4.A — Scaffold + menu rewire + redirect-from-old-list (this file)
 *   C4.B — Full render body (status tabs / toolbar / table / footer / mobile)
 *   C4.C — Row actions + Email Customers modal
 *   C4.D — Bulk actions + sort / filter / pagination + smoke test
 */
class EEM_Reservations_List_Page {

	const MENU_SLUG = 'equine-event-manager-reservations';

	/**
	 * Build the admin URL for this page with optional query args
	 * (status tab, search, paged, etc.). Used by render() + by the
	 * old-list redirect.
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
	 * Render the page. C4.A ships the page shell + a "Coming in C4.B"
	 * placeholder; the table body lands in C4.B.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'equine-event-manager' ) );
		}

		eem_render_page_open(
			array(
				'title'      => __( 'Reservations', 'equine-event-manager' ),
				'breadcrumb' => array(
					array( 'label' => __( 'Reservations', 'equine-event-manager' ) ),
				),
				'actions'    => sprintf(
					'<a class="eem-btn eem-btn-primary" href="%s">+ %s</a>',
					esc_url( admin_url( 'post-new.php?post_type=' . EEM_Reservations_List_Repo::POST_TYPE ) ),
					esc_html__( 'New Reservation', 'equine-event-manager' )
				),
			)
		);

		?>
		<div class="eem-reservations-list" data-eem-reservations-list>
			<div class="eem-card">
				<div class="eem-card-body">
					<p>
						<?php esc_html_e( 'Reservations list table renders here in C4.B (status tabs, toolbar, table, mobile cards, pagination).', 'equine-event-manager' ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php

		eem_render_page_close();
	}

	/**
	 * Redirect requests for the old WP-native CPT list at
	 * `edit.php?post_type=en_reservation` to the new custom page. Wired
	 * to `current_screen` so the redirect fires before WP renders the
	 * legacy list-table chrome.
	 *
	 * Direct hits on a single en_reservation edit screen pass through
	 * untouched — only the list view is intercepted.
	 *
	 * @param WP_Screen $screen
	 * @return void
	 */
	public static function maybe_redirect_old_list( $screen ) {
		if ( ! is_object( $screen ) ) {
			return;
		}
		if ( 'edit-' . EEM_Reservations_List_Repo::POST_TYPE !== $screen->id ) {
			return;
		}
		if ( 'edit' !== $screen->base ) {
			return;
		}

		// Preserve any passthrough query args we want forwarded to the
		// new page — currently just the status tab if WP supplied one.
		$forward = array();
		if ( isset( $_GET['post_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$status = sanitize_key( wp_unslash( $_GET['post_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tabs   = EEM_Reservations_List_Repo::status_tabs();
			if ( isset( $tabs[ $status ] ) ) {
				$forward['status'] = $status;
			}
		}

		wp_safe_redirect( self::url( $forward ), 302 );
		exit;
	}
}
