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
    this.classList = {
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
  const stores = root.appendChild(new FakeElement({ "data-pa-section": "stores" }));
  const notice = new FakeElement();
  notice.appendChild(new FakeElement());
  return { root, form, stores, notice };
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
  dom.form.fields.homepage_url = "https://www.dns-shop.ru/";
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
    enabled: true,
    homepage_url: "https://www.dns-shop.ru/",
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

  root.dispatchEvent({
    type: "click",
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
    },
    document: {
      querySelector: (selector) =>
        selector === "[data-price-assistant-admin]" ? dom.root : null,
      getElementById: (id) => (id === "cashback-pa-admin-notice" ? dom.notice : null),
    },
    fetch,
    FormData: class {
      constructor(form) {
        this.form = form;
      }

      get(name) {
        return this.form.fields[name] || null;
      }
    },
  };
  vm.runInNewContext(scriptSource, context);
  return dom.root;
}

await testStoreSubmitCreatesEnabledStore();
await testStoreTableRendersStatusActionsAndPatchesEnabledState();
