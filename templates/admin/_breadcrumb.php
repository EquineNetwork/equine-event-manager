<?php
/**
 * Breadcrumb header partial (CONV-1).
 *
 * Renders the plugin's branded breadcrumb bar at the top of every admin
 * page beneath WordPress's admin bar. Plugin logo on the left followed
 * by " / "-separated path segments; the last segment renders as the
 * current page (not a link).
 *
 * @package EEM_Plugin
 *
 * Usage:
 *   eem_render_breadcrumb( array(
 *       array( 'label' => 'Reservations', 'url' => admin_url( 'admin.php?page=...' ) ),
 *       array( 'label' => 'Edit Reservation' ),                       // current page
 *   ) );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'eem_render_breadcrumb' ) ) {
	/**
	 * Print the EEM breadcrumb bar.
	 *
	 * @param array $segments Ordered list of `[ 'label' => string, 'url' => string|null ]`.
	 *                        Omit/null `url` to render the segment as the current page.
	 * @return void
	 */
	function eem_render_breadcrumb( array $segments = array() ) {
		$logo_url = admin_url( 'admin.php?page=' . EEM_Admin::MENU_SLUG );
		?>
		<nav class="eem-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'equine-event-manager' ); ?>">
			<a class="eem-breadcrumb-logo" href="<?php echo esc_url( $logo_url ); ?>" aria-label="<?php esc_attr_e( 'Equine Event Manager dashboard', 'equine-event-manager' ); ?>">
				<img src="<?php echo esc_url( EQUINE_EVENT_MANAGER_URL . 'assets/images/logo.png' ); ?>" alt="<?php esc_attr_e( 'Equine Event Manager', 'equine-event-manager' ); ?>" />
			</a>

			<?php
			$last_index = count( $segments ) - 1;
			foreach ( $segments as $index => $segment ) :
				$label = isset( $segment['label'] ) ? (string) $segment['label'] : '';
				$url   = isset( $segment['url'] ) ? (string) $segment['url'] : '';
				$is_current = ( $index === $last_index ) || '' === $url;
				?>
				<span class="eem-breadcrumb-sep" aria-hidden="true">/</span>
				<?php if ( $is_current ) : ?>
					<span class="eem-breadcrumb-segment" aria-current="page"><?php echo esc_html( $label ); ?></span>
				<?php else : ?>
					<a class="eem-breadcrumb-segment" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endif; ?>
			<?php endforeach; ?>
		</nav>
		<?php
	}
}
