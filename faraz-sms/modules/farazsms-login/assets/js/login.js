jQuery(document).ready(function($) {

    // تبدیل ارقام فارسی (۰-۹) و عربی-هندی (٠-٩) به ارقام لاتین.
    // کاربر نباید مجبور باشد چیدمان صفحه‌کلید را عوض کند.
    function normalizeDigits(str) {
        if (str == null) return str;
        return String(str)
            .replace(/[۰-۹]/g, function (c) {
                return String.fromCharCode(c.charCodeAt(0) - 0x06F0 + 48);
            })
            .replace(/[٠-٩]/g, function (c) {
                return String.fromCharCode(c.charCodeAt(0) - 0x0660 + 48);
            });
    }

    function replaceDefaultForms() {
        if (!farazsms_ajax.replace_default_forms || !farazsms_ajax.replacement_form_html) {
            return false;
        }

        var replacementHtml = farazsms_ajax.replacement_form_html;
        var didReplace = false;

        $('.woocommerce-account .u-columns').each(function() {
            var $container = $(this);
            if ($container.find('.farazsms-inline-login-wrapper').length) {
                return;
            }

            if ($container.find('.woocommerce-form-login, .woocommerce-form-register').length) {
                $container.replaceWith(replacementHtml);
                didReplace = true;
            }
        });

        $('form').each(function() {
            var $form = $(this);

            if ($form.closest('.farazsms-main-login-form').length || $form.closest('.farazsms-inline-login-wrapper').length) {
                return;
            }

            if (
                $form.is('#loginform') ||
                $form.is('[name="loginform"]') ||
                ($form.attr('action') && $form.attr('action').indexOf('wp-login.php') !== -1) ||
                $form.hasClass('login') ||
                $form.hasClass('woocommerce-form-login') ||
                $form.hasClass('woocommerce-form-register')
            ) {
                $form.replaceWith(replacementHtml);
                didReplace = true;
            }
        });

        return didReplace;
    }

    replaceDefaultForms();

    // تم‌هایی مثل «وودمارت» فرمِ ورود را در پنلِ کشویی (off-canvas) دیرتر یا با AJAX
    // به صفحه اضافه می‌کنند؛ پس اجرای یک‌باره روی ready آن را از دست می‌دهد. با
    // MutationObserver هر فرمِ پیش‌فرضی که بعداً ساخته شود را هم جایگزین می‌کنیم.
    if (farazsms_ajax.replace_default_forms && farazsms_ajax.replacement_form_html && window.MutationObserver) {
        var farazReplaceTimer = null;
        var farazObserver = new MutationObserver(function(mutations) {
            var added = false;
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes && mutations[i].addedNodes.length) { added = true; break; }
            }
            if (!added) { return; }
            clearTimeout(farazReplaceTimer);
            farazReplaceTimer = setTimeout(replaceDefaultForms, 150);
        });
        farazObserver.observe(document.body, { childList: true, subtree: true });
    }

    $(document).on('submit', '.farazsms-main-login-form form[id="submit-identifier"]', function(e) {
        e.preventDefault();
        var identifierField = $('#identifier');
        if (!identifierField.val()) {
            $('#submit-identifier').addClass('has-error');
        } else {
            var formData = $(this).serialize();
            $.ajax({
                type: 'POST',
                url: farazsms_ajax.ajax_url,
                data: {
                    'action': 'identifier_ajax_handler',
                    'nonce': farazsms_ajax.nonce,
                    'data': formData,
                }, beforeSend: function() {
                    $('.farazsms-submit').hide();
                    $('.farazsms-submit').after("<div class='btn-loading'><div><div></div><div></div><div></div><div></div></div></div>");
                }, success: function(response) {
                    $('.farazsms-main-login-form').html(response);
                }, error: function(error) {
                }
            });
        }
    });

    $(document).on('submit', '.farazsms-main-login-form form[id="submit-login"]', function(e) {
        e.preventDefault();
        var formData = $(this).serialize();
        $.ajax({
            type: 'POST',
            url: farazsms_ajax.ajax_url,
            data: {
                'action': 'login_ajax_handler',
                'nonce': farazsms_ajax.nonce,
                'data': formData,
            }, beforeSend: function() {
                $('.farazsms-submit').hide();
                $('.farazsms-submit').after("<div class='btn-loading'><div><div></div><div></div><div></div><div></div></div></div>");
            }, success: function(response) {
                $('.farazsms-main-login-form').html(response);
            }, error: function(error) {
            }
        });
    });

    $(document).on('submit', '.farazsms-main-login-form form[id="submit-register"]', function(e) {
        e.preventDefault();
        var formData = $(this).serialize();
        $.ajax({
            type: 'POST',
            url: farazsms_ajax.ajax_url,
            data: {
                'action': 'register_ajax_handler',
                'nonce': farazsms_ajax.nonce,
                'data': formData,
            }, beforeSend: function() {
                $('.farazsms-submit').hide();
                $('.farazsms-submit').after("<div class='btn-loading'><div><div></div><div></div><div></div><div></div></div></div>");
            }, success: function(response) {
                $('.farazsms-main-login-form').html(response);
            }, error: function(error) {
            }
        });
    });

    $(document).on('click', '.farazsms-main-login-form #login-with-password', function(e) {
        e.preventDefault();
        
        var identifier = $('input[name="identifier"]').val(),
            identifier_type = $('input[name="identifier_type"]').val(),
            back_url = $('input[name="back_url"]').val(),
            formData = "identifier=" + identifier + "&identifier_type=" + identifier_type + "&back_url=" + back_url;
    
        $.ajax({
            type: 'POST',
            url: farazsms_ajax.ajax_url,
            data: {
                'action': 'login_password_ajax_handler',
                'nonce': farazsms_ajax.nonce,
                'data': formData,
            }, success: function(response) {
                $('.farazsms-main-login-form').html(response);
            }, error: function(error) {
            }
        });
    });

    $(document).on('click', '.farazsms-main-login-form #login-with-code', function(e) {
        e.preventDefault();
        var identifier = $('input[name="identifier"]').val(),
            identifier_type = $('input[name="identifier_type"]').val(),
            back_url = $('input[name="back_url"]').val(),
            formData = "identifier=" + identifier + "&identifier_type=" + identifier_type + "&back_url=" + back_url;
    
        $.ajax({
            type: 'POST',
            url: farazsms_ajax.ajax_url,
                data: {
                    'action': 'identifier_ajax_handler',
                    'nonce': farazsms_ajax.nonce,
                    'data': formData,
                }, success: function(response) {
                $('.farazsms-main-login-form').html(response);
            }, error: function(error) {
            }
        });
    });

    $(document).on('click', '.farazsms-main-login-form #forget-password-mobile', function(e) {
        e.preventDefault();
        var identifier = $('input[name="identifier"]').val(),
            identifier_type = $('input[name="identifier_type"]').val(),
            back_url = $('input[name="back_url"]').val(),
            formData = "identifier=" + identifier + "&identifier_type=" + identifier_type + "&back_url=" + back_url;
    
        $.ajax({
            type: 'POST',
            url: farazsms_ajax.ajax_url,
            data: {
                'action': 'forget_mobile_password_ajax_handler',
                'nonce': farazsms_ajax.nonce,
                'data': formData,
            }, success: function(response) {
                $('.farazsms-main-login-form').html(response);
            }, error: function(error) {
            }
        });
    });

    $(document).on('submit', '.farazsms-main-login-form form[id="submit-forget-code"]', function(e) {
        e.preventDefault();
        var formData = $(this).serialize();
        $.ajax({
            type: 'POST',
            url: farazsms_ajax.ajax_url,
            data: {
                'action': 'forget_code_ajax_handler',
                'nonce': farazsms_ajax.nonce,
                'data': formData,
            }, beforeSend: function() {
                $('.farazsms-submit').hide();
                $('.farazsms-submit').after("<div class='btn-loading'><div><div></div><div></div><div></div><div></div></div></div>");
            }, success: function(response) {
                $('.farazsms-main-login-form').html(response);
            }, error: function(error) {
            }
        });
    });

    $(document).on('submit', '.farazsms-main-login-form form[id="submit-reset-password"]', function(e) {
        e.preventDefault();
        var formData = $(this).serialize();
        $.ajax({
            type: 'POST',
            url: farazsms_ajax.ajax_url,
            data: {
                'action': 'reset_password_ajax_handler',
                'nonce': farazsms_ajax.nonce,
                'data': formData,
            }, beforeSend: function() {
                $('.farazsms-submit').hide();
                $('.farazsms-submit').after("<div class='btn-loading'><div><div></div><div></div><div></div><div></div></div></div>");
            }, success: function(response) {
                $('.farazsms-main-login-form').html(response);
            }, error: function(error) {
            }
        });
    });

    
    function initializeTimer() {
        var timerElement = document.querySelector('#farazsms-timer');
        if (!timerElement) {
          return;
        }
        var time = { minutes: parseInt(timerElement.getAttribute('data-time'), 10), seconds: 0 };
      
        var timerInterval = setInterval(function () {
          timerElement.textContent = formatTime(time);
      
          if (time.minutes === 0 && time.seconds === 0) {
            timerElement.innerHTML = "<a href='#' id='login-with-code' class='farazsms-change-link'>" + farazsms_ajax.resend_code_text + "</a>";
            $(".farazsms-timer-text").hide();
            clearInterval(timerInterval);
          } else {
            if (time.seconds > 0) {
              time.seconds--;
            } else {
              time.minutes--;
              time.seconds = 59;
            }
          }
        }, 1000);
    }
      
    function formatTime(time) {
        var formattedMinutes = time.minutes < 10 ? "0" + time.minutes : time.minutes;
        var formattedSeconds = time.seconds < 10 ? "0" + time.seconds : time.seconds;
        return formattedMinutes + ":" + formattedSeconds;
    }
      
    initializeTimer();
    $(document).ajaxComplete(function () {
        initializeTimer();
        replaceDefaultForms();
    });


    $(document).on('input', '.otp-digit', function() {
        var $this = $(this);
        // ارقام فارسی/عربی تایپ‌شده را بلافاصله به لاتین تبدیل کن.
        var normalized = normalizeDigits($this.val());
        if (normalized !== $this.val()) {
            $this.val(normalized);
        }
        var val = $this.val();

        // اگر autofillِ گوشی (آیفون) کلِ کد را داخلِ یک خانه ریخت، بینِ خانه‌ها پخش کن.
        if (val.length > 1) {
            fillOtp(val);
            return;
        }

        if (val.length === 1) {
            var nextIndex = parseInt($this.data('index')) + 1;
            var $nextInput = $('.otp-digit[data-index="' + nextIndex + '"]');
            
            if ($nextInput.length) {
                $nextInput.focus();
            } else {
                $this.blur();
            }
            
            updateVerificationCode();
        }
    });
    
    $(document).on('keydown', '.otp-digit', function(e) {
        var $this = $(this);
        var key = e.key;
        
        if (key === 'Backspace' && $this.val() === '') {
            var prevIndex = parseInt($this.data('index')) - 1;
            var $prevInput = $('.otp-digit[data-index="' + prevIndex + '"]');
            
            if ($prevInput.length) {
                $prevInput.focus();
            }
        }
        
        // کلیدِ عددی فارسی/عربی را هم مجاز بشمار (بعد از نرمال‌سازی به لاتین).
        if (!/^\d$/.test(normalizeDigits(key)) &&
            key !== 'Backspace' &&
            key !== 'Delete' &&
            key !== 'ArrowLeft' &&
            key !== 'ArrowRight' &&
            key !== 'Tab') {
            e.preventDefault();
        }
        
        updateVerificationCode();
    });
    
    $(document).on('paste', '.otp-digit', function(e) {
        // دسترسی به کلیپ‌بورد سازگار با همه مرورگرها (Safariِ مک‌بوک نیز).
        var clip = (e.originalEvent || e).clipboardData || window.clipboardData;
        if (!clip) { return; }
        var raw = clip.getData('text') || clip.getData('text/plain') || '';
        // فقط ارقام را استخراج کن — حتی اگر کاربر کلِ متنِ پیامک («کد شما ۱۲۳۴۵۶ است») را
        // از iMessage کپی کند یا فاصله/نیم‌فاصله داشته باشد. ارقام فارسی/عربی → لاتین.
        var digits = normalizeDigits(raw).replace(/\D/g, '');
        if (!digits) { return; }
        e.preventDefault();
        fillOtp(digits);
    });

    $('.otp-digit').first().focus();

    // پخشِ ارقامِ کد بینِ خانه‌ها — مشترکِ paste، autofillِ آیفون و WebOTPِ اندروید.
    function fillOtp(raw) {
        var digits = normalizeDigits(String(raw)).replace(/\D/g, '');
        if (!digits) { return; }
        var $inputs = $('.otp-digit');
        if (!$inputs.length) { return; }
        digits = digits.slice(0, $inputs.length); // فقط به تعدادِ خانه‌ها
        $inputs.each(function (index) {
            $(this).val(digits[index] || '');
        });
        updateVerificationCode();
        // فوکوس روی اولین خانه‌ی خالی (اگر کد ناقص بود) وگرنه آخرین خانه.
        var $firstEmpty = $inputs.filter(function () { return !$(this).val(); }).first();
        ($firstEmpty.length ? $firstEmpty : $inputs.last()).focus();
    }

    // دریافتِ خودکارِ کد از پیامک در اندروید/کروم (WebOTP API).
    // پیش‌نیاز: HTTPS و اینکه پیامک با خطِ «@دامنه #کد» تمام شود.
    var webOtpController = null;
    function startWebOTP() {
        if (!('OTPCredential' in window)) { return; }
        if (!$('.otp-digit').length) { return; }
        if (webOtpController) { return; } // در حالِ گوش‌دادن است.
        webOtpController = new AbortController();
        navigator.credentials.get({
            otp: { transport: ['sms'] },
            signal: webOtpController.signal
        }).then(function (otp) {
            webOtpController = null;
            if (otp && otp.code) { fillOtp(otp.code); }
        }).catch(function () {
            webOtpController = null; // کاربر رد کرد یا تایم‌اوت — بی‌اهمیت.
        });
    }
    startWebOTP();
    $(document).ajaxComplete(function () { startWebOTP(); });

    function updateVerificationCode() {
        var code = '';
        var allFilled = true;

        $('.otp-digit').each(function() {
            var digit = normalizeDigits($(this).val());
            code += digit;
            if (digit === '') {
                allFilled = false;
            }
        });

        $('#verification_code').val(normalizeDigits(code));

        // Auto-submit form when all digits are filled
        if (allFilled && code.length === $('.otp-digit').length) {
            var $form = $('.farazsms-main-login-form form');
            if ($form.length > 0) {
                $form.trigger('submit');
            }
        }
    }
});
