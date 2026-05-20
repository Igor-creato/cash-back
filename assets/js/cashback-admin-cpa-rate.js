/**
 * Кнопка «Обновить данные» в блоке «Данные CPA-сети о подтверждении заказов»
 * в редакторе товара.
 *
 * POST admin-ajax.php?action=cashback_refresh_cpa_approval_rate с
 * product_id + nonce (берутся из data-атрибутов кнопки), обновляет бэдж,
 * подпись «Обновлено N назад» и показывает ошибки inline.
 */
(function ($) {
    'use strict';

    $(document).on('click', '#cashback-refresh-cpa-rate', function (event) {
        event.preventDefault();

        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }

        var productId = parseInt($btn.attr('data-product-id'), 10);
        var nonce = $btn.attr('data-nonce');
        if (!productId || !nonce) {
            return;
        }

        var cfg = window.cashbackCpaRateData || {};
        var $badge = $('[data-cpa-rate-badge="1"]').first();
        var $fetched = $('[data-cpa-rate-fetched="1"]').first();
        var $status = $('[data-cpa-rate-status="1"]').first();

        var originalLabel = $btn.text();
        var loadingLabel = (cfg.i18n && cfg.i18n.loading) ? cfg.i18n.loading : 'Loading…';
        var fallbackErr = (cfg.i18n && cfg.i18n.failure) ? cfg.i18n.failure : 'Error';

        $btn.prop('disabled', true).text(loadingLabel);
        $status.removeAttr('style').text('');

        $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: cfg.action,
                product_id: productId,
                nonce: nonce
            }
        }).done(function (response) {
            if (!response || !response.success || !response.data) {
                var msg = (response && response.data && response.data.message) ? response.data.message : fallbackErr;
                $status.css('color', '#a00').text(msg);
                return;
            }

            var data = response.data;

            if ($badge.length) {
                // Меняем только bucket-модификатор, не трогая базовый класс
                // и data-атрибуты, чтобы повторный клик нашёл бэдж тем же селектором.
                $badge.removeClass(function (_, classes) {
                    return (classes.match(/cashback-approval-badge--\S+/g) || []).join(' ');
                }).addClass('cashback-approval-badge--' + (data.bucket || 'insufficient'));
                $badge.text(data.badge_text || '');
            }
            if ($fetched.length) {
                $fetched.text(data.fetched_label || '');
            }
            $status.css('color', '#080').text('OK');
        }).fail(function (xhr) {
            var msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                ? xhr.responseJSON.data.message
                : fallbackErr;
            $status.css('color', '#a00').text(msg);
        }).always(function () {
            $btn.prop('disabled', false).text(originalLabel);
        });
    });
})(jQuery);
