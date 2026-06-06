<?php
/**
 * In-WordPress automatic updates from the GitHub repo.
 *
 * Wires the bundled Plugin Update Checker (PUC v5, MIT) to watch the `main`
 * branch of github.com/EquineNetwork/equine-event-manager. When a pushed commit
 * carries a higher `Version:` header than the installed copy, WordPress surfaces
 * an "Update now" link on the Plugins screen — no manual zip upload.
 *
 * The repo is PUBLIC, so NO authentication is required — PUC reads it
 * unauthenticated, exactly like the sibling Equine-Network-GAM-v2 plugin. There
 * is nothing to configure on each site; updates "just work" after a push.
 *
 * Optional private-repo escape hatch: if the repo is ever switched to private,
 * define a read-only token in wp-config.php and PUC will authenticate with it:
 *
 *     define( 'EEM_UPDATE_TOKEN', 'github_pat_xxx' );  // Contents: Read, this repo only
 *
 * The constant is ignored while the repo is public (no auth needed). With no
 * token AND a private repo, the checker simply finds no updates — no fatal.
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

	/** Optional wp-config token constant — only consulted if the repo is made private. */
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

		// Public repo: no auth needed. Only authenticate if a token constant is
		// defined (the private-repo escape hatch). Harmless no-op while public.
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
