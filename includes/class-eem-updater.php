<?php
/**
 * In-WordPress automatic updates from the private GitHub repo.
 *
 * Wires the bundled Plugin Update Checker (PUC v5, MIT) to watch the `main`
 * branch of github.com/EquineNetwork/equine-event-manager. When a pushed commit
 * carries a higher `Version:` header than the installed copy, WordPress surfaces
 * an "Update now" link on the Plugins screen — no manual zip upload.
 *
 * The repo is PRIVATE, so each site authenticates with a GitHub token supplied
 * via a wp-config.php constant (kept out of the database and out of the repo):
 *
 *     define( 'EEM_UPDATE_TOKEN', 'github_pat_xxx' );  // Contents: Read, this repo only
 *
 * Without the constant the checker loads but simply finds no updates (it can't
 * read a private repo unauthenticated) — no errors, no fatal.
 *
 * The GitHub branch archive WordPress downloads is trimmed to runtime-only files
 * by the `export-ignore` rules in .gitattributes (tests/, .mockups/, docs, etc.).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 * @since     2.7.45
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets up the GitHub-backed update checker.
 */
class EEM_Updater {

	/** Public repo URL (PUC reads owner/name from it). */
	const REPO_URL = 'https://github.com/EquineNetwork/equine-event-manager/';

	/** Branch to track for updates. */
	const BRANCH = 'main';

	/** wp-config constant that carries the GitHub access token for the private repo. */
	const TOKEN_CONSTANT = 'EEM_UPDATE_TOKEN';

	/**
	 * Build the update checker. Safe to call once during plugin bootstrap; it
	 * only registers hooks (the actual GitHub API call is throttled by PUC and
	 * runs on the normal WP update schedule / Plugins-screen visit).
	 *
	 * @return void
	 */
	public static function init(): void {
		$loader = EQUINE_EVENT_MANAGER_PATH . 'includes/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $loader ) ) {
			return;
		}
		require_once $loader;

		$factory = '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
		if ( ! class_exists( $factory ) ) {
			return;
		}

		$checker = $factory::buildUpdateChecker(
			self::REPO_URL,
			EQUINE_EVENT_MANAGER_FILE,
			'equine-event-manager'
		);

		// Track the branch HEAD (push-to-main): PUC compares the Version: header on
		// the branch against the installed copy.
		if ( method_exists( $checker, 'setBranch' ) ) {
			$checker->setBranch( self::BRANCH );
		}

		// Authenticate for the private repo when the token constant is defined.
		if ( defined( self::TOKEN_CONSTANT ) && constant( self::TOKEN_CONSTANT ) && method_exists( $checker, 'setAuthentication' ) ) {
			$checker->setAuthentication( constant( self::TOKEN_CONSTANT ) );
		}
	}

	/**
	 * Whether a GitHub token has been configured (for an admin-notice nudge if not).
	 *
	 * @return bool
	 */
	public static function has_token(): bool {
		return defined( self::TOKEN_CONSTANT ) && (bool) constant( self::TOKEN_CONSTANT );
	}
}
