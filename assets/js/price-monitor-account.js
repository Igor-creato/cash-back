(function () {
    'use strict';

    var config = window.CashbackPriceMonitorAccount || {};
    var root = document.querySelector('[data-price-monitor-account]');

    if (!root) {
        return;
    }

    var form = root.querySelector('[data-price-monitor-add-form]');
    var feedback = root.querySelector('[data-price-monitor-feedback]');
    var itemsRoot = root.querySelector('[data-price-monitor-items]');
    var state = {
        cards: Array.isArray(config.items) ? config.items.slice() : []
    };

    function text(key, fallback) {
        if (config.i18n && typeof config.i18n[key] === 'string' && config.i18n[key] !== '') {
            return config.i18n[key];
        }

        return fallback;
    }

    function endpoint(base, path) {
        return String(base || '').replace(/\/$/, '') + path;
    }

    function request(path, options, base) {
        var settings = options || {};
        var method = settings.method || 'GET';
        var payload = settings.payload || null;
        var url = endpoint(base || config.restBase || '', path);
        var requestOptions = {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce || ''
            }
        };

        if (payload !== null) {
            requestOptions.body = JSON.stringify(payload);
        }

        function parseResponse(response) {
            if (response.status === 204 || typeof response.json !== 'function') {
                return Promise.resolve(null);
            }

            return response.json().catch(function (error) {
                if (error && error.name === 'SyntaxError') {
                    return null;
                }

                throw error;
            });
        }

        return fetch(url, requestOptions).then(function (response) {
            return parseResponse(response).then(function (data) {
                if (!response.ok) {
                    var errorPayload = data && data.error ? data.error : data;
                    throw {
                        code: errorPayload && errorPayload.code ? errorPayload.code : 'request_failed',
                        message: errorPayload && errorPayload.message ? errorPayload.message : text('fetchFailed', 'Не удалось обновить данные товара')
                    };
                }

                return data;
            });
        });
    }

    function clientRequestId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID().replace(/-/g, '');
        }

        return (Date.now().toString(16) + Math.random().toString(16).slice(2)).replace(/[^a-f0-9]/gi, '');
    }

    function clearNode(node) {
        while (node.children && node.children.length > 0) {
            node.removeChild(node.children[0]);
        }
        node.textContent = '';
        node.innerHTML = '';
    }

    function setFeedback(message, variant) {
        feedback.className = 'price-monitor-account__feedback price-monitor-account__feedback--' + (variant || 'info');
        feedback.textContent = message || '';
    }

    function minorToPrice(value, currency) {
        if (typeof value !== 'number' || !Number.isFinite(value)) {
            return '';
        }

        return (value / 100).toFixed(2) + ' ' + (currency || 'RUB');
    }

    function normalizeCard(data) {
        if (data && data.card) {
            return data.card;
        }

        return data || {};
    }

    function ensureArray(value) {
        return Array.isArray(value) ? value : [];
    }

    function buildSparkline(points) {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 120 32');
        svg.setAttribute('class', 'price-monitor-account__sparkline');

        var polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
        var values = ensureArray(points).map(function (point) {
            return Number(point.min_price_minor || point.max_price_minor || 0);
        }).filter(function (value) {
            return Number.isFinite(value);
        });
        var min = values.length ? Math.min.apply(Math, values) : 0;
        var max = values.length ? Math.max.apply(Math, values) : 0;
        var span = max - min || 1;
        var coords = values.map(function (value, index) {
            var x = values.length === 1 ? 60 : (index / (values.length - 1)) * 120;
            var y = 28 - (((value - min) / span) * 24);
            return x.toFixed(2) + ',' + y.toFixed(2);
        }).join(' ');

        polyline.setAttribute('points', coords || '0,28 120,28');
        polyline.setAttribute('fill', 'none');
        polyline.setAttribute('stroke', '#2f6fed');
        polyline.setAttribute('stroke-width', '2');
        svg.appendChild(polyline);

        return svg;
    }

    function renderEmpty() {
        clearNode(itemsRoot);
        var empty = document.createElement('p');
        empty.className = 'price-monitor-account__empty';
        empty.textContent = text('empty', 'Пока нет отслеживаемых товаров');
        itemsRoot.appendChild(empty);
    }

    function removeCard(itemId) {
        state.cards = state.cards.filter(function (card) {
            return String(card.item && card.item.id) !== String(itemId);
        });
        renderCards();
    }

    function updateCard(itemId, patch) {
        state.cards = state.cards.map(function (card) {
            if (String(card.item && card.item.id) !== String(itemId)) {
                return card;
            }

            var next = Object.assign({}, card);
            next.item = Object.assign({}, card.item || {}, patch.item || {});
            next.product = Object.assign({}, card.product || {}, patch.product || {});
            next.source = Object.assign({}, card.source || {}, patch.source || {});
            next.actions = Object.assign({}, card.actions || {}, patch.actions || {});
            next.activation = Object.assign({}, card.activation || {}, patch.activation || {});

            if (patch.chart) {
                next.chart = Object.assign({}, card.chart || {}, patch.chart);
            }

            return next;
        });
        renderCards();
    }

    function isUnavailableActivation(data) {
        return !!(data && (data.cashback_available === false || data.status === 'not_available'));
    }

    function openActivation(card) {
        var directUrl = (card.actions && card.actions.direct_url) || (card.item && card.item.canonical_url) || '';
        if (!directUrl) {
            setFeedback(text('cashbackUnavailable', 'Кэшбэк не начислится'), 'error');
            return;
        }

        var popup = window.open('about:blank', '_blank');
        if (popup) {
            popup.opener = null;
        }

        request('/activate', {
            method: 'POST',
            payload: {
                url: directUrl,
                client_request_id: clientRequestId()
            }
        }, config.linkCheckerRestBase || '')
            .then(function (data) {
                if (isUnavailableActivation(data)) {
                    if (popup && typeof popup.close === 'function') {
                        popup.close();
                    }

                    setFeedback(data.message || data.warning || text('cashbackUnavailable', 'Кэшбэк не начислится'), 'error');
                    return;
                }

                var targetUrl = data.activation_page_url || data.redirect_url || data.cashback_url || '';

                if (targetUrl && popup) {
                    popup.location = targetUrl;
                    return;
                }

                if (targetUrl) {
                    window.location.href = targetUrl;
                    return;
                }

                if (popup && typeof popup.close === 'function') {
                    popup.close();
                }
                setFeedback(data.message || text('cashbackUnavailable', 'Кэшбэк не начислится'), 'error');
            })
            .catch(function (error) {
                if (popup && typeof popup.close === 'function') {
                    popup.close();
                }

                setFeedback(error.message || text('fetchFailed', 'Не удалось обновить данные товара'), 'error');
            });
    }

    function buildCard(cardData) {
        var card = normalizeCard(cardData);
        var item = card.item || {};
        var product = card.product || {};
        var source = card.source || {};
        var chart = card.chart || {};
        var actionText = (card.activation && card.activation.button_text) || text('cashbackButton', 'Активировать кэшбэк');

        var cardNode = document.createElement('article');
        cardNode.className = 'price-monitor-account__card';
        cardNode.setAttribute('data-item-id', String(item.id || ''));
        cardNode.textContent = [
            product.title || '',
            product.rating_value || '',
            source.display_name || ''
        ].join(' ').trim();

        var media = document.createElement('div');
        media.className = 'price-monitor-account__media';

        var image = document.createElement('img');
        image.className = 'price-monitor-account__image';
        image.setAttribute('src', product.image_url || '');
        image.setAttribute('alt', product.title || '');
        media.appendChild(image);

        var meta = document.createElement('div');
        meta.className = 'price-monitor-account__meta';

        var title = document.createElement('h3');
        title.className = 'price-monitor-account__title';
        title.textContent = product.title || text('fetchPending', 'Данные товара загружаются');
        meta.appendChild(title);

        var price = document.createElement('p');
        price.className = 'price-monitor-account__price';
        price.textContent = minorToPrice(Number(product.current_price_minor), product.currency);
        meta.appendChild(price);

        var rating = document.createElement('p');
        rating.className = 'price-monitor-account__rating';
        rating.textContent = product.rating_value ? String(product.rating_value) : '';
        meta.appendChild(rating);

        var sourceRow = document.createElement('div');
        sourceRow.className = 'price-monitor-account__source';
        var sourceLogo = document.createElement('img');
        sourceLogo.className = 'price-monitor-account__source-logo';
        sourceLogo.setAttribute('src', source.logo_url || '');
        sourceLogo.setAttribute('alt', source.display_name || '');
        sourceRow.appendChild(sourceLogo);
        var sourceName = document.createElement('span');
        sourceName.className = 'price-monitor-account__source-name';
        sourceName.textContent = source.display_name || source.source_domain || '';
        sourceRow.appendChild(sourceName);
        meta.appendChild(sourceRow);

        media.appendChild(meta);
        cardNode.appendChild(media);

        var chartWrap = document.createElement('div');
        chartWrap.className = 'price-monitor-account__chart';
        chartWrap.appendChild(buildSparkline(chart.points || []));
        cardNode.appendChild(chartWrap);

        var footer = document.createElement('div');
        footer.className = 'price-monitor-account__footer';

        var actionButton = document.createElement('button');
        actionButton.type = 'button';
        actionButton.className = 'price-monitor-account__action';
        actionButton.textContent = actionText;
        actionButton.addEventListener('click', function () {
            openActivation(card);
        });
        footer.appendChild(actionButton);

        var refreshButton = document.createElement('button');
        refreshButton.type = 'button';
        refreshButton.className = 'price-monitor-account__menu-refresh';
        refreshButton.textContent = text('refreshButton', 'Обновить');
        refreshButton.addEventListener('click', function () {
            request('/items/' + encodeURIComponent(String(item.id || '')) + '/refresh', {
                method: 'POST',
                payload: {
                    client_request_id: clientRequestId()
                }
            }).then(function (response) {
                updateCard(item.id, normalizeCard(response));
            }).catch(function (error) {
                setFeedback(error.message || text('fetchFailed', 'Не удалось обновить данные товара'), 'error');
            });
        });
        footer.appendChild(refreshButton);

        var editButton = document.createElement('button');
        editButton.type = 'button';
        editButton.className = 'price-monitor-account__menu-edit';
        editButton.textContent = text('editButton', 'Изменить цену');
        editButton.addEventListener('click', function () {
            var nextValue = window.prompt(text('editButton', 'Изменить цену'), String(item.target_price_minor || ''));
            if (nextValue === null || nextValue === '') {
                return;
            }

            request('/items/' + encodeURIComponent(String(item.id || '')), {
                method: 'PATCH',
                payload: {
                    target_price_minor: Number(nextValue),
                    client_request_id: clientRequestId()
                }
            }).then(function (response) {
                updateCard(item.id, response);
            }).catch(function (error) {
                setFeedback(error.message || text('fetchFailed', 'Не удалось обновить данные товара'), 'error');
            });
        });
        footer.appendChild(editButton);

        var deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = 'price-monitor-account__menu-delete';
        deleteButton.textContent = text('deleteButton', 'Удалить');
        deleteButton.addEventListener('click', function () {
            if (typeof window.confirm === 'function' && !window.confirm(text('deleteButton', 'Удалить'))) {
                return;
            }

            request('/items/' + encodeURIComponent(String(item.id || '')), {
                method: 'DELETE',
                payload: {
                    client_request_id: clientRequestId()
                }
            }).then(function () {
                removeCard(item.id);
            }).catch(function (error) {
                setFeedback(error.message || text('fetchFailed', 'Не удалось обновить данные товара'), 'error');
            });
        });
        footer.appendChild(deleteButton);

        cardNode.appendChild(footer);

        return cardNode;
    }

    function renderCards() {
        if (!state.cards.length) {
            renderEmpty();
            return;
        }

        clearNode(itemsRoot);
        state.cards.forEach(function (card) {
            itemsRoot.appendChild(buildCard(card));
        });
    }

    function handleSubmit(event) {
        if (!form || event.target !== form) {
            return;
        }

        event.preventDefault();

        var urlInput = form.querySelector('[name="url"]');
        var targetPriceInput = form.querySelector('[name="target_price_minor"]');
        var payload = {
            url: urlInput ? String(urlInput.value || '').trim() : '',
            client_request_id: clientRequestId()
        };

        if (targetPriceInput && String(targetPriceInput.value || '').trim() !== '') {
            payload.target_price_minor = Number(targetPriceInput.value);
        }

        request('/items', {
            method: 'POST',
            payload: payload
        }).then(function (response) {
            setFeedback('', 'info');
            state.cards.unshift(normalizeCard(response));
            renderCards();
        }).catch(function (error) {
            var messages = {
                unsupported_store: text('unsupportedStore', 'Магазин не поддерживается'),
                monitoring_unavailable: text('monitoringUnavailable', 'Для данного магазина мониторинг временно недоступен.'),
                duplicate_watchlist_item: text('duplicateWatchlistItem', 'Товар уже отслеживается'),
                limit_exceeded: text('limitExceeded', 'Достигнут лимит отслеживаемых товаров'),
                invalid_target_price: text('invalidTargetPrice', 'Проверьте желаемую цену'),
                not_product_url: text('notProductUrl', 'Укажите ссылку на карточку товара.'),
                unsafe_url: text('unsafeUrl', 'Ссылка небезопасна или недоступна для проверки.'),
                source_product_id_missing: text('sourceProductIdMissing', 'Не удалось определить товар по ссылке.'),
                source_url_pattern_unsupported: text('sourceUrlPatternUnsupported', 'Формат ссылки пока не поддерживается.')
            };
            var message = messages[error.code] || error.message || text('fetchFailed', 'Не удалось обновить данные товара');
            setFeedback(message, 'error');
        });
    }

    if (form) {
        document.addEventListener('submit', handleSubmit);
    }

    renderCards();
}());
