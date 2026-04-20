/**
 * Admin Affiliate Network — управление параметрами партнерской сети
 * на странице редактирования внешнего товара WooCommerce.
 *
 * @since 2.0.0
 */
jQuery(document).ready(function ($) {
  'use strict';

  function makeRequestId() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0;
      var v = c === 'x' ? r : (r & 0x3 | 0x8);
      return v.toString(16);
    });
  }

  // === Смена сети в выпадающем списке ===
  $(document).on('change', '#_affiliate_network_id', function () {
    var networkId = $(this).val();
    var $container = $('#affiliate-network-params-container');

    // Убираем подсветку ошибки при выборе
    $(this).removeClass('select-error');

    if (!networkId) {
      $container.empty();
      return;
    }

    $container.html(
      '<p style="padding: 5px 12px; color: #666;">Загрузка параметров...</p>',
    );

    $.post(
      wcAffiliateNetworkData.ajaxUrl,
      {
        action: 'get_network_params',
        nonce: wcAffiliateNetworkData.getParamsNonce,
        network_id: networkId,
      },
      function (response) {
        if (response.success) {
          renderParamsTable($container, response.data.params);
        } else {
          $container.html(
            '<p style="padding: 5px 12px; color: #d63638;">Ошибка загрузки параметров.</p>',
          );
        }
      },
    );
  });

  // === Валидация формы при сохранении товара ===
  $('#post').on('submit', function (e) {
    // Проверяем только для внешних товаров
    var productType = $('#product-type').val();
    if (productType !== 'external') {
      return;
    }

    var $networkSelect = $('#_affiliate_network_id');
    if (!$networkSelect.length) {
      return;
    }

    if (!$networkSelect.val()) {
      e.preventDefault();
      $networkSelect.addClass('select-error');

      // Прокручиваем к полю
      $('html, body').animate(
        {
          scrollTop: $networkSelect.offset().top - 100,
        },
        300,
      );

      // Показываем уведомление
      alert('Выберите партнерскую сеть');
    }
  });

  // === Отрисовка таблицы параметров ===
  function renderParamsTable($container, params) {
    if (!params || params.length === 0) {
      $container.html(
        '<p style="padding: 5px 12px; color: #666; font-style: italic;">' +
          'У этой сети нет настроенных параметров.</p>',
      );
      return;
    }

    var html =
      '<table class="affiliate-network-params-table widefat"' +
      ' style="margin: 10px 12px; width: calc(100% - 24px);">' +
      '<thead><tr>' +
      '<th>Параметр</th>' +
      '<th>Значение</th>' +
      '<th style="width:220px;">Действия</th>' +
      '</tr></thead><tbody>';

    for (var i = 0; i < params.length; i++) {
      html +=
        '<tr data-param-id="' +
        params[i].id +
        '">' +
        '<td class="param-cell" data-field="param_name">' +
        escapeHtml(params[i].param_name) +
        '</td>' +
        '<td class="param-cell" data-field="param_type">' +
        escapeHtml(params[i].param_type || '') +
        '</td>' +
        '<td>' +
        '<button type="button" class="button button-small affiliate-param-edit-btn">Редактировать</button> ' +
        '<button type="button" class="button button-small affiliate-param-save-btn" style="display:none;">Сохранить</button> ' +
        '<button type="button" class="button button-small affiliate-param-cancel-btn" style="display:none;">Отмена</button> ' +
        '<button type="button" class="button button-small affiliate-param-delete-btn" style="color:#a00;">Удалить</button>' +
        '</td></tr>';
    }

    html += '</tbody></table>';
    $container.html(html);
  }

  /**
   * Экранирование HTML для безопасной вставки в DOM
   */
  function escapeHtml(text) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(text || ''));
    return div.innerHTML;
  }

  // === Редактирование параметра (inline) ===
  $(document).on('click', '.affiliate-param-edit-btn', function () {
    var $row = $(this).closest('tr');

    // Сохраняем оригинальные значения
    $row.find('.param-cell').each(function () {
      var $cell = $(this);
      var currentValue = $cell.text().trim();
      $cell.data('original-value', currentValue);
      $cell.html(
        '<input type="text" class="param-inline-input" value="' +
          escapeHtml(currentValue) +
          '" style="width:100%;box-sizing:border-box;" />',
      );
    });

    $row.find('.affiliate-param-edit-btn, .affiliate-param-delete-btn').hide();
    $row
      .find('.affiliate-param-save-btn, .affiliate-param-cancel-btn')
      .show();
  });

  // === Отмена редактирования ===
  $(document).on('click', '.affiliate-param-cancel-btn', function () {
    var $row = $(this).closest('tr');

    $row.find('.param-cell').each(function () {
      var $cell = $(this);
      var originalValue = $cell.data('original-value') || '';
      $cell.text(originalValue);
    });

    $row
      .find('.affiliate-param-save-btn, .affiliate-param-cancel-btn')
      .hide();
    $row.find('.affiliate-param-edit-btn, .affiliate-param-delete-btn').show();
  });

  // === Сохранение параметра ===
  $(document).on('click', '.affiliate-param-save-btn', function () {
    var $btn = $(this);
    var $row = $btn.closest('tr');
    var paramId = $row.data('param-id');
    var paramName = $row
      .find('.param-cell[data-field="param_name"] .param-inline-input')
      .val();
    var paramType = $row
      .find('.param-cell[data-field="param_type"] .param-inline-input')
      .val();

    $btn.prop('disabled', true).text('Сохранение...');

    $.post(
      wcAffiliateNetworkData.ajaxUrl,
      {
        action: 'update_network_param',
        nonce: wcAffiliateNetworkData.updateParamNonce,
        param_id: paramId,
        param_name: paramName,
        param_type: paramType,
        request_id: makeRequestId(),
      },
      function (response) {
        if (response.success) {
          $row
            .find('.param-cell[data-field="param_name"]')
            .text(response.data.param_name);
          $row
            .find('.param-cell[data-field="param_type"]')
            .text(response.data.param_type || '');
          $row
            .find('.affiliate-param-save-btn, .affiliate-param-cancel-btn')
            .hide();
          $row
            .find('.affiliate-param-edit-btn, .affiliate-param-delete-btn')
            .show();
        } else {
          alert('Ошибка: ' + (response.data.message || 'Неизвестная ошибка'));
        }
      },
    ).always(function () {
      $btn.prop('disabled', false).text('Сохранить');
    });
  });

  // === Удаление параметра ===
  $(document).on('click', '.affiliate-param-delete-btn', function () {
    if (!confirm('Удалить этот параметр?')) {
      return;
    }

    var $btn = $(this);
    var $row = $btn.closest('tr');
    var paramId = $row.data('param-id');

    $btn.prop('disabled', true);

    $.post(
      wcAffiliateNetworkData.ajaxUrl,
      {
        action: 'delete_network_param',
        nonce: wcAffiliateNetworkData.deleteParamNonce,
        param_id: paramId,
        request_id: makeRequestId(),
      },
      function (response) {
        if (response.success) {
          $row.fadeOut(300, function () {
            $(this).remove();
            // Если параметров не осталось — показываем сообщение
            if (
              $('.affiliate-network-params-table tbody tr').length === 0
            ) {
              $('#affiliate-network-params-container').html(
                '<p style="padding: 5px 12px; color: #666; font-style: italic;">' +
                  'У этой сети нет настроенных параметров.</p>',
              );
            }
          });
        } else {
          alert('Ошибка: ' + (response.data.message || 'Неизвестная ошибка'));
          $btn.prop('disabled', false);
        }
      },
    );
  });

  // === Индивидуальные параметры товара (репитер) ===
  var MAX_PRODUCT_PARAMS = 5;

  $(document).on('click', '#add-product-param-row', function () {
    var $container = $('#affiliate-product-params-rows');
    var rowCount = $container.find('.product-param-row').length;

    if (rowCount >= MAX_PRODUCT_PARAMS) {
      alert(
        'Максимальное количество индивидуальных параметров — ' +
          MAX_PRODUCT_PARAMS,
      );
      return;
    }

    var html =
      '<div class="product-param-row" style="display:flex;gap:10px;margin-bottom:6px;align-items:center;">' +
      '<input type="text" name="affiliate_product_param_key[]" ' +
      'class="regular-text" placeholder="param_key" ' +
      'pattern="[a-zA-Z0-9_\\-]+" title="Только латиница, цифры, _ и -" ' +
      'style="flex:1;" />' +
      '<input type="text" name="affiliate_product_param_value[]" ' +
      'class="regular-text" placeholder="user / uuid / значение" ' +
      'style="flex:1;" />' +
      '<button type="button" class="button button-small remove-product-param-btn" ' +
      'style="color:#a00;min-width:36px;" title="Удалить">&times;</button>' +
      '</div>';

    $container.append(html);

    if ($container.find('.product-param-row').length >= MAX_PRODUCT_PARAMS) {
      $('#add-product-param-row').prop('disabled', true);
    }
  });

  $(document).on('click', '.remove-product-param-btn', function () {
    $(this).closest('.product-param-row').remove();

    if (
      $('#affiliate-product-params-rows .product-param-row').length <
      MAX_PRODUCT_PARAMS
    ) {
      $('#add-product-param-row').prop('disabled', false);
    }
  });
});
