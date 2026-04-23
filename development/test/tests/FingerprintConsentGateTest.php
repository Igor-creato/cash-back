<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Группа 11b-1 ADR — client-side consent gate для evercookie и purge.
 *
 * Closes F-34-006 (partial, client-side часть).
 *
 * Контракт (TDD RED phase):
 *  1. Server-side: enqueue_fingerprint_script() добавляет в wp_localize_script
 *     поля has_consent (bool) и is_guest (bool), источник has_consent —
 *     Cashback_Fraud_Consent::has_consent(). Существующие ajaxurl/nonce/debug
 *     сохраняются.
 *  2. Behaviour: authenticated user без consent meta → has_consent=false.
 *  3. Behaviour: authenticated user с consent_at + version=1.0 → has_consent=true.
 *  4. Client-side JS: функция purgeDeviceIdStorage() удаляет cb_device_id из
 *     localStorage, cookie (max-age=0), IndexedDB.
 *  5. Early guard: JS делает purge + return до вызова getOrCreateDeviceId(),
 *     если cashbackFraudFP.has_consent = false.
 *  6. Regression 11a: collectLegacyComponents, window.FingerprintJS остаются;
 *     openfpcdn.io и new Function не возвращаются.
 *
 * Запуск:
 *   cd development/test && ./vendor/bin/phpunit --filter FingerprintConsentGateTest
 */
#[Group('security')]
#[Group('group11')]
#[Group('consent')]
class FingerprintConsentGateTest extends TestCase
{
	private string $plugin_root;

	protected function setUp(): void
	{
		$this->plugin_root = dirname(__DIR__, 3);

		$GLOBALS['_cb_test_localized_scripts'] = array();
		$GLOBALS['_cb_test_enqueued_scripts']  = array();
		$GLOBALS['_cb_test_is_logged_in']      = false;
		$GLOBALS['_cb_test_is_account_page']   = false;
		$GLOBALS['_cb_test_user_id']           = 0;
		$GLOBALS['_cb_test_user_meta']         = array();
		$GLOBALS['_cb_test_options']           = array();

		// Cashback_Fraud_Consent не load-ится по умолчанию в bootstrap.
		$consent_file = $this->plugin_root . '/includes/class-cashback-fraud-consent.php';
		if (file_exists($consent_file) && !class_exists('Cashback_Fraud_Consent')) {
			require_once $consent_file;
		}
	}

	// ================================================================
	// 1. Source-grep: localize содержит has_consent и is_guest
	// ================================================================

	/**
	 * Извлекает body вызова wp_localize_script('cashback-fraud-fingerprint', ...).
	 */
	private function extract_fingerprint_localize_body(): string
	{
		$path    = $this->plugin_root . '/antifraud/class-fraud-collector.php';
		$content = (string) file_get_contents($path);

		// Матч до закрывающей ")" третьего аргумента. Array-literal может быть в форме array(...) или [...].
		$matched = preg_match(
			"/wp_localize_script\s*\(\s*'cashback-fraud-fingerprint'\s*,\s*'cashbackFraudFP'\s*,\s*(array\s*\([\s\S]*?\)|\[[\s\S]*?\])\s*\)\s*;/",
			$content,
			$m
		);

		if ($matched !== 1) {
			self::fail('Не найден вызов wp_localize_script(\'cashback-fraud-fingerprint\', \'cashbackFraudFP\', ...) — структура enqueue_fingerprint_script() изменилась?');
		}

		return (string) $m[1];
	}

	public function test_collector_localize_includes_has_consent_key(): void
	{
		$body = $this->extract_fingerprint_localize_body();
		self::assertStringContainsString(
			"'has_consent'",
			$body,
			'wp_localize_script для cashback-fraud-fingerprint должен передавать ключ has_consent (11b-1)'
		);
	}

	public function test_collector_localize_includes_is_guest_key(): void
	{
		$body = $this->extract_fingerprint_localize_body();
		self::assertStringContainsString(
			"'is_guest'",
			$body,
			'wp_localize_script для cashback-fraud-fingerprint должен передавать ключ is_guest (11b-1)'
		);
	}

