/**
 * Cashback Fraud Prevention — Browser Fingerprint Collector
 *
 * Собирает лёгкий fingerprint из параметров браузера (без внешних зависимостей).
 * Отправляет SHA-256 хеш один раз за сессию.
 *
 * @since 1.2.0
 */
(function () {
    'use strict';

    if (typeof cashbackFraudFP === 'undefined') {
        return;
    }

    // Один раз за сессию
    if (sessionStorage.getItem('cb_fp_sent')) {
        return;
    }

    /**
     * Собирает компоненты fingerprint
     */
    function collectComponents() {
        var components = [];

        components.push(screen.width + 'x' + screen.height);
        components.push(String(screen.colorDepth));
        components.push(String(new Date().getTimezoneOffset()));
        components.push(navigator.language || '');
        components.push(navigator.platform || '');
        components.push(String(navigator.hardwareConcurrency || 0));
        components.push(String(navigator.maxTouchPoints || 0));

        // Canvas fingerprint
        try {
            var canvas = document.createElement('canvas');
            canvas.width = 200;
            canvas.height = 50;
            var ctx = canvas.getContext('2d');
            if (ctx) {
                ctx.textBaseline = 'top';
                ctx.font = '14px Arial';
                ctx.fillStyle = '#f60';
                ctx.fillRect(0, 0, 100, 50);
                ctx.fillStyle = '#069';
                ctx.fillText('Cashback FP', 2, 15);
                ctx.fillStyle = 'rgba(102,204,0,0.7)';
                ctx.fillText('Cashback FP', 4, 17);
                components.push(canvas.toDataURL());
            }
        } catch (e) {
            components.push('canvas_error');
        }

        return components.join('|');
    }

    /**
     * SHA-256 хеш строки через Web Crypto API
     */
    function sha256(str) {
        var buffer = new TextEncoder().encode(str);
        return crypto.subtle.digest('SHA-256', buffer).then(function (hash) {
            var hexCodes = [];
            var view = new DataView(hash);
            for (var i = 0; i < view.byteLength; i++) {
                var hex = view.getUint8(i).toString(16);
                hexCodes.push(hex.length === 1 ? '0' + hex : hex);
            }
            return hexCodes.join('');
        });
    }

    // Собираем и отправляем
    var raw = collectComponents();

    sha256(raw).then(function (hash) {
        var data = new FormData();
        data.append('action', 'cashback_fraud_fingerprint');
        data.append('nonce', cashbackFraudFP.nonce);
        data.append('fp', hash);

        fetch(cashbackFraudFP.ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data,
        }).then(function () {
            sessionStorage.setItem('cb_fp_sent', '1');
        }).catch(function () {
            // Ставим флаг даже при ошибке, чтобы не спамить повторными запросами
            sessionStorage.setItem('cb_fp_sent', '1');
        });
    });
})();
