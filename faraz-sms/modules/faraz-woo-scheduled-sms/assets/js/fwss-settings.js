jQuery(document).ready(function($){
    "use strict";

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

    $('.fwss_select2').select2({
        theme: "classic",
        "language": {
            "noResults": function(){
                return "نتیجه ای پیدا نشد!";
            }
        }
    });
    $("#fwss_custom_phone_meta_keys").select2({
        tags: true
    });
    $.validator.addMethod("time", function(value, element) {
        return this.optional(element) || /^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/.test(value);
    }, "لطفا ساعت را با فرمت درست وارد کنید");

    var save_button = $('#fwss_save_button');
    var response_message = $('#fwss-response-message');
    $('#fwss_settings_form').validate({
        // v3.17.6: validation برای فیلد apikey حذف شد — کلید از تنظیمات اصلی می‌آید
        rules: {
            fwss_send_time: "required time",
        },
        messages: {
            fwss_send_time: "لطفا ساعت را با فرمت درست وارد کنید",
        },
        submitHandler: function(form) {
            // v3.17.6: apikey از فرم خوانده نمی‌شود — backend از wto_get_apikey() می‌گیرد
            var data = {
                'action': 'fwss_save_credentials',
                'sender': $('#fwss_sender').val(),
                'send_time': $('#fwss_send_time').val(),
            };
            response_message.empty().removeClass().hide();
            save_button.addClass("fwss_button--loading");
            $.post(ajaxurl, data, function (response) {
                save_button.removeClass("fwss_button--loading");
                if(!response.success){
                    $('<span>'+response.data+'</span>').appendTo(response_message);
                    response_message.addClass("fwss-error-message").show();
                }else {
                    $('<span>اطلاعات با موفقیت ذخیره شد</span>').appendTo(response_message);
                    response_message.addClass("fwss-success-message").show();
                    setTimeout(function(){ location.reload(); }, 500);
                }
            });
        }
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

    var save_users_settings_button = $('#fwss_save_users_settings_button');
    var users_settings_response_message = $('#fwss-users-settings-response-message');
    $('#fwss_users_settings_form').validate({
        rules: {
            // fwss_inputs: "required",
        },
        messages: {
            // fwss_uname: "نام کاربری اجباری می باشد",
        },
        submitHandler: function(form) {
            var data = {
                'action': 'fwss_save_users_settings',
                'fwss_active_digits': $('#fwss_active_digits').is(":checked"),
                'fwss_custom_phone_meta_keys': $('#fwss_custom_phone_meta_keys').val(),
            };
            users_settings_response_message.empty().removeClass().hide();
            save_users_settings_button.addClass("fwss_button--loading");
            $.post(ajaxurl, data, function (response) {
                save_users_settings_button.removeClass("fwss_button--loading");
                if(!response.success){
                    $('<span>'+response.data+'</span>').appendTo(users_settings_response_message);
                    users_settings_response_message.addClass("fwss-error-message").show();
                }else {
                    $('<span>اطلاعات با موفقیت ذخیره شد</span>').appendTo(users_settings_response_message);
                    users_settings_response_message.addClass("fwss-success-message").show();
                    //setTimeout(function(){ location.reload(); }, 500);
                }
            });
        }
    });



    var save_wc_button = $('#fwss_save_wc_button');
    var wc_response_message = $('#fwss-wc-response-message');
    $('#fwss_wc_form').validate({
        rules: {
            // fwss_inputs: "required",
        },
        messages: {
            // fwss_uname: "نام کاربری اجباری می باشد",
        },
        submitHandler: function(form) {
            var sms_data = $( "#fwss_wc_form" ).serializeArray();
            var data = {
                'action': 'fwss_save_wc_sms_data',
                'sms_data': sms_data,
            };
            wc_response_message.empty().removeClass().hide();
            save_wc_button.addClass("fwss_button--loading");
            $.post(ajaxurl, data, function (response) {
                save_wc_button.removeClass("fwss_button--loading");
                if(!response.success){
                    $('<span>'+response.data+'</span>').appendTo(wc_response_message);
                    wc_response_message.addClass("fwss-error-message").show();
                }else {
                    $('<span>اطلاعات با موفقیت ذخیره شد</span>').appendTo(wc_response_message);
                    wc_response_message.addClass("fwss-success-message").show();
                    //setTimeout(function(){ location.reload(); }, 500);
                }
            });
        }
    });

    var save_users_button = $('#fwss_save_users_button');
    var users_response_message = $('#fwss-users-response-message');
    $('#fwss_users_form').validate({
        rules: {
            // fwss_inputs: "required",
        },
        messages: {
            // fwss_uname: "نام کاربری اجباری می باشد",
        },
        submitHandler: function(form) {
            var sms_data = $( "#fwss_users_form" ).serializeArray();
            var data = {
                'action': 'fwss_save_users_sms_data',
                'sms_data': sms_data,
            };
            users_response_message.empty().removeClass().hide();
            save_users_button.addClass("fwss_button--loading");
            $.post(ajaxurl, data, function (response) {
                save_users_button.removeClass("fwss_button--loading");
                if(!response.success){
                    $('<span>'+response.data+'</span>').appendTo(users_response_message);
                    users_response_message.addClass("fwss-error-message").show();
                }else {
                    $('<span>اطلاعات با موفقیت ذخیره شد</span>').appendTo(users_response_message);
                    users_response_message.addClass("fwss-success-message").show();
                    //setTimeout(function(){ location.reload(); }, 500);
                }
            });
        }
    });

    $('#fwss_users_form,#fwss_wc_form').on('click', '.fwss_active input:checkbox', function () {
        if($(this).is(":checked")){
            $(this).next('.fwss_active_hidden').val('on')
        }else {
            $(this).next('.fwss_active_hidden').val('off')
        }
    });

    var order_stauses = fwss_settings_info.order_statuses;
    var order_statuses_option = '';
    for (var key in order_stauses) {
        if (order_stauses.hasOwnProperty(key)) {
            order_statuses_option += '<option value="' + key + '">' + order_stauses[key] + '</option>';
        }
    }

    // v3.17.7: استفاده از event delegation به‌جای direct binding —
    // این روش هم هنگام رندر اولیه کار می‌کند و هم اگر DOM dynamically ساخته شود.
    // قبلاً .click() اگر دکمه‌ای در زمان load پیدا نمی‌شد، event bind نمی‌شد.
    $(document).on('click', '#fwss_add_sms_wc', function () {
        var next_index_wc = parseInt( $('#fwss_next_index_wc').val(), 10 );
        if ( isNaN( next_index_wc ) ) next_index_wc = 0;

        // ساخت option وضعیت سفارش — fresh هر بار، با fallback اگر localize fail کرد
        var order_stauses = (typeof fwss_settings_info !== 'undefined' && fwss_settings_info.order_statuses) ? fwss_settings_info.order_statuses : {};
        var order_statuses_option = '';
        for ( var key in order_stauses ) {
            if ( order_stauses.hasOwnProperty( key ) ) {
                // حذف وضعیت «در انتظار پرداخت» — مطابق رفتار قبلی
                if ( key === 'wc-pending' || key === 'pending' ) continue;
                order_statuses_option += '<option value="' + key + '">' + order_stauses[ key ] + '</option>';
            }
        }

        var del_btn = (typeof fwss_settings_info !== 'undefined' && fwss_settings_info.delete_button) ? fwss_settings_info.delete_button : '';

        // ساخت row با همان ساختار قبلی
        var row = '<div class="sms">';
        row += '<div class="delete"><div id="delete_row_' + next_index_wc + '"><img src="' + del_btn + '"></div></div>';
        row += '<div class="fwss_active"><label for="fwss_wc_sms_active_' + next_index_wc + '" class="toggle-control">' +
            '<input type="checkbox" id="fwss_wc_sms_active_' + next_index_wc + '" name="fwss_wc_sms_meta[' + next_index_wc + '][active]">' +
            '<input type="hidden" class="fwss_wc_active_hidden" name="fwss_wc_sms_meta[' + next_index_wc + '][active_or_not]" value="off">' +
            '<span class="control"></span></label></div>';
        row += '<div class="time"><label for="fwss_wc_sms_time_' + next_index_wc + '">زمان</label>' +
            '<input type="number" required min="0" class="fwss_time_input" name="fwss_wc_sms_meta[' + next_index_wc + '][time]" id="fwss_wc_sms_time_' + next_index_wc + '"><span> روز</span></div>';
        row += '<div class="order_status"><label for="fwss_wc_sms_order_status_' + next_index_wc + '">وضعیت سفارش</label>' +
            '<select name="fwss_wc_sms_meta[' + next_index_wc + '][order_status]" id="fwss_wc_sms_order_status_' + next_index_wc + '">' +
            order_statuses_option +
            '</select></div>';
        row += '<div class="hour"><label for="fwss_wc_sms_hour_' + next_index_wc + '">ساعت</label>' +
            '<input type="text" required placeholder="16:59" name="fwss_wc_sms_meta[' + next_index_wc + '][hour]" id="fwss_wc_sms_hour_' + next_index_wc + '"></div>';
        row += '<div class="sms_content"><label for="fwss_wc_sms_content_' + next_index_wc + '">متن پیام</label>' +
            '<textarea required rows="5" cols="20" name="fwss_wc_sms_meta[' + next_index_wc + '][content]" id="fwss_wc_sms_order_content_' + next_index_wc + '"></textarea></div>';
        row += '</div>'; // .sms

        // اضافه به فرم قبل از container دکمه ذخیره
        var $target = $('.fwss_save_button_container_wc').first();
        if ( $target.length === 0 ) {
            // fallback: append to form
            $('#fwss_wc_form').append( row );
        } else {
            $target.before( row );
        }

        // افزایش اندیس
        $('#fwss_next_index_wc').val( next_index_wc + 1 );
    });


    var sms_container = $('#sms_container');
    var sms_container_form_submit = $('#fwss_users_form .fwss_save_button_container');
    var add_sms = $('#fwss_add_sms');
    var next_index = $('#fwss_next_index').val();
    add_sms.click(function (){
        var row = '<div class="sms">';
        row += '<div class="delete"><div id="delete_row_' + next_index + '"><img src="' + fwss_settings_info.delete_button +'"></div></div>';
        row += '<div class="fwss_active"><label for="fwss_sms_active_' + next_index + '" class="toggle-control"><input type="checkbox" id="fwss_sms_active_' + next_index + '" name="fwss_sms_meta[' + next_index + '][active]"><input type="hidden" class="fwss_active_hidden"  name="fwss_sms_meta[' + next_index + '][active_or_not]" value="off"><span class="control"></span></label></div>';
        row += '<div class="time"><label for="fwss_sms_time_' + next_index + '">زمان</label><input type="number" required min="0" class="fwss_time_input" name="fwss_sms_meta[' + next_index + '][time]" id="fwss_sms_time_' + next_index + '"><span> روز</span></div>';
        row += '<div class="hour"><label for="fwss_sms_hour_' + next_index + '">ساعت</label><input type="text" required placeholder="16:59" name="fwss_sms_meta[' + next_index + '][hour]" id="fwss_sms_hour_' + next_index + '"></div>';
        row += '<div class="sms_content"><label for="fwss_sms_content_' + next_index + '">متن پیام</label><textarea required rows="5" cols="20" name="fwss_sms_meta[' + next_index + '][content]" id="fwss_sms_order_content_' + next_index + '"></textarea></div>';
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
    $('#fwss-gravity-forms').on('select2:select', function (e) {
        gf_id = e.params.data.id;
        var fields_select = $("#fwss-gravity-field");
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

    $('#fwss-gravity-field').on('select2:select', function (e) {
        var selected_field = e.params.data.id;
        $(".fwss_gf_field_registered_sms .fwss_show_hide_field").each(function() {
            if($( this ).attr('id') == 'frmid-fldid_' + selected_field){
                $( this ).show();
            }else {
                $( this ).hide();
            }
        });
    });
    $("#fwss-gravity-field").prop("disabled", true);

    var fwss_save_gf_sms_button = $('#fwss_save_gf_sms_button');
    var fwss_gf_sms_response_message = $('#fwss-gf-sms-response-message');
    $('#fwss_gf_sms_form').validate({
        rules: {
            // fwss_inputs: "required",
        },
        messages: {
            // fwss_uname: "نام کاربری اجباری می باشد",
        },
        submitHandler: function(form) {
            var sms_data = $( "#fwss_gf_sms_form" ).serializeArray();
            var data = {
                'action': 'fwss_save_gf_sms_data',
                'sms_data': sms_data,
            };
            fwss_gf_sms_response_message.empty().removeClass().hide();
            fwss_save_gf_sms_button.addClass("fwss_button--loading");
            $.post(ajaxurl, data, function (response) {
                fwss_save_gf_sms_button.removeClass("fwss_button--loading");
                if(!response.success){
                    $('<span>'+response.data+'</span>').appendTo(fwss_gf_sms_response_message);
                    fwss_gf_sms_response_message.addClass("fwss-error-message").show();
                }else {
                    $('<span>اطلاعات با موفقیت ذخیره شد</span>').appendTo(fwss_gf_sms_response_message);
                    fwss_gf_sms_response_message.addClass("fwss-success-message").show();
                }
            });
        }
    });

    $('#fwss_gf_sms_form').on('click', '.fwss_active input:checkbox', function (){
        if($(this).is(":checked")){
            $(this).next('.fwss_active_hidden').val('on')
        }else {
            $(this).next('.fwss_active_hidden').val('off')
        }
    })

    var add_gf_sms = $('.fwss_gf_add_sms');
    var next_gf_index = $('#fwss_gf_next_index').val();
    var sms_gf_container_form_submit = $('#fwss_gf_sms_form .fwss_save_button_container');
    add_gf_sms.click(function (){
        var gf_id = $(this).parent().prop('id').replace('frmid-fldid_','');
        var opt = '';
        $("#fwss-gravity-field option").each(function()
        {
            var res = $(this).val().split("-");
            var id = gf_id.split("-");
            if(res[0] == id[0]){
                opt += '<option value="'+res[1]+'">'+$(this).text()+'</option>'
            }
        });
        var row = '<div class="sms">';
        row += '<input type="hidden" name="fwss_gf_sms_meta[' + next_gf_index + '][gf_formatted_id]" value="' + gf_id + '">';
        row += '<div class="delete"><div id="delete_gf_row_' + next_gf_index + '"><img src="' + fwss_settings_info.delete_button +'"></div></div>';
        row += '<div class="fwss_active"><label for="fwss_gf_sms_active_' + next_gf_index + '" class="toggle-control"><input type="checkbox" id="fwss_gf_sms_active_' + next_gf_index + '" name="fwss_gf_sms_meta[' + next_gf_index + '][active]"><input type="hidden" class="fwss_active_hidden"  name="fwss_gf_sms_meta[' + next_gf_index + '][active_or_not]" value="off"><span class="control"></span></label></div>';
        row += '<div class="time"><label for="fwss_gf_sms_time_' + next_gf_index + '">زمان</label><input type="number" required min="0" class="fwss_time_input" name="fwss_gf_sms_meta[' + next_gf_index + '][time]" id="fwss_gf_sms_time_' + next_gf_index + '"><span> روز</span></div>';
        row += '<div class="hour"><label for="fwss_gf_sms_hour_' + next_gf_index + '">ساعت</label><input type="text" minlength="5" maxlength="5" required placeholder="16:59" name="fwss_gf_sms_meta[' + next_gf_index + '][hour]" id="fwss_gf_sms_hour_' + next_gf_index + '"></div>';
        row += '<div class="condition"><label for="fwss_gf_condition_active_' + next_gf_index + '" class="toggle-control">\n' +
            '                                                                    منطق شرطی\n' +
            '                                                                    <input class="fwss_inputs fwss_condition_toggle" type="checkbox" id="fwss_gf_condition_active_' + next_gf_index + '" name="fwss_gf_sms_meta[' + next_gf_index + '][condition_active]">\n' +
            '                                                                    <span class="control"></span>\n' +
            '                                                                </label></div>';
        row += '<div class="sms_content"><label for="fwss_gf_sms_content_' + next_gf_index + '">متن پیام</label><textarea required rows="5" cols="20" name="fwss_gf_sms_meta[' + next_gf_index + '][content]" id="fwss_gf_sms_order_content_' + next_gf_index + '"></textarea></div>';
        row += '</div><div style="display: none" class="fwss_condition_container" id="fwss_condition_container_' + next_gf_index + '" style="">\n' +
            '                                                            <div class="fwss_if_all_condition">\n' +
            '                                                                <span>ارسال پیامک اگر</span>\n' +
            '                                                                <select name="fwss_gf_sms_meta[' + next_gf_index + '][all_or_one]">\n' +
            '                                                                    <option value="all" selected="">همه</option>\n' +
            '                                                                    <option value="any">حداقل یکی</option>\n' +
            '                                                                </select>\n' +
            '                                                                <span>از شرط های زیر برقرار بود:</span>\n' +
            '                                                                <div class="plus-button plus-button--small"></div>\n' +
            '                                                            </div>\n' +
            '                                                                 <span>اگر </span><div class="fwss_conditions"><select class="fwss_gf_conditional_field" name="fwss_gf_condition_field_[' + next_gf_index + '][0][field]" id="fwss_gf_condition_field_' + next_gf_index + '_0">\n' +
            '\t                                                               '+opt+' </select>\n' +
            '                                                                \n' +
            '                                                                <select class="fwss_gf_conditional_operator" id="fwss_gf_condition_operator_' + next_gf_index + '_0" name="fwss_gf_condition_operator_[' + next_gf_index + '][0][operator]">\n' +
            '                                                                    <option value="is" selected="">هست</option>\n' +
            '                                                                    <option value="isnot">نیست</option>\n' +
            '                                                                    <option value=">">بزرگتر از</option>\n' +
            '                                                                    <option value="<">کوچکتر از</option>\n' +
            '                                                                    <option value="contains">شامل میشود</option>\n' +
            '                                                                    <option value="starts_with">شروع میشود</option>\n' +
            '                                                                    <option value="ends_with">تمام میشود</option>\n' +
            '                                                                </select>\n' +
            '                                                                <div id="fwss_gf_condition_value_' + next_gf_index + '_0" style="display:inline;"><input type="text" class="condition_field_value" style="padding:3px" placeholder="یک مقدار وارد کنید" id="fwss_gf_condition_value_10_10" name="fwss_gf_condition_value_[' + next_gf_index + '][0][value]" value=""></div>\n' +
            '                                                                <div class="minus-button plus-button--small"></div>\n' +
            '                                                            </div>\n' +
            '                                                                                                                    </div>';
        $(this).parent().append(row);
        next_gf_index++;
    })


    var condition_toggle = $('#fwss_gf_sms_form');
    condition_toggle.on('click', '.fwss_condition_toggle',function() {
        var id = $(this).attr('id').replace('fwss_gf_condition_active_','');
        var condition_container = $("#fwss_condition_container_" + id);
        if( $(this).is(':checked')) {
            condition_container.show();
        } else {
            condition_container.hide();
        }
    });
    condition_toggle.find('.fwss_condition_toggle').each(function (){
        if($(this).is(":checked")){
            var id = $(this).attr('id').replace('fwss_gf_condition_active_','');
            var condition_container = $("#fwss_condition_container_" + id);
            condition_container.show();
        }
    })

    var ind = 1;
    $("#fwss_gf_sms_form").on('click', '.plus-button',function (){
        var id = $(this).parents(".fwss_condition_container").attr('id').replace('fwss_condition_container_','');
        ind = $(this).parents('.fwss_condition_container').children(".fwss_conditions").length;
        var opt2 = '';
        $(this).parents(".fwss_condition_container").find(".fwss_conditions .fwss_gf_conditional_field option").each(function (){
            opt2 += '<option value="'+$(this).val()+'">'+$(this).text()+'</option>'
        })
        var con = '<div class="fwss_conditions">\n' +
            '                                                                <span>اگر </span><select class="fwss_gf_conditional_field" name="fwss_gf_condition_field_['+id+']['+ind+'][field]" id="fwss_gf_condition_field_'+id+'_'+ind+'">'+opt2+'</select>\n' +
            '                                                                <select class="fwss_gf_conditional_operator valid" id="fwss_gf_condition_operator_'+id+'_'+ind+'" name="fwss_gf_condition_operator_['+id+']['+ind+'][operator]" aria-invalid="false">\n' +
            '                                                                    <option value="is">هست</option>\n' +
            '                                                                    <option value="isnot">نیست</option>\n' +
            '                                                                    <option value=">">بزرگتر از</option>\n' +
            '                                                                    <option value="<">کوچکتر از</option>\n' +
            '                                                                    <option value="contains">شامل میشود</option>\n' +
            '                                                                    <option value="starts_with">شروع میشود</option>\n' +
            '                                                                    <option value="ends_with">تمام میشود</option>\n' +
            '                                                                </select>\n' +
            '                                                                <div id="fwss_gf_condition_value_'+id+'_'+ind+'" style="display:inline;"><input type="text" class="condition_field_value" style="padding:3px" placeholder="یک مقدار وارد کنید" id="fwss_gf_condition_value_'+id+'_'+ind+'" name="fwss_gf_condition_value_['+id+']['+ind+'][value]" value=""></div>\n' +
            '                                                                <div class="minus-button plus-button--small"></div>\n' +
            '                                                            </div>';
        $(this).parents('.fwss_condition_container').append(con)
        ind++;
    })

    $("#fwss_gf_sms_form").on("click", '.minus-button', function (){
        $(this).parent().remove()
    })

});