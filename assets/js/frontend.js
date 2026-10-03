/**
 * OTP Verification & SMS Notifications for Gravity Forms
 * Front-end script. Version 1.0.0
 *
 * The server verifies the Firebase ID token stored in the hidden
 * input.cindova-gfotp-token field; this script never blocks submission.
 */
(function (window, document, $) {
	'use strict';

	var cfg = window.cindovaGfOtp || {};
	var i18n = cfg.i18n || {};
	var states = {};
	var COOLDOWN = 30;

	var defaults = {
		sendOtp: 'Send code',
		resendOtp: 'Resend code',
		resendIn: 'Resend in %d s',
		verifyOtp: 'Verify code',
		sending: 'Sending code...',
		sent: 'Code sent to %s',
		verifying: 'Verifying...',
		verified: 'Phone number verified.',
		changeNumber: 'Change number',
		invalidPhone: 'Enter a valid phone number in international format, e.g. +14155552671.',
		enterCode: 'Enter the verification code.',
		invalidCode: 'The verification code is invalid.',
		codeExpired: 'The verification code has expired. Please request a new one.',
		tooManyRequests: 'Too many attempts. Please try again later.',
		quotaExceeded: 'SMS quota exceeded. Please try again later.',
		captchaFailed: 'reCAPTCHA verification failed. Please try again.',
		genericError: 'Something went wrong. Please try again.'
	};

	function t(key) {
		var v = i18n[key];
		return (typeof v === 'string' && v !== '') ? v : (defaults[key] || '');
	}

	function errorMessage(err) {
		var map = {
			'auth/invalid-phone-number': 'invalidPhone',
			'auth/invalid-verification-code': 'invalidCode',
			'auth/code-expired': 'codeExpired',
			'auth/too-many-requests': 'tooManyRequests',
			'auth/quota-exceeded': 'quotaExceeded',
			'auth/captcha-check-failed': 'captchaFailed'
		};
		var code = err && err.code;
		return t(Object.prototype.hasOwnProperty.call(map, code) ? map[code] : 'genericError');
	}

	function digits(s) {
		return String(s || '').replace(/\D/g, '');
	}

	function normalizePhone(raw) {
		var n = String(raw || '').replace(/[\s\-.()]/g, '');
		return /^\+[1-9]\d{6,14}$/.test(n) ? n : '';
	}

	function setMessage($el, text, type) {
		$el.removeClass('is-success is-error is-loading');
		if (type) {
			$el.addClass('is-' + type);
		}
		$el.text(text || '');
	}

	function decodeTokenPayload(token) {
		try {
			var part = String(token).split('.')[1];
			if (!part) {
				return null;
			}
			part = part.replace(/-/g, '+').replace(/_/g, '/');
			while (part.length % 4) {
				part += '=';
			}
			var bin = window.atob(part);
			var json = decodeURIComponent(bin.split('').map(function (c) {
				return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
			}).join(''));
			return JSON.parse(json);
		} catch (e) {
			return null;
		}
	}

	function getApp() {
		return firebase.apps.length ? firebase.app() : firebase.initializeApp(cfg.firebaseConfig);
	}

	var appCheckActivated = false;

	// Activates Firebase App Check once per page. Returns false (after logging) on failure.
	function activateAppCheck() {
		var ac = cfg.appCheck;
		if (appCheckActivated || !ac || !ac.siteKey) {
			return true;
		}
		try {
			if (ac.provider === 'enterprise') {
				if (!firebase.appCheck || !firebase.appCheck.ReCaptchaEnterpriseProvider) {
					console.error('Cindova GF OTP: ReCaptchaEnterpriseProvider is not available in this Firebase build.');
					return false;
				}
				firebase.appCheck().activate(new firebase.appCheck.ReCaptchaEnterpriseProvider(ac.siteKey), true);
			} else {
				firebase.appCheck().activate(ac.siteKey, true);
			}
			appCheckActivated = true;
			return true;
		} catch (e) {
			console.error(e);
			return false;
		}
	}

	function createVerifier(state) {
		state.verifier = new firebase.auth.RecaptchaVerifier(state.recaptchaId, { size: 'invisible' });
	}

	function resetVerifier(state) {
		try { if (state.verifier) { state.verifier.clear(); } } catch (e) {}
		state.verifier = null;
		var $old = $('#' + state.recaptchaId);
		var $fresh = $('<div class="cindova-gfotp-recaptcha"></div>').attr('id', state.recaptchaId);
		if ($old.length) {
			$old.replaceWith($fresh);
		} else {
			state.$form.append($fresh);
		}
		try { createVerifier(state); } catch (e) { console.error(e); }
	}

	function stopTimer(state) {
		if (state.timer) {
			window.clearInterval(state.timer);
			state.timer = null;
		}
	}

	function startCooldown(state) {
		var left = COOLDOWN;
		stopTimer(state);
		state.$send.prop('disabled', true).text(t('resendIn').replace('%d', left));
		state.timer = window.setInterval(function () {
			left -= 1;
			if (left <= 0) {
				stopTimer(state);
				state.$send.prop('disabled', false).text(t('resendOtp'));
			} else {
				state.$send.text(t('resendIn').replace('%d', left));
			}
		}, 1000);
	}

	function enterVerified(state) {
		state.verified = true;
		stopTimer(state);
		state.$phone.prop('readonly', true);
		state.$otp.prop('readonly', true);
		state.$send.prop('disabled', true).hide();
		state.$verify.prop('disabled', true).hide();
		state.$verifyMsg.text('').removeClass('is-success is-error is-loading');
		setMessage(state.$phoneMsg, t('verified'), 'success');
		state.$change.show();
	}

	function exitVerified(state) {
		state.verified = false;
		state.confirmation = null;
		state.$token.val('');
		state.$phone.prop('readonly', false);
		state.$otp.val('').prop('readonly', true);
		state.$send.prop('disabled', false).text(t('sendOtp')).show();
		state.$verify.prop('disabled', true).show();
		state.$change.hide();
		setMessage(state.$phoneMsg, '', null);
		setMessage(state.$verifyMsg, '', null);
		state.$phone.trigger('focus');
	}

	function sendCode(state) {
		var phone = normalizePhone(state.$phone.val());
		if (!phone) {
			setMessage(state.$phoneMsg, t('invalidPhone'), 'error');
			return;
		}
		// Firebase (and reCAPTCHA) are only started when the visitor asks for a
		// code, so nothing contacts Google's servers on page load.
		try {
			getApp();
		} catch (e) {
			console.error(e);
			setMessage(state.$phoneMsg, t('genericError'), 'error');
			return;
		}
		if (!activateAppCheck()) {
			setMessage(state.$phoneMsg, t('genericError'), 'error');
			return;
		}
		if (!state.verifier) {
			resetVerifier(state);
		}
		stopTimer(state);
		state.$send.prop('disabled', true).text(t('sending'));
		setMessage(state.$phoneMsg, t('sending'), 'loading');

		getApp().auth().signInWithPhoneNumber(phone, state.verifier).then(function (confirmation) {
			state.confirmation = confirmation;
			setMessage(state.$phoneMsg, t('sent').replace('%s', phone), 'success');
			state.$otp.prop('readonly', false);
			state.$verify.prop('disabled', false);
			state.$otp.trigger('focus');
			startCooldown(state);
		}).catch(function (err) {
			console.error(err);
			setMessage(state.$phoneMsg, errorMessage(err), 'error');
			state.$send.prop('disabled', false).text(t('sendOtp'));
			resetVerifier(state);
		});
	}

	function verifyCode(state) {
		var code = $.trim(state.$otp.val());
		if (!code) {
			setMessage(state.$verifyMsg, t('enterCode'), 'error');
			return;
		}
		if (!state.confirmation) {
			setMessage(state.$verifyMsg, t('genericError'), 'error');
			return;
		}
		state.$verify.prop('disabled', true);
		setMessage(state.$verifyMsg, t('verifying'), 'loading');

		state.confirmation.confirm(code).then(function (result) {
			return result.user.getIdToken();
		}).then(function (idToken) {
			state.$token.val(idToken);
			enterVerified(state);
			try {
				var p = firebase.auth().signOut();
				if (p && p.catch) { p.catch(function () {}); }
			} catch (e) {}
		}).catch(function (err) {
			console.error(err);
			setMessage(state.$verifyMsg, errorMessage(err), 'error');
			state.$verify.prop('disabled', false);
		});
	}

	function initForm(formId) {
		var conf = (window.cindovaGfOtpForms || {})[formId];
		if (!conf) {
			return;
		}
		var $form = $('#gform_' + formId);
		var $phone = $('#input_' + formId + '_' + conf.phoneFieldId);
		var $otp = $('#input_' + formId + '_' + conf.otpFieldId);
		var $token = $form.find('input.cindova-gfotp-token');
		if (!$form.length || !$phone.length || !$otp.length || !$token.length) {
			return; // Fields not on the current page of a multi-page form.
		}
		if ($phone.data('cindovaGfotpInit')) {
			return;
		}
		if (typeof window.firebase === 'undefined' || !cfg.firebaseConfig) {
			console.warn('Cindova GF OTP: Firebase SDK or configuration is missing.');
			return;
		}
		var old = states[formId];
		if (old) {
			stopTimer(old);
			try { if (old.verifier) { old.verifier.clear(); } } catch (e) {}
		}

		var recaptchaId = 'cindova-gfotp-recaptcha-' + formId;
		$('#' + recaptchaId).remove();
		$form.append($('<div class="cindova-gfotp-recaptcha"></div>').attr('id', recaptchaId));

		var $send = $('<button type="button" class="cindova-gfotp-button cindova-gfotp-send"></button>')
			.attr('id', 'cindova-gfotp-send-' + formId).text(t('sendOtp'));
		var $phoneMsg = $('<div class="cindova-gfotp-message" role="status" aria-live="polite"></div>');
		var $change = $('<button type="button" class="cindova-gfotp-change"></button>').text(t('changeNumber')).hide();
		var $verify = $('<button type="button" class="cindova-gfotp-button cindova-gfotp-verify"></button>')
			.text(t('verifyOtp')).prop('disabled', true);
		var $verifyMsg = $('<div class="cindova-gfotp-message" role="status" aria-live="polite"></div>');

		$phone.after($send, $change, $phoneMsg);
		$otp.after($verify, $verifyMsg);
		$otp.prop('readonly', true);
		$phone.data('cindovaGfotpInit', true);

		var state = {
			formId: formId, $form: $form, $phone: $phone, $otp: $otp, $token: $token,
			$send: $send, $verify: $verify, $change: $change,
			$phoneMsg: $phoneMsg, $verifyMsg: $verifyMsg,
			recaptchaId: recaptchaId, verifier: null, confirmation: null, timer: null, verified: false
		};
		states[formId] = state;

		$send.on('click', function () { sendCode(state); });
		$verify.on('click', function () { verifyCode(state); });
		$change.on('click', function () { exitVerified(state); });

		var existing = $token.val();
		if (existing) {
			var payload = decodeTokenPayload(existing);
			if (payload && payload.phone_number && payload.exp &&
				digits(payload.phone_number) === digits($phone.val()) &&
				payload.exp * 1000 > Date.now()) {
				enterVerified(state);
			} else {
				$token.val('');
			}
		}
	}

	function initAll() {
		Object.keys(window.cindovaGfOtpForms || {}).forEach(function (id) { initForm(id); });
	}

	$(document).on('gform_post_render', function (e, formId) {
		initForm(String(formId));
	});
	$(initAll);
})(window, document, jQuery);
