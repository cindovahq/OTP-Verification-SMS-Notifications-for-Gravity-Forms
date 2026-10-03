<?php
/**
 * Slack provider (bot token).
 *
 * @package CindovaGfOtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Escape a value for Slack mrkdwn so form input cannot inject mentions or links.
 *
 * @param string $value Raw value.
 * @return string
 */
function cindova_gfotp_slack_escape( $value ) {
	return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $value );
}

/**
 * Fetch the channels the bot can see.
 *
 * @param string $bot_token Slack bot token.
 * @return array|WP_Error List of array( 'id' => ..., 'name' => ... ).
 */
function cindova_gfotp_slack_fetch_channels( $bot_token ) {
	$channels = array();
	$cursor   = '';

	for ( $page = 0; $page < 10; $page++ ) {
		$url = 'https://slack.com/api/conversations.list?types=public_channel,private_channel&exclude_archived=true&limit=200';
		if ( '' !== $cursor ) {
			$url .= '&cursor=' . rawurlencode( $cursor );
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $bot_token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'cindova_gfotp_slack_bad_response', 'Unexpected response from Slack.' );
		}
		if ( empty( $data['ok'] ) ) {
			$code = isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : 'unknown_error';
			return new WP_Error( 'cindova_gfotp_slack_error', $code );
		}
		if ( ! empty( $data['channels'] ) && is_array( $data['channels'] ) ) {
			foreach ( $data['channels'] as $chan ) {
				$channels[] = array(
					'id'   => isset( $chan['id'] ) ? sanitize_text_field( $chan['id'] ) : '',
					'name' => isset( $chan['name'] ) ? sanitize_text_field( $chan['name'] ) : 'unknown',
				);
			}
		}
		$cursor = isset( $data['response_metadata']['next_cursor'] ) ? (string) $data['response_metadata']['next_cursor'] : '';
		if ( '' === $cursor ) {
			break;
		}
	}
	return $channels;
}

/**
 * Post a message to a Slack channel.
 *
 * @param string $message    Message with optional {{field_X}} placeholders and merge tags.
 * @param array  $entry      Entry used for placeholders and merge tags.
 * @param array  $form       Form used for merge tags.
 * @param string $channel_id Channel ID; empty uses the default channel from the SMS & Slack settings.
 * @param bool   $test_call  Return a human-readable result string instead of a boolean.
 * @return bool|string True on success (or the result text when $test_call is set).
 */
function cindova_gfotp_send_slack_notification( $message, $entry, $form = array(), $channel_id = '', $test_call = false ) {
	$bot_token = (string) cindova_gfotp_notify_setting( 'slack_bot_token', '' );
	if ( '' === (string) $channel_id ) {
		$channel_id = (string) cindova_gfotp_notify_setting( 'slack_channel', '' );
	}
	if ( '' === $bot_token ) {
		cindova_gfotp_log( 'Slack: bot token missing.' );
		return $test_call ? __( 'Slack bot token missing.', 'otp-verification-sms-for-gravity-forms' ) : false;
	}
	if ( '' === $channel_id ) {
		cindova_gfotp_log( 'Slack: no channel selected.' );
		return $test_call ? __( 'No Slack channel selected.', 'otp-verification-sms-for-gravity-forms' ) : false;
	}

	$response = wp_remote_post(
		'https://slack.com/api/chat.postMessage',
		array(
			'timeout' => 20,
			'headers' => array(
				'Content-Type'  => 'application/json; charset=utf-8',
				'Authorization' => 'Bearer ' . $bot_token,
			),
			'body'    => wp_json_encode(
				array(
					'channel' => $channel_id,
					'text'    => cindova_gfotp_render_message( $message, $form, $entry, 'slack' ),
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		cindova_gfotp_log( 'Slack request failed: ' . $response->get_error_message() );
		return $test_call ? $response->get_error_message() : false;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( is_array( $data ) && empty( $data['ok'] ) ) {
		cindova_gfotp_log( 'Slack postMessage error: ' . ( isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : 'unknown' ) );
		return $test_call ? 'HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) : false;
	}
	return $test_call ? 'HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) : true;
}
