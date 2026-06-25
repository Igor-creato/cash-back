/* eslint-disable security/detect-non-literal-fs-filename, security/detect-object-injection, security/detect-unsafe-regex */
import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";

const scriptSource = fs.readFileSync(
  new URL("../assets/js/price-assistant-account.js", import.meta.url),
  "utf8"
);

class FakeClassList {
  constructor(element) {
    this.element = element;
  }

  contains(className) {
    return this.element.className.split(/\s+/).includes(className);
  }

  toggle(className, force) {
    const classes = new Set(this.element.className.split(/\s+/).filter(Boolean));
    if (force) {
      classes.add(className);
    } else {
      classes.delete(className);
    }
    this.element.className = Array.from(classes).join(" ");
  }
}

class FakeElement {
  constructor(tagName, attributes = {}) {
    this.tagName = tagName.toUpperCase();
    this.attributes = {};
    this.children = [];
    this.eventListeners = {};
    this.parentNode = null;
    this.className = "";
    this.hidden = false;
    this.disabled = false;
    this.value = "";
    this.dataset = {};
    this._textContent = "";
    this.classList = new FakeClassList(this);

    Object.entries(attributes).forEach(([name, value]) => {
      this.setAttribute(name, value);
    });
  }

  setAttribute(name, value) {
    this.attributes[name] = String(value);
    if (name === "class") {
      this.className = String(value);
    }
    if (name === "value") {
      this.value = String(value);
    }
  }

  get textContent() {
    return this._textContent;
  }

  set textContent(value) {
    this._textContent = String(value);
    this.children = [];
  }

  getAttribute(name) {
    if (name === "class") {
      return this.className;
    }
    return Object.prototype.hasOwnProperty.call(this.attributes, name)
      ? this.attributes[name]
      : null;
  }

  hasAttribute(name) {
    return Object.prototype.hasOwnProperty.call(this.attributes, name);
  }

  appendChild(child) {
    child.parentNode = this;
    this.children.push(child);
    return child;
  }

  addEventListener(type, listener) {
    this.eventListeners[type] = this.eventListeners[type] || [];
    this.eventListeners[type].push(listener);
  }

  dispatchEvent(event) {
    event.target = event.target || this;
    (this.eventListeners[event.type] || []).forEach((listener) => listener(event));
  }

  reset() {
    this.querySelectorAll("[name]").forEach((field) => {
      field.value = "";
    });
  }

  querySelector(selector) {
    return this.querySelectorAll(selector)[0] || null;
  }

  querySelectorAll(selector) {
    const matches = [];
    const visit = (node) => {
      if (node.matches(selector)) {
        matches.push(node);
      }
      node.children.forEach(visit);
    };
    this.children.forEach(visit);
    return matches;
  }

  matches(selector) {
    const parts = selector.trim().match(/(?:\[[^\]]+\]|\.[A-Za-z0-9_-]+)/g) || [];
    if (!parts.length) {
      return false;
    }
    return parts.every((part) => {
      if (part.startsWith(".")) {
        return this.classList.contains(part.slice(1));
      }
      const match = part.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/);
      if (!match) {
        return false;
      }
      const [, name, expected] = match;
      if (!this.hasAttribute(name)) {
        return false;
      }
      return expected === undefined || this.getAttribute(name) === expected;
    });
  }
}

function textOf(node) {
  return node.textContent + node.children.map(textOf).join("");
}

function findFirst(node, predicate) {
  if (predicate(node)) {
    return node;
  }
  for (const child of node.children) {
    const found = findFirst(child, predicate);
    if (found) {
      return found;
    }
  }
  return null;
}

function findAll(node, predicate) {
  const matches = [];
  const visit = (current) => {
    if (predicate(current)) {
      matches.push(current);
    }
    current.children.forEach(visit);
  };
  visit(node);
  return matches;
}

function countText(text, needle) {
  return text.split(needle).length - 1;
}

