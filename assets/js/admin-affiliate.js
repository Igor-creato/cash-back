/**
 * Admin: Партнёрская программа
 */
(function ($) {
    'use strict';

    if (typeof cashbackAffiliateAdmin === 'undefined') {
        return;
    }

    var data = cashbackAffiliateAdmin;

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

    /* ── Module toggle ── */
    $(document).on('change', '#affiliate-module-toggle', function () {
        var enabled = $(this).is(':checked');

        $.post(data.ajaxurl, {
            action:  'affiliate_toggle_module',
            nonce:   data.toggleNonce,
            enabled: enabled ? 1 : 0,
            request_id: makeRequestId()
        }, function (resp) {
            if (resp.success) {
                location.reload();
            } else {
                alert(resp.data && resp.data.message ? resp.data.message : 'Ошибка');
            }
        });
    });

    /* ── Settings save ── */
    $(document).on('submit', '#affiliate-settings-form', function (e) {
        e.preventDefault();

        var $btn = $('#affiliate-save-settings').prop('disabled', true);

        $.post(data.ajaxurl, {
            action:            'affiliate_save_settings',
            nonce:             data.settingsNonce,
            cookie_ttl:        $('#aff-cookie-ttl').val(),
            rules_url:         $('#aff-rules-url').val(),
            antifraud_enabled: $('#aff-antifraud-enabled').is(':checked') ? 1 : 0,
            request_id:        makeRequestId()
        }, function (resp) {
            $btn.prop('disabled', false);
            if (resp.success) {
                alert(resp.data.message);
            } else {
                alert(resp.data && resp.data.message ? resp.data.message : 'Ошибка');
            }
        });
    });

    /* ── Edit rate modal ── */
    $(document).on('click', '.aff-edit-rate', function () {
        var userId = $(this).data('user-id');
        var currentRate = $(this).data('rate');

        var overlay = $('<div class="aff-rate-modal-overlay">' +
            '<div class="aff-rate-modal">' +
            '<h3>Изменить ставку</h3>' +
            '<div class="aff-rate-input-group">' +
            '<input type="number" id="aff-modal-rate" min="0" max="100" step="0.01" placeholder="Глобальная" value="' + escapeHtml(String(currentRate == null ? '' : currentRate)) + '">' +
            '<span>%</span>' +
            '</div>' +
            '<p><small>Оставьте пустым для глобальной ставки.</small></p>' +
            '<div class="aff-rate-actions">' +
            '<button class="button aff-modal-cancel">Отмена</button>' +
            '<button class="button button-primary aff-modal-save">Сохранить</button>' +
            '</div></div></div>');

        $('body').append(overlay);

        overlay.on('click', '.aff-modal-cancel', function () {
            overlay.remove();
        });

        overlay.on('click', '.aff-modal-save', function () {
            var rate = $('#aff-modal-rate').val();

            $.post(data.ajaxurl, {
                action:         'affiliate_update_partner',
                nonce:          data.partnerNonce,
                user_id:        userId,
                partner_action: 'set_rate',
                rate:           rate,
                request_id:     makeRequestId()
            }, function (resp) {
                overlay.remove();
                if (resp.success) {
                    location.reload();
                } else {
                    alert(resp.data && resp.data.message ? resp.data.message : 'Ошибка');
                }
            });
        });

        overlay.on('click', function (e) {
            if ($(e.target).hasClass('aff-rate-modal-overlay')) {
                overlay.remove();
            }
        });
    });

    /* ── Disable partner ── */
    $(document).on('click', '.aff-disable-partner', function () {
        var userId = $(this).data('user-id');

        if (!confirm('Отключить партнёра от программы? Его партнёрские начисления будут заморожены.')) {
            return;
        }

        $.post(data.ajaxurl, {
            action:         'affiliate_update_partner',
            nonce:          data.partnerNonce,
            user_id:        userId,
            partner_action: 'disable',
            request_id:     makeRequestId()
        }, function (resp) {
            if (resp.success) {
                location.reload();
            } else {
                alert(resp.data && resp.data.message ? resp.data.message : 'Ошибка');
            }
        });
    });

    /* ── Enable partner ── */
    $(document).on('click', '.aff-enable-partner', function () {
        var userId = $(this).data('user-id');

        if (!confirm('Подключить партнёра обратно? Замороженные средства будут разморожены.')) {
            return;
        }

        $.post(data.ajaxurl, {
            action:         'affiliate_update_partner',
            nonce:          data.partnerNonce,
            user_id:        userId,
            partner_action: 'enable',
            request_id:     makeRequestId()
        }, function (resp) {
            if (resp.success) {
                location.reload();
            } else {
                alert(resp.data && resp.data.message ? resp.data.message : 'Ошибка');
            }
        });
    });

    /* ── Bulk commission rate change ── */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    $('#bulk-aff-rate-preview').on('click', function () {
        var oldRate = $('#bulk-aff-old-rate').val().trim();
        var newRate = $('#bulk-aff-new-rate').val().trim();

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

        var $info = $('#bulk-aff-rate-info');
        var $applyBtn = $('#bulk-aff-rate-apply');
        $info.text('Загрузка...');
        $applyBtn.prop('disabled', true);

        $.post(data.ajaxurl, {
            action: 'affiliate_bulk_update_commission_rate',
            nonce: data.bulkRateNonce,
            old_rate: oldRate,
            new_rate: newRate,
            preview: 1
        }, function (response) {
            if (response.success) {
                var count = response.data.count;
                if (count === 0) {
                    $info.text('Не найдено партнёров для обновления.');
                    $applyBtn.prop('disabled', true);
                } else {
                    var label = oldRate.toLowerCase() === 'all'
                        ? 'Будет обновлено партнёров: ' + count + ' (all -> ' + newRate + '%)'
                        : 'Будет обновлено партнёров: ' + count + ' (' + oldRate + '% -> ' + newRate + '%)';
                    $info.text(label);
                    $applyBtn.prop('disabled', false);
                }
            } else {
                $info.text('Ошибка: ' + response.data.message);
                $applyBtn.prop('disabled', true);
            }
        }).fail(function () {
            $info.text('Ошибка соединения.');
            $applyBtn.prop('disabled', true);
        });
    });

    $('#bulk-aff-rate-apply').on('click', function () {
        var oldRate = $('#bulk-aff-old-rate').val().trim();
        var newRate = $('#bulk-aff-new-rate').val().trim();
        var infoText = $('#bulk-aff-rate-info').text();

        if (!confirm('Подтвердите массовое изменение ставки комиссии.\n\n' + infoText)) {
            return;
        }

        var $info = $('#bulk-aff-rate-info');
        var $applyBtn = $('#bulk-aff-rate-apply');
        $applyBtn.prop('disabled', true);
        $info.text('Обновление...');

        $.post(data.ajaxurl, {
            action: 'affiliate_bulk_update_commission_rate',
            nonce: data.bulkRateNonce,
            old_rate: oldRate,
            new_rate: newRate,
            preview: 0,
            request_id: makeRequestId()
        }, function (response) {
            if (response.success) {
                $info.html('<span style="color: green;">Обновлено партнёров: ' + response.data.updated + '</span>');
                $applyBtn.prop('disabled', true);
                $('#bulk-aff-old-rate').val('');
                $('#bulk-aff-new-rate').val('');
                setTimeout(function () {
                    location.reload();
                }, 800);
            } else {
                $info.html('<span style="color: red;">Ошибка: ' + escapeHtml(response.data.message) + '</span>');
            }
        }).fail(function () {
            $info.html('<span style="color: red;">Ошибка соединения.</span>');
        });
    });

    $('#bulk-aff-old-rate, #bulk-aff-new-rate').on('input', function () {
        $('#bulk-aff-rate-apply').prop('disabled', true);
        $('#bulk-aff-rate-info').text('');
    });

    /* ── Edit accrual modal ── */
    $(document).on('click', '.aff-edit-accrual', function () {
        var $btn = $(this);
        var accrualId = $btn.data('id');
        var currentRate = $btn.data('rate');
        var currentAmount = $btn.data('amount');
        var currentCashback = $btn.data('cashback');
        var currentStatus = $btn.data('status');

        var statusOptions = '';
        if (currentStatus === 'pending' || currentStatus === 'declined') {
            statusOptions =
                '<option value="pending"' + (currentStatus === 'pending' ? ' selected' : '') + '>В ожидании</option>' +
                '<option value="declined"' + (currentStatus === 'declined' ? ' selected' : '') + '>Отклонён</option>';
        } else {
            var statusLabels = {frozen: 'Заморожено', paid: 'Выплачено'};
            statusOptions = '<option value="' + currentStatus + '" selected>' + (statusLabels[currentStatus] || currentStatus) + '</option>';
        }

        var overlay = $('<div class="aff-rate-modal-overlay">' +
            '<div class="aff-rate-modal aff-edit-modal">' +
            '<h3>Редактирование начисления</h3>' +
            '<table class="form-table"><tbody>' +
            '<tr><th>Кешбэк реферала</th><td><strong>' + escapeHtml(String(currentCashback)) + ' ₽</strong></td></tr>' +
            '<tr><th><label for="aff-edit-rate">Ставка (%)</label></th>' +
            '<td><input type="number" id="aff-edit-rate" min="0" max="100" step="0.01" value="' + escapeHtml(String(currentRate == null ? '' : currentRate)) + '" class="small-text"></td></tr>' +
            '<tr><th><label for="aff-edit-amount">Комиссия (₽)</label></th>' +
            '<td><input type="number" id="aff-edit-amount" min="0" step="0.01" value="' + escapeHtml(String(currentAmount == null ? '' : currentAmount)) + '" class="small-text"></td></tr>' +
            '<tr><th><label for="aff-edit-status">Статус</label></th>' +
            '<td><select id="aff-edit-status">' + statusOptions + '</select></td></tr>' +
            '</tbody></table>' +
            '<div class="aff-rate-actions">' +
            '<button class="button aff-modal-cancel">Отмена</button>' +
            '<button class="button button-primary aff-edit-save">Сохранить</button>' +
            '</div></div></div>');

        $('body').append(overlay);

        // Пересчёт комиссии при изменении ставки (целочисленная арифметика в копейках)
        overlay.on('input', '#aff-edit-rate', function () {
            var rateStr = String($(this).val()).trim().replace(',', '.');
            if (!/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(rateStr)) {
                overlay.find('#aff-edit-amount').val('');
                return;
            }
            var cashbackStr = String(currentCashback == null ? '' : currentCashback).trim().replace(',', '.');
            var cbMatch = /^(\d+)(?:\.(\d{1,2}))?$/.exec(cashbackStr);
            var rateMatch = /^(\d+)(?:\.(\d{1,2}))?$/.exec(rateStr);
            if (!cbMatch || !rateMatch) {
                overlay.find('#aff-edit-amount').val('');
                return;
            }
            var cashbackKop = parseInt(cbMatch[1], 10) * 100 +
                parseInt(((cbMatch[2] || '') + '00').slice(0, 2), 10);
            var rateHundredths = parseInt(rateMatch[1], 10) * 100 +
                parseInt(((rateMatch[2] || '') + '00').slice(0, 2), 10);
            var commissionKop = Math.round(cashbackKop * rateHundredths / 10000);
            var whole = Math.floor(commissionKop / 100);
            var frac = commissionKop % 100;
            overlay.find('#aff-edit-amount').val(whole + '.' + (frac < 10 ? '0' + frac : String(frac)));
        });

        overlay.on('click', '.aff-modal-cancel', function () {
            overlay.remove();
        });

        overlay.on('click', '.aff-edit-save', function () {
            var $saveBtn = $(this).prop('disabled', true);

            $.post(data.ajaxurl, {
                action:            'affiliate_edit_accrual',
                nonce:             data.editAccrualNonce,
                accrual_id:        accrualId,
                commission_rate:   overlay.find('#aff-edit-rate').val(),
                commission_amount: overlay.find('#aff-edit-amount').val(),
                status:            overlay.find('#aff-edit-status').val(),
                request_id:        makeRequestId()
            }, function (resp) {
                overlay.remove();
                if (resp.success) {
                    location.reload();
                } else {
                    alert(resp.data && resp.data.message ? resp.data.message : 'Ошибка');
                }
            }).fail(function () {
                $saveBtn.prop('disabled', false);
                alert('Ошибка соединения.');
            });
        });

        overlay.on('click', function (e) {
            if ($(e.target).hasClass('aff-rate-modal-overlay')) {
                overlay.remove();
            }
        });
    });

})(jQuery);
