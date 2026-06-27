import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

// eslint-disable-next-line security/detect-non-literal-fs-filename -- Test reads a fixed repository-relative asset.
const source = await readFile(new URL('../assets/js/cashback-link-checker.js', import.meta.url), 'utf8');

class FakeElement {
  constructor(tagName) {
    this.tagName = tagName.toUpperCase();
    this.children = [];
    this.firstChild = null;
    this.className = '';
    this.textContent = '';
    this.clickListener = null;
    this.lastAttribute = null;
    this.disabled = false;
    this.value = '';
  }

  appendChild(child) {
    this.children.push(child);
    this.firstChild = this.children[0] || null;
    return child;
  }

  removeChild(child) {
    this.children = this.children.filter((candidate) => candidate !== child);
    this.firstChild = this.children[0] || null;
    return child;
  }

  setAttribute(name, value) {
    this.lastAttribute = { name, value: String(value) };
  }

  addEventListener(type, callback) {
    if (type === 'click') {
      this.clickListener = callback;
    }
  }

  click() {
    if (this.clickListener) {
      this.clickListener({ target: this });
    }
  }
}

function createForm(url) {
  const form = new FakeElement('form');
  const input = new FakeElement('input');
  const result = new FakeElement('div');
  input.value = url;

  form.matches = (selector) => selector === '[data-cashback-link-checker-form]';
  form.querySelector = (selector) => {
    if (selector === '[name="direct_url"]') {
      return input;
    }
    if (selector === '[data-cashback-link-checker-result]') {
      return result;
    }
    return null;
  };

  return { form, result };
}

async function flushPromises() {
  for (let i = 0; i < 6; i += 1) {
    await Promise.resolve();
  }
}

test('link checker frontend calls check and activate endpoints', () => {
  assert.match(source, /fetch\(/);
  assert.match(source, /\/check/);
  assert.match(source, /\/activate/);
  assert.match(source, /client_request_id/);
});

test('link checker frontend avoids guaranteed cashback copy', () => {
  assert.match(source, /Кэшбэк не гарантируется/);
  assert.doesNotMatch(source, /гарантируем/i);
});

test('link checker frontend opens a tab synchronously and redirects it to activation page', () => {
  assert.match(source, /window\.open\(/);
  assert.match(source, /about:blank/);
  assert.match(source, /activation_page_url/);
  assert.match(source, /popup\.location(?:\.href)?\s*=/);
});

test('link checker activation keeps the reserved tab controllable for async redirect', async () => {
  let submitListener = null;
  const { form, result } = createForm('https://iboxstore.ru/catalog/item');
  const popup = { closed: false, location: 'about:blank', opener: {} };
  const opened = [];
  const requests = [];

  const context = {
    window: {
      CashbackLinkChecker: {
        restBase: 'https://savelloclub.test/wp-json/cashback/v1/link-checker',
        nonce: 'nonce',
        i18n: {}
      },
      crypto: { randomUUID: () => 'request-1' },
      open(url, target, features) {
        opened.push({ url, target, features });
        return String(features || '').includes('noopener') ? null : popup;
      }
    },
    document: {
      addEventListener(type, callback) {
        if (type === 'submit') {
          submitListener = callback;
        }
      },
      createElement(tagName) {
        return new FakeElement(tagName);
      }
    },
    fetch(url, options) {
      requests.push({ url, options });
      if (url.endsWith('/check')) {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve({
            status: 'available',
            host: 'iboxstore.ru',
            store: { name: 'iBox' },
            cashback: { label: 'Кэшбэк', value: '5%' },
            conditions: []
          })
        });
      }

      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({
          status: 'ok',
          activation_page_url: 'https://savelloclub.test/cashback-go/request-1',
          cashback_url: 'https://admitad.example/deeplink'
        })
      });
    }
  };
  context.window.window = context.window;

  vm.runInNewContext(source, context);
  assert.equal(typeof submitListener, 'function');
  submitListener({
    target: form,
    preventDefault() {}
  });

  await flushPromises();
  const activateButton = result.children.find((child) => child.tagName === 'BUTTON');
  assert.ok(activateButton, 'available result should render activation button');

  activateButton.click();
  await flushPromises();

  assert.equal(opened[0].url, 'about:blank');
  assert.doesNotMatch(String(opened[0].features || ''), /noopener/i);
  assert.equal(popup.opener, null);
  assert.equal(popup.location, 'https://savelloclub.test/cashback-go/request-1');
  assert.equal(requests.length, 2);
});
