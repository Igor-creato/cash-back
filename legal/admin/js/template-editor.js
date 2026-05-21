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

    function closeDialog(dlg) {
        if (!dlg) { return; }
        if (typeof dlg.close === 'function') { dlg.close(); }
        else { dlg.removeAttribute('open'); }
    }

    // Единый делегированный обработчик кликов. Делегация на document вместо
    // прямого addEventListener на каждой кнопке гарантирует работоспособность
    // даже если кнопки добавлены в DOM после init() или если что-то прерывает
    // прямую привязку (TinyMCE async-init, theme-overlay, browser extension).
    function onDocumentClick(e) {
        var t = e.target;
        if (!t || typeof t.closest !== 'function') { return; }

        // Все обработчики работают только внутри wrap'а редактора, чтобы не
        // ловить клики по чужим элементам на других admin-страницах.
        var wrap = t.closest('.cashback-legal-template-editor');
        if (!wrap) { return; }

        // placeholder chip — копирование/вставка
        var chip = t.closest('.cashback-legal-placeholder-chip');
        if (chip) {
            e.preventDefault();
            var ph = chip.getAttribute('data-placeholder') || '';
            if (window.navigator && window.navigator.clipboard) {
                window.navigator.clipboard.writeText(ph).catch(function () {});
            }
            var ed = getEditor();
            if (ed && !ed.isHidden()) {
                ed.execCommand('mceInsertContent', false, ph);
            }
            notify('Скопировано / вставлено: ' + ph, 'ok');
            return;
        }

        // dialog close (×)
        var closeBtn = t.closest('.cashback-legal-template-dialog-close');
        if (closeBtn) {
            e.preventDefault();
            closeDialog(closeBtn.closest('dialog'));
            return;
        }

        // основные кнопки
        var btn = t.closest('[data-action]');
        if (!btn) { return; }
        var action = btn.getAttribute('data-action');
        switch (action) {
            case 'save':            e.preventDefault(); saveDraft(); break;
            case 'discard':         e.preventDefault(); discardDraft(); break;
            case 'preview':         e.preventDefault(); preview(); break;
            case 'publish':         e.preventDefault(); openPublishDialog(); break;
            case 'cancel-publish':  e.preventDefault(); closeDialog(publishDialog); break;
            case 'confirm-publish': e.preventDefault(); closeDialog(publishDialog); doPublish(); break;
            default: /* посторонний data-action на странице — игнор */ break;
        }
    }

    function bindActions() {
        if (window.console && window.console.log) {
            window.console.log('cashback-legal-template-editor: bound (delegated)', {
                editorId:    cfg.editorId,
                bootType:    boot.type,
                chips:       document.querySelectorAll('.cashback-legal-placeholder-chip').length,
                save:        document.querySelectorAll('[data-action="save"]').length,
                discard:     document.querySelectorAll('[data-action="discard"]').length,
                preview:     document.querySelectorAll('[data-action="preview"]').length,
                publish:     document.querySelectorAll('[data-action="publish"]').length,
                confirmBtn:  !!publishConfirmBtn,
            });
        }

        document.addEventListener('click', onDocumentClick);

        if (publishConfirmInput) {
            publishConfirmInput.addEventListener('input', function () {
                var expected = publishConfirmInput.getAttribute('data-expected') || '';
                if (publishConfirmBtn) {
                    publishConfirmBtn.disabled = publishConfirmInput.value.trim() !== expected;
                }
            });
        }
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