function createRoot() {
  const root = new FakeElement("section", { "data-price-assistant-account": "" });
  const message = root.appendChild(new FakeElement("div", { "data-price-assistant-message": "" }));
  const viewTabs = root.appendChild(new FakeElement("nav", { "data-price-assistant-view-tabs": "" }));
  viewTabs.appendChild(
    new FakeElement("button", {
      "data-price-assistant-view": "link",
      class: "is-active active",
    })
  );
  viewTabs.appendChild(new FakeElement("button", { "data-price-assistant-view": "cart" }));
  viewTabs.appendChild(new FakeElement("button", { "data-price-assistant-view": "compare" }));

  const linkPanel = root.appendChild(new FakeElement("section", { "data-price-assistant-panel": "link" }));
  const form = linkPanel.appendChild(
    new FakeElement("form", { "data-price-assistant-add-form": "" })
  );
  form.appendChild(new FakeElement("input", { name: "product_url" }));
  form.appendChild(new FakeElement("input", { name: "target_price" }));
  const manualList = linkPanel.appendChild(
    new FakeElement("div", { "data-price-assistant-manual-list": "" })
  );

  const cartPanel = root.appendChild(new FakeElement("section", { "data-price-assistant-panel": "cart" }));
  const tabs = cartPanel.appendChild(new FakeElement("nav", { "data-price-assistant-marketplace-tabs": "" }));
  tabs.appendChild(
    new FakeElement("button", {
      "data-price-assistant-tab": "ozon",
      class: "is-active active",
    })
  );
  tabs.appendChild(new FakeElement("button", { "data-price-assistant-tab": "wildberries" }));

  ["ozon", "wildberries"].forEach((code) => {
    const card = cartPanel.appendChild(
      new FakeElement("article", { "data-marketplace-card": code })
    );
    card.appendChild(new FakeElement("p", { "data-marketplace-state": code }));
  });
  cartPanel.appendChild(
    new FakeElement("div", {
      "data-price-assistant-collection-list": "cart",
      "data-price-assistant-delete-import": "cart",
    })
  );
  cartPanel.appendChild(new FakeElement("div", { "data-price-assistant-pagination": "cart" }));
  cartPanel.appendChild(
    new FakeElement("div", {
      "data-price-assistant-collection-list": "favorites",
      "data-price-assistant-delete-import": "favorites",
    })
  );
  cartPanel.appendChild(new FakeElement("div", { "data-price-assistant-pagination": "favorites" }));
  cartPanel.appendChild(new FakeElement("section", { "data-price-assistant-collection-panel": "cart" }));
  cartPanel.appendChild(new FakeElement("section", { "data-price-assistant-collection-panel": "favorites" }));

  root.appendChild(new FakeElement("section", { "data-price-assistant-panel": "compare" }));

  return { root, form, manualList, message };
}

function createContext(fetchImpl) {
  const { root, form, manualList, message } = createRoot();
  const document = {
    createElement: (tagName) => new FakeElement(tagName),
    createElementNS: (namespace, tagName) => new FakeElement(tagName),
    querySelector: (selector) => (root.matches(selector) ? root : root.querySelector(selector)),
    querySelectorAll: (selector) => (root.matches(selector) ? [root] : root.querySelectorAll(selector)),
  };
  const window = {
    CashbackPriceAssistantAccount: {
      restBase: "https://example.test/wp-json/cashback/v1/price-assistant",
      nonce: "nonce",
      initialMarketplace: "ozon",
      marketplaces: {
        ozon: { code: "ozon", label: "Ozon" },
        wildberries: { code: "wildberries", label: "Wildberries" },
      },
      statuses: {},
    },
    addEventListener() {},
    location: { origin: "https://example.test" },
    open() {},
  };
  const context = {
    document,
    window,
    fetch: fetchImpl,
    URLSearchParams,
    setTimeout,
    clearTimeout,
  };
  context.globalThis = context;
  vm.createContext(context);
  return { context, form, manualList, message };
}

async function flushPromises() {
  for (let index = 0; index < 10; index++) {
    await Promise.resolve();
  }
}

function successResponse(data) {
  return Promise.resolve({
    ok: true,
    text: () => Promise.resolve(JSON.stringify(data)),
  });
}

function rateLimitResponse() {
  return Promise.resolve({
    ok: false,
    text: () => Promise.resolve(JSON.stringify({ code: "price_assistant_rate_limited" })),
  });
}

function unsupportedMonitoringStoreResponse() {
  return Promise.resolve({
    ok: false,
    text: () => Promise.resolve(JSON.stringify({ detail: "unsupported_monitoring_store" })),
  });
}

async function runScript(fetchImpl) {
  const runtime = createContext(fetchImpl);
  vm.runInContext(scriptSource, runtime.context);
  await flushPromises();
  return runtime;
}

await (async function testLinkViewEmptyWatchlistUsesGenericText() {
  const { manualList } = await runScript(() => successResponse({ items: [] }));

  assert.equal(textOf(manualList), "Добавьте первый товар по ссылке.");
})();

