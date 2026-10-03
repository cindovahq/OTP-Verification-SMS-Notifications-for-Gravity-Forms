<?php
/**
 * MSG91 SMS provider.
 *
 * @package CindovaGfOtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Send an SMS through the MSG91 legacy sendhttp API.
 *
 * @param string $mobile           Recipient number.
 * @param string $message_template Message with optional {{field_X}} placeholders and merge tags.
 * @param string $template_id      Optional DLT template ID.
 * @param bool   $test_call        Return a human-readable result string instead of a boolean.
 * @param array  $entry            Entry used for placeholders and merge tags.
 * @param array  $form             Form used for merge tags.
 * @return bool|string
 */
function cindova_gfotp_send_msg91_sms( $mobile, $message_template, $template_id = '', $test_call = false, $entry = array(), $form = array() ) {
	$creds     = cindova_gfotp_get_msg91_credentials();
	$auth_key  = $creds['msg91authkey'];
	$sender_id = $creds['msg91senderid'];
	$route     = $creds['msg91route'];

	$message = urlencode( rtrim( cindova_gfotp_render_message( $message_template, $form, $entry, 'sms' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- Preserves the exact value MSG91 has always received.

	$body = array(
		'authkey' => $auth_key,
		'mobiles' => str_replace( '+', '', $mobile ),
		'message' => $message,
		'sender'  => $sender_id,
		'route'   => $route,
		'country' => 0,
		'unicode' => 1,
	);
	if ( ! empty( $template_id ) ) {
		$body['DLT_TE_ID'] = $template_id;
	}

	$response = wp_remote_post(
		'https://control.msg91.com/api/sendhttp.php',
		array(
			'timeout' => 20,
			'body'    => $body,
		)
	);

	if ( is_wp_error( $response ) ) {
		cindova_gfotp_log( 'MSG91 request failed: ' . $response->get_error_message() );
		return $test_call ? $response->get_error_message() : false;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		cindova_gfotp_log( 'MSG91 returned HTTP ' . $code );
	}
	if ( $test_call ) {
		return 'HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response );
	}
	return $code >= 200 && $code < 300;
}

/**
 * Send an SMS through the MSG91 Flow API v5.
 *
 * @param string $mobile        Recipient number.
 * @param string $template_id   Flow template ID.
 * @param string $variables     Variables, one name=value per line (values may use placeholders and merge tags).
 * @param bool   $test_call     Return a human-readable result string instead of a boolean.
 * @param array  $entry         Entry used for placeholders and merge tags.
 * @param array  $form          Form used for merge tags.
 * @return bool|string
 */
function cindova_gfotp_send_msg91_flow_sms( $mobile, $template_id, $variables, $test_call = false, $entry = array(), $form = array() ) {
	$creds    = cindova_gfotp_get_msg91_credentials();
	$auth_key = $creds['msg91authkey'];

	$recipient = array( 'mobiles' => preg_replace( '/\D/', '', (string) $mobile ) );
	foreach ( cindova_gfotp_parse_msg91_variables( $variables ) as $name => $value ) {
		// "+" keeps numeric-looking names intact and cannot override "mobiles".
		$recipient = $recipient + array( $name => cindova_gfotp_render_message( $value, $form, $entry, 'sms' ) );
	}

	$response = wp_remote_post(
		'https://control.msg91.com/api/v5/flow',
		array(
			'timeout' => 20,
			'headers' => array(
				'authkey'      => $auth_key,
				'accept'       => 'application/json',
				'content-type' => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'template_id'      => $template_id,
					'short_url'        => '0',
					'realTimeResponse' => '1',
					'recipients'       => array( $recipient ),
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		cindova_gfotp_log( 'MSG91 Flow request failed: ' . $response->get_error_message() );
		return $test_call ? $response->get_error_message() : false;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	if ( $code < 200 || $code >= 300 ) {
		cindova_gfotp_log( 'MSG91 Flow returned HTTP ' . $code );
	}
	if ( $test_call ) {
		return 'HTTP ' . $code . ': ' . $body;
	}
	if ( $code < 200 || $code >= 300 ) {
		return false;
	}
	$data = json_decode( $body, true );
	if ( is_array( $data ) && isset( $data['type'] ) && 'success' !== $data['type'] ) {
		cindova_gfotp_log( 'MSG91 Flow reported a non-success response.' );
		return false;
	}
	return true;
}
