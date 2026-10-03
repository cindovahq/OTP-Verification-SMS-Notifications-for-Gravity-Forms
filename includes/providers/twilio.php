<?php
/**
 * Twilio SMS provider.
 *
 * @package CindovaGfOtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Send an SMS through Twilio.
 *
 * @param string $mobile    Recipient number.
 * @param string $message   Message with optional {{field_X}} placeholders and merge tags.
 * @param bool   $test_call Return a human-readable result string instead of a boolean.
 * @param array  $entry     Entry used for placeholders and merge tags.
 * @param array  $form      Form used for merge tags.
 * @return bool|string
 */
function cindova_gfotp_send_twilio_sms( $mobile, $message, $test_call = false, $entry = array(), $form = array() ) {
	$creds = cindova_gfotp_get_twilio_credentials();
	$from  = $creds['phone'];
	$sid   = $creds['sid'];
	$token = $creds['token'];

	$response = wp_remote_post(
		'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $sid ) . '/Messages.json',
		array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth header.
			),
			'body'    => array(
				'From' => $from,
				'To'   => $mobile,
				'Body' => cindova_gfotp_render_message( $message, $form, $entry, 'sms' ),
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		cindova_gfotp_log( 'Twilio request failed: ' . $response->get_error_message() );
		return $test_call ? $response->get_error_message() : false;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		cindova_gfotp_log( 'Twilio returned HTTP ' . $code );
	}
	if ( $test_call ) {
		return 'HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response );
	}
	return $code >= 200 && $code < 300;
}