await (async function testRateLimitShowsHumanReadableMessage() {
  const fetchCalls = [];
  const { form, message } = await runScript((url, options = {}) => {
    fetchCalls.push({ url, method: options.method || "GET" });
    if ((options.method || "GET") === "POST" && url.endsWith("/watchlist/items")) {
      return rateLimitResponse();
    }
    return successResponse({ items: [] });
  });

  form.querySelector('[name="product_url"]').value =
    "https://www.wildberries.ru/catalog/465676229/detail.aspx?targetUrl=EX";
  form.querySelector('[name="target_price"]').value = "1360";
  form.dispatchEvent({
    type: "submit",
    preventDefault() {},
  });
  await flushPromises();

  assert.equal(textOf(message), "Слишком много запросов. Попробуйте позже.");
  assert.equal(fetchCalls.some((call) => call.method === "POST"), true);
})();

await (async function testUnsupportedMonitoringStoreShowsHumanReadableMessage() {
  const { form, message } = await runScript((url, options = {}) => {
    if ((options.method || "GET") === "POST" && url.endsWith("/watchlist/items")) {
      return unsupportedMonitoringStoreResponse();
    }
    return successResponse({ items: [] });
  });

  form.querySelector('[name="product_url"]').value =
    "https://unsupported-shop.example/product/123";
  form.dispatchEvent({
    type: "submit",
    preventDefault() {},
  });
  await flushPromises();

  assert.equal(textOf(message), "Данный магазин не поддержывается для мониторинга.");
})();

await (async function testManualWatchlistShowsProductDataAndSingleEmptyChartMessage() {
  const { manualList } = await runScript((url) => {
    if (url.includes("/watchlist/items?limit=50")) {
      return successResponse({
        items: [
          {
            subscription_id: 10,
            tracked_product_id: 20,
            product_url: "https://www.wildberries.ru/catalog/465676229/detail.aspx",
            source: "wildberries",
            source_display_name: "Wildberries",
            region_code: "default",
            availability: true,
            title: "Тестовый товар",
            image_url: "https://cdn.example.test/product.jpg",
            last_price: "1360.00",
            currency: "RUB",
            target_price: "1200.00",
          },
        ],
      });
    }
    if (url.includes("/products/20/chart")) {
      return successResponse({
        labels: { headline: "Недостаточно данных для графика" },
        series: [],
        summary: { trend: "no_data" },
        currency: "RUB",
      });
    }
    if (url.includes("/collections")) {
      return successResponse({ items: [] });
    }
    if (url.includes("/connections")) {
      return successResponse({ connections: [] });
    }
    return successResponse({});
  });

  await flushPromises();

  const text = textOf(manualList);
  const image = findFirst(manualList, (node) => node.tagName === "IMG");

  assert.equal(image.src, "https://cdn.example.test/product.jpg");
  assert.match(text, /Тестовый товар/);
  assert.match(text, /1360\.00 ₽/);
  assert.equal(countText(text, "Нет данных для графика."), 1);
  assert.equal(text.includes("Недостаточно данных для графика"), false);
})();

await (async function testManualWatchlistCardHidesAvailableAndUsualPriceLabels() {
  const { manualList } = await runScript((url) => {
    if (url.includes("/watchlist/items?limit=50")) {
      return successResponse({
        items: [
          {
            subscription_id: 10,
            tracked_product_id: 20,
            product_url: "https://www.wildberries.ru/catalog/465676229/detail.aspx",
            source: "wildberries",
            source_display_name: "Wildberries",
            region_code: "default",
            availability: true,
            title: "Сумка рюкзак спортивная для фитнеса",
            image_url: "https://cdn.example.test/bag.jpg",
            last_price: "1406.00",
            currency: "RUB",
          },
        ],
      });
    }
    if (url.includes("/products/20/chart")) {
      return successResponse({
        title: "Сумка рюкзак спортивная для фитнеса",
        labels: { headline: "Сейчас обычная цена" },
        series: [{ ts: "2026-06-25T10:00:00Z", price: "1406.00" }],
        summary: {
          current_price: "1406.00",
          min_price: "1406.00",
          max_price: "1406.00",
          trend: "near_average",
        },
        y_axis: { min: "1406.00", avg: "1406.00", max: "1406.00" },
        currency: "RUB",
      });
    }
    if (url.includes("/collections")) {
      return successResponse({ items: [] });
    }
    if (url.includes("/connections")) {
      return successResponse({ connections: [] });
    }
    return successResponse({});
  });

  await flushPromises();

  const text = textOf(manualList);
  const deleteButton = findFirst(manualList, (node) =>
    node.classList.contains("cashback-price-assistant__delete-card")
  );

  assert.ok(deleteButton, "manual card must expose a delete control");
  assert.equal(deleteButton.textContent, "×");
  assert.equal(deleteButton.dataset.priceAssistantAction, "remove-manual");
  assert.equal(text.includes("default"), false);
  assert.ok(text.indexOf("Wildberries") < text.indexOf("1406.00 ₽"));
  assert.equal(text.includes("В наличии"), false);
  assert.equal(text.includes("Сейчас обычная цена"), false);
})();

