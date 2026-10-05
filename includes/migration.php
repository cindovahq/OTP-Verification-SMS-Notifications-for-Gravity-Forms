<?php
/**
 * One-time migration of the settings of version 2.9.98 (the "Gravity Forms OTP and SMS
 * Notifications" plugin) to the two Gravity Forms add-ons.
 *
 * Legacy options and form keys are never deleted here (rollback safety); uninstall.php
 * removes them unless the old plugin is still active.
 *
 * @package CindovaGfOtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Per-form keys used by version 2.9.98.
 *
 * @return string[]
 */
function cindova_gfotp_legacy_form_keys() {
	return array(
		'gf_firebase_phone_field_id',
		'gf_firebase_otp_field_id',
		'gf_msg91_custom_message',
		'gf_msg91_template_id',
		'gf_twilio_custom_message',
		'gf_slack_enabled',
		'gf_slack_message',
	);
}

/**
 * Whether a value is empty for migration purposes (missing, '' or an empty array).
 *
 * @param mixed $value Value.
 * @return bool
 */
function cindova_gfotp_migration_is_blank( $value ) {
	return null === $value || '' === $value || array() === $value;
}

/**
 * Copy the legacy global options into the add-on plugin settings. A group is only copied
 * while the corresponding new settings are still empty.
 *
 * @return void
 */
function cindova_gfotp_migrate_global_settings() {
	$missing = new stdClass(); // Sentinel to tell "absent" from "empty".

	// Phone OTP add-on: Firebase config.
	$otp_addon    = Cindova_GFOTP_Addon::get_instance();
	$otp_settings = $otp_addon->get_plugin_settings();
	$otp_settings = is_array( $otp_settings ) ? $otp_settings : array();
	$legacy       = get_option( 'gf_firebase_config', $missing );
	if ( $legacy !== $missing && cindova_gfotp_migration_is_blank( rgar( $otp_settings, 'firebase_config' ) ) ) {
		$normalized = cindova_gfotp_sanitize_firebase_config( $legacy );
		if ( false !== $normalized ) {
			$otp_settings['firebase_config'] = $normalized;
			$otp_addon->update_plugin_settings( $otp_settings );
		}
	}

	// SMS & Slack add-on.
	$addon    = Cindova_GFOTP_Notifications_Addon::get_instance();
	$settings = $addon->get_plugin_settings();
	$settings = is_array( $settings ) ? $settings : array();
	$changed  = false;

	$provider = get_option( 'gf_sms_provider', $missing );
	if ( in_array( $provider, array( 'msg91', 'twilio' ), true ) && cindova_gfotp_migration_is_blank( rgar( $settings, 'sms_provider' ) ) ) {
		$settings['sms_provider'] = $provider;
		$changed                  = true;
	}

	$msg91 = get_option( 'gf_msg91_api_credentials', $missing );
	if ( is_array( $msg91 ) && cindova_gfotp_migration_is_blank( rgar( $settings, 'msg91_authkey' ) ) && cindova_gfotp_migration_is_blank( rgar( $settings, 'msg91_senderid' ) ) && cindova_gfotp_migration_is_blank( rgar( $settings, 'msg91_route' ) ) ) {
		$settings['msg91_authkey']  = (string) rgar( $msg91, 'msg91authkey' );
		$settings['msg91_senderid'] = (string) rgar( $msg91, 'msg91senderid' );
		$settings['msg91_route']    = (string) rgar( $msg91, 'msg91route' );
		if ( '' !== $settings['msg91_authkey'] && cindova_gfotp_migration_is_blank( rgar( $settings, 'msg91_api' ) ) ) {
			$settings['msg91_api'] = 'legacy'; // 2.x always used the sendhttp API.
		}
		$changed = true;
	}

	$twilio = get_option( 'gf_twilio_api_credentials', $missing );
	if ( is_array( $twilio ) && cindova_gfotp_migration_is_blank( rgar( $settings, 'twilio_phone' ) ) && cindova_gfotp_migration_is_blank( rgar( $settings, 'twilio_sid' ) ) && cindova_gfotp_migration_is_blank( rgar( $settings, 'twilio_token' ) ) ) {
		$settings['twilio_phone'] = (string) rgar( $twilio, 'phone' );
		$settings['twilio_sid']   = (string) rgar( $twilio, 'sid' );
		$settings['twilio_token'] = (string) rgar( $twilio, 'token' );
		$changed                  = true;
	}

	$slack_token = get_option( 'gf_slack_bot_token', $missing );
	if ( is_string( $slack_token ) && '' !== $slack_token && cindova_gfotp_migration_is_blank( rgar( $settings, 'slack_bot_token' ) ) ) {
		$settings['slack_bot_token'] = $slack_token;
		$changed                     = true;
	}
	$slack_channel = get_option( 'gf_slack_selected_channel', $missing );
	if ( is_string( $slack_channel ) && '' !== $slack_channel && cindova_gfotp_migration_is_blank( rgar( $settings, 'slack_channel' ) ) ) {
		$settings['slack_channel'] = $slack_channel;
		$changed                   = true;
	}
	if ( $changed ) {
		$addon->update_plugin_settings( $settings );
	}

	$slack_channels = get_option( 'gf_slack_channels', $missing );
	if ( is_array( $slack_channels ) && ! empty( $slack_channels ) && cindova_gfotp_migration_is_blank( get_option( 'cindova_gfotp_slack_channels', array() ) ) ) {
		update_option( 'cindova_gfotp_slack_channels', $slack_channels, false );
	}
}

