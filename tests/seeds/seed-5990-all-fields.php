<?php
/**
 * Seed reservation 5990 (2026 Southeast Region Super Sort) with data for EVERY
 * editor-form field so the whole customer-facing form renders fully populated.
 *
 * Run: wp eval-file tests/seeds/seed-5990-all-fields.php
 */
if ( ! function_exists( 'get_option' ) ) { fwrite( STDERR, "run via wp eval-file\n" ); return; }

$rid = 5990;
$set = function ( $key, $value ) use ( $rid ) { update_post_meta( $rid, '_en_' . $key, $value ); };

/* ── Sections enabled ── */
$set( 'stalls_enabled', 1 );
$set( 'rv_enabled', 1 );
$set( 'general_addons_enabled', 1 );
$set( 'group_reservations_enabled', 1 );
$set( 'convenience_fee_enabled', 1 );
$set( 'venue_agreement_enabled', 1 );
$set( 'venue_map_enabled', 0 ); // needs a real attachment; leave off
$set( 'event_day_enabled', 1 );
$set( 'cancellation_enabled', 1 );
$set( 'checkin_checkout_enabled', 1 );
$set( 'rv_addons_enabled', 1 );

/* ── Description / check-in-out ── */
$set( 'reservation_description', 'Welcome to the 2026 Southeast Region Super Sort! Stalls and RV hookups are first-come, first-served. Please review the schedule and book early — premium barns sell out fast.' );
$set( 'checkin_time', '14:00' );
$set( 'checkout_time', '11:00' );

/* ── Event Day Info ── */
$set( 'event_day_checkin', 'Check in at the show office (Barn A entrance) starting Friday at 2:00 PM. Bring your confirmation email.' );
$set( 'event_day_bring', 'Negative Coggins (within 12 months), proof of vaccination, your own buckets and muck tools.' );
$set( 'event_day_parking', 'Trailer parking is in the North Lot. Overnight rigs must have an RV reservation — no dry-camping in the trailer lot.' );
$set( 'event_day_contact', 'Show office: (559) 393-5352 · After-hours grounds: (559) 555-0142' );

/* ── Stall config: Numbered + Pick-from-layout (a 12-stall map is connected) ── */
$set( 'stall_inventory_type', 'numbered' );
$set( 'stall_customer_selection', 'pick_layout' );
$set( 'stall_nightly_rate', '45.00' );
$set( 'stall_weekend_rate', '120.00' );
$set( 'stall_weekend_enabled', 1 );
$set( 'stall_max_per_customer', '4' );
$set( 'stall_tack_mode', 'customer' );
$set( 'required_shavings_enabled', 1 );
$set( 'required_shavings_per_stall', 1 );
$set( 'required_shavings_price', '9.00' );
$set( 'additional_shavings_enabled', 1 );
$set( 'additional_shavings_description', 'Extra bag of shavings' );
$set( 'additional_shavings_price', '9.00' );
$set( 'blocked_stalls', array( '11', '12' ) );
$set( 'stall_early_bird_enabled', 1 );
$set( 'stall_early_bird_cutoff', '2026-06-01' );
$set( 'stall_early_bird_nightly_rate', '38.00' );
$set( 'stall_early_bird_weekend_rate', '100.00' );

/* ── RV config: Mapped + Pick-from-layout (a 12-lot map is connected) ── */
$set( 'rv_inventory_type', 'mapped' );
$set( 'rv_customer_selection', 'pick_layout' );
$set( 'rv_nightly_rate', '55.00' );
$set( 'rv_weekend_rate', '140.00' );
$set( 'rv_weekend_enabled', 1 );
$set( 'rv_max_per_customer', '2' );
$set( 'blocked_rv_lots', array( 'Zone 1 12' ) );
$set( 'rv_lot_zones', array(
	array( 'name' => 'Zone 1', 'color' => '#c0392b', 'nightly' => '15.00', 'weekend' => '30.00' ),
) );

/* ── Add-ons (general + RV) ── */
$set( 'general_addons', array(
	array( 'name' => 'Golf Cart Rental', 'price' => '75.00' ),
	array( 'name' => 'Extra Wristband', 'price' => '15.00' ),
) );
$set( 'rv_addons', array(
	array( 'name' => '50-amp Hookup Upgrade', 'price' => '20.00' ),
	array( 'name' => 'Sewer Hookup', 'price' => '25.00' ),
) );

/* ── Group reservation ── */
$set( 'group_description', 'Travelling with a barn or team? Enter your group name so we place you together.' );
$set( 'group_riders_per_group', '8' );
$set( 'group_rider_grounds_fee_enabled', 1 );
$set( 'group_rider_grounds_fee_amount', '25.00' );
$set( 'group_rider_deposit_enabled', 1 );
$set( 'group_rider_deposit_amount', '100.00' );

/* ── Fees ── */
$set( 'convenience_fee_label', 'Non-Refundable Convenience Fee' );
$set( 'convenience_fee_type', 'percent' );
$set( 'convenience_fee_value', '3.5' );

/* ── Agreement ── */
$set( 'venue_agreement_label', 'I agree to the venue terms, release of liability, and facility rules.' );
$set( 'venue_agreement_text', "By reserving, you agree to: (1) provide a current negative Coggins; (2) clean your stall before departure; (3) hold the venue harmless for injury or loss; (4) follow all posted speed limits and quiet hours (10 PM–6 AM)." );

/* ── Cancellation ── */
$set( 'cancellation_policy_override', 'Full refund (minus the convenience fee) if cancelled 14+ days before arrival. 50% refund 7–13 days out. No refund within 7 days of the event.' );

/* ── Special requests handled at event level; nothing to seed ── */

echo "Seeded all editor fields on reservation $rid.\n";
echo "stalls_enabled=" . get_post_meta( $rid, '_en_stalls_enabled', true ) . " rv_enabled=" . get_post_meta( $rid, '_en_rv_enabled', true ) . "\n";
