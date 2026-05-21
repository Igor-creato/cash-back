/* global window, document, fetch, FormData, crypto, tinymce */
(function () {
    'use strict';

    var cfg = window.CashbackLegalTemplateEditor || {};
    var boot = window.CashbackLegalTemplateEditorBoot || {};
    if (!cfg.ajaxUrl || !boot.type || !cfg.editorId) {
        // Silent failure прошлой реализации прятала root cause (cfg/boot не доехал).
        if (window.console && window.console.warn) {
            window.console.warn('cashback-legal-template-editor: missing cfg/boot, aborting init', { cfg: cfg, boot: boot });
        }
        return;
    }

    var textarea = document.getElementById(cfg.editorId);
    var feedback = document.querySelector('[data-role="feedback"]');
    var dirtyEl = document.querySelector('[data-role="dirty-indicator"]');
    var publishDialog = document.getElementById('cashback-legal-template-publish-dialog');
    var previewDialog = document.getElementById('cashback-legal-template-preview-dialog');
    var publishConfirmInput = document.getElementById('cashback-legal-template-publish-confirm');
    var publishConfirmBtn = publishDialog ? publishDialog.querySelector('[data-action="confirm-publish"]') : null;
    var savedHash = null;
    var lastSavedAt = null;
    var publishedHash = boot.publishedHash || '';

    if (!textarea) {
        if (window.console && window.console.warn) {
            window.console.warn('cashback-legal-template-editor: textarea #' + cfg.editorId + ' not found, aborting init');
        }
        return;
    }

    function getEditor() {
        return (window.tinymce && typeof window.tinymce.get === 'function') ? window.tinymce.get(cfg.editorId) : null;
    }

    function getValue() {
        var ed = getEditor();
        if (ed && !ed.isHidden()) {
            return ed.getContent();
        }
        return textarea.value;
    }

    function setValue(v) {
        var ed = getEditor();
        if (ed && !ed.isHidden()) {
            ed.setContent(v);
        }
        textarea.value = v;
    }

    function uuid() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            var v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    async function sha256Hex(s) {
        if (window.crypto && window.crypto.subtle) {
            var buf = new TextEncoder().encode(s);
            var hash = await window.crypto.subtle.digest('SHA-256', buf);
            return Array.from(new Uint8Array(hash))
                .map(function (b) { return b.toString(16).padStart(2, '0'); })
                .join('');
        }
        return null;
    }

    async function updateDirty() {
        var current = getValue();
        var currentHash = await sha256Hex(current);
        var dirty = currentHash !== savedHash;
        if (dirtyEl) {
            dirtyEl.hidden = !dirty;
        }
    }

    function notify(message, kind) {
        if (!feedback) { return; }
        feedback.textContent = message;
        feedback.className = 'cashback-legal-template-feedback notice ' + (kind === 'error' ? 'notice-error' : 'notice-success');
    }

    function ajax(action, payload) {
        var fd = new FormData();
        fd.append('action', cfg.actions[action]);
        fd.append('nonce', cfg.nonce);
        fd.append('type', boot.type);
        Object.keys(payload || {}).forEach(function (k) {
            fd.append(k, payload[k]);
        });
        return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); });
    }

    async function saveDraft() {
        var body = getValue();
        var resp = await ajax('save', { body: body });
        if (!resp || !resp.success) {
            var msg = resp && resp.data && resp.data.message ? resp.data.message : 'Unknown error';
            notify('Ошибка сохранения: ' + msg, 'error');
            return;
        }
        savedHash = resp.data.hash;
        lastSavedAt = resp.data.saved_at;
        notify('Сохранено: ' + lastSavedAt + ' UTC', 'ok');
        await updateDirty();
    }

    async function discardDraft() {
        if (!window.confirm('Удалить черновик и вернуться к опубликованной версии?')) {
            return;
        }
        var resp = await ajax('discard', {});
        if (resp && resp.success) {
            window.location.reload();
        } else {
            notify('Не удалось удалить черновик.', 'error');
        }
    }

    async function preview() {
        var body = getValue();
        var resp = await ajax('preview', { body: body });
        if (!resp || !resp.success) {
            notify('Не удалось построить превью.', 'error');
            return;
        }
        var iframe = previewDialog.querySelector('[data-role="preview-frame"]');
        var fullHtml = '<!doctype html><html><head><meta charset="utf-8"><style>body{font:14px/1.5 -apple-system,BlinkMacSystemFont,sans-serif;padding:24px;color:#1d2327;}h2,h3{color:#1d2327;}</style></head><body>' + (resp.data.rendered_html || '') + '</body></html>';
        iframe.setAttribute('srcdoc', fullHtml);
        if (typeof previewDialog.showModal === 'function') {
            previewDialog.showModal();
        } else {
            previewDialog.setAttribute('open', '');
        }
    }

    function openPublishDialog() {
        if (publishConfirmInput) {
            publishConfirmInput.value = '';
        }
        if (publishConfirmBtn) {
            publishConfirmBtn.disabled = true;
        }
        if (typeof publishDialog.showModal === 'function') {
            publishDialog.showModal();
        } else {
            publishDialog.setAttribute('open', '');
        }
        if (publishConfirmInput) {
            publishConfirmInput.focus();
        }
    }

    async function doPublish() {
        // Перед publish сохраняем draft (на случай если админ не нажал Save).
        await saveDraft();

        var key = uuid();
        var resp = await ajax('publish', {
            idempotency_key: key,
            expected_published_hash: publishedHash,
        });
        if (!resp || !resp.success) {
            var msg = resp && resp.data && resp.data.message ? resp.data.message : 'Unknown error';
            notify('Публикация не удалась: ' + msg, 'error');
            return;
        }
        notify('Опубликовано v' + resp.data.new_version + '. Перезагрузка…', 'ok');
        setTimeout(function () { window.location.reload(); }, 1200);
    }

    function bindPlaceholderChips() {
        document.querySelectorAll('.cashback-legal-placeholder-chip').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var ph = btn.getAttribute('data-placeholder') || '';
                if (window.navigator && window.navigator.clipboard) {
                    window.navigator.clipboard.writeText(ph).catch(function () {});
                }
                // Дополнительно — вставка в курсор TinyMCE если editor активен.
                var ed = getEditor();
                if (ed && !ed.isHidden()) {
                    ed.execCommand('mceInsertContent', false, ph);
                }
                notify('Скопировано / вставлено: ' + ph, 'ok');
            });
        });
    }

    function bindActions() {
        document.querySelectorAll('[data-action="save"]').forEach(function (b) { b.addEventListener('click', saveDraft); });
        document.querySelectorAll('[data-action="discard"]').forEach(function (b) { b.addEventListener('click', discardDraft); });
        document.querySelectorAll('[data-action="preview"]').forEach(function (b) { b.addEventListener('click', preview); });
        document.querySelectorAll('[data-action="publish"]').forEach(function (b) { b.addEventListener('click', openPublishDialog); });

        if (publishConfirmInput) {
            publishConfirmInput.addEventListener('input', function () {
                var expected = publishConfirmInput.getAttribute('data-expected') || '';
                if (publishConfirmBtn) {
                    publishConfirmBtn.disabled = publishConfirmInput.value.trim() !== expected;
                }
            });
        }

        if (publishConfirmBtn) {
            publishConfirmBtn.addEventListener('click', function () {
                if (typeof publishDialog.close === 'function') {
                    publishDialog.close();
                } else {
                    publishDialog.removeAttribute('open');
                }
                doPublish();
            });
        }

        document.querySelectorAll('[data-action="cancel-publish"]').forEach(function (b) {
            b.addEventListener('click', function () {
                if (typeof publishDialog.close === 'function') {
                    publishDialog.close();
                } else {
                    publishDialog.removeAttribute('open');
                }
            });
        });

        document.querySelectorAll('.cashback-legal-template-dialog-close').forEach(function (b) {
            b.addEventListener('click', function () {
                var dlg = b.closest('dialog');
                if (!dlg) { return; }
                if (typeof dlg.close === 'function') {
                    dlg.close();
                } else {
                    dlg.removeAttribute('open');
                }
            });
        });
    }

    function bindDirtyTracking() {
        // TinyMCE — событие change/keyup; в text-mode — input на textarea.
        if (window.tinymce && window.tinymce.on) {
            window.tinymce.on('AddEditor', function (e) {
                if (!e.editor || e.editor.id !== cfg.editorId) { return; }
                e.editor.on('keyup change SetContent', function () {
                    updateDirty();
                });
            });
        }
        textarea.addEventListener('input', updateDirty);
    }

    function init() {
        bindPlaceholderChips();
        bindActions();
        bindDirtyTracking();

        sha256Hex(getValue()).then(function (h) {
            savedHash = h;
            updateDirty();
        });

        window.addEventListener('beforeunload', function (e) {
            if (dirtyEl && !dirtyEl.hidden) {
                var msg = 'Есть несохранённые изменения. Покинуть страницу?';
                e.preventDefault();
                e.returnValue = msg;
                return msg;
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
