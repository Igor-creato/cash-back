jQuery(document).ready(function($) {
    function escapeHtml(text) {
        var map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }

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

    // Массовое изменение ставки кэшбэка — предпросмотр
    $('#bulk-rate-preview').on('click', function() {
        var oldRate = $('#bulk-old-rate').val().trim();
        var newRate = $('#bulk-new-rate').val().trim();

        if (!oldRate || !newRate) {
            alert('Заполните оба поля.');
            return;
        }

        if (oldRate.toLowerCase() !== 'all') {
            var parsed = parseFloat(oldRate);
            if (isNaN(parsed) || parsed < 0 || parsed > 100) {
                alert('Текущая ставка должна быть числом от 0 до 100 или "all".');
                return;
            }
        }

        var parsedNew = parseFloat(newRate);
        if (isNaN(parsedNew) || parsedNew < 0 || parsedNew > 100) {
            alert('Новая ставка должна быть числом от 0 до 100.');
            return;
        }

        var $info = $('#bulk-rate-info');
        var $applyBtn = $('#bulk-rate-apply');
        $info.text('Загрузка...');
        $applyBtn.prop('disabled', true);

        $.post(ajaxurl, {
            action: 'bulk_update_cashback_rate',
            nonce: cashbackUsersData.bulkRateNonce,
            old_rate: oldRate,
            new_rate: newRate,
            preview: 1
        }, function(response) {
            if (response.success) {
                var count = response.data.count;
                if (count === 0) {
                    $info.text('Не найдено пользователей для обновления.');
                    $applyBtn.prop('disabled', true);
                } else {
                    var label = oldRate.toLowerCase() === 'all'
                        ? 'Будет обновлено пользователей: ' + count + ' (все → ' + newRate + '%)'
                        : 'Будет обновлено пользователей: ' + count + ' (' + oldRate + '% → ' + newRate + '%)';
                    $info.text(label);
                    $applyBtn.prop('disabled', false);
                }
            } else {
                $info.text('Ошибка: ' + response.data.message);
                $applyBtn.prop('disabled', true);
            }
        }).fail(function() {
            $info.text('Ошибка соединения.');
            $applyBtn.prop('disabled', true);
        });
    });

    // Массовое изменение ставки кэшбэка — применение
    $('#bulk-rate-apply').on('click', function() {
        var oldRate = $('#bulk-old-rate').val().trim();
        var newRate = $('#bulk-new-rate').val().trim();
        var infoText = $('#bulk-rate-info').text();

        if (!confirm('Подтвердите массовое изменение ставки кэшбэка.\n\n' + infoText)) {
            return;
        }

        var $info = $('#bulk-rate-info');
        var $applyBtn = $('#bulk-rate-apply');
        $applyBtn.prop('disabled', true);
        $info.text('Обновление...');

        $.post(ajaxurl, {
            action: 'bulk_update_cashback_rate',
            nonce: cashbackUsersData.bulkRateNonce,
            request_id: makeRequestId(),
            old_rate: oldRate,
            new_rate: newRate,
            preview: 0
        }, function(response) {
            if (response.success) {
                $info.html('<span style="color: green;">Обновлено пользователей: ' + response.data.updated + '</span>');
                $applyBtn.prop('disabled', true);
                $('#users-tbody tr').each(function() {
                    var $cell = $(this).find('.edit-field[data-field="cashback_rate"]');
                    var currentRate = $cell.text().trim();
                    if (oldRate.toLowerCase() === 'all' || currentRate === parseFloat(oldRate).toFixed(2)) {
                        $cell.text(parseFloat(newRate).toFixed(2));
                    }
                });
            } else {
                $info.html('<span style="color: red;">Ошибка: ' + escapeHtml(response.data.message) + '</span>');
            }
        }).fail(function() {
            $info.html('<span style="color: red;">Ошибка соединения.</span>');
        });
    });

    // Сброс кнопки "Применить" при изменении полей
    $('#bulk-old-rate, #bulk-new-rate').on('input', function() {
        $('#bulk-rate-apply').prop('disabled', true);
        $('#bulk-rate-info').text('');
    });

    // Обработка фильтра по статусу
    $('#filter-submit').on('click', function() {
        var status = $('#filter-status').val();
        var url = new URL(window.location);
        if (status) {
            url.searchParams.set('status', status);
        } else {
            url.searchParams.delete('status');
        }
        url.searchParams.delete('paged');
        window.location.href = url.toString();
    });

    // Обработка поиска
    function applySearch() {
        var search = $('#search-input').val().trim();
        var url = new URL(window.location);
        if (search) {
            url.searchParams.set('search', search);
        } else {
            url.searchParams.delete('search');
        }
        url.searchParams.delete('paged');
        window.location.href = url.toString();
    }

    $('#search-submit').on('click', applySearch);

    $('#search-input').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            applySearch();
        }
    });

    // Обработка клика по кнопке "Редактировать" (используем делегирование)
    $(document).on('click', '.edit-btn', function() {
        var row = $(this).closest('tr');
        var cells = row.find('.edit-field');

        cells.each(function() {
            var cell = $(this);
            var field = cell.data('field');
            var currentValue = cell.text().trim();

            cell.attr('data-original-value', currentValue);

            if (field === 'status') {
                var selectHtml = '<select class="edit-input" data-field="' + escapeHtml(field) + '">';
                selectHtml += '<option value="active"' + (currentValue === 'active' ? ' selected' : '') + '>active</option>';
                selectHtml += '<option value="noactive"' + (currentValue === 'noactive' ? ' selected' : '') + '>noactive</option>';
                selectHtml += '<option value="banned"' + (currentValue === 'banned' ? ' selected' : '') + '>banned</option>';
                selectHtml += '<option value="deleted"' + (currentValue === 'deleted' ? ' selected' : '') + '>deleted</option>';
                selectHtml += '</select>';
                cell.html(selectHtml);
            } else if (field === 'cashback_rate' || field === 'min_payout_amount') {
                var step = '0.01';
                var placeholder = field === 'cashback_rate' ? 'Ставка кэшбэка' : 'Мин. сумма';
                cell.html('<input type="number" step="' + step + '" class="edit-input" data-field="' + escapeHtml(field) + '" value="' + escapeHtml(currentValue) + '" placeholder="' + escapeHtml(placeholder) + '" />');
            } else {
                cell.html('<input type="text" class="edit-input" data-field="' + escapeHtml(field) + '" value="' + escapeHtml(currentValue) + '" />');
            }
        });

        row.find('.edit-btn').hide();
        row.find('.save-btn, .cancel-btn').show();
    });

    // Обработка клика по кнопке "Отмена" (используем делегирование)
    $(document).on('click', '.cancel-btn', function() {
        var row = $(this).closest('tr');
        resetRowToViewMode(row);
    });

    // Обработка клика по кнопке "Сохранить" (используем делегирование)
    $(document).on('click', '.save-btn', function() {
        var row = $(this).closest('tr');
        var userId = row.data('user-id');
        var changedData = {};

        row.find('.edit-input').each(function() {
            var input = $(this);
            var field = input.data('field');
            var newValue = input.val().trim();

            var cell = input.closest('.edit-field');
            var originalValue = cell.attr('data-original-value');

            if (originalValue === undefined) {
                originalValue = '';
            }

            if (originalValue.trim() !== newValue) {
                changedData[field] = newValue;
            }
        });

        var data = {
            'action': 'update_user_profile',
            'user_id': userId,
            'nonce': cashbackUsersData.updateNonce
        };

        Object.assign(data, changedData);

        var hasChanges = Object.keys(changedData).length > 0;

        if (!hasChanges) {
            alert('Нет изменений для сохранения.');
            resetRowToViewMode(row);
            return;
        }

        if (changedData.hasOwnProperty('cashback_rate')) {
            var cashbackRate = parseFloat(changedData['cashback_rate']);
            if (isNaN(cashbackRate) || cashbackRate < 0 || cashbackRate > 100) {
                alert('Ставка кэшбэка должна быть числом от 0 до 100');
                return;
            }
        }

        if (changedData.hasOwnProperty('min_payout_amount')) {
            var rawMinPayout = String(changedData['min_payout_amount']).trim().replace(',', '.');
            if (!/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(rawMinPayout)) {
                alert('Минимальная сумма выплаты должна быть десятичным числом с точностью до 2 знаков');
                return;
            }
            changedData['min_payout_amount'] = rawMinPayout;
            data['min_payout_amount'] = rawMinPayout;
        }

        if (changedData.hasOwnProperty('status')) {
            var status = changedData['status'];
            var allowedStatuses = ['active', 'noactive', 'banned', 'deleted'];
            if (allowedStatuses.indexOf(status) === -1) {
                alert('Недопустимый статус пользователя');
                return;
            }
        }

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                row.find('.edit-field[data-field="cashback_rate"]').text(response.data.cashback_rate);
                row.find('.edit-field[data-field="min_payout_amount"]').text(response.data.min_payout_amount);
                row.find('.edit-field[data-field="status"]').text(response.data.status);
                row.find('.edit-field[data-field="ban_reason"]').text(response.data.ban_reason);
                row.find('.edit-field[data-field="banned_at"]').text(response.data.banned_at ? response.data.banned_at : '');

                row.find('.edit-input').each(function() {
                    var cell = $(this).closest('.edit-field');
                    var field = $(this).data('field');
                    cell.text(response.data[field] || '');
                });

                row.find('.save-btn, .cancel-btn').hide();
                row.find('.edit-btn').show();

                $('.wp-header-end').after('<div class="notice notice-success is-dismissible"><p>Профиль пользователя успешно обновлен.</p></div>');
                setTimeout(function() {
                    $('.notice-success').fadeOut().remove();
                }, 3000);
            } else {
                alert('Ошибка при обновлении профиля пользователя: ' + response.data.message);
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            var status = jqXHR.status || 0;
            var errorMsg = 'Ошибка соединения при обновлении профиля пользователя';

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

    // Сброс строки к режиму просмотра
    function resetRowToViewMode(row) {
        var userId = row.data('user-id');
        var data = {
            'action': 'get_user_profile',
            'user_id': userId,
            'nonce': cashbackUsersData.getNonce
        };

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                row.find('.edit-field[data-field="cashback_rate"]').text(response.data.cashback_rate);
                row.find('.edit-field[data-field="min_payout_amount"]').text(response.data.min_payout_amount);
                row.find('.edit-field[data-field="status"]').text(response.data.status);
                row.find('.edit-field[data-field="ban_reason"]').text(response.data.ban_reason);
                row.find('.edit-field[data-field="banned_at"]').text(response.data.banned_at ? response.data.banned_at : '');
            } else {
                row.find('.edit-field').each(function() {
                    var cell = $(this);
                    var input = cell.find('.edit-input');
                    if (input.length > 0) {
                        var currentValue = input.val();
                        cell.text(currentValue);
                    }
                });
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            var status = jqXHR.status || 0;
            var errorMsg = 'Ошибка при загрузке данных пользователя';

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

            console.error(errorMsg);

            row.find('.edit-field').each(function() {
                var cell = $(this);
                var input = cell.find('.edit-input');
                if (input.length > 0) {
                    var currentValue = input.val();
                    cell.text(currentValue);
                }
            });
        });

        row.find('.save-btn, .cancel-btn').hide();
        row.find('.edit-btn').show();
    }
});
