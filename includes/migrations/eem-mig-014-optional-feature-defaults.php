<?php
/**
 * eem-mig-014 — optional-feature defaults (Entries + Sheets & Results).
 *
 * The two optional features (Settings → Add-Ons) default differently by install
 * age, per Whitney (2026-06-13): **ON for existing sites** (no surprise — they
 * were already using them) and **OFF for new installs** (opt-in). We tell the
 * two apart by whether a DB-version was already stored when this migration first
 * runs: run_one_time_migrations() executes BEFORE the activator writes the
 * current DB_VERSION_OPTION, so a non-empty value means a pre-existing install
 * upgrading, and an empty value means a brand-new activation.
 *
 * Only writes keys that aren't already set, so a customer's later toggle choice
 * is never overwritten. Idempotent (option-guarded in run_one_time_migrations).
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seed `entries_enabled` + `sheets_results_enabled` in feature_settings.
 *
 * @return void
 */
function eem_mig_014_optional_feature_defaults() {
	$option   = class_exists( 'EEM_Events' ) ? EEM_Events::FEATURES_SETTINGS_OPTION : 'equine_event_manager_feature_settings';
	$features = get_option( $option, array() );
	if ( ! is_array( $features ) ) {
		$features = array();
	}

	// Existing install (already had a stored DB version) → ON; new install → OFF.
	$installed = (string) get_option( EEM_Activator::DB_VERSION_OPTION, '' );
	$default   = '' !== $installed ? 1 : 0;

	if ( ! array_key_exists( 'entries_enabled', $features ) ) {
		$features['entries_enabled'] = $default;
	}
	if ( ! array_key_exists( 'sheets_results_enabled', $features ) ) {
		$features['sheets_results_enabled'] = $default;
	}

	update_option( $option, $features );
	update_option( 'eem_mig_014_optional_feature_defaults_complete', 1 );
}
