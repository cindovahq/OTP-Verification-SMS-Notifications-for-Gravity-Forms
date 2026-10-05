<?php
/**
 * Front-end OTP: assets, hidden token field and server-side verification.
 * The hooks are registered by Cindova_GFOTP_Addon::init().
 *
 * @package CindovaGfOtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Read the posted Firebase ID token, stripped to JWT characters.
 *
 * @return string
 */
function cindova_gfotp_get_posted_token() {
	$token = rgpost( 'cindova_gfotp_token' );
	if ( ! is_string( $token ) ) {
		return '';
	}
	return preg_replace( '/[^A-Za-z0-9\-_.]/', '', $token );
}

/**
 * Holder for verification results between validation and entry creation.
 *
 * @param int        $form_id Form ID.
 * @param array|null $set     Data to store, null to read, false to clear.
 * @return array|null
 */
function cindova_gfotp_verified_holder( $form_id, $set = null ) {
	static $holder = array();
	$form_id       = (int) $form_id;
	if ( false === $set ) {
		unset( $holder[ $form_id ] );
		return null;
	}
	if ( null !== $set ) {
		$holder[ $form_id ] = $set;
	}
	return isset( $holder[ $form_id ] ) ? $holder[ $form_id ] : null;
}

/**
 * Append the hidden token input after the opening form tag.
 *
 * @param string $form_tag Form tag HTML.
 * @param array  $form     Form array.
 * @return string
 */
function cindova_gfotp_form_tag( $form_tag, $form ) {
	if ( ! cindova_gfotp_is_otp_enabled( $form ) ) {
		return $form_tag;
	}
	$value = '';
	if ( absint( rgpost( 'gform_submit' ) ) === (int) $form['id'] ) {
		$value = cindova_gfotp_get_posted_token();
	}
	return $form_tag . '<input type="hidden" name="cindova_gfotp_token" class="cindova-gfotp-token" value="' . esc_attr( $value ) . '">';
}

/**
 * Verify the posted Firebase ID token for a form submission.
 *
 * On success the result is stored in the holder so the token can be marked as
 * used once the entry is created.
 *
 * @param array $form Form array.
 * @return true|string True when verified, otherwise a translated error message.
 */
function cindova_gfotp_check_token( $form ) {
	$failed = __( 'Phone verification failed or expired. Please request a new code.', 'cindova-phone-otp-sms-for-gravity-forms' );

	cindova_gfotp_verified_holder( $form['id'], false );

	$token = cindova_gfotp_get_posted_token();
	if ( '' === $token ) {
		return __( 'Please verify your phone number with the code sent by SMS before submitting.', 'cindova-phone-otp-sms-for-gravity-forms' );
	}

	$config = cindova_gfotp_get_firebase_config();
	$claims = cindova_gfotp_verify_id_token( $token, isset( $config['projectId'] ) ? (string) $config['projectId'] : '' );

	if ( is_wp_error( $claims ) ) {
		if ( 'cindova_gfotp_verification_unavailable' === $claims->get_error_code() ) {
			cindova_gfotp_log( 'OTP verification unavailable: ' . $claims->get_error_message() );
			return __( 'We could not verify your phone number right now. Please try again in a moment.', 'cindova-phone-otp-sms-for-gravity-forms' );
		}
		cindova_gfotp_log( 'OTP token rejected: ' . $claims->get_error_message() );
		return $failed;
	}

	$phone_field_id = (string) cindova_gfotp_get_form_setting( $form, 'phone_field_id', '' );
	$posted_phone   = rgpost( 'input_' . str_replace( '.', '_', $phone_field_id ) );
	$posted_digits  = is_string( $posted_phone ) ? preg_replace( '/\D/', '', $posted_phone ) : '';
	$token_digits   = preg_replace( '/\D/', '', $claims['phone_number'] );

	if ( '' === $posted_digits || $posted_digits !== $token_digits ) {
		return $failed;
	}

	$hash = md5( $token );
	if ( false !== get_transient( 'cindova_gfotp_used_' . $hash ) ) {
		return $failed;
	}

	cindova_gfotp_verified_holder(
		$form['id'],
		array(
			'hash'  => $hash,
			'phone' => $claims['phone_number'],
		)
	);
	return true;
}

/**
 * Server-side validation of the OTP field.
 *
 * @param array    $result Validation result.
 * @param mixed    $value  Field value.
 * @param array    $form   Form array.
 * @param GF_Field $field  Field object.
 * @return array
 */
function cindova_gfotp_validate_otp_field( $result, $value, $form, $field ) {
	if ( ! cindova_gfotp_is_otp_enabled( $form ) ) {
		return $result;
	}
	$otp_field_id = (string) cindova_gfotp_get_form_setting( $form, 'otp_field_id', '' );
	if ( (string) $field->id !== $otp_field_id ) {
		return $result;
	}

	$check = cindova_gfotp_check_token( $form );
	if ( true !== $check ) {
		$result['is_valid'] = false;
		$result['message']  = $check;
	}
	return $result;
}

