/**
 * FarazSMS Next Admin Scripts
 */

jQuery(document).ready(function($) {
    "use strict";

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
        
        // Update URL without page reload
        var tabId = $(this).data('tab');
        if (tabId) {
            var url = new URL(window.location.href);
            url.searchParams.set('tab', tabId);
            window.history.pushState({}, '', url);
        }
    });
    
    // Handle create/update phonebook button click
    function handlePhonebookAction($button, $responseDiv) {
        // Disable button and show loading
        $button.prop('disabled', true).addClass('fwss_button--loading');
        $responseDiv.empty().hide();
        
        // Send AJAX request
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'farazsms_next_create_phonebook_from_woocommerce',
                nonce: farazsmsNextPhonebook.nonce
            },
            success: function(response) {
                $button.prop('disabled', false).removeClass('fwss_button--loading');
                
                if (response.success) {
                    // Show success message briefly, then reload page to show updated phonebook
                    $responseDiv.html('<p>' + response.data.message + '</p>')
                        .addClass('fwss-success-message')
                        .removeClass('fwss-error-message')
                        .show();
                    
                    // Reload page after 1.5 seconds to show the updated phonebook
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    $responseDiv.html('<p>' + response.data.message + '</p>')
                        .addClass('fwss-error-message')
                        .removeClass('fwss-success-message')
                        .show();
                }
            },
            error: function() {
                $button.prop('disabled', false).removeClass('fwss_button--loading');
                $responseDiv.html('<p>خطا در ارتباط با سرور. لطفا دوباره تلاش کنید.</p>')
                    .addClass('fwss-error-message')
                    .removeClass('fwss-success-message')
                    .show();
            }
        });
    }

    // Handle create phonebook button click
    $('#farazsms-create-phonebook-btn').on('click', function() {
        var $button = $(this);
        var $responseDiv = $('#farazsms-phonebook-response');
        handlePhonebookAction($button, $responseDiv);
    });

    // Handle update phonebook button click
    $('#farazsms-update-phonebook-btn').on('click', function() {
        var $button = $(this);
        var $responseDiv = $('#farazsms-phonebook-response');
        handlePhonebookAction($button, $responseDiv);
    });

    $('#farazsms-sync-custom-phonebook-btn').on('click', function() {
        var $btn = $(this);
        var $out = $('#farazsms-custom-phonebook-response');
        $btn.prop('disabled', true).addClass('fwss_button--loading');
        $out.hide().empty();
        $.post(ajaxurl, {
            action: 'farazsms_next_sync_custom_meta_phonebook',
            nonce: farazsmsNextPhonebook.nonce,
            custom_phonebook_title: $('#farazsms_custom_phonebook_title').val(),
            custom_meta_key: $('#farazsms_custom_meta_key').val(),
            custom_meta_source: $('#farazsms_custom_meta_source').val()
        }, function(response) {
            $btn.prop('disabled', false).removeClass('fwss_button--loading');
            if (response.success) {
                $out.html('<p>' + response.data.message + '</p>').removeClass('fwss-error-message').addClass('fwss-success-message').show();
            } else {
                $out.html('<p>' + (response.data && response.data.message ? response.data.message : 'خطا') + '</p>').removeClass('fwss-success-message').addClass('fwss-error-message').show();
            }
        }).fail(function() {
            $btn.prop('disabled', false).removeClass('fwss_button--loading');
            $out.html('<p>خطا در ارتباط با سرور</p>').addClass('fwss-error-message').show();
        });
    });

    $('#farazsms-load-marketing-data-btn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.post(ajaxurl, {
            action: 'farazsms_next_phonebook_marketing_data',
            nonce: farazsmsNextPhonebook.nonce
        }, function(response) {
            $btn.prop('disabled', false);
            var $pb = $('#farazsms_bulk_phonebook').empty();
            var $ln = $('#farazsms_bulk_line').empty();
            $pb.append($('<option>').val('').text('— انتخاب کنید —'));
            $ln.append($('<option>').val('').text('— انتخاب کنید —'));
            if (!response.success) {
                alert(response.data && response.data.message ? response.data.message : 'خطا');
                return;
            }
            (response.data.phonebooks || []).forEach(function(p) {
                $pb.append($('<option>').val(p.id).text(p.title + ' (#' + p.id + ')'));
            });
            (response.data.lines || []).forEach(function(l) {
                $ln.append($('<option>').val(l.line_number).text(l.title + ' — ' + l.line_number));
            });
        }).fail(function() {
            $btn.prop('disabled', false);
            alert('خطا در ارتباط با سرور');
        });
    });

    $('#farazsms-send-bulk-phonebook-btn').on('click', function() {
        var $btn = $(this);
        var $out = $('#farazsms-bulk-response');
        var line = ($('#farazsms_bulk_line').val() || '').trim() || ($('#farazsms_bulk_line_custom').val() || '').trim();
        var pid = $('#farazsms_bulk_phonebook').val();
        var msg = $('#farazsms_bulk_message').val();
        if (!pid || !line || !msg.trim()) {
            alert('دفترچه، خط و متن را کامل کنید.');
            return;
        }
        if (!confirm('ارسال پیامک به همه مخاطبین این دفترچه؟')) {
            return;
        }
        $btn.prop('disabled', true).addClass('fwss_button--loading');
        $out.hide().empty();
        $.post(ajaxurl, {
            action: 'farazsms_next_send_bulk_phonebook_sms',
            nonce: farazsmsNextPhonebook.nonce,
            phonebook_id: pid,
            line_number: line,
            message: msg
        }, function(response) {
            $btn.prop('disabled', false).removeClass('fwss_button--loading');
            if (response.success) {
                $out.html('<p>' + response.data.message + '</p>').removeClass('fwss-error-message').addClass('fwss-success-message').show();
            } else {
                $out.html('<p>' + (response.data && response.data.message ? response.data.message : 'خطا') + '</p>').removeClass('fwss-success-message').addClass('fwss-error-message').show();
            }
        }).fail(function() {
            $btn.prop('disabled', false).removeClass('fwss_button--loading');
            $out.html('<p>خطا در ارتباط با سرور</p>').addClass('fwss-error-message').show();
        });
    });
});
