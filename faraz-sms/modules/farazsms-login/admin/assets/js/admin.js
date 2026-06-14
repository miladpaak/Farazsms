/*Select2 Sortable | 1.0.0 | Author: Vijay Hardaha | License : MIT*/
!function(a){a.fn.extend({select2Sortable:function(){var b=Array.prototype.slice.call(arguments,0),c=this.filter("[multiple]");if(0==c.length)this.select2(b[0]);else if(0===b.length||"object"==typeof b[0]){var d={sorter:a=>a.sort(function(c,a){return c.text.localeCompare(a.text)}),createTag:function(){}},e=a.extend([],d,b[0]);"object"!=typeof c.data("select2")&&c.select2(e),c.each(function(){var b=a(this),d=b.siblings(".select2-container").first("ul.select2-selection__rendered");c.select2SetOrderOnInit(b),d.sortable({placeholder:"ui-state-highlight",forcePlaceholderSize:!0,items:"li:not(.select2-search__field)",tolerance:"pointer"}),d.on("sortstop.select2sortable",function(){a(d.find(".select2-selection__choice").get().reverse()).each(function(){var c=a(this).attr("title"),d=b.find("option:contains("+c+")");b.prepend(d)})})})}else if(typeof("string"===b[0])){if(-1==a.inArray(b[0],["destroy"]))throw"Unknown method: "+b[0];"destroy"===b[0]&&c.select2SortableDestroy()}},select2SortableDestroy:function(){var b=this.filter("[multiple]");return b.each(function(){var b=a(this).siblings(".select2-container").first("ul.select2-selection__rendered");b.unbind("sortstop.select2sortable"),b.sortable("destroy")}),b},select2SetOrderOnInit:function(b){var c=b.attr("data-initials"),d=[];if("undefined"!=typeof c){var e=c.split(",");e.length&&(e=e.map(function(a){return a.trim()}),a.each(e,function(a,c){var e=b.find("option[value=\""+c+"\"]");d.push(e),e.remove()}))}d.length&&b.prepend(d)}})}(jQuery);

