(function($) {
    $(document).ready(function() {
        $(document).on('click', '.vms-toggle', function(e) {
            e.preventDefault();
            var $el = $(this);
            var postId = $el.data('post-id');
            var nonce = $el.data('nonce');
            $.post(vmsToggle.ajaxUrl, {
                action: 'vms_toggle_vms',
                post_id: postId,
                nonce: nonce
            }, function(response) {
                if (response.success) {
                    var star = response.data.post_isvms == 1 ? '\u2605' : '\u2606';
                    $el.html(star);
                } else {
                    var msg = response.data && response.data.message ? response.data.message : 'Error toggling VMS';
                    alert(msg);
                }
            });
        });
    });
})(jQuery); 