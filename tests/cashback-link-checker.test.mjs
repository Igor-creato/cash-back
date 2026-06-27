import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

// eslint-disable-next-line security/detect-non-literal-fs-filename -- Test reads a fixed repository-relative asset.
const source = await readFile(new URL('../assets/js/cashback-link-checker.js', import.meta.url), 'utf8');

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
