(function () {
    'use strict';

    var config = window.CashbackLinkChecker || {};

    function text(key, fallback) {
        return config.i18n && config.i18n[key] ? config.i18n[key] : fallback;
    }

    function endpoint(path) {
        return String(config.restBase || '').replace(/\/$/, '') + path;
    }

    function request(path, payload) {
        return fetch(endpoint(path), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce || ''
            },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (data) {
                if (!response.ok) {
                    throw data;
                }
                return data;
            });
        });
    }

    function clear(node) {
        while (node && node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function appendText(node, tag, className, value) {
        var child = document.createElement(tag);
        child.className = className;
        child.textContent = value;
        node.appendChild(child);
        return child;
    }

    function appendHtml(node, className, value) {
        var child = document.createElement('div');
        child.className = className;
        if (typeof window.cashbackSafeHtml === 'function') {
            child.innerHTML = window.cashbackSafeHtml(value);
        } else {
            child.textContent = String(value || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        }
        node.appendChild(child);
        return child;
    }

    function clientRequestId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID().replace(/-/g, '');
        }
        return (Date.now().toString(16) + Math.random().toString(16).slice(2) + Math.random().toString(16).slice(2))
            .replace(/[^a-f0-9]/gi, '')
            .slice(0, 32);
    }

    function appendLink(result, url, label) {
        var link = document.createElement('a');
        link.className = 'cashback-link-checker__goto cashback-btn-primary';
        link.href = url;
        link.rel = 'nofollow noopener';
        link.target = '_blank';
        link.textContent = label;
        result.appendChild(link);
        return link;
    }

    function renderError(result, message) {
        clear(result);
        result.className = 'cashback-link-checker__result cashback-link-checker__result--error';
        appendText(result, 'p', 'cashback-link-checker__message', message || text('error', 'Не удалось проверить ссылку.'));
    }

    function renderUnavailable(result, data) {
        clear(result);
        result.className = 'cashback-link-checker__result cashback-link-checker__result--unavailable';
        appendText(result, 'p', 'cashback-link-checker__message', data.warning || data.message || 'Кэшбэк для этой ссылки недоступен.');

        var directUrl = data.direct_url || data.url || data.redirect_url;
        if (directUrl) {
            appendLink(result, directUrl, data.button_text || 'Перейти в магазин');
        }
    }

    function renderConditions(result, data) {
        if (typeof data.conditions_html === 'string' && data.conditions_html.trim() !== '') {
            appendHtml(result, 'cashback-link-checker__conditions-html', data.conditions_html);
            return;
        }

        if (Array.isArray(data.conditions) && data.conditions.length > 0) {
            var list = document.createElement('ul');
            list.className = 'cashback-link-checker__conditions';
            data.conditions.forEach(function (condition) {
                appendText(list, 'li', 'cashback-link-checker__condition', condition);
            });
            result.appendChild(list);
        }
    }

    function showGuestWarning(onContinue) {
        if (config.isLoggedIn !== false) {
            return false;
        }
        if (!window.CashbackAffiliateGuestWarning || typeof window.CashbackAffiliateGuestWarning.show !== 'function') {
            return false;
        }

        window.CashbackAffiliateGuestWarning.show({
            loginUrl: config.loginUrl || '',
            warningMessage: config.guestWarningMessage || 'Вы не авторизованы, при переходе покупка не будет учтена сервисом. Продолжить?',
            onContinue: onContinue
        });

        return true;
    }

    function renderAvailable(form, result, data, directUrl) {
        clear(result);
        result.className = 'cashback-link-checker__result cashback-link-checker__result--available';

        var title = data.store && data.store.name ? data.store.name : data.host;
        appendText(result, 'p', 'cashback-link-checker__store', title);

        if (data.cashback && data.cashback.value) {
            appendText(
                result,
                'p',
                'cashback-link-checker__cashback',
                (data.cashback.label || 'Кэшбэк') + ': ' + data.cashback.value
            );
        }

        renderConditions(result, data);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'cashback-link-checker__button cashback-link-checker__button--activate cashback-btn-primary';
        button.textContent = data.button_text || 'Активировать кэшбэк';
        button.addEventListener('click', function () {
            if (showGuestWarning(function () {
                activate(form, result, directUrl, button);
            })) {
                return;
            }

            activate(form, result, directUrl, button);
        });
        result.appendChild(button);
    }

    function activate(form, result, directUrl, button) {
        var popup = null;
        var originalText = button.textContent;
        try {
            popup = window.open('about:blank', '_blank');
            if (popup) {
                popup.opener = null;
            }
        } catch (error) {
            popup = null;
        }

        button.disabled = true;
        button.textContent = 'Активируем...';

        request('/activate', {
            url: directUrl,
            client_request_id: clientRequestId()
        }).then(function (data) {
            if (data.cashback_available === false || data.status === 'not_available') {
                if (popup && !popup.closed) {
                    popup.close();
                }
                renderUnavailable(result, data);
                return;
            }

            var targetUrl = data.activation_page_url || data.redirect_url || data.cashback_url;
            if (targetUrl && popup) {
                popup.location = targetUrl;
            } else if (targetUrl) {
                window.location.href = targetUrl;
            }
            button.disabled = false;
            button.textContent = originalText;
        }).catch(function (error) {
            if (popup && !popup.closed) {
                popup.close();
            }
            button.disabled = false;
            button.textContent = originalText;
            renderError(result, error && error.message ? error.message : text('error', 'Не удалось активировать ссылку.'));
        });

        form.setAttribute('data-cashback-link-checker-activated', '1');
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.matches('[data-cashback-link-checker-form]')) {
            return;
        }

        event.preventDefault();

        var input = form.querySelector('[name="direct_url"]');
        var result = form.querySelector('[data-cashback-link-checker-result]');
        var directUrl = input ? input.value.trim() : '';
        if (!result) {
            return;
        }

        clear(result);
        result.className = 'cashback-link-checker__result cashback-link-checker__result--loading';
        appendText(result, 'p', 'cashback-link-checker__message', text('checking', 'Проверяем...'));

        request('/check', {
            url: directUrl
        }).then(function (data) {
            if (data.status === 'available') {
                renderAvailable(form, result, data, directUrl);
                return;
            }
            renderUnavailable(result, data);
        }).catch(function (error) {
            renderError(result, error && error.message ? error.message : text('error', 'Не удалось проверить ссылку.'));
        });
    });
}());
