(function () {
  "use strict";
  /* eslint-disable no-alert */

  const config = window.CashbackPriceAssistantAccount || {};
  const root = document.querySelector("[data-price-assistant-account]");
  if (!root || !config.restBase || !config.nonce) {
    return;
  }

  const marketplaceConfig = config.marketplaces || {};
  const ITEMS_PER_PAGE = 10;

  function initialMarketplace() {
    const configured = normalizeSource(config.initialMarketplace || "");
    if (configured && marketplaceConfig[configured]) {
      return configured;
    }
    const active = root.querySelector("[data-price-assistant-tab].is-active");
    const fromDom = active ? normalizeSource(active.getAttribute("data-price-assistant-tab")) : "";
    if (fromDom && marketplaceConfig[fromDom]) {
      return fromDom;
    }
    return marketplaceConfig.ozon ? "ozon" : normalizeSource(Object.keys(marketplaceConfig)[0] || "");
  }

  const state = {
    connections: {},
    regionCode: "default",
    activeView: "link",
    activeTab: initialMarketplace(),
    activeCollectionType: "cart",
    collectionPages: {
      cart: 1,
      favorites: 1,
    },
    watchlistItems: [],
    collections: [],
    searchData: null,
  };

  const nodes = {
    viewTabs: root.querySelector("[data-price-assistant-view-tabs]"),
    viewPanels: root.querySelectorAll("[data-price-assistant-panel]"),
    searchForm: root.querySelector("[data-price-assistant-search-form]"),
    searchResults: root.querySelector("[data-price-assistant-search-results]"),
    settings: root.querySelector("[data-price-assistant-settings]"),
    settingsToggle: root.querySelector("[data-price-assistant-settings-toggle]"),
    tabs: root.querySelector("[data-price-assistant-marketplace-tabs]"),
    addForm: root.querySelector("[data-price-assistant-add-form]"),
    regionForm: root.querySelector("[data-price-assistant-region-form]"),
    message: root.querySelector("[data-price-assistant-message]"),
    manualList: root.querySelector("[data-price-assistant-manual-list]"),
    cartList: root.querySelector('[data-price-assistant-collection-list="cart"]'),
    favoritesList: root.querySelector('[data-price-assistant-collection-list="favorites"]'),
    cartPanel: root.querySelector('[data-price-assistant-collection-panel="cart"]'),
    favoritesPanel: root.querySelector('[data-price-assistant-collection-panel="favorites"]'),
    cartPagination: root.querySelector('[data-price-assistant-pagination="cart"]'),
    favoritesPagination: root.querySelector('[data-price-assistant-pagination="favorites"]'),
    chart: root.querySelector("[data-price-assistant-chart]"),
    compare: root.querySelector("[data-price-assistant-compare]"),
  };

  function requestJson(path, options) {
    const requestOptions = options || {};
    return fetch(config.restBase + path, {
      method: requestOptions.method || "GET",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": config.nonce,
      },
      body: requestOptions.body ? JSON.stringify(requestOptions.body) : undefined,
    }).then(function (response) {
      return response.text().then(function (text) {
        const data = text ? JSON.parse(text) : {};
        if (!response.ok) {
          const code = data && data.code ? data.code : "request_failed";
          throw new Error(code);
        }
        return data;
      });
    });
  }

  function clearNode(node) {
    if (node) {
      node.textContent = "";
    }
  }

  function appendText(parent, tag, text, className) {
    const node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    node.textContent = text || "";
    parent.appendChild(node);
    return node;
  }

  function setMessage(text, isError) {
    if (!nodes.message) {
      return;
    }
    nodes.message.textContent = text || "";
    nodes.message.classList.toggle("cashback-price-assistant__error", Boolean(isError));
  }

  function setEmpty(node, text) {
    clearNode(node);
    appendText(node, "p", text, "cashback-price-assistant__empty");
  }

  function marketplaceFor(button) {
    const code = button.getAttribute("data-marketplace");
    return code && marketplaceConfig[code] ? marketplaceConfig[code] : null;
  }

  function pageUrl(marketplace, page) {
    return marketplace.page_urls && marketplace.page_urls[page]
      ? marketplace.page_urls[page]
      : "";
  }

  function statusLabel(status) {
    const statuses = config.statuses || {};
    return statuses[status] || status || "Не авторизован";
  }

  function isAuthorizedStatus(status) {
    const normalized = String(status || "").toLowerCase().replace(/[_\s]+/g, " ");
    return normalized === "connected" || normalized === "sync ok" || normalized === "sync_ok";
  }

  function isMarketplaceAuthorized(code) {
    const connection = state.connections[code];
    return Boolean(connection && connectionId(connection) && isAuthorizedStatus(connection.status));
  }

  function updateMarketplaceActions(code) {
    const card = root.querySelector('[data-marketplace-card="' + code + '"]');
    if (!card) {
      return;
    }
    const active = normalizeSource(code) === normalizeSource(state.activeTab);
    const authorized = isMarketplaceAuthorized(code);
    card.hidden = !active;

    card.querySelectorAll("[data-marketplace]").forEach(function (button) {
      const page = button.getAttribute("data-marketplace-page") || "";
      const isConnect = button.classList.contains("cashback-price-assistant__connect");
      const isDisconnect = button.hasAttribute("data-price-assistant-disconnect");
      const isCollection = page === "cart" || page === "favorites";
      button.hidden =
        !active ||
        (authorized && isConnect) ||
        (!authorized && (isCollection || isDisconnect));
      if (isCollection) {
        button.classList.toggle("active", page === state.activeCollectionType);
        button.classList.toggle("is-active", page === state.activeCollectionType);
      }
    });
  }

  function setState(code, status) {
    if (state.connections[code]) {
      state.connections[code].status = status;
    }
    const node = root.querySelector('[data-marketplace-state="' + code + '"]');
    if (node) {
      node.textContent = statusLabel(status);
      node.className =
        "cashback-price-assistant__state cashback-price-assistant__status-value--" +
        String(status || "disconnected").replace(/[_\s]+/g, "-");
    }
    const disconnect = root.querySelector(
      '[data-price-assistant-disconnect][data-marketplace="' + code + '"]'
    );
    if (disconnect) {
      const connection = state.connections[code];
      disconnect.disabled = !connection || !connectionId(connection);
    }
    updateMarketplaceActions(code);
  }

  function openMarketplacePage(marketplace, page) {
    const url = pageUrl(marketplace, page);
    if (url) {
      window.open(url, "_blank", "noopener,noreferrer");
    }
  }

  function consentAccepted(marketplace) {
    const label = marketplace.label || marketplace.code;
    return window.confirm(
      "Разрешить Price Assistant получить только утвержденные технические cookies/tokens для " +
        label +
        " после входа на настоящей странице маркетплейса?"
    );
  }

  function createConnection(code) {
    return requestJson("/connections", {
      method: "POST",
      body: {
        marketplace: code,
        consent_version: config.consentVersion,
        scope: config.scope || ["cart_read", "favorites_read"],
        captured_at: new Date().toISOString(),
        connector_version: "wordpress-account-0.1.0",
      },
    });
  }

  function connectionId(response) {
    return response.connection_id || response.id || response.marketplace_connection_id || null;
  }

  function requestConnectorCapture(marketplace, id) {
    window.postMessage(
      {
        type: "cashback-price-assistant:captureSession",
        payload: {
          action: config.connectorAction,
          restBase: config.restBase,
          nonce: config.nonce,
          connectionId: id,
          marketplace: marketplace.code,
          consent: true,
          consentVersion: config.consentVersion,
          scope: config.scope || ["cart_read", "favorites_read"],
          allowlist: marketplace.allowlist || { cookies: [], tokens: [] },
          hostPermissions: marketplace.host_permissions || [],
          pageUrls: marketplace.page_urls || {},
        },
      },
      window.location.origin
    );
  }

  function formValue(form, name) {
    const field = form ? form.querySelector('[name="' + name + '"]') : null;
    return field ? field.value.trim() : "";
  }

  function optionalAmount(value) {
    if (value === "") {
      return null;
    }
    const amount = Number(value);
    if (!Number.isFinite(amount) || amount < 0) {
      return null;
    }
    return amount.toFixed(2);
  }

  function money(value, currency) {
    if (value === null || value === undefined || value === "") {
      return "—";
    }
    return String(value) + (currency ? " " + currency : "");
  }

  function normalizeSource(value) {
    return String(value || "")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "_")
      .replace(/^_+|_+$/g, "");
  }

  function sourceMatchesActiveTab(source) {
    const active = normalizeSource(state.activeTab || "all");
    if (active === "all") {
      return true;
    }
    return (
      normalizeSource(source) === active ||
      (active === "wildberries" && normalizeSource(source) === "wb") ||
      (active === "yandex_market" && normalizeSource(source) === "yandex")
    );
  }

  function itemSource(item) {
    return item.source || item.source_code || item.marketplace || item.store_code || "";
  }

  function marketplaceLabel(code) {
    const normalized = normalizeSource(code);
    if (normalized === "all") {
      return "";
    }
    if (normalized === "yandex_market") {
      return "Яндекс Маркет";
    }
    if (marketplaceConfig[normalized] && marketplaceConfig[normalized].label) {
      return marketplaceConfig[normalized].label;
    }
    return normalized || "";
  }

  function scopedEmptyText(allText, scopedText) {
    const active = normalizeSource(state.activeTab || "all");
    if (active === "all") {
      return allText;
    }
    return scopedText.replace("%s", marketplaceLabel(active));
  }

  function renderImage(parent, url, title) {
    const media = document.createElement("div");
    media.className = "cashback-price-assistant__item-media";
    if (url) {
      const image = document.createElement("img");
      image.src = url;
      image.alt = title || "Товар";
      image.loading = "lazy";
      media.appendChild(image);
    }
    parent.appendChild(media);
  }

  function appendProductActions(parent, item) {
    const actions = document.createElement("div");
    actions.className = "cashback-price-assistant__item-actions";

    if (item.product_url || item.search_url) {
      const link = document.createElement("a");
      link.className = "button";
      link.href = item.product_url || item.search_url;
      link.target = "_blank";
      link.rel = "noopener noreferrer";
      link.textContent = item.is_fallback ? "Искать в магазине" : "Открыть";
      actions.appendChild(link);
    }

    if (item.product_url) {
      const cheaper = document.createElement("button");
      cheaper.type = "button";
      cheaper.className = "button";
      cheaper.dataset.priceAssistantAction = "compare";
      cheaper.dataset.trackedProductId = item.tracked_product_id || "";
      cheaper.disabled = !item.tracked_product_id;
      cheaper.textContent = "Найти дешевле";
      actions.appendChild(cheaper);
    }

    parent.appendChild(actions);
  }

  function createProductCard(item, sourceCode) {
    const card = document.createElement("article");
    card.className = "cashback-price-assistant__item cashback-price-assistant__product-card";
    card.dataset.priceAssistantSource = normalizeSource(sourceCode || item.source_code || item.source);
    if (item.subscription_id) {
      card.dataset.subscriptionId = item.subscription_id;
    }
    if (item.tracked_product_id) {
      card.dataset.trackedProductId = item.tracked_product_id;
    }

    renderImage(card, item.image_url, item.title);

    const body = document.createElement("div");
    body.className = "cashback-price-assistant__item-body";
    appendText(body, "p", money(item.price || item.current_price || item.last_price, item.currency), "cashback-price-assistant__price");
    appendText(body, "p", item.title || item.product_url || item.external_item_id || "Товар", "cashback-price-assistant__item-title");
    appendText(
      body,
      "p",
      [
        item.store_display_name || item.source_display_name || item.source || item.source_code,
        item.match_label,
        item.availability,
      ]
        .filter(Boolean)
        .join(" · "),
      "cashback-price-assistant__item-meta"
    );
    appendProductActions(body, item);
    card.appendChild(body);

    return card;
  }

  function loadConnections() {
    return requestJson("/connections")
      .then(function (data) {
        const connections = Array.isArray(data) ? data : data.connections || [];
        state.connections = {};
        connections.forEach(function (connection) {
          const code = connection.marketplace || connection.source;
          if (!code) {
            return;
          }
          state.connections[code] = connection;
          setState(code, connection.status || "disconnected");
        });
        Object.keys(marketplaceConfig).forEach(function (code) {
          if (!state.connections[code]) {
            setState(code, "disconnected");
          }
        });
      })
      .catch(function () {
        Object.keys(marketplaceConfig).forEach(function (code) {
          setState(code, "disconnected");
        });
      });
  }

  function loadWatchlist() {
    if (!nodes.manualList) {
      return Promise.resolve();
    }
    setEmpty(nodes.manualList, "Загрузка...");
    return requestJson("/watchlist/items?limit=50")
      .then(function (data) {
        const items = Array.isArray(data) ? data : data.items || [];
        state.watchlistItems = items;
        renderActiveWatchlist();
      })
      .catch(function () {
        state.watchlistItems = [];
        setEmpty(nodes.manualList, "Не удалось загрузить ручные товары.");
      });
  }

  function searchProducts(event) {
    event.preventDefault();
    const query = formValue(nodes.searchForm, "q");
    if (!query) {
      setMessage("Введите название товара для поиска.", true);
      return;
    }
    if (!nodes.searchResults) {
      return;
    }

    setEmpty(nodes.searchResults, "Ищу по магазинам...");
    const params = new URLSearchParams({
      q: query,
      region_code: state.regionCode || formValue(nodes.regionForm, "region_code") || "default",
      limit: "20",
    });

    requestJson("/search?" + params.toString())
      .then(function (data) {
        state.searchData = data;
        renderActiveSearchResults();
      })
      .catch(function (error) {
        state.searchData = null;
        setEmpty(nodes.searchResults, "Не удалось выполнить поиск.");
        setMessage(error.message || "Поиск недоступен.", true);
      });
  }

  function renderActiveSearchResults() {
    if (!state.searchData || !nodes.searchResults) {
      return;
    }
    renderSearchResults(state.searchData);
  }

  function renderSearchResults(data) {
    clearNode(nodes.searchResults);
    const items = Array.isArray(data.items) ? data.items : [];
    const fallbacks = Array.isArray(data.fallbacks) ? data.fallbacks : [];
    const all = items.concat(fallbacks);
    if (!all.length) {
      setEmpty(nodes.searchResults, "Подходящих магазинов пока нет.");
      return;
    }

    appendText(nodes.searchResults, "h3", "Результаты поиска", "cashback-price-assistant__section-title");
    const list = document.createElement("div");
    list.className = "cashback-price-assistant__search-grid";
    all.forEach(function (item) {
      list.appendChild(createProductCard(item, item.source_code));
    });
    nodes.searchResults.appendChild(list);
  }

  function renderActiveWatchlist() {
    if (!nodes.manualList) {
      return;
    }
    renderWatchlist(state.watchlistItems);
  }

  function renderWatchlist(items) {
    clearNode(nodes.manualList);
    if (!items.length) {
      setEmpty(
        nodes.manualList,
        scopedEmptyText(
          "Добавьте первый товар по ссылке.",
          "Для %s пока нет ручных товаров."
        )
      );
      return;
    }
    items.forEach(function (item) {
      const card = document.createElement("article");
      card.className = "cashback-price-assistant__item cashback-price-assistant__product-card";
      card.dataset.subscriptionId = item.subscription_id;
      card.dataset.trackedProductId = item.tracked_product_id;
      card.dataset.priceAssistantSource = normalizeSource(item.source || item.source_code);

      renderImage(card, item.image_url, item.title);
      const body = document.createElement("div");
      body.className = "cashback-price-assistant__item-body";

      appendText(body, "p", item.title || item.product_url || "Товар", "cashback-price-assistant__item-title");
      appendText(
        body,
        "p",
        [
          item.source_display_name || item.source,
          item.region_code,
          money(item.last_price || item.current_price, item.currency),
          item.availability,
        ]
          .filter(Boolean)
          .join(" · "),
        "cashback-price-assistant__item-meta"
      );

      const targets = document.createElement("div");
      targets.className = "cashback-price-assistant__item-targets";
      appendTargetInput(targets, "target_price", "Желаемая цена", item.target_price);
      body.appendChild(targets);

      const actions = document.createElement("div");
      actions.className = "cashback-price-assistant__item-actions";
      appendAction(actions, "save-targets", "Сохранить цели");
      appendAction(actions, "chart", "Обновить график");
      appendAction(actions, "compare", "Где дешевле");
      appendAction(actions, "cashback", "Перейти с кэшбэком");
      appendAction(actions, "remove-manual", "Удалить");
      body.appendChild(actions);

      const chart = document.createElement("div");
      chart.className = "cashback-price-assistant__inline-chart";
      chart.setAttribute("data-price-assistant-item-chart", "");
      body.appendChild(chart);

      card.appendChild(body);
      nodes.manualList.appendChild(card);
      loadInlineChart(item.tracked_product_id, chart);
    });
  }

  function appendTargetInput(parent, name, label, value) {
    const wrapper = document.createElement("label");
    appendText(wrapper, "span", label);
    const input = document.createElement("input");
    input.type = "number";
    input.name = name;
    input.min = "0";
    input.step = "0.01";
    input.value = value || "";
    wrapper.appendChild(input);
    parent.appendChild(wrapper);
  }

  function appendAction(parent, action, label) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "button";
    button.dataset.priceAssistantAction = action;
    button.textContent = label;
    parent.appendChild(button);
  }

  function loadCollections() {
    [nodes.cartList, nodes.favoritesList].forEach(function (node) {
      if (node) {
        setEmpty(node, "Загрузка...");
      }
      });
    return requestJson("/collections")
      .then(function (data) {
        const collections = Array.isArray(data) ? data : data.items || [];
        state.collections = collections;
        renderActiveCollections();
      })
      .catch(function () {
        state.collections = [];
        setEmpty(nodes.cartList, "Не удалось загрузить корзину.");
        setEmpty(nodes.favoritesList, "Не удалось загрузить избранное.");
      });
  }

  function renderActiveCollections() {
    if (!nodes.cartList || !nodes.favoritesList) {
      return;
    }
    renderCollectionType(
      "cart",
      nodes.cartList,
      nodes.cartPagination,
      scopedEmptyText("Корзина пока не импортирована.", "Корзина %s пока не импортирована.")
    );
    renderCollectionType(
      "favorites",
      nodes.favoritesList,
      nodes.favoritesPagination,
      scopedEmptyText("Избранное пока не импортировано.", "Избранное %s пока не импортировано.")
    );
    updateCollectionPanels();
  }

  function collectionRows(type) {
    const rows = [];
    state.collections.forEach(function (collection) {
      if (
        collection.collection_type !== type ||
        !sourceMatchesActiveTab(collection.source || collection.marketplace)
      ) {
        return;
      }
      const items = Array.isArray(collection.items) ? collection.items : [];
      items.forEach(function (item, index) {
        rows.push({
          collection: collection,
          item: item,
          index: index,
        });
      });
    });
    return rows;
  }

  function renderCollectionType(type, node, paginationNode, emptyText) {
    clearNode(node);
    clearNode(paginationNode);
    const rows = collectionRows(type);
    if (!rows.length) {
      setEmpty(node, emptyText);
      return;
    }

    const totalPages = Math.ceil(rows.length / ITEMS_PER_PAGE);
    const currentPage = Math.min(state.collectionPages[type] || 1, totalPages);
    state.collectionPages[type] = currentPage;
    const offset = (currentPage - 1) * ITEMS_PER_PAGE;
    rows.slice(offset, offset + ITEMS_PER_PAGE).forEach(function (row) {
      const collection = row.collection;
      const item = row.item;
      const card = createProductCard(
        Object.assign({}, item, {
          source: collection.source,
          source_display_name: collection.source_display_name,
          region_code: collection.region_code,
          price: item.price || item.current_price || item.last_price,
        }),
        collection.source
      );
      appendText(
        card.querySelector(".cashback-price-assistant__item-body") || card,
        "p",
        (collection.collection_type || "import") +
          " · " +
          (collection.region_code || "default") +
          (item.quantity ? " · " + item.quantity + " шт." : ""),
        "cashback-price-assistant__item-meta"
      );
      if (row.index === 0) {
        const actions = card.querySelector(".cashback-price-assistant__item-actions");
        const button = document.createElement("button");
        button.type = "button";
        button.className = "button";
        button.dataset.priceAssistantAction = "delete-import";
        button.dataset.collectionId = collection.collection_id;
        button.setAttribute("data-price-assistant-delete-import", "");
        button.textContent = "Удалить историю импорта";
        if (actions) {
          actions.appendChild(button);
        }
      }
      node.appendChild(card);
    });

    if (paginationNode && window.CashbackPagination && totalPages > 1) {
      paginationNode.innerHTML = window.CashbackPagination.build(currentPage, totalPages, {
        containerClass: "woocommerce-pagination cashback-price-assistant__pagination-nav",
      });
    }
  }

  function updateCollectionPanels() {
    const activeType = state.activeCollectionType;
    if (nodes.cartPanel) {
      nodes.cartPanel.hidden = activeType !== "cart";
    }
    if (nodes.favoritesPanel) {
      nodes.favoritesPanel.hidden = activeType !== "favorites";
    }
  }

  function refreshCabinet() {
    return Promise.all([loadConnections(), loadWatchlist(), loadCollections()]);
  }

  function addWatchlistItem(event) {
    event.preventDefault();
    const productUrl = formValue(nodes.addForm, "product_url");
    if (!productUrl) {
      setMessage("Укажите ссылку на товар.", true);
      return;
    }
    const body = {
      product_url: productUrl,
      region_code: state.regionCode || formValue(nodes.regionForm, "region_code") || "default",
    };
    const targetPrice = optionalAmount(formValue(nodes.addForm, "target_price"));
    if (targetPrice !== null) {
      body.target_price = targetPrice;
    }
    requestJson("/watchlist/items", { method: "POST", body: body })
      .then(function () {
        nodes.addForm.reset();
        setMessage("Товар добавлен.");
        return loadWatchlist();
      })
      .catch(function (error) {
        setMessage(error.message || "Не удалось добавить товар.", true);
      });
  }

  function updateRegion(event) {
    event.preventDefault();
    const regionCode = formValue(nodes.regionForm, "region_code");
    if (!regionCode) {
      setMessage("Укажите регион.", true);
      return;
    }
    const countryCode = formValue(nodes.regionForm, "country_code");
    const body = { region_code: regionCode };
    if (countryCode) {
      body.country_code = countryCode.toUpperCase();
    }
    requestJson("/user-region", { method: "PATCH", body: body })
      .then(function (data) {
        state.regionCode = data.region_code || regionCode;
        setMessage("Регион сохранён.");
      })
      .catch(function (error) {
        setMessage(error.message || "Не удалось сохранить регион.", true);
      });
  }

  function itemCard(button) {
    return button.closest(".cashback-price-assistant__item");
  }

  function itemIds(card) {
    return {
      subscriptionId: card ? card.dataset.subscriptionId : "",
      trackedProductId: card ? card.dataset.trackedProductId : "",
    };
  }

  function handleItemAction(button) {
    const action = button.dataset.priceAssistantAction;
    const card = itemCard(button);
    const ids = itemIds(card);
    if (action === "save-targets") {
      saveTargets(card, ids.subscriptionId);
    } else if (action === "remove-manual") {
      deleteWatchlistItem(ids.subscriptionId);
    } else if (action === "cashback") {
      openCashbackLink(ids.subscriptionId);
    } else if (action === "chart") {
      loadChart(
        ids.trackedProductId,
        card ? card.querySelector("[data-price-assistant-item-chart]") : null
      );
    } else if (action === "compare") {
      loadCompare(ids.trackedProductId);
    } else if (action === "delete-import") {
      deleteImportHistory(button.dataset.collectionId);
    }
  }

  function saveTargets(card, subscriptionId) {
    if (!subscriptionId || !card) {
      return;
    }
    const targetPrice = optionalAmount(formValue(card, "target_price"));
    const body = {};
    if (targetPrice !== null) {
      body.target_price = targetPrice;
    }
    requestJson("/watchlist/items/" + encodeURIComponent(subscriptionId), {
      method: "PATCH",
      body: body,
    })
      .then(function () {
        setMessage("Цели сохранены.");
        return loadWatchlist();
      })
      .catch(function (error) {
        setMessage(error.message || "Не удалось сохранить цели.", true);
      });
  }

  function deleteWatchlistItem(subscriptionId) {
    if (!subscriptionId || !window.confirm("Удалить товар из Price Assistant?")) {
      return;
    }
    requestJson("/watchlist/items/" + encodeURIComponent(subscriptionId), { method: "DELETE" })
      .then(function () {
        setMessage("Товар удалён.");
        return loadWatchlist();
      })
      .catch(function (error) {
        setMessage(error.message || "Не удалось удалить товар.", true);
      });
  }

  function openCashbackLink(subscriptionId) {
    if (!subscriptionId) {
      return;
    }
    requestJson("/watchlist/items/" + encodeURIComponent(subscriptionId) + "/cashback-link", {
      method: "POST",
      body: {},
    })
      .then(function (data) {
        if (data.cashback_url) {
          window.open(data.cashback_url, "_blank", "noopener,noreferrer");
        }
      })
      .catch(function (error) {
        setMessage(error.message || "Кэшбэк-переход недоступен.", true);
      });
  }

  function deleteImportHistory(collectionId) {
    if (!collectionId || !window.confirm("Удалить историю этого импорта?")) {
      return;
    }
    requestJson("/collections/" + encodeURIComponent(collectionId), { method: "DELETE" })
      .then(function () {
        setMessage("История импорта удалена.");
        return loadCollections();
      })
      .catch(function (error) {
        setMessage(error.message || "Не удалось удалить историю импорта.", true);
      });
  }

  function loadInlineChart(trackedProductId, targetNode) {
    if (!trackedProductId || !targetNode) {
      return;
    }
    loadChart(trackedProductId, targetNode);
  }

  function loadChart(trackedProductId, targetNode) {
    const chartNode = targetNode || nodes.chart;
    if (!trackedProductId || !chartNode) {
      return;
    }
    setEmpty(chartNode, "Загрузка графика...");
    requestJson("/products/" + encodeURIComponent(trackedProductId) + "/chart?days=30&granularity=daily")
      .then(function (data) {
        renderChart(data, chartNode);
      })
      .catch(function () {
        setEmpty(chartNode, "Не удалось загрузить график.");
      });
  }

  function renderChart(data, targetNode) {
    const chartNode = targetNode || nodes.chart;
    if (!chartNode) {
      return;
    }
    clearNode(chartNode);
    appendText(chartNode, "p", data.labels && data.labels.headline, "cashback-price-assistant__item-meta");
    const series = Array.isArray(data.series) ? data.series : [];
    if (series.length === 0 || (data.summary && data.summary.trend === "no_data")) {
      appendText(chartNode, "p", "Нет данных для графика.", "cashback-price-assistant__empty");
      return;
    }
    const values = series.map(function (point) {
      return Number(point.price);
    });
    const min = Math.min.apply(null, values);
    const max = Math.max.apply(null, values);
    const width = 720;
    const height = 220;
    const pad = 24;
    const span = max - min || 1;
    const points = values.map(function (value, index) {
      const x = pad + (index * (width - pad * 2)) / Math.max(series.length - 1, 1);
      const y = height - pad - ((value - min) * (height - pad * 2)) / span;
      return x.toFixed(1) + "," + y.toFixed(1);
    });

    const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    svg.setAttribute("viewBox", "0 0 " + width + " " + height);
    svg.setAttribute("role", "img");
    svg.setAttribute("aria-label", data.title || "График цены");
    const polyline = document.createElementNS("http://www.w3.org/2000/svg", "polyline");
    polyline.setAttribute("class", "cashback-price-assistant__chart-line");
    polyline.setAttribute("points", points.join(" "));
    svg.appendChild(polyline);
    const avg = data.y_axis && data.y_axis.avg ? Number(data.y_axis.avg) : null;
    if (avg !== null && Number.isFinite(avg)) {
      const avgY = height - pad - ((avg - min) * (height - pad * 2)) / span;
      const line = document.createElementNS("http://www.w3.org/2000/svg", "line");
      line.setAttribute("class", "cashback-price-assistant__chart-average");
      line.setAttribute("x1", String(pad));
      line.setAttribute("x2", String(width - pad));
      line.setAttribute("y1", avgY.toFixed(1));
      line.setAttribute("y2", avgY.toFixed(1));
      svg.appendChild(line);
    }
    chartNode.appendChild(svg);
    appendText(
      chartNode,
      "p",
      "Сейчас " +
        money(data.summary && data.summary.current_price, data.currency) +
        " · минимум " +
        money(data.summary && data.summary.min_price, data.currency) +
        " · максимум " +
        money(data.summary && data.summary.max_price, data.currency),
      "cashback-price-assistant__chart-summary"
    );
  }

  function loadCompare(trackedProductId) {
    if (!trackedProductId || !nodes.compare) {
      return;
    }
    setEmpty(nodes.compare, "Ищу предложения...");
    requestJson("/products/" + encodeURIComponent(trackedProductId) + "/compare")
      .then(renderCompare)
      .catch(function () {
        setEmpty(nodes.compare, "Не удалось загрузить сравнение.");
      });
  }

  function renderCompare(data) {
    clearNode(nodes.compare);
    const offers = Array.isArray(data.offers) ? data.offers : [];
    if (!offers.length) {
      setEmpty(nodes.compare, "Пока нет сопоставимых предложений.");
      return;
    }
    offers.forEach(function (offer) {
      const row = document.createElement("div");
      row.className = "cashback-price-assistant__compare-row";
      appendText(
        row,
        "span",
        (offer.store_display_name || offer.store_code) + " · " + offer.match_label,
        "cashback-price-assistant__item-meta"
      );
      appendText(
        row,
        "span",
        money(offer.effective_price || offer.price, offer.currency),
        "cashback-price-assistant__price"
      );
      const link = document.createElement("a");
      link.className = "button";
      link.href = offer.product_url;
      link.target = "_blank";
      link.rel = "noopener noreferrer";
      link.textContent = "Открыть";
      row.appendChild(link);
      nodes.compare.appendChild(row);
    });
  }

  function disconnectMarketplace(button) {
    const marketplace = marketplaceFor(button);
    if (!marketplace) {
      return;
    }
    const connection = state.connections[marketplace.code];
    const id = connection ? connectionId(connection) : null;
    if (!id) {
      return;
    }
    requestJson("/connections/" + encodeURIComponent(id), { method: "DELETE" })
      .then(function () {
        delete state.connections[marketplace.code];
        setState(marketplace.code, "disconnected");
        setMessage("Маркетплейс отключён.");
        return loadCollections();
      })
      .catch(function (error) {
        setMessage(error.message || "Не удалось отключить маркетплейс.", true);
      });
  }

  function applyActiveTab() {
    const active = state.activeTab || initialMarketplace();
    root.querySelectorAll("[data-price-assistant-tab]").forEach(function (button) {
      const isActive = button.getAttribute("data-price-assistant-tab") === active;
      button.classList.toggle("active", isActive);
      button.classList.toggle("is-active", isActive);
    });
    Object.keys(marketplaceConfig).forEach(updateMarketplaceActions);
    renderActiveCollections();
  }

  function applyActiveView() {
    const active = state.activeView || "link";
    root.querySelectorAll("[data-price-assistant-view]").forEach(function (button) {
      const isActive = button.getAttribute("data-price-assistant-view") === active;
      button.classList.toggle("active", isActive);
      button.classList.toggle("is-active", isActive);
    });
    nodes.viewPanels.forEach(function (panel) {
      panel.hidden = panel.getAttribute("data-price-assistant-panel") !== active;
    });
    if (active === "link") {
      renderActiveWatchlist();
    } else if (active === "cart") {
      applyActiveTab();
    } else if (active === "compare") {
      renderActiveSearchResults();
    }
  }

  function toggleSettings() {
    if (!nodes.settings || !nodes.settingsToggle) {
      return;
    }
    const open = nodes.settings.hidden;
    nodes.settings.hidden = !open;
    nodes.settingsToggle.setAttribute("aria-expanded", open ? "true" : "false");
  }

  function loadTrackingSettings() {
    if (!nodes.settings) {
      return;
    }
    let saved = {};
    try {
      saved = JSON.parse(window.localStorage.getItem("cashbackPriceAssistantTracking") || "{}");
    } catch (error) {
      saved = {};
    }
    nodes.settings.querySelectorAll('input[type="checkbox"]').forEach(function (input) {
      if (Object.prototype.hasOwnProperty.call(saved, input.name)) {
        input.checked = Boolean(saved[input.name]);
      }
    });
  }

  function saveTrackingSettings(changedInput) {
    if (!nodes.settings) {
      return;
    }
    if (changedInput && changedInput.name === "track_all") {
      nodes.settings.querySelectorAll('input[type="checkbox"]:not([name="track_all"])').forEach(function (input) {
        input.checked = changedInput.checked;
      });
    }
    const next = {};
    nodes.settings.querySelectorAll('input[type="checkbox"]').forEach(function (input) {
      next[input.name] = input.checked;
    });
    window.localStorage.setItem("cashbackPriceAssistantTracking", JSON.stringify(next));
  }

  root.addEventListener("click", function (event) {
    const settingsButton = event.target.closest("[data-price-assistant-settings-toggle]");
    if (settingsButton) {
      toggleSettings();
      return;
    }

    const viewButton = event.target.closest("[data-price-assistant-view]");
    if (viewButton) {
      state.activeView = viewButton.getAttribute("data-price-assistant-view") || "link";
      applyActiveView();
      return;
    }

    const pageLink = event.target.closest("[data-price-assistant-pagination] .page-numbers[data-page]");
    if (pageLink) {
      event.preventDefault();
      const pagination = pageLink.closest("[data-price-assistant-pagination]");
      const type = pagination ? pagination.getAttribute("data-price-assistant-pagination") : "";
      const page = parseInt(pageLink.getAttribute("data-page") || "1", 10);
      if ((type === "cart" || type === "favorites") && Number.isFinite(page)) {
        state.collectionPages[type] = Math.max(1, page);
        renderActiveCollections();
      }
      return;
    }

    const tabButton = event.target.closest("[data-price-assistant-tab]");
    if (tabButton) {
      state.activeTab = tabButton.getAttribute("data-price-assistant-tab") || initialMarketplace();
      state.activeCollectionType = "cart";
      state.collectionPages.cart = 1;
      state.collectionPages.favorites = 1;
      applyActiveTab();
      return;
    }

    const actionButton = event.target.closest("[data-price-assistant-action]");
    if (actionButton) {
      handleItemAction(actionButton);
      return;
    }

    const disconnectButton = event.target.closest("[data-price-assistant-disconnect]");
    if (disconnectButton) {
      disconnectMarketplace(disconnectButton);
      return;
    }

    const button = event.target.closest("[data-marketplace]");
    if (!button) {
      return;
    }

    const marketplace = marketplaceFor(button);
    if (!marketplace || button.disabled) {
      return;
    }

    const page = button.getAttribute("data-marketplace-page") || "login";
    if (!button.classList.contains("cashback-price-assistant__connect")) {
      if (page === "cart" || page === "favorites") {
        state.activeCollectionType = page;
        state.collectionPages[page] = 1;
        updateMarketplaceActions(marketplace.code);
        renderActiveCollections();
      }
      return;
    }

    if (!consentAccepted(marketplace)) {
      setState(marketplace.code, "disconnected");
      return;
    }

    button.disabled = true;
    setState(marketplace.code, "connecting");
    createConnection(marketplace.code)
      .then(function (response) {
        const id = connectionId(response);
        state.connections[marketplace.code] = response;
        openMarketplacePage(marketplace, page);
        if (id) {
          requestConnectorCapture(marketplace, id);
        }
      })
      .catch(function (error) {
        setState(
          marketplace.code,
          error && error.message === "marketplace_disabled"
            ? "disconnected"
            : "reconnect_required"
        );
      })
      .finally(function () {
        button.disabled = false;
      });
  });

  if (nodes.addForm) {
    nodes.addForm.addEventListener("submit", addWatchlistItem);
  }
  if (nodes.searchForm) {
    nodes.searchForm.addEventListener("submit", searchProducts);
  }
  if (nodes.regionForm) {
    nodes.regionForm.addEventListener("submit", updateRegion);
  }
  if (nodes.settings) {
    loadTrackingSettings();
    nodes.settings.addEventListener("change", function (event) {
      if (event.target && event.target.matches('input[type="checkbox"]')) {
        saveTrackingSettings(event.target);
      }
    });
  }

  window.addEventListener("message", function (event) {
    if (event.origin !== window.location.origin) {
      return;
    }
    const message = event.data || {};
    if (message.type !== "cashback-price-assistant:sanitizedItems" || !message.payload) {
      return;
    }

    const payload = message.payload;
    if (!payload.consent || !payload.connectionId || !payload.marketplace) {
      return;
    }

    requestJson("/connections/" + encodeURIComponent(payload.connectionId) + "/immediate-import", {
      method: "POST",
      body: {
        marketplace: payload.marketplace,
        consent: true,
        collection_type: payload.collectionType || "cart",
        captured_at: new Date().toISOString(),
        items: Array.isArray(payload.items) ? payload.items : [],
      },
    })
      .then(function () {
        setState(payload.marketplace, "sync ok");
        return loadCollections();
      })
      .catch(function () {
        setState(payload.marketplace, "reconnect_required");
      });
  });

  applyActiveView();
  applyActiveTab();
  refreshCabinet();
})();
