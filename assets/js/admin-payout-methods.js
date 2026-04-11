jQuery(document).ready(function($) {
    function escapeHtml(text) {
        var map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
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
        var row = $(this).closest('tr');
        var id = row.data('id');
        var data = {
            'action': 'update_payout_method',
            'id': id,
            'nonce': cashbackPayoutMethodsData.updateNonce
        };

        row.find('.edit-input').each(function() {
            var input = $(this);
            var field = input.data('field');
            data[field] = input.val();
        });

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
                alert('Ошибка при обновлении способа выплаты: ' + response.data.message);
            }
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

        var formData = {
            'action': 'add_payout_method',
            'nonce': cashbackPayoutMethodsData.addNonce,
            'slug': $('#slug').val(),
            'name': $('#name').val(),
            'is_active': $('#is_active').val(),
            'sort_order': $('#sort_order').val(),
            'bank_required': $('#bank_required').val()
        };

        $.post(ajaxurl, formData, function(response) {
            if (response.success) {
                // Перезагружаем страницу для отображения новой записи
                var baseUrl = window.location.href.split('&message=')[0].split('&error=')[0];
                window.location.href = baseUrl + '&message=added';
            } else {
                alert('Ошибка при добавлении способа выплаты: ' + response.data.message);
            }
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
            'max_withdrawal_amount': $('#max_withdrawal_amount').val(),
            'email_sender_name': $('#email_sender_name').val(),
            'balance_delay_days': $('#balance_delay_days').val()
        }, function(response) {
            if (response.success) {
                $msg.html('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>');
            } else {
                $msg.html('<div class="notice notice-error is-dismissible"><p>' + response.data.message + '</p></div>');
            }
            $btn.prop('disabled', false);
            setTimeout(function() { $msg.find('.notice').fadeOut().remove(); }, 3000);
        }).fail(function() {
            $msg.html('<div class="notice notice-error is-dismissible"><p>Ошибка сети.</p></div>');
            $btn.prop('disabled', false);
        });
    });
});
