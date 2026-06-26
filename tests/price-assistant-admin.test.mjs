/* eslint-disable security/detect-non-literal-fs-filename, security/detect-object-injection, security/detect-unsafe-regex */
import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";

const scriptSource = fs.readFileSync(
  new URL("../admin/js/price-assistant-admin.js", import.meta.url),
  "utf8"
);

class FakeElement {
  constructor(attributes = {}) {
    this.attributes = attributes;
    this.children = [];
    this.eventListeners = {};
    this.fields = {};
    this.hidden = false;
    this.className = "";
    this.textContent = "";
    this.innerHTML = "";
    this.value = attributes.value || "";
    this.classList = {
      add: () => {},
      remove: () => {},
      toggle: () => {},
    };
  }

  appendChild(child) {
    this.children.push(child);
    return child;
  }

  getAttribute(name) {
    return this.attributes[name] || null;
  }

  setAttribute(name, value) {
    this.attributes[name] = String(value);
  }

  querySelector(selector) {
    if (selector === "p") {
      return this.children[0] || null;
    }
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
    visit(this);
    return matches;
  }

  matches(selector) {
    const match = selector.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/);
    if (!match) {
      return false;
    }
    const [, name, value] = match;
    if (!Object.prototype.hasOwnProperty.call(this.attributes, name)) {
      return false;
    }
    return value === undefined || this.attributes[name] === value;
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
    this.fields = {};
  }
}

function createAdminDom() {
  const root = new FakeElement({ "data-price-assistant-admin": "" });
  const form = root.appendChild(new FakeElement({ "data-pa-store-form": "" }));
  form.appendChild(new FakeElement({ name: "homepage_url" }));
  form.appendChild(new FakeElement({ name: "display_name" }));
  form.appendChild(new FakeElement({ name: "logo_url" }));
  const stores = root.appendChild(new FakeElement({ "data-pa-section": "stores" }));
  const pagination = root.appendChild(new FakeElement({ "data-pa-store-pagination": "" }));
  const notice = new FakeElement();
  notice.appendChild(new FakeElement());
  return { root, form, stores, pagination, notice };
}

function response(data, ok = true) {
  return Promise.resolve({
    ok,
    json: () => Promise.resolve(data),
  });
}

async function flush() {
  for (let index = 0; index < 6; index += 1) {
    await Promise.resolve();
  }
}

async function testStoreSubmitCreatesEnabledStore() {
  const dom = createAdminDom();
  dom.form.querySelector('[name="homepage_url"]').value = "https://www.dns-shop.ru/";
  dom.form.querySelector('[name="display_name"]').value = "DNS";
  dom.form.querySelector('[name="logo_url"]').value = "";
  const calls = [];
  const fetch = (url, options = {}) => {
    calls.push({ url, options });
    if (options.method === "POST") {
      return response({ store_id: 10 });
    }
    return response({ items: [] });
  };

  runScript(dom, fetch);
  await flush();
  dom.form.dispatchEvent({
    type: "submit",
    preventDefault: () => {},
  });
  await flush();

  const post = calls.find((call) => call.options.method === "POST");
  assert.deepEqual(JSON.parse(post.options.body), {
    display_name: "DNS",
    enabled: true,
    homepage_url: "https://www.dns-shop.ru/",
    logo_url: null,
  });
}

async function testStoreTableRendersStatusActionsAndPatchesEnabledState() {
  const dom = createAdminDom();
  const calls = [];
  const fetch = (url, options = {}) => {
    calls.push({ url, options });
    if (options.method === "PATCH") {
      return response({ store_id: 2, enabled: false });
    }
    return response({
      items: [
        {
          store_id: 1,
          store_code: "ozon",
          display_name: "Ozon",
          enabled: true,
          sources: [],
        },
        {
          store_id: 2,
          store_code: "dns_shop_ru",
          display_name: "Dns Shop Ru",
          enabled: false,
          sources: [],
        },
      ],
    });
  };

  const root = runScript(dom, fetch);
  await flush();

  assert.match(dom.stores.innerHTML, /Деактивировать/);
  assert.match(dom.stores.innerHTML, /Активировать/);
  assert.match(dom.stores.innerHTML, /Редактировать/);
  assert.match(dom.stores.innerHTML, /<th>Логотип<\/th>/);

  root.dispatchEvent({
    type: "click",
    preventDefault: () => {},
    target: {
      closest: (selector) =>
        selector === "[data-pa-toggle-store]"
          ? {
              getAttribute: (name) =>
                ({
                  "data-pa-store-id": "2",
                  "data-pa-store-enabled": "true",
                })[name] || null,
            }
          : null,
    },
  });
  await flush();

  const patch = calls.find((call) => call.options.method === "PATCH");
  assert.equal(patch.url, "https://example.test/wp-json/cashback/v1/price-assistant/admin/stores/2");
  assert.deepEqual(JSON.parse(patch.options.body), { enabled: true });
}

