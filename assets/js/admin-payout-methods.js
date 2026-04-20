jQuery(document).ready(function($) {
    function escapeHtml(text) {
        var map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }

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

    // Обработка клика по кнопке "Редактировать"
    $('.edit-btn').on('click', function() {
        var row = $(this).closest('tr');
        var cells = row.find('.edit-field');

        cells.each(function() {
            var cell = $(this);
            var field = cell.data('field');
            var currentValue = cell.text();

            if (field === 'is_active' || field === 'bank_required') {
                // Для полей is_active и bank_required создаем select
                var selectHtml = '<select class="edit-input" data-field="' + escapeHtml(field) + '">';
                selectHtml += '<option value="1"' + (currentValue.trim() === 'Да' ? ' selected' : '') + '>Да</option>';
                selectHtml += '<option value="0"' + (currentValue.trim() === 'Нет' ? ' selected' : '') + '>Нет</option>';
                selectHtml += '</select>';
                cell.html(selectHtml);
            } else {
                // Для остальных полей создаем input
                cell.html('<input type="text" class="edit-input regular-text" data-field="' + escapeHtml(field) + '" value="' + escapeHtml(currentValue) + '" />');
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
        var $saveBtn = $(this);
        if ($saveBtn.prop('disabled')) return;
        var row = $saveBtn.closest('tr');
        var id = row.data('id');
        var data = {
            'action': 'update_payout_method',
            'id': id,
            'nonce': cashbackPayoutMethodsData.updateNonce,
            'request_id': makeRequestId()
        };

        row.find('.edit-input').each(function() {
            var input = $(this);
            var field = input.data('field');
            data[field] = input.val();
        });

        $saveBtn.prop('disabled', true);

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                // Обновляем значения в ячейках
                row.find('.edit-field[data-field="slug"]').text(response.data.slug);
                row.find('.edit-field[data-field="name"]').text(response.data.name);

                var isActiveText = response.data.is_active == 1 ? 'Да' : 'Нет';
                row.find('.edit-field[data-field="is_active"]').text(isActiveText);

                row.find('.edit-field[data-field="sort_order"]').text(response.data.sort_order);

                var bankRequiredText = response.data.bank_required == 1 ? 'Да' : 'Нет';
                row.find('.edit-field[data-field="bank_required"]').text(bankRequiredText);

                // Переключаем строку в режим просмотра
                row.find('.save-btn, .cancel-btn').hide();
                row.find('.edit-btn').show();

                // Показываем сообщение об успешном обновлении
                $('.wp-header-end').after('<div class="notice notice-success is-dismissible"><p>Способ выплаты успешно обновлен.</p></div>');
                setTimeout(function() {
                    $('.notice-success').fadeOut().remove();
                }, 3000);
            } else {
                alert('Ошибка при обновлении способа выплаты: ' + (response.data && response.data.message ? response.data.message : 'неизвестная ошибка'));
            }
        }).always(function() {
            $saveBtn.prop('disabled', false);
        });
    });

    // Сброс строки к режиму просмотра
    function resetRowToViewMode(row) {
        row.find('.edit-field').each(function() {
            var cell = $(this);
            var field = cell.data('field');
            var currentValue = cell.find('.edit-input').val();

            if (field === 'is_active' || field === 'bank_required') {
                var displayValue = currentValue == '1' ? 'Да' : 'Нет';
                cell.text(displayValue);
            } else {
                cell.text(currentValue);
            }
        });

        row.find('.save-btn, .cancel-btn').hide();
        row.find('.edit-btn').show();
    }

    // Обработка формы добавления способа выплаты
    $('#add-payout-method').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $submitBtn = $form.find('input[type="submit"], button[type="submit"]');
        if ($submitBtn.prop('disabled')) return;

        var formData = {
            'action': 'add_payout_method',
            'nonce': cashbackPayoutMethodsData.addNonce,
            'request_id': makeRequestId(),
            'slug': $('#slug').val(),
            'name': $('#name').val(),
            'is_active': $('#is_active').val(),
            'sort_order': $('#sort_order').val(),
            'bank_required': $('#bank_required').val()
        };

        $submitBtn.prop('disabled', true);

        $.post(ajaxurl, formData, function(response) {
            if (response.success) {
                // Перезагружаем страницу для отображения новой записи
                var baseUrl = window.location.href.split('&message=')[0].split('&error=')[0];
                window.location.href = baseUrl + '&message=added';
            } else {
                alert('Ошибка при добавлении способа выплаты: ' + (response.data && response.data.message ? response.data.message : 'неизвестная ошибка'));
                $submitBtn.prop('disabled', false);
            }
        }).fail(function() {
            $submitBtn.prop('disabled', false);
        });
    });

    // Обработка формы настроек выплат
    $('#withdrawal-settings-form').on('submit', function(e) {
        e.preventDefault();

        var $msg = $('#withdrawal-settings-message');
        var $btn = $(this).find('input[type="submit"]');

        $btn.prop('disabled', true);

        $.post(ajaxurl, {
            'action': 'save_withdrawal_settings',
            'nonce': cashbackPayoutMethodsData.saveSettingsNonce,
            'request_id': makeRequestId(),
            'max_withdrawal_amount': $('#max_withdrawal_amount').val(),
            'email_sender_name': $('#email_sender_name').val(),
            'balance_delay_days': $('#balance_delay_days').val()
        }, function(response) {
            var noticeClass = response.success ? 'notice-success' : 'notice-error';
            var msgText = (response.data && response.data.message) ? String(response.data.message) : (response.success ? 'Сохранено.' : 'Ошибка.');
            $msg.empty().append(
                $('<div>', { 'class': 'notice ' + noticeClass + ' is-dismissible' })
                    .append($('<p>').text(msgText))
            );
            $btn.prop('disabled', false);
            setTimeout(function() { $msg.find('.notice').fadeOut().remove(); }, 3000);
        }).fail(function() {
            $msg.empty().append(
                $('<div>', { 'class': 'notice notice-error is-dismissible' })
                    .append($('<p>').text('Ошибка сети.'))
            );
            $btn.prop('disabled', false);
        });
    });
});
