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

  // Авторизованные пользователи — никакого JS-перехвата,
  // ссылки работают как обычные <a href target=_blank>
  if (wcAffiliateParams.isLoggedIn) {
    return;
  }

  $(document).ready(function () {
    // Перехват кликов — ТОЛЬКО для гостей.
    // Используем селектор по href (cashback_click=) — это надёжнее, чем CSS-классы,
    // которые могут отличаться в разных темах (WoodMart, Flavor, и т.д.).
    $(document).on(
      'click',
      'a[href*="cashback_click="]',
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
   * @param {jQuery} $button Кнопка, по которой кликнули
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

  function showAuthWarning($button) {
    // Удаляем существующее модальное окно
    $('#wc-affiliate-warning-modal').remove();

    var redirectUrl = safeUrl($button.attr('href'));
    var loginUrl = safeUrl(wcAffiliateParams.loginUrl);
    var warningMessage = String(wcAffiliateParams.warningMessage == null ? '' : wcAffiliateParams.warningMessage);

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
      $('<a>', {
        href: redirectUrl,
        target: '_blank',
        rel: 'nofollow noopener noreferrer',
        'class': 'wc-affiliate-btn wc-affiliate-btn-primary',
        id: 'wc-affiliate-continue'
      }).text('\u041F\u0440\u043E\u0434\u043E\u043B\u0436\u0438\u0442\u044C \u0431\u0435\u0437 \u0430\u0432\u0442\u043E\u0440\u0438\u0437\u0430\u0446\u0438\u0438')
    );

    $content.append($actions);
    $modal.append($content);
    $('body').append($modal);

    setTimeout(function () {
      $('#wc-affiliate-warning-modal').addClass('show');
    }, 10);

    // «Продолжить без авторизации» — обычная ссылка, закрываем модалку
    $('#wc-affiliate-continue').on('click', function () {
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
