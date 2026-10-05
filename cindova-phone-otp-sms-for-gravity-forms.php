<?php
/**
 * Plugin Name:       Cindova Phone OTP & SMS for Gravity Forms
 * Plugin URI:        https://github.com/cindovahq/OTP-Verification-SMS-Notifications-for-Gravity-Forms
 * Description:       Adds Firebase phone OTP verification to Gravity Forms, plus SMS notifications via MSG91 or Twilio and Slack notifications.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Cindova Technologies
 * Author URI:        https://www.cindova.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cindova-phone-otp-sms-for-gravity-forms
 * Domain Path:       /languages
 *
 * @package CindovaGfOtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'CINDOVA_GFOTP_VERSION', '1.0.0' );
define( 'CINDOVA_GFOTP_FILE', __FILE__ );
define( 'CINDOVA_GFOTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'CINDOVA_GFOTP_URL', plugin_dir_url( __FILE__ ) );
define( 'CINDOVA_GFOTP_SLUG', 'cindova-gfotp' );
define( 'CINDOVA_GFOTP_NOTIFY_SLUG', 'cindova-gfotp-notify' );

/**
 * Whether the installed Gravity Forms is present and recent enough.
 *
 * @return bool
 */
function cindova_gfotp_gravity_forms_ok() {
	if ( ! class_exists( 'GFForms' ) ) {
		return false;
	}
	return ! isset( GFForms::$version ) || version_compare( GFForms::$version, '2.5', '>=' );
}

/**
 * Load the plugin and register its two Gravity Forms add-ons once Gravity Forms has loaded.
 *
 * @return void
 */
function cindova_gfotp_load() {
	if ( ! cindova_gfotp_gravity_forms_ok() ) {
		return;
	}

	require_once CINDOVA_GFOTP_DIR . 'includes/helpers.php';
	require_once CINDOVA_GFOTP_DIR . 'includes/migration.php';
	require_once CINDOVA_GFOTP_DIR . 'includes/token-verifier.php';
	require_once CINDOVA_GFOTP_DIR . 'includes/providers/msg91.php';
	require_once CINDOVA_GFOTP_DIR . 'includes/providers/twilio.php';
	require_once CINDOVA_GFOTP_DIR . 'includes/providers/slack.php';
	require_once CINDOVA_GFOTP_DIR . 'includes/otp.php';
	require_once CINDOVA_GFOTP_DIR . 'includes/admin-settings.php';

	GFForms::include_addon_framework();
	GFForms::include_feed_addon_framework();
	require_once CINDOVA_GFOTP_DIR . 'includes/trait-cindova-gfotp-addon-common.php';
	require_once CINDOVA_GFOTP_DIR . 'includes/class-cindova-gfotp-addon.php';
	require_once CINDOVA_GFOTP_DIR . 'includes/class-cindova-gfotp-notifications-addon.php';

	GFAddOn::register( 'Cindova_GFOTP_Addon' );
	GFAddOn::register( 'Cindova_GFOTP_Notifications_Addon' );
}
add_action( 'gform_loaded', 'cindova_gfotp_load', 5 );

/**
 * Whether the current admin screen is one where this plugin's notices belong
 * (the Plugins screen or Gravity Forms' settings screen), per guideline 11.
 *
 * @return bool
 */
function cindova_gfotp_is_notice_screen() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	return $screen && in_array( $screen->id, array( 'plugins', 'plugins-network', 'forms_page_gf_settings' ), true );
}

/**
 * Show an admin notice when Gravity Forms is missing or too old.
 *
 * @return void
 */
function cindova_gfotp_missing_gf_notice() {
	if ( ! current_user_can( 'activate_plugins' ) || cindova_gfotp_gravity_forms_ok() || ! cindova_gfotp_is_notice_screen() ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Cindova Phone OTP & SMS for Gravity Forms requires Gravity Forms 2.5 or later. Install and activate Gravity Forms (or update it to 2.5+), or deactivate this plugin. This notice disappears once Gravity Forms is active.', 'cindova-phone-otp-sms-for-gravity-forms' )
	);
}
add_action( 'admin_notices', 'cindova_gfotp_missing_gf_notice' );

/**
 * Warn when the pre-3.0 version of this plugin (different folder) is still active,
 * because both would send OTP checks and SMS/Slack notifications.
 *
 * @return void
 */
function cindova_gfotp_legacy_plugin_notice() {
	if ( ! current_user_can( 'activate_plugins' ) || ! function_exists( 'gf_firebase_otp_after_submission_send_sms_unified' ) || ! cindova_gfotp_is_notice_screen() ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'An older version of "Gravity Forms OTP and SMS Notifications" (2.x) is still active. Its settings have been copied to Cindova Phone OTP & SMS for Gravity Forms; please deactivate and delete the old plugin to avoid duplicate SMS and Slack messages.', 'cindova-phone-otp-sms-for-gravity-forms' )
	);
}
add_action( 'admin_notices', 'cindova_gfotp_legacy_plugin_notice' );
