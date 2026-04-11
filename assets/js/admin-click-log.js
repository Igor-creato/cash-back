/**
 * Admin Click Log JavaScript
 *
 * @package WP_Cashback_Plugin
 */

(function ($) {
  'use strict';

  $(document).ready(function () {
    initFilters();
    initCopyable();
  });

  /**
   * Инициализация фильтров
   */
  function initFilters() {
    $('#filter-submit').on('click', function () {
      var url = new URL(window.location);

      var email = $('#filter-email').val();
      var dateFrom = $('#filter-date-from').val();
      var dateTo = $('#filter-date-to').val();
      var spamOnly = $('#filter-spam-only').is(':checked');

      if (email) {
        url.searchParams.set('email', email);
      } else {
        url.searchParams.delete('email');
      }

      if (dateFrom) {
        url.searchParams.set('date_from', dateFrom);
      } else {
        url.searchParams.delete('date_from');
      }

      if (dateTo) {
        url.searchParams.set('date_to', dateTo);
      } else {
        url.searchParams.delete('date_to');
      }

      if (spamOnly) {
        url.searchParams.set('spam_only', '1');
      } else {
        url.searchParams.delete('spam_only');
      }

      url.searchParams.delete('paged');
      window.location.href = url.toString();
    });

    $('#filter-reset').on('click', function () {
      var url = new URL(window.location);
      url.searchParams.delete('email');
      url.searchParams.delete('date_from');
      url.searchParams.delete('date_to');
      url.searchParams.delete('spam_only');
      url.searchParams.delete('paged');
      window.location.href = url.toString();
    });
  }

  /**
   * Инициализация копирования по клику
   */
  function initCopyable() {
    $(document).on('click', '.copyable', function () {
      var $el = $(this);
      var text = $el.data('copy');

      if (!text) {
        return;
      }

      navigator.clipboard.writeText(text).then(function () {
        // Убираем предыдущую метку если есть
        $el.find('.copy-ok').remove();

        var $ok = $('<span class="copy-ok">скопировано</span>');
        $el.append($ok);

        setTimeout(function () {
          $ok.fadeOut(200, function () {
            $(this).remove();
          });
        }, 1500);
      });
    });
  }
})(jQuery);
