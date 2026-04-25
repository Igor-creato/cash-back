// Frontend JavaScript for the cashback withdrawal functionality

/**
 * Генерация UUID v4 для идемпотентного ключа.
 * Использует crypto.randomUUID() если доступен, иначе fallback на Math.random().
 */
function generateIdempotencyKey() {
  // crypto.randomUUID() поддерживается во всех современных браузерах
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  // Безопасный fallback через getRandomValues
  return ([1e7] + -1e3 + -4e3 + -8e3 + -1e11).replace(/[018]/g, (c) =>
    (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16),
  );
}

jQuery(document).ready(function ($) {
  // Идемпотентный ключ: генерируется при загрузке, обновляется только после успешного вывода
  var withdrawalIdempotencyKey = generateIdempotencyKey();

  // Переключение вкладок
  $(document).on('click', '.cashback-tab', function () {
    $('.cashback-tab').removeClass('active cashback-tab--error');
    $(this).addClass('active');
    var target = $(this).data('tab');
    $('.cashback-tab-content').removeClass('active');
    $('#' + target).addClass('active');
  });

  // Сброс подсветки ошибки при вводе суммы
  $('#withdrawal-amount').on('input', function () {
    $(this).removeClass('input--error');
  });

  // Обработчик отправки формы вывода кэшбэка
  $('#withdrawal-form').on('submit', function (e) {
    e.preventDefault(); // Предотвращаем стандартную отправку формы

    // Блокируем кнопку отправки и очищаем предыдущие сообщения
    var submitBtn = $('#withdrawal-submit');
    var withdrawalAmount = $('#withdrawal-amount');

    // Проверяем, заблокирована ли форма (чтобы избежать двойного нажатия)
    if (submitBtn.prop('disabled')) {
      return false;
    }

    // iter-32 F-32-003: строгая decimal-валидация без parseFloat.
    // parseFloat принимает частично валидные строки ("1abc" → 1) и IEEE-float-арифметику,
    // что создаёт расхождения между введённым и отправленным значением. Отправляем на
    // сервер нормализованную decimal-строку; сервер — single source of truth для сумм.
    var amountInput      = withdrawalAmount.val();
    var normalizedAmount = String(amountInput || '').trim().replace(',', '.');

    // Проверка формата + нулевого значения. Сервер валидирует повторно.
    if (
      !/^\d+(?:\.\d{1,2})?$/.test(normalizedAmount) ||
      normalizedAmount === '0' ||
      normalizedAmount === '0.00' ||
      normalizedAmount === '0.0'
    ) {
      withdrawalAmount.addClass('input--error');
      // iter-32 F-32-001: безопасная вставка текста (DOM-построение вместо .html()).
      $('#withdrawal-messages').empty().append(
        $('<div>').addClass('error-message').text('Пожалуйста, введите корректную сумму для вывода.'),
      );
      // Разблокируем форму, так как не отправляем запрос
      submitBtn.prop('disabled', false);
      withdrawalAmount.prop('disabled', false);
      submitBtn.val('Вывести'); // Возвращаем первоначальный текст кнопки
      return false;
    }

    // Блокируем форму во время обработки
    submitBtn.prop('disabled', true);
    withdrawalAmount.prop('disabled', true);
    submitBtn.val('Обработка...'); // Меняем текст кнопки

    // Подготовка данных для AJAX запроса
    var data = {
      action: 'process_cashback_withdrawal',
      withdrawal_amount: normalizedAmount,
      nonce: cashback_ajax.withdrawal_submit_nonce,
      idempotency_key: withdrawalIdempotencyKey,
    };

    // Юр. чекбокс согласия на обработку платёжных данных (161-ФЗ).
    // Передаётся только если присутствует на форме (рендерится при первом
    // обращении пользователя — Cashback_Legal_Payout_Consent::render_checkbox).
    var $legalConsent = $('#withdrawal-form input[name="cashback_legal_payment_pd_consent"]');
    if ($legalConsent.length) {
      // UX-валидация: при отсутствии отметки — красная рамка + сообщение
      // под чекбоксом + focus, без отправки на сервер. Серверная валидация
      // в Cashback_Legal_Payout_Consent::enforce_or_error остаётся как fallback.
      if (!$legalConsent.is(':checked') && window.CashbackConsentValidate) {
        var legalMsg = (typeof cashback_ajax !== 'undefined' && cashback_ajax.legal_payment_pd_required_message)
          ? cashback_ajax.legal_payment_pd_required_message
          : 'Подтвердите согласие на обработку платёжных данных (161-ФЗ).';
        window.CashbackConsentValidate.validateRequired([$legalConsent[0]], legalMsg);
        submitBtn.prop('disabled', false);
        withdrawalAmount.prop('disabled', false);
        submitBtn.val('Вывести');
        return false;
      }
      if (window.CashbackConsentValidate) {
        window.CashbackConsentValidate.bindAutoClear($legalConsent[0]);
      }
      data.cashback_legal_payment_pd_consent = $legalConsent.is(':checked') ? '1' : '0';
      data.cashback_legal_payment_pd_request_id = $('#withdrawal-form input[name="cashback_legal_payment_pd_request_id"]').val() || '';
    }

    // Отправляем AJAX запрос
    $.ajax({
      url: cashback_ajax.ajax_url,
      type: 'POST',
      data: data,
      success: function (response) {
        if (response.success) {
          // Обновляем идемпотентный ключ после подтверждённого успеха
          withdrawalIdempotencyKey = generateIdempotencyKey();

          // iter-32 F-32-001: server-data идёт через .text(), не .html() — XSS закрыт.
          $('#withdrawal-messages').empty().append(
            $('<div>').addClass('success-message').text(String(response.data || '')),
          );

          // Очищаем поле ввода
          withdrawalAmount.val('');

          // Обновляем баланс пользователя через AJAX
          updateBalanceDisplay();
        } else {
          // Ошибка — поддержка как строки, так и объекта с message и show_form
          var errorMsg =
            typeof response.data === 'object' && response.data !== null
              ? response.data.message
              : response.data;
          // iter-32 F-32-001: error-сообщение с сервера выводим как текст.
          $('#withdrawal-messages').empty().append(
            $('<div>').addClass('error-message').text(String(errorMsg || '')),
          );

          // Если сервер указал показать форму настроек (платёжная система или банк неактивны)
          var isFormError =
            typeof response.data === 'object' && response.data !== null && response.data.show_form;

          if (isFormError) {
            // Подсвечиваем вкладку настроек красным
            $('.cashback-tab[data-tab="tab-settings"]').addClass('cashback-tab--error');
            // Переключаемся на вкладку настроек
            $('.cashback-tab').removeClass('active');
            $('.cashback-tab[data-tab="tab-settings"]').addClass('active');
            $('.cashback-tab-content').removeClass('active');
            $('#tab-settings').addClass('active');
            // Показываем форму редактирования
            $('#payout_settings_display').hide();
            $('#payout_settings_form').removeClass('payout-settings-form-hidden').show();
          } else {
            // Ошибка связана с суммой — подсвечиваем поле
            withdrawalAmount.addClass('input--error');
          }
        }
      },
      error: function (xhr, status, error) {
        // Ошибка соединения или серверная ошибка
        var errorMessage = 'Ошибка соединения. Пожалуйста, попробуйте еще раз.';
        if (xhr.status === 403) {
          errorMessage = 'Ошибка авторизации. Пожалуйста, войдите в систему и попробуйте снова.';
        } else if (xhr.status === 400) {
          errorMessage = 'Неверный запрос. Пожалуйста, обновите страницу и попробуйте снова.';
        } else if (xhr.status === 500) {
          errorMessage = 'Внутренняя ошибка сервера. Пожалуйста, попробуйте позже.';
        }
        $('#withdrawal-messages').html('<div class="error-message">' + errorMessage + '</div>');
      },
      complete: function () {
        // Разблокируем форму после завершения запроса
        submitBtn.prop('disabled', false);
        withdrawalAmount.prop('disabled', false);
        submitBtn.val('Вывести'); // Возвращаем первоначальный текст кнопки
      },
    });
  });
});

