/* eslint-disable security/detect-non-literal-fs-filename, security/detect-object-injection */
import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";

const scriptSource = fs.readFileSync(
  new URL("../assets/js/cashback-product-link-form.js", import.meta.url),
  "utf8"
);

class FakeElement {
  constructor(tagName, attributes = {}) {
    this.tagName = tagName.toUpperCase();
    this.attributes = { ...attributes };
    this.children = [];
    this.disabled = false;
    this.hidden = false;
    this.value = "";
    this._innerHTML = "";
    this.textContent = "";
  }

  get innerHTML() {
    return this._innerHTML;
  }

  set innerHTML(value) {
    this._innerHTML = String(value);
    this.children = [];
    const linkMatch = this._innerHTML.match(/<a[^>]+href="([^"]+)"/);
    if (linkMatch) {
      this.children.push(new FakeElement("a", { href: linkMatch[1] }));
    }
  }

  getAttribute(name) {
    return this.attributes[name] || null;
  }

  matches(selector) {
    return selector === "[data-cashback-product-link-form]" && Boolean(this.attributes["data-cashback-product-link-form"]);
  }

  querySelector(selector) {
    return this.children.find((child) => child.matchesSelector(selector)) || null;
  }

  matchesSelector(selector) {
    if (selector === "[data-cashback-product-link-result]") {
      return Boolean(this.attributes["data-cashback-product-link-result"]);
    }
    if (selector === "[data-cashback-product-link-warning]") {
      return Boolean(this.attributes["data-cashback-product-link-warning"]);
    }
    if (selector === 'input[name="direct_url"]') {
      return this.tagName === "INPUT" && this.attributes.name === "direct_url";
    }
    if (selector === 'button[type="submit"]') {
      return this.tagName === "BUTTON" && this.attributes.type === "submit";
    }
    return false;
  }
}

class FakeFormElement extends FakeElement {}

function createForm() {
  const form = new FakeFormElement("form", { "data-cashback-product-link-form": "1" });
  const input = new FakeElement("input", { name: "direct_url" });
  input.value = "https://krona.ru/catalog/fridges/vstraivaemye/balfrin/";
  form.children.push(
    input,
    new FakeElement("button", { type: "submit" }),
    new FakeElement("p", { "data-cashback-product-link-warning": "1" }),
    new FakeElement("div", { "data-cashback-product-link-result": "1" })
  );
  return form;
}

async function flushPromises() {
  for (let index = 0; index < 10; index++) {
    await Promise.resolve();
  }
}

await (async function testActivationPageUrlWinsOverRawAffiliateUrl() {
  const listeners = {};
  const form = createForm();
  const document = {
    addEventListener(type, listener) {
      listeners[type] = listener;
    },
  };
  const context = {
    document,
    fetch: () =>
      Promise.resolve({
        ok: true,
        json: () =>
          Promise.resolve({
            cashback_available: true,
            button_text: "Активировать кэшбэк",
            merchant: "KRONA",
            cashback_rate: "2.77%",
            url: "https://rcpsj.com/g/raw-affiliate/",
            activation_page_url:
              "https://savelloclub.test/?cashback_go=1&click_id=0123456789abcdef0123456789abcdef&t=signed",
          }),
      }),
    HTMLFormElement: FakeFormElement,
    window: {
      CashbackProductLinkForm: {
        endpoint: "https://savelloclub.test/wp-json/cashback/v1/product-link/resolve",
        nonce: "nonce",
      },
    },
  };
  context.globalThis = context;
  vm.createContext(context);
  vm.runInContext(scriptSource, context);

  listeners.submit({
    target: form,
    preventDefault() {},
  });
  await flushPromises();

  const result = form.querySelector("[data-cashback-product-link-result]");
  const link = result.children[0];

  assert.equal(
    link.getAttribute("href"),
    "https://savelloclub.test/?cashback_go=1&amp;click_id=0123456789abcdef0123456789abcdef&amp;t=signed"
  );
})();
