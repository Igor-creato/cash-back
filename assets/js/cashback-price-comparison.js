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
    fetch(config().restUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config().nonce || ''
      },
      body: JSON.stringify({ city: city, query: query })
    })
      .then(function (response) {
        return response.json().then(function (payload) {
          return { ok: response.ok, payload: payload };
        });
      })
      .then(function (result) {
        var payload = result.payload || {};
        if (!result.ok || payload.status === 'error') {
          setMessage(form, text(payload.message || copy('error', 'Ошибка поиска')));
          if (results) {
            renderItems(results, []);
          }
          return;
        }

        var items = Array.isArray(payload.items) ? payload.items : [];
        if (!items.length) {
          var warnings = payload.meta && Array.isArray(payload.meta.warnings) ? payload.meta.warnings : [];
          setMessage(form, text(warnings[0] || copy('notFound', 'Товаров не нашлось')));
        }
        if (results) {
          renderItems(results, items);
        }
      })
      .catch(function () {
        setMessage(form, copy('error', 'Ошибка поиска'));
      });
  }

  if (typeof document !== 'undefined' && document.addEventListener) {
    document.addEventListener('submit', handleSubmit);
  }

  window.CashbackPriceComparisonRenderer = {
    renderItems: renderItems,
  };
})();
