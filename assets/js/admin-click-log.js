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

      // Общие для обоих табов.
      setOrDelete(url, 'email', $('#filter-email').val());
      setOrDelete(url, 'date_from', $('#filter-date-from').val());
      setOrDelete(url, 'date_to', $('#filter-date-to').val());

      // Tab «Все клики»: spam_only.
      var $spam = $('#filter-spam-only');
      if ($spam.length) {
        if ($spam.is(':checked')) {
          url.searchParams.set('spam_only', '1');
        } else {
          url.searchParams.delete('spam_only');
        }
      }

      // Tab «Промо клики»: promo_action + promo_id.
      var $promoAction = $('#filter-promo-action');
      if ($promoAction.length) {
        setOrDelete(url, 'promo_action', $promoAction.val());
      }
      var $promoId = $('#filter-promo-id');
      if ($promoId.length) {
        var pid = parseInt($promoId.val(), 10);
        if (pid > 0) {
          url.searchParams.set('promo_id', String(pid));
        } else {
          url.searchParams.delete('promo_id');
        }
      }

      url.searchParams.delete('paged');
      window.location.href = url.toString();
    });

    $('#filter-reset').on('click', function () {
      var url = new URL(window.location);
      // Не трогаем `tab` и `page` — остаёмся на текущем табе.
      ['email', 'date_from', 'date_to', 'spam_only', 'promo_action', 'promo_id', 'paged'].forEach(function (k) {
        url.searchParams.delete(k);
      });
      window.location.href = url.toString();
    });
  }

  function setOrDelete(url, key, value) {
    if (value !== undefined && value !== null && String(value) !== '') {
      url.searchParams.set(key, String(value));
    } else {
      url.searchParams.delete(key);
    }
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
