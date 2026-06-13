<?php
/**
 * Term Categories admin page smoke (Native Events Admin B).
 *
 * Verifies the branded EEM_Term_Categories_Page that replaces the default WP
 * edit-tags.php screens for en_event_category / en_venue_category /
 * en_producer_category: config + taxonomy mapping, list render (split form +
 * table chrome, content density), edit-mode render, and the write-path gates
 * (cap + nonce + admin-post action registration).
 *
 * AJAX/admin-post handlers call wp_safe_redirect()+exit (which kills the CLI
 * runner), so — per the canonical pattern (see venues-page-smoke) — the gates
 * the handlers rely on are tested directly rather than invoking the handlers.
 *
 * Run: wp eval-file tests/smoke/term-categories-page-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

// Determinism guard: the en_*_category taxonomies register on `init` only when
// the native_events feature flag is on (and that flag can be toggled off between
// runs). Register the content types directly so this smoke does not depend on the
// flag state / init ordering of the current request.
if ( ! taxonomy_exists( 'en_event_category' ) && class_exists( 'EEM_Events' ) ) {
	( new EEM_Events() )->register_content_types();
}

// --- class + config --------------------------------------------------------
$check( 'page class loaded', class_exists( 'EEM_Term_Categories_Page' ) );
$slugs = EEM_Term_Categories_Page::slugs();
$check( 'three category page slugs', is_array( $slugs ) && 3 === count( $slugs ) );
$check( 'event-categories slug present', in_array( 'equine-event-manager-event-categories', $slugs, true ) );
$check( 'venue-categories slug present', in_array( 'equine-event-manager-venue-categories', $slugs, true ) );
$check( 'producer-categories slug present', in_array( 'equine-event-manager-producer-categories', $slugs, true ) );
$check( 'taxonomy → slug maps event', EEM_Term_Categories_Page::slug_for_taxonomy( 'en_event_category' ) === 'equine-event-manager-event-categories' );
$check( 'taxonomy → slug maps venue', EEM_Term_Categories_Page::slug_for_taxonomy( 'en_venue_category' ) === 'equine-event-manager-venue-categories' );
$check( 'taxonomy → slug maps producer', EEM_Term_Categories_Page::slug_for_taxonomy( 'en_producer_category' ) === 'equine-event-manager-producer-categories' );
$check( 'unknown taxonomy → empty', EEM_Term_Categories_Page::slug_for_taxonomy( 'category' ) === '' );

// --- write-path gates ------------------------------------------------------
$check( 'save action registered', false !== has_action( 'admin_post_eem_save_term' ) );
$check( 'delete action registered', false !== has_action( 'admin_post_eem_delete_term' ) );
$check( 'bulk-delete action registered', false !== has_action( 'admin_post_eem_bulk_delete_terms' ) );

$saved_user = get_current_user_id();
wp_set_current_user( 0 );
$check( 'capability gate rejects non-admins', ! current_user_can( 'manage_options' ) );
wp_set_current_user( $saved_user );
$check( 'capability gate accepts admin', current_user_can( 'manage_options' ) );
$check( 'save nonce verifies', false !== wp_verify_nonce( wp_create_nonce( 'eem_save_term' ), 'eem_save_term' ) );

// --- seed terms (parent + child) -------------------------------------------
$suffix = substr( md5( (string) wp_rand() ), 0, 6 );
$parent = wp_insert_term( 'Western ' . $suffix, 'en_event_category', array( 'slug' => 'western-' . $suffix, 'description' => 'Western disciplines' ) );
$check( 'seed parent term created', ! is_wp_error( $parent ) && ! empty( $parent['term_id'] ) );
$parent_id = is_wp_error( $parent ) ? 0 : (int) $parent['term_id'];
$child = wp_insert_term( 'Reining ' . $suffix, 'en_event_category', array( 'slug' => 'reining-' . $suffix, 'parent' => $parent_id ) );
$check( 'seed child term created', ! is_wp_error( $child ) && ! empty( $child['term_id'] ) );
$child_id = is_wp_error( $child ) ? 0 : (int) $child['term_id'];

// --- list render -----------------------------------------------------------
ob_start();
$_GET = array( 'page' => 'equine-event-manager-event-categories' );
EEM_Term_Categories_Page::render();
$list = ob_get_clean();
$check( 'list renders the split body', false !== strpos( $list, 'eem-term-split' ) );
$check( 'list renders the Add form panel', false !== strpos( $list, 'eem-term-form-panel' ) );
$check( 'list shows "Add New Category" title', false !== strpos( $list, 'Add New Category' ) );
$check( 'list renders name/slug/parent/desc fields', false !== strpos( $list, 'name="term_name"' ) && false !== strpos( $list, 'name="term_slug"' ) && false !== strpos( $list, 'name="term_parent"' ) && false !== strpos( $list, 'name="term_description"' ) );
$check( 'list parent select lists the seeded parent', false !== strpos( $list, 'Western ' . $suffix ) );
$check( 'list renders the shared table', false !== strpos( $list, 'eem-term-table' ) );
$check( 'list renders the bulk form', false !== strpos( $list, 'id="eem-term-bulk-form"' ) );
$check( 'list renders the search input', false !== strpos( $list, 'eem-search-input' ) );
$check( 'list shows the parent term name', false !== strpos( $list, 'Western ' . $suffix ) );
$check( 'list shows the child term indented', false !== strpos( $list, '— Reining ' . $suffix ) );
$check( 'list shows the slug chip', false !== strpos( $list, 'eem-term-slug' ) && false !== strpos( $list, 'western-' . $suffix ) );
$check( 'list shows the count cell', false !== strpos( $list, 'eem-term-count' ) );
$check( 'list shows the description', false !== strpos( $list, 'Western disciplines' ) );
$check( 'list wires the row dropdown', false !== strpos( $list, 'data-eem-action="dropdown-toggle"' ) );
$check( 'list wires the delete confirm action', false !== strpos( $list, 'data-eem-action="eem-term-delete"' ) );
$check( 'list checkboxes associate to bulk form', (bool) preg_match( '/name="term_ids\[\]"[^>]*form="eem-term-bulk-form"/', $list ) || (bool) preg_match( '/form="eem-term-bulk-form"[^>]*name="term_ids\[\]"/', $list ) );
$check( 'list select-all toggles the bulk form', false !== strpos( $list, 'data-eem-action="eem-term-toggle-all"' ) );

// --- edit render -----------------------------------------------------------
ob_start();
$_GET = array( 'page' => 'equine-event-manager-event-categories', 'edit' => (string) $child_id );
EEM_Term_Categories_Page::render();
$edit = ob_get_clean();
$check( 'edit mode shows "Edit Category" title', false !== strpos( $edit, 'Edit Category' ) );
$check( 'edit mode shows the Update button', false !== strpos( $edit, 'Update Category' ) );
$check( 'edit mode carries the term_id hidden field', (bool) preg_match( '/name="term_id" value="' . $child_id . '"/', $edit ) );
$check( 'edit mode prefills the name', false !== strpos( $edit, 'value="Reining ' . $suffix . '"' ) );
$check( 'edit mode shows a Cancel link', false !== strpos( $edit, 'Cancel' ) );
$check( 'edit parent select excludes self', false === strpos( $edit, 'value="' . $child_id . '" selected' ) );

// --- search/flat render ----------------------------------------------------
ob_start();
$_GET = array( 'page' => 'equine-event-manager-event-categories', 's' => 'Reining ' . $suffix );
EEM_Term_Categories_Page::render();
$search = ob_get_clean();
// The parent still appears as a <option> in the Add form's parent select, so
// assert against the table-row anchor specifically ("…</a>"), not the option.
$check( 'search shows the matching term row', false !== strpos( $search, 'Reining ' . $suffix . '</a>' ) );
$check( 'search hides the non-matching parent row', false === strpos( $search, 'Western ' . $suffix . '</a>' ) );

// --- cleanup ---------------------------------------------------------------
if ( $child_id ) { wp_delete_term( $child_id, 'en_event_category' ); }
if ( $parent_id ) { wp_delete_term( $parent_id, 'en_event_category' ); }
$_GET = array();

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
