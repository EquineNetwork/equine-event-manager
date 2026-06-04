<?php
/**
 * C13.C.2 smoke — Create Order adjustment collection + persistence.
 *
 * Exercises the POST-collection helpers (collect_custom_items_from_post,
 * collect_discount_from_post) and persist_adjustments against a synthetic order
 * key, then reads the result back through the canonical consumers:
 *  - EEM_Order_Adjustments_Repo::get_for_order (the render-time consumer)
 *  - EEM_Activity_Log::get_for_order_key (the discount-log consumer)
 *
 * Covers the discount-reason-required gate, the $_POST array shape the JS sends,
 * negative-amount custom items, and the subtotal-based discount resolution.
 *
 * Run: wp eval-file tests/smoke/c13c2-create-order-adjustments-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run via wp eval-file\n" );
	exit( 1 );
}

$passed = 0;
$failed = 0;
$check  = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) {
		$passed++;
		echo "  ok  - {$label}\n";
	} else {
		$failed++;
		echo "FAIL  - {$label}\n";
	}
};

$check( 'create-order page class loaded', class_exists( 'EEM_Create_Order_Page' ) );

$ref = new ReflectionClass( 'EEM_Create_Order_Page' );
$collect_items = $ref->getMethod( 'collect_custom_items_from_post' );
$collect_items->setAccessible( true );
$collect_disc = $ref->getMethod( 'collect_discount_from_post' );
$collect_disc->setAccessible( true );
$persist = $ref->getMethod( 'persist_adjustments' );
$persist->setAccessible( true );

// Simulate a logged-in admin so activity-log actor + created_by populate.
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! empty( $admin ) ) {
	wp_set_current_user( (int) $admin[0] );
}

// --- 1. collect_custom_items_from_post parses the parallel-array JS shape ----
$_POST['custom_item_desc']   = array( 'Late arrival fee', '   ', 'Transferred credit' );
$_POST['custom_item_amount'] = array( '25.00', '99', '-15.50' );
$items = $collect_items->invoke( null );
$check( 'empty-description rows dropped (2 of 3 kept)', count( $items ) === 2 );
$check( 'first item description parsed', 'Late arrival fee' === $items[0]['description'] );
$check( 'index-aligned negative amount preserved', abs( $items[1]['amount'] - ( -15.50 ) ) < 0.001 );

// No custom-items key at all -> empty array (not a warning).
unset( $_POST['custom_item_desc'], $_POST['custom_item_amount'] );
$check( 'missing custom_item_desc -> empty array', $collect_items->invoke( null ) === array() );

// --- 2. collect_discount_from_post -----------------------------------------
$_POST['eem_discount_value']  = '0';
$check( 'zero discount value -> null (no discount)', null === $collect_disc->invoke( null ) );

$_POST['eem_discount_type']   = 'percent';
$_POST['eem_discount_value']  = '10';
$_POST['eem_discount_reason'] = 'First-time customer';
$d = $collect_disc->invoke( null );
$check( 'discount collected when value > 0', is_array( $d ) );
$check( 'discount type parsed', 'percent' === $d['type'] );
$check( 'discount value parsed', abs( $d['value'] - 10.0 ) < 0.001 );
$check( 'discount reason parsed', 'First-time customer' === $d['reason'] );

// Unknown type coerces to dollar.
$_POST['eem_discount_type'] = 'bogus';
$d2 = $collect_disc->invoke( null );
$check( 'unknown discount type coerces to dollar', 'dollar' === $d2['type'] );

// Reason-required gate: discount present, reason empty -> reason === ''
$_POST['eem_discount_type']   = 'dollar';
$_POST['eem_discount_value']  = '20';
$_POST['eem_discount_reason'] = '   ';
$d3 = $collect_disc->invoke( null );
$check( 'empty reason surfaces as empty string (handler rejects)', is_array( $d3 ) && '' === $d3['reason'] );

// --- 3. persist_adjustments end-to-end -> read back via repo ----------------
// Order keys must fit order_number varchar(20) (matches the component tables).
$order_key = 'SMK-C2-' . wp_generate_password( 8, false );
$persist_items = array(
	array( 'description' => 'Late arrival fee', 'amount' => 25.00 ),
	array( 'description' => 'Damage charge', 'amount' => 40.00 ),
);
$persist_disc = array( 'type' => 'percent', 'value' => 10.0, 'reason' => 'First-time customer' );

$persist->invoke( null, $order_key, $persist_items, $persist_disc );

$bundle = EEM_Order_Adjustments_Repo::get_for_order( $order_key );
$check( 'custom items persisted + read back (2)', count( $bundle['custom_items'] ) === 2 );
$check( 'custom_items_total = 65', abs( $bundle['custom_items_total'] - 65.00 ) < 0.001 );
$check( 'discount persisted', is_array( $bundle['discount'] ) );
// No order rows exist for this synthetic key, so subtotal = custom items (65); 10% = 6.50.
$check( 'discount resolved against custom-item subtotal (10% of 65 = 6.50)', isset( $bundle['discount']['amount'] ) && abs( $bundle['discount']['amount'] - 6.50 ) < 0.001 );
$check( 'discount reason snapshotted', 'First-time customer' === $bundle['discount']['reason'] );

// --- 4. discount logged + retrievable via canonical consumer ---------------
$log = EEM_Activity_Log::get_for_order_key( $order_key );
$found = false;
foreach ( (array) $log as $entry ) {
	if ( isset( $entry['event_type'] ) && 'order_discount_applied' === $entry['event_type'] ) {
		$found = true;
		$check( 'activity entry carries reason in payload', isset( $entry['payload']['reason'] ) && 'First-time customer' === $entry['payload']['reason'] );
		$check( 'activity entry actor_type admin', isset( $entry['actor_type'] ) && 'admin' === $entry['actor_type'] );
		break;
	}
}
$check( 'discount_applied entry retrievable via get_for_order_key', $found );

// --- 5. persist with null discount leaves no discount row ------------------
$order_key2 = 'SMK-C2B-' . wp_generate_password( 8, false );
$persist->invoke( null, $order_key2, array( array( 'description' => 'Solo item', 'amount' => 10.0 ) ), null );
$b2 = EEM_Order_Adjustments_Repo::get_for_order( $order_key2 );
$check( 'null discount -> no discount row', null === $b2['discount'] );
$check( 'custom item still persisted with null discount', count( $b2['custom_items'] ) === 1 );

// --- cleanup ---------------------------------------------------------------
EEM_Order_Adjustments_Repo::delete_for_order( $order_key );
EEM_Order_Adjustments_Repo::delete_for_order( $order_key2 );
unset( $_POST['eem_discount_type'], $_POST['eem_discount_value'], $_POST['eem_discount_reason'] );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) {
	exit( 1 );
}
