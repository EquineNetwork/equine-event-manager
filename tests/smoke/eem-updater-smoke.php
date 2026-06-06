<?php
/**
 * GitHub auto-update wiring (EEM_Updater + bundled Plugin Update Checker) guard.
 *
 * Verifies the in-WordPress updater is bundled + wired (so "push to main" surfaces
 * an Update in WP), that it authenticates the PRIVATE repo via the wp-config
 * constant, and that the .gitattributes export-ignore rules keep dev files out
 * of the archive GitHub serves to the updater.
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
up_ok( 'token option key is eem_update_token', 'eem_update_token' === EEM_Updater::TOKEN_OPTION, $pass, $fail, $log );
up_ok( 'repo URL points at the EquineNetwork repo', false !== strpos( EEM_Updater::REPO_URL, 'EquineNetwork/equine-event-manager' ), $pass, $fail, $log );

// --- Token resolution: option path + constant precedence ---
// (The EEM_UPDATE_TOKEN constant is not defined on this test site, so the
// option is the active source here — exactly the Settings-field path staging uses.)
$saved_token = get_option( EEM_Updater::TOKEN_OPTION );
delete_option( EEM_Updater::TOKEN_OPTION );
up_ok( 'no token → has_token() false', false === EEM_Updater::has_token(), $pass, $fail, $log );
up_ok( 'no token → get_token() empty', '' === EEM_Updater::get_token(), $pass, $fail, $log );
update_option( EEM_Updater::TOKEN_OPTION, 'github_pat_smoke', false );
up_ok( 'option token → has_token() true', true === EEM_Updater::has_token(), $pass, $fail, $log );
up_ok( 'option token → get_token() returns it', 'github_pat_smoke' === EEM_Updater::get_token(), $pass, $fail, $log );
up_ok( 'option token → token_is_constant() false', false === EEM_Updater::token_is_constant(), $pass, $fail, $log );
if ( false === $saved_token ) { delete_option( EEM_Updater::TOKEN_OPTION ); } else { update_option( EEM_Updater::TOKEN_OPTION, $saved_token, false ); }

// --- Settings page surfaces the token field (no-server-access path) ---
$settings_src = (string) file_get_contents( $root . 'admin/class-eem-settings-page.php' );
up_ok( 'Settings renders a Plugin Updates card', false !== strpos( $settings_src, 'render_updates_card' ), $pass, $fail, $log );
up_ok( 'Settings exposes the token input', false !== strpos( $settings_src, 'name="payload[update_token]"' ), $pass, $fail, $log );
up_ok( 'save handler persists to TOKEN_OPTION', false !== strpos( $settings_src, 'EEM_Updater::TOKEN_OPTION' ), $pass, $fail, $log );

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
$attrs = (string) file_get_contents( $root . '.gitattributes' );
foreach ( array( '/tests/ export-ignore', '/.mockups/ export-ignore', '/CLAUDE.md export-ignore', '/composer.json export-ignore' ) as $rule ) {
	up_ok( ".gitattributes has: $rule", false !== strpos( $attrs, $rule ), $pass, $fail, $log );
}

echo "\n=== EEM_Updater (GitHub auto-update) smoke: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
