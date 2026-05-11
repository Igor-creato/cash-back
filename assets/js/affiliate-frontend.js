/**
 * Frontend: Партнёрская программа
 */
(function ($) {
    'use strict';

    if (typeof cashbackAffiliateData === 'undefined') {
        return;
    }

    var data = cashbackAffiliateData;

    function safeHtml(dirty) {
        if (typeof window.cashbackSafeHtml === 'function') {
            return window.cashbackSafeHtml(dirty);
        }
        if (typeof DOMPurify !== 'undefined') {
            return DOMPurify.sanitize(dirty);
        }
        return dirty;
    }

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
    $(document).on('click', '#affiliate-accruals-pagination .page-numbers[data-page]', function (e) {
        e.preventDefault();
        if ($(this).hasClass('current')) return;

        var page = parseInt($(this).data('page'), 10);
        if (!page) return;

        var $container  = $('#affiliate-accruals-container');
        var $pagination = $('#affiliate-accruals-pagination');

        $container.css('opacity', '0.5');

        $.post(data.ajaxurl, {
            action: 'affiliate_load_accruals',
            nonce:  data.nonce,
            page:   page
        }, function (resp) {
            $container.css('opacity', '1');
            if (resp.success && resp.data) {
                if (typeof resp.data.html === 'string') {
                    $container.html(safeHtml(resp.data.html));
                }
                if (typeof window.CashbackPagination !== 'undefined' &&
                    typeof window.CashbackPagination.build === 'function') {
                    $pagination.html(safeHtml(window.CashbackPagination.build(
                        resp.data.current_page,
                        resp.data.total_pages
                    )));
                }
            }
        }).fail(function () {
            $container.css('opacity', '1');
        });
    });

    /* ── Referrals pagination ── */
    $(document).on('click', '#affiliate-referrals-pagination .page-numbers[data-page]', function (e) {
        e.preventDefault();
        if ($(this).hasClass('current')) return;

        var page = parseInt($(this).data('page'), 10);
        if (!page) return;

        var $container  = $('#affiliate-referrals-container');
        var $pagination = $('#affiliate-referrals-pagination');

        $container.css('opacity', '0.5');

        $.post(data.ajaxurl, {
            action: 'affiliate_load_referrals',
            nonce:  data.nonce,
            page:   page
        }, function (resp) {
            $container.css('opacity', '1');
            if (resp.success && resp.data) {
                if (typeof resp.data.html === 'string') {
                    $container.html(safeHtml(resp.data.html));
                }
                if (typeof window.CashbackPagination !== 'undefined' &&
                    typeof window.CashbackPagination.build === 'function') {
                    $pagination.html(safeHtml(window.CashbackPagination.build(
                        resp.data.current_page,
                        resp.data.total_pages
                    )));
                }
            }
        }).fail(function () {
            $container.css('opacity', '1');
        });
    });

})(jQuery);
