(function () {
  const config = window.cashbackPriceAssistantAdmin || {};
  const root = document.querySelector('[data-price-assistant-admin]');
  if (!root || !config.restBase) {
    return;
  }

  const labels = config.labels || {};
  const notice = document.getElementById('cashback-pa-admin-notice');
  const STORE_PER_PAGE = 20;
  const state = {
    stores: [],
    storePage: 1,
    logoFrame: null,
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
      renderStorePagination(data);
      return;
    }
    target.innerHTML =
      '<table class="widefat striped"><thead><tr>' +
      '<th>Магазин</th><th>Код</th><th>Статус</th><th>Источники</th><th>Действие</th>' +
      '</tr></thead><tbody>' +
      items.map(function (store) {
        const sources = Array.isArray(store.sources) ? store.sources : [];
        return '<tr>' +
          '<td>' + renderStoreName(store) + '</td>' +
          '<td>' + escapeHtml(store.store_code || '') + '</td>' +
          '<td>' + escapeHtml(store.enabled ? (labels.enabled || 'Включён') : (labels.disabled || 'Отключён')) + '</td>' +
          '<td>' + renderSourceDetails(sources) + '</td>' +
          '<td>' + renderStoreAction(store) + '</td>' +
          '</tr>';
      }).join('') +
      '</tbody></table>';
    renderStorePagination(data);
  }

  function renderStoreName(store) {
    const name = escapeHtml(store.display_name || '');
    const logoUrl = store.logo_url || '';
    if (!logoUrl) {
      return name;
    }
    return '<span class="cashback-pa-store-name">' +
      '<img class="cashback-pa-store-logo" src="' +
      escapeHtml(logoUrl) +
      '" alt="' +
      name +
      '" loading="lazy" />' +
      '<span>' +
      name +
      '</span>' +
      '</span>';
  }

  function renderStoreAction(store) {
    const storeId = store.store_id || '';
    const nextEnabled = !store.enabled;
    const label = store.enabled ? 'Деактивировать' : 'Активировать';
    return '<div class="cashback-pa-actions">' +
      '<button type="button" class="button" data-pa-edit-store data-pa-store-id="' +
      escapeHtml(storeId) +
      '">Редактировать</button>' +
      '<button type="button" class="button" data-pa-toggle-store data-pa-store-id="' +
      escapeHtml(storeId) +
      '" data-pa-store-enabled="' +
      escapeHtml(nextEnabled ? 'true' : 'false') +
      '">' +
      escapeHtml(label) +
      '</button>' +
      '</div>';
  }

  function renderStorePagination(data) {
    const target = root.querySelector('[data-pa-store-pagination]');
    if (!target) {
      return;
    }
    const page = parseInt(data.page, 10) || state.storePage || 1;
    const totalItems = parseInt(data.total_items, 10) || 0;
    const totalPages = parseInt(data.total_pages, 10) || 0;
    if (
      totalItems <= STORE_PER_PAGE ||
      totalPages <= 1 ||
      !window.CashbackPagination ||
      typeof window.CashbackPagination.build !== 'function'
    ) {
      target.innerHTML = '';
      return;
    }
    target.innerHTML = window.CashbackPagination.build(page, totalPages, {
      containerClass: 'cashback-admin-pagination cashback-pa-store-pagination',
    });
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
    const path = section === 'stores'
      ? '/stores?page=' + encodeURIComponent(state.storePage) + '&per_page=' + encodeURIComponent(STORE_PER_PAGE)
      : '/' + section;
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

  function storeForm() {
    return root.querySelector('[data-pa-store-form]');
  }

  function storeField(name) {
    const form = storeForm();
    return form ? form.querySelector('[name="' + name + '"]') : null;
  }

  function setStoreField(name, value) {
    const field = storeField(name);
    if (field) {
      field.value = value ? String(value) : '';
    }
  }

  function selectedStore(storeId) {
    return state.stores.find(function (store) {
      return String(store.store_id || '') === String(storeId || '');
    });
  }

  function resetStoreForm() {
    const form = storeForm();
    if (form) {
      form.reset();
    }
    setStoreField('editing_store_id', '');
    setStoreField('homepage_url', '');
    setStoreField('display_name', '');
    setLogoPreview('');
    const label = root.querySelector('[data-pa-store-submit-label]');
    const cancel = root.querySelector('[data-pa-store-cancel-edit]');
    if (label) {
      label.textContent = 'Сохранить магазин';
    }
    if (cancel) {
      cancel.classList.add('hidden');
      cancel.hidden = true;
    }
  }

  function beginStoreEdit(storeId) {
    const store = selectedStore(storeId);
    if (!store) {
      return;
    }
    setStoreField('editing_store_id', store.store_id || '');
    setStoreField('homepage_url', store.homepage_url || '');
    setStoreField('display_name', store.display_name || '');
    setLogoPreview(store.logo_url || '');
    const label = root.querySelector('[data-pa-store-submit-label]');
    const cancel = root.querySelector('[data-pa-store-cancel-edit]');
    if (label) {
      label.textContent = 'Сохранить изменения';
    }
    if (cancel) {
      cancel.classList.remove('hidden');
      cancel.hidden = false;
    }
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
      const uploadLogo = event.target.closest('[data-pa-logo-upload]');
      if (uploadLogo) {
        event.preventDefault();
        openLogoFrame();
        return;
      }

      const removeLogo = event.target.closest('[data-pa-logo-remove]');
      if (removeLogo) {
        event.preventDefault();
        setLogoPreview('');
        return;
      }

      const editStore = event.target.closest('[data-pa-edit-store]');
      if (editStore) {
        event.preventDefault();
        beginStoreEdit(editStore.getAttribute('data-pa-store-id') || '');
        return;
      }

      const cancelEdit = event.target.closest('[data-pa-store-cancel-edit]');
      if (cancelEdit) {
        event.preventDefault();
        resetStoreForm();
        return;
      }

      const pageLink = event.target.closest('[data-pa-store-pagination] .page-numbers[data-page]');
      if (pageLink) {
        event.preventDefault();
        state.storePage = parseInt(pageLink.getAttribute('data-page'), 10) || 1;
        loadSection('stores');
        return;
      }

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
        const editingStoreId = form.get('editing_store_id') || '';
        const payload = {
          display_name: form.get('display_name'),
          homepage_url: form.get('homepage_url') || null,
          logo_url: form.get('logo_url') || null,
        };
        const isEditing = Boolean(editingStoreId);
        const path = isEditing ? '/stores/' + encodeURIComponent(editingStoreId) : '/stores';
        const method = isEditing ? 'PATCH' : 'POST';
        if (!isEditing) {
          payload.enabled = true;
        }
        request(path, {
          method: method,
          body: JSON.stringify(payload),
        }).then(function () {
          showNotice(labels.saved || 'Сохранено.', false);
          if (!isEditing) {
            state.storePage = 1;
          }
          resetStoreForm();
          loadSection('stores');
        }).catch(function () {
          showNotice(labels.saveError || 'Не удалось сохранить.', true);
        });
      });
    }
  }

  function openLogoFrame() {
    if (!window.wp || !window.wp.media) {
      showNotice(labels.saveError || 'Не удалось сохранить.', true);
      return;
    }
    if (!state.logoFrame) {
      state.logoFrame = window.wp.media({
        title: 'Логотип магазина',
        button: { text: 'Выбрать логотип' },
        library: { type: "image" },
        multiple: false,
      });
      state.logoFrame.on('select', function () {
        const attachment = state.logoFrame.state().get('selection').first();
        const data = attachment ? attachment.toJSON() : {};
        setLogoPreview(data.url || '');
      });
    }
    state.logoFrame.open();
  }

  function setLogoPreview(url) {
    const input = root.querySelector('[name="logo_url"]');
    const preview = root.querySelector('[data-pa-logo-preview]');
    const remove = root.querySelector('[data-pa-logo-remove]');
    if (input) {
      input.value = url || '';
    }
    if (preview) {
      preview.textContent = '';
      if (url) {
        const image = document.createElement('img');
        image.className = 'cashback-pa-store-logo';
        image.src = url;
        image.alt = 'Логотип магазина';
        preview.appendChild(image);
      }
    }
    if (remove) {
      remove.classList.toggle('hidden', !url);
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