/**
 * Safety net for the final submission of multi-page forms.
 *
 * Gravity Forms skips validation of fields hidden by conditional logic and validates
 * page by page, so the OTP field validation may not run on the final submit. Re-check
 * the token here whenever the phone field is visible, unless it was already verified
 * (or rejected) in this request.
 *
 * @param array $validation_result Validation result with 'is_valid', 'form' and 'failed_validation_page'.
 * @return array
 */
function cindova_gfotp_validate_final_submission( $validation_result ) {
	$form = $validation_result['form'];
	if ( ! cindova_gfotp_is_otp_enabled( $form ) ) {
		return $validation_result;
	}

	// Only on the final submission (target page 0), not when moving between pages.
	if ( 0 !== absint( rgpost( 'gform_target_page_number_' . absint( $form['id'] ) ) ) ) {
		return $validation_result;
	}

	// Already verified by the field validation in this request.
	if ( null !== cindova_gfotp_verified_holder( $form['id'] ) ) {
		return $validation_result;
	}

	// Verification is required whenever the phone field is visible, whatever the
	// visibility of the OTP field: a visitor controls the posted values that drive
	// conditional logic and could otherwise hide the OTP field to skip the check.
	$otp_field_id   = (string) cindova_gfotp_get_form_setting( $form, 'otp_field_id', '' );
	$phone_field_id = (string) (int) cindova_gfotp_get_form_setting( $form, 'phone_field_id', '' );
	$otp_field      = null;
	$phone_field    = null;
	foreach ( $form['fields'] as $field ) {
		if ( (string) $field->id === $otp_field_id ) {
			$otp_field = $field;
		} elseif ( (string) $field->id === $phone_field_id ) {
			$phone_field = $field;
		}
	}
	if ( ! $otp_field || ! $phone_field ) {
		return $validation_result;
	}

	// Phone field hidden by conditional logic: no phone number is submitted, nothing to verify.
	if ( class_exists( 'GFFormsModel' ) && GFFormsModel::is_field_hidden( $form, $phone_field, array() ) ) {
		return $validation_result;
	}

	$otp_hidden = class_exists( 'GFFormsModel' ) && GFFormsModel::is_field_hidden( $form, $otp_field, array() );
	if ( ! $otp_hidden && ! empty( $otp_field->failed_validation ) ) {
		return $validation_result; // Already reported by the field validation.
	}

	$check = cindova_gfotp_check_token( $form );
	if ( true !== $check ) {
		// Show the message on the OTP field, or on the phone field when the OTP field is hidden.
		$target                                      = $otp_hidden ? $phone_field : $otp_field;
		$target->failed_validation                   = true;
		$target->validation_message                  = $check;
		$validation_result['is_valid']               = false;
		$validation_result['failed_validation_page'] = ! empty( $target->pageNumber ) ? (int) $target->pageNumber : 1; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Gravity Forms property.
	}

	$validation_result['form'] = $form;
	return $validation_result;
}

/**
 * Mark the token as used once the entry exists, and leave an entry note.
 *
 * @param array $entry Entry.
 * @param array $form  Form.
 * @return void
 */
function cindova_gfotp_mark_token_used( $entry, $form ) {
	$data = cindova_gfotp_verified_holder( $form['id'] );
	if ( ! $data ) {
		return;
	}
	cindova_gfotp_verified_holder( $form['id'], false );

	set_transient( 'cindova_gfotp_used_' . $data['hash'], 1, HOUR_IN_SECONDS + 5 * MINUTE_IN_SECONDS );

	if ( class_exists( 'GFAPI' ) && method_exists( 'GFAPI', 'add_note' ) ) {
		GFAPI::add_note(
			$entry['id'],
			0,
			'OTP Verification',
			sprintf(
				/* translators: %s: verified phone number. */
				__( 'Phone number %s verified via Firebase OTP.', 'cindova-phone-otp-sms-for-gravity-forms' ),
				$data['phone']
			),
			'note',
			'success'
		);
	}
}

/**
 * Enqueue front-end assets for forms with OTP enabled.
 *
 * @param array $form    Form array.
 * @param bool  $is_ajax Whether the form is AJAX-enabled.
 * @return void
 */
