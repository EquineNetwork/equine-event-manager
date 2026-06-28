<?php
/**
 * Full data teardown for Equine Event Manager.
 *
 * Single source of truth for "remove everything this plugin created", shared by
 * two entry points:
 *   - uninstall.php  → purge_all_data( true )  (DROP tables; plugin is being deleted)
 *   - Settings → Danger Zone "Erase all data & start fresh" → purge_all_data( false )
 *                    (TRUNCATE tables so the still-active plugin keeps working)
 *
 * Deliberately self-contained: depends only on $wpdb + core WP functions so
 * uninstall.php can load just this one file (plugin classes are NOT bootstrapped
 * in the uninstall context).
 *
 * Scope (per the launch decision "Everything EEM created"):
 *   - The 4 CPTs this plugin registers (reservations + native events/venues/producers)
 *     and their post meta / term relationships.
 *   - The 6 custom tables.
 *   - All plugin options + transients (by the namespaces this plugin owns).
 *   - The uploads/eem-reports cached-export directory.
 *
 * Never touched: TEC / The Events Calendar data (it belongs to that plugin),
 * WordPress core options (admin_email, date_format, siteurl, …), the media
 * library (uploaded logos/venue-map images stay; only the option references to
 * them are cleared), and any non-EEM content.
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes all plugin-created data (posts, tables, options, transients, files).
 */
class EEM_Uninstaller {

	/**
	 * Custom tables this plugin creates (sans the $wpdb->prefix).
	 *
	 * @var string[]
	 */
	const TABLES = array(
		'eem_stall_reservations',
		'eem_rv_reservations',
		'eem_report_exports',
		'eem_activity_log',
		'eem_event_defaults',
		'eem_order_adjustments',
	);

	/**
	 * CPT slugs this plugin registers. Deleting these posts (force=true) also
	 * removes their post meta and term relationships.
	 *
	 * @var string[]
	 */
	const POST_TYPES = array(
		'en_reservation',
		'en_event',
		'en_venue',
		'en_producer',
	);

	/**
	 * SQL LIKE patterns (pre-escaped fragments) for the option namespaces this
	 * plugin owns. The wildcard `%` is appended at query time.
	 *
	 * @var string[]
	 */
	const OPTION_LIKE = array(
		'equine_event_manager_',
		'eem_',
	);

	/**
	 * Plugin-owned options that don't fit a namespace prefix and must be removed
	 * explicitly. `cancellation_policy` is the deprecated global policy option.
	 *
	 * @var string[]
	 */
	const EXPLICIT_OPTIONS = array(
		'cancellation_policy',
	);

	/**
	 * The uploads subdirectory that holds cached report exports.
	 */
	const UPLOAD_SUBDIR = 'eem-reports';

	/**
	 * Resolve the exact option_name values this plugin owns, currently present in
	 * the options table. Used both by count_data() (preview) and delete_options()
	 * — keeping a single matcher guarantees the preview and the deletion agree.
	 *
	 * Core WP options (admin_email, date_format, siteurl, blogname, …) never
	 * match the namespaces above, so they are inherently excluded.
	 *
	 * @return string[] Distinct option_name values owned by this plugin.
	 */
	public static function plugin_option_names(): array {
		global $wpdb;

		$where  = array();
		$params = array();
		foreach ( self::OPTION_LIKE as $prefix ) {
			$where[]  = 'option_name LIKE %s';
			$params[] = $wpdb->esc_like( $prefix ) . '%';
		}
		foreach ( self::EXPLICIT_OPTIONS as $name ) {
			$where[]  = 'option_name = %s';
			$params[] = $name;
		}
		// Transients for this plugin's namespaces (option-table backed).
		foreach ( array( '_transient_eem', '_transient_timeout_eem', '_transient_equine_event_manager', '_transient_timeout_equine_event_manager' ) as $t_prefix ) {
			$where[]  = 'option_name LIKE %s';
			$params[] = $wpdb->esc_like( $t_prefix ) . '%';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where is built from constant fragments; values are parameterized.
		$sql  = 'SELECT option_name FROM ' . $wpdb->options . ' WHERE ' . implode( ' OR ', $where );
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? array_values( array_unique( array_map( 'strval', $rows ) ) ) : array();
	}

	/**
	 * Count what a purge would remove, WITHOUT removing anything. Powers the
	 * Danger-Zone confirmation preview ("This will permanently delete …").
	 *
	 * @return array{reservations:int,events:int,venues:int,producers:int,orders:int,activity_log:int,options:int}
	 */
	public static function count_data(): array {
		global $wpdb;

		$post_counts = array();
		foreach ( self::POST_TYPES as $type ) {
			$post_counts[ $type ] = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'auto-draft'", $type )
			);
		}

		$orders = self::count_table_rows( 'eem_stall_reservations' ) + self::count_table_rows( 'eem_rv_reservations' );

		return array(
			'reservations' => $post_counts['en_reservation'] ?? 0,
			'events'       => $post_counts['en_event'] ?? 0,
			'venues'       => $post_counts['en_venue'] ?? 0,
			'producers'    => $post_counts['en_producer'] ?? 0,
			'orders'       => $orders,
			'activity_log' => self::count_table_rows( 'eem_activity_log' ),
			'options'      => count( self::plugin_option_names() ),
		);
	}

