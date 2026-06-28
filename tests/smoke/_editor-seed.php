<?php
/**
 * Shared editor-render seed helper (#55).
 *
 * The reservation-editor render path reads section config from the
 * eem_reservation_config TABLE (post mig-016) and HARD-GATES section rendering on
 * a linked event. Many older editor-render smokes still seed everything via
 * update_post_meta('_en_*'), which the editor no longer reads — so their sections
 * never render and every structural assertion fails.
 *
 * Call eem_smoke_setup_editor( $reservation_id ) AFTER the smoke's existing
 * post-meta seed. It mirrors those `_en_*` values into the config table, ensures
 * the event-link gate is satisfied, and forces the active event source to 'feed'
 * for the current request only (a read-filter, NOT update_option, so it never
 * leaks into other smokes running against the shared DB).
 *
 * @package EEM_Plugin
 */

if ( ! function_exists( 'eem_smoke_setup_editor' ) ) {
	/**
	 * Make a reservation render its editor section bodies under the current code.
	 *
	 * @param int $reservation_id Reservation post ID (already created + post-meta seeded).
	 * @return void
	 */
	function eem_smoke_setup_editor( $reservation_id ) {
		if ( ! class_exists( 'EEM_Reservation_Config' ) ) {
			return;
		}

		// Active source = feed for THIS request only (no DB write → no leakage).
		add_filter( 'option_equine_event_manager_event_source', 'eem_smoke_force_feed_source', 99 );
		add_filter( 'pre_option_equine_event_manager_event_source', 'eem_smoke_force_feed_source', 99 );

		$cfg = EEM_Reservation_Config::for( $reservation_id );

		// Mirror every `_en_*` post-meta the smoke set into the config field of the
		// same name (minus the prefix). Unknown keys are tolerated by set().
		foreach ( get_post_meta( $reservation_id ) as $key => $vals ) {
			if ( 0 === strpos( (string) $key, '_en_' ) && isset( $vals[0] ) ) {
				$cfg->set( substr( (string) $key, 4 ), maybe_unserialize( $vals[0] ) );
			}
		}

		// Satisfy the linked-event hard gate if the smoke didn't set a link.
		if ( '' === (string) $cfg->get( 'external_event_id', '' ) ) {
			$cfg->set( 'external_event_id', 'ext-smoke-' . (int) $reservation_id )
				->set( 'external_event_name', 'Smoke Event' );
		}

		$cfg->save();
		EEM_Reservation_Config::flush_cache( $reservation_id );
	}

	/**
	 * Read-filter callback that forces the feed event source for the request.
	 *
	 * @return string
	 */
	function eem_smoke_force_feed_source() {
		return 'feed';
	}
}
