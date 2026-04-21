/**
 * Admin Payout Detail Page JavaScript
 *
 * @package WP_Cashback_Plugin
 */

(function ($) {
  'use strict';

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
   * @param {string} text
   * @returns {string}
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
   * Копирование текста в буфер обмена
   *
   * @param {string} text
   * @param {jQuery} btn
   */
  function copyToClipboard(text, btn) {
    if (!text) {
      return;
    }

    const originalHtml = btn.html();

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard
        .writeText(text)
        .then(function () {
          showCopySuccess(btn, originalHtml);
        })
        .catch(function () {
          fallbackCopy(text, btn, originalHtml);
        });
    } else {
      fallbackCopy(text, btn, originalHtml);
    }
  }

  /**
   * Fallback копирование через textarea
   *
   * @param {string} text
   * @param {jQuery} btn
   * @param {string} originalHtml
   */
  function fallbackCopy(text, btn, originalHtml) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();

    try {
      document.execCommand('copy');
      showCopySuccess(btn, originalHtml);
    } catch (e) {
      console.error('Copy failed:', e);
    }

    document.body.removeChild(textarea);
  }

  /**
   * Показать визуальную обратную связь при копировании
   *
   * @param {jQuery} btn
   * @param {string} originalHtml
   */
  function showCopySuccess(btn, originalHtml) {
    btn.html('&#10003;').addClass('copied');
    setTimeout(function () {
      btn.html(originalHtml).removeClass('copied');
    }, 1500);
  }

  /**
   * Показать уведомление
   *
   * @param {string} message
   * @param {string} type - 'success' или 'error'
   */
  function showNotice(message, type) {
    const notice = $('<div>')
      .addClass('notice notice-' + type + ' is-dismissible')
      .append($('<p>').text(message));

    $('#payout-detail-notices').empty().append(notice);

    if (type === 'success') {
      setTimeout(function () {
        notice.fadeOut(function () {
          $(this).remove();
        });
      }, 4000);
    }
  }

  /**
   * Инициализация при загрузке DOM
   */
  $(document).ready(function () {
    initCopyButtons();
    initDecryptButton();
    initSaveButton();
    initVerifyDetailButton();
  });

  /**
   * Инициализация кнопок копирования
   */
  function initCopyButtons() {
    $(document).on('click', '.copy-btn', function () {
      const btn = $(this);
      const text = btn.data('copy');

      if (text !== undefined && text !== '') {
        copyToClipboard(String(text), btn);
      }
    });
  }

  /**
   * Инициализация кнопки расшифровки
   */
  function initDecryptButton() {
    $(document).on('click', '.decrypt-detail-btn', function () {
      const btn = $(this);
      const cell = btn.closest('.payout-account-detail-cell');
      const payoutId = cell.data('payout-id');

      if (!payoutId) {
        return;
      }

      // Блокируем кнопку
      btn.prop('disabled', true).text('...');

      const data = {
        action: 'decrypt_payout_details',
        payout_id: payoutId,
        nonce: cashbackPayoutDetailData.decryptNonce,
      };

      $.post(cashbackPayoutDetailData.ajaxurl, data, function (response) {
        if (response.success) {
          const details = response.data;

          // Показываем расшифрованный номер счета
          const accountText = details.account || '';
          cell.find('.masked-account').hide();
          cell.find('.decrypted-account').text(accountText).show();

          // Показываем кнопку копирования счета
          if (accountText) {
            const copyBtnSpan = cell.find('.decrypted-account-copy-btn');
            copyBtnSpan.find('.copy-btn').data('copy', accountText);
            copyBtnSpan.show();
          }

          // Показываем ФИО
          if (details.full_name) {
            const fullNameRow = $('.full-name-row');
            fullNameRow.find('.decrypted-full-name').text(details.full_name);
            fullNameRow.find('.full-name-copy-btn').data('copy', details.full_name).show();
            fullNameRow.show();
          }

          // Скрываем кнопку глаза
          btn.hide();
        } else {
          alert(response.data.message || 'Ошибка расшифровки');
          btn.prop('disabled', false).html('&#128065;');
        }
      }).fail(function (jqXHR) {
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

  /**
   * Инициализация кнопки сохранения
   */
  function initSaveButton() {
    $('#save-detail-btn').on('click', function () {
      const btn = $(this);
      const payoutId = btn.data('payout-id');
      const changedData = {};

      // Собираем изменённые поля
      const originalStatus = btn.data('original-status');
      const originalProviderPayoutId = String(btn.data('original-provider-payout-id') || '');
      const originalAttempts = String(btn.data('original-attempts'));
      const originalFailReason = String(btn.data('original-fail-reason') || '');

      const newStatus = $('#detail-status').val();
      const newProviderPayoutId = $('#detail-provider-payout-id').val().trim();
      const newAttempts = $('#detail-attempts').val();
      const newFailReason = $('#detail-fail-reason').val().trim();

      if (newStatus && newStatus !== originalStatus) {
        changedData.status = newStatus;
      }
      if (newProviderPayoutId !== originalProviderPayoutId) {
        changedData.provider_payout_id = newProviderPayoutId;
      }
      if (newAttempts !== originalAttempts) {
        changedData.attempts = newAttempts;
      }
      if (newFailReason !== originalFailReason) {
        changedData.fail_reason = newFailReason;
      }

      if (Object.keys(changedData).length === 0) {
        showNotice('Нет изменений для сохранения.', 'error');
        return;
      }

      // Валидация
      if (changedData.hasOwnProperty('attempts')) {
        const attempts = parseInt(changedData.attempts);
        if (isNaN(attempts) || attempts < 0) {
          alert('Количество попыток должно быть неотрицательным числом');
          return;
        }
      }

      // Блокируем кнопку
      btn.prop('disabled', true).text('Сохранение...');

      // Группа 5 ADR: генерируем request_id per-submit-клик. При сетевом retry
      // клиент пошлёт тот же UUID, и сервер вернёт прежний ответ вместо повторного
      // UPDATE. Сбрасывается при success, чтобы следующий клик получил свежий id.
      let requestId = btn.data('cb-request-id');
      if (!requestId) {
        requestId = (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function')
          ? crypto.randomUUID()
          : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = (Math.random() * 16) | 0;
            return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
          });
        btn.data('cb-request-id', requestId);
      }

      const postData = {
        action: 'update_payout_request',
        payout_id: payoutId,
        nonce: cashbackPayoutDetailData.updateNonce,
        request_id: requestId,
        ...changedData,
      };

      $.post(cashbackPayoutDetailData.ajaxurl, postData, function (response) {
        if (response.success) {
          showNotice('Заявка успешно обновлена.', 'success');

          const payoutData = response.data.payout_data;

          // Обновляем оригинальные значения на кнопке
          if (payoutData.status) {
            btn.data('original-status', payoutData.status);

            // Обновляем отображение статуса
            const statusLabel = statusLabels[payoutData.status] || payoutData.status;
            $('.detail-status-label strong').text(statusLabel);

            // Если статус финальный, перезагружаем страницу для обновления формы
            if (['paid', 'failed', 'declined'].indexOf(payoutData.status) !== -1) {
              setTimeout(function () {
                window.location.reload();
              }, 1500);
              return;
            }

            // Обновляем select статуса
            if ($('#detail-status').length) {
              $('#detail-status option:first').val(payoutData.status).text(
                statusLabel + ' (текущий)',
              );
            }
          }

          btn.data('original-provider-payout-id', payoutData.provider_payout_id || '');
          btn.data('original-attempts', payoutData.attempts);
          btn.data('original-fail-reason', payoutData.fail_reason || '');

          // Группа 5: сбрасываем request_id — следующий клик получит свежий UUID.
          btn.removeData('cb-request-id');
        } else {
          showNotice(
            'Ошибка: ' + (response.data.message || 'Неизвестная ошибка'),
            'error',
          );
        }
      })
        .fail(function (jqXHR, textStatus, errorThrown) {
          const status = jqXHR.status || 0;
          let errorMsg = 'Ошибка соединения при сохранении';

          if (status === 403) {
            errorMsg = 'Ошибка 403: Доступ запрещён. Обновите страницу.';
          } else if (status === 500) {
            errorMsg = 'Ошибка 500: Внутренняя ошибка сервера.';
          } else if (status === 0) {
            errorMsg = 'Нет соединения с сервером.';
          } else if (textStatus === 'timeout') {
            errorMsg = 'Превышено время ожидания.';
          } else {
            errorMsg = 'Ошибка HTTP ' + status + ': ' + (errorThrown || textStatus);
          }

          showNotice(errorMsg, 'error');
        })
        .always(function () {
          btn.prop('disabled', false).text('Сохранить изменения');
        });
    });
  }
  /**
   * Форматирование суммы с пробелами
   *
   * @param {string} value
   * @returns {string}
   */
  function formatAmount(value) {
    const raw = String(value == null ? '' : value).trim().replace(',', '.');
    const match = /^(-?)(\d+)(?:\.(\d{1,2}))?$/.exec(raw);
    if (!match) {
      return '0.00 ₽';
    }
    const whole = match[2].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    const frac = (match[3] || '').padEnd(2, '0').slice(0, 2);
    return match[1] + whole + '.' + frac + ' ₽';
  }

  function safeInt(value) {
    const raw = String(value == null ? '' : value).trim();
    return /^-?\d+$/.test(raw) ? raw : '0';
  }

  /**
   * Построение HTML отчёта о проверке движений средств
   *
   * @param {Object} result
   * @returns {string}
   */
  function buildVerifyReport(result) {
    const isOk = result.status === 'ok';
    const borderColor = isOk ? '#46b450' : '#dc3232';
    const icon = isOk ? '&#10004;' : '&#10006;';

    const cellStyle = 'padding:5px 10px; white-space:nowrap;';

    let html = '<div style="display:inline-block; border:1px solid ' + borderColor + '; border-radius:4px; font-size:13px; max-width:100%;">';

    // Заголовок
    html += '<div style="background:' + borderColor + '; color:#fff; padding:8px 12px; font-weight:bold; white-space:nowrap;">';
    html += '<span style="margin-right:6px;">' + icon + '</span>';
    html += escapeHtml(result.message);
    html += '</div>';

    // Сводка по балансу
    if (result.balance_summary) {
      html += '<div style="padding:10px 12px; border-bottom:1px solid #e5e5e5;">';
      html += '<strong>Баланс пользователя:</strong>';
      html += '<table style="margin-top:6px; border-collapse:collapse; font-size:12px;">';
      html += '<tr style="background:#f9f9f9;">';
      html += '<th style="text-align:left; ' + cellStyle + '"></th>';
      html += '<th style="text-align:right; ' + cellStyle + '">Расчётный</th>';
      html += '<th style="text-align:right; ' + cellStyle + '">В базе</th>';
      html += '<th style="text-align:center; ' + cellStyle + '"></th></tr>';

      const fields = ['available', 'pending', 'paid', 'frozen'];
      for (let i = 0; i < fields.length; i++) {
        const key = fields[i];
        const item = result.balance_summary[key];
        if (!item) {
          continue;
        }

        const ledgerVal = item.ledger;
        const cacheVal = item.cache;
        const match = ledgerVal === '—' || ledgerVal === cacheVal;
        const rowColor = match ? '' : ' background:#fff3f3;';
        const statusIcon = ledgerVal === '—' ? '' : (match ? '<span style="color:#46b450;">&#10004;</span>' : '<span style="color:#dc3232;">&#10006;</span>');

        html += '<tr style="border-top:1px solid #eee;' + rowColor + '">';
        html += '<td style="' + cellStyle + '">' + escapeHtml(item.label) + '</td>';
        html += '<td style="text-align:right; ' + cellStyle + '">' + (ledgerVal === '—' ? '—' : formatAmount(ledgerVal)) + '</td>';
        html += '<td style="text-align:right; ' + cellStyle + '">' + formatAmount(cacheVal) + '</td>';
        html += '<td style="text-align:center; ' + cellStyle + '">' + statusIcon + '</td>';
        html += '</tr>';
      }

      html += '</table></div>';
    }

    // Операции
    if (result.operations_summary && result.operations_summary.length > 0) {
      html += '<div style="padding:10px 12px; border-bottom:1px solid #e5e5e5;">';
      html += '<strong>Операции в журнале:</strong>';
      html += '<table style="margin-top:6px; border-collapse:collapse; font-size:12px;">';

      for (let i = 0; i < result.operations_summary.length; i++) {
        const op = result.operations_summary[i];
        html += '<tr style="border-top:1px solid #eee;">';
        html += '<td style="' + cellStyle + '">' + escapeHtml(op.label) + '</td>';
        html += '<td style="text-align:center; ' + cellStyle + ' color:#666;">' + safeInt(op.count) + ' шт.</td>';
        html += '<td style="text-align:right; ' + cellStyle + ' font-weight:500;">' + escapeHtml(op.sum) + ' ₽</td>';
        html += '</tr>';
      }

      html += '</table></div>';
    }

    // Проблемы (если есть)
    if (!isOk && result.issues && result.issues.length > 0) {
      html += '<div style="padding:10px 12px; background:#fff8f8;">';
      html += '<strong style="color:#dc3232;">Обнаруженные проблемы:</strong>';
      html += '<ul style="margin:6px 0 0 0; padding-left:18px; color:#72150a;">';
      for (let i = 0; i < result.issues.length; i++) {
        html += '<li style="margin-bottom:4px; white-space:nowrap;">' + escapeHtml(result.issues[i]) + '</li>';
      }
      html += '</ul></div>';
    }

    html += '</div>';
    return html;
  }

  /**
   * Инициализация кнопки проверки движений средств
   */
  function initVerifyDetailButton() {
    $('#verify-detail-btn').on('click', function () {
      const btn = $(this);
      const payoutId = btn.data('payout-id');

      if (!payoutId) {
        return;
      }

      const originalText = btn.text();
      btn.prop('disabled', true).text('Проверка...');

      const resultContainer = $('#verify-detail-result');
      resultContainer.hide().empty();

      const data = {
        action: 'verify_payout_balance',
        payout_id: payoutId,
        nonce: cashbackPayoutDetailData.verifyNonce,
      };

      $.post(cashbackPayoutDetailData.ajaxurl, data, function (response) {
        btn.prop('disabled', false).text(originalText);

        if (response.success) {
          const reportHtml = buildVerifyReport(response.data);
          resultContainer.html(reportHtml).show();
        } else {
          showNotice(response.data.message || 'Ошибка проверки', 'error');
        }
      }).fail(function (jqXHR) {
        btn.prop('disabled', false).text(originalText);
        let errorMsg = 'Ошибка соединения';
        if (jqXHR.status === 403) {
          errorMsg = 'Ошибка 403: Доступ запрещён. Обновите страницу.';
        } else if (jqXHR.status === 500) {
          errorMsg = 'Ошибка 500: Внутренняя ошибка сервера.';
        }
        showNotice(errorMsg, 'error');
      });
    });
  }
})(jQuery);