/**
 * Convert the legacy per-form keys of every form into OTP settings and notification feeds.
 *
 * A form is only recorded as migrated when everything for it was written. If creating a
 * feed fails (for example while Gravity Forms is upgrading its database), the feeds made
 * for that form are removed again and the form is retried on the next run.
 *
 * @return bool True when every form was migrated, false when at least one must be retried.
 */
function cindova_gfotp_migrate_forms() {
	$done = get_option( 'cindova_gfotp_migrated_forms', array() );
	$done = is_array( $done ) ? array_map( 'intval', $done ) : array();

	$forms = GFAPI::get_forms( null );
	if ( ! is_array( $forms ) ) {
		return false;
	}

	$provider = cindova_gfotp_get_sms_provider();
	$legacy   = cindova_gfotp_legacy_form_keys();
	$all_done = true;

	foreach ( $forms as $form ) {
		$form_id = (int) rgar( $form, 'id' );
		if ( ! $form_id || in_array( $form_id, $done, true ) || isset( $form[ CINDOVA_GFOTP_SLUG ] ) ) {
			continue;
		}

		$has_legacy = false;
		foreach ( $legacy as $key ) {
			if ( ! cindova_gfotp_migration_is_blank( rgar( $form, $key ) ) ) {
				$has_legacy = true;
				break;
			}
		}
		if ( ! $has_legacy ) {
			continue;
		}

		$phone_id = (string) rgar( $form, 'gf_firebase_phone_field_id' );
		$otp_id   = (string) rgar( $form, 'gf_firebase_otp_field_id' );

		$feeds = array();
		$sms   = (string) rgar( $form, 'twilio' === $provider ? 'gf_twilio_custom_message' : 'gf_msg91_custom_message' );
		if ( '' !== $phone_id && '' !== $sms ) {
			$feeds[] = array(
				'feedName'                         => __( 'SMS notification', 'cindova-phone-otp-sms-for-gravity-forms' ),
				'notification_type'                => 'sms',
				'phone_field'                      => $phone_id,
				'message'                          => $sms,
				'msg91_template_id'                => (string) rgar( $form, 'gf_msg91_template_id' ),
				'msg91_variables'                  => '',
				'feed_condition_conditional_logic' => '0',
			);
		}
		$slack_message = (string) rgar( $form, 'gf_slack_message' );
		if ( '1' === (string) rgar( $form, 'gf_slack_enabled' ) && '' !== $slack_message ) {
			$feeds[] = array(
				'feedName'                         => __( 'Slack notification', 'cindova-phone-otp-sms-for-gravity-forms' ),
				'notification_type'                => 'slack',
				'slack_message'                    => $slack_message,
				'slack_channel'                    => '',
				'feed_condition_conditional_logic' => '0',
			);
		}

		$created = array();
		$failed  = false;
		foreach ( $feeds as $meta ) {
			$feed_id = GFAPI::add_feed( $form_id, $meta, CINDOVA_GFOTP_NOTIFY_SLUG );
			if ( is_wp_error( $feed_id ) ) {
				cindova_gfotp_log( 'Migration: could not create a notification feed for form ' . $form_id . ': ' . $feed_id->get_error_message() );
				$failed = true;
				break;
			}
			$created[] = $feed_id;
		}

		// The OTP settings go last: a form that has them is considered complete.
		if ( ! $failed && '' !== $phone_id && '' !== $otp_id ) {
			$form[ CINDOVA_GFOTP_SLUG ] = array(
				'enabled'        => '1',
				'phone_field_id' => $phone_id,
				'otp_field_id'   => $otp_id,
			);
			$updated                    = GFAPI::update_form( $form );
			if ( is_wp_error( $updated ) ) {
				cindova_gfotp_log( 'Migration: could not save the OTP settings of form ' . $form_id . ': ' . $updated->get_error_message() );
				$failed = true;
			}
		}

		if ( $failed ) {
			foreach ( $created as $feed_id ) {
				GFAPI::delete_feed( $feed_id );
			}
			$all_done = false;
			continue;
		}

		$done[] = $form_id;
		update_option( 'cindova_gfotp_migrated_forms', array_values( array_unique( $done ) ), false );
	}

	return $all_done;
}

/**
 * Run the migration (global settings, then forms). Idempotent.
 *
 * @return bool True when complete, false when some forms must be retried on the next request.
 */
function cindova_gfotp_run_migration() {
	// The add-on setup() creates the feeds table; it normally ran during their init.
	Cindova_GFOTP_Notifications_Addon::get_instance()->setup();

	cindova_gfotp_migrate_global_settings();
	return cindova_gfotp_migrate_forms();
}

/**
 * Run the migration once, after both add-ons have initialized (they init at priority 15).
 *
 * @return void
 */
function cindova_gfotp_maybe_migrate() {
	if ( '1' === get_option( 'cindova_gfotp_migrated' ) ) {
		return;
	}
	if ( ! class_exists( 'Cindova_GFOTP_Addon' ) || ! class_exists( 'Cindova_GFOTP_Notifications_Addon' ) ) {
		return;
	}
	if ( cindova_gfotp_run_migration() ) {
		update_option( 'cindova_gfotp_migrated', '1' );
	}
}
add_action( 'init', 'cindova_gfotp_maybe_migrate', 20 );
