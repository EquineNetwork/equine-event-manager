<?php
/**
 * Stall-pick "confirm, then allow" guard.
 *
 * In pick-your-stalls mode, if the customer is reserving stalls but hasn't
 * picked any specific units, Reserve Now must pop a confirm before falling back
 * to auto-assign (Whitney's chosen behavior for the "didn't pick a stall" bug).
 * Implemented as a capture-phase submit listener (bindStallPickConfirm) so it
 * runs before the Stripe bubble handler.
 *
 * Asserts the wiring is present in the rendered pick-mode form, and that a
 * non-pick (quantity-mode) form does NOT render the picker (so the confirm is
 * correctly scoped to pick mode only).
 */

$pass = 0; $fail = 0; $log = array();
function sok( $label, $cond, &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; } else { $fail++; $log[] = "FAIL: $label"; }
}

$sc = new EEM_Shortcodes();

// ---- Pick-mode reservation (exact_map) ----
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Stall Pick Confirm Smoke' ) );
update_post_meta( $rid, '_en_event_source', 'feed' );
update_post_meta( $rid, '_en_use_global_event_source', 0 );
update_post_meta( $rid, '_en_external_event_id', 'ext-stall-pick-confirm' );
update_post_meta( $rid, '_en_external_event_title', 'Stall Pick Confirm Event' );
update_post_meta( $rid, '_en_stalls_enabled', 1 );
update_post_meta( $rid, '_en_stall_chart_enabled', 1 );
update_post_meta( $rid, '_en_stall_inventory_type', 'numbered' );
update_post_meta( $rid, '_en_stall_customer_selection', 'pick_layout' );
update_post_meta( $rid, '_en_stall_selection_mode', 'exact_map' );
update_post_meta( $rid, '_en_stall_rows', array(
	array( 'name' => 'Barn A', 'layout' => 'one-sided', 'first' => '100', 'last' => '108' ),
) );

// ---- Quantity-mode reservation (no picker) ----
$qid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Stall Qty Mode Smoke' ) );
update_post_meta( $qid, '_en_event_source', 'feed' );
update_post_meta( $qid, '_en_use_global_event_source', 0 );
update_post_meta( $qid, '_en_external_event_id', 'ext-stall-qty-mode' );
update_post_meta( $qid, '_en_external_event_title', 'Stall Qty Mode Event' );
update_post_meta( $qid, '_en_stalls_enabled', 1 );
update_post_meta( $qid, '_en_stall_quantity_available', 20 );

register_shutdown_function( static function () use ( $rid, $qid ) {
	if ( $rid ) { wp_delete_post( (int) $rid, true ); }
	if ( $qid ) { wp_delete_post( (int) $qid, true ); }
} );

$form_html = $sc->render_reservation( array( 'id' => $rid ) );

// The form's JS (init loop + bindStallPickConfirm) is printed in the footer via
// render_frontend_form_assets_in_footer(), not in render_reservation()'s return.
// Capture it and combine so the JS-wiring assertions can see it.
ob_start();
$sc->render_frontend_form_assets_in_footer();
$footer_js = ob_get_clean();
$html = $form_html . $footer_js;

// Wiring present (function defined + called in the per-form init loop).
sok( 'bindStallPickConfirm function defined',  false !== strpos( $html, 'function bindStallPickConfirm(form)' ), $pass, $fail, $log );
sok( 'bindStallPickConfirm called in init loop', false !== strpos( $html, 'bindStallPickConfirm(form);' ),        $pass, $fail, $log );

// Confirm copy + capture-phase listener present.
sok( 'confirm copy present',                   false !== strpos( $html, "auto-assign them for you after checkout" ), $pass, $fail, $log );
sok( 'reads stall_qty',                         false !== strpos( $html, 'name="stall_qty"' ) || false !== strpos( $html, "name=\\\"stall_qty\\\"" ) || false !== strpos( $html, 'stall_qty' ), $pass, $fail, $log );
sok( 'counts picked checkboxes (:checked)',     false !== strpos( $html, 'preferred_stall_units[]"]:checked' ),  $pass, $fail, $log );
sok( 'uses capture phase (stopImmediatePropagation)', false !== strpos( $html, 'stopImmediatePropagation' ),     $pass, $fail, $log );

// Pick mode actually renders the picker checkboxes the confirm keys off.
sok( 'pick-mode form renders picker checkboxes', false !== strpos( $html, 'name="preferred_stall_units[]"' ),    $pass, $fail, $log );
sok( 'pick-mode form renders the picker grid',   false !== strpos( $html, 'data-eem-stall-picker' ),             $pass, $fail, $log );

// Quantity mode: no picker checkboxes => confirm is correctly inert there.
$qhtml = $sc->render_reservation( array( 'id' => $qid ) );
sok( 'quantity-mode form does NOT render picker checkboxes', false === strpos( $qhtml, 'name="preferred_stall_units[]"' ), $pass, $fail, $log );

echo "\n=== Stall-pick confirm smoke: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