await (async function testManualWatchlistCardShowsOutOfStockLabel() {
  const { manualList } = await runScript((url) => {
    if (url.includes("/watchlist/items?limit=50")) {
      return successResponse({
        items: [
          {
            subscription_id: 10,
            tracked_product_id: 20,
            product_url: "https://www.wildberries.ru/catalog/465676229/detail.aspx",
            source: "wildberries",
            source_display_name: "Wildberries",
            region_code: "default",
            availability: false,
            title: "Сумка рюкзак спортивная для фитнеса",
            image_url: "https://cdn.example.test/bag.jpg",
            last_price: "1406.00",
            currency: "RUB",
          },
        ],
      });
    }
    if (url.includes("/products/20/chart")) {
      return successResponse({
        title: "Сумка рюкзак спортивная для фитнеса",
        labels: { headline: "Сейчас обычная цена" },
        series: [{ ts: "2026-06-25T10:00:00Z", price: "1406.00" }],
        summary: {
          current_price: "1406.00",
          min_price: "1406.00",
          max_price: "1406.00",
          trend: "near_average",
        },
        y_axis: { min: "1406.00", avg: "1406.00", max: "1406.00" },
        currency: "RUB",
      });
    }
    if (url.includes("/collections")) {
      return successResponse({ items: [] });
    }
    if (url.includes("/connections")) {
      return successResponse({ connections: [] });
    }
    return successResponse({});
  });

  await flushPromises();

  const text = textOf(manualList);

  assert.match(text, /Нет в наличии/);
  assert.equal(text.includes("Сейчас обычная цена"), false);
})();

await (async function testSinglePriceChartDrawsFlatLineWithMinMaxMarkers() {
  const { manualList } = await runScript((url) => {
    if (url.includes("/watchlist/items?limit=50")) {
      return successResponse({
        items: [
          {
            subscription_id: 10,
            tracked_product_id: 20,
            source: "wildberries",
            source_display_name: "Wildberries",
            region_code: "default",
            title: "Сумка рюкзак спортивная для фитнеса",
            last_price: "1406.00",
            currency: "RUB",
          },
        ],
      });
    }
    if (url.includes("/products/20/chart")) {
      return successResponse({
        title: "Сумка рюкзак спортивная для фитнеса",
        labels: { headline: "Сейчас обычная цена" },
        series: [{ ts: "2026-06-25T10:00:00Z", price: "1406.00" }],
        summary: {
          current_price: "1406.00",
          min_price: "1406.00",
          max_price: "1406.00",
          trend: "near_average",
        },
        y_axis: { min: "1406.00", avg: "1406.00", max: "1406.00" },
        currency: "RUB",
      });
    }
    if (url.includes("/collections")) {
      return successResponse({ items: [] });
    }
    if (url.includes("/connections")) {
      return successResponse({ connections: [] });
    }
    return successResponse({});
  });

  await flushPromises();

  const polyline = findFirst(manualList, (node) =>
    node.classList.contains("cashback-price-assistant__chart-line")
  );
  const markers = findAll(manualList, (node) =>
    node.classList.contains("cashback-price-assistant__chart-extreme-label")
  );
  const priceLabels = findAll(manualList, (node) =>
    node.classList.contains("cashback-price-assistant__chart-price-label")
  );
  const axisLabels = findAll(manualList, (node) =>
    node.classList.contains("cashback-price-assistant__chart-axis-label")
  );
  const chartSummary = findFirst(manualList, (node) =>
    node.classList.contains("cashback-price-assistant__chart-summary")
  );
  const pointPairs = (polyline && polyline.getAttribute("points")
    ? polyline.getAttribute("points").trim().split(/\s+/)
    : []
  ).map((point) => point.split(",").map(Number));

  assert.equal(pointPairs.length, 2);
  assert.equal(pointPairs[0][1], pointPairs[1][1]);
  assert.equal(chartSummary, null);
  assert.equal(textOf(manualList).includes("Сейчас 1406.00 ₽ · минимум"), false);
  assert.deepEqual(
    priceLabels.map(textOf),
    ["1406.00 ₽", "1406.00 ₽"]
  );
  assert.deepEqual(axisLabels.map(textOf), ["Мин", "Макс"]);
  assert.equal(
    Number(priceLabels[0].getAttribute("y")) < Number(axisLabels[0].getAttribute("y")),
    true
  );
  assert.deepEqual(
    markers.map(textOf),
    ["1406.00 ₽", "Мин", "1406.00 ₽", "Макс"]
  );
})();
