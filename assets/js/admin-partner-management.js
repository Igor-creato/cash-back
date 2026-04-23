jQuery(document).ready(function($) {

    var MAX_PARAMS = 10;

    // 12i-5 ADR (F-10-001): UUID v4 для client-side идемпотентности admin-AJAX.
    function makeRequestId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            var r = Math.random() * 16 | 0;
            var v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    // 12i-5 ADR (F-10-001): human-readable labels для click_session_policy.
    var POLICY_LABELS = {
        'reuse_in_window':    'Переиспользовать в окне',
        'always_new':         'Всегда новый клик',
        'reuse_per_product':  'Переиспользовать по товару'
    };

    // =============================
    // Вкладка "Партнеры" — inline-редактирование
    // =============================

    // Обработка клика по кнопке "Редактировать"
    $('.edit-btn').on('click', function() {
        var row = $(this).closest('tr');
        var cells = row.find('.edit-field');

        cells.each(function() {
            var cell = $(this);
            var field = cell.data('field');
            var currentValue = cell.text().trim();

            if (field === 'is_active') {
                var selectHtml = '<select class="edit-input" data-field="' + field + '">';
                selectHtml += '<option value="1"' + (currentValue === 'Да' ? ' selected' : '') + '>Да</option>';
                selectHtml += '<option value="0"' + (currentValue === 'Нет' ? ' selected' : '') + '>Нет</option>';
                selectHtml += '</select>';
                cell.html(selectHtml);
            } else if (field === 'click_session_policy') {
                // 12i-5 ADR: policy dropdown с 3 options. data-value на cell хранит raw slug.
                var currentSlug = cell.data('value') || 'reuse_in_window';
                var policySelect = '<select class="edit-input" data-field="' + field + '">';
                policySelect += '<option value="reuse_in_window"' + (currentSlug === 'reuse_in_window' ? ' selected' : '') + '>Переиспользовать в окне</option>';
                policySelect += '<option value="always_new"' + (currentSlug === 'always_new' ? ' selected' : '') + '>Всегда новый клик</option>';
                policySelect += '<option value="reuse_per_product"' + (currentSlug === 'reuse_per_product' ? ' selected' : '') + '>Переиспользовать по товару</option>';
                policySelect += '</select>';
                cell.html(policySelect);
            } else if (field === 'activation_window_seconds') {
                // 12i-5 ADR: numeric input с clamp 60..86400.
                cell.html('<input type="number" class="edit-input" data-field="' + field + '" value="' + escapeHtml(currentValue) + '" min="60" max="86400" style="width:100%;box-sizing:border-box;" />');
            } else if (field === 'sort_order') {
                cell.html('<input type="number" class="edit-input" data-field="' + field + '" value="' + escapeHtml(currentValue) + '" min="0" style="width:100%;box-sizing:border-box;" />');
            } else {
                cell.html('<input type="text" class="edit-input" data-field="' + field + '" value="' + escapeHtml(currentValue) + '" style="width:100%;box-sizing:border-box;" />');
            }
        });

        row.find('.edit-btn').hide();
        row.find('.save-btn, .cancel-btn').show();
    });

    // Обработка клика по кнопке "Отмена"
    $('.cancel-btn').on('click', function() {
        var row = $(this).closest('tr');
        resetRowToViewMode(row);
    });

    // Обработка клика по кнопке "Сохранить"
    $('.save-btn').on('click', function() {
        var row = $(this).closest('tr');
        var id = row.data('id');
        // 12i-5 ADR (F-10-001): request_id для server-side idempotency.
        var data = {
            'action': 'update_partner',
            'id': id,
            'nonce': cashbackPartnersData.updateNonce,
            'request_id': makeRequestId()
        };

        row.find('.edit-input').each(function() {
            var input = $(this);
            var field = input.data('field');
            data[field] = input.val();
        });

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                row.find('.edit-field[data-field="name"]').text(response.data.name);
                row.find('.edit-field[data-field="slug"]').text(response.data.slug);
                row.find('.edit-field[data-field="notes"]').text(response.data.notes || '');
                row.find('.edit-field[data-field="sort_order"]').text(response.data.sort_order);

                var isActiveText = response.data.is_active == 1 ? 'Да' : 'Нет';
                row.find('.edit-field[data-field="is_active"]').text(isActiveText);

                // 12i-5 ADR: обновляем новые ячейки (human-readable policy + window number).
                if (response.data.click_session_policy) {
                    var policySlug = response.data.click_session_policy;
                    var policyLabel = POLICY_LABELS[policySlug] || POLICY_LABELS.reuse_in_window;
                    var policyCell = row.find('.edit-field[data-field="click_session_policy"]');
                    policyCell.text(policyLabel);
                    policyCell.attr('data-value', policySlug);
                }
                if (typeof response.data.activation_window_seconds !== 'undefined') {
                    row.find('.edit-field[data-field="activation_window_seconds"]').text(response.data.activation_window_seconds);
                }

                row.find('.save-btn, .cancel-btn').hide();
                row.find('.edit-btn').show();

                $('.wp-header-end').after('<div class="notice notice-success is-dismissible"><p>Партнер успешно обновлен.</p></div>');
                setTimeout(function() {
                    $('.notice-success').fadeOut().remove();
                }, 3000);
            } else {
                alert('Ошибка при обновлении партнера: ' + response.data.message);
            }
        });
    });

    // Сброс строки к режиму просмотра
    function resetRowToViewMode(row) {
        row.find('.edit-field').each(function() {
            var cell = $(this);
            var field = cell.data('field');
            var currentValue = cell.find('.edit-input').val();

            if (field === 'is_active') {
                var displayValue = currentValue == '1' ? 'Да' : 'Нет';
                cell.text(displayValue);
            } else if (field === 'click_session_policy') {
                // 12i-5 ADR: human-readable label, raw slug — в data-value.
                var policyLabel = POLICY_LABELS[currentValue] || POLICY_LABELS.reuse_in_window;
                cell.text(policyLabel);
                cell.attr('data-value', currentValue);
            } else {
                cell.text(currentValue);
            }
        });

        row.find('.save-btn, .cancel-btn').hide();
        row.find('.edit-btn').show();
    }

    // Обработка формы добавления партнера
    $('#add-partner').on('submit', function(e) {
        e.preventDefault();

        // 12i-5 ADR (F-10-001): request_id + новые поля click_session_policy/activation_window_seconds.
        var formData = {
            'action': 'add_partner',
            'nonce': cashbackPartnersData.addNonce,
            'request_id': makeRequestId(),
            'name': $('#name').val(),
            'slug': $('#slug').val(),
            'notes': $('#notes').val(),
            'sort_order': $('#sort_order').val(),
            'is_active': $('#is_active').val(),
            'click_session_policy': $('#click_session_policy').val(),
            'activation_window_seconds': $('#activation_window_seconds').val()
        };

        $.post(ajaxurl, formData, function(response) {
            if (response.success) {
                var baseUrl = window.location.href.split('&message=')[0].split('&error=')[0];
                window.location.href = baseUrl + '&message=added';
            } else {
                $('.notice-error.partner-add-error').remove();
                var $notice = $('<div>', { 'class': 'notice notice-error is-dismissible partner-add-error' })
                    .append($('<p>').text((response.data && response.data.message) ? response.data.message : 'Ошибка'));
                $('#add-partner-form').before($notice);
                $('html, body').animate({
                    scrollTop: $('.partner-add-error').offset().top - 50
                }, 300);
            }
        });
    });

    // =============================
    // Вкладка "Добавить передаваемые в URL"
    // =============================

    // Подсчет новых строк для добавления
    function getParamRowCount() {
        return $('#params-container .param-row').length;
    }

    // Подсчет общего кол-ва (существующие + новые)
    function getTotalParamCount() {
        return $('#existing-params-table tbody tr').length + getParamRowCount();
    }

    // Создание HTML одной строки параметра (для добавления новых)
    function createParamRowHtml(paramName, paramType) {
        return '<div class="param-row" style="margin-bottom: 15px;">' +
            '<div style="margin-bottom: 10px;">' +
            '<label style="display:block; font-weight:600; margin-bottom:5px;">Название параметра:</label>' +
            '<input type="text" class="regular-text param-name" name="param_name[]" value="' + (paramName || '') + '" />' +
            '</div>' +
            '<div>' +
            '<label style="display:block; font-weight:600; margin-bottom:5px;">Значение параметра:</label>' +
            '<input type="text" class="regular-text param-type" name="param_type[]" value="' + (paramType || '') + '" />' +
            '</div>' +
            '</div>';
    }

    // Построение таблицы существующих параметров
    function renderExistingParamsTable(params) {
        var $container = $('#existing-params-table');

        if (!params || params.length === 0) {
            $container.hide().empty();
            return;
        }

        var html = '<h3 style="margin-bottom:10px;">Существующие параметры</h3>' +
            '<table class="wp-list-table widefat fixed striped" style="margin-bottom:10px;">' +
            '<thead><tr>' +
            '<th>Название параметра</th>' +
            '<th>Значение параметра</th>' +
            '<th style="width:200px;">Действия</th>' +
            '</tr></thead><tbody>';

        for (var i = 0; i < params.length; i++) {
            html += '<tr data-param-id="' + params[i].id + '">' +
                '<td class="param-edit-field" data-field="param_name">' + escapeHtml(params[i].param_name) + '</td>' +
                '<td class="param-edit-field" data-field="param_type">' + escapeHtml(params[i].param_type || '') + '</td>' +
                '<td>' +
                '<button class="button button-secondary param-edit-btn">Редактировать</button> ' +
                '<button class="button button-primary param-save-btn" style="display:none;">Сохранить</button> ' +
                '<button class="button param-cancel-btn" style="display:none;">Отмена</button> ' +
                '<button class="button param-delete-btn" style="color:#a00;">Удалить</button>' +
                '</td></tr>';
        }

        html += '</tbody></table>';
        $container.html(html).show();
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    // Загрузка параметров сети
    function loadNetworkParams(networkId) {
        $.post(ajaxurl, {
            'action': 'get_network_params',
            'nonce': cashbackPartnersData.getParamsNonce,
            'network_id': networkId
        }, function(response) {
            if (response.success) {
                renderExistingParamsTable(response.data.params);
                // Сбрасываем поля добавления к одной пустой строке
                $('#params-container').html(createParamRowHtml('', ''));
            } else {
                alert('Ошибка загрузки параметров: ' + response.data.message);
            }
        });
    }

    // Кнопка "Добавить" — добавить новую пару полей
    $('#add-param-row').on('click', function() {
        if (getTotalParamCount() >= MAX_PARAMS) {
            alert('Больше нельзя добавить параметров, максимальное количество параметров ' + MAX_PARAMS + '.');
            return;
        }
        $('#params-container').append(createParamRowHtml('', ''));
    });

    // При выборе сети — загрузить существующие параметры
    $('#network-select').on('change', function() {
        var networkId = $(this).val();

        if (!networkId) {
            $('#existing-params-table').hide().empty();
            $('#params-container').html(createParamRowHtml('', ''));
            return;
        }

        loadNetworkParams(networkId);
    });

    // Редактирование параметра в таблице
    $('#existing-params-table').on('click', '.param-edit-btn', function() {
        var row = $(this).closest('tr');

        row.find('.param-edit-field').each(function() {
            var cell = $(this);
            var value = cell.text().trim();
            cell.html('<input type="text" class="param-inline-input" data-field="' + cell.data('field') + '" value="' + escapeHtml(value) + '" style="width:100%;box-sizing:border-box;" />');
        });

        row.find('.param-edit-btn, .param-delete-btn').hide();
        row.find('.param-save-btn, .param-cancel-btn').show();
    });

    // Отмена редактирования
    $('#existing-params-table').on('click', '.param-cancel-btn', function() {
        var row = $(this).closest('tr');

        row.find('.param-edit-field').each(function() {
            var cell = $(this);
            var val = cell.find('.param-inline-input').val();
            cell.text(val);
        });

        row.find('.param-save-btn, .param-cancel-btn').hide();
        row.find('.param-edit-btn, .param-delete-btn').show();
    });

    // Сохранение одного параметра
    $('#existing-params-table').on('click', '.param-save-btn', function() {
        var row = $(this).closest('tr');
        var paramId = row.data('param-id');
        var paramName = row.find('.param-inline-input[data-field="param_name"]').val();
        var paramType = row.find('.param-inline-input[data-field="param_type"]').val();

        $.post(ajaxurl, {
            'action': 'update_network_param',
            'nonce': cashbackPartnersData.updateParamNonce,
            'param_id': paramId,
            'param_name': paramName,
            'param_type': paramType
        }, function(response) {
            if (response.success) {
                row.find('.param-edit-field[data-field="param_name"]').text(response.data.param_name);
                row.find('.param-edit-field[data-field="param_type"]').text(response.data.param_type || '');
                row.find('.param-save-btn, .param-cancel-btn').hide();
                row.find('.param-edit-btn, .param-delete-btn').show();

                showParamsNotice('success', 'Параметр обновлен.');
            } else {
                alert('Ошибка: ' + response.data.message);
            }
        });
    });

    // Удаление параметра
    $('#existing-params-table').on('click', '.param-delete-btn', function() {
        if (!confirm('Удалить этот параметр?')) return;

        var row = $(this).closest('tr');
        var paramId = row.data('param-id');

        $.post(ajaxurl, {
            'action': 'delete_network_param',
            'nonce': cashbackPartnersData.deleteParamNonce,
            'param_id': paramId
        }, function(response) {
            if (response.success) {
                row.fadeOut(300, function() {
                    $(this).remove();
                    // Если не осталось строк — скрываем таблицу
                    if ($('#existing-params-table tbody tr').length === 0) {
                        $('#existing-params-table').hide().empty();
                    }
                });
                showParamsNotice('success', 'Параметр удален.');
            } else {
                alert('Ошибка: ' + response.data.message);
            }
        });
    });

    // Уведомление для вкладки параметров
    function showParamsNotice(type, message) {
        $('.params-notice').remove();
        var safeType = type === 'success' ? 'success' : 'error';
        var $notice = $('<div>', { 'class': 'notice notice-' + safeType + ' is-dismissible params-notice' })
            .append($('<p>').text(message == null ? '' : String(message)));
        $('#network-params-form').before($notice);
        setTimeout(function() {
            $('.params-notice').fadeOut().remove();
        }, 3000);
    }

    // Кнопка "Сохранить" новые параметры
    $('#save-network-params').on('click', function() {
        var networkId = $('#network-select').val();

        if (!networkId) {
            alert('Выберите партнерскую сеть.');
            return;
        }

        var paramNames = [];
        var paramTypes = [];

        $('#params-container .param-row').each(function() {
            paramNames.push($(this).find('.param-name').val());
            paramTypes.push($(this).find('.param-type').val());
        });

        // Проверяем, есть ли хоть одно заполненное название
        var hasValue = false;
        for (var i = 0; i < paramNames.length; i++) {
            if (paramNames[i] && paramNames[i].trim() !== '') {
                hasValue = true;
                break;
            }
        }

        if (!hasValue) {
            alert('Заполните хотя бы одно название параметра.');
            return;
        }

        $.post(ajaxurl, {
            'action': 'save_network_params',
            'nonce': cashbackPartnersData.saveParamsNonce,
            'network_id': networkId,
            'param_names': paramNames,
            'param_types': paramTypes
        }, function(response) {
            $('.params-notice').remove();

            if (response.success) {
                showParamsNotice('success', response.data.message);
                // Перезагружаем таблицу существующих параметров
                loadNetworkParams(networkId);
            } else {
                showParamsNotice('error', response.data.message);
            }
        });
    });
});
