<?php
/**
 * Plugins screen link and privacy policy text.
 *
 * @package CindovaGfOtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Add a Settings link on the Plugins screen (Forms > Settings > Phone OTP).
 *
 * @param array $links Existing action links.
 * @return array
 */
function cindova_gfotp_action_links( $links ) {
	$url = admin_url( 'admin.php?page=gf_settings&subview=' . CINDOVA_GFOTP_SLUG );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'otp-verification-sms-for-gravity-forms' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( CINDOVA_GFOTP_FILE ), 'cindova_gfotp_action_links' );

/**
 * Suggest privacy policy text (Settings > Privacy > Policy Guide).
 *
 * @return void
 */
function cindova_gfotp_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	$content = '<p>' . esc_html__( 'When you verify your phone number on a form, your phone number is sent from your browser to Google Firebase Authentication, which sends you a one-time code by SMS and uses Google reCAPTCHA to prevent abuse. See the Google Privacy Policy: https://policies.google.com/privacy', 'otp-verification-sms-for-gravity-forms' ) . '</p>'
		. '<p>' . esc_html__( 'After you submit a form, we may send an SMS to the phone number you entered through our SMS provider (MSG91 or Twilio), and we may post a notification containing some of the information you submitted to our Slack workspace. These providers process that data under their own privacy policies.', 'otp-verification-sms-for-gravity-forms' ) . '</p>';

	wp_add_privacy_policy_content(
		__( 'OTP Verification & SMS Notifications for Gravity Forms', 'otp-verification-sms-for-gravity-forms' ),
		wp_kses_post( $content )
	);
}
add_action( 'admin_init', 'cindova_gfotp_privacy_policy_content' );
