<?php
/**
 * Notifications recipient-engine smoke (v2 Notifications, Slice 1).
 *
 * Seeds order component rows (stall/RV) + a division ledger for one event, then
 * exercises EEM_Notifications::resolve_recipients across Include / Exclude /
 * Payment combinations — proving the two canonical asks:
 *   - "everyone who entered the #9.5 Division"  → include = division:{id}
 *   - "RV buyers but NOT stall customers"        → include=rv, exclude=stall
 *
 * Run: wp eval-file tests/smoke/notifications-recipients-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};
$same = static function ( array $a, array $b ): bool {
	$a = array_map( 'strtolower', $a ); $b = array_map( 'strtolower', $b );
	sort( $a ); sort( $b ); return $a === $b;
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

global $wpdb;
$stall_t = $wpdb->prefix . 'en_stall_reservations';
$rv_t    = $wpdb->prefix . 'en_rv_reservations';

// Seed a reservation (event) + a published division on it.
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Notif Smoke Event' ) );
$did = wp_insert_post( array( 'post_type' => 'en_entry', 'post_status' => 'publish', 'post_title' => 'Notif Smoke Event - #9.5 Division' ) );
update_post_meta( $did, EEM_Entries::META_RESERVATION, $rid );
update_post_meta( $did, EEM_Entries::META_DIVISION_NAME, '#9.5 Division' );
update_post_meta( $did, EEM_Entries::META_PRICE, '45.00' );

$note = static function ( $tok ) use ( $rid ) { return "Reservation setup ID: {$rid}\nSubmission token: {$tok}"; };

// Order A — stall + RV in ONE order (shared token), paid.
$wpdb->insert( $stall_t, array( 'reservation_id' => $rid, 'customer_name' => 'A One', 'email' => 'a@example.com', 'stall_qty' => 1, 'payment_status' => 'paid', 'notes' => $note( 'TOKA' ), 'created_at' => current_time( 'mysql' ) ) );
$wpdb->insert( $rv_t,    array( 'reservation_id' => $rid, 'customer_name' => 'A One', 'email' => 'a@example.com', 'rv_qty' => 1, 'payment_status' => 'paid', 'notes' => $note( 'TOKA' ), 'created_at' => current_time( 'mysql' ) ) );
// Order B — RV only, unpaid (pending).
$wpdb->insert( $rv_t,    array( 'reservation_id' => $rid, 'customer_name' => 'B Two', 'email' => 'b@example.com', 'rv_qty' => 1, 'payment_status' => 'pending', 'notes' => $note( 'TOKB' ), 'created_at' => current_time( 'mysql' ) ) );
// Order D — stall only, paid.
$wpdb->insert( $stall_t, array( 'reservation_id' => $rid, 'customer_name' => 'D Four', 'email' => 'd@example.com', 'stall_qty' => 1, 'payment_status' => 'paid', 'notes' => $note( 'TOKD' ), 'created_at' => current_time( 'mysql' ) ) );

// Division ledger: A paid, B unpaid (both also have orders).
EEM_Division_Entries::create_table();
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Division_Entries::table_name() . ' WHERE division_id = %d', $did ) );
EEM_Division_Entries::record_entry( $did, 'TOKA', 'A One', 'a@example.com', 1, 'paid' );
EEM_Division_Entries::record_entry( $did, 'TOKB', 'B Two', 'b@example.com', 1, 'unpaid' );

$div = 'division:' . $did;

// --- divisions list for the dropdown ---------------------------------------
$divs = EEM_Notifications::event_divisions( $rid );
$check( 'event_divisions lists the division', isset( $divs[ $did ] ) && '#9.5 Division' === $divs[ $did ] );

// --- include segments ------------------------------------------------------
$check( 'include=all → A,B,D',        $same( EEM_Notifications::resolve_recipients( $rid, 'all' ),   array( 'a@example.com', 'b@example.com', 'd@example.com' ) ) );
$check( 'include=stall → A,D',        $same( EEM_Notifications::resolve_recipients( $rid, 'stall' ), array( 'a@example.com', 'd@example.com' ) ) );
$check( 'include=rv → A,B',           $same( EEM_Notifications::resolve_recipients( $rid, 'rv' ),    array( 'a@example.com', 'b@example.com' ) ) );
$check( 'include=division → A,B',     $same( EEM_Notifications::resolve_recipients( $rid, $div ),    array( 'a@example.com', 'b@example.com' ) ) );

// --- the canonical "RV but not stall" (set difference) ----------------------
$check( 'include=rv exclude=stall → B only', $same( EEM_Notifications::resolve_recipients( $rid, 'rv', 'stall' ), array( 'b@example.com' ) ) );
$check( 'include=all exclude=division → D only (A,B are entrants)', $same( EEM_Notifications::resolve_recipients( $rid, 'all', $div ), array( 'd@example.com' ) ) );

// --- payment filter --------------------------------------------------------
$check( 'include=all payment=paid → A,D',      $same( EEM_Notifications::resolve_recipients( $rid, 'all', '', 'paid' ),   array( 'a@example.com', 'd@example.com' ) ) );
$check( 'include=all payment=unpaid → B',      $same( EEM_Notifications::resolve_recipients( $rid, 'all', '', 'unpaid' ), array( 'b@example.com' ) ) );
$check( 'include=division payment=paid → A',   $same( EEM_Notifications::resolve_recipients( $rid, $div, '', 'paid' ),    array( 'a@example.com' ) ) );
$check( 'include=division payment=unpaid → B', $same( EEM_Notifications::resolve_recipients( $rid, $div, '', 'unpaid' ),  array( 'b@example.com' ) ) );

// --- count helper + guards -------------------------------------------------
$check( 'count() matches resolve count', 3 === EEM_Notifications::count( $rid, 'all' ) );
$check( 'reservation 0 → empty', array() === EEM_Notifications::resolve_recipients( 0, 'all' ) );

// --- cleanup ---------------------------------------------------------------
$wpdb->query( $wpdb->prepare( "DELETE FROM {$stall_t} WHERE reservation_id = %d", $rid ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$rv_t} WHERE reservation_id = %d", $rid ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Division_Entries::table_name() . ' WHERE division_id = %d', $did ) );
wp_delete_post( (int) $did, true );
wp_delete_post( (int) $rid, true );
$check( 'cleaned up temp orders + division + posts', null === get_post( $rid ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
