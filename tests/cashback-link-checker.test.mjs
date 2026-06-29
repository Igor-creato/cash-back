import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

// eslint-disable-next-line security/detect-non-literal-fs-filename -- Test reads a fixed repository-relative asset.
const source = await readFile(new URL('../assets/js/cashback-link-checker.js', import.meta.url), 'utf8');
// eslint-disable-next-line security/detect-non-literal-fs-filename -- Test reads a fixed repository-relative asset.
const guestWarningSource = await readFile(new URL('../assets/js/affiliate-guest-warning.js', import.meta.url), 'utf8');

class FakeElement {
  constructor(tagName) {
    this.tagName = tagName.toUpperCase();
    this.children = [];
    this.firstChild = null;
    this.className = '';
    this.textContent = '';
    this.innerHTML = '';
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
  assert.doesNotMatch(source, /Кэшбэк не гарантируется/);
  assert.doesNotMatch(source, /гарантируем/i);
});

test('guest warning script exposes reusable modal API with onContinue hook', () => {
  assert.match(guestWarningSource, /CashbackAffiliateGuestWarning/);
  assert.match(guestWarningSource, /onContinue/);
});

test('link checker renders product conditions html through safe html helper', async () => {
  let submitListener = null;
  const { form, result } = createForm('https://iboxstore.ru/catalog/item');

  const context = {
    window: {
      CashbackLinkChecker: {
        restBase: 'https://savelloclub.test/wp-json/cashback/v1/link-checker',
        nonce: 'nonce',
        isLoggedIn: true,
        i18n: {}
      },
      cashbackSafeHtml(html) {
        return String(html).replace('unsafe-marker', 'safe-marker');
      },
      crypto: { randomUUID: () => 'request-conditions' }
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
    fetch(url) {
      if (url.endsWith('/check')) {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve({
            status: 'available',
            host: 'iboxstore.ru',
            store: { name: 'iBox' },
            cashback: { label: 'Кэшбэк', value: '5%' },
            conditions_html: '<h3><strong>Условия начисления</strong></h3><p>unsafe-marker <strong>5%</strong></p>',
            conditions: ['generic fallback']
          })
        });
      }

      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({})
      });
    }
  };
  context.window.window = context.window;

  vm.runInNewContext(source, context);
  submitListener({
    target: form,
    preventDefault() {}
  });

  await flushPromises();
  const conditions = result.children.find((child) => child.className === 'cashback-link-checker__conditions-html');

  assert.ok(conditions, 'available result should render conditions_html');
  assert.match(conditions.innerHTML, /Условия начисления/);
  assert.match(conditions.innerHTML, /safe-marker/);
  assert.equal(
    result.children.some((child) => /Кэшбэк не гарантируется/.test(child.textContent)),
    false
  );
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

test('guest link checker activation opens existing warning modal and waits for continue', async () => {
  let submitListener = null;
  let modalOptions = null;
  const { form, result } = createForm('https://iboxstore.ru/catalog/item');
  const popup = { closed: false, location: 'about:blank', opener: {} };
  const opened = [];
  const requests = [];

  const context = {
    window: {
      CashbackLinkChecker: {
        restBase: 'https://savelloclub.test/wp-json/cashback/v1/link-checker',
        nonce: 'nonce',
        isLoggedIn: '',
        loginUrl: 'https://savelloclub.test/my-account/?action=register',
        guestWarningMessage: 'Гость предупрежден',
        i18n: {}
      },
      CashbackAffiliateGuestWarning: {
        show(options) {
          modalOptions = options;
        }
      },
      crypto: { randomUUID: () => 'request-guest' },
      open(url, target, features) {
        opened.push({ url, target, features });
        return popup;
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
          activation_page_url: 'https://savelloclub.test/cashback-go/request-guest',
          cashback_url: 'https://admitad.example/deeplink'
        })
      });
    }
  };
  context.window.window = context.window;

  vm.runInNewContext(source, context);
  submitListener({
    target: form,
    preventDefault() {}
  });

  await flushPromises();
  const activateButton = result.children.find((child) => child.tagName === 'BUTTON');
  assert.ok(activateButton, 'available result should render activation button');

  activateButton.click();
  await flushPromises();

  assert.equal(requests.length, 1);
  assert.equal(opened.length, 0);
  assert.equal(modalOptions.loginUrl, 'https://savelloclub.test/my-account/?action=register');
  assert.equal(modalOptions.warningMessage, 'Гость предупрежден');
  assert.equal(typeof modalOptions.onContinue, 'function');

  modalOptions.onContinue();
  await flushPromises();

  assert.equal(requests.length, 2);
  assert.equal(opened[0].url, 'about:blank');
  assert.equal(popup.location, 'https://savelloclub.test/cashback-go/request-guest');
});

test('link checker activation keeps the checked result text unchanged after redirect starts', async () => {
  let submitListener = null;
  const { form, result } = createForm('https://iboxstore.ru/catalog/item');
  const popup = { closed: false, location: 'about:blank', opener: {} };

  const context = {
    window: {
      CashbackLinkChecker: {
        restBase: 'https://savelloclub.test/wp-json/cashback/v1/link-checker',
        nonce: 'nonce',
        i18n: {}
      },
      crypto: { randomUUID: () => '550e8400-e29b-41d4-a716-446655440000' },
      open() {
        return popup;
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
    fetch(url) {
      if (url.endsWith('/check')) {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve({
            status: 'available',
            host: 'iboxstore.ru',
            store: { name: 'iBOX' },
            cashback: { label: 'Кэшбэк', value: '2.87%' },
            conditions: ['Кэшбэк начисляется после подтверждения заказа магазином и CPA-сетью.']
          })
        });
      }

      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({
          status: 'ok',
          activation_page_url: 'https://savelloclub.test/?cashback_go=1&click_id=click-1'
        })
      });
    }
  };
  context.window.window = context.window;

  vm.runInNewContext(source, context);
  submitListener({
    target: form,
    preventDefault() {}
  });

  await flushPromises();
  const activateButton = result.children.find((child) => child.tagName === 'BUTTON');
  assert.ok(activateButton, 'available result should render activation button');

  activateButton.click();
  await flushPromises();

  assert.equal(result.className, 'cashback-link-checker__result cashback-link-checker__result--available');
  assert.equal(result.children[0].textContent, 'iBOX');
  assert.equal(activateButton.disabled, false);
  assert.equal(activateButton.textContent, 'Активировать кэшбэк');
  assert.equal(
    result.children.some((child) => /Переход активирован/.test(child.textContent)),
    false
  );
});
