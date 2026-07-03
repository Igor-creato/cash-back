import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(
  new URL('../assets/js/cashback-price-comparison.js', import.meta.url),
  'utf8'
);

class FakeElement {
  constructor(tagName) {
    this.tagName = tagName.toUpperCase();
    this.children = [];
    this.firstChild = null;
    this.className = '';
    this.textContent = '';
    this.href = '';
    this.rel = '';
    this.value = '';
    this.readOnly = false;
    this.focused = false;
    this.selected = false;
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

  focus() {
    this.focused = true;
  }

  select() {
    this.selected = true;
  }
}

function createForm(city, query) {
  const form = new FakeElement('form');
  const cityInput = new FakeElement('input');
  const queryInput = new FakeElement('input');
  const message = new FakeElement('div');
  const results = new FakeElement('div');
  cityInput.value = city;
  queryInput.value = query;

  form.matches = (selector) => selector === '[data-price-comparison-form]';
  form.querySelector = (selector) => {
    if (selector === '[name="city"]') {
      return cityInput;
    }
    if (selector === '[name="query"]') {
      return queryInput;
    }
    if (selector === '[data-price-comparison-message]') {
      return message;
    }
    if (selector === '[data-price-comparison-results]') {
      return results;
    }
    return null;
  };

  return { form, message, results };
}

function createAccountMarkup(city, query) {
  const { form, message, results } = createForm(city, query);
  const wrapper = new FakeElement('section');

  form.querySelector = (selector) => {
    if (selector === '[name="city"]') {
      return { value: city };
    }
    if (selector === '[name="query"]') {
      return { value: query };
    }
    return null;
  };
  form.closest = (selector) => (selector === '[data-cashback-price-comparison]' ? wrapper : null);
  wrapper.querySelector = (selector) => {
    if (selector === '[data-price-comparison-message]') {
      return message;
    }
    if (selector === '[data-price-comparison-results]') {
      return results;
    }
    return null;
  };

  return { form, message, results };
}

async function flushPromises() {
  for (let i = 0; i < 12; i += 1) {
    await Promise.resolve();
  }
}

function jsonResponse(status, payload) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(payload)
  });
}

function renderer() {
  const context = {
    window: {},
    document: {
      createElement(tagName) {
        return new FakeElement(tagName);
      }
    }
  };
  context.window.window = context.window;

  vm.runInNewContext(source, context);
  return context.window.CashbackPriceComparisonRenderer;
}

test('price comparison renderer uses text nodes for product titles and buy links', () => {
  const root = new FakeElement('div');

  renderer().renderItems(root, [
    {
      title: '<img src=x onerror=alert(1)>iPhone',
      store_domain: 'ozon.ru',
      price: 80000,
      currency: 'RUB',
      action_label: 'Купить',
      action_url: 'https://ozon.ru/product/1'
    }
  ]);

  const card = root.children[0];
  assert.equal(card.children[0].tagName, 'H3');
  assert.equal(card.children[0].textContent, '<img src=x onerror=alert(1)>iPhone');
  assert.equal(card.children[2].tagName, 'A');
  assert.equal(card.children[2].textContent, 'Купить');
  assert.equal(card.children[2].href, 'https://ozon.ru/product/1');
});

