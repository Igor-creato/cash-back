/**
 * History Payout — Pagination + Filters
 * @package HistoryPayout
 */

jQuery(document).ready(function ($) {
  'use strict';

  function buildPagination(currentPage, totalPages) {
    if (totalPages <= 1) {
      return '';
    }

    var range = 2;
    var edge = 2;
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

    var html = '<nav class="woocommerce-pagination"><ul class="page-numbers">';

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

  function getFilters() {
    return {
      date_from: $('#payout-date-from').val() || '',
      date_to: $('#payout-date-to').val() || '',
      search: $('#payout-search').val() || '',
      status: $('#payout-status').val() || ''
    };
  }

  function loadPage(page) {
    var filters = getFilters();

    $.ajax({
      url: payout_ajax.ajax_url,
      type: 'POST',
      data: $.extend({
        action: 'load_page_payouts',
        nonce: payout_ajax.nonce,
        page: page
      }, filters),
      success: function (response) {
        if (response.success) {
          $('#payouts-table-container').html(response.data.html);
          $('#pagination-container').html(
            buildPagination(response.data.current_page, response.data.total_pages)
          );
        }
      }
    });
  }

  // Pagination
  $(document).on('click', '#pagination-container .page-numbers[data-page]', function (e) {
    e.preventDefault();
    var page = $(this).data('page');
    if (page) {
      loadPage(page);
    }
  });

  // Filter apply
  $('#payout-filter-apply').on('click', function () {
    loadPage(1);
  });

  // Filter reset
  $('#payout-filter-reset').on('click', function () {
    $('#payout-date-from').val('');
    $('#payout-date-to').val('');
    $('#payout-search').val('');
    $('#payout-status').val('');
    loadPage(1);
  });

  // Enter key in search field
  $('#payout-search').on('keypress', function (e) {
    if (e.which === 13) {
      e.preventDefault();
      loadPage(1);
    }
  });
});
