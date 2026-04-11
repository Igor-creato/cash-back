/**
 * Admin Payouts Management JavaScript
 *
 * @package WP_Cashback_Plugin
 */

(function ($) {
  'use strict';

  /**
   * Описания статусов выплат
   */
  const statusDescriptions = {
    waiting: 'Платеж еще не обрабатывался',
    processing: 'Платеж осуществляется',
    paid: 'Платеж выплачен',
    failed:
      'Выплату невозможно осуществить по каким либо причинам и она возвращена в доступный баланс',
    declined: 'Выплата заморожена из-за мошенничества',
    needs_retry: 'Выплата не прошла, попробовать повторить выплату',
  };

  /**
   * Метки статусов для отображения
   */
  const statusLabels = {
    waiting: 'Не выплачен',
    processing: 'В обработке',
    paid: 'Выплачен',
    failed: 'Возврат в доступный баланс',
    declined: 'Выплата заморожена',
    needs_retry: 'Проверить выплату',
  };

  /**
   * Экранирование HTML для предотвращения XSS
   *
   * @param {string} text - Текст для экранирования
   * @returns {string} Экранированный текст
   */
  function escapeHtml(text) {
    const map = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;',
    };
    return String(text).replace(/[&<>"']/g, (m) => map[m]);
  }

  /**
   * Инициализация при загрузке DOM
   */
  $(document).ready(function () {
    console.log('Cashback Payouts Admin JS loaded');
    console.log('Edit buttons found:', $('.edit-btn').length);

    initFilters();
    initEditButtons();
    initCancelButtons();
    initSaveButtons();
    initDecryptButtons();
    initVerifyButtons();
  });

  /**
   * Инициализация фильтров
   */
  function initFilters() {
    // Обработка фильтра
    $('#filter-submit').on('click', function () {
      const status = $('#filter-status').val();
      const dateFrom = $('#filter-date-from').val();
      const dateTo = $('#filter-date-to').val();

      const url = new URL(window.location);

      if (status) {
        url.searchParams.set('status', status);
      } else {
        url.searchParams.delete('status');
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

      const reference = $('#filter-reference').val();
      if (reference) {
        url.searchParams.set('reference', reference);
      } else {
        url.searchParams.delete('reference');
      }

      url.searchParams.delete('paged');
      window.location.href = url.toString();
    });

    // Сброс фильтров
    $('#filter-reset').on('click', function () {
      const url = new URL(window.location);
      url.searchParams.delete('status');
      url.searchParams.delete('date_from');
      url.searchParams.delete('date_to');
      url.searchParams.delete('reference');
      url.searchParams.delete('paged');
      window.location.href = url.toString();
    });
  }

  /**
   * Инициализация кнопок редактирования
   */
  function initEditButtons() {
    $('.edit-btn').on('click', function () {
      const row = $(this).closest('tr');
      const cells = row.find('.edit-field');
      const payoutId = row.data('payout-id');

      // Получаем текущие данные о выплате из базы
      loadPayoutData(payoutId, function (error, payoutData) {
        if (error) {
          console.error('Ошибка загрузки данных выплаты:', error);
          alert('Ошибка загрузки данных выплаты: ' + error);
          return;
        }

        cells.each(function () {
          const cell = $(this);
          const field = cell.data('field');
          const currentValue = cell.text().trim();

          cell.css('min-width', cell.width() + 'px');

          // Поле provider (банк) не редактируется
          if (field === 'provider') {
            return; // Пропускаем это поле, оставляем только для отображения
          } else if (field === 'status') {
            createStatusSelect(cell, currentValue);
          } else if (field === 'attempts') {
            createNumberInput(cell, field, currentValue);
          } else {
            createTextInput(cell, field, currentValue);
          }
        });

        row.find('.edit-btn').hide();
        row.find('.save-btn, .cancel-btn').show();
      });
    });
  }

  /**
   * Создание select для статуса
   *
   * @param {jQuery} cell - Ячейка таблицы
   * @param {string} currentValue - Текущее значение
   */
  function createStatusSelect(cell, currentValue) {
    const select = $('<select>').addClass('edit-input').attr('data-field', 'status').css({
      width: '100%',
      'box-sizing': 'border-box',
    });

    const statuses = ['waiting', 'processing', 'paid', 'failed', 'declined', 'needs_retry'];

    statuses.forEach(function (status) {
      const option = $('<option>').val(status).text(statusLabels[status]);

      if (currentValue === status || statusLabels[status] === currentValue) {
        option.prop('selected', true);
      }

      select.append(option);
    });

    cell.empty().append(select);
  }

  /**
   * Создание числового input
   *
   * @param {jQuery} cell - Ячейка таблицы
   * @param {string} field - Имя поля
   * @param {string} currentValue - Текущее значение
   */
  function createNumberInput(cell, field, currentValue) {
    const input = $('<input>')
      .attr('type', 'number')
      .attr('min', '0')
      .addClass('edit-input regular-text')
      .attr('data-field', field)
      .val(currentValue)
      .css({
        width: '100%',
        'box-sizing': 'border-box',
      });

    cell.empty().append(input);
  }

  /**
   * Создание текстового input
   *
   * @param {jQuery} cell - Ячейка таблицы
   * @param {string} field - Имя поля
   * @param {string} currentValue - Текущее значение
   */
  function createTextInput(cell, field, currentValue) {
    const input = $('<input>')
      .attr('type', 'text')
      .addClass('edit-input regular-text')
      .attr('data-field', field)
      .val(currentValue)
      .css({
        width: '100%',
        'box-sizing': 'border-box',
      });

    cell.empty().append(input);
  }

  /**
   * Инициализация кнопок отмены
   */
  function initCancelButtons() {
    $('.cancel-btn').on('click', function () {
      const row = $(this).closest('tr');
      resetRowToViewMode(row);
    });
  }

  /**
   * Инициализация кнопок сохранения
   */
  function initSaveButtons() {
    $('.save-btn').on('click', function () {
      const row = $(this).closest('tr');
      const payoutId = row.data('payout-id');
      const originalValues = {};
      const changedData = {};

      // Сохраняем оригинальные значения
      row.find('.edit-field').each(function () {
        const cell = $(this);
        const field = cell.data('field');
        originalValues[field] = cell.text().trim();
      });

      // Собираем измененные данные
      row.find('.edit-input').each(function () {
        const input = $(this);
        const field = input.data('field');
        const newValue = input.val();
        const originalValue = originalValues[field];

        if (originalValue !== newValue) {
          changedData[field] = newValue;
        }
      });

      if (Object.keys(changedData).length === 0) {
        alert('Нет изменений для сохранения.');
        row.find('.save-btn, .cancel-btn').hide();
        row.find('.edit-btn').show();
        return;
      }

      // Валидация
      if (changedData.hasOwnProperty('attempts')) {
        const attempts = parseInt(changedData['attempts']);
        if (isNaN(attempts) || attempts < 0) {
          alert('Количество попыток должно быть неотрицательным числом');
          return;
        }
      }

      if (changedData.hasOwnProperty('status')) {
        const status = changedData['status'];
        const allowedStatuses = [
          'waiting',
          'processing',
          'paid',
          'failed',
          'declined',
          'needs_retry',
        ];
        if (allowedStatuses.indexOf(status) === -1) {
          alert('Недопустимый статус выплаты');
          return;
        }
      }

      // Отправка AJAX запроса
      const data = {
        action: 'update_payout_request',
        payout_id: payoutId,
        nonce: cashbackPayoutsData.updateNonce,
        ...changedData,
      };

      $.post(ajaxurl, data, function (response) {
        if (response.success) {
          updateRowData(row, response.data.payout_data);
          showSuccessNotice('Запрос на выплату успешно обновлен.');

          row.find('.save-btn, .cancel-btn').hide();
          row.find('.edit-btn').show();
        } else {
          alert(
            'Ошибка при обновлении запроса на выплату: ' +
              (response.data.message || 'Неизвестная ошибка'),
          );
        }
      }).fail(function (jqXHR, textStatus, errorThrown) {
        const status = jqXHR.status || 0;
        let errorMsg = 'Ошибка соединения при обновлении запроса на выплату';

        if (status === 403) {
          errorMsg = 'Ошибка 403: Доступ запрещён. Возможно, сессия истекла. Обновите страницу.';
        } else if (status === 500) {
          errorMsg = 'Ошибка 500: Внутренняя ошибка сервера. Обратитесь к администратору.';
        } else if (status === 0) {
          errorMsg = 'Нет соединения с сервером. Проверьте подключение к интернету.';
        } else if (textStatus === 'timeout') {
          errorMsg = 'Превышено время ожидания ответа от сервера.';
        } else {
          errorMsg = 'Ошибка HTTP ' + status + ': ' + (errorThrown || textStatus);
        }

        alert(errorMsg);
      });
    });
  }

  /**
   * Обновление данных строки
   *
   * @param {jQuery} row - Строка таблицы
   * @param {Object} payoutData - Данные выплаты
   */
  function updateRowData(row, payoutData) {
    // Обновляем поле банка: находим название банка по коду
    const bankCode = payoutData.provider || '';
    const bankName = getBankNameByCode(bankCode);
    row.find('.edit-field[data-field="provider"]').text(bankName);

    row
      .find('.edit-field[data-field="provider_payout_id"]')
      .text(payoutData.provider_payout_id || '');
    row.find('.edit-field[data-field="attempts"]').text(payoutData.attempts);
    row.find('.edit-field[data-field="fail_reason"]').text(payoutData.fail_reason || '');

    const statusText = payoutData.status;
    const statusLabel = statusLabels[statusText] || statusText;
    const statusDescription = statusDescriptions[statusText] || statusText;

    row.find('.edit-field[data-field="status"]').text(statusLabel).attr('title', statusDescription);

    // Обновляем кнопку расшифровки в зависимости от статуса
    updateDecryptButton(row, payoutData);

    // Обновляем кнопку "Просмотр" в зависимости от статуса
    updateViewButton(row, payoutData);

    row.find('.edit-field').css('min-width', '');
  }

  /**
   * Обновление кнопки расшифровки
   *
   * @param {jQuery} row - Строка таблицы
   * @param {Object} payoutData - Данные выплаты
   */
  function updateDecryptButton(row, payoutData) {
    const payoutAccountCell = row.find('.payout-account-cell');
    const existingBtn = payoutAccountCell.find('.decrypt-btn');

    // Кнопка должна отображаться только если статус 'processing' и есть зашифрованные данные
    const shouldShowButton = payoutData.status === 'processing' && payoutData.has_encrypted_data;

    if (shouldShowButton && existingBtn.length === 0) {
      // Добавляем кнопку, если её нет
      const decryptBtn = $('<button>')
        .attr('type', 'button')
        .addClass('button button-small decrypt-btn')
        .attr('title', 'Показать реквизиты')
        .html('&#128065;');

      payoutAccountCell.append(decryptBtn);
    } else if (!shouldShowButton && existingBtn.length > 0) {
      // Удаляем кнопку, если она не должна отображаться
      existingBtn.remove();
    }
  }

  /**
   * Обновление кнопки "Просмотр"
   *
   * @param {jQuery} row - Строка таблицы
   * @param {Object} payoutData - Данные выплаты
   */
  function updateViewButton(row, payoutData) {
    const existingBtn = row.find('.view-btn');
    const shouldShow = payoutData.status === 'processing';

    if (shouldShow && existingBtn.length === 0) {
      const payoutId = row.data('payout-id');
      const viewBtn = $('<a>')
        .addClass('button button-primary view-btn')
        .attr('href', 'admin.php?page=cashback-payouts&action=view&payout_id=' + payoutId)
        .text('Просмотр');
      row.find('.edit-btn').before(viewBtn).before(' ');
    } else if (!shouldShow && existingBtn.length > 0) {
      existingBtn.remove();
    }
  }

  /**
   * Получить название банка по коду
   *
   * @param {string} bankCode - Код банка
   * @returns {string} Название банка
   */
  function getBankNameByCode(bankCode) {
    if (!bankCode) {
      return '';
    }

    const banks = cashbackPayoutsData.banks || [];
    const bank = banks.find((b) => b.bank_code === bankCode);

    return bank ? bank.name : bankCode;
  }

  /**
   * Показать уведомление об успехе
   *
   * @param {string} message - Сообщение
   */
  function showSuccessNotice(message) {
    const notice = $('<div>')
      .addClass('notice notice-success is-dismissible')
      .append($('<p>').text(message));

    $('.wp-header-end').after(notice);

    setTimeout(function () {
      notice.fadeOut(function () {
        $(this).remove();
      });
    }, 3000);
  }

  /**
   * Загрузка данных выплаты из базы
   *
   * @param {number} payoutId - ID выплаты
   * @param {Function} callback - Функция обратного вызова
   */
  function loadPayoutData(payoutId, callback) {
    const data = {
      action: 'get_payout_request',
      payout_id: payoutId,
      nonce: cashbackPayoutsData.getNonce,
    };

    $.post(ajaxurl, data, function (response) {
      if (response.success) {
        callback(null, response.data);
      } else {
        callback(response.data.message || 'Ошибка при загрузке данных выплаты', null);
      }
    }).fail(function (jqXHR, textStatus, errorThrown) {
      const status = jqXHR.status || 0;
      let errorMsg = 'Ошибка соединения при загрузке данных выплаты';

      if (status === 403) {
        errorMsg = 'Ошибка 403: Доступ запрещён. Обновите страницу.';
      } else if (status === 500) {
        errorMsg = 'Ошибка 500: Внутренняя ошибка сервера.';
      } else if (status === 0) {
        errorMsg = 'Нет соединения с сервером.';
      } else if (textStatus === 'timeout') {
        errorMsg = 'Превышено время ожидания ответа.';
      } else {
        errorMsg = 'Ошибка HTTP ' + status + ': ' + (errorThrown || textStatus);
      }

      callback(errorMsg, null);
    });
  }

  /**
   * Сброс строки к режиму просмотра
   *
   * @param {jQuery} row - Строка таблицы
   */
  function resetRowToViewMode(row) {
    const payoutId = row.data('payout-id');

    loadPayoutData(payoutId, function (error, payoutData) {
      if (error) {
        console.error('Ошибка загрузки данных выплаты:', error);
        alert('Ошибка загрузки данных выплаты: ' + error);
        return;
      }

      updateRowData(row, payoutData);

      row.find('.save-btn, .cancel-btn').hide();
      row.find('.edit-btn').show();
    });
  }

  /**
   * Инициализация кнопок проверки баланса
   */
  function initVerifyButtons() {
    $(document).on('click', '.verify-btn', function () {
      const btn = $(this);
      const row = btn.closest('tr');
      const payoutId = row.data('payout-id');

      if (!payoutId) {
        return;
      }

      // Блокируем кнопку
      const originalText = btn.text();
      btn.prop('disabled', true).text('...');

      const data = {
        action: 'verify_payout_balance',
        payout_id: payoutId,
        nonce: cashbackPayoutsData.verifyNonce,
      };

      $.post(ajaxurl, data, function (response) {
        btn.prop('disabled', false).text(originalText);

        if (response.success) {
          const result = response.data;
          if (result.status === 'ok') {
            // Успех — зелёное уведомление
            showVerifyResult(row, 'ok', result.message);
          } else {
            // Расхождение — красное уведомление с деталями
            let msg = result.message;
            if (result.issues && result.issues.length > 0) {
              msg += ':\n• ' + result.issues.join('\n• ');
            }
            showVerifyResult(row, 'mismatch', msg);
          }
        } else {
          // Ошибка (sync в процессе, нет прав и т.д.)
          alert(response.data.message || 'Ошибка проверки');
        }
      }).fail(function (jqXHR) {
        btn.prop('disabled', false).text(originalText);
        let errorMsg = 'Ошибка соединения';
        if (jqXHR.status === 403) {
          errorMsg = 'Ошибка 403: Доступ запрещён. Обновите страницу.';
        } else if (jqXHR.status === 500) {
          errorMsg = 'Ошибка 500: Внутренняя ошибка сервера.';
        }
        alert(errorMsg);
      });
    });
  }

  /**
   * Показать результат проверки рядом со строкой
   *
   * @param {jQuery} row - Строка таблицы
   * @param {string} status - 'ok' или 'mismatch'
   * @param {string} message - Текст сообщения
   */
  function showVerifyResult(row, status, message) {
    // Удаляем предыдущий результат
    row.find('.verify-result').remove();

    const badge = $('<span>')
      .addClass('verify-result')
      .css({
        display: 'inline-block',
        padding: '2px 8px',
        'margin-left': '4px',
        'border-radius': '3px',
        'font-size': '12px',
        'font-weight': 'bold',
        color: '#fff',
        'background-color': status === 'ok' ? '#46b450' : '#dc3232',
        cursor: status === 'mismatch' ? 'pointer' : 'default',
      })
      .text(status === 'ok' ? 'OK' : 'Расхождение');

    if (status === 'mismatch') {
      badge.attr('title', message);
      badge.on('click', function () {
        alert(message);
      });
    }

    row.find('.verify-btn').after(badge);

    // Автоскрытие через 15 секунд
    setTimeout(function () {
      badge.fadeOut(function () {
        $(this).remove();
      });
    }, 15000);
  }

  /**
   * Инициализация кнопок расшифровки реквизитов
   */
  function initDecryptButtons() {
    $(document).on('click', '.decrypt-btn', function () {
      const btn = $(this);
      const cell = btn.closest('.payout-account-cell');
      const payoutId = cell.data('payout-id');

      if (!payoutId) {
        return;
      }

      // Блокируем кнопку на время запроса
      btn.prop('disabled', true).text('...');

      const data = {
        action: 'decrypt_payout_details',
        payout_id: payoutId,
        nonce: cashbackPayoutsData.decryptNonce,
      };

      $.post(ajaxurl, data, function (response) {
        if (response.success) {
          const details = response.data;
          let displayText = escapeHtml(details.account || '');
          if (details.full_name) {
            displayText += ' (' + escapeHtml(details.full_name) + ')';
          }

          // Показываем расшифрованные данные
          cell.find('.masked-account').hide();
          cell.find('.decrypted-account').html(displayText).show();
          btn.hide();

          // Автоскрытие через 30 секунд
          setTimeout(function () {
            cell.find('.decrypted-account').hide();
            cell.find('.masked-account').show();
            btn.show().prop('disabled', false).html('&#128065;');
          }, 30000);
        } else {
          alert(response.data.message || 'Ошибка расшифровки');
          btn.prop('disabled', false).html('&#128065;');
        }
      }).fail(function (jqXHR, textStatus) {
        let errorMsg = 'Ошибка соединения';
        if (jqXHR.status === 403) {
          errorMsg = 'Ошибка 403: Доступ запрещён. Обновите страницу.';
        } else if (jqXHR.status === 500) {
          errorMsg = 'Ошибка 500: Внутренняя ошибка сервера.';
        }
        alert(errorMsg);
        btn.prop('disabled', false).html('&#128065;');
      });
    });
  }
})(jQuery);
