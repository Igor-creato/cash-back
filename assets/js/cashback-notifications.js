(function ($) {
    'use strict';

    function makeRequestId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            var v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    var isSubmitting = false;

    $(document).on('submit', '#cashback-notification-prefs-form', function (e) {
        e.preventDefault();

        if (isSubmitting) {
            return;
        }

        var $btn = $('#cashback-save-notification-prefs');
        var $status = $('#cashback-notification-status');

        isSubmitting = true;
        $btn.prop('disabled', true);
        $status.text('Сохранение...').removeClass('error');

        var payload = $(this).serialize()
            + '&action=cashback_save_notification_prefs'
            + '&nonce=' + encodeURIComponent(cashbackNotifications.nonce)
            + '&request_id=' + encodeURIComponent(makeRequestId());

        $.ajax({
            url: cashbackNotifications.ajaxUrl,
            type: 'POST',
            data: payload,
            success: function (response) {
                if (response.success) {
                    $status.text(response.data.message);
                } else {
                    $status.text(response.data.message || 'Ошибка').addClass('error');
                }
            },
            error: function () {
                $status.text('Ошибка сети').addClass('error');
            },
            complete: function () {
                isSubmitting = false;
                $btn.prop('disabled', false);
                setTimeout(function () {
                    $status.text('');
                }, 3000);
            }
        });
    });
})(jQuery);
