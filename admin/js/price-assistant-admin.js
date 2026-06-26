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
    storesData: null,
    editingStoreId: '',
    storePage: 1,
    logoFrame: null,
    logoSelectCallback: null,
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
    state.storesData = data;
    if (items.length === 0) {
      target.innerHTML = '<p>' + escapeHtml(labels.empty || 'Данных пока нет.') + '</p>';
      renderStorePagination(data);
      return;
    }
    target.innerHTML =
      '<table class="widefat striped"><thead><tr>' +
      '<th>Магазин</th><th>URL</th><th>Логотип</th><th>Код</th><th>Статус</th><th>Источники</th><th>Действия</th>' +
      '</tr></thead><tbody>' +
      items.map(function (store) {
        const sources = Array.isArray(store.sources) ? store.sources : [];
        return '<tr data-pa-store-row data-pa-store-id="' + escapeHtml(store.store_id || '') + '">' +
          '<td class="edit-field">' + renderStoreDisplayName(store) + '</td>' +
          '<td class="edit-field">' + renderStoreHomepage(store) + '</td>' +
          '<td class="edit-field">' + renderStoreLogoCell(store) + '</td>' +
          '<td>' + escapeHtml(store.store_code || '') + '</td>' +
          '<td>' + escapeHtml(store.enabled ? (labels.enabled || 'Включён') : (labels.disabled || 'Отключён')) + '</td>' +
          '<td>' + renderSourceDetails(sources) + '</td>' +
          '<td>' + renderStoreAction(store) + '</td>' +
          '</tr>';
      }).join('') +
      '</tbody></table>';
    renderStorePagination(data);
  }

  function renderStoreDisplayName(store) {
    if (isStoreEditing(store)) {
      return '<input type="text" class="edit-input" data-pa-store-input="display_name" value="' +
        escapeHtml(store.display_name || '') +
        '" required autocomplete="off" />';
    }
    return escapeHtml(store.display_name || '');
  }

  function renderStoreHomepage(store) {
    const homepage = store.homepage_url || '';
    if (isStoreEditing(store)) {
      return '<input type="url" class="edit-input" data-pa-store-input="homepage_url" value="' +
        escapeHtml(homepage) +
        '" required autocomplete="off" />';
    }
    if (!homepage) {
      return '<span class="description">—</span>';
    }
    return '<a href="' + escapeHtml(homepage) + '" target="_blank" rel="noopener noreferrer">' +
      escapeHtml(homepage) +
      '</a>';
  }

  function renderStoreLogoCell(store) {
    const logoUrl = store.logo_url || '';
    if (!isStoreEditing(store)) {
      return logoUrl ? renderLogoImage(logoUrl, store.display_name || 'Логотип магазина') : '<span class="description">—</span>';
    }
    return '<div class="cashback-pa-inline-logo-field">' +
      '<input type="hidden" class="edit-input" data-pa-store-input="logo_url" value="' + escapeHtml(logoUrl) + '" />' +
      '<div class="cashback-pa-logo-preview" data-pa-inline-logo-preview>' +
      (logoUrl ? renderLogoImage(logoUrl, store.display_name || 'Логотип магазина') : '') +
      '</div>' +
      '<div class="cashback-pa-logo-actions">' +
      '<button type="button" class="button" data-pa-inline-logo-upload>Выбрать</button>' +
      '<button type="button" class="button' + (logoUrl ? '' : ' hidden') + '" data-pa-inline-logo-remove>Удалить</button>' +
      '</div>' +
      '</div>';
  }

  function renderLogoImage(url, alt) {
    return '<img class="cashback-pa-store-logo" src="' +
      escapeHtml(url) +
      '" alt="' +
      escapeHtml(alt || 'Логотип магазина') +
      '" loading="lazy" decoding="async" />';
  }

  function isStoreEditing(store) {
    return String(state.editingStoreId || '') === String(store.store_id || '');
  }

  function renderStoreAction(store) {
    const storeId = store.store_id || '';
    if (isStoreEditing(store)) {
      return '<div class="cashback-pa-actions">' +
        '<button type="button" class="button button-primary save-btn" data-pa-save-store data-pa-store-id="' +
        escapeHtml(storeId) +
        '">Сохранить</button>' +
        '<button type="button" class="button button-default cancel-btn" data-pa-cancel-store data-pa-store-id="' +
        escapeHtml(storeId) +
        '">Отмена</button>' +
        '</div>';
    }
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

  function resetStoreForm() {
    const form = storeForm();
    if (form) {
      form.reset();
    }
    setLogoPreview('');
  }

  function beginStoreEdit(storeId) {
    state.editingStoreId = storeId || '';
    rerenderStores();
  }

  function cancelStoreEdit() {
    state.editingStoreId = '';
    rerenderStores();
  }

  function rerenderStores() {
    if (state.storesData) {
      renderStores(state.storesData);
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
        openLogoFrame(setLogoPreview);
        return;
      }

      const removeLogo = event.target.closest('[data-pa-logo-remove]');
      if (removeLogo) {
        event.preventDefault();
        setLogoPreview('');
        return;
      }

      const inlineLogoUpload = event.target.closest('[data-pa-inline-logo-upload]');
      if (inlineLogoUpload) {
        event.preventDefault();
        const row = event.target.closest('[data-pa-store-row]');
        openLogoFrame(function (url) {
          setInlineLogoPreview(row, url);
        });
        return;
      }

      const inlineLogoRemove = event.target.closest('[data-pa-inline-logo-remove]');
      if (inlineLogoRemove) {
        event.preventDefault();
        setInlineLogoPreview(event.target.closest('[data-pa-store-row]'), '');
        return;
      }

      const editStore = event.target.closest('[data-pa-edit-store]');
      if (editStore) {
        event.preventDefault();
        beginStoreEdit(editStore.getAttribute('data-pa-store-id') || '');
        return;
      }

      const cancelEdit = event.target.closest('[data-pa-cancel-store]');
      if (cancelEdit) {
        event.preventDefault();
        cancelStoreEdit();
        return;
      }

      const saveStore = event.target.closest('[data-pa-save-store]');
      if (saveStore) {
        event.preventDefault();
        saveStoreChanges(saveStore, event.target.closest('[data-pa-store-row]'));
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
        const payload = {
          display_name: form.get('display_name'),
          homepage_url: form.get('homepage_url') || null,
          logo_url: form.get('logo_url') || null,
          enabled: true,
        };
        request('/stores', {
          method: 'POST',
          body: JSON.stringify(payload),
        }).then(function () {
          showNotice(labels.saved || 'Сохранено.', false);
          state.storePage = 1;
          resetStoreForm();
          loadSection('stores');
        }).catch(function () {
          showNotice(labels.saveError || 'Не удалось сохранить.', true);
        });
      });
    }
  }

  function saveStoreChanges(button, row) {
    const storeId = button.getAttribute('data-pa-store-id') || '';
    if (!storeId || !row) {
      return;
    }
    request('/stores/' + encodeURIComponent(storeId), {
      method: 'PATCH',
      body: JSON.stringify({
        display_name: rowInputValue(row, 'display_name'),
        homepage_url: rowInputValue(row, 'homepage_url') || null,
        logo_url: rowInputValue(row, 'logo_url') || null,
      }),
    }).then(function () {
      showNotice(labels.saved || 'Сохранено.', false);
      state.editingStoreId = '';
      loadSection('stores');
    }).catch(function () {
      showNotice(labels.saveError || 'Не удалось сохранить.', true);
    });
  }

  function rowInputValue(row, name) {
    const input = row.querySelector('[data-pa-store-input="' + name + '"]');
    return input ? input.value : '';
  }

  function openLogoFrame(onSelect) {
    if (!window.wp || !window.wp.media) {
      showNotice(labels.saveError || 'Не удалось сохранить.', true);
      return;
    }
    state.logoSelectCallback = onSelect || setLogoPreview;
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
        const callback = state.logoSelectCallback || setLogoPreview;
        callback(data.url || '');
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
        image.loading = 'lazy';
        image.decoding = 'async';
        preview.appendChild(image);
      }
    }
    if (remove) {
      remove.classList.toggle('hidden', !url);
    }
  }

  function setInlineLogoPreview(row, url) {
    if (!row) {
      return;
    }
    const input = row.querySelector('[data-pa-store-input="logo_url"]');
    const preview = row.querySelector('[data-pa-inline-logo-preview]');
    const remove = row.querySelector('[data-pa-inline-logo-remove]');
    if (input) {
      input.value = url || '';
    }
    if (preview) {
      preview.innerHTML = url ? renderLogoImage(url, 'Логотип магазина') : '';
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