	/**
	 * Permanently remove all plugin data.
	 *
	 * @param bool $drop_tables true → DROP the custom tables (uninstall); false →
	 *                          TRUNCATE them so the still-active plugin keeps a
	 *                          valid schema (in-place reset).
	 * @return array{posts:int,tables:int,options:int} Removed counts.
	 */
	public static function purge_all_data( bool $drop_tables = true ): array {
		$posts   = self::delete_posts();
		$tables  = self::delete_tables( $drop_tables );
		$options = self::delete_options();
		self::delete_uploads();

		return array(
			'posts'   => $posts,
			'tables'  => $tables,
			'options' => $options,
		);
	}

	/**
	 * Force-delete every post of this plugin's CPTs (removes post meta too).
	 *
	 * @return int Number of posts deleted.
	 */
	private static function delete_posts(): int {
		global $wpdb;

		$deleted = 0;
		foreach ( self::POST_TYPES as $type ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $type )
			);
			foreach ( (array) $ids as $id ) {
				if ( wp_delete_post( (int) $id, true ) ) {
					$deleted++;
				}
			}
		}

		return $deleted;
	}

	/**
	 * Drop or truncate every custom table.
	 *
	 * @param bool $drop true → DROP TABLE; false → TRUNCATE TABLE.
	 * @return int Number of tables acted on.
	 */
	private static function delete_tables( bool $drop ): int {
		global $wpdb;

		$count = 0;
		foreach ( self::TABLES as $suffix ) {
			$table = $wpdb->prefix . $suffix; // constant suffix — not user input.
			$sql   = $drop ? "DROP TABLE IF EXISTS `{$table}`" : "TRUNCATE TABLE `{$table}`";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL on a known plugin-owned table name.
			if ( false !== $wpdb->query( $sql ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Delete all plugin options + transients (via the shared matcher).
	 *
	 * @return int Number of option rows deleted.
	 */
	private static function delete_options(): int {
		$names   = self::plugin_option_names();
		$deleted = 0;
		foreach ( $names as $name ) {
			if ( delete_option( $name ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * Recursively delete the uploads/eem-reports cached-export directory. Scoped
	 * strictly to that directory (realpath-guarded) so nothing outside it is touched.
	 *
	 * @return void
	 */
	private static function delete_uploads(): void {
		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return;
		}

		$base   = (string) trailingslashit( $uploads['basedir'] );
		$target = $base . self::UPLOAD_SUBDIR;

		$real_base   = realpath( $base );
		$real_target = realpath( $target );
		// Bail unless the target exists AND is genuinely inside the uploads dir.
		if ( ! $real_base || ! $real_target || 0 !== strpos( $real_target, $real_base ) ) {
			return;
		}

		self::rrmdir( $real_target );
	}

	/**
	 * Recursively remove a directory and its contents.
	 *
	 * @param string $dir Absolute path (already realpath-validated by caller).
	 * @return void
	 */
	private static function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::rrmdir( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup.
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup.
	}

	/**
	 * Row count for a custom table (0 if the table is absent).
	 *
	 * @param string $suffix Table suffix (without prefix).
	 * @return int
	 */
	private static function count_table_rows( string $suffix ): int {
		global $wpdb;
		$table  = $wpdb->prefix . $suffix;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $exists !== $table ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- known plugin table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}
}
