/**
 * WC Affiliate URL Params — Guest Warning Modal
 *
 * Для авторизованных пользователей: JS-перехват не нужен.
 * Ссылки ведут на ?cashback_click={id}, сервер делает 302 redirect.
 *
 * Для гостей: перехватываем клик, показываем модалку с предупреждением.
 * Кнопка «Продолжить» — обычная ссылка на redirect endpoint.
 *
 * @since 4.0.0
 */
(function ($) {
  'use strict';

  var params = window.wcAffiliateParams || {};

  window.CashbackAffiliateGuestWarning = window.CashbackAffiliateGuestWarning || {};
  window.CashbackAffiliateGuestWarning.show = showAuthWarning;

  // Авторизованные пользователи — никакого JS-перехвата,
  // ссылки работают как обычные <a href target=_blank>
  if (params.isLoggedIn) {
    return;
  }

  $(document).ready(function () {
    // Перехват кликов — ТОЛЬКО для гостей.
    // Используем селектор по href — надёжнее CSS-классов (могут отличаться в темах).
    // Покрываем оба redirect-endpoint'а:
    //   - cashback_click=        — обычный WC external product
    //   - cashback_promo_click=  — карточка промокода (Cashback_Promocodes_Redirect)
    $(document).on(
      'click',
      'a[href*="cashback_click="], a[href*="cashback_promo_click="]',
      function (e) {
        // Не перехватываем клик по кнопке «Продолжить» внутри модалки
        if ($(this).closest('#wc-affiliate-warning-modal').length) {
          return;
        }

        e.preventDefault();
        e.stopImmediatePropagation();

        showAuthWarning($(this));
        return false;
      },
    );
  });

  /**
   * Показ модального окна для неавторизованных пользователей
   *
   * @param {jQuery|Object} source Кнопка, по которой кликнули, или опции модалки.
   */
  function safeUrl(raw) {
    var s = String(raw == null ? '' : raw).trim();
    if (/^\s*javascript:/i.test(s) || /^\s*data:/i.test(s) || /^\s*vbscript:/i.test(s)) {
      return '#';
    }
    // Разрешаем http/https/относительные пути
    if (/^https?:\/\//i.test(s) || s.charAt(0) === '/' || s.charAt(0) === '?' || s.charAt(0) === '#') {
      return s;
    }
    if (!/:/.test(s)) {
      // относительный без двоеточия — допустим
      return s;
    }
    return '#';
  }

  function showAuthWarning(source) {
    // Удаляем существующее модальное окно
    $('#wc-affiliate-warning-modal').remove();

    var hasAttr = source && typeof source.attr === 'function';
    var options = !hasAttr && source && typeof source === 'object' ? source : {};
    var redirectUrl = safeUrl(hasAttr ? source.attr('href') : options.redirectUrl);
    var loginUrl = safeUrl(options.loginUrl || params.loginUrl);
    var warningMessage = String(
      options.warningMessage == null
        ? (params.warningMessage == null ? '' : params.warningMessage)
        : options.warningMessage
    );
    var onContinue = typeof options.onContinue === 'function' ? options.onContinue : null;

    var $modal = $('<div>', { id: 'wc-affiliate-warning-modal', 'class': 'wc-affiliate-modal' });
    var $content = $('<div>', { 'class': 'wc-affiliate-modal-content' });

    $content.append($('<span>', { 'class': 'wc-affiliate-modal-close' }).html('&times;'));
    $content.append($('<div>', { 'class': 'wc-affiliate-modal-icon' }).text('\u26A0\uFE0F'));
    $content.append($('<h3>', { 'class': 'wc-affiliate-modal-title' }).text('\u0412\u043D\u0438\u043C\u0430\u043D\u0438\u0435'));
    $content.append($('<p>', { 'class': 'wc-affiliate-modal-message' }).text(warningMessage));

    var $actions = $('<div>', { 'class': 'wc-affiliate-modal-actions' });
    $actions.append(
      $('<a>', {
        href: loginUrl,
        'class': 'wc-affiliate-btn wc-affiliate-btn-secondary',
        id: 'wc-affiliate-cancel'
      }).text('\u0410\u0432\u0442\u043E\u0440\u0438\u0437\u043E\u0432\u0430\u0442\u044C\u0441\u044F \u0438\u043B\u0438 \u0437\u0430\u0440\u0435\u0433\u0438\u0441\u0442\u0440\u0438\u0440\u043E\u0432\u0430\u0442\u044C\u0441\u044F')
    );
    $actions.append(
      $('<a>', continueAttrs(onContinue, redirectUrl)).text('\u041F\u0440\u043E\u0434\u043E\u043B\u0436\u0438\u0442\u044C \u0431\u0435\u0437 \u0430\u0432\u0442\u043E\u0440\u0438\u0437\u0430\u0446\u0438\u0438')
    );

    function continueAttrs(callback, url) {
      var attrs = {
        href: url,
        target: '_blank',
        rel: 'nofollow noopener noreferrer',
        'class': 'wc-affiliate-btn wc-affiliate-btn-primary',
        id: 'wc-affiliate-continue'
      };

      if (callback) {
        attrs.href = '#';
        delete attrs.target;
        delete attrs.rel;
      }

      return attrs;
    }

    $content.append($actions);
    $modal.append($content);
    $('body').append($modal);

    setTimeout(function () {
      $('#wc-affiliate-warning-modal').addClass('show');
    }, 10);

    // «Продолжить без авторизации» — обычная ссылка, закрываем модалку
    $('#wc-affiliate-continue').on('click', function (e) {
      if (onContinue) {
        e.preventDefault();
        closeModal();
        onContinue();
        return false;
      }

      closeModal();
      // Ссылка — обычный <a href target=_blank>, браузер сам откроет
    });

    // Закрытие по крестику
    $('#wc-affiliate-warning-modal').on('click', '.wc-affiliate-modal-close', function () {
      closeModal();
    });

    // Закрытие при клике вне окна
    $(window).on('click.wcAffiliateModal', function (e) {
      if ($(e.target).attr('id') === 'wc-affiliate-warning-modal') {
        closeModal();
      }
    });
  }

  /**
   * Закрытие модального окна
   */
  function closeModal() {
    $('#wc-affiliate-warning-modal').removeClass('show');
    setTimeout(function () {
      $('#wc-affiliate-warning-modal').remove();
      $(window).off('click.wcAffiliateModal');
    }, 300);
  }
})(jQuery);
