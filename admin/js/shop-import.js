/**
 * Кэшбэк → Импорт магазинов: real-time фидбэк кнопки «Импортировать сейчас».
 *
 * Прогрессивное улучшение: без JS форма работает как раньше (POST →
 * admin-post.php → redirect). С JS — AJAX-запуск + polling строк лога без
 * перезагрузки страницы.
 *
 * @since 1.0.0
 */
/* global jQuery, cashbackShopImport */
(function ($) {
    'use strict';

    var cfg = window.cashbackShopImport || {};
    var i18n = cfg.i18n || {};

    function escapeHtml(str) {
        return String(str == null ? '' : str).replace(/[&<>"']/g, function (c) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[c];
        });
    }

    function showNotice(type, message) {
        var $n = $('#cashback-shop-import-notice');
        if (!$n.length) {
            return;
        }
        $n.removeClass('notice-success notice-error notice-warning')
            .addClass(type === 'error' ? 'notice-error' : (type === 'warning' ? 'notice-warning' : 'notice-success'))
            .show();
        $n.find('p').first().text(message);
    }

    function sprintf1(tpl, val) {
        return String(tpl || '').replace('%s', val);
    }

    // Optimistic-плейсхолдер: 10 колонок, бейдж «В обработке».
    function optimisticRow(runId, networkName) {
        var shortId = escapeHtml(String(runId).slice(0, 16)) + '…';
        return '' +
            '<tr data-run="' + escapeHtml(runId) + '" class="cashback-import-optimistic">' +
            '<td>—</td>' +
            '<td><code>' + shortId + '</code></td>' +
            '<td>' + escapeHtml(networkName) + '</td>' +
            '<td>—</td><td>—</td><td>—</td><td>—</td><td>—</td>' +
            '<td><span class="cashback-import-status--pending">⏳ ' +
            escapeHtml(i18n.processing || 'В обработке') + '</span></td>' +
            '<td></td>' +
            '</tr>';
    }

    function replaceRunRows(runId, rowsHtml) {
        var $tbody = $('#cashback-shop-import-rows');
        if (!$tbody.length) {
            return;
        }
        $tbody.find('.cashback-import-empty').remove();
        $tbody.find('tr[data-run="' + (window.CSS && CSS.escape ? CSS.escape(runId) : runId) + '"]').remove();
        if (rowsHtml && $.trim(rowsHtml) !== '') {
            $tbody.prepend(rowsHtml);
        }
    }

    // Один независимый poll-цикл на каждый запущенный run.
    function startPolling(runId, networkName) {
        var polls = 0;
        var settle = 0;
        var maxPolls = parseInt(cfg.maxPolls, 10) || 150;
        var settleNeeded = parseInt(cfg.settlePolls, 10) || 3;
        var interval = parseInt(cfg.pollInterval, 10) || 3000;
        var netErrors = 0;
        var sawRows = false;

        function poll() {
            $.ajax({
                url: cfg.ajaxUrl,
                method: 'POST',
                data: {
                    action: cfg.statusAction,
                    nonce: cfg.nonce,
                    run_id: runId
                }
            }).done(function (resp) {
                netErrors = 0;
                if (!resp || !resp.success || !resp.data) {
                    scheduleNext();
                    return;
                }
                var d = resp.data;

                if (d.count > 0) {
                    sawRows = true;
                    replaceRunRows(runId, d.rows_html);
                } else if (d.pending && !sawRows) {
                    showNotice('warning', i18n.queued || 'Задача в очереди.');
                }

                if (d.done) {
                    settle += 1;
                    if (settle >= settleNeeded) {
                        return; // завершено устойчиво — стоп.
                    }
                } else {
                    settle = 0;
                }
                scheduleNext();
            }).fail(function (xhr) {
                netErrors += 1;
                if (netErrors >= 3) {
                    showNotice('error', (i18n.netError || 'Ошибка сети') +
                        ': ' + (xhr ? xhr.status : '?'));
                    return;
                }
                scheduleNext();
            });
        }

        function scheduleNext() {
            polls += 1;
            if (polls >= maxPolls) {
                showNotice('warning', i18n.stillRunning ||
                    'Импорт ещё выполняется — обновите страницу позже.');
                return;
            }
            window.setTimeout(poll, interval);
        }

        // Первый опрос — чуть раньше (воркер мог уже создать строку).
        window.setTimeout(poll, 1200);
    }

    $(function () {
        if (!cfg.ajaxUrl || !cfg.nonce) {
            return; // нет конфига — деградация до обычной формы.
        }

        $('.cashback-shop-import-form').on('submit', function (e) {
            e.preventDefault();

            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            var networkId = parseInt($form.find('input[name="network_id"]').val(), 10) || 0;

            if (networkId <= 0 || $btn.prop('disabled')) {
                return;
            }

            if (!$btn.data('origText')) {
                $btn.data('origText', $btn.text());
            }
            $btn.prop('disabled', true).text(i18n.starting || 'Запуск…');

            $.ajax({
                url: cfg.ajaxUrl,
                method: 'POST',
                data: {
                    action: cfg.triggerAction,
                    nonce: cfg.nonce,
                    network_id: networkId
                }
            }).done(function (resp) {
                $btn.prop('disabled', false).text($btn.data('origText'));

                if (!resp || !resp.success || !resp.data || !resp.data.run_id) {
                    showNotice('error', (resp && resp.data && resp.data.message) ||
                        (i18n.netError || 'Ошибка'));
                    return;
                }

                var runId = String(resp.data.run_id);
                var networkName = String(resp.data.network_name || '');

                showNotice('success',
                    sprintf1(i18n.started || 'Импорт запущен (run_id=%s)', runId));
                replaceRunRows(runId, optimisticRow(runId, networkName));
                startPolling(runId, networkName);
            }).fail(function (xhr) {
                $btn.prop('disabled', false).text($btn.data('origText'));
                showNotice('error', (i18n.netError || 'Ошибка сети') +
                    ': ' + (xhr ? xhr.status : '?'));
            });
        });
    });
})(jQuery);
