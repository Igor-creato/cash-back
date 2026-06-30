import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../assets/js/price-monitor-account.js', import.meta.url), 'utf8');

class FakeElement {
  constructor(tagName) {
    this.tagName = tagName.toUpperCase();
    this.children = [];
    this.parentNode = null;
    this.className = '';
    this._textContent = '';
    this.innerHTML = '';
    this.value = '';
    this.disabled = false;
    this.type = '';
    this.attributes = new Map();
    this.dataset = {};
    this.listeners = new Map();
  }

  set textContent(value) {
    this._textContent = String(value);
  }

  get textContent() {
    return [
      this._textContent,
      ...this.children.map((child) => child.textContent)
    ].join(' ').trim();
  }

  appendChild(child) {
    child.parentNode = this;
    this.children.push(child);
    return child;
  }

  removeChild(child) {
    this.children = this.children.filter((candidate) => candidate !== child);
    child.parentNode = null;
    return child;
  }

  remove() {
    if (this.parentNode) {
      this.parentNode.removeChild(this);
    }
  }

  setAttribute(name, value) {
    const stringValue = String(value);
    this.attributes.set(name, stringValue);
    if (name === 'class') {
      this.className = stringValue;
    }
    if (name.startsWith('data-')) {
      const key = name
        .slice(5)
        .replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
      this.dataset[key] = stringValue;
    }
  }

  getAttribute(name) {
    return this.attributes.get(name) ?? null;
  }

  addEventListener(type, callback) {
    this.listeners.set(type, callback);
  }

  click() {
    const handler = this.listeners.get('click');
    if (handler) {
      handler({ preventDefault() {}, target: this, currentTarget: this });
    }
  }

  matches(selector) {
    if (selector.startsWith('.')) {
      const className = selector.slice(1);
      return this.className.split(/\s+/).filter(Boolean).includes(className);
    }

    const dataSelector = selector.match(/^\[data-([a-z0-9-]+)\]$/i);
    if (dataSelector) {
      return this.attributes.has(`data-${dataSelector[1]}`);
    }

    const namedSelector = selector.match(/^\[name="([^"]+)"\]$/);
    if (namedSelector) {
      return this.getAttribute('name') === namedSelector[1];
    }

    if (/^[a-z]+$/i.test(selector)) {
      return this.tagName === selector.toUpperCase();
    }

    return false;
  }

  closest(selector) {
    let current = this;
    while (current) {
      if (current.matches(selector)) {
        return current;
      }
      current = current.parentNode;
    }
    return null;
  }

  querySelector(selector) {
    if (selector.includes(' ')) {
      const [parentSelector, childSelector] = selector.split(/\s+/, 2);
      const parent = this.querySelector(parentSelector);
      return parent ? parent.querySelector(childSelector) : null;
    }
    const queue = [...this.children];
    while (queue.length > 0) {
      const current = queue.shift();
      if (current.matches(selector)) {
        return current;
      }
      queue.push(...current.children);
    }
    return null;
  }

  querySelectorAll(selector) {
    const matches = [];
    const queue = [...this.children];
    while (queue.length > 0) {
      const current = queue.shift();
      if (current.matches(selector)) {
        matches.push(current);
      }
      queue.push(...current.children);
    }
    return matches;
  }
}

function createEnvironment({
  responses = [],
  initialItems = [],
  promptValue = '4999',
  confirmValue = true
} = {}) {
  let submitListener = null;
  const root = new FakeElement('section');
  root.setAttribute('data-price-monitor-account', '');
  const form = new FakeElement('form');
  form.setAttribute('data-price-monitor-add-form', '');
  const urlInput = new FakeElement('input');
  urlInput.setAttribute('name', 'url');
  urlInput.value = 'https://shop.example/item';
  const priceInput = new FakeElement('input');
  priceInput.setAttribute('name', 'target_price_minor');
  priceInput.value = '12345';
  const feedback = new FakeElement('div');
  feedback.setAttribute('data-price-monitor-feedback', '');
  const items = new FakeElement('div');
  items.setAttribute('data-price-monitor-items', '');
  form.appendChild(urlInput);
  form.appendChild(priceInput);
  root.appendChild(form);
  root.appendChild(feedback);
  root.appendChild(items);

  const opened = [];
  const popup = { closed: false, location: 'about:blank', opener: {} };
  const requests = [];

  const context = {
    window: {
      CashbackPriceMonitorAccount: {
        restBase: 'https://savelloclub.test/wp-json/cashback/v1/price-monitor',
        nonce: 'nonce',
        isLoggedIn: true,
        items: initialItems,
        i18n: {
          title: 'Мониторинг цен',
          unsupportedStore: 'Магазин не поддерживается',
          duplicateWatchlistItem: 'Товар уже отслеживается',
          limitExceeded: 'Достигнут лимит отслеживаемых товаров',
          fetchPending: 'Данные товара загружаются',
          fetchFailed: 'Не удалось обновить данные товара',
          cashbackUnavailable: 'Кэшбэк не начислится',
          invalidTargetPrice: 'Проверьте желаемую цену',
          empty: 'Пока нет отслеживаемых товаров',
          cashbackButton: 'Активировать кэшбэк',
          editButton: 'Изменить цену',
          deleteButton: 'Удалить'
        }
      },
      prompt() {
        return promptValue;
      },
      confirm() {
        return confirmValue;
      },
      open(url) {
        opened.push(url);
        return popup;
      },
      crypto: {
        randomUUID() {
          return '550e8400-e29b-41d4-a716-446655440000';
        }
      }
    },
    document: {
      addEventListener(type, callback) {
        if (type === 'submit') {
          submitListener = callback;
        }
      },
      querySelector(selector) {
        if (selector === '[data-price-monitor-account]') {
          return root;
        }
        return null;
      },
      createElement(tagName) {
        return new FakeElement(tagName);
      },
      createElementNS(_ns, tagName) {
        return new FakeElement(tagName);
      }
    },
    fetch(url, options = {}) {
      requests.push({ url, options });
      const next = responses.shift();
      if (!next) {
        throw new Error(`Unexpected fetch: ${url}`);
      }
      return Promise.resolve(next);
    }
  };
  context.window.window = context.window;

  return {
    context,
    root,
    form,
    urlInput,
    priceInput,
    feedback,
    items,
    get submitListener() {
      return submitListener;
    },
    requests,
    opened,
    popup
  };
}

