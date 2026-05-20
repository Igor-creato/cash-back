/**
 * Кнопки блока «Данные CPA-сети о подтверждении заказов» в редакторе товара:
 *
 *   1. «Обновить данные» (Admitad/API-режим) →
 *      POST admin-ajax.php?action=cashback_refresh_cpa_approval_rate
 *
 *   2. «Сохранить» (Advcake/EPN/manual-режим, v4.4.28+) →
 *      POST admin-ajax.php?action=cashback_save_cpa_approval_rate
 *      с полем `rate` из соседнего <input id="cashback-cpa-rate-input">.
 *      Пустая строка = сброс (delete post_meta).
 *
 * Оба пути обновляют бэдж, подпись «Обновлено N назад» и показывают
 * ошибки inline.
 */
(function ($) {
    'use strict';

    function applyResponse(response, $badge, $fetched, $status, fallbackErr) {
        if (!response || !response.success || !response.data) {
            var msg = (response && response.data && response.data.message) ? response.data.message : fallbackErr;
            $status.css('color', '#a00').text(msg);
            return false;
        }
        var data = response.data;
        if ($badge.length) {
            $badge.removeClass(function (_, classes) {
                return (classes.match(/cashback-approval-badge--\S+/g) || []).join(' ');
            }).addClass('cashback-approval-badge--' + (data.bucket || 'insufficient'));
            $badge.text(data.badge_text || '');
        }
        if ($fetched.length) {
            $fetched.text(data.fetched_label || '');
        }
        $status.css('color', '#080').text('OK');
        return true;
    }

    function failResponse(xhr, $status, fallbackErr) {
        var msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
            ? xhr.responseJSON.data.message
            : fallbackErr;
        $status.css('color', '#a00').text(msg);
    }

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
            applyResponse(response, $badge, $fetched, $status, fallbackErr);
        }).fail(function (xhr) {
            failResponse(xhr, $status, fallbackErr);
        }).always(function () {
            $btn.prop('disabled', false).text(originalLabel);
        });
    });

    // v4.4.28: ручной ввод для Advcake/EPN (Publisher API этих сетей не отдаёт offer-wide AR).
    $(document).on('click', '#cashback-save-cpa-rate', function (event) {
        event.preventDefault();

        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }

        var $input = $('#cashback-cpa-rate-input').first();
        if (!$input.length) {
            return;
        }

        var productId = parseInt($input.attr('data-product-id'), 10);
        var nonce = $input.attr('data-nonce');
        if (!productId || !nonce) {
            return;
        }

        // Передаём строкой, чтобы отличить "" (сброс) от "0" (0%).
        var rateRaw = $.trim($input.val() || '');

        var cfg = window.cashbackCpaRateData || {};
        var $badge = $('[data-cpa-rate-badge="1"]').first();
        var $fetched = $('[data-cpa-rate-fetched="1"]').first();
        var $status = $('[data-cpa-rate-status="1"]').first();

        var originalLabel = $btn.text();
        var savingLabel = (cfg.i18n && cfg.i18n.saving) ? cfg.i18n.saving : 'Saving…';
        var fallbackErr = (cfg.i18n && cfg.i18n.failure) ? cfg.i18n.failure : 'Error';
        var saveAction = (cfg.saveAction) ? cfg.saveAction : 'cashback_save_cpa_approval_rate';

        $btn.prop('disabled', true).text(savingLabel);
        $status.removeAttr('style').text('');

        $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: saveAction,
                product_id: productId,
                nonce: nonce,
                rate: rateRaw
            }
        }).done(function (response) {
            applyResponse(response, $badge, $fetched, $status, fallbackErr);
        }).fail(function (xhr) {
            failResponse(xhr, $status, fallbackErr);
        }).always(function () {
            $btn.prop('disabled', false).text(originalLabel);
        });
    });
})(jQuery);
