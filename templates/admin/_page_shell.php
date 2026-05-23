<?php
/**
 * Page shell partial — outer wrap + page header.
 *
 * Plugin admin pages call:
 *   eem_render_page_open(  $args )   — opens .eem-page wrapper + breadcrumb + page-wrap + header
 *   eem_render_page_close( $args )   — closes the wrappers (pass the same $args back)
 *
 * Between the two calls the page renders its body content (table, form, etc.).
 *
 * @package EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'eem_render_page_open' ) ) {
	/**
	 * Open the EEM page shell. Renders breadcrumb + page-wrap header.
	 *
	 * @param array $args {
	 *     @type string $title      Page title (h1).
	 *     @type string $subtitle   Optional subtitle under the title.
	 *     @type string $meta       Optional pre-rendered HTML (badges row, meta
	 *                              line, link cluster) that renders below the
	 *                              subtitle inside .eem-page-header-left. Added
	 *                              in C6.A for Order Detail; reusable by C7
	 *                              (Edit Reservation overview meta), C8 (Stall
	 *                              Chart Detail event-meta), and any other page
	 *                              whose title band needs secondary structured
	 *                              content beyond a simple subtitle string.
	 *     @type array  $breadcrumb Segments passed to eem_render_breadcrumb().
	 *     @type string $actions    Pre-rendered HTML for the right-side actions slot.
	 * }
	 * @return void
	 */
	// C5.5: removed dead `wrap` arg + standalone-header else-branch — verified zero `wrap => false` callers (the standalone variant + its .eem-page-header--standalone CSS class were both unused).
	function eem_render_page_open( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'title'      => '',
				'subtitle'   => '',
				'meta'       => '',
				'breadcrumb' => array(),
				'actions'    => '',
			)
		);
		?>
		<div class="eem-page">
			<?php eem_render_breadcrumb( $args['breadcrumb'] ); ?>
			<div class="eem-page-wrap">
				<header class="eem-page-header">
					<div class="eem-page-header-left">
						<?php if ( '' !== $args['title'] ) : ?>
							<h1 class="eem-page-title"><?php echo esc_html( $args['title'] ); ?></h1>
						<?php endif; ?>
						<?php if ( '' !== $args['subtitle'] ) : ?>
							<p class="eem-page-subtitle"><?php echo esc_html( $args['subtitle'] ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $args['meta'] ) : ?>
							<div class="eem-page-meta"><?php echo wp_kses_post( $args['meta'] ); ?></div>
						<?php endif; ?>
					</div>
					<?php if ( '' !== $args['actions'] ) : ?>
						<div class="eem-page-actions"><?php echo wp_kses_post( $args['actions'] ); ?></div>
					<?php endif; ?>
				</header>
				<div class="eem-page-body">
		<?php
	}
}

if ( ! function_exists( 'eem_render_page_close' ) ) {
	/**
	 * Close the EEM page shell opened by eem_render_page_open().
	 *
	 * @param array $args Reserved for future use; currently unused.
	 * @return void
	 */
	function eem_render_page_close( array $args = array() ) {
		unset( $args );
		?>
				</div><!-- /.eem-page-body -->
			</div><!-- /.eem-page-wrap -->
		</div><!-- /.eem-page -->
		<?php
	}
}