/**
 * Форматирование суммы в стиле шорткода: «1 234,56 ₽»
 * (number_format с разделителем тысяч — пробел, десятичным — запятая)
 */
function formatCashbackAmount(amount, decimals) {
  var fixed = parseFloat(amount).toFixed(decimals);
  var parts = fixed.split('.');
  parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0'); // неразрывный пробел
  return parts.join(',') + '\u00a0\u20bd'; // «,» + «₽»
}

/**
 * Функция для обновления отображения баланса пользователя
 */
function updateBalanceDisplay() {
  jQuery.ajax({
    url: cashback_ajax.ajax_url,
    type: 'POST',
    data: {
      action: 'get_user_balance',
      nonce: cashback_ajax.nonce,
    },
    success: function (response) {
      if (response.success) {
        var avail   = response.data.balance;
        var pending = response.data.pending_balance;
        var paid    = response.data.paid_balance;

        // iter-32 F-32-001: server-formatted данные через .text(), не .html() (defense-in-depth).
        jQuery('#cashback-balance-amount').text(String(response.data.formatted_balance || ''));
        jQuery('#cashback-pending-amount').text(String(response.data.formatted_pending || ''));
        jQuery('#cashback-paid-amount').text(String(response.data.formatted_paid || ''));

        // Обновляем максимальное значение для поля ввода
        jQuery('#withdrawal-amount').attr('max', avail);

        // Обновляем шорткоды [cashback_balance] — одиночный виджет
        jQuery('.cashback-balance--available .cashback-balance__amount').text(formatCashbackAmount(avail, 2));
        jQuery('.cashback-balance--pending .cashback-balance__amount').text(formatCashbackAmount(pending, 2));
        jQuery('.cashback-balance--paid .cashback-balance__amount').text(formatCashbackAmount(paid, 2));

        // Обновляем шорткоды [cashback_balance type="all"] — виджет со всеми балансами
        jQuery('.cashback-balance-widget__row--available .cashback-balance-widget__amount').text(formatCashbackAmount(avail, 2));
        jQuery('.cashback-balance-widget__row--pending .cashback-balance-widget__amount').text(formatCashbackAmount(pending, 2));
        jQuery('.cashback-balance-widget__row--paid .cashback-balance-widget__amount').text(formatCashbackAmount(paid, 2));
      }
    },
    error: function () {
      // iter-32 F-32-002: diagnostic console-лог удалён (PII/nonce policy).
    },
  });
}