async function testEditStoreTurnsTableRowIntoEditableFields() {
  const dom = createAdminDom();
  const fetch = () =>
    response({
      items: [
        {
          store_id: 7,
          store_code: "dns_shop_ru",
          display_name: "DNS",
          homepage_url: "https://www.dns-shop.ru/",
          logo_url: "https://cdn.example.com/dns.svg",
          enabled: true,
          sources: [],
        },
      ],
    });

  const root = runScript(dom, fetch);
  await flush();

  root.dispatchEvent({
    type: "click",
    preventDefault: () => {},
    target: {
      closest: (selector) =>
        selector === "[data-pa-edit-store]"
          ? {
              getAttribute: (name) =>
                ({
                  "data-pa-store-id": "7",
                })[name] || null,
            }
          : null,
    },
  });

  assert.match(dom.stores.innerHTML, /data-pa-store-input="display_name"/);
  assert.match(dom.stores.innerHTML, /data-pa-store-input="homepage_url"/);
  assert.match(dom.stores.innerHTML, /data-pa-store-input="logo_url"/);
  assert.match(dom.stores.innerHTML, /data-pa-save-store/);
  assert.match(dom.stores.innerHTML, /data-pa-cancel-store/);
  assert.doesNotMatch(dom.stores.innerHTML, /data-pa-store-cancel-edit/);
}

async function testInlineStoreSavePatchesEditableFieldsOnly() {
  const dom = createAdminDom();
  const calls = [];
  const fetch = (url, options = {}) => {
    calls.push({ url, options });
    if (options.method === "PATCH") {
      return response({ store_id: 7, display_name: "DNS Updated" });
    }
    return response({
      items: [
        {
          store_id: 7,
          store_code: "dns_shop_ru",
          display_name: "DNS",
          homepage_url: "https://www.dns-shop.ru/",
          logo_url: "https://cdn.example.com/dns.svg",
          enabled: true,
          sources: [],
        },
      ],
    });
  };

  const row = {
    querySelector: (selector) =>
      ({
        '[data-pa-store-input="display_name"]': { value: "DNS Updated" },
        '[data-pa-store-input="homepage_url"]': { value: "https://www.dns-shop.ru/" },
        '[data-pa-store-input="logo_url"]': { value: "https://cdn.example.com/dns-updated.svg" },
      })[selector] || null,
  };

  const root = runScript(dom, fetch);
  await flush();
  root.dispatchEvent({
    type: "click",
    preventDefault: () => {},
    target: {
      closest: (selector) => {
        if (selector === "[data-pa-save-store]") {
          return { getAttribute: (name) => (name === "data-pa-store-id" ? "7" : null) };
        }
        if (selector === "[data-pa-store-row]") {
          return row;
        }
        return null;
      },
    },
  });
  await flush();

  const patch = calls.find((call) => call.options.method === "PATCH");
  assert.equal(patch.url, "https://example.test/wp-json/cashback/v1/price-assistant/admin/stores/7");
  assert.deepEqual(JSON.parse(patch.options.body), {
    display_name: "DNS Updated",
    homepage_url: "https://www.dns-shop.ru/",
    logo_url: "https://cdn.example.com/dns-updated.svg",
  });
}