test('price comparison form validates city before sending request', () => {
  let submitListener = null;
  const { form, message } = createForm('', 'iphone');
  const context = {
    window: {
      CashbackPriceComparison: {
        restUrl: 'https://savelloclub.test/wp-json/cashback/v1/price-comparison/search',
        nonce: 'nonce',
        copy: {
          emptyCity: 'Укажите город для поиска',
          emptyQuery: 'Укажите название товара',
          notFound: 'Товаров не нашлось',
          error: 'Ошибка поиска'
        }
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
    fetch() {
      throw new Error('fetch should not run for invalid city');
    }
  };
  context.window.window = context.window;

  vm.runInNewContext(source, context);
  submitListener({
    target: form,
    preventDefault() {}
  });

  assert.equal(message.textContent, 'Укажите город для поиска');
});

test('price comparison form posts to WordPress REST and renders returned items', async () => {
  let submitListener = null;
  const requests = [];
  const { form, results } = createForm('Москва', 'iphone');
  const context = {
    window: {
      CashbackPriceComparison: {
        restUrl: 'https://savelloclub.test/wp-json/cashback/v1/price-comparison/search',
        nonce: 'nonce',
        copy: {
          emptyCity: 'Укажите город для поиска',
          emptyQuery: 'Укажите название товара',
          notFound: 'Товаров не нашлось',
          error: 'Ошибка поиска'
        }
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
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({
          status: 'ok',
          items: [{
            title: 'iPhone 15',
            store_domain: 'ozon.ru',
            price: 80000,
            currency: 'RUB',
            action_label: 'Купить',
            action_url: 'https://ozon.ru/product/1'
          }],
          meta: { warnings: [] }
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

  assert.equal(requests[0].url, 'https://savelloclub.test/wp-json/cashback/v1/price-comparison/search');
  assert.equal(requests[0].options.headers['X-WP-Nonce'], 'nonce');
  assert.deepEqual(JSON.parse(requests[0].options.body), { city: 'Москва', query: 'iphone' });
  assert.equal(results.children[0].children[0].textContent, 'iPhone 15');
});

test('live search starts a run, polls status, and renders returned items', async () => {
  let submitListener = null;
  const requests = [];
  const { form, results } = createForm('Пенза', 'телевизор');
  const context = {
    window: {
      CashbackPriceComparison: {
        liveStartUrl: 'https://savelloclub.test/wp-json/cashback/v1/price-comparison/live-search',
        livePollBaseUrl: 'https://savelloclub.test/wp-json/cashback/v1/price-comparison/live-search',
        nonce: 'nonce',
        copy: {
          emptyCity: 'Укажите город для поиска',
          emptyQuery: 'Укажите название товара',
          notFound: 'Товаров не нашлось',
          error: 'Ошибка поиска',
          searching: 'Ищем в магазинах...',
          partial: 'Часть магазинов недоступна'
        }
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
      if (requests.length === 1) {
        return jsonResponse(202, { status: 'accepted', run_id: 'run_1234' });
      }
      return jsonResponse(200, {
        status: 'ok',
        items: [{
          title: 'Телевизор TCL 55C645',
          action_label: 'Купить',
          action_url: 'https://shop.test/tv'
        }],
        meta: { warnings: [] },
        store_statuses: [{ store_domain: 'fixture.test', status: 'ok' }]
      });
    }
  };
  context.window.window = context.window;

  vm.runInNewContext(source, context);
  submitListener({ target: form, preventDefault() {} });
  await flushPromises();

  assert.equal(requests[0].url, 'https://savelloclub.test/wp-json/cashback/v1/price-comparison/live-search');
  assert.equal(requests[1].url, 'https://savelloclub.test/wp-json/cashback/v1/price-comparison/live-search/run_1234');
  assert.equal(results.children[0].children[0].textContent, 'Телевизор TCL 55C645');
});

test('price comparison form renders no-results message outside the form node', async () => {
  let submitListener = null;
  const { form, message, results } = createAccountMarkup('Москва', 'iphone');
  const context = {
    window: {
      CashbackPriceComparison: {
        restUrl: 'https://savelloclub.test/wp-json/cashback/v1/price-comparison/search',
        nonce: 'nonce',
        copy: {
          emptyCity: 'Укажите город для поиска',
          emptyQuery: 'Укажите название товара',
          notFound: 'Товаров не нашлось',
          error: 'Ошибка поиска'
        }
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
    fetch() {
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({
          status: 'ok',
          items: [],
          meta: { warnings: ['Товаров не нашлось'] }
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

  assert.equal(message.textContent, 'Товаров не нашлось');
  assert.equal(results.children.length, 0);
});

test('price comparison form renders backend error message instead of no-results text', async () => {
  let submitListener = null;
  const { form, message, results } = createAccountMarkup('Пенза', 'телевизор');
  const context = {
    window: {
      CashbackPriceComparison: {
        restUrl: 'https://savelloclub.test/wp-json/cashback/v1/price-comparison/search',
        nonce: 'nonce',
        copy: {
          emptyCity: 'Укажите город для поиска',
          emptyQuery: 'Укажите название товара',
          notFound: 'Товаров не нашлось',
          error: 'Ошибка поиска'
        }
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
    fetch() {
      return Promise.resolve({
        ok: false,
        json: () => Promise.resolve({
          status: 'error',
          error_code: 'SEARCH_INDEX_EMPTY',
          message: 'Индекс поиска пуст. Запустите импорт товаров.'
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

  assert.equal(message.textContent, 'Индекс поиска пуст. Запустите импорт товаров.');
  assert.equal(results.children.length, 0);
});

test('price comparison city edit button unlocks saved city field', () => {
  let clickListener = null;
  let prevented = false;
  const input = new FakeElement('input');
  const button = new FakeElement('button');
  const wrapper = new FakeElement('section');
  input.readOnly = true;
  button.matches = (selector) => selector === '[data-price-comparison-city-edit]';
  button.closest = (selector) => (selector === '[data-cashback-price-comparison]' ? wrapper : null);
  wrapper.querySelector = (selector) => (
    selector === '[data-price-comparison-city-input]' ? input : null
  );
  const context = {
    window: {},
    document: {
      addEventListener(type, callback) {
        if (type === 'click') {
          clickListener = callback;
        }
      },
      createElement(tagName) {
        return new FakeElement(tagName);
      }
    }
  };
  context.window.window = context.window;

  vm.runInNewContext(source, context);
  clickListener({
    target: button,
    preventDefault() {
      prevented = true;
    }
  });

  assert.equal(prevented, true);
  assert.equal(input.readOnly, false);
  assert.equal(input.focused, true);
  assert.equal(input.selected, true);
});
