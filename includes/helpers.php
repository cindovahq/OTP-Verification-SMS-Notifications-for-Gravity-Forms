<?php
/**
 * Shared helpers.
 *
 * @package CindovaGfOtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Write a message to the debug log (only when WP_DEBUG and WP_DEBUG_LOG are on).
 * Never pass secrets or tokens here.
 *
 * @param string $message Message to log.
 * @return void
 */
function cindova_gfotp_log( $message ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		error_log( '[OTP Verification & SMS] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated behind WP_DEBUG and WP_DEBUG_LOG.
	}
}

/**
 * Read one plugin setting of the Phone OTP add-on (Forms > Settings > Phone OTP).
 *
 * @param string $key     Setting name.
 * @param mixed  $fallback Value returned when the setting is missing.
 * @return mixed
 */
function cindova_gfotp_otp_setting( $key, $fallback = '' ) {
	if ( ! class_exists( 'Cindova_GFOTP_Addon' ) ) {
		return $fallback;
	}
	$settings = Cindova_GFOTP_Addon::get_instance()->get_plugin_settings();
	return is_array( $settings ) && isset( $settings[ $key ] ) ? $settings[ $key ] : $fallback;
}

/**
 * Read one plugin setting of the SMS & Slack add-on (Forms > Settings > SMS & Slack).
 *
 * @param string $key     Setting name.
 * @param mixed  $fallback Value returned when the setting is missing.
 * @return mixed
 */
function cindova_gfotp_notify_setting( $key, $fallback = '' ) {
	if ( ! class_exists( 'Cindova_GFOTP_Notifications_Addon' ) ) {
		return $fallback;
	}
	$settings = Cindova_GFOTP_Notifications_Addon::get_instance()->get_plugin_settings();
	return is_array( $settings ) && isset( $settings[ $key ] ) ? $settings[ $key ] : $fallback;
}

/**
 * Read a per-form OTP setting (stored by the Phone OTP add-on in $form['cindova-gfotp']).
 *
 * @param array  $form    Gravity Forms form array.
 * @param string $key     Setting name: enabled, phone_field_id or otp_field_id.
 * @param string $fallback Value returned when the setting is missing.
 * @return mixed
 */
function cindova_gfotp_get_form_setting( $form, $key, $fallback = '' ) {
	if ( isset( $form[ CINDOVA_GFOTP_SLUG ] ) && is_array( $form[ CINDOVA_GFOTP_SLUG ] ) && array_key_exists( $key, $form[ CINDOVA_GFOTP_SLUG ] ) ) {
		return $form[ CINDOVA_GFOTP_SLUG ][ $key ];
	}
	return $fallback;
}

/**
 * Get the saved Firebase web config as an array.
 *
 * @return array Decoded config or an empty array.
 */
function cindova_gfotp_get_firebase_config() {
	$raw = cindova_gfotp_otp_setting( 'firebase_config', '' );
	if ( ! is_string( $raw ) || '' === $raw ) {
		return array();
	}
	$config = json_decode( $raw, true );
	return is_array( $config ) ? $config : array();
}

/**
 * Whether phone OTP verification is fully configured for a form.
 *
 * @param array $form Gravity Forms form array.
 * @return bool
 */
