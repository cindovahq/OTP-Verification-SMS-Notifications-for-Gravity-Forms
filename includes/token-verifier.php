<?php
/**
 * Firebase ID token verification (RS256, no external dependencies).
 *
 * Standalone: uses WordPress core functions only. Implements
 * https://firebase.google.com/docs/auth/server/verify-id-tokens
 *
 * @package CindovaGfOtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Decode a base64url string.
 *
 * @param string $data Base64url encoded data.
 * @return string|false Decoded bytes or false on failure.
 */
function cindova_gfotp_base64url_decode( $data ) {
	$data      = strtr( (string) $data, '-_', '+/' );
	$remainder = strlen( $data ) % 4;
	if ( 1 === $remainder ) {
		return false;
	}
	if ( $remainder ) {
		$data .= str_repeat( '=', 4 - $remainder );
	}
	return base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a JWT segment, not obfuscation.
}

/**
 * Get Google's public certificates used to sign Firebase ID tokens.
 *
 * @param bool $bypass_cache Skip the transient cache and refetch.
 * @return array|WP_Error Map of kid => PEM certificate.
 */
function cindova_gfotp_get_firebase_certs( $bypass_cache = false ) {
	$certs = $bypass_cache ? false : get_transient( 'cindova_gfotp_firebase_certs' );

	if ( ! is_array( $certs ) ) {
		$certs    = array();
		$fetch_ok = false;
		$response = wp_remote_get(
			'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com',
			array( 'timeout' => 10 )
		);

		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $decoded ) && ! empty( $decoded ) ) {
				$certs    = $decoded;
				$fetch_ok = true;
				$ttl      = HOUR_IN_SECONDS;
				$cc       = wp_remote_retrieve_header( $response, 'cache-control' );
				if ( is_string( $cc ) && preg_match( '/max-age=(\d+)/', $cc, $m ) && (int) $m[1] > 0 ) {
					$ttl = (int) $m[1];
				}
				set_transient( 'cindova_gfotp_firebase_certs', $certs, $ttl );
			}
		}
		if ( ! $fetch_ok ) {
			$error = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response );
			$certs = array( '__fetch_error' => $error );
		}
	}

	$fetch_error = null;
	if ( isset( $certs['__fetch_error'] ) ) {
		$fetch_error = $certs['__fetch_error'];
		$certs       = array();
	}

	/**
	 * Filter the Firebase public certificates (kid => PEM). Used by tests.
	 *
	 * @param array $certs Certificates keyed by key ID.
	 */
	$certs = apply_filters( 'cindova_gfotp_firebase_public_keys', $certs );

	if ( ! is_array( $certs ) || empty( $certs ) ) {
		return new WP_Error(
			'cindova_gfotp_verification_unavailable',
			'Could not fetch Firebase public keys' . ( $fetch_error ? ': ' . $fetch_error : '.' )
		);
	}
	return $certs;
}

/**
 * Verify a Firebase ID token.
 *
 * @param string $token      The raw JWT.
 * @param string $project_id Firebase project ID.
 * @return array|WP_Error Claims on success; WP_Error with code
 *                        'cindova_gfotp_invalid_token' or 'cindova_gfotp_verification_unavailable'.
 */
function cindova_gfotp_verify_id_token( $token, $project_id ) {
	$invalid = function ( $why ) {
		return new WP_Error( 'cindova_gfotp_invalid_token', $why );
	};

	if ( ! is_string( $token ) || '' === $token || ! is_string( $project_id ) || '' === $project_id ) {
		return $invalid( 'Missing token or project ID.' );
	}

	$parts = explode( '.', $token );
	if ( 3 !== count( $parts ) ) {
		return $invalid( 'Malformed token.' );
	}

	$header_json  = cindova_gfotp_base64url_decode( $parts[0] );
	$payload_json = cindova_gfotp_base64url_decode( $parts[1] );
	$signature    = cindova_gfotp_base64url_decode( $parts[2] );
	if ( false === $header_json || false === $payload_json || false === $signature || '' === $signature ) {
		return $invalid( 'Token is not valid base64url.' );
	}

	$header = json_decode( $header_json, true );
	$claims = json_decode( $payload_json, true );
	if ( ! is_array( $header ) || ! is_array( $claims ) ) {
		return $invalid( 'Token segments are not valid JSON.' );
	}

	if ( ! isset( $header['alg'] ) || 'RS256' !== $header['alg'] ) {
		return $invalid( 'Unexpected signing algorithm.' );
	}
	if ( empty( $header['kid'] ) || ! is_string( $header['kid'] ) ) {
		return $invalid( 'Missing key ID.' );
	}

	if ( ! function_exists( 'openssl_verify' ) || ! function_exists( 'openssl_pkey_get_public' ) ) {
		return new WP_Error( 'cindova_gfotp_verification_unavailable', 'The PHP openssl extension is not available.' );
	}

	$certs = cindova_gfotp_get_firebase_certs( false );
	if ( is_wp_error( $certs ) ) {
		return $certs;
	}
	if ( ! isset( $certs[ $header['kid'] ] ) ) {
		// Keys may have rotated: refetch once, bypassing the cache.
		$certs = cindova_gfotp_get_firebase_certs( true );
		if ( is_wp_error( $certs ) ) {
			return $certs;
		}
		if ( ! isset( $certs[ $header['kid'] ] ) ) {
			return $invalid( 'Unknown signing key.' );
		}
	}

	$public_key = openssl_pkey_get_public( $certs[ $header['kid'] ] );
	if ( false === $public_key ) {
		return new WP_Error( 'cindova_gfotp_verification_unavailable', 'Could not load the Firebase public key.' );
	}

	$verified = openssl_verify( $parts[0] . '.' . $parts[1], $signature, $public_key, OPENSSL_ALGO_SHA256 );
	if ( 1 !== $verified ) {
		return $invalid( 'Signature mismatch.' );
	}

	$now    = time();
	$leeway = 60;

	if ( ! isset( $claims['exp'] ) || ! is_numeric( $claims['exp'] ) || (int) $claims['exp'] <= $now - $leeway ) {
		return $invalid( 'Token expired.' );
	}
	if ( ! isset( $claims['iat'] ) || ! is_numeric( $claims['iat'] ) || (int) $claims['iat'] > $now + $leeway ) {
		return $invalid( 'Token issued in the future.' );
	}
	if ( isset( $claims['auth_time'] ) && ( ! is_numeric( $claims['auth_time'] ) || (int) $claims['auth_time'] > $now + $leeway ) ) {
		return $invalid( 'Token auth_time is in the future.' );
	}
	if ( ! isset( $claims['aud'] ) || $claims['aud'] !== $project_id ) {
		return $invalid( 'Audience mismatch.' );
	}
	if ( ! isset( $claims['iss'] ) || 'https://securetoken.google.com/' . $project_id !== $claims['iss'] ) {
		return $invalid( 'Issuer mismatch.' );
	}
	if ( empty( $claims['sub'] ) || ! is_string( $claims['sub'] ) ) {
		return $invalid( 'Missing subject.' );
	}
	if ( empty( $claims['phone_number'] ) || ! is_string( $claims['phone_number'] ) ) {
		return $invalid( 'Token has no phone number.' );
	}

	return $claims;
}
