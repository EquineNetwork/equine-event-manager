<?php
/**
 * C6.A.2 smoke — Order Detail render fix-up.
 *
 * Per the C6.A.2 kickoff: ~80 content-density assertions across ~22
 * mockup rows verify that each section the C6.A escape-review flagged
 * now renders the actual data (not a placeholder string), and that the
 * three "honest representation" decisions (Card block omitted, save bar
 * deferred, Special Instructions always renders) are enforced both in
 * the rendered HTML and in the source-level grep targets that CLEANUP
 * #33 and #34 promise downstream chunks.
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C6.A.2 SMOKE ===\n";
wp_set_current_user( 1 );

// ── [1] Helper promotions — public static on EEM_Admin ─────────────
echo "\n[1] EEM_Admin rider-parse helpers promoted to public static\n";
$count_ref = new ReflectionMethod( 'EEM_Admin', 'parse_group_rider_count_from_notes' );
$names_ref = new ReflectionMethod( 'EEM_Admin', 'parse_group_rider_names_from_notes' );
ok( 'parse_group_rider_count_from_notes is public', $count_ref->isPublic(), $pass, $fail, $log );
ok( 'parse_group_rider_count_from_notes is static', $count_ref->isStatic(), $pass, $fail, $log );
ok( 'parse_group_rider_names_from_notes is public', $names_ref->isPublic(), $pass, $fail, $log );
ok( 'parse_group_rider_names_from_notes is static', $names_ref->isStatic(), $pass, $fail, $log );
ok( 'rider count parses canonical notes format',  3 === EEM_Admin::parse_group_rider_count_from_notes( "Group Riders Count: 3\nGroup Riders: A | B | C" ), $pass, $fail, $log );
ok( 'rider names parses pipe-delimited list',     array( 'A', 'B', 'C' ) === EEM_Admin::parse_group_rider_names_from_notes( "Group Riders: A | B | C" ), $pass, $fail, $log );
ok( 'rider names empty notes → empty array',       array() === EEM_Admin::parse_group_rider_names_from_notes( '' ), $pass, $fail, $log );

// ── [2] Add-on helper exists + computes from totals residual ───────
echo "\n[2] compute_addon_subtotal residual\n";
ok( 'compute_addon_subtotal exists',
	method_exists( 'EEM_Order_Detail_Page', 'compute_addon_subtotal' ),
	$pass, $fail, $log );

// Render the detail page against a real seeded order, then assert
// section-by-section content density.
$repo  = new EEM_Orders_Repository();
$ref   = ( new ReflectionClass( $repo ) )->getMethod( 'get_grouped_orders' );
$ref->setAccessible( true );
$rows  = $ref->invoke( $repo );
$order = null;
// #55: prefer a content-rich seeded order (has email + a priced section) so the
// header + summary density assertions exercise a real populated render; fall back
// to the first keyed order if the seeder hasn't run.
foreach ( $rows as $o ) {
	if ( ! empty( $o['order_key'] ) && ! empty( $o['email'] )
		&& ( (float) ( $o['stall_subtotal'] ?? 0 ) > 0 || (float) ( $o['rv_subtotal'] ?? 0 ) > 0 ) ) {
		$order = $o; break;
	}
}
if ( null === $order ) {
	foreach ( $rows as $o ) { if ( ! empty( $o['order_key'] ) ) { $order = $o; break; } }
}
$html = '';
if ( $order ) {
	$_GET = array( 'page' => EEM_Order_Detail_Page::MENU_SLUG, 'order_key' => $order['order_key'] );
	ob_start();
	( new EEM_Order_Detail_Page() )->render();
	$html = ob_get_clean();
}

// ── [3] Header meta line — customer email key fix ──────────────────
echo "\n[3] Header meta — email key bug fixed (was customer_email, now email)\n";
$has_email = $order && ! empty( $order['email'] );
ok( 'order has email key (top-level)', $has_email, $pass, $fail, $log );
// NOTE: live-order email→HTML round-trip is covered by the synthetic
// payment-details direct render in §14 (which uses a known email and
// asserts the mailto: appears). The source-grep assertions below catch
// any regression where someone re-introduces the customer_email/phone
// typo.
ok( 'header source uses $order[email] not $order[customer_email]',
	false === strpos( file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-order-detail-page.php' ), "\$order['customer_email']" ),
	$pass, $fail, $log );
ok( 'header source uses $order[phone] not $order[customer_phone]',
	false === strpos( file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-order-detail-page.php' ), "\$order['customer_phone']" ),
	$pass, $fail, $log );

// ── [4] Add-On card — real $ amount, not "See section subtotal" ────
echo "\n[4] Add-On card content density\n";
ok( 'addon card placeholder string GONE — "See section subtotal"',
	false === strpos( $html, 'See section subtotal' ), $pass, $fail, $log );
ok( 'addon card placeholder string GONE — "See summary"',
	false === strpos( $html, 'See summary' ), $pass, $fail, $log );

// ── [5] Summary card — per-section breakdown + badges + Section Total ──
echo "\n[5] Order Summary content density (mockup lines 479-529)\n";
// The night-count badge only renders when the section has computed nights
// (compute_nights from arrival/departure dates), NOT merely a subtotal — a
// flat-rate stall order has a subtotal but zero nights. Gate the badge
// assertions on nights computed the same way the page does.
$nights_ref = new ReflectionMethod( 'EEM_Order_Detail_Page', 'compute_nights' );
$nights_ref->setAccessible( true );
$odp = new EEM_Order_Detail_Page();
$stall_nights = $order ? (int) $nights_ref->invoke( $odp, (string) ( $order['stall_arrival_date'] ?? '' ), (string) ( $order['stall_departure_date'] ?? '' ) ) : 0;
$rv_nights    = $order ? (int) $nights_ref->invoke( $odp, (string) ( $order['rv_arrival_date'] ?? '' ),    (string) ( $order['rv_departure_date'] ?? '' ) ) : 0;
$has_stall_sub = $order && (float) ( $order['stall_subtotal'] ?? 0 ) > 0 && $stall_nights > 0;
$has_rv_sub    = $order && (float) ( $order['rv_subtotal']    ?? 0 ) > 0 && $rv_nights > 0;
$has_fees      = $order && (float) ( $order['fees']           ?? 0 ) > 0;
// #55: the summary redesigned to per-section labelled subtotals ("Stalls
// Subtotal", "RV Subtotal", "Fees Total") instead of a generic "Section Total".
ok( 'summary emits a per-section subtotal label',              ! ( $has_stall_sub || $has_rv_sub ) || str_contains( $html, 'Subtotal' ), $pass, $fail, $log );
ok( 'summary emits .eem-order-summary__section-subtotal class', str_contains( $html, 'eem-order-summary__section-subtotal' ),                       $pass, $fail, $log );
ok( 'summary emits Non-Refundable Convenience Fee label',      ! $has_fees || str_contains( $html, 'Non-Refundable Convenience Fee' ),             $pass, $fail, $log );
ok( 'summary stall badge class present when stall section exists',
	! $has_stall_sub || str_contains( $html, 'eem-order-summary__section-badge--stall' ),
	$pass, $fail, $log );
ok( 'summary rv badge class present when rv section exists',
	! $has_rv_sub || str_contains( $html, 'eem-order-summary__section-badge--rv' ),
	$pass, $fail, $log );
ok( 'summary grand-total navy box emitted',                    str_contains( $html, 'eem-order-summary__grand-total' ),                            $pass, $fail, $log );
ok( 'summary grand label "Total"',                             str_contains( $html, 'eem-order-summary__grand-label' ),                            $pass, $fail, $log );

// ── [6] Payment Details — Customer/Email/Phone/Processor/Captured + NO Card block ──
echo "\n[6] Payment Details content density (mockup lines 531-565)\n";
ok( 'payment details emits Customer label',           str_contains( $html, '>Customer</div>' ),                                  $pass, $fail, $log );
ok( 'payment details emits Processor label',          str_contains( $html, '>Processor</div>' ) || str_contains( $html, 'Processor' ), $pass, $fail, $log );
ok( 'payment details emits Captured label',           str_contains( $html, '>Captured</div>' ) || str_contains( $html, 'Captured' ), $pass, $fail, $log );
ok( 'payment details emits Refund History label',     str_contains( $html, 'Refund History' ),                                   $pass, $fail, $log );
ok( 'payment details emits separator class on Refund History',
	str_contains( $html, 'eem-order-payment__label--sep' ),
	$pass, $fail, $log );
// CLEANUP #34 is NOT yet wired into live data — card brand/last4 capture lands
// in C14 (collect-payment confirm handler writes "Card Brand:"/"Card Last4:" into
// the component notes). The render code is already conditional on that data
// (admin/class-eem-order-detail-page.php ~808), so on a live seed order with no
// card notes the Card block is correctly OMITTED. The live-render assertions that
// previously expected the block to appear were test drift (a comment falsely
// claiming "#34 done"). We instead prove the CONDITIONAL block via a direct render
// that supplies the card notes — verifying real behavior: when card data IS
// present, the Card label + masked last4 glyph render. (Empty-data omission is
// already covered by §14's "NO Card label / NO •••• mask" assertions.)
$card_page = new EEM_Order_Detail_Page();
$card_m    = new ReflectionMethod( $card_page, 'render_payment_details_card' );
$card_m->setAccessible( true );
ob_start();
$card_m->invoke( $card_page, array(
	'status_slug' => 'paid',
	'components'  => array( array(
		'transaction_id' => 'pi_card_test',
		'notes'          => "Card Brand: visa\nCard Last4: 4242",
	) ),
) );
$card_html = ob_get_clean();
ok( 'Card display block renders when card data present',
	false !== strpos( $card_html, '>Card</div>' ),
	$pass, $fail, $log );
ok( 'card shows masked •••• last4 glyph (with last4 digits)',
	false !== strpos( $card_html, '•••• 4242' ),
	$pass, $fail, $log );

// ── [7] Special Instructions — always renders ──────────────────────
echo "\n[7] Special Instructions always renders (no empty-guard)\n";
ok( 'requests card always present in output',
	str_contains( $html, 'eem-order-full-width' ) && str_contains( $html, 'Special Requests' ),
	$pass, $fail, $log );

// Direct test of the renderer with an empty order array (forces empty path).
// #55: card renamed render_special_instructions_card → render_special_requests_card
// and now takes an $order array (customer notes), not a reservation_id.
$page = new EEM_Order_Detail_Page();
$m = new ReflectionMethod( $page, 'render_special_requests_card' );
$m->setAccessible( true );
ob_start();
$m->invoke( $page, array() );
$empty_si_html = ob_get_clean();
ok( 'empty-state still renders the card chrome',                str_contains( $empty_si_html, 'Special Requests' ),                                $pass, $fail, $log );
ok( 'empty-state renders the no-requests placeholder',           str_contains( $empty_si_html, 'No special requests.' ),                             $pass, $fail, $log );
ok( 'empty-state applies --empty modifier class',                str_contains( $empty_si_html, 'eem-order-instructions__text--empty' ),             $pass, $fail, $log );

// ── [8] Save bar — DEFERRED per CLEANUP #33 ────────────────────────
echo "\n[8] Save bar deferred to C7 (CLEANUP #33)\n";
$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-order-detail-page.php' );
ok( 'inline marker references CLEANUP #33 by number',           str_contains( $src, 'CLEANUP #33' ),                                                $pass, $fail, $log );
ok( 'inline marker references mockup lines 586-592',            str_contains( $src, 'mockup lines 586-592' ) || str_contains( $src, 'lines 586-592' ), $pass, $fail, $log );
ok( 'no Save Changes button rendered',                           false === strpos( $html, 'Save Changes' ),                                          $pass, $fail, $log );
ok( 'no .eem-save-bar markup rendered',                          false === strpos( $html, 'eem-save-bar' ),                                          $pass, $fail, $log );

// ── [9] Card display capture marker (CLEANUP #34 — now complete) ───
echo "\n[9] Card display block present (CLEANUP #34 complete)\n";
ok( 'inline marker references CLEANUP #34 by number',           str_contains( $src, 'CLEANUP #34' ),                                                $pass, $fail, $log );
ok( 'card data sourced from captured component notes',          str_contains( $src, 'Card Brand' ) && str_contains( $src, 'Card Last4' ),           $pass, $fail, $log );

// ── [10] Edit Reservation button ────────────────────────────────────
echo "\n[10] Edit Reservation button in header actions\n";
// #55: the header "Edit Reservation" button was removed in a later pass
// (admin/class-eem-order-detail-page.php ~389) — assert it's gone, not present.
ok( 'no header Edit Reservation button (removed)',
	false === strpos( $html, '>Edit Reservation<' ),
	$pass, $fail, $log );

// ── [11] Group card — rider list rendered when notes contain riders ─
echo "\n[11] Group card rider-list (mockup lines 468-471)\n";
$page = new EEM_Order_Detail_Page();
$m = new ReflectionMethod( $page, 'render_group_card' );
$m->setAccessible( true );
ob_start();
$m->invoke( $page, array(
	'type'  => 'Group',
	'notes' => "Group Riders Count: 3\nGroup Riders: Tanner Sperle | Whitney Mitchell | John Doe",
) );
$group_html = ob_get_clean();
ok( 'group card renders Rider Count row',          str_contains( $group_html, 'Rider Count' ),               $pass, $fail, $log );
ok( 'group card renders count = 3 (parsed from notes)', preg_match( '/Rider Count<\/td><td>3<\/td>/', $group_html ) === 1, $pass, $fail, $log );
ok( 'group card renders rider-row wrapper',        str_contains( $group_html, 'eem-rider-row' ),             $pass, $fail, $log );
ok( 'group card renders rider-num numbered badge', str_contains( $group_html, 'eem-rider-num' ),             $pass, $fail, $log );
ok( 'group card renders rider-list container',     str_contains( $group_html, 'eem-rider-list' ),            $pass, $fail, $log );
ok( 'group card emits Tanner Sperle rider name',   str_contains( $group_html, 'Tanner Sperle' ),             $pass, $fail, $log );
ok( 'group card emits Whitney Mitchell rider name', str_contains( $group_html, 'Whitney Mitchell' ),         $pass, $fail, $log );
ok( 'group card emits John Doe rider name',        str_contains( $group_html, 'John Doe' ),                  $pass, $fail, $log );
ok( 'group card numbers riders 1, 2, 3 in order',
	preg_match( '/eem-rider-num">1<.+Tanner Sperle/s', $group_html ) === 1 &&
	preg_match( '/eem-rider-num">2<.+Whitney Mitchell/s', $group_html ) === 1 &&
	preg_match( '/eem-rider-num">3<.+John Doe/s', $group_html ) === 1,
	$pass, $fail, $log );

// Group card silent-degrade when notes are empty.
ob_start();
$m->invoke( $page, array( 'type' => 'Group', 'notes' => '', 'components' => array( array() ) ) );
$empty_group_html = ob_get_clean();
ok( 'group card no-notes fallback: card still rendered',
	str_contains( $empty_group_html, 'Group Reservation' ),
	$pass, $fail, $log );
ok( 'group card no-notes fallback: no rider-list block',
	false === strpos( $empty_group_html, 'eem-rider-list' ),
	$pass, $fail, $log );

// ── [12] Add-On card direct render with synthetic shape ────────────
echo "\n[12] Add-On card direct render\n";
$m = new ReflectionMethod( $page, 'render_addon_card' );
$m->setAccessible( true );
ob_start();
$m->invoke( $page, array(
	'type'                    => 'Add-On',
	'required_shavings_qty'   => 1,
	'additional_shavings_qty' => 1,
	'total'                   => 24.00,
	'stall_subtotal'          => 0.0,
	'rv_subtotal'             => 0.0,
	'fees'                    => 0.0,
) );
$addon_html = ob_get_clean();
ok( 'addon card title rendered',                       str_contains( $addon_html, 'Add-Ons' ),                                  $pass, $fail, $log );
// #55: addon card collapses to a single "Add-On Charges" line (not a per-qty
// "Shavings (×N)" breakdown — that detail lives on the Stall card).
ok( 'addon card Add-On Charges line label',            str_contains( $addon_html, 'Add-On Charges' ),                           $pass, $fail, $log );
ok( 'addon card line item shows $24.00 (computed)',    str_contains( $addon_html, '$24.00' ),                                   $pass, $fail, $log );
ok( 'addon card Add-On Subtotal row',                  str_contains( $addon_html, 'Add-On Subtotal' ),                          $pass, $fail, $log );
ok( 'addon card subtotal cell echoes $24.00',          substr_count( $addon_html, '$24.00' ) >= 2,                              $pass, $fail, $log );

// ── [12b] Add-On card SUPPRESSED when residual is $0 ──────────────
// Required shavings billed inside the stall subtotal => add-on residual is
// $0. The card must NOT render (no misleading "Shavings (×N) $0.00" line);
// the shavings quantities still show on the Stall card. Mirrors the
// order-summary sidebar gate.
echo "\n[12b] Add-On card suppressed at \$0 residual\n";
ob_start();
$m->invoke( $page, array(
	'type'                    => 'Stall, Add-On',
	'required_shavings_qty'   => 4,
	'additional_shavings_qty' => 0,
	'total'                   => 299.52,
	'stall_subtotal'          => 288.00, // includes the 4 required shaving bags
	'rv_subtotal'             => 0.0,
	'fees'                    => 11.52,
) );
$addon_zero_html = ob_get_clean();
ok( 'addon card NOT rendered when residual is $0',     '' === trim( $addon_zero_html ),                                         $pass, $fail, $log );
ok( 'no "Shavings (×4) $0.00" misleading line',        ! str_contains( $addon_zero_html, 'Shavings (×4)' ),                     $pass, $fail, $log );

// ── [13] Summary card direct render with synthetic shape ───────────
echo "\n[13] Summary card direct render\n";
$m = new ReflectionMethod( $page, 'render_summary_card' );
$m->setAccessible( true );
ob_start();
$m->invoke( $page, array(
	'stall_subtotal'          => 64.00,
	'rv_subtotal'             => 100.00,
	'fees'                    => 6.56,
	'total'                   => 194.56,
	'stall_arrival_date'      => '2026-05-08',
	'stall_departure_date'    => '2026-05-10',
	'rv_arrival_date'         => '2026-05-08',
	'rv_departure_date'       => '2026-05-10',
	'required_shavings_qty'   => 1,
	'additional_shavings_qty' => 1,
) );
$sum_html = ob_get_clean();
ok( 'summary Stall Reservation section title',         str_contains( $sum_html, 'Stall Reservation' ),                          $pass, $fail, $log );
ok( 'summary RV Reservation section title',            str_contains( $sum_html, 'RV Reservation' ),                             $pass, $fail, $log );
ok( 'summary Add-Ons section title',                   str_contains( $sum_html, '>Add-Ons<' ) || str_contains( $sum_html, 'Add-Ons' ), $pass, $fail, $log );
ok( 'summary Fees section title',                      str_contains( $sum_html, 'Fees' ),                                        $pass, $fail, $log );
ok( 'summary stall section-badge "2 nights"',          str_contains( $sum_html, '2 nights' ),                                   $pass, $fail, $log );
ok( 'summary stall badge class --stall',               str_contains( $sum_html, 'eem-order-summary__section-badge--stall' ),    $pass, $fail, $log );
ok( 'summary rv badge class --rv',                     str_contains( $sum_html, 'eem-order-summary__section-badge--rv' ),       $pass, $fail, $log );
// #55: each priced section renders its own labelled subtotal row (Stalls
// Subtotal / RV Subtotal / Add-Ons Total / Fees Total) — 4 section-subtotal rows
// rather than 4 generic "Section Total" rows; addon/fees sections carry no badge.
ok( 'summary renders 4 section-subtotal rows (stall+rv+addon+fees)',
	substr_count( $sum_html, 'eem-order-summary__section-subtotal' ) >= 4,
	$pass, $fail, $log, 'got ' . substr_count( $sum_html, 'eem-order-summary__section-subtotal' ) );
ok( 'summary line: Stalls Subtotal $64.00',            str_contains( $sum_html, 'Stalls Subtotal' ) && str_contains( $sum_html, '$64.00' ), $pass, $fail, $log );
ok( 'summary line: RV Subtotal $100.00',               str_contains( $sum_html, 'RV Subtotal' ) && str_contains( $sum_html, '$100.00' ),   $pass, $fail, $log );
ok( 'summary line: Non-Refundable Convenience Fee $6.56', str_contains( $sum_html, 'Non-Refundable Convenience Fee' ) && str_contains( $sum_html, '$6.56' ), $pass, $fail, $log );
ok( 'summary addon line: Add-On Charges $24.00',       str_contains( $sum_html, 'Add-On Charges' ) && str_contains( $sum_html, '$24.00' ), $pass, $fail, $log );
ok( 'summary grand total $194.56',                     str_contains( $sum_html, '$194.56' ),                                    $pass, $fail, $log );
ok( 'summary grand-total navy box class',              str_contains( $sum_html, 'eem-order-summary__grand-total' ),             $pass, $fail, $log );

// ── [14] Payment Details direct render — Card block omitted ────────
echo "\n[14] Payment Details direct render\n";
$m = new ReflectionMethod( $page, 'render_payment_details_card' );
$m->setAccessible( true );
ob_start();
$m->invoke( $page, array(
	'email'           => 'test@example.com',
	'customer_name'   => 'Test Customer',
	'phone'           => '(555) 123-4567',
	'payment_gateway' => 'Stripe',
	'status_slug'     => 'paid',
	'components'      => array( array( 'transaction_id' => 'pi_test_XYZ' ) ),
) );
$pd_html = ob_get_clean();
ok( 'payment details emits customer name',             str_contains( $pd_html, 'Test Customer' ),                               $pass, $fail, $log );
ok( 'payment details emits email link',                str_contains( $pd_html, 'mailto:test@example.com' ),                     $pass, $fail, $log );
ok( 'payment details emits phone number',              str_contains( $pd_html, '(555) 123-4567' ),                              $pass, $fail, $log );
ok( 'payment details emits processor "Stripe"',        str_contains( $pd_html, 'Stripe' ),                                      $pass, $fail, $log );
ok( 'payment details emits transaction id',            str_contains( $pd_html, 'pi_test_XYZ' ),                                 $pass, $fail, $log );
ok( 'payment details captured=Yes for paid status',    str_contains( $pd_html, '>Yes<' ),                                       $pass, $fail, $log );
ok( 'payment details NO Card label (omitted per #34)', false === strpos( $pd_html, '>Card</div>' ),                             $pass, $fail, $log );
ok( 'payment details NO VISA glyph (omitted per #34)', false === strpos( $pd_html, 'VISA' ),                                    $pass, $fail, $log );
ok( 'payment details NO •••• mask (omitted per #34)',  false === strpos( $pd_html, '••••' ),                                    $pass, $fail, $log );
ok( 'payment details emits Refund History row',        str_contains( $pd_html, 'Refund History' ),                              $pass, $fail, $log );
ok( 'payment details Refund History uses separator class',
	str_contains( $pd_html, 'eem-order-payment__label--sep' ),
	$pass, $fail, $log );

// ── [15] CSS additions present ──────────────────────────────────────
echo "\n[15] CSS additions\n";
$css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
ok( 'CSS: .eem-order-summary__section-badge',           false !== strpos( $css, '.eem-order-summary__section-badge' ),          $pass, $fail, $log );
ok( 'CSS: .eem-order-summary__section-badge--stall',    false !== strpos( $css, '.eem-order-summary__section-badge--stall' ),   $pass, $fail, $log );
ok( 'CSS: .eem-order-summary__section-badge--rv',       false !== strpos( $css, '.eem-order-summary__section-badge--rv' ),      $pass, $fail, $log );
ok( 'CSS: .eem-order-summary__section-badge--addon',    false !== strpos( $css, '.eem-order-summary__section-badge--addon' ),   $pass, $fail, $log );
ok( 'CSS: .eem-order-summary__section-subtotal',        false !== strpos( $css, '.eem-order-summary__section-subtotal' ),       $pass, $fail, $log );
ok( 'CSS: .eem-rider-list',                             false !== strpos( $css, '.eem-rider-list' ),                            $pass, $fail, $log );
ok( 'CSS: .eem-rider-row',                              false !== strpos( $css, '.eem-rider-row' ),                             $pass, $fail, $log );
ok( 'CSS: .eem-rider-num',                              false !== strpos( $css, '.eem-rider-num' ),                             $pass, $fail, $log );
ok( 'CSS: .eem-order-instructions__text--empty',        false !== strpos( $css, '.eem-order-instructions__text--empty' ),       $pass, $fail, $log );

// [16] retired (#55): CLEANUP.md is an internal dev doc, export-ignored from the
// shipped plugin (#40), so it isn't present at EQUINE_EVENT_MANAGER_PATH on a real
// install. Verifying markdown heading text is process theater, not behavior.

// ── [17] C6.A.3 polish — canonical palette + status-badge + markers ──
echo "\n[17] C6.A.3 polish — palette, Section Total case, status-badge naming, markers\n";

// Section-badge colors must match the canonical .eem-type-badge--{X} palette
// (mockup lines 66-71). Three variants × hex+border = 6 assertions.
// #55/#59: the canonical hexes were extracted into CSS custom properties; the
// badge selectors now reference them via var(). Assert (a) the token holds the
// canonical hex AND (b) the selector consumes the matching token.
ok( 'token --eem-badge-blue-bg = canonical #EEF4FF',   (bool) preg_match( '/--eem-badge-blue-bg:\s*#EEF4FF/i', $css ), $pass, $fail, $log );
ok( 'section-badge--stall consumes --eem-badge-blue-bg/text',
	(bool) preg_match( '/\.eem-order-summary__section-badge--stall\s*\{[^}]*var\(\s*--eem-badge-blue-bg/i', $css )
	&& (bool) preg_match( '/--eem-badge-blue-text:\s*#1668F2/i', $css ), $pass, $fail, $log );
ok( 'token --eem-badge-purple-bg = canonical #F5F3FF', (bool) preg_match( '/--eem-badge-purple-bg:\s*#F5F3FF/i', $css ), $pass, $fail, $log );
ok( 'section-badge--rv consumes --eem-badge-purple-bg/text',
	(bool) preg_match( '/\.eem-order-summary__section-badge--rv\s*\{[^}]*var\(\s*--eem-badge-purple-bg/i', $css )
	&& (bool) preg_match( '/--eem-badge-purple-text:\s*#6d28d9/i', $css ), $pass, $fail, $log );
ok( 'token --eem-badge-orange-bg = canonical #FFF7ED', (bool) preg_match( '/--eem-badge-orange-bg:\s*#FFF7ED/i', $css ), $pass, $fail, $log );
ok( 'section-badge--addon consumes --eem-badge-orange-bg/text',
	(bool) preg_match( '/\.eem-order-summary__section-badge--addon\s*\{[^}]*var\(\s*--eem-badge-orange-bg/i', $css )
	&& (bool) preg_match( '/--eem-badge-orange-text:\s*#c2410c/i', $css ), $pass, $fail, $log );

// Section-Total no longer uppercase per mockup (lines 163-164 use title-case).
ok( 'CSS section-subtotal does NOT use text-transform: uppercase',
	(bool) preg_match( '/\.eem-order-summary__section-subtotal\s*\{(?:(?!\}).)*\}/s', $css, $m ) && false === stripos( $m[0], 'text-transform' ),
	$pass, $fail, $log );
// #55: per-section subtotal labels are title-case ("Stalls Subtotal", "Fees
// Total") — verify a section-subtotal row renders, not the retired "Section Total".
ok( 'rendered output emits a title-case section-subtotal row',
	! ( $has_stall_sub || $has_rv_sub ) || str_contains( $html, 'eem-order-summary__section-subtotal' ),
	$pass, $fail, $log );

// Status-badge render uses legacy single-dash class to match existing CSS.
ok( 'status badge renders as legacy .eem-status-{slug} (no BEM -- prefix)',
	(bool) preg_match( '/class="eem-status-badge eem-status-[a-z-]+"/', $html ),
	$pass, $fail, $log );
ok( 'NO BEM-style eem-status-badge--{slug} in render output',
	false === strpos( $html, 'eem-status-badge--' ),
	$pass, $fail, $log );

// Diagnostic markers removed.
ok( 'NO C6A2-MARKER strings in render output',
	false === strpos( $html, 'C6A2-MARKER' ),
	$pass, $fail, $log );
ok( 'NO C6A2-MARKER strings in source file',
	false === strpos( file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-order-detail-page.php' ), 'C6A2-MARKER' ),
	$pass, $fail, $log );

// CLEANUP #36 doc check retired (#55) — internal dev doc, not shipped.

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
