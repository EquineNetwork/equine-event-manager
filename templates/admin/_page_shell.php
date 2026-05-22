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
	 *     @type array  $breadcrumb Segments passed to eem_render_breadcrumb().
	 *     @type string $actions    Pre-rendered HTML for the right-side actions slot.
	 *     @type bool   $wrap       When true (default), renders .eem-page-wrap +
	 *                              .eem-page-header + .eem-page-body as the
	 *                              outer card. When false, renders the title +
	 *                              actions as a standalone .eem-page-header
	 *                              sibling of the breadcrumb (no outer card,
	 *                              no body wrapper) so the caller can render
	 *                              its own bordered chrome — used by pages
	 *                              whose mockup spec puts the title above a
	 *                              separate card (Reservations list, etc.).
	 * }
	 * @return void
	 */
	function eem_render_page_open( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'title'      => '',
				'subtitle'   => '',
				'breadcrumb' => array(),
				'actions'    => '',
				'wrap'       => true,
			)
		);
		?>
		<div class="eem-page">
			<?php eem_render_breadcrumb( $args['breadcrumb'] ); ?>
			<?php if ( $args['wrap'] ) : ?>
				<div class="eem-page-wrap">
					<header class="eem-page-header">
						<div class="eem-page-header-left">
							<?php if ( '' !== $args['title'] ) : ?>
								<h1 class="eem-page-title"><?php echo esc_html( $args['title'] ); ?></h1>
							<?php endif; ?>
							<?php if ( '' !== $args['subtitle'] ) : ?>
								<p class="eem-page-subtitle"><?php echo esc_html( $args['subtitle'] ); ?></p>
							<?php endif; ?>
						</div>
						<?php if ( '' !== $args['actions'] ) : ?>
							<div class="eem-page-actions"><?php echo wp_kses_post( $args['actions'] ); ?></div>
						<?php endif; ?>
					</header>
					<div class="eem-page-body">
			<?php else : ?>
				<header class="eem-page-header eem-page-header--standalone">
					<div class="eem-page-header-left">
						<?php if ( '' !== $args['title'] ) : ?>
							<h1 class="eem-page-title"><?php echo esc_html( $args['title'] ); ?></h1>
						<?php endif; ?>
						<?php if ( '' !== $args['subtitle'] ) : ?>
							<p class="eem-page-subtitle"><?php echo esc_html( $args['subtitle'] ); ?></p>
						<?php endif; ?>
					</div>
					<?php if ( '' !== $args['actions'] ) : ?>
						<div class="eem-page-actions"><?php echo wp_kses_post( $args['actions'] ); ?></div>
					<?php endif; ?>
				</header>
			<?php endif; ?>
		<?php
	}
}

if ( ! function_exists( 'eem_render_page_close' ) ) {
	/**
	 * Close the EEM page shell opened by eem_render_page_open().
	 *
	 * @param array $args { @type bool $wrap Must match the value passed to
	 *                      eem_render_page_open() so we close the right number
	 *                      of divs. Default true. }
	 * @return void
	 */
	function eem_render_page_close( array $args = array() ) {
		$args = wp_parse_args( $args, array( 'wrap' => true ) );
		if ( $args['wrap'] ) :
			?>
				</div><!-- /.eem-page-body -->
			</div><!-- /.eem-page-wrap -->
			<?php
		endif;
		?>
		</div><!-- /.eem-page -->
		<?php
	}
}
