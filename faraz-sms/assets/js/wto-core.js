jQuery(document).ready(function($){
    "use strict";
    var order_stauses = wto_data.order_statuses;
    var order_statuses_option = '';
    for (var key in order_stauses) {
        if (order_stauses.hasOwnProperty(key)) {
            order_statuses_option += '<option value="' + key + '">' + order_stauses[key] + '</option>';
        }
    }

    var sms_container = $('#sms_container');
    var add_sms = $('#wto_add_sms');
    var next_index = $('#wto_next_index').val();
    add_sms.click(function (){
        var row = '<div class="sms">';
        row += '<div class="delete"><div id="delete_row_' + next_index + '"><img src="' + wto_data.delete_button +'"></div></div>';
        row += '<div class="active"><label for="wto_sms_active_' + next_index + '" class="toggle-control"><input type="checkbox" id="wto_sms_active_' + next_index + '" name="wto_sms_meta[' + next_index + '][active]"><span class="control"></span></label></div>';
        row += '<div class="time"><label for="wto_sms_time_' + next_index + '">زمان</label><input type="number" min="0" class="wto_time_input" name="wto_sms_meta[' + next_index + '][time]" id="wto_sms_time_' + next_index + '"><span> روز بعد از</span></div>';
        row += '<div class="order_status">\n' +
            '                   <label for="wto_sms_order_status_' + next_index + '">وضعیت سفارش</label>\n' +
            '                   <select name="wto_sms_meta[' + next_index + '][order_status]" id="wto_sms_order_status_' + next_index + '">' + order_statuses_option + '</select></div>';
        row += '<div class="hour"><label for="wto_sms_hour_' + next_index + '">ساعت</label><input type="text" placeholder="16:59" name="wto_sms_meta[' + next_index + '][hour]" id="wto_sms_hour_' + next_index + '"></div>';
        row += '<div class="sms_content"><label for="wto_sms_content_' + next_index + '">متن پیام</label><textarea rows="5" cols="20" name="wto_sms_meta[' + next_index + '][content]" id="wto_sms_order_content_' + next_index + '"></textarea></div>';
        row += '</div>';
        sms_container.append(row);
        next_index++;
    })

    var delete_button = $('#sms_container .sms .delete');
    $('#sms_container').on('click', '.sms .delete > div', function () {
        if (confirm('آیا مطمئن هستید؟')) {
            $(this).parents(".sms").remove();
        } else {return;}
    });
});