function cindova_gfotp_is_otp_enabled( $form ) {
	if ( ! is_array( $form ) ) {
		return false;
	}
	if ( '1' !== (string) cindova_gfotp_get_form_setting( $form, 'enabled', '' ) ) {
		return false;
	}
	$phone_id = (string) cindova_gfotp_get_form_setting( $form, 'phone_field_id', '' );
	$otp_id   = (string) cindova_gfotp_get_form_setting( $form, 'otp_field_id', '' );
	if ( '' === $phone_id || '' === $otp_id ) {
		return false;
	}
	$config = cindova_gfotp_get_firebase_config();
	foreach ( array( 'apiKey', 'authDomain', 'projectId' ) as $required ) {
		if ( empty( $config[ $required ] ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Normalize and validate a pasted Firebase web config.
 *
 * Accepts the JSON text or the already decoded array (Gravity Forms decodes
 * posted JSON). Keys must be simple identifiers, every value a string; apiKey,
 * authDomain and projectId are required.
 *
 * @param string|array $input Raw JSON text or decoded object.
 * @return string|false Normalized JSON to store, or false when invalid.
 */
function cindova_gfotp_sanitize_firebase_config( $input ) {
	$decoded = is_array( $input ) ? $input : json_decode( (string) $input, true );
	if ( ! is_array( $decoded ) || empty( $decoded ) ) {
		return false;
	}
	$clean = array();
	foreach ( $decoded as $key => $value ) {
		if ( ! is_string( $key ) || 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $key ) || ! is_string( $value ) ) {
			return false;
		}
		$clean[ $key ] = sanitize_text_field( $value );
	}
	foreach ( array( 'apiKey', 'authDomain', 'projectId' ) as $required ) {
		if ( empty( $clean[ $required ] ) ) {
			return false;
		}
	}
	return wp_json_encode( $clean );
}

/**
 * Replace {{field_X}} / {{field_X.Y}} placeholders with entry values.
 *
 * @param string        $message   Message template.
 * @param array         $entry     Gravity Forms entry.
 * @param callable|null $escape_cb Optional callback applied to each replaced value.
 * @return string
 */
function cindova_gfotp_replace_placeholders( $message, $entry, $escape_cb = null ) {
	return preg_replace_callback(
		'/\{\{field_(\d+(?:\.\d+)?)\}\}/',
		function ( $matches ) use ( $entry, $escape_cb ) {
			$value = rgar( $entry, $matches[1] );
			if ( ! is_scalar( $value ) || '' === (string) $value ) {
				return '';
			}
			$value = (string) $value;
			return $escape_cb ? call_user_func( $escape_cb, $value ) : $value;
		},
		(string) $message
	);
}

/**
 * Replace "{" with its HTML entity in every string of an entry (recursively).
 *
 * Used on a copy of the entry before {all_fields} is expanded, because Gravity
 * Forms does not protect tags typed by visitors inside the {all_fields} output.
 *
 * @param mixed $value Entry value.
 * @return mixed
 */
function cindova_gfotp_encode_braces( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'cindova_gfotp_encode_braces', $value );
	}
	return is_string( $value ) ? str_replace( '{', '&#x7b;', $value ) : $value;
}

/**
 * Render a message template in a single protected pass.
 *
 * 1. {{field_X}} placeholders in the admin-written template become opaque
 *    tokens, so Gravity Forms cannot touch them and they are not scanned again.
 * 2. Gravity Forms merge tags are expanded (only when Gravity Forms is loaded and
 *    $entry is a real entry: non-empty array with an 'id'; otherwise they are left
 *    as written). GF protects values of single tags such as {Name:3}; for
 *    {all_fields} it does not, so that expansion runs on a copy of the entry
 *    where "{" is encoded as "&#x7b;".
 * 3. The tokens are replaced by the entry values, which are never re-scanned.
 * 4. Encoded braces are turned back into "{" (GF does this itself for most tags).
 *
 * @param string $template Message template.
 * @param array  $form     Gravity Forms form array.
 * @param array  $entry    Gravity Forms entry (empty for test messages).
 * @param string $context  'sms' or 'slack'. 'slack' escapes substituted values for Slack mrkdwn.
 * @return string
 */
function cindova_gfotp_render_message( $template, $form, $entry, $context = 'sms' ) {
	$is_slack = 'slack' === $context;
	$text     = (string) $template;
	$values   = array();

	// Step 1: protect {{field_X}} placeholders.
	$text = preg_replace_callback(
		'/\{\{field_(\d+(?:\.\d+)?)\}\}/',
		function ( $matches ) use ( &$values, $entry, $is_slack ) {
			$value = is_array( $entry ) ? rgar( $entry, $matches[1] ) : '';
			$value = is_scalar( $value ) ? (string) $value : '';
			$token = '%%CGFOTP' . count( $values ) . '%%';

			$values[ $token ] = $is_slack ? cindova_gfotp_slack_escape( $value ) : $value;
			return $token;
		},
		$text
	);

	// Step 2: Gravity Forms merge tags.
	if ( class_exists( 'GFCommon' ) && is_array( $entry ) && ! empty( $entry ) && isset( $entry['id'] ) ) {
		$merge_entry = $entry;
		if ( false !== stripos( $text, '{all_fields' ) ) {
			$merge_entry = cindova_gfotp_encode_braces( $entry );
		}
		if ( $is_slack ) {
			add_filter( 'gform_merge_tag_filter', 'cindova_gfotp_slack_merge_tag_filter', 10, 6 );
		}
		try {
			$text = GFCommon::replace_variables( $text, $form, $merge_entry, false, false, false, 'text' );
		} finally {
			if ( $is_slack ) {
				remove_filter( 'gform_merge_tag_filter', 'cindova_gfotp_slack_merge_tag_filter', 10 );
			}
		}
	}

	// Step 3: insert the placeholder values.
	$text = str_replace( array_keys( $values ), array_values( $values ), $text );

	// Step 4: undo brace encoding (also the form escaped by the Slack filter).
	return str_replace( array( '&amp;#x7b;', '&#x7b;' ), '{', $text );
}

/**
 * Escape merge tag values for Slack (temporarily hooked by cindova_gfotp_render_message()).
 *
 * Gravity Forms encodes "{" as "&#x7b;" in merge tag values; that entity is kept
 * as is (it is turned back into "{" after rendering).
 *
 * @param mixed  $value     Merge tag value.
 * @param string $merge_tag Merge tag.
 * @param string $modifier  Modifier.
 * @param mixed  $field     Field object.
 * @param mixed  $raw_value Raw value.
 * @param string $format    Format.
 * @return mixed
 */
function cindova_gfotp_slack_merge_tag_filter( $value, $merge_tag = '', $modifier = '', $field = null, $raw_value = '', $format = 'text' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Filter callback signature.
	if ( ! is_string( $value ) ) {
		return $value;
	}
	return str_replace( '&amp;#x7b;', '&#x7b;', cindova_gfotp_slack_escape( $value ) );
}

/**
 * Parse the MSG91 Flow variables setting (one name=value per line).
 *
 * Names are trimmed and case-sensitive; values are returned unrendered. Lines
 * without "=" or with an empty name are skipped, and "mobiles" is reserved.
 *
 * @param string $text Raw setting.
 * @return array Map of variable name => value template.
 */
function cindova_gfotp_parse_msg91_variables( $text ) {
	$vars = array();
	foreach ( explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) ) as $line ) {
		$pos = strpos( $line, '=' );
		if ( false === $pos ) {
			continue;
		}
		$name = trim( substr( $line, 0, $pos ) );
		if ( '' === $name || 'mobiles' === $name ) {
			continue;
		}
		$vars[ $name ] = trim( substr( $line, $pos + 1 ) );
	}
	return $vars;
}

