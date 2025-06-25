(function($) {
    'use strict';
    $(function() {
        var $checkbox = $('#vms_dev_mode');
        if (!$checkbox.length) {
            console.warn('Dev Mode checkbox not found');
            return;
        }
        $checkbox.on('change', function() {
            var value = $(this).is(':checked') ? 1 : 0;
            console.log('Dev Mode changed, saving value:', value);
            $.post(vmsSettings.ajaxUrl, {
                action: 'vms_toggle_dev_mode',
                dev_mode: value,
                security: vmsSettings.nonce
            })
            .done(function(response) {
                if (response.success) {
                    console.log('Dev Mode saved:', response.data.dev_mode);
                } else {
                    console.error('Error saving Dev Mode:', response.data);
                }
            })
            .fail(function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX error saving Dev Mode:', textStatus, errorThrown);
            });
        });
    });
})(jQuery); 