(function () {
  'use strict';

  function text(value) {
    return value == null ? '' : String(value);
  }

  function clear(node) {
    while (node.firstChild) {
      node.removeChild(node.firstChild);
    }
  }

  function renderItems(root, items) {
    clear(root);
    items.forEach(function (item) {
      var card = document.createElement('article');
      card.className = 'cashback-price-card';

      var title = document.createElement('h3');
      title.textContent = text(item.title);
      card.appendChild(title);

      var meta = document.createElement('p');
      meta.textContent = [item.store_name || item.store_domain, item.price, item.currency]
        .filter(Boolean)
        .join(' · ');
      card.appendChild(meta);

      var action = document.createElement('a');
      action.textContent = text(item.action_label || 'Купить');
      action.href = text(item.action_url || item.url || '#');
      action.rel = 'nofollow sponsored noopener';
      card.appendChild(action);

      root.appendChild(card);
    });
  }

  function config() {
    return window.CashbackPriceComparison || { copy: {} };
  }

  function copy(key, fallback) {
    return (config().copy && config().copy[key]) || fallback;
  }

  function scope(form) {
    if (form.closest) {
      return form.closest('[data-cashback-price-comparison]') || form;
    }
    return form;
  }

  function scopedQuery(form, selector) {
    var root = scope(form);
    return (root && root.querySelector && root.querySelector(selector)) ||
      (form.querySelector && form.querySelector(selector));
  }

  function setMessage(form, message) {
    var node = scopedQuery(form, '[data-price-comparison-message]');
    if (node) {
      node.textContent = message;
    }
  }

  function requestJson(url, options) {
    return fetch(url, options).then(function (response) {
      return response.json().then(function (payload) {
        return { ok: response.ok, status: response.status, payload: payload };
      });
    });
  }

  function postJson(url, payload) {
    return requestJson(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config().nonce || ''
      },
      body: JSON.stringify(payload)
    });
  }

  function getJson(url) {
    return requestJson(url, {
      method: 'GET',
      headers: {
        'X-WP-Nonce': config().nonce || ''
      }
    });
  }

  function pollUrl(runId) {
    return String(config().livePollBaseUrl || '').replace(/\/$/, '') + '/' + encodeURIComponent(runId);
  }

  function statusMessage(payload) {
    var statuses = Array.isArray(payload.store_statuses) ? payload.store_statuses : [];
    if (statuses.some(function (status) {
      return status && status.status === 'BLOCKED_BY_ANTIBOT';
    })) {
      return copy('partial', 'Часть магазинов недоступна');
    }
    if (payload.progress && payload.progress.current_store) {
      return 'Проверяем ' + text(payload.progress.current_store);
    }
    return copy('searching', 'Ищем в магазинах...');
  }

  function renderSearchPayload(form, payload, results) {
    var items = Array.isArray(payload.items) ? payload.items : [];
    if (payload.status === 'partial') {
      setMessage(form, statusMessage(payload));
    } else if (!items.length) {
      var warnings = payload.meta && Array.isArray(payload.meta.warnings) ? payload.meta.warnings : [];
      setMessage(form, text(warnings[0] || copy('notFound', 'Товаров не нашлось')));
    } else {
      setMessage(form, '');
    }
    if (results) {
      renderItems(results, items);
    }
  }

  function pollLiveSearch(form, runId, startedAt, results) {
    return getJson(pollUrl(runId)).then(function (result) {
      var payload = result.payload || {};
      if (!result.ok || payload.status === 'error') {
        setMessage(form, text(payload.message || copy('error', 'Ошибка поиска')));
        if (results) {
          renderItems(results, []);
        }
        return;
      }

      if (payload.status === 'running' || payload.status === 'queued') {
        setMessage(form, statusMessage(payload));
        if (Date.now() - startedAt >= 180000) {
          setMessage(form, copy('error', 'Ошибка поиска'));
          return;
        }
        if (typeof setTimeout === 'function') {
          setTimeout(function () {
            pollLiveSearch(form, runId, startedAt, results);
          }, 2000);
        }
        return;
      }

      if (payload.status === 'blocked') {
        setMessage(form, statusMessage(payload));
        if (results) {
          renderItems(results, []);
        }
        return;
      }

      renderSearchPayload(form, payload, results);
    });
  }

  function startLiveSearch(form, city, query, results) {
    setMessage(form, copy('searching', 'Ищем в магазинах...'));
    if (results) {
      renderItems(results, []);
    }
    return postJson(config().liveStartUrl, { city: city, query: query }).then(function (result) {
      var payload = result.payload || {};
      if (!result.ok || payload.status === 'error') {
        setMessage(form, text(payload.message || copy('error', 'Ошибка поиска')));
        return;
      }
      if (!payload.run_id) {
        setMessage(form, copy('error', 'Ошибка поиска'));
        return;
      }
      return pollLiveSearch(form, payload.run_id, Date.now(), results);
    });
  }

  function handleCityEdit(event) {
    var button = event.target;
    if (!button || !button.matches || !button.matches('[data-price-comparison-city-edit]')) {
      return;
    }

    event.preventDefault();

    var root = button.closest && button.closest('[data-cashback-price-comparison]');
    var input = root && root.querySelector && root.querySelector('[data-price-comparison-city-input]');
    if (!input) {
      return;
    }

    input.readOnly = false;
    if (input.removeAttribute) {
      input.removeAttribute('readonly');
    }
    if (input.focus) {
      input.focus();
    }
    if (input.select) {
      input.select();
    }
  }

  function handleSubmit(event) {
    var form = event.target;
    if (!form || !form.matches || !form.matches('[data-price-comparison-form]')) {
      return;
    }

    event.preventDefault();

    var cityInput = form.querySelector('[name="city"]');
    var queryInput = form.querySelector('[name="query"]');
    var results = scopedQuery(form, '[data-price-comparison-results]');
    var city = cityInput && cityInput.value ? cityInput.value.trim() : '';
    var query = queryInput && queryInput.value ? queryInput.value.trim() : '';

    if (!city) {
      setMessage(form, copy('emptyCity', 'Укажите город для поиска'));
      return;
    }
    if (!query) {
      setMessage(form, copy('emptyQuery', 'Укажите название товара'));
      return;
    }

    setMessage(form, '');
    if (config().liveStartUrl && config().livePollBaseUrl) {
      startLiveSearch(form, city, query, results).catch(function () {
        setMessage(form, copy('error', 'Ошибка поиска'));
      });
      return;
    }

    postJson(config().restUrl, { city: city, query: query })
      .then(function (result) {
        var payload = result.payload || {};
        if (!result.ok || payload.status === 'error') {
          setMessage(form, text(payload.message || copy('error', 'Ошибка поиска')));
          if (results) {
            renderItems(results, []);
          }
          return;
        }

        renderSearchPayload(form, payload, results);
      })
      .catch(function () {
        setMessage(form, copy('error', 'Ошибка поиска'));
      });
  }

  if (typeof document !== 'undefined' && document.addEventListener) {
    document.addEventListener('submit', handleSubmit);
    document.addEventListener('click', handleCityEdit);
  }

  window.CashbackPriceComparisonRenderer = {
    renderItems: renderItems,
  };
})();
