(function () {
  const config = window.cashbackPriceAssistantAdmin || {};
  const root = document.querySelector('[data-price-assistant-admin]');
  if (!root || !config.restBase) {
    return;
  }

  const labels = config.labels || {};
  const notice = document.getElementById('cashback-pa-admin-notice');
  const state = {
    stores: [],
  };

  function showNotice(message, isError) {
    if (!notice) {
      return;
    }
    notice.hidden = false;
    notice.className = 'notice ' + (isError ? 'notice-error' : 'notice-success');
    notice.querySelector('p').textContent = message;
  }

  function request(path, options) {
    const nextOptions = options || {};
    nextOptions.headers = Object.assign(
      {
        'X-WP-Nonce': config.nonce || '',
        'Content-Type': 'application/json',
      },
      nextOptions.headers || {}
    );
    return fetch(config.restBase + path, nextOptions).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) {
          throw new Error((data && data.message) || labels.loadError || 'Ошибка');
        }
        return data;
      });
    });
  }

  function renderItems(section, data) {
    const target = root.querySelector('[data-pa-section="' + section + '"]');
    if (!target) {
      return;
    }
    const items = Array.isArray(data.items) ? data.items : [];
    if (items.length === 0) {
      target.innerHTML = '<p>' + escapeHtml(labels.empty || 'Данных пока нет.') + '</p>';
      return;
    }
    const keys = Object.keys(items[0]).slice(0, 8);
    target.innerHTML =
      '<table class="widefat striped"><thead><tr>' +
      keys.map(function (key) { return '<th>' + escapeHtml(key) + '</th>'; }).join('') +
      '</tr></thead><tbody>' +
      items.map(function (item) {
        return '<tr>' + keys.map(function (key) {
          return '<td>' + escapeHtml(formatValue(valueForKey(item, key))) + '</td>';
        }).join('') + '</tr>';
      }).join('') +
      '</tbody></table>';
  }

  function renderStores(data) {
    const target = root.querySelector('[data-pa-section="stores"]');
    const items = Array.isArray(data.items) ? data.items : [];
    if (!target) {
      return;
    }
    state.stores = items;
    if (items.length === 0) {
      target.innerHTML = '<p>' + escapeHtml(labels.empty || 'Данных пока нет.') + '</p>';
      return;
    }
    target.innerHTML =
      '<table class="widefat striped"><thead><tr>' +
      '<th>Магазин</th><th>Код</th><th>Статус</th><th>Источники</th><th>Действие</th>' +
      '</tr></thead><tbody>' +
      items.map(function (store) {
        const sources = Array.isArray(store.sources) ? store.sources : [];
        return '<tr>' +
          '<td>' + escapeHtml(store.display_name || '') + '</td>' +
          '<td>' + escapeHtml(store.store_code || '') + '</td>' +
          '<td>' + escapeHtml(store.enabled ? (labels.enabled || 'Включён') : (labels.disabled || 'Отключён')) + '</td>' +
          '<td>' + renderSourceDetails(sources) + '</td>' +
          '<td>' + renderStoreAction(store) + '</td>' +
          '</tr>';
      }).join('') +
      '</tbody></table>';
  }

  function renderStoreAction(store) {
    const storeId = store.store_id || '';
    const nextEnabled = !store.enabled;
    const label = store.enabled ? 'Деактивировать' : 'Активировать';
    return '<button type="button" class="button" data-pa-toggle-store data-pa-store-id="' +
      escapeHtml(storeId) +
      '" data-pa-store-enabled="' +
      escapeHtml(nextEnabled ? 'true' : 'false') +
      '">' +
      escapeHtml(label) +
      '</button>';
  }

  function renderSourceDetails(sources) {
    if (!sources.length) {
      return '<span class="description">Источники не добавлены</span>';
    }
    return '<div class="cashback-pa-source-details" data-pa-source-details>' +
      sources.map(function (source) {
        return '<div class="cashback-pa-source-detail">' +
          '<strong>' + escapeHtml(source.display_name || source.source_code || 'Источник') + '</strong>' +
          '<span>' + escapeHtml(source.enabled ? (labels.enabled || 'Включён') : (labels.disabled || 'Отключён')) + '</span>' +
          '<span>Домены: ' + escapeHtml(formatValue(source.domains || [])) + '</span>' +
          '<span>Search: ' + escapeHtml(source.search_template || '') + '</span>' +
          '<span>Proxy: ' + escapeHtml(source.proxy_tier_policy || 'none') + '</span>' +
          '</div>';
      }).join('') +
      '</div>';
  }

  function loadSection(section) {
    const path = section === 'stores' ? '/stores' : '/' + section;
    const target = root.querySelector('[data-pa-section="' + section + '"]');
    if (target) {
      target.innerHTML = '<p>' + escapeHtml(labels.loading || 'Загрузка…') + '</p>';
    }
    request(path)
      .then(function (data) {
        if (section === 'stores') {
          renderStores(data);
          return;
        }
        renderItems(section, data);
      })
      .catch(function () {
        showNotice(labels.loadError || 'Не удалось загрузить данные.', true);
      });
  }

  function bindTabs() {
    root.querySelectorAll('[data-price-assistant-tab]').forEach(function (tab) {
      tab.addEventListener('click', function () {
        const section = tab.getAttribute('data-price-assistant-tab');
        root.querySelectorAll('[data-price-assistant-tab]').forEach(function (node) {
          node.classList.toggle('nav-tab-active', node === tab);
        });
        root.querySelectorAll('[data-price-assistant-panel]').forEach(function (panel) {
          panel.classList.toggle('is-active', panel.getAttribute('data-price-assistant-panel') === section);
        });
        loadSection(section);
      });
    });
  }

  function bindForms() {
    root.addEventListener('click', function (event) {
      const toggle = event.target.closest('[data-pa-toggle-store]');
      if (!toggle) {
        return;
      }
      const storeId = toggle.getAttribute('data-pa-store-id') || '';
      if (!storeId) {
        return;
      }
      request('/stores/' + encodeURIComponent(storeId), {
        method: 'PATCH',
        body: JSON.stringify({
          enabled: toggle.getAttribute('data-pa-store-enabled') === 'true',
        }),
      }).then(function () {
        showNotice(labels.saved || 'Сохранено.', false);
        loadSection('stores');
      }).catch(function () {
        showNotice(labels.saveError || 'Не удалось сохранить.', true);
      });
    });

    const storeForm = root.querySelector('[data-pa-store-form]');
    if (storeForm) {
      storeForm.addEventListener('submit', function (event) {
        event.preventDefault();
        const form = new FormData(storeForm);
        request('/stores', {
          method: 'POST',
          body: JSON.stringify({
            enabled: true,
            homepage_url: form.get('homepage_url') || null,
          }),
        }).then(function () {
          showNotice(labels.saved || 'Сохранено.', false);
          storeForm.reset();
          loadSection('stores');
        }).catch(function () {
          showNotice(labels.saveError || 'Не удалось сохранить.', true);
        });
      });
    }
  }

  function formatValue(value) {
    if (value === null || typeof value === 'undefined') {
      return '';
    }
    if (typeof value === 'object') {
      return JSON.stringify(value);
    }
    return String(value);
  }

  function valueForKey(item, key) {
    const pair = Object.entries(item).find(function (entry) {
      return entry[0] === key;
    });
    return pair ? pair[1] : '';
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (char) {
      switch (char) {
        case '&':
          return '&amp;';
        case '<':
          return '&lt;';
        case '>':
          return '&gt;';
        case '"':
          return '&quot;';
        case "'":
          return '&#039;';
        default:
          return char;
      }
    });
  }

  bindTabs();
  bindForms();
  loadSection('stores');
})();
