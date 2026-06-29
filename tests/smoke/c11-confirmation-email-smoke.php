<?php
/**
 * C11 smoke — Customer Confirmation Email.
 *
 * Renders build_confirmation_email_html() against a synthetic order payload and
 * asserts content-density per card (not just card presence), plus the
 * decision-locked omissions (no assignments, no PDF note) and that
 * EEM_Mailer::inline_css() actually inlines the <style> block.
 *
 * Run: wp eval-file tests/smoke/c11-confirmation-email-smoke.php
 *
 * Source-presence assertions only for layout chrome — the render path is
 * exercised end-to-end via the real builder + real Emogrifier inliner.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0;
$pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) {
		$pass++;
		WP_CLI::log( "  ok  — {$label}" );
	} else {
		$fail++;
		WP_CLI::warning( "FAIL — {$label}" );
	}
};

// ── Seed a lightweight reservation so reservation-meta-derived sections
//    (Required Shavings price, Event Day Info, Cancellation Policy) compute. ──
$rid = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'publish',
	'post_title'  => '2026 Southeast Region Super Sort',
) );
update_post_meta( $rid, '_en_required_shavings_price', '22.00' );
update_post_meta( $rid, '_en_event_day_enabled', 1 );
update_post_meta( $rid, '_en_event_day_checkin', 'Check in at the stall office' );
update_post_meta( $rid, '_en_event_day_bring', 'Health and Coggins certificates' );
update_post_meta( $rid, '_en_event_day_parking', 'Truck and trailer parking in Silver Lot' );
update_post_meta( $rid, '_en_event_day_contact', '555-555-5555' );
// #55: the cancellation resolver reads the canonical _eem_-prefixed override key
// (eem-cancellation-policy.php), not _en_.
update_post_meta( $rid, '_eem_cancellation_policy_override', 'Full refund 14+ days before the event; non-refundable within 14 days.' );

// #55: the canonical stall breakdown reads the shavings PRICE from the config
// table and the shavings QTY from the order's stall component ROW
// (eem_stall_reservations), NOT the top-level order fields — so the synthetic
// order must carry a real stall row + a config price for the "Required Shavings"
// line to split out of the stall base.
// Seeding a config row activates the config-table read path for this
// reservation, so seed EVERY field the email reads (event-day info +
// cancellation override) there too — otherwise those fields read empty.
if ( class_exists( 'EEM_Reservation_Config' ) ) {
	EEM_Reservation_Config::for( (int) $rid )
		->set_many( array(
			'required_shavings_price'        => 22.00,
			'event_day_enabled'              => 1,
			'event_day_checkin'              => 'Check in at the stall office',
			'event_day_bring'                => 'Health and Coggins certificates',
			'event_day_parking'              => 'Truck and trailer parking in Silver Lot',
			'event_day_contact'              => '555-555-5555',
			'cancellation_policy_override'   => 'Full refund 14+ days before the event; non-refundable within 14 days.',
		) )
		->save();
	EEM_Reservation_Config::flush_cache( (int) $rid );
}
global $wpdb;
$wpdb->insert( $wpdb->prefix . 'eem_stall_reservations', array( // phpcs:ignore WordPress.DB
	'event_source'            => 'native',
	'reservation_id'          => (int) $rid,
	'customer_name'           => 'Whitney Mitchell',
	'email'                   => 'whitney@eem-test.local',
	'stall_qty'               => 1,
	'required_shavings_qty'   => 2,
	'additional_shavings_qty' => 0,
	'arrival_date'            => '2026-05-08',
	'departure_date'          => '2026-05-10',
	'subtotal'                => '64.00',
	'total'                   => '64.00',
	'amount_paid'             => '64.00', // #55: paid row records collected amount (mig-029 invariant)
	'payment_status'          => 'paid',
	'order_number'            => '42',
) );
$c11_stall_row_id = (int) $wpdb->insert_id;

// ── Synthetic order payload (EEM_Orders_Repository grouped shape) ──
$order = array(
	'order_key'            => 'test-c11-key',
	'order_number'         => '42',
	'created_at'           => '2026-04-24 10:30:00',
	'reservation_id'       => $rid,
	'reservation_title'    => '2026 Southeast Region Super Sort',
	'event_name'           => '2026 Southeast Region Super Sort',
	'event_dates'          => 'May 8, 2026 – May 10, 2026',
	'customer_name'        => 'Whitney Mitchell',
	'total'                => 170.56,
	'fees'                 => 6.56,
	'transaction_id'       => 'ch_test_123',
	'notes'                  => '',
	'components'             => array( array( 'table' => 'stall', 'row_id' => $c11_stall_row_id ) ),
	'stall_quantity'         => 1,
	'stall_subtotal'         => 64.00, // 20 stall base + 44 required shavings
	'stall_arrival_date'     => '2026-05-08',
	'stall_departure_date'   => '2026-05-10',
	'stall_stay_type'        => 'nightly',
	'required_shavings_qty'  => 2,
	'required_shavings_price'=> 22.00,
	'additional_shavings_qty'=> 0,
	'rv_quantity'            => 1,
	'rv_arrival_date'        => '2026-05-08',
	'rv_departure_date'      => '2026-05-10',
	'rv_stay_type'           => 'nightly',
	'rv_subtotal'            => 40.00,
	'rv_type'                => '',
);

$shortcodes = new EEM_Shortcodes();
$ref        = new ReflectionMethod( 'EEM_Shortcodes', 'build_confirmation_email_html' );
$ref->setAccessible( true );
$html = (string) $ref->invoke( $shortcodes, $order );

WP_CLI::log( 'Rendered HTML length: ' . strlen( $html ) );

// ── Header / confirm bar content density ──
$check( 'header shows event title', false !== strpos( $html, '2026 Southeast Region Super Sort' ) );
$check( 'header shows event dates', false !== strpos( $html, 'May 8, 2026' ) );
$check( 'confirm bar shows 5-digit order number #00042', false !== strpos( $html, '#00042' ) );
$check( 'confirm bar shows payment date Apr 24', false !== strpos( $html, 'April 24, 2026' ) );
$check( 'confirm bar shows amount paid $170.56', false !== strpos( $html, '$170.56' ) );

// ── Greeting ──
$check( 'greeting uses first name only (Whitney, not full name)', false !== strpos( $html, '<strong>Whitney</strong>' ) );

// ── Type badges ──
$check( 'stall badge present', false !== strpos( $html, 'type-stall' ) );
$check( 'rv badge present', false !== strpos( $html, 'type-rv' ) );

// ── Line items: section + non-empty content per row ──
$check( 'line item: Stall Res. row', false !== strpos( $html, 'Stall Res.' ) );
$check( 'line item: Required Shavings row (Stall Product)', false !== strpos( $html, 'Required Shavings' ) && false !== strpos( $html, 'Stall Product' ) );
$check( 'line item: RV Res. row', false !== strpos( $html, 'RV Res.' ) );
$check( 'line item: Convenience Fee row', false !== strpos( $html, 'Convenience Fee' ) );
$check( 'line item table has Section/Description/Qty/Units/Rate/Total head', false !== strpos( $html, '>Units<' ) && false !== strpos( $html, '>Rate<' ) );

// ── Totals ──
$check( 'totals row shows Total Paid', false !== strpos( $html, 'Total Paid' ) );

// ── Decision-locked omissions ──
$check( 'OMIT: no "Your Assignments" section (nothing assigned)', false === strpos( $html, 'Your Assignments' ) );
$check( 'OMIT: no PDF attachment note (deferred to C12)', false === strpos( $html, 'PDF Receipt Attached' ) );

// ── Support block (depends on company settings; assert structure regardless) ──
$check( 'support block heading present', false !== strpos( $html, 'Questions about your reservation?' ) || false === strpos( $html, 'support-block' ) );

// ── Footer ──
$check( 'footer shows event line', false !== strpos( $html, 'footer-event' ) );

// ── CSS inlining round-trip ──
$inlined = EEM_Mailer::inline_css( $html );
$check( 'inline_css produced an inline style attribute', false !== strpos( $inlined, 'style="' ) );
// #55: the .header-event navy was retuned to #0d1b3e (confirmation.php:48).
$check( 'inline_css carried .header-event navy color (#0d1b3e) inline', false !== stripos( $inlined, '0d1b3e' ) && preg_match( '/style="[^"]*0d1b3e/i', $inlined ) === 1 );

// ── POSITIVE: reservation-meta-derived sections render with real content ──
$check( 'line item: Required Shavings row splits out (Stall Product)', false !== strpos( $html, 'Required Shavings' ) && false !== strpos( $html, 'Stall Product' ) );
$check( "What's Next section present", false !== strpos( $html, 'Event Day Info' ) );
$check( "What's Next: check-in content", false !== strpos( $html, 'Check in at the stall office' ) );
$check( "What's Next: what-to-bring content", false !== strpos( $html, 'Health and Coggins certificates' ) );
$check( "What's Next: parking content", false !== strpos( $html, 'Silver Lot' ) );
$check( "What's Next: event contact content", false !== strpos( $html, '555-555-5555' ) );
$check( 'Cancellation Policy present with override text', false !== strpos( $html, 'Cancellation Policy' ) && false !== strpos( $html, 'Full refund 14+ days' ) );

// ── OMIT: same order with no reservation hides the gated sections ──
$order_ed = $order;
$order_ed['reservation_id'] = 0;
$html_ed = (string) $ref->invoke( $shortcodes, $order_ed );
$check( "OMIT: What's Next hidden when event_day unavailable", false === strpos( $html_ed, 'Event Day Info' ) );
$check( 'OMIT: Cancellation Policy hidden when override empty', false === strpos( $html_ed, 'Cancellation Policy' ) );

// ── C12: PDF attachment note toggles with the pdf_attached flag ──
$html_pdf    = (string) $ref->invoke( $shortcodes, $order, true );
$html_no_pdf = (string) $ref->invoke( $shortcodes, $order, false );
$check( 'PDF note shown when a PDF is attached', false !== strpos( $html_pdf, 'PDF Receipt Attached' ) );
$check( 'PDF note hidden when no PDF attached', false === strpos( $html_no_pdf, 'PDF Receipt Attached' ) );

// ── Cleanup ──
wp_delete_post( $rid, true );

WP_CLI::log( "\n=== C11 smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) {
	WP_CLI::error( "{$fail} assertion(s) failed." );
}
WP_CLI::success( 'C11 confirmation email smoke passed.' );
