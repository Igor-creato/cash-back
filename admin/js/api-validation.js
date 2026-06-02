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

    // =========================================================================
    // Self-test дедупликации (read-only) — ловит перепутанный крон-маппинг
    // =========================================================================

    $(document).on('click', '#cashback-dedup-selftest-btn', function () {
        const $btn = $(this);
        const network = $('#cashback-validate-network').val();

        if (!network || network === '__all__') {
            alert('Выберите конкретную CPA-сеть (не «Все сети»).');
            return;
        }

        const orig = $btn.text();
        $btn.prop('disabled', true).text('Проверка дедупа...');
        $('#cashback-dedup-selftest-result').hide();

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cashback_dedup_selftest',
                nonce: config.nonce,
                network: network,
            },
            success: function (response) {
                $btn.prop('disabled', false).text(orig);
                if (response.success && response.data) {
                    renderDedupSelftestResult(response.data);
                } else {
                    renderDedupSelftestError(response.data?.message || 'Неизвестная ошибка');
                }
            },
            error: function (xhr) {
                $btn.prop('disabled', false).text(orig);
                renderDedupSelftestError('Ошибка сети: ' + xhr.status + ' ' + xhr.statusText);
            },
        });
    });

    function dedupEsc(v) {
        return $('<div>').text(v == null ? '' : String(v)).html();
    }

    function renderDedupSelftestError(msg) {
        $('#cashback-dedup-selftest-result')
            .html('<div class="notice notice-error" style="padding:12px"><strong>❌ ' + dedupEsc(msg) + '</strong></div>')
            .show();
    }

    function renderDedupSelftestResult(data) {
        const verdict = data.verdict || 'inconclusive';
        let cls = 'notice-warning';
        let head = 'ℹ️ Неубедительно (это НЕ ошибка)';
        if (verdict === 'match') {
            cls = 'notice-success';
            head = '✅ Маппинг согласован';
        } else if (verdict === 'mismatch') {
            cls = 'notice-error';
            head = '⚠️ РАССИНХРОН МАППИНГА';
        }

        let html = '<div class="notice ' + cls + '" style="padding:12px">';
        html += '<p><strong>' + head + '</strong> — сеть <code>' + dedupEsc(data.network) + '</code>';
        if (typeof data.checked === 'number') {
            html += ' · проверено конверсий: ' + dedupEsc(data.checked);
        }
        html += '</p>';
        html += '<p>' + dedupEsc(data.message) + '</p>';

        if (verdict === 'mismatch' && data.detail) {
            const d = data.detail;
            html += '<table class="widefat striped" style="max-width:760px;margin-top:8px">';
            html += '<tbody>';
            if (d.api_field_cron_reads !== undefined) {
                html += '<tr><td>API-поле, которое читает крон под uniq_id</td><td><code>'
                    + dedupEsc(d.api_field_cron_reads) + '</code> = «' + dedupEsc(d.api_field_value) + '»</td></tr>';
            }
            html += '<tr><td>uniq_id сохранён (webhook)</td><td><code>' + dedupEsc(d.stored_uniq) + '</code></td></tr>';
            html += '<tr><td>uniq_id вычислил крон-резолвер</td><td><code>' + dedupEsc(d.computed_uniq) + '</code></td></tr>';
            html += '<tr><td>idempotency_key сохранён</td><td><code>' + dedupEsc(d.stored_idem) + '</code></td></tr>';
            html += '<tr><td>idempotency_key ожидаемый</td><td><code>' + dedupEsc(d.computed_idem) + '</code></td></tr>';
            html += '</tbody></table>';
        }
        if (verdict === 'inconclusive' && data.reason) {
            html += '<p><em>reason: <code>' + dedupEsc(data.reason) + '</code></em></p>';
        }
        html += '</div>';

        $('#cashback-dedup-selftest-result').html(html).show();
    }

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
        const windowLimitedCount = (data.window_limited_local || []).length;
        const totalIssues = mismatchedCount + missingLocalCount + missingApiCount;
        const totalRows = totalIssues + windowLimitedCount;
        const showNetCol = data._isMultiNetwork;

        if (windowLimitedCount > 0) {
            html += `<div class="notice notice-warning" style="margin-top:15px;padding:10px 15px;">
                <p><strong>Не проверены из-за ограничения API Advcake 7 дней</strong></p>
                <p>Эти локальные транзакции старше доказуемого окна XML API. Отсутствие их в ответе не считается расхождением.</p>
            </div>`;
        }

        if (totalRows > 0) {
            const defaultTab = totalIssues > 0 ? 'tab-mismatched' : 'tab-window-limited';

            // Навигация вкладок
            html += '<nav class="validation-result-tabs nav-tab-wrapper" style="margin-top:20px;">';
            html += `<a href="#" class="nav-tab${defaultTab === 'tab-mismatched' ? ' nav-tab-active' : ''}" data-tab="tab-mismatched">Расхождения <span class="tab-badge${mismatchedCount > 0 ? ' badge-red' : ''}">${mismatchedCount}</span></a>`;
            html += `<a href="#" class="nav-tab${defaultTab === 'tab-missing-local' ? ' nav-tab-active' : ''}" data-tab="tab-missing-local">Есть в API, нет на сайте <span class="tab-badge${missingLocalCount > 0 ? ' badge-red' : ''}">${missingLocalCount}</span></a>`;
            html += `<a href="#" class="nav-tab${defaultTab === 'tab-missing-api' ? ' nav-tab-active' : ''}" data-tab="tab-missing-api">Есть на сайте, нет в API <span class="tab-badge${missingApiCount > 0 ? ' badge-red' : ''}">${missingApiCount}</span></a>`;
            html += `<a href="#" class="nav-tab${defaultTab === 'tab-window-limited' ? ' nav-tab-active' : ''}" data-tab="tab-window-limited">Не проверены <span class="tab-badge">${windowLimitedCount}</span></a>`;
            html += '</nav>';

            // Вкладка 1: Расхождения
            html += `<div class="validation-tab-content" id="tab-mismatched"${defaultTab === 'tab-mismatched' ? '' : ' style="display:none;"'}>`;
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

            // Вкладка 4: локальные строки вне доказуемого окна API
            html += `<div class="validation-tab-content" id="tab-window-limited"${defaultTab === 'tab-window-limited' ? '' : ' style="display:none;"'}>`;
            {
                let thead = '<tr>';
                if (showNetCol) thead += '<th>Сеть</th>';
                thead += '<th>Local ID</th><th>Uniq ID</th><th>Click ID</th><th>Статус</th><th>Комиссия</th><th>Сумма заказа</th><th>Создано</th><th>Обновлено</th><th>Причина</th></tr>';
                html += setupPaginatedTable('tab-window-limited', data.window_limited_local || [], thead, renderWindowLimitedRow, showNetCol, 'Нет локальных транзакций вне доказуемого окна API.');
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
            window_limited_local: t.window_limited_local || [],
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
    // Экспорт / Импорт настроек сети (полностью клиентский, без серверных endpoint-ов)
    //
    // Секреты (client_id, client_secret, api_key, scope) намеренно НЕ покидают
    // сервер: они не попадают в файл и не подставляются при импорте — админ
    // заводит их вручную, как при первичной настройке.
    // =========================================================================

    const NETWORK_SETTINGS_FILE_TYPE = 'cashback_network_settings';
    const NETWORK_SETTINGS_FILE_VERSION = '1.0';
    const NETWORK_SETTINGS_FILE_MAX_BYTES = 1024 * 1024;
    const NETWORK_SETTINGS_AUTH_VALUES = ['oauth2', 'api_key'];
    const NETWORK_SETTINGS_PAGINATION_VALUES = ['offset_limit', 'page', 'none'];

    // Whitelist скалярных полей для импорта/экспорта (без секретов и без идентификаторов записи).
    const NETWORK_SETTINGS_STRING_FIELDS = [
        'api_base_url',
        'api_token_endpoint',
        'api_actions_endpoint',
        'api_website_id',
        'api_user_field',
        'api_click_field',
        'api_coupons_endpoint',
    ];

    function isPlainObject(x) {
        return x !== null && typeof x === 'object' && !Array.isArray(x);
    }

    function escapeAttrSelector(s) {
        return String(s).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    }

    function tryParseJson(s) {
        if (typeof s !== 'string' || s === '') return null;
        try {
            return JSON.parse(s);
        } catch (e) {
            return null;
        }
    }

    function readMapEditor($card, rowSelector, keyClass, valueClass) {
        const map = {};
        $card.find(rowSelector).each(function () {
            const key = String($(this).find(keyClass).val() || '').trim();
            const val = $(this).find(valueClass).val();
            if (key) {
                map[key] = val;
            }
        });
        return map;
    }

    function rebuildStatusMapEditor($card, map) {
        const $editor = $card.find('.status-map-editor');
        $editor.empty();
        Object.keys(map).forEach(function (cpaKey) {
            const $row = $(statusMapRowHtml());
            $row.find('.status-map-cpa').val(cpaKey);
            const localVal = String(map[cpaKey] != null ? map[cpaKey] : '');
            const $select = $row.find('.status-map-local');
            if ($select.find('option[value="' + escapeAttrSelector(localVal) + '"]').length) {
                $select.val(localVal);
            }
            $editor.append($row);
        });
        serializeStatusMap($card);
    }

    function rebuildFieldMapEditor($card, map) {
        const $editor = $card.find('.field-map-editor');
        $editor.empty();
        Object.keys(map).forEach(function (apiKey) {
            const $row = $(fieldMapRowHtml());
            $row.find('.field-map-api').val(apiKey);
            const localCol = String(map[apiKey] != null ? map[apiKey] : '');
            const $select = $row.find('.field-map-local');
            if ($select.find('option[value="' + escapeAttrSelector(localCol) + '"]').length) {
                $select.val(localCol);
            }
            $editor.append($row);
        });
        serializeFieldMap($card);
    }

    function showCardNotice($card, msg, kind) {
        const isOk = kind === 'success';
        const $status = $card.find('.cashback-save-status');
        if ($status.length) {
            $status.text((isOk ? '✅ ' : '❌ ') + msg).css('color', isOk ? 'green' : 'red');
            setTimeout(function () { $status.text(''); }, isOk ? 10000 : 8000);
        } else {
            window.alert(msg);
        }
    }

    function pad2(n) {
        return n < 10 ? '0' + n : String(n);
    }

    function todayIsoDate() {
        const d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }

    $(document).on('click', '.cashback-export-network-btn', function () {
        const $card = $(this).closest('.cashback-network-card');
        if (!$card.length) return;

        const slug = String($card.data('network-slug') || '');
        const name = String($card.data('network-name') || slug);

        const settings = {};
        NETWORK_SETTINGS_STRING_FIELDS.forEach(function (field) {
            const $el = $card.find('[name="' + field + '"]').first();
            if ($el.length) {
                settings[field] = String($el.val() != null ? $el.val() : '');
            }
        });

        const authType = String($card.find('[name="api_auth_type"]').val() || '');
        if (NETWORK_SETTINGS_AUTH_VALUES.indexOf(authType) !== -1) {
            settings.api_auth_type = authType;
        }
        const pagination = String($card.find('[name="api_coupons_pagination"]').val() || '');
        if (NETWORK_SETTINGS_PAGINATION_VALUES.indexOf(pagination) !== -1) {
            settings.api_coupons_pagination = pagination;
        }

        // Маппинги — читаем актуальное состояние визуальных редакторов
        settings.api_status_map = readMapEditor($card, '.status-map-row', '.status-map-cpa', '.status-map-local');
        settings.api_field_map = readMapEditor($card, '.field-map-row', '.field-map-api', '.field-map-local');

        // Coupons maps — из textarea; если валидный JSON-object, экспортируем как объект,
        // иначе кладём raw-строку (плагин на импорте поднимет её обратно в textarea).
        const couponsFieldRaw = String($card.find('textarea[name="api_coupons_field_map"]').val() || '');
        const couponsSpeciesRaw = String($card.find('textarea[name="api_coupons_species_map"]').val() || '');
        const couponsFieldParsed = tryParseJson(couponsFieldRaw);
        const couponsSpeciesParsed = tryParseJson(couponsSpeciesRaw);
        settings.api_coupons_field_map = isPlainObject(couponsFieldParsed) ? couponsFieldParsed : couponsFieldRaw;
        settings.api_coupons_species_map = isPlainObject(couponsSpeciesParsed) ? couponsSpeciesParsed : couponsSpeciesRaw;

        const obj = {
            _type: NETWORK_SETTINGS_FILE_TYPE,
            _version: NETWORK_SETTINGS_FILE_VERSION,
            _exported_at: new Date().toISOString(),
            _network_slug: slug,
            _network_name: name,
            settings: settings,
        };

        const blob = new Blob([JSON.stringify(obj, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'cashback-network-' + (slug || 'export') + '-' + todayIsoDate() + '.json';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    });

    $(document).on('click', '.cashback-import-network-btn', function () {
        $('#cashback-import-network-file').trigger('click');
    });

    $(document).on('change', '#cashback-import-network-file', function () {
        const $input = $(this);
        const fileEl = $input.get(0);
        const file = fileEl && fileEl.files && fileEl.files[0];
        const $visibleCard = $('.cashback-network-card:visible').first();

        const resetInput = function () {
            try { $input.val(''); } catch (e) { /* IE quirk, ignore */ }
        };

        if (!file) {
            resetInput();
            return;
        }

        if (file.size > NETWORK_SETTINGS_FILE_MAX_BYTES) {
            showCardNotice($visibleCard, i18n.import_file_too_large || 'Файл слишком большой.', 'error');
            resetInput();
            return;
        }
        if (!/\.json$/i.test(file.name)) {
            showCardNotice($visibleCard, i18n.import_invalid_type || 'Неподдерживаемый тип файла.', 'error');
            resetInput();
            return;
        }

        const reader = new FileReader();
        reader.onload = function (e) {
            let data;
            try {
                data = JSON.parse(String(e.target.result || ''));
            } catch (err) {
                showCardNotice($visibleCard, i18n.import_invalid_json || 'Файл повреждён или это не JSON.', 'error');
                resetInput();
                return;
            }

            if (!data
                || data._type !== NETWORK_SETTINGS_FILE_TYPE
                || data._version !== NETWORK_SETTINGS_FILE_VERSION
                || !isPlainObject(data.settings)) {
                showCardNotice($visibleCard, i18n.import_invalid_signature || 'Файл не похож на экспорт настроек сети.', 'error');
                resetInput();
                return;
            }

            const slug = String(data._network_slug || '');
            const $targetCard = $('#cashback-api-settings .cashback-network-card[data-network-slug="' + escapeAttrSelector(slug) + '"]').first();
            if (!$targetCard.length) {
                const tpl = i18n.import_network_not_found || 'Сеть «%s» не зарегистрирована в плагине, импорт невозможен.';
                showCardNotice($visibleCard, tpl.replace('%s', slug), 'error');
                resetInput();
                return;
            }

            // Переключиться на целевую сеть (если открыта другая)
            const targetId = $targetCard.data('network-id');
            const $selector = $('#cashback-network-selector');
            if (String($selector.val()) !== String(targetId)) {
                $selector.val(String(targetId)).trigger('change');
            }

            applyImportedSettings($targetCard, data.settings);

            resetInput();
            showCardNotice($targetCard, i18n.import_success || 'Настройки загружены. Проверьте поля и нажмите «Сохранить».', 'success');
        };
        reader.onerror = function () {
            showCardNotice($visibleCard, i18n.import_invalid_json || 'Не удалось прочитать файл.', 'error');
            resetInput();
        };
        reader.readAsText(file);
    });

    function applyImportedSettings($card, settings) {
        // Скалярные поля
        NETWORK_SETTINGS_STRING_FIELDS.forEach(function (field) {
            if (typeof settings[field] === 'string') {
                const $el = $card.find('[name="' + field + '"]').first();
                if ($el.length) {
                    $el.val(settings[field]);
                }
            }
        });

        // Селекты с whitelist значений
        if (typeof settings.api_auth_type === 'string'
            && NETWORK_SETTINGS_AUTH_VALUES.indexOf(settings.api_auth_type) !== -1) {
            $card.find('[name="api_auth_type"]').val(settings.api_auth_type).trigger('change');
        }
        if (typeof settings.api_coupons_pagination === 'string'
            && NETWORK_SETTINGS_PAGINATION_VALUES.indexOf(settings.api_coupons_pagination) !== -1) {
            $card.find('[name="api_coupons_pagination"]').val(settings.api_coupons_pagination);
        }

        // Маппинги — визуальные редакторы
        if (isPlainObject(settings.api_status_map)) {
            rebuildStatusMapEditor($card, settings.api_status_map);
        }
        if (isPlainObject(settings.api_field_map)) {
            rebuildFieldMapEditor($card, settings.api_field_map);
        }

        // Coupons maps — textarea с JSON. Принимаем и объект, и raw-строку.
        const $couponsField = $card.find('textarea[name="api_coupons_field_map"]');
        if (isPlainObject(settings.api_coupons_field_map)) {
            $couponsField.val(JSON.stringify(settings.api_coupons_field_map, null, 2));
        } else if (typeof settings.api_coupons_field_map === 'string') {
            $couponsField.val(settings.api_coupons_field_map);
        }
        const $couponsSpecies = $card.find('textarea[name="api_coupons_species_map"]');
        if (isPlainObject(settings.api_coupons_species_map)) {
            $couponsSpecies.val(JSON.stringify(settings.api_coupons_species_map, null, 2));
        } else if (typeof settings.api_coupons_species_map === 'string') {
            $couponsSpecies.val(settings.api_coupons_species_map);
        }
    }

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
    // Сохранение окна API-синхронизации (опция cashback_api_sync_window_days)
    // =========================================================================

    $(document).on('click', '#cashback-save-sync-window', function () {
        const $btn = $(this);
        const $input = $('#cashback-sync-window-days');
        const $status = $('#cashback-sync-window-status');

        const raw = String($input.val() || '').trim();
        const days = parseInt(raw, 10);

        if (!Number.isInteger(days) || days < 1 || days > 365 || String(days) !== raw) {
            $status.text('❌ Введите целое число от 1 до 365.').css('color', 'red');
            return;
        }

        $btn.prop('disabled', true);
        $status.text(i18n.saving || 'Сохранение...').css('color', '#666');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cashback_save_sync_window',
                nonce: config.nonce,
                days: days,
            },
            success: function (response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    $status.text('✅ ' + (response.data?.message || (i18n.saved || 'Сохранено'))).css('color', 'green');
                    setTimeout(function () {
                        $status.text('').css('color', '');
                    }, 3000);
                } else {
                    $status.text('❌ ' + (response.data?.message || 'Ошибка')).css('color', 'red');
                }
            },
            error: function () {
                $btn.prop('disabled', false);
                $status.text('❌ Ошибка сети').css('color', 'red');
            },
        });
    });

    // =========================================================================
    // Ручная синхронизация
    // =========================================================================

    let manualSyncPollTimer = null;

    function clearManualSyncPolling() {
        if (manualSyncPollTimer) {
            clearTimeout(manualSyncPollTimer);
            manualSyncPollTimer = null;
        }
    }

    function startManualSyncPolling(runId, $btn, originalText, $status) {
        clearManualSyncPolling();
        pollManualSyncStatus(runId, $btn, originalText, $status, 0);
    }

    function pollManualSyncStatus(runId, $btn, originalText, $status, errors) {
        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cashback_manual_sync_status',
                nonce: config.nonce,
                run_id: runId,
            },
            timeout: 15000,
            success: function (response) {
                if (!response.success || !response.data) {
                    $btn.prop('disabled', false).text(originalText);
                    $status.text('❌ ' + (response.data?.message || 'Не удалось получить статус синхронизации')).css('color', 'red');
                    return;
                }

                const status = response.data.status || 'queued';
                if (status === 'completed' || status === 'completed_with_errors') {
                    $btn.prop('disabled', false).text(originalText);
                    const color = status === 'completed' ? 'green' : '#b26a00';
                    const icon = status === 'completed' ? '✅ ' : '⚠️ ';
                    const message = status === 'completed'
                        ? (i18n.sync_complete || response.data.message || 'Синхронизация завершена')
                        : (response.data.message || 'Синхронизация завершена с ошибками');
                    $status.text(icon + message).css('color', color);
                    setTimeout(function () {
                        location.reload();
                    }, 1500);
                    return;
                }

                if (status === 'failed') {
                    $btn.prop('disabled', false).text(originalText);
                    $status.text('❌ ' + (response.data.message || 'Синхронизация завершилась ошибкой')).css('color', 'red');
                    return;
                }

                const message = status === 'running'
                    ? (i18n.sync_running || response.data.message || 'Синхронизация выполняется...')
                    : (i18n.sync_queued || response.data.message || 'Синхронизация запущена...');
                $status.text(message).css('color', '#666');
                manualSyncPollTimer = setTimeout(function () {
                    pollManualSyncStatus(runId, $btn, originalText, $status, 0);
                }, 3000);
            },
            error: function () {
                if (errors < 2) {
                    $status.text(i18n.sync_running || 'Синхронизация запущена, проверяю статус...').css('color', '#666');
                    manualSyncPollTimer = setTimeout(function () {
                        pollManualSyncStatus(runId, $btn, originalText, $status, errors + 1);
                    }, 5000);
                    return;
                }

                $btn.prop('disabled', false).text(originalText);
                $status.text(i18n.sync_status_unavailable || 'Синхронизация запущена, но статус временно недоступен. Обновите страницу через минуту.').css('color', '#b26a00');
            },
        });
    }

    $(document).on('click', '#cashback-manual-sync-btn', function () {
        const $btn = $(this);
        const $status = $('#cashback-sync-status');
        const originalText = $btn.text();

        if (!confirm(i18n.confirm_sync || 'Запустить синхронизацию статусов?')) {
            return;
        }

        clearManualSyncPolling();
        $btn.prop('disabled', true);
        $status.text(i18n.syncing || 'Синхронизация...').css('color', '#666');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cashback_manual_sync',
                nonce: config.nonce,
            },
            timeout: 30000,
            success: function (response) {
                if (response.success) {
                    if (response.data?.async && response.data?.run_id) {
                        $status.text(i18n.sync_queued || response.data.message || 'Синхронизация запущена...').css('color', '#666');
                        startManualSyncPolling(response.data.run_id, $btn, originalText, $status);
                        return;
                    }

                    $btn.prop('disabled', false).text(originalText);
                    $status.text('✅ ' + (i18n.sync_complete || 'Завершено')).css('color', 'green');
                    setTimeout(function () {
                        location.reload();
                    }, 1500);
                } else {
                    $btn.prop('disabled', false).text(originalText);
                    $status.text('❌ ' + (response.data?.message || 'Ошибка')).css('color', 'red');
                }
            },
            error: function () {
                $btn.prop('disabled', false).text(originalText);
                $status.text('❌ Не удалось запустить синхронизацию. Проверьте соединение и повторите.').css('color', 'red');
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

    function renderWindowLimitedRow(m, showNetCol) {
        let row = '<tr data-local-id="' + m.local_id + '">';
        if (showNetCol) row += '<td>' + escHtml(m.network || '') + '</td>';

        const effective = m.effective_params || {};
        const from = effective.update_from || effective.date_from || '';
        const reason = from
            ? 'API проверил только изменения с ' + from
            : 'Строка вне доказуемого окна API';

        row += '<td>#' + m.local_id + '</td>'
            + '<td><code>' + escHtml(m.uniq_id || '\u2014') + '</code></td>'
            + '<td><code>' + escHtml(m.click_id || '\u2014') + '</code></td>'
            + '<td>' + escHtml(m.status || '') + '</td>'
            + '<td>' + formatMoney(m.commission) + '</td>'
            + '<td>' + formatMoney(m.sum_order) + '</td>'
            + '<td>' + escHtml(m.created || '') + '</td>'
            + '<td>' + escHtml(m.updated || '') + '</td>'
            + '<td>' + escHtml(reason) + '</td></tr>';
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
