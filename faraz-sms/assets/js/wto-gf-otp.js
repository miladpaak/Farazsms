(function ($) {
	'use strict';

	function initOtpFields() {
		$('.wto-gf-otp').each(function () {
			var $wrap = $(this);
			if ($wrap.data('wto-otp-inited')) return;
			$wrap.data('wto-otp-inited', true);

			var formId = $wrap.data('form-id');
			var $mobile = $wrap.find('.wto-otp-mobile');
			var $sendBtn = $wrap.find('.wto-otp-send-btn');
			var $msg = $wrap.find('.wto-otp-msg');
			var $codeRow = $wrap.find('.wto-otp-code-row');
			var $code = $wrap.find('.wto-otp-code');
			var $verifyBtn = $wrap.find('.wto-otp-verify-btn');

			function showMsg(text, isError) {
				$msg.removeClass('wto-otp-msg-ok wto-otp-msg-err').addClass(isError ? 'wto-otp-msg-err' : 'wto-otp-msg-ok').text(text).show();
			}

			$sendBtn.on('click', function () {
				var mobile = $mobile.val().trim();
				if (!mobile) {
					showMsg(wtoGfOtp.strings.enter_mobile, true);
					return;
				}
				$sendBtn.prop('disabled', true);
				showMsg(wtoGfOtp.strings.sending, false);
				$.post(wtoGfOtp.ajaxurl, {
					action: 'wto_otp_send',
					nonce: wtoGfOtp.nonce_send,
					mobile: mobile
				}).done(function (r) {
					if (r.success) {
						showMsg(r.data && r.data.message ? r.data.message : wtoGfOtp.strings.sent, false);
						$codeRow.show();
						$code.val('').focus();
					} else {
						showMsg((r.data && r.data.message) ? r.data.message : wtoGfOtp.strings.error, true);
					}
				}).fail(function () {
					showMsg(wtoGfOtp.strings.error, true);
				}).always(function () {
					$sendBtn.prop('disabled', false);
				});
			});

			$verifyBtn.on('click', function () {
				var mobile = $mobile.val().trim();
				var code = $code.val().trim();
				if (!mobile || !code) {
					showMsg(wtoGfOtp.strings.enter_code, true);
					return;
				}
				$verifyBtn.prop('disabled', true);
				showMsg(wtoGfOtp.strings.verifying, false);
				$.post(wtoGfOtp.ajaxurl, {
					action: 'wto_otp_verify',
					nonce: wtoGfOtp.nonce_verify,
					mobile: mobile,
					code: code,
					context: 'gf',
					form_id: formId
				}).done(function (r) {
					if (r.success) {
						showMsg(r.data && r.data.message ? r.data.message : wtoGfOtp.strings.verified, false);
						$wrap.attr('data-otp-verified', '1');
					} else {
						showMsg((r.data && r.data.message) ? r.data.message : wtoGfOtp.strings.code_invalid, true);
					}
				}).fail(function () {
					showMsg(wtoGfOtp.strings.error, true);
				}).always(function () {
					$verifyBtn.prop('disabled', false);
				});
			});
		});
	}

	$(document).ready(initOtpFields);
	$(document).on('gform_post_render', initOtpFields);
})(jQuery);
