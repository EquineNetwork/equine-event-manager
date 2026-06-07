<?php
/**
 * v2 — Status-badge class consistency.
 *
 * Every status badge across the plugin renders as `eem-status-badge eem-status-{slug}`
 * (the legacy, CSS-backed pattern). The BEM `eem-status-badge--{slug}` variant has
 * NO CSS and must not appear in any render — it produced unstyled badges in the
 * refund/cancel AJAX fragments before this normalization.
 */

$pass = 0; $fail = 0; $log = array();
function sb_ok( $label, $cond, &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; } else { $fail++; $log[] = "FAIL: $label"; }
}

$base  = EQUINE_EVENT_MANAGER_PATH;
$files = array(
	'admin/class-equine-event-manager-admin.php',
	'admin/class-eem-orders-list-page.php',
	'admin/class-eem-order-detail-page.php',
	'admin/class-eem-dashboard-page.php',
	'admin/class-eem-customer-profile-page.php',
	'assets/js/admin.js',
);

// 1) No render/JS file may emit the un-styled BEM status-badge modifier.
foreach ( $files as $rel ) {
	$src = (string) file_get_contents( $base . $rel );
	sb_ok( "no eem-status-badge-- in {$rel}", false === strpos( $src, 'eem-status-badge--' ), $pass, $fail, $log );
}

// 2) Every slug status_slug_to_css_class can return has a matching .eem-status-{slug}
//    rule in admin.css (so no badge is ever unstyled).
$css   = (string) file_get_contents( $base . 'assets/css/admin.css' );
$slugs = array( 'paid', 'partial', 'invoice', 'refunded', 'cancelled', 'unpaid', 'active', 'archived', 'draft', 'trashed' );
foreach ( $slugs as $slug ) {
	sb_ok( "admin.css defines .eem-status-{$slug}", false !== strpos( $css, '.eem-status-' . $slug ), $pass, $fail, $log );
}

// 3) status_slug_to_css_class only returns slugs that have CSS.
foreach ( array( 'paid', 'partially-refunded', 'invoice-sent', 'refunded', 'cancelled', 'unpaid', 'anything-else' ) as $in ) {
	$out = EEM_Orders_List_Page::status_slug_to_css_class( $in );
	sb_ok( "css class for '{$in}' ({$out}) is CSS-backed", in_array( $out, $slugs, true ), $pass, $fail, $log );
}

echo "\n=== v2 status-badge consistency smoke: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
