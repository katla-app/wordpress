<?php
/**
 * Uninstall routine.
 *
 * Only removes data when the site opted in under Settings → Katla Consent →
 * Tools, so an accidental delete-and-reinstall keeps the configuration.
 *
 * @package Katla
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$katla_settings = get_option( 'katla_consent_settings', array() );

if ( ! is_array( $katla_settings ) || empty( $katla_settings['delete_data_on_uninstall'] ) ) {
	return;
}

// Cached remote responses.
$katla_index = get_option( 'katla_cache_index', array() );

if ( is_array( $katla_index ) ) {
	foreach ( $katla_index as $katla_transient ) {
		delete_transient( (string) $katla_transient );
	}
}

delete_option( 'katla_cache_index' );
delete_option( 'katla_consent_settings' );
delete_option( 'katla_seen_scripts' );
delete_option( 'katla_cookie_policy_page' );
delete_transient( 'katla_seen_scripts_lock' );
delete_transient( 'katla_admin_notice' );

if ( is_multisite() ) {
	delete_site_transient( 'katla_admin_notice' );
}