	public function test_collector_sources_has_consent_from_fraud_consent_class(): void
	{
		$path    = $this->plugin_root . '/antifraud/class-fraud-collector.php';
		$content = (string) file_get_contents($path);

		self::assertStringContainsString(
			'Cashback_Fraud_Consent::has_consent',
			$content,
			'has_consent должен вычисляться через Cashback_Fraud_Consent::has_consent() — не дублировать логику'
		);
	}

	// ================================================================
	// 2. Behaviour: вызов enqueue с разными user states
	// ================================================================

	public function test_localize_has_consent_false_for_authenticated_without_meta(): void
	{
		$GLOBALS['_cb_test_is_logged_in']    = true;
		$GLOBALS['_cb_test_is_account_page'] = true;
		$GLOBALS['_cb_test_user_id']         = 42;

		Cashback_Fraud_Collector::get_instance()->enqueue_fingerprint_script();

		$localized = $GLOBALS['_cb_test_localized_scripts']['cashback-fraud-fingerprint']['cashbackFraudFP'] ?? null;
		self::assertIsArray(
			$localized,
			'cashbackFraudFP должен быть localized для authenticated user на account page'
		);
		self::assertArrayHasKey('has_consent', $localized);
		self::assertFalse(
			(bool) $localized['has_consent'],
			'Юзер без consent_at meta → has_consent=false'
		);
		self::assertArrayHasKey('is_guest', $localized);
		self::assertFalse(
			(bool) $localized['is_guest'],
			'Logged-in user → is_guest=false'
		);
	}

	public function test_localize_has_consent_true_for_user_with_fresh_consent_meta(): void
	{
		$GLOBALS['_cb_test_is_logged_in']    = true;
		$GLOBALS['_cb_test_is_account_page'] = true;
		$GLOBALS['_cb_test_user_id']         = 77;
		$GLOBALS['_cb_test_user_meta']       = array(
			77 => array(
				'cashback_fraud_consent_at'      => '2026-04-23 12:00:00',
				'cashback_fraud_consent_version' => '1.0',
			),
		);

		Cashback_Fraud_Collector::get_instance()->enqueue_fingerprint_script();

		$localized = $GLOBALS['_cb_test_localized_scripts']['cashback-fraud-fingerprint']['cashbackFraudFP'] ?? null;
		self::assertIsArray($localized);
		self::assertTrue(
			(bool) $localized['has_consent'],
			'Юзер с consent_at + version=1.0 → has_consent=true'
		);
	}

	public function test_localize_preserves_existing_ajaxurl_nonce_debug_keys(): void
	{
		$GLOBALS['_cb_test_is_logged_in']    = true;
		$GLOBALS['_cb_test_is_account_page'] = true;
		$GLOBALS['_cb_test_user_id']         = 42;

		Cashback_Fraud_Collector::get_instance()->enqueue_fingerprint_script();

		$localized = $GLOBALS['_cb_test_localized_scripts']['cashback-fraud-fingerprint']['cashbackFraudFP'] ?? null;
		self::assertIsArray($localized);
		self::assertArrayHasKey('ajaxurl', $localized, 'ajaxurl не должен пропасть из localize (backward-compat)');
		self::assertArrayHasKey('nonce', $localized, 'nonce не должен пропасть из localize (backward-compat)');
		self::assertArrayHasKey('debug', $localized, 'debug не должен пропасть из localize (backward-compat)');
	}

	// ================================================================
	// 3. Client-side JS: purge функция и ранний guard
	// ================================================================

	public function test_fraud_fingerprint_js_defines_purge_function(): void
	{
		$path    = $this->plugin_root . '/assets/js/fraud-fingerprint.js';
		$content = (string) file_get_contents($path);

		self::assertMatchesRegularExpression(
			'/function\s+purgeDeviceIdStorage\s*\(/',
			$content,
			'fraud-fingerprint.js должен определять функцию purgeDeviceIdStorage() для очистки evercookie'
		);
	}

