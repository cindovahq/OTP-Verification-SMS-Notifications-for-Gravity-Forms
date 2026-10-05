/*
 * Cindova Phone OTP & SMS for Gravity Forms
 * "Send test" button on the SMS & Slack feed edit page. Version 1.0.0
 *
 * Sends the values currently entered in the feed form (saved or not) to the
 * cindova_gfotp_send_test AJAX action and prints the provider response as text.
 */
( function ( $ ) {
	'use strict';

	var settings = window.cindova_gfotp_admin_feed_strings;
	if ( ! settings ) {
		return;
	}

	$( function () {
		var $button = $( '#cindova_gfotp_send_test' );
		var $result = $( '#cindova_gfotp_test_result' );
		var $number = $( '#cindova_gfotp_test_number' );
		if ( ! $button.length ) {
			return;
		}

		function field( name ) {
			return $( '[name="_gform_setting_' + name + '"]' );
		}

		function currentType() {
			var type = $( 'input[name="_gform_setting_notification_type"]:checked' ).val();
			return 'slack' === type ? 'slack' : 'sms';
		}

		// The test number only applies to SMS.
		function toggleNumber() {
			$( '.cindova-gfotp-test-number' ).toggle( 'sms' === currentType() );
		}
		$( 'input[name="_gform_setting_notification_type"]' ).on( 'change', toggleNumber );
		toggleNumber();

		$button.on( 'click', function () {
			var type = currentType();
			var phone = $.trim( $number.val() );

			if ( 'sms' === type && '' === phone ) {
				$result.text( settings.noNumber );
				return;
			}

			$button.prop( 'disabled', true );
			$result.text( settings.sending );

			$.post( settings.ajaxUrl, {
				action: 'cindova_gfotp_send_test',
				nonce: settings.nonce,
				form_id: settings.formId,
				type: type,
				phone: phone,
				message: 'slack' === type ? field( 'slack_message' ).val() : field( 'message' ).val(),
				template_id: field( 'msg91_template_id' ).val(),
				variables: field( 'msg91_variables' ).val(),
				channel: field( 'slack_channel' ).val()
			} )
				.done( function ( response ) {
					var text = response && response.data && response.data.message ? response.data.message : settings.noResponse;
					$result.text( text );
				} )
				.fail( function ( xhr ) {
					var data = xhr && xhr.responseJSON && xhr.responseJSON.data;
					$result.text( data && data.message ? data.message : settings.requestFailed );
				} )
				.always( function () {
					$button.prop( 'disabled', false );
				} );
		} );
	} );
}( jQuery ) );
