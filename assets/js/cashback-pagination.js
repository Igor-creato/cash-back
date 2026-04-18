/**
 * Cashback Pagination — единый клиентский хелпер.
 *
 * Использование:
 *   var html = window.CashbackPagination.build(currentPage, totalPages);
 *   $('#pagination-container').html(html);
 *
 * HTML идентичен рендерингу PHP-класса Cashback_Pagination в режиме 'ajax'.
 *
 * @package Cashback_Plugin
 */

(function () {
    'use strict';

    function build(currentPage, totalPages, opts) {
        currentPage = parseInt(currentPage, 10) || 1;
        totalPages  = parseInt(totalPages, 10) || 0;
        opts        = opts || {};

        if (totalPages <= 1) {
            return '';
        }

        var edge  = (typeof opts.edge === 'number') ? opts.edge : 2;
        var range = (typeof opts.range === 'number') ? opts.range : 2;
        var containerClass = opts.containerClass || 'woocommerce-pagination';

        var pagesSet = {};
        var i;

        for (i = 1; i <= Math.min(edge, totalPages); i++) {
            pagesSet[i] = true;
        }
        for (i = Math.max(1, currentPage - range); i <= Math.min(totalPages, currentPage + range); i++) {
            pagesSet[i] = true;
        }
        for (i = Math.max(1, totalPages - edge + 1); i <= totalPages; i++) {
            pagesSet[i] = true;
        }

        var pages = Object.keys(pagesSet).map(Number).sort(function (a, b) { return a - b; });

        var html = '<nav class="' + containerClass + '"><ul class="page-numbers">';

        if (currentPage > 1) {
            html += '<li><a href="#" class="page-numbers prev" data-page="' + (currentPage - 1) + '">&lsaquo;</a></li>';
        }

        var prev = 0;
        for (i = 0; i < pages.length; i++) {
            var page = pages[i];
            if (prev && page - prev > 1) {
                html += '<li><span class="page-numbers dots">&hellip;</span></li>';
            }
            var cls = (page === currentPage) ? 'current' : '';
            html += '<li><a href="#" class="page-numbers ' + cls + '" data-page="' + page + '">' + page + '</a></li>';
            prev = page;
        }

        if (currentPage < totalPages) {
            html += '<li><a href="#" class="page-numbers next" data-page="' + (currentPage + 1) + '">&rsaquo;</a></li>';
        }

        html += '</ul></nav>';
        return html;
    }

    window.CashbackPagination = { build: build };
}());
