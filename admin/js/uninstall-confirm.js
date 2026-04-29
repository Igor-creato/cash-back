/**
 * Cashback uninstall confirm modal.
 *
 * Включается на странице plugins.php?action=delete-selected (verify-delete).
 * Перехватывает submit формы WP, показывает 2-шаговую модалку, AJAX'ом
 * пишет выбор пользователя в transient, после чего отдаёт submit назад
 * нативной форме WP. Vanilla JS, без зависимостей.
 */
(function () {
    'use strict';

    if (typeof window.CashbackUninstallConfirm === 'undefined') {
        return;
    }

    var cfg = window.CashbackUninstallConfirm;
    var i18n = cfg.i18n || {};

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        var form = findVerifyDeleteForm();
        if (!form) {
            return;
        }
        if (!formContainsOurPlugin(form)) {
            return;
        }
        form.addEventListener('submit', onSubmit, true);
    }

    /**
     * WP verify-delete страница содержит форму с hidden input
     * verify-delete=1 и action=delete-selected. Ищем именно её.
     */
    function findVerifyDeleteForm() {
        var forms = document.querySelectorAll('form');
        for (var i = 0; i < forms.length; i++) {
            var f = forms[i];
            var verify = f.querySelector('input[name="verify-delete"]');
            var action = f.querySelector('input[name="action"]');
            if (verify && action && action.value === 'delete-selected') {
                return f;
            }
        }
        return null;
    }

    function formContainsOurPlugin(form) {
        var inputs = form.querySelectorAll('input[name="checked[]"]');
        for (var i = 0; i < inputs.length; i++) {
            if (inputs[i].value === cfg.pluginBasename) {
                return true;
            }
        }
        return false;
    }

    var modalState = { confirmed: false };

    function onSubmit(e) {
        if (modalState.confirmed) {
            return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        showStep1(e.target);
    }

    function showStep1(form) {
        var modal = buildModal({
            title: i18n.step1Title,
            body: '<p>' + escapeHtml(i18n.step1Body) + '</p>',
            buttons: [
                {
                    label: i18n.btnYes,
                    klass: 'button button-primary cashback-uc-btn-yes',
                    onClick: function () { closeModal(modal); showStep2(form); }
                },
                {
                    label: i18n.btnNo,
                    klass: 'button cashback-uc-btn-no',
                    onClick: function () {
                        setMode('0', function (ok) {
                            if (!ok) { return; }
                            closeModal(modal);
                            submitForm(form);
                        });
                    }
                }
            ],
            danger: false
        });
        document.body.appendChild(modal);
        focusFirstButton(modal);
    }

    function showStep2(form) {
        var modal = buildModal({
            title: i18n.step2Title,
            body: '<p class="cashback-uc-warning">' + escapeHtml(i18n.step2Body) + '</p>',
            buttons: [
                {
                    label: i18n.btnContinue,
                    klass: 'button button-primary button-link-delete cashback-uc-btn-continue',
                    onClick: function () {
                        setMode('1', function (ok) {
                            if (!ok) { return; }
                            closeModal(modal);
                            submitForm(form);
                        });
                    }
                },
                {
                    label: i18n.btnCancel,
                    klass: 'button cashback-uc-btn-cancel',
                    onClick: function () { closeModal(modal); }
                }
            ],
            danger: true
        });
        document.body.appendChild(modal);
        focusFirstButton(modal);
    }

    function buildModal(opts) {
        var overlay = document.createElement('div');
        overlay.className = 'cashback-uc-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'cashback-uc-title');

        var box = document.createElement('div');
        box.className = 'cashback-uc-box' + (opts.danger ? ' cashback-uc-danger' : '');

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'cashback-uc-close';
        closeBtn.setAttribute('aria-label', i18n.closeAria || 'Close');
        closeBtn.textContent = '×';
        closeBtn.addEventListener('click', function () { closeModal(overlay); });

        var h = document.createElement('h2');
        h.id = 'cashback-uc-title';
        h.className = 'cashback-uc-title';
        h.textContent = opts.title || '';

        var body = document.createElement('div');
        body.className = 'cashback-uc-body';
        body.innerHTML = opts.body || '';

        var actions = document.createElement('div');
        actions.className = 'cashback-uc-actions';
        opts.buttons.forEach(function (b) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = b.klass;
            btn.textContent = b.label;
            btn.addEventListener('click', b.onClick);
            actions.appendChild(btn);
        });

        var error = document.createElement('div');
        error.className = 'cashback-uc-error';
        error.setAttribute('role', 'alert');
        error.style.display = 'none';

        box.appendChild(closeBtn);
        box.appendChild(h);
        box.appendChild(body);
        box.appendChild(error);
        box.appendChild(actions);
        overlay.appendChild(box);

        overlay.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                closeModal(overlay);
            }
        });

        return overlay;
    }

    function focusFirstButton(modal) {
        var first = modal.querySelector('.cashback-uc-actions button');
        if (first) {
            first.focus();
        }
    }

    function closeModal(modal) {
        if (modal && modal.parentNode) {
            modal.parentNode.removeChild(modal);
        }
    }

    function showError(modal, msg) {
        var box = modal.querySelector('.cashback-uc-error');
        if (!box) { return; }
        box.textContent = msg;
        box.style.display = 'block';
    }

    /**
     * AJAX: пишем выбор в transient. callback(ok:boolean).
     * При сетевой ошибке/HTTP не-200 показываем ошибку в модалке и НЕ submit'им форму.
     */
    function setMode(purge, cb) {
        var modal = document.querySelector('.cashback-uc-overlay');
        var body = 'action=cashback_set_uninstall_mode'
            + '&nonce=' + encodeURIComponent(cfg.nonce)
            + '&purge=' + encodeURIComponent(purge);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', cfg.ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) { return; }
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp && resp.success) { cb(true); return; }
                } catch (e) { /* fallthrough */ }
            }
            if (modal) { showError(modal, i18n.errAjax || 'Error'); }
            cb(false);
        };
        xhr.send(body);
    }

    function submitForm(form) {
        modalState.confirmed = true;
        // form.submit() не триггерит submit-event → safe от рекурсии.
        form.submit();
    }

    function escapeHtml(s) {
        if (s === null || s === undefined) { return ''; }
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
})();