function cindova_gfotp_enqueue_frontend( $form, $is_ajax ) {
	if ( ! cindova_gfotp_is_otp_enabled( $form ) ) {
		return;
	}

	wp_enqueue_style( 'cindova-gfotp-frontend', CINDOVA_GFOTP_URL . 'assets/css/frontend.css', array(), CINDOVA_GFOTP_VERSION );
	wp_enqueue_script( 'cindova-gfotp-firebase-app', CINDOVA_GFOTP_URL . 'assets/vendor/firebase/firebase-app-compat.js', array(), '12.19.0', true );
	wp_enqueue_script( 'cindova-gfotp-firebase-auth', CINDOVA_GFOTP_URL . 'assets/vendor/firebase/firebase-auth-compat.js', array( 'cindova-gfotp-firebase-app' ), '12.19.0', true );

	$app_check    = cindova_gfotp_get_app_check_config();
	$frontend_dep = array( 'jquery', 'cindova-gfotp-firebase-auth' );
	if ( '' !== $app_check['site_key'] ) {
		wp_register_script( 'cindova-gfotp-firebase-app-check', CINDOVA_GFOTP_URL . 'assets/vendor/firebase/firebase-app-check-compat.js', array( 'cindova-gfotp-firebase-app' ), '12.19.0', true );
		$frontend_dep[] = 'cindova-gfotp-firebase-app-check';
	}
	wp_enqueue_script( 'cindova-gfotp-frontend', CINDOVA_GFOTP_URL . 'assets/js/frontend.js', $frontend_dep, CINDOVA_GFOTP_VERSION, true );

	static $global_added = false;
	if ( ! $global_added ) {
		$global_added = true;
		$data         = array(
			'firebaseConfig' => cindova_gfotp_get_firebase_config(),
			'i18n'           => cindova_gfotp_get_js_strings(),
		);
		if ( '' !== $app_check['site_key'] ) {
			$data['appCheck'] = array(
				'siteKey'  => $app_check['site_key'],
				'provider' => $app_check['provider'],
			);
		}
		wp_add_inline_script( 'cindova-gfotp-frontend', 'window.cindovaGfOtp = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	static $forms_added = array();
	$form_id            = (int) $form['id'];
	if ( ! isset( $forms_added[ $form_id ] ) ) {
		$forms_added[ $form_id ] = true;
		$form_data               = array(
			'phoneFieldId' => (string) cindova_gfotp_get_form_setting( $form, 'phone_field_id', '' ),
			'otpFieldId'   => (string) cindova_gfotp_get_form_setting( $form, 'otp_field_id', '' ),
		);
		wp_add_inline_script(
			'cindova-gfotp-frontend',
			'window.cindovaGfOtpForms = window.cindovaGfOtpForms || {}; window.cindovaGfOtpForms[' . $form_id . '] = ' . wp_json_encode( $form_data ) . ';',
			'before'
		);
	}
}

/**
 * Translated strings exposed to the front-end script.
 *
 * @return array
 */
function cindova_gfotp_get_js_strings() {
	return array(
		'sendOtp'         => __( 'Send code', 'cindova-phone-otp-sms-for-gravity-forms' ),
		'resendOtp'       => __( 'Resend code', 'cindova-phone-otp-sms-for-gravity-forms' ),
		/* translators: %d: seconds until the code can be resent. */
		'resendIn'        => __( 'Resend in %d s', 'cindova-phone-otp-sms-for-gravity-forms' ),
		'verifyOtp'       => __( 'Verify code', 'cindova-phone-otp-sms-for-gravity-forms' ),
		'sending'         => __( 'Sending code…', 'cindova-phone-otp-sms-for-gravity-forms' ),
		/* translators: %s: phone number the code was sent to. */
		'sent'            => __( 'Code sent to %s', 'cindova-phone-otp-sms-for-gravity-forms' ),
		'verifying'       => __( 'Verifying…', 'cindova-phone-otp-sms-for-gravity-forms' ),
		'verified'        => __( 'Phone number verified.', 'cindova-phone-otp-sms-for-gravity-forms' ),
		'changeNumber'    => __( 'Change number', 'cindova-phone-otp-sms-for-gravity-forms' ),
		'invalidPhone'    => __( 'Enter your phone number with country code, e.g. +14155552671.', 'cindova-phone-otp-sms-for-gravity-forms' ),
		'enterCode'       => __( 'Enter the code you received.', 'cindova-phone-otp-sms-for-gravity-forms' ),
		'invalidCode'     => __( 'That code is incorrect. Please try again.', 'cindova-phone-otp-sms-for-gravity-forms' ),
		'codeExpired'     => __( 'The code has expired. Please request a new one.', 'cindova-phone-otp-sms-for-gravity-forms' ),
		'tooManyRequests' => __( 'Too many attempts. Please wait and try again later.', 'cindova-phone-otp-sms-for-gravity-forms' ),
		'quotaExceeded'   => __( 'SMS limit reached. Please try again later.', 'cindova-phone-otp-sms-for-gravity-forms' ),
		'captchaFailed'   => __( 'Security check failed. Please reload the page and try again.', 'cindova-phone-otp-sms-for-gravity-forms' ),
		'genericError'    => __( 'Something went wrong. Please try again.', 'cindova-phone-otp-sms-for-gravity-forms' ),
	);
}
