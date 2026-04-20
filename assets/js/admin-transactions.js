(function ($) {
    'use strict';

    var statusLabels = {
        waiting:   'В ожидании',
        completed: 'Подтверждена',
        declined:  'Отклонена',
        hold:      'Удержание',
        balance:   'Зачислена на баланс'
    };

    function escapeHtml(text) {
        var map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
        return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
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

    var MONEY_RE = /^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/;

    function showSuccessNotice(message) {
        var notice = $('<div>').addClass('notice notice-success is-dismissible')
            .append($('<p>').text(message));
        $('.wp-header-end').after(notice);
        setTimeout(function () {
            notice.fadeOut(function () { $(this).remove(); });
        }, 3000);
    }

    function updateRowData(row, txData) {
        row.find('.edit-field[data-field="order_status"]').text(statusLabels[txData.order_status] || txData.order_status);
        row.find('.edit-field[data-field="sum_order"]').text(txData.sum_order);
        row.find('.edit-field[data-field="comission"]').text(txData.comission);
        row.find('.cashback-display').text(txData.cashback);
    }

    var transferTransactionId = null;
    var transferRow = null;

    $(document).ready(function () {

        // Модальное окно переноса транзакции
        $('body').append(
            '<div id="transfer-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:99999;">' +
                '<div id="transfer-modal-inner">' +
                    '<h3 style="margin-top:0;">Перенести транзакцию</h3>' +
                    '<p>Введите email пользователя, на которого переносится транзакция:</p>' +
                    '<input type="email" id="transfer-email" placeholder="user@example.com" autocomplete="email" />' +
                    '<div id="transfer-error" style="color:#d63638;margin:8px 0;display:none;"></div>' +
                    '<div style="margin-top:12px;">' +
                        '<button id="transfer-confirm" class="button button-primary">Перенести</button>' +
                        '&nbsp;' +
                        '<button id="transfer-cancel" class="button">Отмена</button>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );

        // --- Filters ---
        $('#filter-submit').on('click', function () {
            var status = $('#filter-status').val();
            var partner = $('#filter-partner').val();
            var search = $('#filter-search').val();
            var url = new URL(window.location.href);

            if (status) {
                url.searchParams.set('status', status);
            } else {
                url.searchParams.delete('status');
            }

            if (partner) {
                url.searchParams.set('partner', partner);
            } else {
                url.searchParams.delete('partner');
            }

            if (search) {
                url.searchParams.set('search', search);
            } else {
                url.searchParams.delete('search');
            }

            url.searchParams.delete('paged');
            window.location.href = url.toString();
        });

        $('#filter-reset').on('click', function () {
            var url = new URL(window.location.href);
            url.searchParams.delete('status');
            url.searchParams.delete('partner');
            url.searchParams.delete('search');
            url.searchParams.delete('paged');
            window.location.href = url.toString();
        });

        // --- Transfer (unregistered → registered) ---
        $(document).on('click', '.transfer-btn', function () {
            transferTransactionId = $(this).data('transaction-id');
            transferRow = $(this).closest('tr');
            $('#transfer-email').val('');
            $('#transfer-error').hide().text('');
            $('#transfer-modal').show();
            setTimeout(function () { $('#transfer-email').focus(); }, 50);
        });

        $('#transfer-cancel').on('click', function () {
            $('#transfer-modal').hide();
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#transfer-modal').is(':visible')) {
                $('#transfer-modal').hide();
            }
        });

        $('#transfer-modal').on('click', function (e) {
            if ($(e.target).is('#transfer-modal')) {
                $('#transfer-modal').hide();
            }
        });

        $('#transfer-confirm').on('click', function () {
            var email = $('#transfer-email').val().trim();
            var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                $('#transfer-error').text('Введите корректный email.').show();
                return;
            }

            $('#transfer-error').hide();
            var btn = $(this);
            btn.prop('disabled', true).text('Загрузка...');

            $.post(ajaxurl, {
                action: 'transfer_unregistered_transaction',
                transaction_id: transferTransactionId,
                email: email,
                nonce: cashbackTransactionsData.transferNonce,
                request_id: makeRequestId()
            }, function (response) {
                if (response.success) {
                    $('#transfer-modal').hide();
                    if (transferRow) {
                        transferRow.remove();
                    }
                    showSuccessNotice(response.data.message || 'Транзакция успешно перенесена.');
                } else {
                    $('#transfer-error').text(response.data.message || 'Неизвестная ошибка.').show();
                }
            }).fail(function (jqXHR) {
                var msg = 'Ошибка соединения';
                if (jqXHR.status === 403) {
                    msg = 'Доступ запрещён. Обновите страницу.';
                } else if (jqXHR.status === 500) {
                    msg = 'Ошибка сервера.';
                } else if (jqXHR.status) {
                    msg = 'Ошибка: HTTP ' + jqXHR.status;
                }
                $('#transfer-error').text(msg).show();
            }).always(function () {
                btn.prop('disabled', false).text('Перенести');
            });
        });

        $('#transfer-email').on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#transfer-confirm').click();
            }
        });

        // Allow Enter key in search field
        $('#filter-search').on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#filter-submit').click();
            }
        });

        // --- Edit ---
        $(document).on('click', '.edit-btn', function () {
            var row = $(this).closest('tr');
            var cells = row.find('.edit-field');

            cells.each(function () {
                var cell = $(this);
                var field = cell.data('field');
                var currentValue = cell.text().trim();

                cell.attr('data-original-value', currentValue);

                if (field === 'order_status') {
                    var selectHtml = '<select class="edit-input" data-field="order_status">';
                    var statuses = ['waiting', 'completed', 'declined', 'hold'];
                    for (var i = 0; i < statuses.length; i++) {
                        var s = statuses[i];
                        var selected = (statusLabels[s] === currentValue || s === currentValue) ? ' selected' : '';
                        selectHtml += '<option value="' + escapeHtml(s) + '"' + selected + '>' + escapeHtml(statusLabels[s]) + '</option>';
                    }
                    selectHtml += '</select>';
                    cell.html(selectHtml);
                } else if (field === 'sum_order' || field === 'comission') {
                    cell.html(
                        '<input type="number" step="0.01" min="0" class="edit-input" data-field="' +
                        escapeHtml(field) + '" value="' + escapeHtml(currentValue) + '" />'
                    );
                }
            });

            row.find('.edit-btn').hide();
            row.find('.save-btn, .cancel-btn').show();
        });

        // --- Save ---
        $(document).on('click', '.save-btn', function () {
            var $saveBtn = $(this);
            var row = $saveBtn.closest('tr');
            var transactionId = row.data('transaction-id');
            var tab = row.data('tab');
            var changedData = {};

            row.find('.edit-input').each(function () {
                var input = $(this);
                var field = input.data('field');
                var newValue = input.val();
                var cell = input.closest('.edit-field');
                var originalValue = (cell.attr('data-original-value') || '').trim();

                if (field === 'order_status') {
                    if (statusLabels[newValue] !== originalValue && newValue !== originalValue) {
                        changedData[field] = newValue;
                    }
                } else if (originalValue !== newValue) {
                    changedData[field] = newValue;
                }
            });

            if (Object.keys(changedData).length === 0) {
                alert('Нет изменений для сохранения.');
                row.find('.save-btn, .cancel-btn').hide();
                row.find('.edit-btn').show();
                // Restore text from original values
                row.find('.edit-field').each(function () {
                    var cell = $(this);
                    var orig = cell.attr('data-original-value');
                    if (orig !== undefined && cell.find('.edit-input').length) {
                        cell.text(orig);
                    }
                });
                return;
            }

            // Client-side validation (decimal-string, без float-арифметики)
            if (changedData.sum_order !== undefined) {
                var sumStr = String(changedData.sum_order).trim().replace(',', '.');
                if (!MONEY_RE.test(sumStr)) {
                    alert('Сумма заказа должна быть неотрицательным числом с точностью до 2 знаков.');
                    return;
                }
                changedData.sum_order = sumStr;
            }
            if (changedData.comission !== undefined) {
                var comStr = String(changedData.comission).trim().replace(',', '.');
                if (!MONEY_RE.test(comStr)) {
                    alert('Комиссия должна быть неотрицательным числом с точностью до 2 знаков.');
                    return;
                }
                changedData.comission = comStr;
            }

            var data = $.extend({
                action: 'update_transaction',
                transaction_id: transactionId,
                tab: tab,
                nonce: cashbackTransactionsData.updateNonce,
                request_id: makeRequestId()
            }, changedData);

            $saveBtn.prop('disabled', true);

            $.post(ajaxurl, data, function (response) {
                if (response.success) {
                    updateRowData(row, response.data.transaction_data);
                    showSuccessNotice('Транзакция успешно обновлена.');
                    row.find('.save-btn, .cancel-btn').hide();
                    row.find('.edit-btn').show();
                } else {
                    alert('Ошибка: ' + (response.data.message || 'Неизвестная ошибка'));
                }
            }).fail(function (jqXHR) {
                var errorMsg = 'Ошибка соединения';
                if (jqXHR.status === 403) {
                    errorMsg = 'Доступ запрещён. Обновите страницу.';
                } else if (jqXHR.status === 500) {
                    errorMsg = 'Ошибка сервера.';
                } else if (jqXHR.status) {
                    errorMsg = 'Ошибка соединения: HTTP ' + jqXHR.status;
                }
                alert(errorMsg);
            }).always(function () {
                $saveBtn.prop('disabled', false);
            });
        });

        // --- Cancel ---
        $(document).on('click', '.cancel-btn', function () {
            var row = $(this).closest('tr');
            var transactionId = row.data('transaction-id');
            var tab = row.data('tab');

            $.post(ajaxurl, {
                action: 'get_transaction',
                transaction_id: transactionId,
                tab: tab,
                nonce: cashbackTransactionsData.getNonce
            }, function (response) {
                if (response.success) {
                    updateRowData(row, response.data);
                } else {
                    // Fallback: restore from original values
                    row.find('.edit-field').each(function () {
                        var cell = $(this);
                        var orig = cell.attr('data-original-value');
                        if (orig !== undefined) {
                            cell.text(orig);
                        }
                    });
                }
            }).fail(function () {
                row.find('.edit-field').each(function () {
                    var cell = $(this);
                    var orig = cell.attr('data-original-value');
                    if (orig !== undefined) {
                        cell.text(orig);
                    }
                });
            });

            row.find('.save-btn, .cancel-btn').hide();
            row.find('.edit-btn').show();
        });
    });
})(jQuery);