/**
 * Обработчик для кнопки "Сохранить настройки" на странице вывода кэшбэка
 */
jQuery(document).ready(function ($) {
  // Проверяем наличие cashback_ajax
  if (typeof cashback_ajax === 'undefined') {
    // iter-32 F-32-002: cashback_ajax содержит nonce — не логируем даже при отсутствии.
    return;
  }

  /**
   * Валидация номера телефона для СБП.
   * Должен начинаться с +, далее 10-15 цифр (E.164).
   * @param {string} phone
   * @returns {string} Сообщение об ошибке или пустая строка
   */
  function validatePhoneNumber(phone) {
    var cleaned = phone.replace(/[\s\-\(\)]/g, '');

    if (!cleaned || cleaned.charAt(0) !== '+') {
      return 'Номер телефона должен начинаться с кода страны (например, +79001234567).';
    }

    var digits = cleaned.substring(1);

    if (!/^\d+$/.test(digits)) {
      return 'Номер телефона содержит недопустимые символы.';
    }

    if (digits.length < 10 || digits.length > 15) {
      return 'Введите полный номер телефона с кодом страны (10-15 цифр после +).';
    }

    return '';
  }

  /**
   * Валидация номера карты МИР.
   * 16 цифр, BIN 2200-2204, проверка Luhn.
   * @param {string} card
   * @returns {string} Сообщение об ошибке или пустая строка
   */
  function validateMirCard(card) {
    var cleaned = card.replace(/[\s\-]/g, '');

    if (!/^\d+$/.test(cleaned)) {
      return 'Номер карты должен содержать только цифры.';
    }

    if (cleaned.length !== 16) {
      return 'Номер карты МИР должен содержать 16 цифр.';
    }

    var binPrefix = parseInt(cleaned.substring(0, 4), 10);
    if (binPrefix < 2200 || binPrefix > 2204) {
      return 'Номер карты МИР должен начинаться с 2200-2204.';
    }

    if (!luhnCheck(cleaned)) {
      return 'Некорректный номер карты (не прошёл проверку контрольной суммы).';
    }

    return '';
  }

  /**
   * Алгоритм Луна для проверки номера карты.
   * @param {string} number Строка только из цифр
   * @returns {boolean}
   */
  function luhnCheck(number) {
    var sum = 0;
    var parity = number.length % 2;

    for (var i = 0; i < number.length; i++) {
      var digit = parseInt(number.charAt(i), 10);

      if (i % 2 === parity) {
        digit *= 2;
        if (digit > 9) {
          digit -= 9;
        }
      }

      sum += digit;
    }

    return sum % 10 === 0;
  }

  // Сброс подсветки ошибок и обновление label/placeholder при смене способа вывода
  $(document).on('change', '#payout_method_id', function () {
    $(this).removeClass('input--error');

    var selectedOption = $(this).find('option:selected');
    var methodSlug = selectedOption.data('slug') || '';
    var $label = $('label[for="payout_account"]');
    var $input = $('#payout_account');

    switch (methodSlug) {
      case 'sbp':
        $label.html('Номер телефона <span class="required">*</span>');
        $input.attr('placeholder', '+79001234567');
        $input.attr('type', 'tel');
        $input.attr('inputmode', 'tel');
        $input.attr('maxlength', '20');
        break;
      case 'mir':
        $label.html('Номер карты МИР <span class="required">*</span>');
        $input.attr('placeholder', '2200 XXXX XXXX XXXX');
        $input.attr('type', 'text');
        $input.attr('inputmode', 'numeric');
        $input.attr('maxlength', '19');
        break;
      default:
        $label.html('Номер счета или телефона <span class="required">*</span>');
        $input.attr('placeholder', '');
        $input.attr('type', 'text');
        $input.removeAttr('inputmode');
        $input.attr('maxlength', '50');
        break;
    }

    $input.removeClass('input--error');
    $('#payout_settings_message').text('').removeClass('success error');

    // Показываем/скрываем блок банка в зависимости от bank_required
    var bankRequired = parseInt(selectedOption.data('bank-required'), 10);
    var $bankRow = $('#bank_search_input').closest('.woocommerce-form-row');

    if (bankRequired === 0) {
      $bankRow.hide();
      $('#bank_id').val('');
      $('#bank_search_input').val('');
      $('#bank_search_error').text('').hide();
      $('#bank_search_input').removeClass('bank-search-input--error');
    } else {
      $bankRow.show();
    }
  });

  // Сброс ошибки и авто-форматирование при вводе номера счёта/карты
  $(document).on('input', '#payout_account', function () {
    $(this).removeClass('input--error');

    var selectedOption = $('#payout_method_id option:selected');
    var methodSlug = selectedOption.data('slug') || '';

    if (methodSlug === 'mir') {
      var cursorPos = this.selectionStart;
      var rawValue = $(this).val().replace(/\D/g, '');
      rawValue = rawValue.substring(0, 16);

      var formatted = rawValue.replace(/(\d{4})(?=\d)/g, '$1 ');

      var oldVal = $(this).val();
      $(this).val(formatted);

      var spacesBeforeOld = (oldVal.substring(0, cursorPos).match(/ /g) || []).length;
      var spacesBeforeNew = (
        formatted.substring(0, cursorPos + (formatted.length - oldVal.length)).match(/ /g) || []
      ).length;
      var newPos = cursorPos + (spacesBeforeNew - spacesBeforeOld);
      this.setSelectionRange(newPos, newPos);
    }
  });

  // При загрузке страницы — обновить label/placeholder для уже выбранного метода
  if ($('#payout_method_id').val()) {
    $('#payout_method_id').trigger('change');
  }

  // ============================================================
  // Компонент поиска банков с AJAX, клавиатурной навигацией и a11y
  // ============================================================
  var bankSearchTimer = null;
  // Если bank_id уже установлен (сохранённые настройки), считаем что банк выбран
  var bankSelectedFromList =
    $('#bank_id').val() && parseInt($('#bank_id').val(), 10) > 0 ? true : false;
  var bankActiveIndex = -1; // Текущий индекс для клавиатурной навигации

  /**
   * Экранирование HTML-символов для защиты от XSS при вставке в DOM
   * @param {string} str
   * @returns {string}
   */
  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  /**
   * Показать выпадающий список банков
   */
  function showBankDropdown() {
    var $results = $('#bank_search_results');
    var $wrapper = $('.bank-search-wrapper');
    $results.addClass('bank-search-results--visible');
    $wrapper.attr('aria-expanded', 'true');
  }

  /**
   * Скрыть выпадающий список банков
   */
  function hideBankDropdown() {
    var $results = $('#bank_search_results');
    var $wrapper = $('.bank-search-wrapper');
    $results.removeClass('bank-search-results--visible');
    $wrapper.attr('aria-expanded', 'false');
    bankActiveIndex = -1;
    // Снимаем активный выделение
    $results.find('.bank-search-item').removeClass('bank-search-item--active');
  }

  /**
   * Обновить список банков в DOM
   * @param {Array} banks - Массив банков [{id, name}]
   */
  function updateBankList(banks) {
    var $results = $('#bank_search_results');
    $results.empty();
    bankActiveIndex = -1;

    if (banks.length === 0) {
      $results.append(
        '<li class="bank-search-no-results" role="option" aria-disabled="true">Банк не найден</li>',
      );
    } else {
      var selectedBankId = $('#bank_id').val();
      $.each(banks, function (idx, bank) {
        var isSelected = String(bank.id) === String(selectedBankId);
        $results.append(
          '<li class="bank-search-item" role="option" aria-selected="' +
            (isSelected ? 'true' : 'false') +
            '" data-bank-id="' +
            escapeHtml(String(bank.id)) +
            '" data-bank-name="' +
            escapeHtml(bank.name) +
            '" tabindex="-1">' +
            escapeHtml(bank.name) +
            '</li>',
        );
      });
    }
    showBankDropdown();
  }

  /**
   * AJAX поиск банков
   * @param {string} searchTerm
   */
  function searchBanks(searchTerm) {
    $.ajax({
      url: cashback_ajax.ajax_url,
      type: 'POST',
      data: {
        action: 'search_banks',
        search: searchTerm,
        security: cashback_ajax.nonce,
      },
      success: function (response) {
        if (response.success && response.data && response.data.banks) {
          updateBankList(response.data.banks);
        } else {
          updateBankList([]);
        }
      },
      error: function () {
        updateBankList([]);
      },
    });
  }

  /**
   * Выбрать банк из списка
   * @param {jQuery} $item
   */
  function selectBank($item) {
    var bankId = $item.data('bank-id');
    var bankName = $item.data('bank-name');

    $('#bank_id').val(bankId);
    $('#bank_search_input').val(bankName);
    bankSelectedFromList = true;

    // Убрать ошибку, если была
    $('#bank_search_error').text('').hide();
    $('#bank_search_input').removeClass('bank-search-input--error');

    // Обновить aria-selected
    $('#bank_search_results .bank-search-item').attr('aria-selected', 'false');
    $item.attr('aria-selected', 'true');

    hideBankDropdown();
  }

  /**
   * Клавиатурная навигация по списку банков
   * @param {string} direction - 'up' или 'down'
   */
  function navigateBankList(direction) {
    var $items = $('#bank_search_results .bank-search-item');
    if ($items.length === 0) return;

    $items.removeClass('bank-search-item--active');

    if (direction === 'down') {
      bankActiveIndex = bankActiveIndex < $items.length - 1 ? bankActiveIndex + 1 : 0;
    } else if (direction === 'up') {
      bankActiveIndex = bankActiveIndex > 0 ? bankActiveIndex - 1 : $items.length - 1;
    }

    var $active = $items.eq(bankActiveIndex);
    $active.addClass('bank-search-item--active');
    // Прокрутка к активному элементу
    $active[0].scrollIntoView({ block: 'nearest' });
    // Обновляем aria-activedescendant
    var itemId = 'bank-item-' + bankActiveIndex;
    $active.attr('id', itemId);
    $('#bank_search_input').attr('aria-activedescendant', itemId);
  }

  // --- Обработчики событий для поиска банков ---

  // При фокусе на поле ввода — показать начальный список
  $(document).on('focus', '#bank_search_input', function () {
    var $results = $('#bank_search_results');
    if ($results.find('.bank-search-item').length > 0) {
      showBankDropdown();
    } else {
      // Если список пуст, загружаем первые 10
      searchBanks('');
    }
  });

  // При вводе текста — поиск с debounce
  $(document).on('input', '#bank_search_input', function () {
    var searchTerm = $(this).val().trim();

    // Сбрасываем выбор, так как пользователь редактирует
    bankSelectedFromList = false;
    $('#bank_id').val('');

    clearTimeout(bankSearchTimer);
    bankSearchTimer = setTimeout(function () {
      searchBanks(searchTerm);
    }, 300); // Debounce 300ms
  });

  // Клавиатурная навигация
  $(document).on('keydown', '#bank_search_input', function (e) {
    var $results = $('#bank_search_results');
    var isVisible = $results.hasClass('bank-search-results--visible');

    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        if (!isVisible) {
          showBankDropdown();
        }
        navigateBankList('down');
        break;
      case 'ArrowUp':
        e.preventDefault();
        if (isVisible) {
          navigateBankList('up');
        }
        break;
      case 'Enter':
        e.preventDefault();
        if (isVisible && bankActiveIndex >= 0) {
          var $active = $results.find('.bank-search-item').eq(bankActiveIndex);
          if ($active.length) {
            selectBank($active);
          }
        }
        break;
      case 'Escape':
        e.preventDefault();
        hideBankDropdown();
        break;
      case 'Tab':
        hideBankDropdown();
        break;
    }
  });

  // Клик по элементу списка
  $(document).on('click', '#bank_search_results .bank-search-item', function (e) {
    e.preventDefault();
    e.stopPropagation();
    selectBank($(this));
  });

  // Закрытие списка при клике вне компонента
  $(document).on('click', function (e) {
    if (
      !$(e.target).closest('.bank-search-wrapper').length &&
      !$(e.target).is('#bank_search_input')
    ) {
      hideBankDropdown();
    }
  });

  // Hover-эффект для элементов списка
  $(document).on('mouseenter', '#bank_search_results .bank-search-item', function () {
    $('#bank_search_results .bank-search-item').removeClass('bank-search-item--active');
    $(this).addClass('bank-search-item--active');
    bankActiveIndex = $(this).index();
  });

  // ============================================================
  // Конец компонента поиска банков
  // ============================================================

  /**
   * Обработчик кнопки "Изменить данные"
   */
  $(document).on('click', '#edit_payout_settings_btn', function (e) {
    e.preventDefault();
    // iter-32 F-32-002: debug-лог удалён.

    // Скрываем блок с отображением данных
    $('#payout_settings_display').hide();
    // Показываем форму редактирования
    $('#payout_settings_form').removeClass('payout-settings-form-hidden').show();
    // Очищаем поля формы
    $('#payout_method_id').val('').trigger('change');
    $('#payout_account').val('');
    $('#bank_id').val('');
    $('#bank_search_input').val('');
    // Очищаем сообщения
    $('#payout_settings_message').text('').removeClass('success error');
  });

  /**
   * Обработчик кнопки "Отменить" при редактировании
   */
  $(document).on('click', '#cancel_edit_payout_settings_btn', function (e) {
    e.preventDefault();
    // iter-32 F-32-002: debug-лог удалён.

    // Показываем блок с отображением данных
    $('#payout_settings_display').show();
    // Скрываем форму редактирования
    $('#payout_settings_form').addClass('payout-settings-form-hidden').hide();
    // Очищаем сообщения
    $('#payout_settings_message').text('').removeClass('success error');
  });

  /**
   * Обработчик кнопки "Сохранить настройки"
   */
  $(document).on('click', '#save_payout_settings_btn', function (e) {
    e.preventDefault();
    // iter-32 F-32-002: debug-лог удалён (payoutAccount — PII).

    const payoutMethodId = $('#payout_method_id').val();
    const payoutAccount = $('#payout_account').val();
    const bankId = $('#bank_id').val();
    const bankInputVal = $('#bank_search_input').val().trim();
    const nonce = cashback_ajax.nonce;

    // iter-32 F-32-002: payoutAccount / bankId — PII, удалены из console-лога.

    // Сбрасываем подсветку ошибок перед валидацией
    $('#payout_method_id').removeClass('input--error');
    $('#payout_account').removeClass('input--error');
    $('#bank_search_input').removeClass('bank-search-input--error');
    $('#bank_search_error').text('').hide();

    // Валидация
    var hasErrors = false;

    if (!payoutMethodId || payoutMethodId === '' || payoutMethodId === '0') {
      $('#payout_method_id').addClass('input--error');
      $('#payout_settings_message')
        .removeClass('success')
        .addClass('error')
        .text('Пожалуйста, выберите способ вывода');
      hasErrors = true;
    }

    if (!payoutAccount || !payoutAccount.trim()) {
      $('#payout_account').addClass('input--error');
      if (!hasErrors) {
        $('#payout_settings_message')
          .removeClass('success')
          .addClass('error')
          .text('Пожалуйста, введите номер счета или телефона');
      }
      hasErrors = true;
    }

    // Валидация банка: проверяем только если банк обязателен для выбранного способа
    var bankRequired = parseInt($('#payout_method_id option:selected').data('bank-required'), 10);

    if (bankRequired !== 0) {
      if (!bankId || bankId === '' || bankId === '0' || parseInt(bankId, 10) <= 0) {
        $('#bank_search_input').addClass('bank-search-input--error');
        // Если введено название но не выбрано из списка
        if (bankInputVal.length > 0 && !bankSelectedFromList) {
          $('#bank_search_error').text('Вы не выбрали банк из списка').show();
          if (!hasErrors) {
            $('#payout_settings_message')
              .removeClass('success')
              .addClass('error')
              .text('Вы не выбрали банк из списка');
          }
        } else {
          if (!hasErrors) {
            $('#payout_settings_message')
              .removeClass('success')
              .addClass('error')
              .text('Пожалуйста, выберите банк');
          }
        }
        hasErrors = true;
      } else if (bankInputVal.length > 0 && !bankSelectedFromList) {
        // Дополнительная проверка: если bank_id есть, но bankSelectedFromList = false
        // и текст в поле отличается от выбранного — значит пользователь изменил текст после выбора
        $('#bank_search_error').text('Вы не выбрали банк из списка').show();
        $('#bank_search_input').addClass('bank-search-input--error');
        if (!hasErrors) {
          $('#payout_settings_message')
            .removeClass('success')
            .addClass('error')
            .text('Вы не выбрали банк из списка');
        }
        hasErrors = true;
      }
    }

    if (hasErrors) {
      return;
    }

    // Валидация формата номера счёта в зависимости от выбранного способа вывода
    var selectedOption = $('#payout_method_id option:selected');
    var methodSlug = selectedOption.data('slug') || '';

    if (methodSlug === 'sbp') {
      var phoneError = validatePhoneNumber(payoutAccount);
      if (phoneError) {
        $('#payout_account').addClass('input--error');
        $('#payout_settings_message').removeClass('success').addClass('error').text(phoneError);
        return;
      }
    } else if (methodSlug === 'mir') {
      var cardError = validateMirCard(payoutAccount);
      if (cardError) {
        $('#payout_account').addClass('input--error');
        $('#payout_settings_message').removeClass('success').addClass('error').text(cardError);
        return;
      }
    }

    // Очищаем предыдущие сообщения
    $('#payout_settings_message').text('').removeClass('success error');

    // iter-32 F-32-002: отправка AJAX без debug-логирования (URL/body/response/xhr содержат PII и nonce).

    // Юр. чекбокс согласия на обработку платёжных данных (161-ФЗ).
    var settingsData = {
      action: 'save_payout_settings',
      payout_method_id: payoutMethodId,
      payout_account: payoutAccount,
      bank_id: bankRequired === 0 ? 0 : bankId,
      security: nonce,
    };
    var $settingsLegal = $('#payout-settings-form input[name="cashback_legal_payment_pd_consent"]');
    if ($settingsLegal.length) {
      // UX-валидация: при отсутствии отметки — красная рамка + сообщение
      // под чекбоксом + focus, без отправки на сервер. Серверная валидация
      // в Cashback_Legal_Payout_Consent::enforce_or_error остаётся как fallback.
      if (!$settingsLegal.is(':checked') && window.CashbackConsentValidate) {
        var settingsLegalMsg = (typeof cashback_ajax !== 'undefined' && cashback_ajax.legal_payment_pd_required_message)
          ? cashback_ajax.legal_payment_pd_required_message
          : 'Подтвердите согласие на обработку платёжных данных (161-ФЗ).';
        window.CashbackConsentValidate.validateRequired([$settingsLegal[0]], settingsLegalMsg);
        $('#save_payout_settings_btn').prop('disabled', false).text('Сохранить настройки');
        return;
      }
      if (window.CashbackConsentValidate) {
        window.CashbackConsentValidate.bindAutoClear($settingsLegal[0]);
      }
      settingsData.cashback_legal_payment_pd_consent = $settingsLegal.is(':checked') ? '1' : '0';
      settingsData.cashback_legal_payment_pd_request_id = $('#payout-settings-form input[name="cashback_legal_payment_pd_request_id"]').val() || '';
    }

    // Отправляем AJAX-запрос
    $.ajax({
      url: cashback_ajax.ajax_url,
      type: 'POST',
      data: settingsData,
      beforeSend: function () {
        $('#save_payout_settings_btn').prop('disabled', true).text('Сохранение...');
      },
      success: function (response) {
        if (response.success) {
          $('#payout_settings_message')
            .removeClass('error')
            .addClass('success')
            .text(response.data.message);

          // Перезагружаем страницу через 1.5 секунды для отображения обновленных данных
          setTimeout(function () {
            location.reload();
          }, 1500);
        } else {
          $('#payout_settings_message')
            .removeClass('success')
            .addClass('error')
            .text(response.data.message || 'Ошибка при сохранении данных');
        }
      },
      error: function () {
        // iter-32 F-32-002: xhr может содержать response-body с серверными деталями; не логируем.
        $('#payout_settings_message')
          .removeClass('success')
          .addClass('error')
          .text('Ошибка соединения');
      },
      complete: function () {
        $('#save_payout_settings_btn').prop('disabled', false).text('Сохранить настройки');
      },
    });
  });
});
