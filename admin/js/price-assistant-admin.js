(function () {
  const config = window.cashbackPriceAssistantAdmin || {};
  const root = document.querySelector('[data-price-assistant-admin]');
  if (!root || !config.restBase) {
    return;
  }

  const labels = config.labels || {};
  const notice = document.getElementById('cashback-pa-admin-notice');

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
    if (items.length === 0) {
      target.innerHTML = '<p>' + escapeHtml(labels.empty || 'Данных пока нет.') + '</p>';
      return;
    }
    target.innerHTML =
      '<table class="widefat striped"><thead><tr>' +
      '<th>Магазин</th><th>Код</th><th>Статус</th><th>Источники</th>' +
      '</tr></thead><tbody>' +
      items.map(function (store) {
        const sources = Array.isArray(store.sources) ? store.sources : [];
        return '<tr data-store-id="' + escapeHtml(store.store_id) + '">' +
          '<td>' + escapeHtml(store.display_name || '') + '</td>' +
          '<td>' + escapeHtml(store.store_code || '') + '</td>' +
          '<td>' + escapeHtml(store.enabled ? (labels.enabled || 'Включён') : (labels.disabled || 'Отключён')) + '</td>' +
          '<td>' + escapeHtml(String(sources.length)) + '</td>' +
          '</tr>';
      }).join('') +
      '</tbody></table>';
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

  function activeStoreId() {
    const row = root.querySelector('[data-store-id]');
    return row ? row.getAttribute('data-store-id') : '';
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
    const storeForm = root.querySelector('[data-pa-store-form]');
    if (storeForm) {
      storeForm.addEventListener('submit', function (event) {
        event.preventDefault();
        const form = new FormData(storeForm);
        request('/stores', {
          method: 'POST',
          body: JSON.stringify({
            store_code: form.get('store_code') || '',
            display_name: form.get('display_name') || '',
            enabled: form.get('enabled') === 'on',
            homepage_url: form.get('homepage_url') || null,
          }),
        }).then(function () {
          showNotice(labels.saved || 'Сохранено.', false);
          loadSection('stores');
        }).catch(function () {
          showNotice(labels.saveError || 'Не удалось сохранить.', true);
        });
      });
    }

    const sourceForm = root.querySelector('[data-pa-source-form]');
    if (sourceForm) {
      sourceForm.addEventListener('submit', function (event) {
        event.preventDefault();
        const storeId = activeStoreId();
        if (!storeId) {
          showNotice('Сначала добавьте или загрузите магазин.', true);
          return;
        }
        const form = new FormData(sourceForm);
        request('/stores/' + encodeURIComponent(storeId) + '/sources', {
          method: 'POST',
          body: JSON.stringify({
            source_code: form.get('source_code') || '',
            display_name: form.get('display_name') || '',
            enabled: form.get('enabled') === 'on',
            source_type: 'api',
            domains: splitList(form.get('domains')),
            search_template: form.get('search_template') || null,
            region_support: splitList(form.get('region_support')),
            priority: parseInt(form.get('priority') || '100', 10),
            extraction_mode: form.get('extraction_mode') || 'json',
            proxy_tier_policy: form.get('proxy_tier_policy') || 'none',
            min_fetch_interval_minutes: parseInt(form.get('min_fetch_interval_minutes') || '60', 10),
            matching_threshold: parseInt(form.get('matching_threshold') || '65', 10),
            cashback_merchant_mapping: parseMapping(form.get('cashback_merchant_mapping')),
          }),
        }).then(function () {
          showNotice(labels.saved || 'Сохранено.', false);
          loadSection('stores');
        }).catch(function () {
          showNotice(labels.saveError || 'Не удалось сохранить.', true);
        });
      });
    }
  }

  function splitList(value) {
    return String(value || '').split(',').map(function (item) {
      return item.trim();
    }).filter(Boolean);
  }

  function parseMapping(value) {
    const text = String(value || '').trim();
    if (!text) {
      return null;
    }
    try {
      return JSON.parse(text);
    } catch (error) {
      return { merchant_id: text };
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
