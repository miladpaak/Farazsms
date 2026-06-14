jQuery(document).ready(function($) {
    // Handle wallet transaction form
    $('#wallet-transaction-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $submitBtn = $form.find('input[type="submit"]');
        var originalText = $submitBtn.val();

        // Show loading
        $submitBtn.val(farazsms_wallet_admin.strings.processing).prop('disabled', true);

        $.ajax({
            url: farazsms_wallet_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'farazsms_wallet_transaction',
                wallet_nonce: farazsms_wallet_admin.nonce,
                user_id: $form.find('input[name="user_id"]').val(),
                transaction_type: $form.find('select[name="transaction_type"]').val(),
                amount: $form.find('input[name="amount"]').val(),
                description: $form.find('textarea[name="description"]').val()
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    alert(farazsms_wallet_admin.strings.success);

                    // Update balance display
                    $('.farazsms-wallet-user p strong').text(response.data.balance);

                    // Clear form
                    $form.find('input[name="amount"]').val('');
                    $form.find('textarea[name="description"]').val('');

                    // Reload page to show updated transactions
                    location.reload();
                } else {
                    alert(response.data.message || farazsms_wallet_admin.strings.error);
                }
            },
            error: function() {
                alert(farazsms_wallet_admin.strings.error);
            },
            complete: function() {
                $submitBtn.val(originalText).prop('disabled', false);
            }
        });
    });
});
