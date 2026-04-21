/**
 * Admin UI для вкладки «Разрешенные домены API Base URL»
 * (admin.php?page=cashback-partners&tab=outbound-allowlist).
 *
 * AJAX-хендлеры cashback_outbound_allowlist_add / cashback_outbound_allowlist_remove.
 * При успешной операции — полная перезагрузка страницы (не приоритет
 * оптимизировать — операции редкие).
 */
(function ($) {
    'use strict';

    var cfg = window.cashbackOutboundAllowlist || null;
    if (!cfg || !cfg.ajaxurl || !cfg.nonce) {
        return;
    }

    var i18n = cfg.i18n || {};

    function showError(resp) {
        var message = (resp && resp.data && resp.data.message)
            ? resp.data.message
            : (i18n.errorGeneric || 'Ошибка.');
        window.alert(message);
    }

    $(document).on('click', '#cb-outbound-add-btn', function (event) {
        event.preventDefault();

        var host   = String($('#cb-outbound-host').val() || '').trim();
        var reason = String($('#cb-outbound-reason').val() || '').trim();

        if (host === '' || reason.length < 5) {
            window.alert(i18n.errorGeneric || 'Заполните хост и причину (не короче 5 символов).');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true);

        $.post(cfg.ajaxurl, {
            action: 'cashback_outbound_allowlist_add',
            nonce:  cfg.nonce,
            host:   host,
            reason: reason
        })
        .done(function (resp) {
            if (resp && resp.success) {
                window.location.reload();
            } else {
                showError(resp);
                $btn.prop('disabled', false);
            }
        })
        .fail(function () {
            window.alert(i18n.errorGeneric || 'Ошибка сети.');
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.cb-outbound-remove', function (event) {
        event.preventDefault();

        var host = String($(this).data('host') || '').trim();
        if (host === '') {
            return;
        }
        if (!window.confirm(i18n.confirmRemove || 'Удалить?')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true);

        $.post(cfg.ajaxurl, {
            action: 'cashback_outbound_allowlist_remove',
            nonce:  cfg.nonce,
            host:   host
        })
        .done(function (resp) {
            if (resp && resp.success) {
                window.location.reload();
            } else {
                showError(resp);
                $btn.prop('disabled', false);
            }
        })
        .fail(function () {
            window.alert(i18n.errorGeneric || 'Ошибка сети.');
            $btn.prop('disabled', false);
        });
    });
})(jQuery);
