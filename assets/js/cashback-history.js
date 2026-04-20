/**
 * Cashback History — Pagination + Filters
 * @package CashbackHistory
 */

(function ($) {
  'use strict';

  function safeHtml(dirty) {
    if (typeof window.cashbackSafeHtml === 'function') {
      return window.cashbackSafeHtml(dirty);
    }
    if (typeof DOMPurify !== 'undefined') {
      return DOMPurify.sanitize(dirty);
    }
    return dirty;
  }

  function getFilters() {
    return {
      date_from: $('#history-date-from').val() || '',
      date_to: $('#history-date-to').val() || '',
      search: $('#history-search').val() || '',
      status: $('#history-status').val() || ''
    };
  }

  function loadPage(page) {
    var filters = getFilters();

    $.ajax({
      url: cashback_history_ajax.ajax_url,
      type: 'POST',
      data: $.extend({
        action: 'load_page_transactions',
        nonce: cashback_history_ajax.nonce,
        page: page
      }, filters),
      success: function (response) {
        if (response.success) {
          $('#transactions-table-container').html(safeHtml(response.data.html));
          $('#pagination-container').html(
            safeHtml(window.CashbackPagination.build(response.data.current_page, response.data.total_pages))
          );
        }
      }
    });
  }

  var initHistory = function () {
    if (typeof cashback_history_ajax === 'undefined') {
      return;
    }

    if (cashback_history_ajax.is_cashback_page !== 'true') {
      return;
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
    $('#history-filter-apply').on('click', function () {
      loadPage(1);
    });

    // Filter reset
    $('#history-filter-reset').on('click', function () {
      $('#history-date-from').val('');
      $('#history-date-to').val('');
      $('#history-search').val('');
      $('#history-status').val('');
      loadPage(1);
    });

    // Enter key in search field
    $('#history-search').on('keypress', function (e) {
      if (e.which === 13) {
        e.preventDefault();
        loadPage(1);
      }
    });
  };

  $(document).ready(function () {
    initHistory();
  });
})(jQuery);
