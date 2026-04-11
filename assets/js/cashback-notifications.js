(function ($) {
    'use strict';

    $(document).on('submit', '#cashback-notification-prefs-form', function (e) {
        e.preventDefault();

        var $btn = $('#cashback-save-notification-prefs');
        var $status = $('#cashback-notification-status');

        $btn.prop('disabled', true);
        $status.text('Сохранение...').removeClass('error');

        $.ajax({
            url: cashbackNotifications.ajaxUrl,
            type: 'POST',
            data: $(this).serialize() + '&action=cashback_save_notification_prefs&nonce=' + cashbackNotifications.nonce,
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
                $btn.prop('disabled', false);
                setTimeout(function () {
                    $status.text('');
                }, 3000);
            }
        });
    });
})(jQuery);
