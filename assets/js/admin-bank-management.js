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

            if (field === 'is_active') {
                // Для поля is_active создаем select
                var selectHtml = '<select class="edit-input" data-field="' + escapeHtml(field) + '">';
                selectHtml += '<option value="1"' + (currentValue === 'Да' ? ' selected' : '') + '>Да</option>';
                selectHtml += '<option value="0"' + (currentValue === 'Нет' ? ' selected' : '') + '>Нет</option>';
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
            'action': 'update_bank',
            'id': id,
            'nonce': cashbackBanksData.updateNonce
        };

        row.find('.edit-input').each(function() {
            var input = $(this);
            var field = input.data('field');
            data[field] = input.val();
        });

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                // Обновляем значения в ячейках
                row.find('.edit-field[data-field="bank_code"]').text(response.data.bank_code);
                row.find('.edit-field[data-field="name"]').text(response.data.name);
                row.find('.edit-field[data-field="short_name"]').text(response.data.short_name);

                var isActiveText = response.data.is_active == 1 ? 'Да' : 'Нет';
                row.find('.edit-field[data-field="is_active"]').text(isActiveText);

                row.find('.edit-field[data-field="sort_order"]').text(response.data.sort_order);

                // Переключаем строку в режим просмотра
                row.find('.save-btn, .cancel-btn').hide();
                row.find('.edit-btn').show();

                // Показываем сообщение об успешном обновлении
                $('.wp-header-end').after('<div class="notice notice-success is-dismissible"><p>Банк успешно обновлен.</p></div>');
                setTimeout(function() {
                    $('.notice-success').fadeOut().remove();
                }, 3000);
            } else {
                alert('Ошибка при обновлении банка: ' + response.data.message);
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
            } else {
                cell.text(currentValue);
            }
        });

        row.find('.save-btn, .cancel-btn').hide();
        row.find('.edit-btn').show();
    }

    // Обработка формы добавления банка
    $('#add-bank').on('submit', function(e) {
        e.preventDefault();

        var formData = {
            'action': 'add_bank',
            'nonce': cashbackBanksData.addNonce,
            'bank_code': $('#bank_code').val(),
            'name': $('#name').val(),
            'short_name': $('#short_name').val(),
            'is_active': $('#is_active').val(),
            'sort_order': $('#sort_order').val()
        };

        $.post(ajaxurl, formData, function(response) {
            if (response.success) {
                // Перезагружаем страницу для отображения новой записи
                var baseUrl = window.location.href.split('&message=')[0].split('&error=')[0];
                window.location.href = baseUrl + '&message=added';
            } else {
                // Удаляем предыдущие уведомления об ошибках
                $('.notice-error.bank-add-error').remove();
                // Показываем ошибку как WordPress notice
                var errorHtml = '<div class="notice notice-error is-dismissible bank-add-error"><p>' + escapeHtml(response.data.message) + '</p></div>';
                $('#add-bank-form').before(errorHtml);
                // Прокручиваем к сообщению об ошибке
                $('html, body').animate({
                    scrollTop: $('.bank-add-error').offset().top - 50
                }, 300);
            }
        });
    });
});
