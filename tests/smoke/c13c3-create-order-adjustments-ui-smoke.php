<?php
/**
 * C13.C.3 smoke — Create Order custom-items + discount UI wiring.
 *
 * Source-presence assertions ONLY — mandatory browser self-verify required for
 * the computed/runtime claims (rail total recompute, .open reveal, field
 * serialization into the AJAX POST). This guards the static contract: the
 * discount markup renders, the CSS reveal/affordance classes exist, the JS
 * helpers + handlers are wired, and the field names agree across JS ⇄ PHP.
 *
 * Run: wp eval-file tests/smoke/c13c3-create-order-adjustments-ui-smoke.php
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

$base = dirname( __DIR__, 2 );
$js   = (string) file_get_contents( $base . '/assets/js/admin.js' );
$css  = (string) file_get_contents( $base . '/assets/css/admin.css' );

// --- 1. render_summary_card emits the discount-fields markup ----------------
$ref = new ReflectionMethod( 'EEM_Create_Order_Page', 'render_summary_card' );
$ref->setAccessible( true );
ob_start();
$ref->invoke( null, 'Test Event' );
$html = (string) ob_get_clean();

$check( 'discount fields container rendered', str_contains( $html, 'data-eem-co-discount-fields' ) );
$check( 'discount type select (name=eem_discount_type)', str_contains( $html, 'name="eem_discount_type"' ) );
$check( 'discount type has dollar + percent options', str_contains( $html, 'value="dollar"' ) && str_contains( $html, 'value="percent"' ) );
$check( 'discount value input (name=eem_discount_value)', str_contains( $html, 'name="eem_discount_value"' ) );
$check( 'discount reason input (name=eem_discount_reason)', str_contains( $html, 'name="eem_discount_reason"' ) );
$check( 'discount applied-row present + hidden', str_contains( $html, 'data-eem-co-discount-applied' ) && str_contains( $html, 'data-eem-co-discount-applied-value' ) );
$check( 'remove-discount action button', str_contains( $html, 'data-eem-action="create-order-remove-discount"' ) );
$check( 'add-discount action button', str_contains( $html, 'data-eem-action="create-order-add-discount"' ) );
$check( 'currency symbol node for $/% swap', str_contains( $html, 'data-eem-co-discount-symbol' ) );

// --- 2. CSS carries the reveal + affordance rules ---------------------------
$check( 'CSS .eem-co-discount.open reveals fields', str_contains( $css, '.eem-co-discount.open .eem-co-discount-fields' ) );
$check( 'CSS hides add button when open', str_contains( $css, '.eem-co-discount.open .eem-co-discount-add' ) );
$check( 'CSS applied-row styled', str_contains( $css, '.eem-co-discount-applied-row' ) );
$check( 'CSS discount summary line modifier', str_contains( $css, '.eem-co-summary-line--discount' ) );
$check( 'CSS custom summary line modifier', str_contains( $css, '.eem-co-summary-line--custom' ) );
$check( 'CSS uses no !important in discount block', ! preg_match( '/eem-co-discount[^{]*\{[^}]*!important/', $css ) );
// Strip /* ... */ comments first — the file documents the historical global
// underline rule in prose, which is not an active declaration.
$css_no_comments = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
$check( 'CSS has no active text-decoration underline on hover', ! preg_match( '/:hover\s*\{[^}]*text-decoration:\s*underline/', $css_no_comments ) );

// --- 3. JS helpers + wiring -------------------------------------------------
foreach ( array(
	'coParseMoney', 'coFmtMoney', 'coCollectCustomItems', 'coReadDiscount',
	'coSummaryLine', 'coUpdateAppliedRow', 'coSyncTotals', 'coAppendAdjustments',
) as $fn ) {
	$check( "JS defines {$fn}()", (bool) preg_match( '/function\s+' . preg_quote( $fn, '/' ) . '\s*\(/', $js ) );
}
$check( 'JS exposes window.EEM_CO.appendAdjustments', str_contains( $js, 'window.EEM_CO.appendAdjustments' ) );
$check( 'JS exposes window.EEM_CO.syncTotals', str_contains( $js, 'window.EEM_CO.syncTotals' ) );
$check( 'JS submit path calls appendAdjustments', str_contains( $js, 'window.EEM_CO.appendAdjustments(formData)' ) );
$check( 'JS handles add-discount click', str_contains( $js, "'create-order-add-discount'" ) );
$check( 'JS handles remove-discount click', str_contains( $js, "'create-order-remove-discount'" ) );
$check( 'JS swaps $/% via data-eem-co-discount-symbol', str_contains( $js, 'data-eem-co-discount-symbol' ) );

// --- 4. Field-name agreement JS ⇄ PHP ---------------------------------------
$check( 'JS coAddCustomItem emits custom_item_desc[]', str_contains( $js, 'name="custom_item_desc[]"' ) );
$check( 'JS coAddCustomItem emits custom_item_amount[]', str_contains( $js, 'name="custom_item_amount[]"' ) );
$check( 'JS appends custom_item_desc[] to formData', str_contains( $js, "formData.append('custom_item_desc[]'" ) );

$page = (string) file_get_contents( $base . '/admin/class-eem-create-order-page.php' );
$check( 'PHP collector reads custom_item_desc', str_contains( $page, "\$_POST['custom_item_desc']" ) );
$check( 'PHP collector reads custom_item_amount', str_contains( $page, "\$_POST['custom_item_amount']" ) );
$check( 'PHP collector reads eem_discount_value', str_contains( $page, "\$_POST['eem_discount_value']" ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) {
	exit( 1 );
}
