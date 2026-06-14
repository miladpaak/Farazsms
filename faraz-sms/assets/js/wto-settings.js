jQuery(document).ready(function($){
    "use strict";

    // امنیت (CSRF): به همه‌ی درخواست‌های ذخیره‌ی تنظیمات (اکشن‌های wto_save_*) به‌صورت
    // خودکار nonce اضافه می‌شود؛ بدون نیاز به تغییر تک‌تک فرم‌ها.
    $.ajaxPrefilter(function(options) {
        var n = (typeof wto_settings_info !== 'undefined' && wto_settings_info.save_nonce) ? wto_settings_info.save_nonce : '';
        if (!n) { return; }
        var d = options.data;
        if (d && typeof d === 'object' && d.action && String(d.action).indexOf('wto_save_') === 0) {
            d.wto_save_nonce = n;
        } else if (typeof d === 'string' && d.indexOf('action=wto_save_') !== -1 && d.indexOf('wto_save_nonce=') === -1) {
            options.data = d + '&wto_save_nonce=' + encodeURIComponent(n);
        }
    });

/*    $(".time_type input[type=radio]").change(function () {
        var rval = $(this).val();
        if (rval === 'hour'){
            $(this).parent().parent().prev().find('.next_type').html('ساعت');
            $(this).parent().parent().parent().parent().find('.hour').hide();
        }else {
            $(this).parent().parent().prev().find('.next_type').html('روز');
            $(this).parent().parent().parent().parent().find('.hour').fadeIn();
        }
    });*/

    $('.wto_select2').select2({
        theme: "classic",
        "language": {
            "noResults": function(){
                return "نتیجه ای پیدا نشد!";
            }
        }
    });
    $("#wto_custom_phone_meta_keys").select2({
        tags: true
    });
    $.validator.addMethod("time", function(value, element) {
        return this.optional(element) || /^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/.test(value);
    }, "لطفا ساعت را با فرمت درست وارد کنید");

    var save_button = $('#wto_save_button');
    var response_message = $('#wto-response-message');

// فرم کد رهگیری: فقط کد پترن
$('#wto_settings_form').validate({
    rules: {
        wto_pattern: "required"
    },
    messages: {
        wto_pattern: "کد پترن اجباری می باشد"
    },
    submitHandler: function(form) {
        var data = {
            'action': 'wto_save_credentials',
            'form': 'tracking',
            'pattern': $('#wto_pattern').val()
        };
        var response_message = $('#wto-response-message');
        var save_button = $('#wto_save_button');

        response_message.empty().removeClass().hide();
        save_button.addClass("wto_button--loading");

        $.post(ajaxurl, data, function(response) {
            save_button.removeClass("wto_button--loading");
            if(!response.success){
                $('<span>'+response.data+'</span>').appendTo(response_message);
                response_message.addClass("wto-error-message").show();
            } else {
                $('<span>اطلاعات با موفقیت ذخیره شد</span>').appendTo(response_message);
                response_message.addClass("wto-success-message").show();
                setTimeout(function() {
                    location.reload();
                }, 500);
            }
        });
    }
});

// فرم تنظیمات: کلید دسترسی و خط ارسال کننده
$('#wto_settings_credentials_form').validate({
    rules: {
        wto_apikey: "required"
    },
    messages: {
        wto_apikey: "کلید دسترسی اجباری می باشد"
    },
    submitHandler: function(form) {
        var data = {
            'action': 'wto_save_credentials',
            'form': 'settings',
            'apikey': $('#wto_apikey_settings').val(),
            'sender': $('#wto_sender_settings').val(),
            'show_credit_in_admin_bar': $('#wto_show_credit_in_admin_bar').is(':checked') ? '1' : '0'
        };
        var response_message = $('#wto-settings-response-message');
        var save_button = $('#wto_save_settings_button');

        response_message.empty().removeClass().hide();
        save_button.addClass("wto_button--loading");

        $.post(ajaxurl, data, function(response) {
            save_button.removeClass("wto_button--loading");
            if(!response.success){
                $('<span>'+response.data+'</span>').appendTo(response_message);
                response_message.addClass("wto-error-message").show();
            } else {
                $('<span>اطلاعات با موفقیت ذخیره شد</span>').appendTo(response_message);
                response_message.addClass("wto-success-message").show();
                setTimeout(function() {
                    location.reload();
                }, 500);
            }
        });
    }
});

// فرم OTP در صفحه اطلاع رسانی گرویتی - المنتور
$('#wto_sms_forms_otp_form').validate({
    submitHandler: function(form) {
        var data = {
            'action': 'wto_save_credentials',
            'form': 'otp',
            'wto_otp_pattern': $('#wto_sms_forms_otp_pattern').val(),
            'wto_otp_message': $('#wto_sms_forms_otp_message').val()
        };
        var response_message = $('#wto-sms-forms-otp-response-message');
        var save_button = $('#wto_sms_forms_otp_save_button');

        response_message.empty().removeClass().hide();
        save_button.addClass("wto_button--loading");

        $.post(ajaxurl, data, function(response) {
            save_button.removeClass("wto_button--loading");
            if (!response.success) {
                $('<span>' + response.data + '</span>').appendTo(response_message);
                response_message.addClass("wto-error-message").show();
            } else {
                $('<span>اطلاعات با موفقیت ذخیره شد</span>').appendTo(response_message);
                response_message.addClass("wto-success-message").show();
                setTimeout(function() {
                    location.reload();
                }, 500);
            }
        });
    }
});

// Validation و handler برای فرم نظرسنجی
$('#wto_poll_settings_form').validate({
    rules: {
        wto_poll_pattern: "required",
        send_time: {
            required: function(element) {
                return $('#sms_poll_checkbox').is(':checked');
            },
            digits: true,
            min: 1
        },
        send_status: {
            required: function(element) {
                return $('#sms_poll_checkbox').is(':checked');
            }
        }
    },
    messages: {
        wto_poll_pattern: "کد پترن پیامک نظرسنجی اجباری می باشد",
        send_time: {
            required: "لطفا تعداد روز را وارد کنید",
            digits: "لطفا فقط عدد وارد کنید",
            min: "تعداد روز باید بزرگتر از صفر باشد"
        },
        send_status: {
            required: "لطفا وضعیت سفارش را انتخاب کنید"
        }
    },
    submitHandler: function(form) {
        var data = {
            'action': 'wto_save_credentials',
            'apikey': '',
            'pattern': '',
            'sender': '',
            'poll_pattern': $('#wto_poll_pattern').val(),
            'send_poll_sms': $('#sms_poll_checkbox').is(':checked') ? '1' : '0',
            'send_time': $('#send_time').val(),
            'send_status': $('#send_status').val()
        };
        var response_message = $('#wto-poll-response-message');
        var save_button = $('#wto_save_poll_button');

        response_message.empty().removeClass().hide();
        save_button.addClass("wto_button--loading");

        $.post(ajaxurl, data, function(response) {
            save_button.removeClass("wto_button--loading");
            if(!response.success){
                $('<span>'+response.data+'</span>').appendTo(response_message);
                response_message.addClass("wto-error-message").show();
            } else {
                $('<span>اطلاعات با موفقیت ذخیره شد</span>').appendTo(response_message);
                response_message.addClass("wto-success-message").show();
                setTimeout(function() {
                    location.reload();
                }, 500);
            }
        });
    }
});

// فرم دیدگاه سایت: ذخیره همه فیلدها با form=comments
$('#wto_comments_settings_form').on('submit', function(e) {
    e.preventDefault();
    var $form = $(this);
    var $btn = $('#wto_save_comments_button');
    var $msg = $('#wto-comments-response-message');
    $msg.empty().removeClass().hide();
    $btn.addClass("wto_button--loading");
    var data = {
        action: 'wto_save_credentials',
        form: 'comments',
        wto_comment_admin_phones: $form.find('#wto_comment_admin_phones').val(),
        wto_comment_admin_pattern: $form.find('#wto_comment_admin_pattern').val(),
        wto_comment_admin_message: $form.find('#wto_comment_admin_message').val(),
        wto_comment_user_approve_pattern: $form.find('#wto_comment_user_approve_pattern').val(),
        wto_comment_user_approve_message: $form.find('#wto_comment_user_approve_message').val(),
        wto_comment_user_reply_pattern: $form.find('#wto_comment_user_reply_pattern').val(),
        wto_comment_user_reply_message: $form.find('#wto_comment_user_reply_message').val()
    };
    $.post(ajaxurl, data, function(response) {
        $btn.removeClass("wto_button--loading");
        if (!response.success) {
            $('<span>' + (response.data || 'خطا') + '</span>').appendTo($msg);
            $msg.addClass("wto-error-message").show();
        } else {
            $('<span>اطلاعات با موفقیت ذخیره شد</span>').appendTo($msg);
            $msg.addClass("wto-success-message").show();
            setTimeout(function() { location.reload(); }, 800);
        }
    }).fail(function() {
        $btn.removeClass("wto_button--loading");
        $msg.html('<span>خطا در ارتباط با سرور</span>').addClass("wto-error-message").show();
    });
});

var create_pattern_button = $('#wto_save_create_pattern');
var create_pattern_response = $('#wto-create-pattern-response');

create_pattern_button.on('click', function(e) {
    e.preventDefault();

    var message = $('#wto_create_pattern').val();
    
    if (!message || message.trim() === '') {
        alert('⚠️ متن پیامک خالی است. لطفا ابتدا متن را وارد کنید.');
        return;
    }

    create_pattern_response.empty().removeClass().hide();
    create_pattern_button.addClass("wto_button--loading");

    $.post(ajaxurl, {
        action: 'wto_create_pattern',
        message_text: message,
        nonce: typeof wto_settings_info !== 'undefined' ? wto_settings_info.create_pattern_nonce : ''
    }, function(response) {
        create_pattern_button.removeClass("wto_button--loading");
        if (!response.success) {
            var errorMsg = '❌ خطا در ساخت پترن:\n\n';
            if (response.data && response.data.message) {
                errorMsg += response.data.message;
            } else if (response.data && typeof response.data === 'string') {
                errorMsg += response.data;
            } else {
                errorMsg += 'خطای ناشناخته';
            }
            $('<span>' + errorMsg + '</span>').appendTo(create_pattern_response);
            create_pattern_response.addClass("wto-error-message").show();
        } else {
            var successMsg = '✅ پترن با موفقیت ساخته شد!';
            if (response.data && response.data.pattern_code) {
                successMsg += '\n\nکد پترن: ' + response.data.pattern_code;
                successMsg += '\n\nکد پترن به‌صورت خودکار در تنظیمات ذخیره شد.';
                
                // به‌روزرسانی input کد پترن
                $('#wto_pattern').val(response.data.pattern_code);
            }
            $('<span>' + successMsg + '</span>').appendTo(create_pattern_response);
            create_pattern_response.addClass("wto-success-message").show();
            // refresh صفحه بعد از 2 ثانیه
            setTimeout(function() {
                location.reload();
            }, 2000);
        }
    }).fail(function(xhr, status, error) {
        create_pattern_button.removeClass("wto_button--loading");
        $('<span>❌ خطا در ارتباط با سرور: ' + error + '</span>').appendTo(create_pattern_response);
        create_pattern_response.addClass("wto-error-message").show();
    });
});

// دکمه‌های «ساخت پترن» در صفحه دیدگاه سایت و صفحه OTP (اطلاع رسانی گرویتی - المنتور)
$('.wto-comment-create-pattern').on('click', function(e) {
    e.preventDefault();
    var nonce = typeof wto_settings_info !== 'undefined' && wto_settings_info.create_pattern_nonce ? wto_settings_info.create_pattern_nonce : '';
    if (!nonce) {
        alert('تنظیمات صفحه بارگذاری نشده. صفحه را یک بار رفرش کنید.');
        return;
    }
    var $btn = $(this);
    var targetPatternId = $btn.data('target_pattern');
    var targetMessageId = $btn.data('target_message');
    var $textarea = $('#' + targetMessageId);
    var message = $textarea.val();
    var sectionType = $textarea.data('section_type');
    var statusKey = $textarea.data('status_key');
    var patternCode = targetPatternId ? ($('#' + targetPatternId).val() || '').trim() : '';
    var $responseBox = $btn.nextAll('.wto-comment-pattern-response').first();
    if (!message || message.trim() === '') {
        alert('متن پیامک خالی است. لطفا ابتدا متن را وارد کنید.');
        return;
    }
    $responseBox.empty().removeClass().hide();
    $btn.addClass("wto_button--loading");
    $.post(ajaxurl, {
        action: 'wto_create_pattern',
        message_text: message,
        nonce: nonce,
        section_type: sectionType,
        status_key: statusKey,
        pattern_code: patternCode
    }, function(response) {
        $btn.removeClass("wto_button--loading");
        if (!response.success) {
            var err = (response.data && response.data.message) ? response.data.message : (response.data || 'خطا');
            $responseBox.html('<span>' + err + '</span>').addClass("wto-error-message").show();
        } else {
            if (response.data && response.data.pattern_code) {
                $('#' + targetPatternId).val(response.data.pattern_code);
            }
            var okMsg = (response.data && response.data.updated)
                ? 'پترن با موفقیت به‌روزرسانی شد. در صورت نیاز «ذخیره» را بزنید.'
                : 'پترن با موفقیت ساخته شد. کد در فیلد کد پترن قرار گرفت. ذخیره را بزنید.';
            $responseBox.html('<span>' + okMsg + '</span>').addClass("wto-success-message").show();
        }
    }).fail(function(xhr, status, error) {
        $btn.removeClass("wto_button--loading");
        $responseBox.html('<span>خطا در ارتباط با سرور</span>').addClass("wto-error-message").show();
    });
});

    var clickedTab = $(".tabs > .active");
    var tabWrapper = $(".tab__content");
    var activeTab = tabWrapper.find(".active");
    var activeTabHeight = activeTab.outerHeight();
    // Show tab on page load
    activeTab.show();
    // Set height of wrapper on page load
    tabWrapper.height(activeTabHeight);
    $(".tabs > li").on("click", function() {
        $(".tabs > li").removeClass("active");
        $(this).addClass("active");
        clickedTab = $(".tabs .active");
        activeTab.fadeOut(150, function() {
            $(".tab__content > li").removeClass("active");
            var clickedTabIndex = clickedTab.index();
            $(".tab__content > li").eq(clickedTabIndex).addClass("active");
            activeTab = $(".tab__content > .active");
            activeTabHeight = activeTab.outerHeight();
            tabWrapper.stop().delay(10).animate({
                height: activeTabHeight
            }, 200, function() {
                activeTab.delay(10).fadeIn(150);
            });
        });
    });

    var save_users_settings_button = $('#wto_save_users_settings_button');
    var users_settings_response_message = $('#wto-users-settings-response-message');
    $('#wto_users_settings_form').validate({
        rules: {
            // wto_inputs: "required",
        },
        messages: {
            // wto_uname: "نام کاربری اجباری می باشد",
        },
        submitHandler: function(form) {
            var data = {
                'action': 'wto_save_users_settings',
                'wto_active_digits': $('#wto_active_digits').is(":checked"),
                'wto_custom_phone_meta_keys': $('#wto_custom_phone_meta_keys').val(),
            };
            users_settings_response_message.empty().removeClass().hide();
            save_users_settings_button.addClass("wto_button--loading");
            $.post(ajaxurl, data, function (response) {
                save_users_settings_button.removeClass("wto_button--loading");
                if(!response.success){
                    $('<span>'+response.data+'</span>').appendTo(users_settings_response_message);
                    users_settings_response_message.addClass("wto-error-message").show();
                }else {
                    $('<span>اطلاعات با موفقیت ذخیره شد</span>').appendTo(users_settings_response_message);
                    users_settings_response_message.addClass("wto-success-message").show();
                    //setTimeout(function(){ location.reload(); }, 500);
                }
            });
        }
    });



    var save_wc_button = $('#wto_save_wc_button');
    var wc_response_message = $('#wto-wc-response-message');
    $('#wto_wc_form').validate({
        rules: {
            // wto_inputs: "required",
        },
        messages: {
            // wto_uname: "نام کاربری اجباری می باشد",
        },
        submitHandler: function(form) {
            var sms_data = $( "#wto_wc_form" ).serializeArray();
            var data = {
                'action': 'wto_save_wc_sms_data',
                'sms_data': sms_data,
            };
            wc_response_message.empty().removeClass().hide();
            save_wc_button.addClass("wto_button--loading");
            $.post(ajaxurl, data, function (response) {
                save_wc_button.removeClass("wto_button--loading");
                if(!response.success){
                    $('<span>'+response.data+'</span>').appendTo(wc_response_message);
                    wc_response_message.addClass("wto-error-message").show();
                }else {
                    $('<span>اطلاعات با موفقیت ذخیره شد</span>').appendTo(wc_response_message);
                    wc_response_message.addClass("wto-success-message").show();
                    //setTimeout(function(){ location.reload(); }, 500);
                }
            });
        }
    });

    var save_users_button = $('#wto_save_users_button');
    var users_response_message = $('#wto-users-response-message');
    $('#wto_users_form').validate({
        rules: {
            // wto_inputs: "required",
        },
        messages: {
            // wto_uname: "نام کاربری اجباری می باشد",
        },
        submitHandler: function(form) {
            var sms_data = $( "#wto_users_form" ).serializeArray();
            var data = {
                'action': 'wto_save_users_sms_data',
                'sms_data': sms_data,
            };
            users_response_message.empty().removeClass().hide();
            save_users_button.addClass("wto_button--loading");
            $.post(ajaxurl, data, function (response) {
                save_users_button.removeClass("wto_button--loading");
                if(!response.success){
                    $('<span>'+response.data+'</span>').appendTo(users_response_message);
                    users_response_message.addClass("wto-error-message").show();
                }else {
                    $('<span>اطلاعات با موفقیت ذخیره شد</span>').appendTo(users_response_message);
                    users_response_message.addClass("wto-success-message").show();
                    //setTimeout(function(){ location.reload(); }, 500);
                }
            });
        }
    });

    $('#wto_users_form,#wto_wc_form').on('click', '.wto_active input:checkbox', function () {
        if($(this).is(":checked")){
            $(this).next('.wto_active_hidden').val('on')
        }else {
            $(this).next('.wto_active_hidden').val('off')
        }
    });

    var order_stauses = wto_settings_info.order_statuses;
    var order_statuses_option = '';
    for (var key in order_stauses) {
        if (order_stauses.hasOwnProperty(key)) {
            order_statuses_option += '<option value="' + key + '">' + order_stauses[key] + '</option>';
        }
    }

    var sms_container_wc = $('#sms_container_wc');
    var sms_container_form_submit_wc = $('.wto_save_button_container_wc');
    var add_sms_wc = $('#wto_add_sms_wc');
    var next_index_wc = $('#wto_next_index_wc').val();
    add_sms_wc.click(function () {
        var row = '<div class="sms">';
        row += '<div class="delete"><div id="delete_row_' + next_index_wc + '"><img src="' + wto_settings_info.delete_button +'"></div></div>';
        row += '<div class="wto_active"><label for="wto_sms_active_' + next_index_wc + '" class="toggle-control"><input type="checkbox" id="wto_sms_active_' + next_index_wc + '" name="wto_sms_meta[' + next_index_wc + '][active]"><input type="hidden" class="wto_active_hidden"  name="wto_sms_meta[' + next_index_wc + '][active_or_not]" value="off"><span class="control"></span></label></div>';
        row += '<div class="time"><label for="wto_sms_time_' + next_index_wc + '">زمان</label><input type="number" required min="0" class="wto_time_input" name="wto_sms_meta[' + next_index_wc + '][time]" id="wto_sms_time_' + next_index_wc + '"><span> روز</span></div>';
        row += '<div class="order_status">\n' +
            '                   <label for="wto_sms_order_status_' + next_index_wc + '">وضعیت سفارش</label>\n' +
            '                   <select name="wto_sms_meta[' + next_index_wc + '][order_status]" id="wto_sms_order_status_' + next_index_wc + '">' + order_statuses_option + '</select></div>';
        row += '<div class="hour"><label for="wto_sms_hour_' + next_index_wc + '">ساعت</label><input type="text" required placeholder="16:59" name="wto_sms_meta[' + next_index_wc + '][hour]" id="wto_sms_hour_' + next_index_wc + '"></div>';
        row += '<div class="sms_content"><label for="wto_sms_content_' + next_index_wc + '">متن پیام</label><textarea required rows="5" cols="20" name="wto_sms_meta[' + next_index_wc + '][content]" id="wto_sms_order_content_' + next_index_wc + '"></textarea></div>';
        row += '</div>';
        sms_container_form_submit_wc.before(row);
        next_index_wc++;
    });

    var sms_container = $('#sms_container');
    var sms_container_form_submit = $('#wto_users_form .wto_save_button_container');
    var add_sms = $('#wto_add_sms');
    var next_index = $('#wto_next_index').val();
    add_sms.click(function (){
        var row = '<div class="sms">';
        row += '<div class="delete"><div id="delete_row_' + next_index + '"><img src="' + wto_settings_info.delete_button +'"></div></div>';
        row += '<div class="wto_active"><label for="wto_sms_active_' + next_index + '" class="toggle-control"><input type="checkbox" id="wto_sms_active_' + next_index + '" name="wto_sms_meta[' + next_index + '][active]"><input type="hidden" class="wto_active_hidden"  name="wto_sms_meta[' + next_index + '][active_or_not]" value="off"><span class="control"></span></label></div>';
        row += '<div class="time"><label for="wto_sms_time_' + next_index + '">زمان</label><input type="number" required min="0" class="wto_time_input" name="wto_sms_meta[' + next_index + '][time]" id="wto_sms_time_' + next_index + '"><span> روز</span></div>';
        row += '<div class="hour"><label for="wto_sms_hour_' + next_index + '">ساعت</label><input type="text" required placeholder="16:59" name="wto_sms_meta[' + next_index + '][hour]" id="wto_sms_hour_' + next_index + '"></div>';
        row += '<div class="sms_content"><label for="wto_sms_content_' + next_index + '">متن پیام</label><textarea required rows="5" cols="20" name="wto_sms_meta[' + next_index + '][content]" id="wto_sms_order_content_' + next_index + '"></textarea></div>';
        row += '</div>';
        sms_container_form_submit.before(row);
        next_index++;
    })
    var delete_button = $('#sms_container .sms .delete');
    $('#sms_container,#sms_container_wc,#gf_form_and_fields').on('click', '.sms .delete > div', function () {
        if (confirm('آیا مطمئن هستید؟')) {
            $(this).parents(".sms").remove();
        } else {
            return;
        }
    });

    var gf_id;
    $('#wto-gravity-forms').on('select2:select', function (e) {
        gf_id = e.params.data.id;
        var fields_select = $("#wto-gravity-field");
        if(gf_id == -1){
            fields_select.prop("disabled", true);
        }else {
            fields_select.prop("disabled", false);
        }
        fields_select.select2({
            templateResult: formatState
        });
    });
    function formatState (state, container) {
        if(state.id){
            if(state.id.startsWith(gf_id.toString())) {
                $(container).addClass("gf_show");
            }else {
                $(container).addClass("gf_hide");
            }
        }
        return state.text;
    };

    $('#wto-gravity-field').on('select2:select', function (e) {
        var selected_field = e.params.data.id;
        $(".wto_gf_field_registered_sms .wto_show_hide_field").each(function() {
            if($( this ).attr('id') == 'frmid-fldid_' + selected_field){
                $( this ).show();
            }else {
                $( this ).hide();
            }
        });
    });
    $("#wto-gravity-field").prop("disabled", true);

    var wto_save_gf_sms_button = $('#wto_save_gf_sms_button');
    var wto_gf_sms_response_message = $('#wto-gf-sms-response-message');
    $('#wto_gf_sms_form').validate({
        rules: {
            // wto_inputs: "required",
        },
        messages: {
            // wto_uname: "نام کاربری اجباری می باشد",
        },
        submitHandler: function(form) {
            var sms_data = $( "#wto_gf_sms_form" ).serializeArray();
            var data = {
                'action': 'wto_save_gf_sms_data',
                'sms_data': sms_data,
            };
            wto_gf_sms_response_message.empty().removeClass().hide();
            wto_save_gf_sms_button.addClass("wto_button--loading");
            $.post(ajaxurl, data, function (response) {
                wto_save_gf_sms_button.removeClass("wto_button--loading");
                if(!response.success){
                    $('<span>'+response.data+'</span>').appendTo(wto_gf_sms_response_message);
                    wto_gf_sms_response_message.addClass("wto-error-message").show();
                }else {
                    $('<span>اطلاعات با موفقیت ذخیره شد</span>').appendTo(wto_gf_sms_response_message);
                    wto_gf_sms_response_message.addClass("wto-success-message").show();
                }
            });
        }
    });

    $('#wto_gf_sms_form').on('click', '.wto_active input:checkbox', function (){
        if($(this).is(":checked")){
            $(this).next('.wto_active_hidden').val('on')
        }else {
            $(this).next('.wto_active_hidden').val('off')
        }
    })

    var add_gf_sms = $('.wto_gf_add_sms');
    var next_gf_index = $('#wto_gf_next_index').val();
    var sms_gf_container_form_submit = $('#wto_gf_sms_form .wto_save_button_container');
    add_gf_sms.click(function (){
        var gf_id = $(this).parent().prop('id').replace('frmid-fldid_','');
        var opt = '';
        $("#wto-gravity-field option").each(function()
        {
            var res = $(this).val().split("-");
            var id = gf_id.split("-");
            if(res[0] == id[0]){
                opt += '<option value="'+res[1]+'">'+$(this).text()+'</option>'
            }
        });
        var row = '<div class="sms">';
        row += '<input type="hidden" name="wto_gf_sms_meta[' + next_gf_index + '][gf_formatted_id]" value="' + gf_id + '">';
        row += '<div class="delete"><div id="delete_gf_row_' + next_gf_index + '"><img src="' + wto_settings_info.delete_button +'"></div></div>';
        row += '<div class="wto_active"><label for="wto_gf_sms_active_' + next_gf_index + '" class="toggle-control"><input type="checkbox" id="wto_gf_sms_active_' + next_gf_index + '" name="wto_gf_sms_meta[' + next_gf_index + '][active]"><input type="hidden" class="wto_active_hidden"  name="wto_gf_sms_meta[' + next_gf_index + '][active_or_not]" value="off"><span class="control"></span></label></div>';
        row += '<div class="time"><label for="wto_gf_sms_time_' + next_gf_index + '">زمان</label><input type="number" required min="0" class="wto_time_input" name="wto_gf_sms_meta[' + next_gf_index + '][time]" id="wto_gf_sms_time_' + next_gf_index + '"><span> روز</span></div>';
        row += '<div class="hour"><label for="wto_gf_sms_hour_' + next_gf_index + '">ساعت</label><input type="text" minlength="5" maxlength="5" required placeholder="16:59" name="wto_gf_sms_meta[' + next_gf_index + '][hour]" id="wto_gf_sms_hour_' + next_gf_index + '"></div>';
        row += '<div class="condition"><label for="wto_gf_condition_active_' + next_gf_index + '" class="toggle-control">\n' +
            '                                                                    منطق شرطی\n' +
            '                                                                    <input class="wto_inputs wto_condition_toggle" type="checkbox" id="wto_gf_condition_active_' + next_gf_index + '" name="wto_gf_sms_meta[' + next_gf_index + '][condition_active]">\n' +
            '                                                                    <span class="control"></span>\n' +
            '                                                                </label></div>';
        row += '<div class="sms_content"><label for="wto_gf_sms_content_' + next_gf_index + '">متن پیام</label><textarea required rows="5" cols="20" name="wto_gf_sms_meta[' + next_gf_index + '][content]" id="wto_gf_sms_order_content_' + next_gf_index + '"></textarea></div>';
        row += '</div><div style="display: none" class="wto_condition_container" id="wto_condition_container_' + next_gf_index + '" style="">\n' +
            '                                                            <div class="wto_if_all_condition">\n' +
            '                                                                <span>ارسال پیامک اگر</span>\n' +
            '                                                                <select name="wto_gf_sms_meta[' + next_gf_index + '][all_or_one]">\n' +
            '                                                                    <option value="all" selected="">همه</option>\n' +
            '                                                                    <option value="any">حداقل یکی</option>\n' +
            '                                                                </select>\n' +
            '                                                                <span>از شرط های زیر برقرار بود:</span>\n' +
            '                                                                <div class="plus-button plus-button--small"></div>\n' +
            '                                                            </div>\n' +
            '                                                                 <span>اگر </span><div class="wto_conditions"><select class="wto_gf_conditional_field" name="wto_gf_condition_field_[' + next_gf_index + '][0][field]" id="wto_gf_condition_field_' + next_gf_index + '_0">\n' +
            '\t                                                               '+opt+' </select>\n' +
            '                                                                \n' +
            '                                                                <select class="wto_gf_conditional_operator" id="wto_gf_condition_operator_' + next_gf_index + '_0" name="wto_gf_condition_operator_[' + next_gf_index + '][0][operator]">\n' +
            '                                                                    <option value="is" selected="">هست</option>\n' +
            '                                                                    <option value="isnot">نیست</option>\n' +
            '                                                                    <option value=">">بزرگتر از</option>\n' +
            '                                                                    <option value="<">کوچکتر از</option>\n' +
            '                                                                    <option value="contains">شامل میشود</option>\n' +
            '                                                                    <option value="starts_with">شروع میشود</option>\n' +
            '                                                                    <option value="ends_with">تمام میشود</option>\n' +
            '                                                                </select>\n' +
            '                                                                <div id="wto_gf_condition_value_' + next_gf_index + '_0" style="display:inline;"><input type="text" class="condition_field_value" style="padding:3px" placeholder="یک مقدار وارد کنید" id="wto_gf_condition_value_10_10" name="wto_gf_condition_value_[' + next_gf_index + '][0][value]" value=""></div>\n' +
            '                                                                <div class="minus-button plus-button--small"></div>\n' +
            '                                                            </div>\n' +
            '                                                                                                                    </div>';
        $(this).parent().append(row);
        next_gf_index++;
    })


    var condition_toggle = $('#wto_gf_sms_form');
    condition_toggle.on('click', '.wto_condition_toggle',function() {
        var id = $(this).attr('id').replace('wto_gf_condition_active_','');
        var condition_container = $("#wto_condition_container_" + id);
        if( $(this).is(':checked')) {
            condition_container.show();
        } else {
            condition_container.hide();
        }
    });
    condition_toggle.find('.wto_condition_toggle').each(function (){
        if($(this).is(":checked")){
            var id = $(this).attr('id').replace('wto_gf_condition_active_','');
            var condition_container = $("#wto_condition_container_" + id);
            condition_container.show();
        }
    })

    var ind = 1;
    $("#wto_gf_sms_form").on('click', '.plus-button',function (){
        var id = $(this).parents(".wto_condition_container").attr('id').replace('wto_condition_container_','');
        ind = $(this).parents('.wto_condition_container').children(".wto_conditions").length;
        var opt2 = '';
        $(this).parents(".wto_condition_container").find(".wto_conditions .wto_gf_conditional_field option").each(function (){
            opt2 += '<option value="'+$(this).val()+'">'+$(this).text()+'</option>'
        })
        var con = '<div class="wto_conditions">\n' +
            '                                                                <span>اگر </span><select class="wto_gf_conditional_field" name="wto_gf_condition_field_['+id+']['+ind+'][field]" id="wto_gf_condition_field_'+id+'_'+ind+'">'+opt2+'</select>\n' +
            '                                                                <select class="wto_gf_conditional_operator valid" id="wto_gf_condition_operator_'+id+'_'+ind+'" name="wto_gf_condition_operator_['+id+']['+ind+'][operator]" aria-invalid="false">\n' +
            '                                                                    <option value="is">هست</option>\n' +
            '                                                                    <option value="isnot">نیست</option>\n' +
            '                                                                    <option value=">">بزرگتر از</option>\n' +
            '                                                                    <option value="<">کوچکتر از</option>\n' +
            '                                                                    <option value="contains">شامل میشود</option>\n' +
            '                                                                    <option value="starts_with">شروع میشود</option>\n' +
            '                                                                    <option value="ends_with">تمام میشود</option>\n' +
            '                                                                </select>\n' +
            '                                                                <div id="wto_gf_condition_value_'+id+'_'+ind+'" style="display:inline;"><input type="text" class="condition_field_value" style="padding:3px" placeholder="یک مقدار وارد کنید" id="wto_gf_condition_value_'+id+'_'+ind+'" name="wto_gf_condition_value_['+id+']['+ind+'][value]" value=""></div>\n' +
            '                                                                <div class="minus-button plus-button--small"></div>\n' +
            '                                                            </div>';
        $(this).parents('.wto_condition_container').append(con)
        ind++;
    })

    $("#wto_gf_sms_form").on("click", '.minus-button', function (){
        $(this).parent().remove()
    })

});

const checkbox = document.getElementById('sms_poll_checkbox');
const sendTimeInput = document.getElementById('send_time');
const sendStatusSelect = document.getElementById('send_status');
const pollPatternInput = document.getElementById('wto_poll_pattern');

function toggleInputs() {
  if (checkbox.checked) {
    sendTimeInput.disabled = false;
    sendStatusSelect.disabled = false;
    pollPatternInput.disabled = false;
    sendTimeInput.focus();
  } else {
    sendTimeInput.disabled = true;
    sendTimeInput.value = '';
    sendStatusSelect.disabled = true;
    sendStatusSelect.selectedIndex = 0;
    pollPatternInput.disabled = true;
    pollPatternInput.value = '';
  }
}

// فقط وقتی چک‌باکس روی صفحه هست، رویداد و مقدار اولیه را تنظیم کن
if (checkbox) {
  checkbox.addEventListener('change', toggleInputs);
  window.addEventListener('DOMContentLoaded', () => {
    toggleInputs();
  });
}
