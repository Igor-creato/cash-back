(function ($) {
    'use strict';

    // Toggle label update
    $(document).on('change', '.cashback-admin-toggle input', function () {
        var $label = $(this).closest('.cashback-admin-toggle').find('.cashback-admin-toggle-label');
        $label.text(this.checked ? 'Включено' : 'Выключено');
    });

    // Save settings
    $(document).on('submit', '#cashback-admin-notification-form', function (e) {
        e.preventDefault();

        var $btn = $('#cashback-save-notification-admin-settings');
        var $status = $('#cashback-admin-notification-status');

        $btn.prop('disabled', true);
        $status.text('Сохранение...').removeClass('error');

        $.ajax({
            url: cashbackAdminNotifications.ajaxUrl,
            type: 'POST',
            data: $(this).serialize() + '&action=cashback_admin_save_notification_settings&nonce=' + cashbackAdminNotifications.nonce,
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