jQuery(document).ready(function($) {

    $('.farazsms-field-color .color-picker').wpColorPicker();
    
    $('.farazsms-select').select2();

    $('.select-multiple').select2({
        placeholder: farazsms_admin.i18n.select_placeholder,
        multiple: true,
        allowClear: true
    });

    function farazsms_file_uploader($class) {
        var uploader,
        metaBox = $($class),
        addImgLink = metaBox.find('.farazsms-field-upload-file'),
        delImgLink = metaBox.find( '.farazsms-field-delete-file'),
        imgContainer = metaBox.find( '.farazsms-field-file-container'),
        imgURLInput = metaBox.find( '.farazsms-field-file-url' );
        addImgLink.off('click').on( 'click', function( event ){
            event.preventDefault();
            if ( uploader ) {uploader.open();return;}
            uploader = wp.media({
                multiple: false,
            });
            uploader.on( 'select', function() {
                var attachment = uploader.state().get('selection').first().toJSON();
                imgContainer.empty().append( '<img src="'+attachment.url+'" alt="" style="max-width:100%;"/>' );
                imgURLInput.val( attachment.url );
                delImgLink.removeClass( 'hidden' );
            });
            uploader.open();
        });
        delImgLink.off('click').on( 'click', function( event ){
            event.preventDefault();
            imgContainer.html( '' );
            delImgLink.addClass( 'hidden' );
            imgURLInput.val( '' );
        });
    }

    $('.farazsms-field-file-uploader').each(function () {
        var id = $(this).attr("id");
        farazsms_file_uploader('#' + id);
    });

    $(".add-repeater-row").click(function(e) {
        e.preventDefault();
        var container = $(this).closest(".main-repeater");
        var originalTable = container.find(".repeater-table:first");
        var tableCount = container.find(".repeater-table").length;
        var newTable = originalTable.clone();

        newTable.find(".repeater-table-entry").each(function(index) {
            var inputs = $(this).find("input, textarea");
            inputs.each(function() {
                var name = $(this).attr("name");
                if (name) {
                    var newName = name.replace(/\[(\d+)\]/g, "[" + tableCount + "]");
                    $(this).attr("name", newName);
                }
                $(this).val("");
            });

            var idPrefix = $(this).closest('.repeater-table').attr('id');
            var newIdPrefix = idPrefix.replace(/\[(\d+)\]/g, "[" + tableCount + "]");
            $(this).closest('.repeater-table').attr('id', newIdPrefix);

            $(this).find('[id]').each(function() {
                var oldId = $(this).attr('id');
                var newId = oldId.replace(/\[(\d+)\]/g, "[" + tableCount + "]");
                $(this).attr('id', newId);
            });

            $(this).find('[for]').each(function() {
                var oldFor = $(this).attr('for');
                var newFor = oldFor.replace(/\[(\d+)\]/g, "[" + tableCount + "]");
                $(this).attr('for', newFor);
            });

            farazsms_file_uploader($(this));
        });

        newTable.insertBefore($(this));
    });


    $(".delete-repeater-row").click(function(e) {
        e.preventDefault();
    
        var deletedTable = $(this).closest(".repeater-table");
        deletedTable.remove();
    
        $(".main-repeater .repeater-table").each(function(index) {
            var newIndex = index;
            $(this).attr("id", $(this).attr("id").replace(/\[(\d+)\]/, "[" + newIndex + "]"));
            var inputsAndTextareas = $(this).find("input,textarea");
    
            inputsAndTextareas.each(function() {
                var originalName = $(this).attr("name");
                var originalFor = $(this).siblings("label").attr("for");
    
                var newName = originalName.replace(/\[(\d+)\]/, "[" + newIndex + "]");
                var newFor = originalFor.replace(/\[(\d+)\]/, "[" + newIndex + "]");
    
                $(this).attr("name", newName);
                $(this).siblings("label").attr("for", newFor);
            });
        });
    });

    function farazsms_multiple_image_uploader($class) {
        var uploader,
            metaBox = $($class),
            addImgLink = metaBox.find('.farazsms-field-upload-file'),
            imgContainer = metaBox.find('.farazsms-field-file-container'),
            imgIDsInput = metaBox.find('.farazsms-field-img-ids');
    
        addImgLink.on('click', function (event) {
            event.preventDefault();
            if (uploader) { uploader.open(); return; }
    
            uploader = wp.media({
                library: { type: 'image' },
                multiple: true,
            });
    
            uploader.on('select', function () {
                var attachments = uploader.state().get('selection').toJSON();
                imgContainer.empty();
                var ids = [];
    
                attachments.forEach(function (attachment) {
                    imgContainer.append('<div class="farazsms-field-img-item" data-id="' + attachment.id + '"><img src="' + attachment.url + '" alt=""><button class="remove-image-button" data-id="' + attachment.id + '">×</button></div>');
                    ids.push(attachment.id);
                });
    
                imgIDsInput.val(ids.join(','));
            });
    
            uploader.open();
        });
    
        imgContainer.on('click', '.remove-image-button', function () {
            var idToRemove = $(this).data('id');
            var currentIds = imgIDsInput.val().split(',');
            var updatedIds = currentIds.filter(function (id) {
                return id !== idToRemove.toString();
            });
    
            imgIDsInput.val(updatedIds.join(','));
            $(this).parent().remove();
        });
    }
    
    $('.farazsms-field-multiple-image-uploader').each(function () {
        var id = $(this).attr("id");
        farazsms_multiple_image_uploader('#' + id);
    });

    $('.farazsms-accordion-wrap').on('click', '.add_field', function() {
        var row = $(this).closest('.farazsms-accordion-wrap').find('p:first-child').clone();
        row.find('input[type="text"], textarea').val('');
        $(this).closest('.farazsms-accordion-wrap').append(row);
        return false;
    });

    $('.farazsms-accordion-wrap').on('click', '.remove_field', function() {
        $(this).parent().remove();
        return false;
    });

    function updateIndexes(container) {
        container.find(".repeater-table").each(function(index) {
            var newIndex = index;
            var $table = $(this);

            var inputs = $(this).find("input, textarea, select");
            inputs.each(function() {
                var name = $(this).attr("name");
                if (name) {
                    var newName = name.replace(/\[(\d+)\]/g, "[" + newIndex + "]");
                    $(this).attr("name", newName);
                }
            });

            var idPrefix = $(this).closest('.repeater-table').attr('id');
            var newIdPrefix = idPrefix.replace(/\[(\d+)\]/g, "[" + newIndex + "]");
            $(this).closest('.repeater-table').attr('id', newIdPrefix);

            $(this).find('[id]').each(function() {
                var oldId = $(this).attr('id');
                var newId = oldId.replace(/\[(\d+)\]/g, "[" + newIndex + "]");
                $(this).attr('id', newId);
            });

            $(this).find('[for]').each(function() {
                var oldFor = $(this).attr('for');
                var newFor = oldFor.replace(/\[(\d+)\]/g, "[" + newIndex + "]");
                $(this).attr('for', newFor);
            });
        });
    }

    $(".main-repeater").each(function() {
        var $container = $(this);
        $container.sortable({
            items: '.repeater-table',
            cursor: 'move',
            opacity: 0.7,
            update: function(event, ui) {
                updateIndexes($container);
            }
        });
    });


    $('.single-image-uploader .upload-single-image-button').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var mediaUploader = wp.media({
            title: farazsms_admin.i18n.select_image,
            button: {
                text: farazsms_admin.i18n.select_button
            },
            multiple: false
        });

        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            button.siblings('.upload-single-image-url').val(attachment.url);
            button.siblings('.image-uploader-preview').attr('src', attachment.url).css('display', 'block');
        });

        mediaUploader.open();
    });

    $('.single-image-uploader .remove-single-image-button').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        button.siblings('.upload-single-image-url').val('');
        button.siblings('.image-uploader-preview').css('display', 'none');
    });

    $(document).on('click', '.upload-image-button', function(e) {
        e.preventDefault();
        uploadImage($(this));
    });

    $(document).on('click', '.remove-image-button', function(e) {
        e.preventDefault();
        $(this).closest('.banner-row').remove();
    });

    function uploadImage(button) {
        var mediaUploader = wp.media({
            title: farazsms_admin.i18n.select_image,
            button: {
                text: farazsms_admin.i18n.select_button
            },
            multiple: false
        });

        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            button.siblings('input.image').val(attachment.url);
            button.siblings('.image-uploader-preview').attr('src', attachment.url).show();
        });

        mediaUploader.open();
    }
});

