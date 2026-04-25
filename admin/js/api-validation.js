/**
 * Cashback API Validation — Admin JS
 *
 * AJAX-обработчики:
 * - Кнопка «Проверить пользователя»
 * - Сохранение настроек API
 * - Ручной запуск синхронизации
 * - Загрузка лога синхронизации
 * - Inline-кнопки на странице выплат
 *
 * @since 5.0.0
 */

(function ($) {
    'use strict';

    const config = window.cashbackApiValidation || {};
    const i18n = config.i18n || {};

    // =========================================================================
    // Пагинация таблиц расхождений (использует общий хелпер CashbackPagination)
    // =========================================================================

    const ITEMS_PER_PAGE = 20;
    const paginationStore = {};

    function buildPaginationHtml(currentPage, totalPages, totalItems, perPage) {
        const start = (currentPage - 1) * perPage + 1;
        const end = Math.min(currentPage * perPage, totalItems);
        const nav = window.CashbackPagination
            ? window.CashbackPagination.build(currentPage, totalPages, { containerClass: 'cashback-admin-pagination' })
            : '';

        let html = '<div class="cashback-pagination-wrap tablenav bottom">';
        html += '<span class="displaying-num">Показано ' + start + '–' + end + ' из ' + totalItems + '</span>';
        html += nav;
        html += '</div>';
        return html;
    }

    function setupPaginatedTable(tabId, items, theadHtml, renderRowFn, showNetCol, emptyMsg) {
        const totalPages = Math.ceil(items.length / ITEMS_PER_PAGE);
        paginationStore[tabId] = { items, renderRowFn, showNetCol, totalPages };

        if (items.length === 0) {
            return '<p class="validation-empty">' + escHtml(emptyMsg) + '</p>';
        }

        let html = '<div class="validation-paginated-table" data-tab-id="' + tabId + '" data-page="1">';
        html += '<table class="widefat striped"><thead>' + theadHtml + '</thead>';
        html += '<tbody>' + renderPageRows(tabId, 1) + '</tbody></table>';
        html += buildPaginationHtml(1, totalPages, items.length, ITEMS_PER_PAGE);
        html += '</div>';
        return html;
    }

    function renderPageRows(tabId, page) {
        const store = paginationStore[tabId];
        const start = (page - 1) * ITEMS_PER_PAGE;
        const end = Math.min(start + ITEMS_PER_PAGE, store.items.length);
        let html = '';
        for (let i = start; i < end; i++) {
            html += store.renderRowFn(store.items[i], store.showNetCol);
        }
        return html;
    }

    $(document).on('click', '.validation-paginated-table .page-numbers[data-page]', function (e) {
        e.preventDefault();
        const $link = $(this);
        if ($link.hasClass('current')) return;

        const $wrap = $link.closest('.validation-paginated-table');
        const tabId = $wrap.data('tab-id');
        const store = paginationStore[tabId];
        if (!store) return;

        const newPage = parseInt($link.data('page'), 10);
        if (!newPage || newPage < 1 || newPage > store.totalPages) return;

        $wrap.data('page', newPage);
        $wrap.find('tbody').html(renderPageRows(tabId, newPage));
        $wrap.find('.cashback-pagination-wrap').replaceWith(
            buildPaginationHtml(newPage, store.totalPages, store.items.length, ITEMS_PER_PAGE)
        );
    });

    // =========================================================================
    // Валидация пользователя (вкладка «Проверка»)
    // =========================================================================

    $(document).on('click', '#cashback-validate-btn', function () {
        const $btn = $(this);
        const userId = $('#cashback-validate-user-id').val();
        const network = $('#cashback-validate-network').val();
        const fullCheck = $('#cashback-validate-full').is(':checked');

        if (userId === '' || userId === null || userId === undefined || userId < 0) {
            alert('Укажите корректный User ID (0 = незарегистрированные)');
            return;
        }

        $btn.prop('disabled', true).text(i18n.validating || 'Проверка...');
        $('#cashback-validation-result').hide();

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cashback_validate_user',
                nonce: config.nonce,
                user_id: userId,
                network: network,
                full_check: fullCheck ? 1 : 0,
            },
            success: function (response) {
                $btn.prop('disabled', false).text(i18n.validate || '🔍 Проверить пользователя');

                if (response.success && response.data) {
                    renderValidationResult(response.data);
                } else {
                    renderValidationError(response.data?.message || response.data?.error || 'Неизвестная ошибка');
                }
            },
            error: function (xhr) {
                $btn.prop('disabled', false).text(i18n.validate || '🔍 Проверить пользователя');
                renderValidationError('Ошибка сети: ' + xhr.status + ' ' + xhr.statusText);
            },
        });
    });

    /**
     * Рендер результата валидации
     */
    function renderValidationResult(data) {
        // Мульти-сетевой результат — нормализуем в единую структуру
        if (data.multi_network) {
            data = normalizeMultiNetworkData(data);
        }

        const $result = $('#cashback-validation-result');
        let html = '';

        const isMatch = data.status === 'match';
        const statusClass = isMatch ? 'notice-success' : 'notice-warning';
        const statusText = isMatch
            ? (i18n.match || '✅ Данные совпадают')
            : (i18n.mismatch || '⚠️ Обнаружены расхождения');

        html += `<div class="notice ${statusClass}"><p><strong>${statusText}</strong></p></div>`;

        // Ошибки по сетям (если есть)
        if (data._errors && Object.keys(data._errors).length > 0) {
            html += '<div class="notice notice-error"><p>';
            for (const [slug, msg] of Object.entries(data._errors)) {
                html += `<strong>${escHtml(slug)}:</strong> ${escHtml(msg)}<br>`;
            }
            html += '</p></div>';
        }

        // Сводка
        html += '<table class="widefat fixed" style="max-width:700px;">';
        html += '<thead><tr><th colspan="2">Сводка проверки</th></tr></thead>';
        html += '<tbody>';
        const userLabel = data.user_id === 0 ? 'Незарегистрированные' : `#${data.user_id}`;
        html += `<tr><td>Пользователь</td><td><strong>${userLabel}</strong></td></tr>`;
        html += `<tr><td>Сеть</td><td>${escHtml(data._networkLabel || data.network)}</td></tr>`;
        html += `<tr><td>Период</td><td>${escHtml(data.date_range?.start || '')} — ${escHtml(data.date_range?.end || '')}</td></tr>`;
        const apiTotal = data.api_total || 0;
        const localTotal = data.local_total || 0;
        const apiTotalStyle = apiTotal === 0 && localTotal > 0 ? ' style="color:red;font-weight:bold;"' : '';
        html += `<tr><td>Действий в API</td><td${apiTotalStyle}>${apiTotal}</td></tr>`;
        html += `<tr><td>Транзакций локально</td><td>${localTotal}</td></tr>`;
        html += `<tr><td>Совпадений</td><td style="color:green;">${data.matched_count || 0}</td></tr>`;
        html += `<tr><td>Расхождений</td><td style="color:${data.mismatch_count > 0 ? 'red' : 'green'};">${data.mismatch_count || 0}</td></tr>`;
        html += '</tbody></table>';

        // Предупреждение: API вернул 0, но локально есть данные
        if (apiTotal === 0 && localTotal > 0) {
            const userId = data.user_id !== undefined ? data.user_id : '?';
            html += `<div class="notice notice-warning" style="margin-top:15px;padding:10px 15px;">
                <p><strong>⚠️ API вернул 0 транзакций</strong> при ${localTotal} локальных — все они отображаются как «Есть на сайте, нет в API».</p>
                <p style="margin-top:6px;">Возможные причины:</p>
                <ul style="list-style:disc;padding-left:20px;margin-top:4px;">
                    <li>В тестовом сервере нет транзакций — добавьте их через интерфейс mock-сервера.</li>
                    <li>Неверный <code>subid2</code> в тестовых данных — должен быть равен User ID = <strong>${userId}</strong>.</li>
                    <li>Неверный <code>subid1</code> (click_id) — должен совпадать с UUID из таблицы <code>cashback_click_log</code>.</li>
                    <li>Некорректные API credentials или недоступный endpoint.</li>
                </ul>
            </div>`;
        }

        // Финансовая сверка
        if (data.sums) {
            html += '<table class="widefat fixed" style="max-width:700px; margin-top:15px;">';
            html += '<thead><tr><th colspan="2">Финансовая сверка</th></tr></thead>';
            html += '<tbody>';
            html += `<tr><td>API approved</td><td>${formatMoney(data.sums.api_approved)}</td></tr>`;
            html += `<tr><td>API pending</td><td>${formatMoney(data.sums.api_pending)}</td></tr>`;
            html += `<tr><td>API declined</td><td>${formatMoney(data.sums.api_declined)}</td></tr>`;
            html += `<tr><td>Локальная сумма approved</td><td>${formatMoney(data.sums.local_approved)}</td></tr>`;
            html += `<tr><td>Локальная сумма pending</td><td>${formatMoney(data.sums.local_pending)}</td></tr>`;
            html += `<tr><td>Локальная сумма declined</td><td>${formatMoney(data.sums.local_declined)}</td></tr>`;

            const discStyle = data.sums.discrepancy > 0.01 ? 'color:red; font-weight:bold;' : 'color:green;';
            html += `<tr><td>Расхождение</td><td style="${discStyle}">${formatMoney(data.sums.discrepancy)}</td></tr>`;
            html += '</tbody></table>';
        }

        // ─── Вкладки с расхождениями ───
        const mismatchedCount = (data.mismatched || []).length;
        const missingLocalCount = (data.missing_local || []).length;
        const missingApiCount = (data.missing_api || []).length;
        const totalIssues = mismatchedCount + missingLocalCount + missingApiCount;
        const showNetCol = data._isMultiNetwork;

        if (totalIssues > 0) {
            // Навигация вкладок
            html += '<nav class="validation-result-tabs nav-tab-wrapper" style="margin-top:20px;">';
            html += `<a href="#" class="nav-tab nav-tab-active" data-tab="tab-mismatched">Расхождения <span class="tab-badge${mismatchedCount > 0 ? ' badge-red' : ''}">${mismatchedCount}</span></a>`;
            html += `<a href="#" class="nav-tab" data-tab="tab-missing-local">Есть в API, нет на сайте <span class="tab-badge${missingLocalCount > 0 ? ' badge-red' : ''}">${missingLocalCount}</span></a>`;
            html += `<a href="#" class="nav-tab" data-tab="tab-missing-api">Есть на сайте, нет в API <span class="tab-badge${missingApiCount > 0 ? ' badge-red' : ''}">${missingApiCount}</span></a>`;
            html += '</nav>';

            // Вкладка 1: Расхождения
            html += '<div class="validation-tab-content" id="tab-mismatched">';
            {
                let thead = '<tr>';
                if (showNetCol) thead += '<th>Сеть</th>';
                thead += '<th>Action ID</th><th>Uniq ID</th><th>API статус</th><th>Локальный статус</th><th>API сумма</th><th>Локальная сумма</th><th>Проблема</th><th>Действия</th></tr>';
                html += setupPaginatedTable('tab-mismatched', data.mismatched || [], thead, renderMismatchRow, showNetCol, 'Нет расхождений в сопоставленных данных.');
            }
            html += '</div>';

            // Вкладка 2: Есть в API, нет на сайте
            html += '<div class="validation-tab-content" id="tab-missing-local" style="display:none;">';
            {
                let thead = '<tr>';
                if (showNetCol) thead += '<th>Сеть</th>';
                thead += '<th>Action ID</th><th>Order ID</th><th>Статус</th><th>Комиссия</th><th>Сумма заказа</th><th>Дата</th><th>Магазин</th><th>Действия</th></tr>';
                html += setupPaginatedTable('tab-missing-local', data.missing_local || [], thead, renderMissingLocalRow, showNetCol, 'Все данные из API найдены в локальной базе.');
            }
            html += '</div>';

            // Вкладка 3: Есть на сайте, нет в API
            html += '<div class="validation-tab-content" id="tab-missing-api" style="display:none;">';
            {
                let thead = '<tr>';
                if (showNetCol) thead += '<th>Сеть</th>';
                thead += '<th>Local ID</th><th>Uniq ID</th><th>Click ID</th><th>Статус</th><th>Комиссия</th><th>Сумма заказа</th><th>Создано</th><th>Добавлена админом</th><th>Действия</th></tr>';
                html += setupPaginatedTable('tab-missing-api', data.missing_api || [], thead, renderMissingApiRow, showNetCol, 'Все локальные транзакции найдены в API.');
            }
            html += '</div>';
        }

        $result.html(html).fadeIn();
    }

    /**
     * Нормализует мульти-сетевой ответ в формат, совместимый с renderValidationResult.
     */
    function normalizeMultiNetworkData(data) {
        const t = data.totals || {};
        return {
            user_id: data.user_id,
            network: '__all__',
            _isMultiNetwork: true,
            _networkLabel: (data.network_names || []).join(', ') || 'Все сети',
            _errors: data.errors || {},
            status: data.status,
            date_range: null,
            api_total: t.api_total || 0,
            local_total: t.local_total || 0,
            matched_count: t.matched_count || 0,
            mismatch_count: t.mismatch_count || 0,
            sums: t.sums || null,
            mismatched: t.mismatched || [],
            missing_local: t.missing_local || [],
            missing_api: t.missing_api || [],
        };
    }

    // Переключение вкладок результата
    $(document).on('click', '.validation-result-tabs .nav-tab', function (e) {
        e.preventDefault();
        const $tab = $(this);
        const targetId = $tab.data('tab');

        $tab.siblings('.nav-tab').removeClass('nav-tab-active');
        $tab.addClass('nav-tab-active');

        $tab.closest('#cashback-validation-result').find('.validation-tab-content').hide();
        $('#' + targetId).show();
    });

    /**
     * Рендер ошибки валидации
     */
    function renderValidationError(message) {
        const $result = $('#cashback-validation-result');
        $result.html(`<div class="notice notice-error"><p><strong>${i18n.error || '❌ Ошибка'}</strong>: ${escHtml(message)}</p></div>`);
        $result.fadeIn();
    }

    // =========================================================================
    // Выбор сети из dropdown
    // =========================================================================

    $(document).on('change', '#cashback-network-selector', function () {
        const networkId = $(this).val();
        $('#cashback-api-settings .cashback-network-card').hide();
        if (networkId) {
            $('#cashback-api-settings .cashback-network-card[data-network-id="' + networkId + '"]').show();
        }
    });

    // =========================================================================
    // Переключение полей авторизации (OAuth2 / API Key)
    // =========================================================================

    $(document).on('change', '.cashback-auth-type-select', function () {
        const type = $(this).val();
        const $card = $(this).closest('.cashback-network-card');
        $card.find('.auth-oauth2').toggle(type === 'oauth2');
        $card.find('.auth-api-key').toggle(type === 'api_key');
    });

    // =========================================================================
    // Визуальный редактор маппинга статусов
    // =========================================================================

    function statusMapRowHtml() {
        return '<div class="status-map-row">'
            + '<input type="text" class="status-map-cpa regular-text" placeholder="статус CPA" value="">'
            + '<span class="status-map-arrow">→</span>'
            + '<select class="status-map-local">'
            + '<option value="waiting">waiting</option>'
            + '<option value="hold">hold</option>'
            + '<option value="completed">completed</option>'
            + '<option value="declined">declined</option>'
            + '</select>'
            + '<button type="button" class="status-map-remove button-link">'
            + '<span class="dashicons dashicons-no-alt" style="color:#dc3232;"></span>'
            + '</button>'
            + '</div>';
    }

    $(document).on('click', '.status-map-add-btn', function () {
        $(this).prev('.status-map-editor').append(statusMapRowHtml());
    });

    $(document).on('click', '.status-map-remove', function () {
        $(this).closest('.status-map-row').remove();
    });

    function serializeStatusMap($card) {
        const map = {};
        $card.find('.status-map-row').each(function () {
            const key = $(this).find('.status-map-cpa').val().trim();
            const val = $(this).find('.status-map-local').val();
            if (key) {
                map[key] = val;
            }
        });
        $card.find('input[name="api_status_map"]').val(JSON.stringify(map));
    }

    // =========================================================================
    // Визуальный редактор маппинга полей API
    // =========================================================================

    function fieldMapRowHtml() {
        return '<div class="field-map-row">'
            + '<input type="text" class="field-map-api regular-text" placeholder="поле API" value="">'
            + '<span class="field-map-arrow">→</span>'
            + '<select class="field-map-local">'
            + '<option value="comission">comission (комиссия)</option>'
            + '<option value="sum_order">sum_order (сумма заказа)</option>'
            + '<option value="uniq_id">uniq_id (ID действия)</option>'
            + '<option value="order_number">order_number (номер заказа)</option>'
            + '<option value="offer_id">offer_id (ID оффера)</option>'
            + '<option value="offer_name">offer_name (название оффера)</option>'
            + '<option value="currency">currency (валюта)</option>'
            + '<option value="action_date">action_date (дата покупки)</option>'
            + '<option value="click_time">click_time (время клика)</option>'
            + '<option value="action_type">action_type (тип действия)</option>'
            + '<option value="website_id">website_id (ID площадки)</option>'
            + '<option value="funds_ready">funds_ready (готовность к выплате)</option>'
            + '</select>'
            + '<button type="button" class="field-map-remove button-link">'
            + '<span class="dashicons dashicons-no-alt" style="color:#dc3232;"></span>'
            + '</button>'
            + '</div>';
    }

    $(document).on('click', '.field-map-add-btn', function () {
        $(this).prev('.field-map-editor').append(fieldMapRowHtml());
    });

    $(document).on('click', '.field-map-remove', function () {
        $(this).closest('.field-map-row').remove();
    });

    function serializeFieldMap($card) {
        const map = {};
        $card.find('.field-map-row').each(function () {
            const key = $(this).find('.field-map-api').val().trim();
            const val = $(this).find('.field-map-local').val();
            if (key) {
                map[key] = val;
            }
        });
        $card.find('input[name="api_field_map"]').val(JSON.stringify(map));
    }

    // =========================================================================
    // Сохранение настроек сети
    // =========================================================================

    $(document).on('click', '.cashback-save-network-btn', function () {
        const $btn = $(this);
        const $card = $btn.closest('.cashback-network-card');
        const networkId = $btn.data('network-id');
        const $status = $card.find('.cashback-save-status');

        const data = {
            action: 'cashback_save_api_credentials',
            nonce: config.nonce,
            network_id: networkId,
        };

        // Сериализуем визуальные редакторы маппингов в hidden inputs
        serializeStatusMap($card);
        serializeFieldMap($card);

        // Собираем все поля
        $card.find('.api-field').each(function () {
            data[$(this).attr('name')] = $(this).val();
        });

        // Credentials (только если заполнены)
        $card.find('.api-credential').each(function () {
            const name = $(this).attr('name');
            const val = $(this).val();
            if (val && !val.startsWith('•')) {
                data[name] = val;
            }
        });

        $btn.prop('disabled', true);
        $status.text(i18n.saving || 'Сохранение...').css('color', '#666');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: data,
            success: function (response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    $status.text('✅ ' + (i18n.saved || 'Сохранено')).css('color', 'green');
                    // Очищаем поля credentials после сохранения
                    $card.find('.api-credential').val('');
                    $card.find('.api-credential[name="client_id"]').attr('placeholder', '••••••• (сохранён)');
                    $card.find('.api-credential[name="client_secret"]').attr('placeholder', '••••••• (сохранён)');
                } else {
                    $status.text('❌ ' + (response.data?.message || 'Ошибка')).css('color', 'red');
                }

                setTimeout(function () {
                    $status.text('');
                }, 5000);
            },
            error: function () {
                $btn.prop('disabled', false);
                $status.text('❌ Ошибка сети').css('color', 'red');
            },
        });
    });

    // =========================================================================
    // Проверка соединения с API
    // =========================================================================

    $(document).on('click', '.cashback-test-connection-btn', function () {
        const $btn = $(this);
        const $card = $btn.closest('.cashback-network-card');
        const networkId = $btn.data('network-id');
        const $status = $card.find('.cashback-save-status');
        const originalText = $btn.text();

        $btn.prop('disabled', true).text('Проверка...');
        $status.text('').css('color', '');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cashback_test_connection',
                nonce: config.nonce,
                network_id: networkId,
            },
            timeout: 30000,
            success: function (response) {
                $btn.prop('disabled', false).text(originalText);
                if (response.success) {
                    $status.text('✅ ' + response.data.message).css('color', 'green');
                } else {
                    $status.text('❌ ' + (response.data?.message || 'Ошибка')).css('color', 'red');
                }
                setTimeout(function () {
                    $status.text('');
                }, 8000);
            },
            error: function () {
                $btn.prop('disabled', false).text(originalText);
                $status.text('❌ Таймаут или ошибка сети').css('color', 'red');
                setTimeout(function () {
                    $status.text('');
                }, 8000);
            },
        });
    });

    // =========================================================================
    // Ручная синхронизация
    // =========================================================================

    $(document).on('click', '#cashback-manual-sync-btn', function () {
        const $btn = $(this);
        const $status = $('#cashback-sync-status');

        if (!confirm(i18n.confirm_sync || 'Запустить синхронизацию статусов?')) {
            return;
        }

        $btn.prop('disabled', true);
        $status.text(i18n.syncing || 'Синхронизация...').css('color', '#666');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cashback_manual_sync',
                nonce: config.nonce,
            },
            timeout: 120000, // 2 минуты таймаут
            success: function (response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    $status.text('✅ ' + (i18n.sync_complete || 'Завершено')).css('color', 'green');
                    // Перезагружаем страницу для обновления блока статистики
                    setTimeout(function () {
                        location.reload();
                    }, 1500);
                } else {
                    $status.text('❌ ' + (response.data?.message || 'Ошибка')).css('color', 'red');
                }
            },
            error: function (xhr) {
                $btn.prop('disabled', false);
                $status.text('❌ Таймаут или ошибка сети').css('color', 'red');
            },
        });
    });

    // =========================================================================
    // Лог синхронизации
    // =========================================================================

    $(document).on('click', '#cashback-load-sync-log', function () {
        const $btn = $(this);
        const days = $('#cashback-sync-log-period').val();

        $btn.prop('disabled', true);

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cashback_get_sync_log',
                nonce: config.nonce,
                days: days,
            },
            success: function (response) {
                $btn.prop('disabled', false);

                if (response.success && response.data.log) {
                    renderSyncLog(response.data.log);
                }
            },
            error: function () {
                $btn.prop('disabled', false);
            },
        });
    });

    function renderSyncLogRow(row) {
        const statusColor = row.new_status === 'completed' ? 'green' : row.new_status === 'declined' ? 'red' : '#666';
        return '<tr>'
            + '<td>' + escHtml(row.synced_at) + '</td>'
            + '<td>' + escHtml(row.network_slug) + '</td>'
            + '<td>#' + row.transaction_id + (row.user_id ? ' (user ' + row.user_id + ')' : '') + '</td>'
            + '<td><code>' + escHtml(row.action_id || '') + '</code></td>'
            + '<td>' + escHtml(row.old_status) + '</td>'
            + '<td style="color:' + statusColor + '; font-weight:bold;">' + escHtml(row.new_status) + '</td>'
            + '<td>' + formatMoney(row.api_payment) + '</td>'
            + '</tr>';
    }

    function renderSyncLog(log) {
        const $table = $('#cashback-sync-log-table');
        const $tbody = $table.find('tbody');
        $tbody.empty();

        // Очистить предыдущую пагинацию (при повторной загрузке)
        let $wrap = $table.closest('.validation-paginated-table');
        if ($wrap.length) {
            $wrap.find('.cashback-pagination-wrap').remove();
            $wrap.data('page', 1);
        }

        if (log.length === 0) {
            $tbody.append('<tr><td colspan="7" style="text-align:center;">Нет записей за выбранный период</td></tr>');
            delete paginationStore['sync-log'];
        } else {
            const totalPages = Math.ceil(log.length / ITEMS_PER_PAGE);
            paginationStore['sync-log'] = { items: log, renderRowFn: renderSyncLogRow, showNetCol: false, totalPages };
            $tbody.html(renderPageRows('sync-log', 1));

            if (!$wrap.length) {
                $table.wrap('<div class="validation-paginated-table" data-tab-id="sync-log" data-page="1"></div>');
                $wrap = $table.parent();
            }
            $wrap.append(buildPaginationHtml(1, totalPages, log.length, ITEMS_PER_PAGE));
        }

        $table.show();
    }

    // =========================================================================
    // Inline-кнопка валидации на странице выплат
    // =========================================================================

    $(document).on('click', '.cashback-inline-validate-btn', function () {
        const $btn = $(this);
        const userId = $btn.data('user-id');
        const $status = $(`.cashback-inline-validate-status[data-user-id="${userId}"]`);

        $btn.prop('disabled', true).text('⏳');
        $status.text('');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cashback_validate_user',
                nonce: config.nonce,
                user_id: userId,
                network: 'admitad',
                full_check: 0,
            },
            success: function (response) {
                $btn.prop('disabled', false).text('🔍 Проверить');

                if (response.success && response.data) {
                    const d = response.data;
                    if (d.status === 'match') {
                        $status.html('<span style="color:green;">✅ OK</span>');
                    } else {
                        $status.html(
                            `<span style="color:red;">⚠️ ${d.mismatch_count || 0} расх.</span>` +
                            (d.sums?.discrepancy > 0 ? ` <small>(${formatMoney(d.sums.discrepancy)})</small>` : '')
                        );
                    }
                } else {
                    $status.html('<span style="color:red;">❌ Ошибка</span>');
                }
            },
            error: function () {
                $btn.prop('disabled', false).text('🔍 Проверить');
                $status.html('<span style="color:red;">❌ Ошибка сети</span>');
            },
        });
    });

    // =========================================================================
    // Действия из таблиц валидации
    // =========================================================================

    // --- Редактирование транзакции (таблица «Есть на сайте, нет в API») ---

    $(document).on('click', '.cashback-edit-tx-btn', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');

        // Отмена редактирования
        if ($row.hasClass('editing')) {
            $row.removeClass('editing');
            $row.find('.editable-cell').each(function () {
                const $cell = $(this);
                const field = $cell.data('field');
                const original = $cell.data('value');
                $cell.html(field === 'order_status' ? escHtml(original) : formatMoney(original));
            });
            $btn.text('Редактировать');
            $row.find('.cashback-save-tx-btn').remove();
            return;
        }

        $row.addClass('editing');
        $btn.text('Отмена');

        // Превращаем ячейки в поля ввода
        $row.find('.editable-cell').each(function () {
            const $cell = $(this);
            const field = $cell.data('field');
            const value = $cell.data('value');

            if (field === 'order_status') {
                const statuses = ['waiting', 'completed', 'declined', 'hold'];
                let select = '<select class="edit-input" data-field="' + field + '">';
                statuses.forEach(function (s) {
                    select += '<option value="' + s + '"' + (s === value ? ' selected' : '') + '>' + s + '</option>';
                });
                select += '</select>';
                $cell.html(select);
            } else {
                $cell.html('<input type="number" step="0.01" min="0" class="edit-input" data-field="' + field + '" value="' + value + '">');
            }
        });

        // Добавляем кнопку «Сохранить»
        $btn.after('<button type="button" class="button button-small button-primary cashback-save-tx-btn" style="margin-left:4px;">Сохранить</button>');
    });

    // Сохранение редактированной транзакции
    $(document).on('click', '.cashback-save-tx-btn', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const localId = $row.data('local-id');

        const postData = {
            action: 'cashback_edit_transaction',
            nonce: config.nonce,
            transaction_id: localId,
            user_id: $('#cashback-validate-user-id').val(),
        };

        $row.find('.edit-input').each(function () {
            postData[$(this).data('field')] = $(this).val();
        });

        $btn.prop('disabled', true).text(i18n.saving || 'Сохранение...');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: postData,
            success: function (response) {
                if (response.success) {
                    // Обновляем значения ячеек
                    $row.find('.edit-input').each(function () {
                        const $input = $(this);
                        const $cell = $input.closest('.editable-cell');
                        const field = $input.data('field');
                        const newVal = $input.val();
                        $cell.data('value', newVal);
                        $cell.html(field === 'order_status' ? escHtml(newVal) : formatMoney(newVal));
                    });
                    $row.removeClass('editing');
                    $row.find('.cashback-edit-tx-btn').text('Редактировать');
                    $btn.remove();
                    flashRow($row, '#dff0d8');
                } else {
                    alert(response.data?.message || 'Ошибка сохранения');
                    $btn.prop('disabled', false).text('Сохранить');
                }
            },
            error: function () {
                alert('Ошибка сети');
                $btn.prop('disabled', false).text('Сохранить');
            },
        });
    });

    // --- Добавление транзакции из API (таблица «Есть в API, нет на сайте») ---

    $(document).on('click', '.cashback-add-tx-btn', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const userId = $('#cashback-validate-user-id').val();
        const network = $btn.attr('data-network') || $('#cashback-validate-network').val();

        $btn.prop('disabled', true).text(i18n.adding || 'Добавление...');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cashback_add_transaction',
                nonce: config.nonce,
                user_id: userId,
                network: network,
                action_id: $btn.attr('data-action-id'),
                click_id: $btn.attr('data-click-id') || '',
                order_id: $btn.attr('data-order-id') || '',
                status: $btn.attr('data-status'),
                payment: $btn.attr('data-payment'),
                cart: $btn.attr('data-cart'),
                date: $btn.attr('data-date') || '',
                campaign: $btn.attr('data-campaign') || '',
                campaign_id: $btn.attr('data-campaign-id') || '',
                currency: $btn.attr('data-currency') || 'RUB',
                click_time: $btn.attr('data-click-time') || '',
                action_type: $btn.attr('data-action-type') || '',
                website_id: $btn.attr('data-website-id') || '',
                funds_ready: $btn.attr('data-funds-ready') || '0',
            },
            success: function (response) {
                if (response.success) {
                    $btn.replaceWith('<span style="color:green;">Добавлено #' + (response.data.insert_id || '') + '</span>');
                    flashRow($row, '#dff0d8');
                } else {
                    alert(response.data?.message || 'Ошибка добавления');
                    $btn.prop('disabled', false).text('Добавить');
                }
            },
            error: function () {
                alert('Ошибка сети');
                $btn.prop('disabled', false).text('Добавить');
            },
        });
    });

    // --- Перезапись транзакции данными API (таблица «Расхождения») ---

    $(document).on('click', '.cashback-overwrite-tx-btn', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const network = $btn.attr('data-network') || $('#cashback-validate-network').val();

        if (!confirm(i18n.confirm_overwrite || 'Перезаписать локальные данные данными из API?')) {
            return;
        }

        $btn.prop('disabled', true).text(i18n.saving || 'Сохранение...');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cashback_overwrite_transaction',
                nonce: config.nonce,
                local_id: $btn.data('local-id'),
                network: network,
                api_status: $btn.data('api-status'),
                api_payment: $btn.data('api-payment'),
                api_cart: $btn.data('api-cart'),
                user_id: $('#cashback-validate-user-id').val(),
            },
            success: function (response) {
                if (response.success) {
                    $btn.replaceWith('<span style="color:green;">Перезаписано</span>');
                    $row.find('.cashback-remove-row-btn').remove();
                    flashRow($row, '#dff0d8');
                } else {
                    alert(response.data?.message || 'Ошибка перезаписи');
                    $btn.prop('disabled', false).text('Перезаписать');
                }
            },
            error: function () {
                alert('Ошибка сети');
                $btn.prop('disabled', false).text('Перезаписать');
            },
        });
    });

    // --- Удаление строки из результатов (только UI) ---

    $(document).on('click', '.cashback-remove-row-btn', function () {
        const $row = $(this).closest('tr');

        if (!confirm(i18n.confirm_delete || 'Удалить эту строку из результатов?')) {
            return;
        }

        $row.fadeOut(300, function () {
            $(this).remove();
        });
    });

    // --- Подсветка строки после успешного действия ---

    function flashRow($row, color) {
        $row.css('background-color', color);
        setTimeout(function () {
            $row.css('background-color', '');
        }, 2000);
    }

    // =========================================================================
    // Рендеры строк для пагинированных таблиц
    // =========================================================================

    function renderMismatchRow(m, showNetCol) {
        const problems = [];
        if (m.status_mismatch) problems.push('статус');
        if (m.commission_mismatch) problems.push('комиссия');
        if (m.cart_mismatch) problems.push('сумма заказа');

        const statusCls = m.status_mismatch ? ' class="cell-mismatch"' : '';
        const sumCls = m.commission_mismatch ? ' class="cell-mismatch"' : '';
        const isBalance = m.local_status === 'balance';

        let row = '<tr>';
        if (showNetCol) row += '<td>' + escHtml(m.network || '') + '</td>';
        row += '<td><code>' + escHtml(m.action_id) + '</code></td>'
            + '<td><code>' + escHtml(m.uniq_id || m.click_id) + '</code></td>'
            + '<td' + statusCls + '>' + escHtml(m.api_status) + ' &rarr; ' + escHtml(m.mapped_api_status) + '</td>'
            + '<td' + statusCls + '>' + escHtml(m.local_status) + '</td>'
            + '<td' + sumCls + '>' + formatMoney(m.api_payment) + '</td>'
            + '<td' + sumCls + '>' + formatMoney(m.local_commission) + '</td>'
            + '<td style="color:red; font-weight:bold;">' + problems.join(', ') + '</td>'
            + '<td class="validation-actions">'
            + '<button type="button" class="button button-small button-primary cashback-overwrite-tx-btn"'
            + ' data-local-id="' + m.local_id + '"'
            + ' data-network="' + escHtml(m.network || '') + '"'
            + ' data-api-status="' + escHtml(m.api_status) + '"'
            + ' data-api-payment="' + m.api_payment + '"'
            + ' data-api-cart="' + m.api_cart + '"'
            + (isBalance ? ' disabled title="Нельзя изменить транзакцию со статусом balance"' : '') + '>Перезаписать</button>'
            + '<button type="button" class="button button-small cashback-remove-row-btn">Удалить</button>'
            + '</td></tr>';
        return row;
    }

    function renderMissingLocalRow(m, showNetCol) {
        let row = '<tr>';
        if (showNetCol) row += '<td>' + escHtml(m.network || '') + '</td>';
        row += '<td><code>' + escHtml(m.action_id) + '</code></td>'
            + '<td>' + escHtml(m.order_id) + '</td>'
            + '<td>' + escHtml(m.status) + '</td>'
            + '<td>' + formatMoney(m.payment) + '</td>'
            + '<td>' + formatMoney(m.cart) + '</td>'
            + '<td>' + escHtml(m.date) + '</td>'
            + '<td>' + escHtml(m.campaign || '') + '</td>'
            + '<td class="validation-actions">'
            + '<button type="button" class="button button-small button-primary cashback-add-tx-btn"'
            + ' data-network="' + escHtml(m.network || '') + '"'
            + ' data-action-id="' + escHtml(m.action_id) + '"'
            + ' data-click-id="' + escHtml(m.click_id || '') + '"'
            + ' data-order-id="' + escHtml(m.order_id || '') + '"'
            + ' data-status="' + escHtml(m.status) + '"'
            + ' data-payment="' + m.payment + '"'
            + ' data-cart="' + m.cart + '"'
            + ' data-date="' + escHtml(m.date || '') + '"'
            + ' data-campaign="' + escHtml(m.campaign || '') + '"'
            + ' data-campaign-id="' + escHtml(m.campaign_id || '') + '"'
            + ' data-currency="' + escHtml(m.currency || 'RUB') + '"'
            + ' data-click-time="' + escHtml(m.click_time || '') + '"'
            + ' data-action-type="' + escHtml(m.action_type || '') + '"'
            + ' data-website-id="' + escHtml(m.website_id || '') + '"'
            + ' data-funds-ready="' + (m.funds_ready || 0) + '">Добавить</button>'
            + '</td></tr>';
        return row;
    }

    function renderMissingApiRow(m, showNetCol) {
        let row = '<tr data-local-id="' + m.local_id + '">';
        if (showNetCol) row += '<td>' + escHtml(m.network || '') + '</td>';
        // Колонка «Добавлена админом»: зелёным жирным «Да» для tx, созданных
        // админом вручную (Сверка баланса → зависший claim). Для остальных —
        // прочерк, чтобы строка не рассыпалась визуально.
        const adminCell = (parseInt(m.created_by_admin, 10) === 1)
            ? '<td class="cashback-tx-admin-yes" style="color:#1f8f3a;font-weight:bold;">Да</td>'
            : '<td>—</td>';
        row += '<td>#' + m.local_id + '</td>'
            + '<td><code>' + escHtml(m.uniq_id || '\u2014') + '</code></td>'
            + '<td><code>' + escHtml(m.click_id || '\u2014') + '</code></td>'
            + '<td class="editable-cell" data-field="order_status" data-value="' + escHtml(m.status) + '">' + escHtml(m.status) + '</td>'
            + '<td class="editable-cell" data-field="comission" data-value="' + m.commission + '">' + formatMoney(m.commission) + '</td>'
            + '<td class="editable-cell" data-field="sum_order" data-value="' + (m.sum_order || 0) + '">' + formatMoney(m.sum_order) + '</td>'
            + '<td>' + escHtml(m.created) + '</td>'
            + adminCell
            + '<td class="validation-actions">'
            + '<button type="button" class="button button-small cashback-edit-tx-btn"'
            + ' data-local-id="' + m.local_id + '">Редактировать</button>'
            + '</td></tr>';
        return row;
    }

    // =========================================================================
    // Утилиты
    // =========================================================================

    function escHtml(str) {
        if (str === null || str === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    function formatMoney(value) {
        if (value === null || value === undefined) return '—';
        return parseFloat(value).toFixed(2) + ' ₽';
    }

    // =========================================================================
    // Вкладка «Кампании» — две колонки, поиск, пагинация
    // =========================================================================

    const CAMPAIGNS_PER_PAGE = 50;
    const campaignPagination = {};

    function filterCampaigns(allCampaigns, networkSlug, searchTerm) {
        let filtered = allCampaigns;
        if (networkSlug) {
            filtered = filtered.filter(function (c) { return c.network_slug === networkSlug; });
        }
        if (searchTerm) {
            const term = searchTerm.toLowerCase();
            filtered = filtered.filter(function (c) { return (c.name || '').toLowerCase().indexOf(term) !== -1; });
        }
        const active = [];
        const inactive = [];
        for (let i = 0; i < filtered.length; i++) {
            if (filtered[i].is_active) {
                active.push(filtered[i]);
            } else {
                inactive.push(filtered[i]);
            }
        }
        return { active: active, inactive: inactive };
    }

    function renderCampaignRow(c, showNetCol) {
        let row = '<tr>';
        if (showNetCol) {
            row += '<td>' + escHtml(c.network_name) + '</td>';
        }
        row += '<td>' + escHtml(c.id) + '</td>';
        row += '<td>' + escHtml(c.name) + '</td>';
        row += '<td>' + escHtml(c.status) + '</td>';
        row += '<td>' + escHtml(c.connection_status) + '</td>';
        row += '</tr>';
        return row;
    }

    function buildCampaignTable(tabId, items, showNetCol, emptyMsg) {
        const totalPages = Math.ceil(items.length / CAMPAIGNS_PER_PAGE);
        campaignPagination[tabId] = { items: items, showNetCol: showNetCol, totalPages: totalPages };

        if (items.length === 0) {
            return '<p class="validation-empty">' + escHtml(emptyMsg) + '</p>';
        }

        let theadHtml = '<tr>';
        if (showNetCol) theadHtml += '<th>Сеть</th>';
        theadHtml += '<th>ID</th><th>Название</th><th>Статус</th><th>Подключение</th></tr>';

        let html = '<div class="campaigns-paginated-table" data-tab-id="' + tabId + '" data-page="1">';
        html += '<table class="widefat striped"><thead>' + theadHtml + '</thead>';
        html += '<tbody>' + renderCampaignPageRows(tabId, 1) + '</tbody></table>';
        html += buildPaginationHtml(1, totalPages, items.length, CAMPAIGNS_PER_PAGE);
        html += '</div>';
        return html;
    }

    function renderCampaignPageRows(tabId, page) {
        const store = campaignPagination[tabId];
        const start = (page - 1) * CAMPAIGNS_PER_PAGE;
        const end = Math.min(start + CAMPAIGNS_PER_PAGE, store.items.length);
        let html = '';
        for (let i = start; i < end; i++) {
            html += renderCampaignRow(store.items[i], store.showNetCol);
        }
        return html;
    }

    $(document).on('click', '.campaigns-paginated-table .page-numbers[data-page]', function (e) {
        e.preventDefault();
        const $link = $(this);
        if ($link.hasClass('current')) return;

        const $wrap = $link.closest('.campaigns-paginated-table');
        const tabId = $wrap.data('tab-id');
        const store = campaignPagination[tabId];
        if (!store) return;

        const newPage = parseInt($link.data('page'), 10);
        if (!newPage || newPage < 1 || newPage > store.totalPages) return;

        $wrap.data('page', newPage);
        $wrap.find('tbody').html(renderCampaignPageRows(tabId, newPage));
        $wrap.find('.cashback-pagination-wrap').replaceWith(
            buildPaginationHtml(newPage, store.totalPages, store.items.length, CAMPAIGNS_PER_PAGE)
        );
    });

    function renderNetworkStats(networkSlug, stats) {
        if (!stats || Object.keys(stats).length === 0) return '';
        let html = '';
        const slugs = networkSlug ? [networkSlug] : Object.keys(stats);
        for (let i = 0; i < slugs.length; i++) {
            const s = stats[slugs[i]];
            if (!s) continue;
            html += '<p><strong>' + escHtml(s.name) + ':</strong> '
                + 'обновлено ' + escHtml(s.timestamp || '\u2014') + ' | '
                + 'всего: ' + s.total + ' | '
                + 'активных: ' + s.active + ' | '
                + 'неактивных: ' + s.inactive + '</p>';
        }
        return html;
    }

    function renderCampaignsView() {
        const allCampaigns = window.cashbackCampaignsData || [];
        const networkStats = window.cashbackCampaignsNetworkStats || {};
        const networkSlug  = $('#cashback-check-network-select').val() || '';
        const searchTerm   = ($('#cashback-campaigns-search').val() || '').trim();
        const showNetCol   = networkSlug === '';

        const result = filterCampaigns(allCampaigns, networkSlug, searchTerm);

        $('#cashback-campaigns-active-table').html(
            buildCampaignTable('campaigns-active', result.active, showNetCol, 'Нет активных кампаний')
        );
        $('#cashback-campaigns-inactive-table').html(
            buildCampaignTable('campaigns-inactive', result.inactive, showNetCol, 'Нет неактивных кампаний')
        );

        $('#cashback-active-count').text(result.active.length);
        $('#cashback-inactive-count').text(result.inactive.length);

        $('#cashback-campaigns-net-stats').html(renderNetworkStats(networkSlug, networkStats));
    }

    window.initCampaignsTab = function () {
        if (!window.cashbackCampaignsData) return;

        renderCampaignsView();

        $('#cashback-check-network-select').on('change', function () {
            renderCampaignsView();
        });

        $('#cashback-campaigns-search-btn').on('click', function () {
            renderCampaignsView();
        });

        $('#cashback-campaigns-reset-btn').on('click', function () {
            $('#cashback-campaigns-search').val('');
            renderCampaignsView();
        });

        $('#cashback-campaigns-search').on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                renderCampaignsView();
            }
        });
    };

})(jQuery);
