<?php
/**
 * GitHub auto-update wiring (EEM_Updater + bundled Plugin Update Checker) guard.
 *
 * Verifies the in-WordPress updater is bundled + wired (so "push to main" surfaces
 * an Update in WP) against the PUBLIC repo (no token needed), and that the
 * .gitattributes export-ignore rules keep dev files out of the archive GitHub
 * serves to the updater.
 *
 * Does not hit the GitHub API.
 */

$pass = 0; $fail = 0; $log = array();
function up_ok( $label, $cond, &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; } else { $fail++; $log[] = "FAIL: $label"; }
}

$root = EQUINE_EVENT_MANAGER_PATH;

// --- Updater class + live wiring ---
up_ok( 'EEM_Updater class exists', class_exists( 'EEM_Updater' ), $pass, $fail, $log );
up_ok( 'EEM_Updater::init exists', method_exists( 'EEM_Updater', 'init' ), $pass, $fail, $log );
up_ok( 'tracks the main branch', 'main' === EEM_Updater::BRANCH, $pass, $fail, $log );
up_ok( 'token constant is EEM_UPDATE_TOKEN', 'EEM_UPDATE_TOKEN' === EEM_Updater::TOKEN_CONSTANT, $pass, $fail, $log );
up_ok( 'repo URL points at the EquineNetwork repo', false !== strpos( EEM_Updater::REPO_URL, 'EquineNetwork/equine-event-manager' ), $pass, $fail, $log );

// PUC actually loaded + registered its WP update hook (proves the checker built).
up_ok( 'PUC v5 factory loaded', class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ), $pass, $fail, $log );
up_ok( 'WP update-check hook registered by PUC', false !== has_filter( 'pre_set_site_transient_update_plugins' ), $pass, $fail, $log );

// --- Bundled library present (so installs don't depend on composer) ---
up_ok( 'PUC loader bundled', is_readable( $root . 'includes/plugin-update-checker/plugin-update-checker.php' ), $pass, $fail, $log );
up_ok( 'PUC v5 factory file bundled', is_readable( $root . 'includes/plugin-update-checker/Puc/v5/PucFactory.php' ), $pass, $fail, $log );
up_ok( 'PUC GitHub API bundled', is_readable( $root . 'includes/plugin-update-checker/Puc/v5p7/Vcs/GitHubApi.php' ), $pass, $fail, $log );

// --- Bootstrap wires it ---
$main = (string) file_get_contents( $root . 'equine-event-manager.php' );
up_ok( 'main file requires the updater', false !== strpos( $main, "includes/class-eem-updater.php" ), $pass, $fail, $log );
up_ok( 'main file calls EEM_Updater::init()', false !== strpos( $main, 'EEM_Updater::init()' ), $pass, $fail, $log );

// --- export-ignore keeps the updater's download archive clean ---
// #55: .gitattributes is a repo build-config file; it is itself export-ignored and
// is NOT present in a deployed/copied plugin (EQUINE_EVENT_MANAGER_PATH). Only
// assert the rules when the file is present (dev checkout / source tree).
if ( file_exists( $root . '.gitattributes' ) ) {
	$attrs = (string) file_get_contents( $root . '.gitattributes' );
	foreach ( array( '/tests/ export-ignore', '/.mockups/ export-ignore', '/CLAUDE.md export-ignore', '/composer.json export-ignore' ) as $rule ) {
		up_ok( ".gitattributes has: $rule", false !== strpos( $attrs, $rule ), $pass, $fail, $log );
	}
} else {
	$log[] = 'SKIP .gitattributes rules — not present on a deployed install (export-ignored).';
}

echo "\n=== EEM_Updater (GitHub auto-update) smoke: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