// SMS Test functionality
jQuery(document).ready(function($) {
    $('.farazsms-sms-test-send').on('click', function(e) {
        e.preventDefault();

        var button = $(this);
        var phoneFieldId = button.data('phone-field');
        var phoneNumber = $('#' + phoneFieldId).val();
        var resultContainer = button.siblings('.farazsms-sms-test-result');

        if (!phoneNumber) {
            resultContainer.html('<span class="error">' + farazsms_admin.i18n.enter_phone + '</span>');
            return;
        }

        // Show loading
        button.prop('disabled', true).text(farazsms_admin.i18n.sending || 'Sending...');
        resultContainer.html('<span class="loading">' + (farazsms_admin.i18n.sending || 'Sending...') + '</span>');

        // AJAX request
        $.ajax({
            url: farazsms_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'farazsms_send_test_sms',
                phone: phoneNumber,
                nonce: farazsms_admin.nonce
            },
            success: function(response) {
                var msg = response.data && response.data.message;
                var msgStr = (typeof msg === 'string') ? msg : (farazsms_admin.i18n.error || 'Error sending SMS');
                if (response.success) {
                    resultContainer.html('<span class="success">' + (msgStr || farazsms_admin.i18n.success) + '</span>');
                } else {
                    resultContainer.html('<span class="error">' + msgStr + '</span>');
                }
            },
            error: function() {
                resultContainer.html('<span class="error">' + (farazsms_admin.i18n.error || 'Error occurred') + '</span>');
            },
            complete: function() {
                button.prop('disabled', false).text(farazsms_admin.i18n.send_test_sms || 'Send Test SMS');
            }
        });
    });

    // Image Radio functionality
    $('.image-radio-label').on('click', function() {
        var container = $(this).closest('.image-radio-container');
        container.find('.image-radio-item').removeClass('active');
        $(this).parent('.image-radio-item').addClass('active');
    });

    // Tabs functionality
    $('.farazsms-tab-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var tabKey = $btn.data('tab');
        var $wrapper = $btn.closest('.farazsms-tabs-wrapper');
        
        $wrapper.find('.farazsms-tab-btn').removeClass('active');
        $btn.addClass('active');
        
        $wrapper.find('.farazsms-tab-pane').removeClass('active');
        $wrapper.find('.farazsms-tab-pane[data-tab="' + tabKey + '"]').addClass('active');
    });

    // Copy shortcode on click
    $('.html-setting-shortcodes code').on('click', function() {
        var codeText = $(this).text();
        var $temp = $('<textarea>');
        $('body').append($temp);
        $temp.val(codeText).select();
        document.execCommand('copy');
        $temp.remove();
        
        var $code = $(this);
        var originalBg = $code.css('background');
        $code.css('background', '#00a32a');
        setTimeout(function() {
            $code.css('background', originalBg);
        }, 200);

        var $existingToast = $('.farazsms-toast');
        if ($existingToast.length) {
            $existingToast.remove();
        }

        var $toast = $('<div class="farazsms-toast show">' +
            '<span class="toast-icon">✓</span>' +
            '<span class="toast-message">' + (farazsms_admin.i18n && farazsms_admin.i18n.copied ? farazsms_admin.i18n.copied : 'کپی شد: ') + '<strong>' + codeText + '</strong></span>' +
            '</div>');
        
        $('body').append($toast);

        setTimeout(function() {
            $toast.removeClass('show');
            setTimeout(function() {
                $toast.remove();
            }, 300);
        }, 2500);
    });
});