/**
 * Which MSG91 API to use: 'flow' (Flow API v5) or 'legacy' (sendhttp).
 *
 * A stored valid value wins. Without one, installs that already saved an auth
 * key keep the legacy API; everything else defaults to Flow.
 *
 * @return string
 */
function cindova_gfotp_get_msg91_api_mode() {
	$mode = cindova_gfotp_notify_setting( 'msg91_api', '' );
	if ( in_array( $mode, array( 'flow', 'legacy' ), true ) ) {
		return $mode;
	}
	return '' !== (string) cindova_gfotp_notify_setting( 'msg91_authkey', '' ) ? 'legacy' : 'flow';
}

/**
 * Which SMS provider is selected: 'msg91' (default) or 'twilio'.
 *
 * @return string
 */
function cindova_gfotp_get_sms_provider() {
	return 'twilio' === cindova_gfotp_notify_setting( 'sms_provider', 'msg91' ) ? 'twilio' : 'msg91';
}

/**
 * MSG91 credentials from the SMS & Slack settings.
 *
 * @return array array( 'msg91authkey', 'msg91senderid', 'msg91route' ).
 */
function cindova_gfotp_get_msg91_credentials() {
	$route = (string) cindova_gfotp_notify_setting( 'msg91_route', '' );
	return array(
		'msg91authkey'  => (string) cindova_gfotp_notify_setting( 'msg91_authkey', '' ),
		'msg91senderid' => (string) cindova_gfotp_notify_setting( 'msg91_senderid', '' ),
		'msg91route'    => '' !== $route ? $route : '4',
	);
}

/**
 * Twilio credentials from the SMS & Slack settings.
 *
 * @return array array( 'phone', 'sid', 'token' ).
 */
function cindova_gfotp_get_twilio_credentials() {
	return array(
		'phone' => (string) cindova_gfotp_notify_setting( 'twilio_phone', '' ),
		'sid'   => (string) cindova_gfotp_notify_setting( 'twilio_sid', '' ),
		'token' => (string) cindova_gfotp_notify_setting( 'twilio_token', '' ),
	);
}

/**
 * Get the Firebase App Check settings.
 *
 * @return array array( 'site_key' => string, 'provider' => 'v3'|'enterprise' ).
 */
function cindova_gfotp_get_app_check_config() {
	$site_key = cindova_gfotp_otp_setting( 'app_check_site_key', '' );
	return array(
		'site_key' => is_string( $site_key ) ? $site_key : '',
		'provider' => 'enterprise' === cindova_gfotp_otp_setting( 'app_check_provider', 'v3' ) ? 'enterprise' : 'v3',
	);
}

/**
 * Sanitize an SMS/Slack message template.
 *
 * Unlike sanitize_textarea_field() this keeps angle brackets, which Slack uses
 * for mentions and links (<!here>, <https://example.com|text>). The value is
 * only sent to SMS/Slack APIs and is escaped with esc_textarea() on output.
 *
 * @param string|array $value Raw, unslashed value.
 * @return string
 */
function cindova_gfotp_sanitize_message( $value ) {
	if ( is_array( $value ) ) {
		$value = wp_json_encode( $value ); // Gravity Forms decodes posted text that is valid JSON.
	}
	$value = wp_check_invalid_utf8( (string) $value, true );
	$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
	$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
	return trim( $value );
}
