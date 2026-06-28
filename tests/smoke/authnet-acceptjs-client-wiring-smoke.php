<?php
/**
 * Accept.js client-wiring smoke (ship-readiness 4.2 / task #46).
 *
 * SOURCE-PRESENCE ASSERTIONS ONLY — the actual browser tokenization can only be
 * verified with a live/sandbox Accept.js key (the final live-test phase). This
 * guards against the client wiring being accidentally dropped from any of the
 * three Auth.net card forms: each must, when Accept.js is enabled, expose the
 * opaque hidden fields, call Accept.dispatchData, and stop the raw PAN from
 * posting (strip the card field name / send opaque data instead).
 *
 * Run via: wp eval-file tests/smoke/authnet-acceptjs-client-wiring-smoke.php
 *
 * @package EEM_Plugin
 */

$pass = 0; $fail = 0;
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

$root      = EQUINE_EVENT_MANAGER_PATH;
$shortcode = (string) file_get_contents( $root . 'public/class-equine-event-manager-shortcodes.php' );
$collect   = (string) file_get_contents( $root . 'admin/class-eem-collect-payment-page.php' );

// Customer reservation form (primary path).
$chk( false !== strpos( $shortcode, "name=\"authorize_opaque_descriptor\" id=\"eem-opaque-descriptor-" ), 'customer form emits opaque hidden fields (gated on use_acceptjs)' );
$chk( substr_count( $shortcode, 'Accept.dispatchData' ) >= 2, 'customer + invoice forms call Accept.dispatchData' );
$chk( false !== strpos( $shortcode, "num.removeAttribute( 'name' )" ), 'customer form strips the raw card NAME after tokenizing (PAN never posts)' );
$chk( false !== strpos( $shortcode, "if ( ! empty( \$authorize_net_config['use_acceptjs'] ) )" ), 'customer form gates the Accept.js block on use_acceptjs' );

// Hosted invoice form.
$chk( false !== strpos( $shortcode, '$authnet_acceptjs = ' ) && false !== strpos( $shortcode, "esc_url( \$authorize_config['acceptjs_url'] )" ), 'invoice page loads Accept.js from the configured URL when gated on' );
$chk( false !== strpos( $shortcode, "form.querySelector( '[name=\"authorize_opaque_descriptor\"]' )" ), 'invoice form wires the opaque descriptor field' );

// Admin Collect Payment form.
$chk( false !== strpos( $collect, 'cfg.useAcceptjs' ), 'admin collect form branches on useAcceptjs' );
$chk( false !== strpos( $collect, 'window.Accept.dispatchData' ), 'admin collect form tokenizes via Accept.dispatchData' );
$chk( false !== strpos( $collect, 'authorize_opaque_descriptor: response.opaqueData.dataDescriptor' ), 'admin collect sends opaque data (not the PAN) when Accept.js on' );

// Config plumbing.
$chk( false !== strpos( $shortcode, 'public function get_active_authorize_net_configuration' ), 'config getter is public (admin page can read Accept.js config)' );

echo "\n$pass passed, $fail failed\n";
