/**
 * History Payout — Pagination + Filters
 * @package HistoryPayout
 */

jQuery(document).ready(function ($) {
  'use strict';

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
            window.CashbackPagination.build(response.data.current_page, response.data.total_pages)
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
