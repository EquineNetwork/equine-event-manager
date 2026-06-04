<?php
/**
 * C13.C.1 smoke — EEM_Order_Adjustments_Repo data layer.
 *
 * Exercises the en_order_adjustments storage + discount math end-to-end:
 * custom-item insert/replace/read-back, discount set/replace/remove (at most
 * one per order), the resolve_discount_amount clamp rules, and get_for_order
 * aggregation. Uses a throwaway order_number so it leaves no fixture residue.
 *
 * Run: wp eval-file tests/smoke/c13c1-order-adjustments-smoke.php
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

global $wpdb;
$table = $wpdb->prefix . 'en_order_adjustments';
$order = 'SMOKE-C13C1-' . wp_generate_password( 8, false );

// --- 0. Table exists -------------------------------------------------------
$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
$check( 'en_order_adjustments table exists', $table_exists );

$check( 'repo class loaded', class_exists( 'EEM_Order_Adjustments_Repo' ) );

// --- 1. Custom item insert + read-back -------------------------------------
$id = EEM_Order_Adjustments_Repo::insert_custom_item( $order, 'Late arrival fee', 25.00 );
$check( 'insert_custom_item returns row id', is_int( $id ) && $id > 0 );

$items = EEM_Order_Adjustments_Repo::get_custom_items( $order );
$check( 'one custom item read back', count( $items ) === 1 );
$check( 'custom item description round-trips', isset( $items[0]['description'] ) && 'Late arrival fee' === $items[0]['description'] );
$check( 'custom item amount round-trips', isset( $items[0]['amount'] ) && abs( $items[0]['amount'] - 25.00 ) < 0.001 );

// Empty description is rejected.
$bad = EEM_Order_Adjustments_Repo::insert_custom_item( $order, '   ', 10.00 );
$check( 'empty-description custom item rejected', false === $bad );

// Negative amount (credit) is allowed.
EEM_Order_Adjustments_Repo::insert_custom_item( $order, 'Transferred credit', -15.00 );
$items = EEM_Order_Adjustments_Repo::get_custom_items( $order );
$check( 'negative-amount credit stored', count( $items ) === 2 );

// --- 2. replace_custom_items clears + re-inserts ---------------------------
$n = EEM_Order_Adjustments_Repo::replace_custom_items( $order, array(
	array( 'description' => 'Damage charge', 'amount' => 40.00 ),
	array( 'description' => 'Comp', 'amount' => -5.00 ),
) );
$check( 'replace_custom_items returns inserted count', 2 === $n );
$items = EEM_Order_Adjustments_Repo::get_custom_items( $order );
$check( 'replace cleared prior items (exactly 2 remain)', count( $items ) === 2 );
$check( 'replace ordering preserved (Damage charge first)', 'Damage charge' === $items[0]['description'] );

// --- 3. resolve_discount_amount math + clamps ------------------------------
$r = EEM_Order_Adjustments_Repo::resolve_discount_amount( 'percent', 10.0, 100.0 );
$check( '10% of 100 = 10.00', abs( $r - 10.00 ) < 0.001 );
$r = EEM_Order_Adjustments_Repo::resolve_discount_amount( 'dollar', 30.0, 100.0 );
$check( '$30 dollar discount = 30.00', abs( $r - 30.00 ) < 0.001 );
$r = EEM_Order_Adjustments_Repo::resolve_discount_amount( 'dollar', 500.0, 100.0 );
$check( 'discount clamped to subtotal (500 -> 100)', abs( $r - 100.00 ) < 0.001 );
$r = EEM_Order_Adjustments_Repo::resolve_discount_amount( 'percent', 150.0, 100.0 );
$check( '150% clamped to subtotal (100)', abs( $r - 100.00 ) < 0.001 );
$r = EEM_Order_Adjustments_Repo::resolve_discount_amount( 'percent', -5.0, 100.0 );
$check( 'negative discount value -> 0', abs( $r ) < 0.001 );

// --- 4. set_discount snapshots resolved amount + at-most-one ----------------
$did = EEM_Order_Adjustments_Repo::set_discount( $order, 'percent', 10.0, 'First-time customer', 200.0 );
$check( 'set_discount returns row id', is_int( $did ) && $did > 0 );
$d = EEM_Order_Adjustments_Repo::get_discount( $order );
$check( 'discount read back', is_array( $d ) );
$check( 'discount type round-trips', isset( $d['type'] ) && 'percent' === $d['type'] );
$check( 'discount value round-trips', isset( $d['value'] ) && abs( $d['value'] - 10.0 ) < 0.001 );
$check( 'discount reason round-trips', isset( $d['reason'] ) && 'First-time customer' === $d['reason'] );
$check( 'discount amount snapshotted (10% of 200 = 20)', isset( $d['amount'] ) && abs( $d['amount'] - 20.00 ) < 0.001 );

// Reason required.
$bad_d = EEM_Order_Adjustments_Repo::set_discount( $order, 'dollar', 10.0, '   ', 200.0 );
$check( 'empty-reason discount rejected', false === $bad_d );

// Replacing keeps at most one discount row.
EEM_Order_Adjustments_Repo::set_discount( $order, 'dollar', 15.0, 'Adjusted', 200.0 );
$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_number = %s AND kind = %s", $order, 'discount' ) );
$check( 'at most one discount row after replace', 1 === $rows );
$d = EEM_Order_Adjustments_Repo::get_discount( $order );
$check( 'replacement discount is the current one ($15 dollar)', 'dollar' === $d['type'] && abs( $d['value'] - 15.0 ) < 0.001 );

// --- 5. get_for_order aggregation ------------------------------------------
$bundle = EEM_Order_Adjustments_Repo::get_for_order( $order );
$check( 'get_for_order returns custom_items', isset( $bundle['custom_items'] ) && count( $bundle['custom_items'] ) === 2 );
$check( 'get_for_order returns discount', isset( $bundle['discount'] ) && is_array( $bundle['discount'] ) );
$check( 'custom_items_total = 40 + (-5) = 35', isset( $bundle['custom_items_total'] ) && abs( $bundle['custom_items_total'] - 35.00 ) < 0.001 );

// --- 6. remove_discount -----------------------------------------------------
$check( 'remove_discount returns true', EEM_Order_Adjustments_Repo::remove_discount( $order ) === true );
$check( 'discount gone after remove', null === EEM_Order_Adjustments_Repo::get_discount( $order ) );
$check( 'custom items survive discount removal', count( EEM_Order_Adjustments_Repo::get_custom_items( $order ) ) === 2 );

// --- 7. delete_for_order cleanup -------------------------------------------
$deleted = EEM_Order_Adjustments_Repo::delete_for_order( $order );
$check( 'delete_for_order removes remaining rows', $deleted >= 2 );
$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_number = %s", $order ) );
$check( 'no rows remain for test order', 0 === $remaining );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) {
	exit( 1 );
}
