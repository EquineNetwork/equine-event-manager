<?php
/**
 * Reservation Editor — shared read-only layout-summary widget (C7.X.4).
 *
 * Mockup-canonical .eem-layout-summary widget (mockup lines 624–644
 * for Stall; 864–884 for Lot). Renders 3-stat header line + per-row
 * breakdown + "Manage Layout" stub button with C8 corner badge.
 *
 * Until C8 ships the full Stall Chart editor, counts can be em-dash
 * placeholders if no chart data exists yet (Dashboard em-dash precedent).
 *
 * Args:
 *   kind             string  'stall' or 'lot' — used for label text
 *   row_count        int     number of rows
 *   total_count      int     total stalls/lots
 *   blocked_count    int     blocked count (optional, can be 0)
 *   row_breakdown    array   list of ['label' => 'Red Barn Row A',
 *                            'count' => 20, 'blocked' => 2]
 *   manage_label     string  Button text (e.g. 'Manage Stall Layout')
 *   manage_url       string  href for the stub button (C8 page slug)
 *   hint             string  Field-hint copy under the widget
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'eem_render_editor_layout_summary' ) ) {
	function eem_render_editor_layout_summary( array $args ) {
		$d = array_merge( array(
			'kind'           => 'stall',
			'row_count'      => 0,
			'total_count'    => 0,
			'blocked_count'  => 0,
			'row_breakdown'  => array(),
			'manage_label'   => __( 'Manage Layout', 'equine-event-manager' ),
			'manage_url'     => '#',
			'hint'           => '',
		), $args );

		$unit_singular = 'stall' === $d['kind'] ? __( 'stall',  'equine-event-manager' ) : __( 'lot',  'equine-event-manager' );
		$unit_plural   = 'stall' === $d['kind'] ? __( 'stalls', 'equine-event-manager' ) : __( 'lots', 'equine-event-manager' );
		$rows_label    = sprintf( _n( '%d row',  '%d rows',  (int) $d['row_count'],   'equine-event-manager' ), (int) $d['row_count'] );
		$total_label   = sprintf( _n( '%d %s total', '%d %s total', (int) $d['total_count'], 'equine-event-manager' ), (int) $d['total_count'], (int) $d['total_count'] === 1 ? $unit_singular : $unit_plural );
		$blocked_label = sprintf( _n( '%d blocked', '%d blocked', (int) $d['blocked_count'], 'equine-event-manager' ), (int) $d['blocked_count'] );
		?>
		<div class="eem-layout-summary">
			<div class="eem-layout-summary-left">
				<div class="eem-layout-summary-stat">
					<?php
					if ( (int) $d['row_count'] > 0 ) {
						echo '<span class="eem-layout-summary-stat-num">' . esc_html( $rows_label ) . '</span>';
						echo ' &nbsp;·&nbsp; <span class="eem-layout-summary-stat-num">' . esc_html( $total_label ) . '</span>';
						if ( (int) $d['blocked_count'] > 0 ) {
							echo ' &nbsp;·&nbsp; <span class="eem-layout-summary-stat-num">' . esc_html( $blocked_label ) . '</span>';
						}
					} else {
						echo '<span class="eem-layout-summary-stat-num">—</span>';
						esc_html_e( ' layout not configured yet', 'equine-event-manager' );
					}
					?>
				</div>
				<?php if ( ! empty( $d['row_breakdown'] ) ) : ?>
					<div class="eem-layout-summary-meta">
						<?php foreach ( (array) $d['row_breakdown'] as $row ) :
							$rl  = isset( $row['label'] ) ? (string) $row['label'] : '';
							$rc  = isset( $row['count'] ) ? (int) $row['count'] : 0;
							$rb  = isset( $row['blocked'] ) ? (int) $row['blocked'] : 0;
							if ( '' === $rl ) continue;
							?>
							<span>
								<strong><?php echo esc_html( $rl ); ?></strong>
								&middot; <?php echo esc_html( sprintf( _n( '%d ' . $unit_singular, '%d ' . $unit_plural, $rc, 'equine-event-manager' ), $rc ) ); ?>
								<?php if ( $rb > 0 ) : ?>
									(<?php echo esc_html( sprintf( _n( '%d blocked', '%d blocked', $rb, 'equine-event-manager' ), $rb ) ); ?>)
								<?php endif; ?>
							</span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<a class="eem-btn-manage-layout" href="<?php echo esc_url( $d['manage_url'] ); ?>" data-eem-stub-c8>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
				<?php echo esc_html( $d['manage_label'] ); ?>
			</a>
		</div>
		<?php if ( '' !== $d['hint'] ) : ?>
			<span class="eem-field-hint"><?php echo esc_html( $d['hint'] ); ?></span>
		<?php endif; ?>
		<?php
	}
}
