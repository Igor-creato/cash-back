/**
 * Frontend: Партнёрская программа
 */
(function ($) {
    'use strict';

    if (typeof cashbackAffiliateData === 'undefined') {
        return;
    }

    var data = cashbackAffiliateData;

    /* ── Copy referral link ── */
    $(document).on('click', '.cashback-affiliate-copy-btn', function () {
        var targetId = $(this).data('target');
        var $input = $('#' + targetId);
        var $btn = $(this);

        if (!$input.length) return;

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText($input.val()).then(function () {
                $btn.text('Скопировано!');
                setTimeout(function () { $btn.text('Копировать'); }, 2000);
            });
        } else {
            $input[0].select();
            document.execCommand('copy');
            $btn.text('Скопировано!');
            setTimeout(function () { $btn.text('Копировать'); }, 2000);
        }
    });

    /* ── Accruals pagination ── */
    $(document).on('click', '#affiliate-accruals-container .page-numbers[data-page]', function (e) {
        e.preventDefault();
        var page = $(this).data('page');
        var $container = $('#affiliate-accruals-container');

        $container.css('opacity', '0.5');

        $.post(data.ajaxurl, {
            action: 'affiliate_load_accruals',
            nonce:  data.nonce,
            page:   page
        }, function (resp) {
            $container.css('opacity', '1');
            if (resp.success && resp.data.html) {
                $container.html(resp.data.html);
            }
        }).fail(function () {
            $container.css('opacity', '1');
        });
    });

    /* ── Referrals pagination ── */
    $(document).on('click', '#affiliate-referrals-container .page-numbers[data-page]', function (e) {
        e.preventDefault();
        var page = $(this).data('page');
        var $container = $('#affiliate-referrals-container');

        $container.css('opacity', '0.5');

        $.post(data.ajaxurl, {
            action: 'affiliate_load_referrals',
            nonce:  data.nonce,
            page:   page
        }, function (resp) {
            $container.css('opacity', '1');
            if (resp.success && resp.data.html) {
                $container.html(resp.data.html);
            }
        }).fail(function () {
            $container.css('opacity', '1');
        });
    });

})(jQuery);
