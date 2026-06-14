(function ($) {
	'use strict';

	function initOtpFields() {
		$('.wto-elementor-otp').each(function () {
			var $wrap = $(this);
			if ($wrap.data('wto-otp-inited')) return;
			$wrap.data('wto-otp-inited', true);

			var formId = $wrap.data('form-id') || '';
			if (!formId && $wrap.closest('form').length) {
				formId = $wrap.closest('form').attr('data-elementor-form-id') || $wrap.closest('[data-elementor-id]').attr('data-elementor-id') || '';
			}
			if (!formId) {
				formId = '';
			}

			var $mobile = $wrap.find('.elementor-field-otp-mobile, .wto-otp-mobile').first();
			var $sendBtn = $wrap.find('.wto-otp-send-btn').first();
			var $msg = $wrap.find('.wto-otp-msg').first();
			var $codeRow = $wrap.find('.wto-otp-code-row').first();
			var $code = $wrap.find('.wto-otp-code').first();
			var $verifyBtn = $wrap.find('.wto-otp-verify-btn').first();

			function showMsg(text, isError) {
				$msg.removeClass('wto-otp-msg-ok wto-otp-msg-err').addClass(isError ? 'wto-otp-msg-err' : 'wto-otp-msg-ok').text(text).show();
			}

			$sendBtn.on('click', function () {
				var mobile = $mobile.val().trim();
				if (!mobile) {
					showMsg(typeof wtoElementorOtp !== 'undefined' && wtoElementorOtp.strings ? wtoElementorOtp.strings.enter_mobile : 'شماره موبایل را وارد کنید.', true);
					return;
				}
				$sendBtn.prop('disabled', true);
				showMsg(typeof wtoElementorOtp !== 'undefined' && wtoElementorOtp.strings ? wtoElementorOtp.strings.sending : 'در حال ارسال کد...', false);
				$.post(typeof wtoElementorOtp !== 'undefined' ? wtoElementorOtp.ajaxurl : '', {
					action: 'wto_otp_send',
					nonce: typeof wtoElementorOtp !== 'undefined' ? wtoElementorOtp.nonce_send : '',
					mobile: mobile
				}).done(function (r) {
					if (r.success) {
						showMsg(r.data && r.data.message ? r.data.message : (wtoElementorOtp && wtoElementorOtp.strings ? wtoElementorOtp.strings.sent : 'کد تأیید ارسال شد.'), false);
						$codeRow.show();
						$code.val('').focus();
					} else {
						showMsg((r.data && r.data.message) ? r.data.message : (wtoElementorOtp && wtoElementorOtp.strings ? wtoElementorOtp.strings.error : 'خطا'), true);
					}
				}).fail(function () {
					showMsg(wtoElementorOtp && wtoElementorOtp.strings ? wtoElementorOtp.strings.error : 'خطا', true);
				}).always(function () {
					$sendBtn.prop('disabled', false);
				});
			});

			$verifyBtn.on('click', function () {
				var mobile = $mobile.val().trim();
				var code = $code.val().trim();
				if (!mobile || !code) {
					showMsg(wtoElementorOtp && wtoElementorOtp.strings ? wtoElementorOtp.strings.enter_code : 'کد تأیید را وارد کنید.', true);
					return;
				}
				$verifyBtn.prop('disabled', true);
				showMsg(wtoElementorOtp && wtoElementorOtp.strings ? wtoElementorOtp.strings.verifying : 'در حال تأیید...', false);
				$.post(wtoElementorOtp.ajaxurl, {
					action: 'wto_otp_verify',
					nonce: wtoElementorOtp.nonce_verify,
					mobile: mobile,
					code: code,
					context: 'elementor',
					form_id: formId
				}).done(function (r) {
					if (r.success) {
						showMsg(r.data && r.data.message ? r.data.message : (wtoElementorOtp.strings ? wtoElementorOtp.strings.verified : 'تأیید شد.'), false);
						$wrap.attr('data-otp-verified', '1');
					} else {
						showMsg((r.data && r.data.message) ? r.data.message : (wtoElementorOtp.strings ? wtoElementorOtp.strings.code_invalid : 'کد اشتباه است.'), true);
					}
				}).fail(function () {
					showMsg(wtoElementorOtp.strings ? wtoElementorOtp.strings.error : 'خطا', true);
				}).always(function () {
					$verifyBtn.prop('disabled', false);
				});
			});
		});
	}

	$(document).ready(initOtpFields);
	$(window).on('elementor/frontend/init', function () {
		elementorFrontend.hooks.addAction('frontend/element_ready/form.default', function () {
			setTimeout(initOtpFields, 100);
		});
	});
})(jQuery);
