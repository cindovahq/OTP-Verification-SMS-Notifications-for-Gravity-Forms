<?php
/**
 * Uninstall: remove all plugin data.
 *
 * @package CindovaGfOtp
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// If the pre-3.0 plugin (separate folder) is still active, it still uses the
// legacy gf_* settings, so leave them in place and remove only our own data.
$cindova_gfotp_keep_legacy = function_exists( 'gf_firebase_otp_after_submission_send_sms_unified' );

$cindova_gfotp_slugs = array( 'cindova-gfotp', 'cindova-gfotp-notify' );

// Feeds of the notifications add-on (the shared feed table and its version option are left alone).
if ( class_exists( 'GFAPI' ) ) {
	$cindova_gfotp_feeds = GFAPI::get_feeds( null, null, 'cindova-gfotp-notify', null );
	foreach ( is_array( $cindova_gfotp_feeds ) ? $cindova_gfotp_feeds : array() as $cindova_gfotp_feed ) {
		GFAPI::delete_feed( $cindova_gfotp_feed['id'] );
	}
}

$cindova_gfotp_options = array(
	'cindova_gfotp_slack_channels',
	'cindova_gfotp_migrated',
	'cindova_gfotp_migrated_forms',
);
foreach ( $cindova_gfotp_slugs as $cindova_gfotp_slug ) {
	$cindova_gfotp_options[] = 'gravityformsaddon_' . $cindova_gfotp_slug . '_settings';
	$cindova_gfotp_options[] = 'gravityformsaddon_' . $cindova_gfotp_slug . '_version';
}
if ( ! $cindova_gfotp_keep_legacy ) {
	// Legacy (pre-3.0) option names.
	$cindova_gfotp_options = array_merge(
		$cindova_gfotp_options,
		array(
			'gf_firebase_config',
			'gf_sms_provider',
			'gf_msg91_api_credentials',
			'gf_twilio_api_credentials',
			'gf_slack_bot_token',
			'gf_slack_channels',
			'gf_slack_selected_channel',
		)
	);
}
foreach ( $cindova_gfotp_options as $cindova_gfotp_option ) {
	delete_option( $cindova_gfotp_option );
}

global $wpdb;
// Transient names are dynamic (cert cache and per-token markers), so they must be removed by prefix. Direct query is the only way to match them.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_cindova_gfotp_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_cindova_gfotp_' ) . '%'
	)
);

if ( class_exists( 'GFAPI' ) ) {
	$cindova_gfotp_form_keys = array( 'cindova-gfotp' );
	if ( ! $cindova_gfotp_keep_legacy ) {
		$cindova_gfotp_form_keys = array_merge(
			$cindova_gfotp_form_keys,
			array(
				'gf_firebase_phone_field_id',
				'gf_firebase_otp_field_id',
				'gf_msg91_custom_message',
				'gf_msg91_template_id',
				'gf_twilio_custom_message',
				'gf_slack_enabled',
				'gf_slack_message',
			)
		);
	}
	$cindova_gfotp_forms = GFAPI::get_forms( null );
	foreach ( is_array( $cindova_gfotp_forms ) ? $cindova_gfotp_forms : array() as $cindova_gfotp_form ) {
		$cindova_gfotp_changed = false;
		foreach ( $cindova_gfotp_form_keys as $cindova_gfotp_key ) {
			if ( array_key_exists( $cindova_gfotp_key, $cindova_gfotp_form ) ) {
				unset( $cindova_gfotp_form[ $cindova_gfotp_key ] );
				$cindova_gfotp_changed = true;
			}
		}
		if ( $cindova_gfotp_changed ) {
			GFAPI::update_form( $cindova_gfotp_form );
		}
	}
}
