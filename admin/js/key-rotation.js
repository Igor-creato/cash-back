/**
 * Cashback Key Rotation — admin polling client.
 *
 * Опрашивает wp_ajax_cashback_rotation_status каждые N мс, обновляет таблицу
 * прогресса на странице ротации. Reload страницы при смене major state,
 * чтобы перерисовать панель соответствующего состояния.
 *
 * Глобалы (из wp_localize_script):
 *   CashbackKeyRotation.ajaxUrl        — admin-ajax.php
 *   CashbackKeyRotation.nonce          — nonce для NONCE_STATUS
 *   CashbackKeyRotation.pollInterval   — базовый интервал опроса (мс)
 *   CashbackKeyRotation.statusAction   — имя AJAX-action'а
 *   CashbackKeyRotation.strings        — i18n-строки
 */
(function () {
    'use strict';

    if (typeof window.CashbackKeyRotation !== 'object') {
        return;
    }

    var cfg = window.CashbackKeyRotation;
    var root = document.querySelector('.cashback-key-rotation-page');
    if (!root) {
        return;
    }

    var pollIntervalMs = parseInt(cfg.pollInterval, 10) || 3000;
    var currentIntervalMs = pollIntervalMs;
    var backoffMultiplier = 1;
    var timer = null;
    var lastStateName = root.getAttribute('data-state') || 'idle';

    /**
     * Рендерит ячейки done/total/failed по data-phase="<phase>".
     */
    function updateProgressTable(progress, currentPhase) {
        if (!progress || typeof progress !== 'object') {
            return;
        }
        var rows = root.querySelectorAll('[data-phase]');
        rows.forEach(function (row) {
            var phase = row.getAttribute('data-phase');
            var data = progress[phase];
            if (!data) { return; }
            ['done', 'total', 'failed'].forEach(function (cell) {
                var td = row.querySelector('[data-cell="' + cell + '"]');
                if (td && typeof data[cell] !== 'undefined') {
                    td.textContent = String(data[cell]);
                }
            });
            if (phase === currentPhase) {
                row.classList.add('cashback-row-active');
            } else {
                row.classList.remove('cashback-row-active');
            }
        });
    }

    /**
     * Обновляет fingerprint-ячейки (primary/new/previous).
     */
    function updateFingerprints(fps) {
        if (!fps) { return; }
        ['primary', 'new', 'previous'].forEach(function (role) {
            var el = root.querySelector('[data-fp-role="' + role + '"]');
            if (!el || !fps[role]) { return; }
            el.textContent = fps[role].substring(0, 16) + '…';
        });
    }

    /**
     * Если major-state изменился с момента загрузки страницы — перезагружаем,
     * чтобы WordPress перерисовал соответствующую панель (staging→migrating→…).
     */
    function maybeReloadOnStateChange(newState) {
        if (!newState || newState === lastStateName) {
            return false;
        }
        // Любой переход major-state требует перерисовки админ-панели.
        window.location.reload();
        return true;
    }

    function schedule() {
        if (timer) {
            clearTimeout(timer);
        }
        timer = window.setTimeout(poll, currentIntervalMs);
    }

    function onNetworkError() {
        // Экспоненциальный backoff: 3s → 6s → 12s → 24s → max 60s.
        backoffMultiplier = Math.min(backoffMultiplier * 2 || 2, 20);
        currentIntervalMs = Math.min(pollIntervalMs * backoffMultiplier, 60000);
    }

    function resetBackoff() {
        backoffMultiplier = 1;
        currentIntervalMs = pollIntervalMs;
    }

    function poll() {
        var url = cfg.ajaxUrl + '?action=' + encodeURIComponent(cfg.statusAction)
            + '&_ajax_nonce=' + encodeURIComponent(cfg.nonce);

        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (resp) {
                if (resp.status === 429) {
                    // Rate-limited: удваиваем интервал.
                    onNetworkError();
                    throw new Error('rate_limited');
                }
                if (!resp.ok) {
                    throw new Error('http_' + resp.status);
                }
                return resp.json();
            })
            .then(function (json) {
                if (!json || !json.success || !json.data || !json.data.state) {
                    throw new Error('bad_payload');
                }
                resetBackoff();
                var state = json.data.state;
                updateProgressTable(state.progress, state.current_phase);
                updateFingerprints(json.data.fingerprints);
                maybeReloadOnStateChange(state.state);
            })
            .catch(function (err) {
                // Сетевая/сервер-ошибка: включаем backoff, но продолжаем опрашивать.
                if (err && err.message !== 'rate_limited') {
                    onNetworkError();
                }
            })
            .finally(function () {
                // Поллим только в «живых» состояниях. Для idle/staging/migrated/completed — стоп.
                if (lastStateName === 'migrating' || lastStateName === 'rolling_back') {
                    schedule();
                }
            });
    }

    // Запуск поллинга только для active-состояний, в которых прогресс меняется.
    if (lastStateName === 'migrating' || lastStateName === 'rolling_back') {
        schedule();
    }
})();