async function testInlineStoreCancelRestoresReadOnlyRow() {
  const dom = createAdminDom();
  const fetch = () =>
    response({
      items: [
        {
          store_id: 7,
          store_code: "dns_shop_ru",
          display_name: "DNS",
          homepage_url: "https://www.dns-shop.ru/",
          logo_url: "https://cdn.example.com/dns.svg",
          enabled: true,
          sources: [],
        },
      ],
    });

  const root = runScript(dom, fetch);
  await flush();

  root.dispatchEvent({
    type: "click",
    preventDefault: () => {},
    target: {
      closest: (selector) =>
        selector === "[data-pa-edit-store]"
          ? {
              getAttribute: (name) =>
                ({
                  "data-pa-store-id": "7",
                })[name] || null,
            }
          : null,
    },
  });

  assert.match(dom.stores.innerHTML, /data-pa-store-input="display_name"/);

  root.dispatchEvent({
    type: "click",
    preventDefault: () => {},
    target: {
      closest: (selector) =>
        selector === "[data-pa-cancel-store]"
          ? {
              getAttribute: (name) =>
                ({
                  "data-pa-store-id": "7",
                })[name] || null,
            }
          : null,
    },
  });

  assert.doesNotMatch(dom.stores.innerHTML, /data-pa-store-input="display_name"/);
  assert.match(dom.stores.innerHTML, /Редактировать/);
}

async function testStorePaginationUsesSharedHelperAfterTwentyItems() {
  const dom = createAdminDom();
  const calls = [];
  const fetch = (url, options = {}) => {
    calls.push({ url, options });
    return response({
      items: [{ store_id: 1, store_code: "one", display_name: "One", enabled: true, sources: [] }],
      page: 1,
      per_page: 20,
      total_items: 21,
      total_pages: 2,
    });
  };

  const root = runScript(dom, fetch);
  await flush();

  assert.equal(
    calls[0].url,
    "https://example.test/wp-json/cashback/v1/price-assistant/admin/stores?page=1&per_page=20"
  );
  assert.match(dom.pagination.innerHTML, /data-page="2"/);

  root.dispatchEvent({
    type: "click",
    target: {
      closest: (selector) =>
        selector === "[data-pa-store-pagination] .page-numbers[data-page]"
          ? {
              getAttribute: (name) => (name === "data-page" ? "2" : null),
              closest: () => dom.pagination,
            }
          : null,
    },
    preventDefault: () => {},
  });
  await flush();

  assert.equal(
    calls[1].url,
    "https://example.test/wp-json/cashback/v1/price-assistant/admin/stores?page=2&per_page=20"
  );
}

function runScript(dom, fetch) {
  const context = {
    window: {
      cashbackPriceAssistantAdmin: {
        restBase: "https://example.test/wp-json/cashback/v1/price-assistant/admin",
        nonce: "nonce",
        labels: {
          loading: "Загрузка…",
          empty: "Данных пока нет.",
          enabled: "Включён",
          disabled: "Отключён",
          saved: "Сохранено.",
          saveError: "Не удалось сохранить.",
          loadError: "Не удалось загрузить данные.",
        },
      },
      CashbackPagination: {
        build: (currentPage, totalPages) =>
          totalPages > 1
            ? `<nav><a href="#" class="page-numbers" data-page="${currentPage + 1}">${currentPage + 1}</a></nav>`
            : "",
      },
    },
    document: {
      querySelector: (selector) =>
        selector === "[data-price-assistant-admin]" ? dom.root : null,
      getElementById: (id) => (id === "cashback-pa-admin-notice" ? dom.notice : null),
      createElement: () => new FakeElement(),
    },
    fetch,
    FormData: class {
      constructor(form) {
        this.form = form;
      }

      get(name) {
        const field = this.form.querySelector(`[name="${name}"]`);
        return field ? field.value : null;
      }
    },
  };
  vm.runInNewContext(scriptSource, context);
  return dom.root;
}

await testStoreSubmitCreatesEnabledStore();
await testStoreTableRendersStatusActionsAndPatchesEnabledState();
await testEditStoreTurnsTableRowIntoEditableFields();
await testInlineStoreSavePatchesEditableFieldsOnly();
await testInlineStoreCancelRestoresReadOnlyRow();
await testStorePaginationUsesSharedHelperAfterTwentyItems();
