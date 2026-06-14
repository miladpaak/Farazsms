jQuery(document).ready(function($) {
    // Handle wallet checkbox changes
    $(document).on('change', 'input[name="use_wallet_full"], input[name="use_wallet_partial"]', function() {
        var $this = $(this);
        var isChecked = $this.is(':checked');

        // Uncheck the other option
        if ($this.attr('name') === 'use_wallet_full') {
            $('input[name="use_wallet_partial"]').prop('checked', false);
        } else {
            $('input[name="use_wallet_full"]').prop('checked', false);
        }

        // Update session via AJAX
        updateWalletSession($this.attr('name'), isChecked);
    });

    function updateWalletSession(fieldName, isChecked) {

        var data = {
            'action': 'farazsms_update_wallet_session',
            'nonce': farazsms_wallet.nonce
        };

        if (fieldName === 'use_wallet_full' && isChecked) {
            data.use_wallet_full = '1';
        } else if (fieldName === 'use_wallet_partial' && isChecked) {
            data.use_wallet_partial = '1';
        }

        $.ajax({
            url: farazsms_wallet.ajax_url,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    // Trigger checkout update
                    $(document.body).trigger('update_checkout');
                } else {
                    alert('Error: ' + response.data.message);
                    // Uncheck the checkbox on error
                    $this.prop('checked', false);
                }
            },
            error: function(xhr, status, error) {
                alert('AJAX Error: ' + error);
            }
        });
    }
});
