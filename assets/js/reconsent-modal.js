/**
 * Cashback Legal — Reconsent modal.
 *
 * Блокирующий модал для авторизованных юзеров с устаревшими согласиями.
 * Клики по любым ссылкам/формам кроме allowlist (/my-account/, logout)
 * перехватываются. До акцепта всех чекбоксов кнопка submit disabled.
 *
 * Конфиг: window.cashbackLegalReconsent (wp_localize_script).
 *
 * @since 1.3.0
 */
(function () {
    'use strict';

    if (typeof window === 'undefined' || !window.cashbackLegalReconsent) {
        return;
    }
    var cfg = window.cashbackLegalReconsent;
    if (!Array.isArray(cfg.types) || cfg.types.length === 0) {
        return;
    }

    function isAllowedHref(href) {
        if (!href || typeof href !== 'string') {
            return true;
        }
        if (href.charAt(0) === '#') {
            return true;
        }
        try {
            var url = new URL(href, window.location.origin);
            for (var i = 0; i < cfg.allowedPaths.length; i++) {
                if (url.pathname.indexOf(cfg.allowedPaths[i]) !== -1) {
                    return true;
                }
            }
            // Внешние ссылки (target=_blank на документы) — разрешаем.
            if (url.host !== window.location.host) {
                return true;
            }
        } catch (e) {
            return false;
        }
        return false;
    }

    function blockNavigation() {
        document.addEventListener('click', function (ev) {
            var anchor = ev.target.closest && ev.target.closest('a');
            if (!anchor) {
                return;
            }
            // Внутри модала — пропускаем (target=_blank на документы).
            if (anchor.closest('#cashback-legal-reconsent-modal')) {
                return;
            }
            if (!isAllowedHref(anchor.getAttribute('href'))) {
                ev.preventDefault();
                ev.stopPropagation();
            }
        }, true);

        document.addEventListener('submit', function (ev) {
            var form = ev.target;
            if (form && form.closest && form.closest('#cashback-legal-reconsent-modal')) {
                return;
            }
            ev.preventDefault();
            ev.stopPropagation();
        }, true);
    }

    function buildModal() {
        var container = document.getElementById('cashback-legal-reconsent-modal');
        if (!container) {
            return null;
        }
        container.innerHTML = '';

        var backdrop = document.createElement('div');
        backdrop.className = 'cashback-legal-reconsent-modal__backdrop';
        container.appendChild(backdrop);

        var dialog = document.createElement('div');
        dialog.className = 'cashback-legal-reconsent-modal__dialog';
        dialog.setAttribute('role', 'document');

        var title = document.createElement('h2');
        title.className = 'cashback-legal-reconsent-modal__title';
        title.textContent = cfg.i18n.title;
        dialog.appendChild(title);

        var msg = document.createElement('p');
        msg.className = 'cashback-legal-reconsent-modal__message';
        msg.textContent = cfg.i18n.message;
        dialog.appendChild(msg);

        var list = document.createElement('div');
        list.className = 'cashback-legal-reconsent-modal__list';

        cfg.types.forEach(function (item, idx) {
            var row = document.createElement('label');
            row.className = 'cashback-legal-reconsent-modal__row';

            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.required = true;
            checkbox.setAttribute('data-cashback-reconsent', item.type);
            checkbox.id = 'cashback-reconsent-' + idx;

            var label = document.createElement('span');
            label.className = 'cashback-legal-reconsent-modal__row-label';
            label.textContent = item.title + ' (v' + item.version + ')';

            row.appendChild(checkbox);
            row.appendChild(label);

            if (item.url) {
                var link = document.createElement('a');
                link.href = item.url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.className = 'cashback-legal-reconsent-modal__link';
                link.textContent = cfg.i18n.reading;
                row.appendChild(link);
            }
            list.appendChild(row);
        });

        dialog.appendChild(list);

        var actions = document.createElement('div');
        actions.className = 'cashback-legal-reconsent-modal__actions';

        var submitBtn = document.createElement('button');
        submitBtn.type = 'button';
        submitBtn.className = 'cashback-legal-reconsent-modal__btn cashback-legal-reconsent-modal__btn--primary';
        submitBtn.textContent = cfg.i18n.submit;

        var logoutLink = document.createElement('a');
        // wp_logout_url() выдаёт wp-login.php?action=logout с одноразовым nonce —
        // мгновенный logout без WC confirmation page. Fallback оставлен на случай
        // старого закэшированного JS без cfg.logoutUrl.
        logoutLink.href = (typeof cfg.logoutUrl === 'string' && cfg.logoutUrl)
            ? cfg.logoutUrl
            : '/my-account/customer-logout/';
        logoutLink.className = 'cashback-legal-reconsent-modal__btn cashback-legal-reconsent-modal__btn--secondary';
        logoutLink.textContent = cfg.i18n.logout;

        var errorBox = document.createElement('p');
        errorBox.className = 'cashback-legal-reconsent-modal__error';
        errorBox.textContent = cfg.i18n.errorAcceptRequired || 'Примите новые пользовательские соглашения';
        errorBox.setAttribute('role', 'alert');
        dialog.appendChild(errorBox);

        actions.appendChild(submitBtn);
        actions.appendChild(logoutLink);
        dialog.appendChild(actions);

        container.appendChild(dialog);

        var checkboxes = container.querySelectorAll('[data-cashback-reconsent]');

        // На change снимаем подсветку у этой строки и (если все отмечены) скрываем общую ошибку.
        checkboxes.forEach(function (cb) {
            cb.addEventListener('change', function () {
                var row = cb.closest('.cashback-legal-reconsent-modal__row');
                if (cb.checked && row) {
                    row.classList.remove('has-error');
                }
                var allChecked = true;
                checkboxes.forEach(function (other) {
                    if (!other.checked) {
                        allChecked = false;
                    }
                });
                if (allChecked) {
                    errorBox.classList.remove('is-visible');
                }
            });
        });

        submitBtn.addEventListener('click', function () {
            var allChecked = true;
            checkboxes.forEach(function (cb) {
                var row = cb.closest('.cashback-legal-reconsent-modal__row');
                if (!cb.checked) {
                    allChecked = false;
                    if (row) {
                        row.classList.add('has-error');
                    }
                } else if (row) {
                    row.classList.remove('has-error');
                }
            });
            if (!allChecked) {
                errorBox.classList.add('is-visible');
                return;
            }
            errorBox.classList.remove('is-visible');
            submitBtn.disabled = true;
            submitConsent(submitBtn, container);
        });

        return container;
    }

    function submitConsent(submitBtn, container) {
        var body = new URLSearchParams();
        body.append('action', cfg.action);
        body.append('nonce', cfg.nonce);

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (resp) {
            if (!resp.ok) {
                throw new Error('http_' + resp.status);
            }
            return resp.json();
        }).then(function (data) {
            if (data && data.success) {
                container.classList.add('is-hidden');
                window.location.reload();
            } else {
                submitBtn.disabled = false;
            }
        }).catch(function () {
            submitBtn.disabled = false;
        });
    }

    function start() {
        var container = buildModal();
        if (!container) {
            return;
        }
        blockNavigation();
        // focus-trap простой — фокус на первый чекбокс.
        var firstCheckbox = container.querySelector('[data-cashback-reconsent]');
        if (firstCheckbox && firstCheckbox.focus) {
            firstCheckbox.focus();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