async function flushPromises() {
  for (let i = 0; i < 8; i += 1) {
    await Promise.resolve();
  }
}

function okJson(payload) {
  return {
    ok: true,
    json: async () => payload
  };
}

function errorJson(status, payload) {
  return {
    ok: false,
    status,
    json: async () => payload
  };
}

function cardFixture() {
  return {
    item: {
      id: 'item-1',
      product_id: 'product-1',
      target_price_minor: 12345
    },
    product: {
      title: 'Example Product',
      image_url: 'https://example.com/image.jpg',
      rating_value: '4.7',
      current_price_minor: 11888,
      currency: 'RUB'
    },
    source: {
      source_domain: 'shop.example',
      display_name: 'Shop Example',
      logo_url: 'https://example.com/logo.png'
    },
    chart: {
      currency: 'RUB',
      points: [
        { date: '2026-06-28', min_price_minor: 12500, max_price_minor: 12500 },
        { date: '2026-06-29', min_price_minor: 12100, max_price_minor: 12100 },
        { date: '2026-06-30', min_price_minor: 11888, max_price_minor: 11888 }
      ]
    },
    activation: {
      activation_page_url: 'https://savelloclub.test/cashback-go/item-1',
      button_text: 'Активировать кэшбэк'
    },
    actions: {
      direct_url: 'https://shop.example/item'
    }
  };
}

test('price monitor account script declares expected REST and action flow', () => {
  assert.match(source, /X-WP-Nonce/);
  assert.match(source, /PATCH/);
  assert.match(source, /DELETE/);
  assert.match(source, /activation_page_url/);
});

test('unsupported store renders Russian error message', async () => {
  const env = createEnvironment({
    responses: [
      errorJson(422, {
        code: 'unsupported_store',
        message: 'Магазин не поддерживается'
      })
    ]
  });

  vm.runInNewContext(source, env.context);
  env.submitListener({
    target: env.form,
    preventDefault() {}
  });
  await flushPromises();

  assert.equal(env.feedback.textContent, 'Магазин не поддерживается');
});

test('duplicate and limit responses use exact Russian copy', async () => {
  for (const payload of [
    { code: 'duplicate_watchlist_item', message: 'Товар уже отслеживается' },
    { code: 'limit_exceeded', message: 'Достигнут лимит отслеживаемых товаров' }
  ]) {
    const env = createEnvironment({
      responses: [errorJson(409, payload)]
    });

    vm.runInNewContext(source, env.context);
    env.submitListener({
      target: env.form,
      preventDefault() {}
    });
    await flushPromises();

    assert.equal(env.feedback.textContent, payload.message);
  }
});

test('successful add renders product card with image, rating, source logo, chart, and cashback action', async () => {
  const env = createEnvironment({
    responses: [okJson(cardFixture())]
  });

  vm.runInNewContext(source, env.context);
  env.submitListener({
    target: env.form,
    preventDefault() {}
  });
  await flushPromises();

  const card = env.items.children[0];
  assert.ok(card, 'a successful add should append a card');
  assert.ok(card.querySelector('.price-monitor-account__image'));
  assert.ok(card.querySelector('.price-monitor-account__source-logo'));
  assert.ok(card.querySelector('.price-monitor-account__chart svg'));
  assert.ok(card.querySelector('.price-monitor-account__action'));
  assert.match(card.textContent, /Example Product/);
  assert.match(card.textContent, /4\.7/);
  assert.match(card.textContent, /Shop Example/);
});

test('edit, delete, and cashback action use PATCH, DELETE, and activation URL flow', async () => {
  const env = createEnvironment({
    initialItems: [cardFixture()],
    responses: [
      okJson({
        item: {
          id: 'item-1',
          target_price_minor: 4999
        }
      }),
      okJson({})
    ]
  });

  vm.runInNewContext(source, env.context);
  await flushPromises();

  const card = env.items.children[0];
  assert.ok(card, 'initial items should render on load');

  card.querySelector('.price-monitor-account__menu-edit').click();
  await flushPromises();
  assert.equal(env.requests[0].options.method, 'PATCH');
  assert.match(String(env.requests[0].options.body), /4999/);

  card.querySelector('.price-monitor-account__action').click();
  await flushPromises();
  assert.equal(env.opened[0], 'about:blank');
  assert.equal(env.popup.location, 'https://savelloclub.test/cashback-go/item-1');

  card.querySelector('.price-monitor-account__menu-delete').click();
  await flushPromises();
  assert.equal(env.requests[1].options.method, 'DELETE');
  assert.equal(env.items.querySelector('.price-monitor-account__card'), null);
  assert.equal(env.items.querySelector('.price-monitor-account__empty').textContent, 'Пока нет отслеживаемых товаров');
});
