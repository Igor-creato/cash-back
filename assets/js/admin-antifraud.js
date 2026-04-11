/**
 * Cashback Antifraud Admin Page — JS
 *
 * Handles: alert review, alert details modal, settings save, manual scan, user ban.
 *
 * @since 1.2.0
 */
(function ($) {
    'use strict';

    if (typeof cashbackFraudData === 'undefined') {
        return;
    }

    var ajaxurl = cashbackFraudData.ajaxurl;

    // ===========================
    // Run manual scan
    // ===========================

    $('#fraud-run-scan').on('click', function () {
        var $btn = $(this);
        if ($btn.prop('disabled')) return;

        $btn.prop('disabled', true).text('Сканирование...');

        $.post(ajaxurl, {
            action: 'fraud_run_scan_now',
            nonce: cashbackFraudData.scanNonce
        })
        .done(function (response) {
            if (response.success) {
                alert(response.data.message);
                location.reload();
            } else {
                alert(response.data.message || 'Ошибка');
            }
        })
        .fail(function () {
            alert('Ошибка сети');
        })
        .always(function () {
            $btn.prop('disabled', false).text('Запустить проверку');
        });
    });

    // ===========================
    // Alert review (confirm / dismiss)
    // ===========================

    $(document).on('click', '.fraud-review-btn', function () {
        var $btn = $(this);
        var alertId = $btn.data('id');
        var newStatus = $btn.data('action');

        var statusLabel = newStatus === 'confirmed' ? 'подтвердить' : 'отклонить';
        if (!confirm('Вы уверены, что хотите ' + statusLabel + ' алерт #' + alertId + '?')) {
            return;
        }

        var reviewNote = prompt('Комментарий (необязательно):') || '';

        $btn.prop('disabled', true);

        $.post(ajaxurl, {
            action: 'fraud_review_alert',
            nonce: cashbackFraudData.reviewNonce,
            alert_id: alertId,
            new_status: newStatus,
            review_note: reviewNote
        })
        .done(function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data.message || 'Ошибка');
                $btn.prop('disabled', false);
            }
        })
        .fail(function () {
            alert('Ошибка сети');
            $btn.prop('disabled', false);
        });
    });

    // ===========================
    // Alert details modal
    // ===========================

    $(document).on('click', '.fraud-view-details', function () {
        var alertId = $(this).data('id');
        var $modal = $('#fraud-alert-modal');
        var $body = $('#fraud-alert-modal-body');

        $body.html('<p>Загрузка...</p>');
        $modal.show();

        $.post(ajaxurl, {
            action: 'fraud_get_alert_details',
            nonce: cashbackFraudData.detailsNonce,
            alert_id: alertId
        })
        .done(function (response) {
            if (!response.success) {
                $body.html('<p>' + escHtml(response.data.message || 'Ошибка') + '</p>');
                return;
            }

            var data = response.data;
            var alert = data.alert;
            var signals = data.signals;
            var balance = data.balance;

            var html = '<h2>Алерт #' + escHtml(alert.id) + '</h2>';

            // Alert info
            html += '<table class="widefat fixed" style="margin-bottom:15px;">';
            html += '<tbody>';
            html += rowHtml('Пользователь', escHtml(alert.user_login || 'ID:' + alert.user_id) + ' (' + escHtml(alert.user_email || '') + ')');
            html += row('Зарегистрирован', alert.user_registered || 'N/A');
            html += row('Тип', alert.alert_type);
            html += rowHtml('Уровень', '<span class="cashback-severity-' + escHtml(alert.severity) + '">' + escHtml(alert.severity.toUpperCase()) + '</span>');
            html += row('Риск-скор', parseFloat(alert.risk_score).toFixed(0));
            html += row('Статус', alert.status);
            html += row('Описание', alert.summary);
            html += row('Создан', alert.created_at);

            if (alert.reviewed_by) {
                html += rowHtml('Проверил', 'ID:' + escHtml(alert.reviewed_by) + ' от ' + escHtml(alert.reviewed_at || ''));
            }
            if (alert.review_note) {
                html += row('Комментарий', alert.review_note);
            }
            html += '</tbody></table>';

            // Balance
            if (balance) {
                html += '<h3>Баланс пользователя</h3>';
                html += '<table class="widefat fixed" style="margin-bottom:15px;">';
                html += '<tbody>';
                html += row('Доступно', balance.available_balance);
                html += row('В обработке', balance.pending_balance);
                html += row('Выплачено', balance.paid_balance);
                html += row('Заморожено', balance.frozen_balance);
                html += '</tbody></table>';
            }

            // Signals
            if (signals && signals.length > 0) {
                html += '<h3>Сигналы (' + signals.length + ')</h3>';
                html += '<table class="widefat striped">';
                html += '<thead><tr><th>Тип</th><th>Вес</th><th>Данные</th></tr></thead><tbody>';

                for (var i = 0; i < signals.length; i++) {
                    var s = signals[i];
                    var evidence = s.evidence || {};
                    var evidenceStr = '';

                    for (var key in evidence) {
                        if (evidence.hasOwnProperty(key)) {
                            var val = evidence[key];
                            if (typeof val === 'object') {
                                val = JSON.stringify(val);
                            }
                            evidenceStr += '<strong>' + escHtml(key) + ':</strong> ' + escHtml(String(val)) + '<br>';
                        }
                    }

                    html += '<tr>';
                    html += '<td>' + escHtml(s.signal_type) + '</td>';
                    html += '<td>' + escHtml(parseFloat(s.weight).toFixed(0)) + '</td>';
                    html += '<td>' + (evidenceStr || escHtml('—')) + '</td>';
                    html += '</tr>';
                }
                html += '</tbody></table>';
            }

            // Alert details JSON
            if (alert.details && Object.keys(alert.details).length > 0) {
                html += '<h3>Дополнительные данные</h3>';
                html += '<pre style="background:#f6f7f7;padding:10px;overflow:auto;max-height:200px;">' + escHtml(JSON.stringify(alert.details, null, 2)) + '</pre>';
            }

            $body.html(html);
        })
        .fail(function () {
            $body.html('<p>Ошибка сети</p>');
        });
    });

    // Close modal
    $(document).on('click', '.cashback-modal-close', function () {
        $('#fraud-alert-modal').hide();
    });

    $(document).on('click', '#fraud-alert-modal', function (e) {
        if (e.target === this) {
            $(this).hide();
        }
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            $('#fraud-alert-modal').hide();
        }
    });

    // ===========================
    // Save settings
    // ===========================

    $('#fraud-settings-form').on('submit', function (e) {
        e.preventDefault();

        var $btn = $('#fraud-save-settings');
        var $msg = $('#fraud-settings-message');

        $btn.prop('disabled', true).text('Сохранение...');
        $msg.hide();

        // Collect settings as key-value object
        var settings = {};
        $(this).find('input, select').each(function () {
            var $input = $(this);
            var name = $input.attr('name');
            if (!name) return;

            if ($input.attr('type') === 'checkbox') {
                settings[name] = $input.is(':checked') ? '1' : '0';
            } else {
                settings[name] = $input.val();
            }
        });

        $.post(ajaxurl, {
            action: 'fraud_save_settings',
            nonce: cashbackFraudData.settingsNonce,
            settings: settings
        })
        .done(function (response) {
            if (response.success) {
                $msg.html('<div class="notice notice-success"><p>' + escHtml(response.data.message) + '</p></div>').show();
            } else {
                $msg.html('<div class="notice notice-error"><p>' + escHtml(response.data.message || 'Ошибка') + '</p></div>').show();
            }
        })
        .fail(function () {
            $msg.html('<div class="notice notice-error"><p>Ошибка сети</p></div>').show();
        })
        .always(function () {
            $btn.prop('disabled', false).text('Сохранить настройки');
        });
    });

    // ===========================
    // Bot protection settings
    // ===========================

    $('#bot-protection-settings-form').on('submit', function (e) {
        e.preventDefault();

        var $btn = $('#bot-protection-save-settings');
        var $msg = $('#bot-protection-settings-message');

        $btn.prop('disabled', true).text('Сохранение...');
        $msg.hide();

        var settings = {};
        $(this).find('input, select').each(function () {
            var $input = $(this);
            var name = $input.attr('name');
            if (!name) return;

            if ($input.attr('type') === 'checkbox') {
                settings[name] = $input.is(':checked') ? '1' : '0';
            } else {
                settings[name] = $input.val();
            }
        });

        $.post(ajaxurl, {
            action: 'fraud_save_bot_settings',
            nonce: cashbackFraudData.botSettingsNonce,
            settings: settings
        })
        .done(function (response) {
            if (response.success) {
                $msg.html('<div class="notice notice-success"><p>' + escHtml(response.data.message) + '</p></div>').show();
            } else {
                $msg.html('<div class="notice notice-error"><p>' + escHtml(response.data.message || 'Ошибка') + '</p></div>').show();
            }
        })
        .fail(function () {
            $msg.html('<div class="notice notice-error"><p>Ошибка сети</p></div>').show();
        })
        .always(function () {
            $btn.prop('disabled', false).text('Сохранить настройки бот-защиты');
        });
    });

    // ===========================
    // Ban user
    // ===========================

    $(document).on('click', '.fraud-ban-user-btn', function () {
        var $btn = $(this);
        var userId = $btn.data('user-id');

        var reason = prompt('Причина бана (обязательно):');
        if (reason === null) return; // cancelled
        if (!reason.trim()) {
            reason = 'Заблокирован антифрод-системой';
        }
        if (reason.length > 500) {
            reason = reason.substring(0, 500);
        }

        if (!confirm('Забанить пользователя ID ' + userId + '?\n\nПричина: ' + reason + '\n\nБаланс будет заморожен, активные заявки отклонены.')) {
            return;
        }

        $btn.prop('disabled', true).text('Бан...');

        $.post(ajaxurl, {
            action: 'fraud_ban_user',
            nonce: cashbackFraudData.banNonce,
            user_id: userId,
            ban_reason: reason
        })
        .done(function (response) {
            if (response.success) {
                alert(response.data.message);
                location.reload();
            } else {
                alert(response.data.message || 'Ошибка');
                $btn.prop('disabled', false).text('Забанить');
            }
        })
        .fail(function () {
            alert('Ошибка сети');
            $btn.prop('disabled', false).text('Забанить');
        });
    });

    // ===========================
    // Helpers
    // ===========================

    function escHtml(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function row(label, value) {
        return '<tr><th style="width:180px;">' + escHtml(label) + '</th><td>' + escHtml(value) + '</td></tr>';
    }

    function rowHtml(label, safeHtml) {
        return '<tr><th style="width:180px;">' + escHtml(label) + '</th><td>' + safeHtml + '</td></tr>';
    }

})(jQuery);
