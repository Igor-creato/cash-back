/**
 * Cashback Fraud Prevention — Browser Fingerprint Collector
 *
 * - Persistent device_id (UUID v4) сохраняется в LocalStorage + IndexedDB + Cookie
 *   (evercookie pattern: восстанавливается из любого живого хранилища, синхронизируется
 *   во все три, чтобы выживать clear-cookies / private mode).
 * - FingerprintJS OSS v3.4.2 даёт стабильный visitor_id. UMD-бандл грузится локально
 *   через wp_enqueue_script как зависимость (см. Cashback_Fraud_Collector), ставит
 *   window.FingerprintJS до выполнения этого скрипта. Graceful fallback на legacy
 *   SHA-256 если bundle не подгрузился (deploy-ошибка / блок со стороны браузера).
 * - Legacy SHA-256 fingerprint всё равно отправляется ради обратной совместимости
 *   с check_shared_fingerprint в Cashback_Fraud_Detector.
 *
 * @since 1.3.0
 */
(function () {
    'use strict';

    if (typeof cashbackFraudFP === 'undefined') {
        return;
    }

    if (sessionStorage.getItem('cb_fp_sent')) {
        return;
    }

    var DEBUG = !!(cashbackFraudFP && cashbackFraudFP.debug);
    var STORAGE_KEY = 'cb_device_id';
    var IDB_NAME = 'cashback';
    var IDB_STORE = 'kv';
    var COOKIE_DAYS = 365;

    function dbg() {
        if (DEBUG && typeof console !== 'undefined' && console.log) {
            try { console.log.apply(console, arguments); } catch (e) {}
        }
    }

    // --------------------------------------------------------------
    // UUID v4 валидация и генерация
    // --------------------------------------------------------------
    var UUID_V4_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

    function isValidUuidV4(s) {
        return typeof s === 'string' && UUID_V4_RE.test(s);
    }

    function generateUuidV4() {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
            try { return crypto.randomUUID(); } catch (e) {}
        }
        // Fallback: ручная генерация через crypto.getRandomValues (старые браузеры).
        var bytes = new Uint8Array(16);
        if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
            crypto.getRandomValues(bytes);
        } else {
            for (var i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
        }
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        var hex = [];
        for (var j = 0; j < 16; j++) {
            var h = bytes[j].toString(16);
            hex.push(h.length === 1 ? '0' + h : h);
        }
        return hex[0] + hex[1] + hex[2] + hex[3] + '-' +
               hex[4] + hex[5] + '-' +
               hex[6] + hex[7] + '-' +
               hex[8] + hex[9] + '-' +
               hex[10] + hex[11] + hex[12] + hex[13] + hex[14] + hex[15];
    }

    // --------------------------------------------------------------
    // Cookie helpers
    // --------------------------------------------------------------
    function readCookie(name) {
        try {
            var prefix = name + '=';
            var parts = document.cookie ? document.cookie.split(';') : [];
            for (var i = 0; i < parts.length; i++) {
                var c = parts[i].replace(/^\s+/, '');
                if (c.indexOf(prefix) === 0) return decodeURIComponent(c.substring(prefix.length));
            }
        } catch (e) {}
        return null;
    }

    function writeCookie(name, value, days) {
        try {
            var d = new Date();
            d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
            // SameSite=Lax — разумный дефолт для first-party device id.
            var secure = (typeof location !== 'undefined' && location.protocol === 'https:') ? '; Secure' : '';
            document.cookie = name + '=' + encodeURIComponent(value) +
                              '; expires=' + d.toUTCString() +
                              '; path=/; SameSite=Lax' + secure;
        } catch (e) {}
    }

    // --------------------------------------------------------------
    // IndexedDB key/value store
    // --------------------------------------------------------------
    function openIdb() {
        return new Promise(function (resolve, reject) {
            try {
                if (typeof indexedDB === 'undefined') return resolve(null);
                var req = indexedDB.open(IDB_NAME, 1);
                req.onupgradeneeded = function () {
                    try { req.result.createObjectStore(IDB_STORE); } catch (e) {}
                };
                req.onsuccess = function () { resolve(req.result); };
                req.onerror = function () { resolve(null); };
                req.onblocked = function () { resolve(null); };
            } catch (e) { resolve(null); }
        });
    }

    function readIndexedDB(key) {
        return openIdb().then(function (db) {
            if (!db) return null;
            return new Promise(function (resolve) {
                try {
                    var tx = db.transaction(IDB_STORE, 'readonly');
                    var store = tx.objectStore(IDB_STORE);
                    var req = store.get(key);
                    req.onsuccess = function () { resolve(req.result || null); };
                    req.onerror = function () { resolve(null); };
                } catch (e) { resolve(null); }
            });
        });
    }

    function writeIndexedDB(key, value) {
        return openIdb().then(function (db) {
            if (!db) return false;
            return new Promise(function (resolve) {
                try {
                    var tx = db.transaction(IDB_STORE, 'readwrite');
                    var store = tx.objectStore(IDB_STORE);
                    var req = store.put(value, key);
                    req.onsuccess = function () { resolve(true); };
                    req.onerror = function () { resolve(false); };
                } catch (e) { resolve(false); }
            });
        });
    }

    function readLocalStorage(key) {
        try { return localStorage.getItem(key); } catch (e) { return null; }
    }

    function writeLocalStorage(key, value) {
        try { localStorage.setItem(key, value); } catch (e) {}
    }

    /**
     * Evercookie-style: ищем device_id в любом из трёх хранилищ, синхронизируем во все.
     */
    function getOrCreateDeviceId() {
        var ls = readLocalStorage(STORAGE_KEY);
        return readIndexedDB(STORAGE_KEY).then(function (idb) {
            var ck = readCookie(STORAGE_KEY);
            var id = null;
            // Приоритет: первый валидный
            if (isValidUuidV4(ls)) id = ls;
            else if (isValidUuidV4(idb)) id = idb;
            else if (isValidUuidV4(ck)) id = ck;

            if (!id) {
                id = generateUuidV4();
                dbg('cb_fp: generated new device_id');
            } else {
                dbg('cb_fp: restored device_id');
            }

            // Sync во все три хранилища (восстановление из любого источника на следующий визит).
            writeLocalStorage(STORAGE_KEY, id);
            writeCookie(STORAGE_KEY, id, COOKIE_DAYS);
            return writeIndexedDB(STORAGE_KEY, id).then(function () { return id; });
        });
    }

    // --------------------------------------------------------------
    // FingerprintJS OSS v3.4.2 loader (локальный UMD-бандл, graceful fallback)
    // --------------------------------------------------------------
    // Бандл поставляется из assets/vendor/fingerprintjs/fp.min.js и enqueue-ится
    // как зависимость cashback-fraud-fingerprint (см. Cashback_Fraud_Collector).
    // К моменту выполнения этого скрипта window.FingerprintJS уже должен быть
    // определён. Если его нет — deploy-проблема или браузер заблокировал скрипт;
    // возвращаем null, чтобы сработал legacy SHA-256 fallback.
    function loadFingerprintJS() {
        if (typeof window !== 'undefined' && window.FingerprintJS) {
            return Promise.resolve(window.FingerprintJS);
        }
        dbg('cb_fp: window.FingerprintJS missing — local bundle not enqueued, using legacy fallback');
        return Promise.resolve(null);
    }

    // --------------------------------------------------------------
    // Legacy SHA-256 fingerprint (fallback и обратная совместимость)
    // --------------------------------------------------------------
    function collectLegacyComponents() {
        var components = [];
        try {
            components.push(screen.width + 'x' + screen.height);
            components.push(String(screen.colorDepth));
            components.push(String(new Date().getTimezoneOffset()));
            components.push(navigator.language || '');
            components.push(navigator.platform || '');
            components.push(String(navigator.hardwareConcurrency || 0));
            components.push(String(navigator.maxTouchPoints || 0));

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
            components.push('error');
        }
        return components.join('|');
    }

    function sha256(str) {
        try {
            var buffer = new TextEncoder().encode(str);
            return crypto.subtle.digest('SHA-256', buffer).then(function (hash) {
                var view = new DataView(hash);
                var hex = [];
                for (var i = 0; i < view.byteLength; i++) {
                    var h = view.getUint8(i).toString(16);
                    hex.push(h.length === 1 ? '0' + h : h);
                }
                return hex.join('');
            });
        } catch (e) {
            return Promise.resolve(null);
        }
    }

    function collectLegacyFingerprintHash() {
        return sha256(collectLegacyComponents());
    }

    // --------------------------------------------------------------
    // Send to server
    // --------------------------------------------------------------
    function sendToServer(payload) {
        var data = new FormData();
        data.append('action', 'cashback_fraud_fingerprint');
        data.append('nonce', cashbackFraudFP.nonce);
        if (payload.fingerprint_hash) data.append('fp', payload.fingerprint_hash);
        if (payload.device_id)        data.append('device_id', payload.device_id);
        if (payload.visitor_id)       data.append('visitor_id', payload.visitor_id);
        if (payload.components_hash)  data.append('components_hash', payload.components_hash);
        if (payload.confidence != null) data.append('confidence', String(payload.confidence));

        return fetch(cashbackFraudFP.ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        });
    }

    // --------------------------------------------------------------
    // Main
    // --------------------------------------------------------------
    function init() {
        return getOrCreateDeviceId().then(function (deviceId) {
            return Promise.all([
                Promise.resolve(deviceId),
                loadFingerprintJS(),
                collectLegacyFingerprintHash()
            ]);
        }).then(function (results) {
            var deviceId = results[0];
            var fp = results[1];
            var legacyHash = results[2];

            if (!fp) {
                return { device_id: deviceId, fingerprint_hash: legacyHash };
            }

            return fp.load().then(function (agent) {
                return agent.get();
            }).then(function (result) {
                var visitorId = result && result.visitorId ? result.visitorId : null;
                var confidence = (result && result.confidence && typeof result.confidence.score === 'number')
                    ? result.confidence.score
                    : null;
                var componentKeys = (result && result.components)
                    ? Object.keys(result.components).sort().join(',')
                    : '';
                return sha256(componentKeys).then(function (componentsHash) {
                    return {
                        device_id: deviceId,
                        visitor_id: visitorId,
                        fingerprint_hash: legacyHash,
                        components_hash: componentsHash,
                        confidence: confidence
                    };
                });
            }).catch(function () {
                dbg('cb_fp: FingerprintJS get() failed');
                return { device_id: deviceId, fingerprint_hash: legacyHash };
            });
        }).then(function (payload) {
            return sendToServer(payload);
        }).then(function (response) {
            if (!response || !response.ok) {
                throw new Error('fingerprint submit failed');
            }
            sessionStorage.setItem('cb_fp_sent', '1');
        }).catch(function () {
            dbg('cb_fp: pipeline failed');
            // Флаг не ставим: при следующей загрузке страницы сеанс попытается отправить снова.
        });
    }

    init();
})();