	public function test_purge_function_clears_localstorage(): void
	{
		$path    = $this->plugin_root . '/assets/js/fraud-fingerprint.js';
		$content = (string) file_get_contents($path);

		// localStorage.removeItem в файле сейчас отсутствует — RED-условие.
		self::assertStringContainsString(
			'removeItem',
			$content,
			'purgeDeviceIdStorage должен чистить localStorage через removeItem(STORAGE_KEY)'
		);
	}

	public function test_purge_function_clears_cookie(): void
	{
		$path    = $this->plugin_root . '/assets/js/fraud-fingerprint.js';
		$content = (string) file_get_contents($path);

		// Cookie-инвалидация: либо max-age=0, либо expires в прошлом.
		self::assertMatchesRegularExpression(
			'/max-age\s*=\s*0|expires.*1970|expires.*Thu,\s*01\s*Jan/i',
			$content,
			'purgeDeviceIdStorage должен обнулять cookie (max-age=0 или expires в 1970)'
		);
	}

	public function test_purge_function_deletes_indexeddb(): void
	{
		$path    = $this->plugin_root . '/assets/js/fraud-fingerprint.js';
		$content = (string) file_get_contents($path);

		// IDB delete — в текущем файле отсутствует (только put). RED-условие.
		self::assertMatchesRegularExpression(
			'/store\.delete\s*\(|objectStore\([^)]*\)\.delete/',
			$content,
			'purgeDeviceIdStorage должен удалять ключ из IndexedDB через store.delete()'
		);
	}

	public function test_fraud_fingerprint_js_has_early_consent_guard(): void
	{
		$path    = $this->plugin_root . '/assets/js/fraud-fingerprint.js';
		$content = (string) file_get_contents($path);

		self::assertMatchesRegularExpression(
			'/!\s*cashbackFraudFP\.has_consent/',
			$content,
			'JS должен содержать !cashbackFraudFP.has_consent для early-return guard'
		);
	}

	public function test_consent_guard_runs_before_getOrCreateDeviceId(): void
	{
		$path    = $this->plugin_root . '/assets/js/fraud-fingerprint.js';
		$content = (string) file_get_contents($path);

		$guard_pos = strpos($content, 'cashbackFraudFP.has_consent');
		$call_pos  = strpos($content, 'getOrCreateDeviceId()');

		self::assertNotFalse($guard_pos, 'Consent guard (cashbackFraudFP.has_consent) не найден');
		self::assertNotFalse($call_pos, 'Вызов getOrCreateDeviceId() не найден');
		self::assertLessThan(
			$call_pos,
			$guard_pos,
			'Consent guard должен стоять ДО вызова getOrCreateDeviceId() — иначе writes происходят до проверки'
		);
	}

	// ================================================================
	// 4. Regression 11a — контракт supply chain не сломан
	// ================================================================

	public function test_regression_collect_legacy_components_still_defined(): void
	{
		$path    = $this->plugin_root . '/assets/js/fraud-fingerprint.js';
		$content = (string) file_get_contents($path);

		self::assertStringContainsString(
			'collectLegacyComponents',
			$content,
			'Regression: 11a контракт требует сохранить legacy SHA-256 fallback (collectLegacyComponents)'
		);
	}

	public function test_regression_window_fingerprintjs_still_used(): void
	{
		$path    = $this->plugin_root . '/assets/js/fraud-fingerprint.js';
		$content = (string) file_get_contents($path);

		self::assertStringContainsString(
			'window.FingerprintJS',
			$content,
			'Regression: 11a контракт требует использовать window.FingerprintJS от локального bundle'
		);
	}

	public function test_regression_no_openfpcdn_or_new_function(): void
	{
		$path    = $this->plugin_root . '/assets/js/fraud-fingerprint.js';
		$content = (string) file_get_contents($path);

		self::assertStringNotContainsString(
			'openfpcdn.io',
			$content,
			'Regression: внешний CDN не должен вернуться в fraud-fingerprint.js (11a, F-34-007)'
		);
		self::assertStringNotContainsString(
			'new Function',
			$content,
			'Regression: new Function dynamic import не должен вернуться (11a, F-34-008)'
		);
	}
}
