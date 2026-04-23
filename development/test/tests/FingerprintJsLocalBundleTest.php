<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Группа 11a ADR — supply-chain hardening FingerprintJS.
 *
 * Closes F-34-007 (external CDN openfpcdn.io) и F-34-008 (`new Function` dynamic import).
 *
 * Контракт (TDD RED phase):
 *  1. Локальный bundle FingerprintJS v3.4.2 (MIT) присутствует: assets/vendor/fingerprintjs/fp.min.js.
 *     v3 выбран, потому что v4.x под BSL 1.1 (commercial-restricted). API идентичен для нашего use-case.
 *  2. MIT LICENSE и README с SHA-256 checksum в assets/vendor/fingerprintjs/.
 *  3. assets/js/fraud-fingerprint.js НЕ ссылается на openfpcdn.io и НЕ использует new Function.
 *  4. fraud-fingerprint.js сохраняет legacy SHA-256 fallback (обратная совместимость не ломается).
 *  5. antifraud/class-fraud-collector.php enqueue-ит handle 'cashback-fingerprintjs' с версией '3.4.2',
 *     src → assets/vendor/fingerprintjs/fp.min.js.
 *  6. Handle 'cashback-fraud-fingerprint' имеет 'cashback-fingerprintjs' в зависимостях.
 *
 * Запуск:
 *   cd development/test && ./vendor/bin/phpunit --filter FingerprintJsLocalBundleTest
 */
#[Group('security')]
#[Group('group11')]
#[Group('supply-chain')]
class FingerprintJsLocalBundleTest extends TestCase
{
	private string $plugin_root;

	protected function setUp(): void
	{
		$this->plugin_root = dirname(__DIR__, 3);

		$GLOBALS['_cb_test_enqueued_scripts'] = array();
	}

	// ================================================================
	// 1. Локальный bundle FingerprintJS v4.5.1
	// ================================================================

	public function test_fingerprintjs_bundle_file_exists(): void
	{
		$path = $this->plugin_root . '/assets/vendor/fingerprintjs/fp.min.js';
		self::assertFileExists(
			$path,
			'Локальный bundle FingerprintJS должен лежать в assets/vendor/fingerprintjs/fp.min.js (supply-chain: убираем внешний CDN)'
		);
	}

	public function test_fingerprintjs_bundle_is_not_empty(): void
	{
		$path = $this->plugin_root . '/assets/vendor/fingerprintjs/fp.min.js';
		if (!file_exists($path)) {
			self::fail('fp.min.js ещё не скопирован из npm-пакета — нельзя проверить размер');
		}
		self::assertGreaterThan(
			10000,
			(int) filesize($path),
			'fp.min.js должен быть нетривиальным файлом (FingerprintJS v3 UMD bundle ≈ 30+ KB)'
		);
	}

	public function test_fingerprintjs_bundle_looks_like_fingerprintjs_v3(): void
	{
		$path = $this->plugin_root . '/assets/vendor/fingerprintjs/fp.min.js';
		if (!file_exists($path)) {
			self::fail('fp.min.js ещё не скопирован — нельзя проверить содержимое');
		}
		$head = (string) file_get_contents($path, false, null, 0, 2048);

		// Подписи FingerprintJS OSS v4 в верхнем комментарии/строке экспорта.
		// Проверяем на любой из нескольких маркеров — сам файл минифицирован.
		$marker_found = (
			stripos($head, 'FingerprintJS') !== false
			|| stripos($head, 'fpjs') !== false
			|| stripos($head, '@fingerprintjs/fingerprintjs') !== false
		);
		self::assertTrue(
			$marker_found,
			'fp.min.js должен содержать маркер FingerprintJS в первых 2KB (ожидается "FingerprintJS" или "@fingerprintjs/fingerprintjs")'
		);
	}

	public function test_fingerprintjs_license_file_exists(): void
	{
		$path = $this->plugin_root . '/assets/vendor/fingerprintjs/LICENSE';
		self::assertFileExists(
			$path,
			'LICENSE файл FingerprintJS OSS (MIT) должен быть включён рядом с bundle'
		);
	}

	public function test_fingerprintjs_license_is_mit(): void
	{
		$path = $this->plugin_root . '/assets/vendor/fingerprintjs/LICENSE';
		if (!file_exists($path)) {
			self::fail('LICENSE не найден — невозможно проверить тип лицензии');
		}
		$content = (string) file_get_contents($path);
		// FingerprintJS v3.x — MIT. Upstream v3 LICENSE ссылается на MIT текст через
		// «Permission is hereby granted, free of charge» (классический MIT-шаблон).
		self::assertStringContainsString(
			'Permission is hereby granted, free of charge',
			$content,
			'FingerprintJS v3.x распространяется под MIT. v4.x под BSL 1.1 (commercial-restricted) — не берём.'
		);
	}

	public function test_fingerprintjs_readme_documents_version_and_checksum(): void
	{
		$path = $this->plugin_root . '/assets/vendor/fingerprintjs/README.md';
		self::assertFileExists(
			$path,
			'README с pinned версией и SHA-256 bundle\'а должен быть рядом с fp.min.js'
		);
		$content = (string) file_get_contents($path);

		self::assertStringContainsString(
			'3.4.2',
			$content,
			'README должен фиксировать версию FingerprintJS (3.4.2 — последняя стабильная v3 под MIT; v4 под BSL 1.1)'
		);

		// SHA-256 — 64 hex-символа.
		self::assertMatchesRegularExpression(
			'/[a-f0-9]{64}/i',
			$content,
			'README должен содержать SHA-256 checksum fp.min.js (supply-chain: фиксируем integrity bundle\'а)'
		);
	}

	// ================================================================
	// 2. fraud-fingerprint.js: убрать внешний CDN и new Function
	// ================================================================

	public function test_fraud_fingerprint_js_does_not_reference_external_cdn(): void
	{
		$path = $this->plugin_root . '/assets/js/fraud-fingerprint.js';
		self::assertFileExists($path);
		$content = (string) file_get_contents($path);

		self::assertStringNotContainsString(
			'openfpcdn.io',
			$content,
			'F-34-007: внешний CDN (openfpcdn.io) должен быть выпилен — bundle грузится локально'
		);
	}

	public function test_fraud_fingerprint_js_does_not_use_new_function(): void
	{
		$path = $this->plugin_root . '/assets/js/fraud-fingerprint.js';
		self::assertFileExists($path);
		$content = (string) file_get_contents($path);

		// new Function('u', 'return import(u)') — обход статического CSP-анализа.
		self::assertStringNotContainsString(
			'new Function',
			$content,
			'F-34-008: new Function(...) dynamic import должен быть заменён на глобал window.FingerprintJS (CSP-safe)'
		);
	}

	public function test_fraud_fingerprint_js_uses_global_fingerprintjs(): void
	{
		$path = $this->plugin_root . '/assets/js/fraud-fingerprint.js';
		self::assertFileExists($path);
		$content = (string) file_get_contents($path);

		// После рефакторинга — читаем глобал, который кладёт WP enqueue.
		self::assertStringContainsString(
			'window.FingerprintJS',
			$content,
			'fraud-fingerprint.js должен использовать window.FingerprintJS (bundle enqueue-ится как зависимость через WP)'
		);
	}

	public function test_fraud_fingerprint_js_retains_legacy_fallback(): void
	{
		$path = $this->plugin_root . '/assets/js/fraud-fingerprint.js';
		self::assertFileExists($path);
		$content = (string) file_get_contents($path);

		// Backward-compat: legacy SHA-256 path не удаляется. Ключевая функция — collectLegacyComponents.
		self::assertStringContainsString(
			'collectLegacyComponents',
			$content,
			'Legacy SHA-256 fingerprint функция должна остаться для fallback (backward-compat с check_shared_fingerprint в Cashback_Fraud_Detector)'
		);
	}

	// ================================================================
	// 3. Cashback_Fraud_Collector регистрирует локальный handle
	// ================================================================

	public function test_fraud_collector_enqueues_cashback_fingerprintjs_handle(): void
	{
		$path = $this->plugin_root . '/antifraud/class-fraud-collector.php';
		self::assertFileExists($path);
		$content = (string) file_get_contents($path);

		self::assertStringContainsString(
			"'cashback-fingerprintjs'",
			$content,
			'Cashback_Fraud_Collector должен регистрировать handle "cashback-fingerprintjs" для локального bundle'
		);

		self::assertStringContainsString(
			'assets/vendor/fingerprintjs/fp.min.js',
			$content,
			'enqueue должен указывать src на локальный bundle assets/vendor/fingerprintjs/fp.min.js'
		);

		self::assertStringContainsString(
			"'3.4.2'",
			$content,
			'enqueue должен пинить версию FingerprintJS = 3.4.2 (MIT; supply-chain: версия видимая, не "v4"-алиас)'
		);
	}

	public function test_fraud_fingerprint_script_depends_on_fingerprintjs_handle(): void
	{
		$path = $this->plugin_root . '/antifraud/class-fraud-collector.php';
		self::assertFileExists($path);
		$content = (string) file_get_contents($path);

		// После рефакторинга блок wp_enqueue_script('cashback-fraud-fingerprint', ...) должен иметь
		// 'cashback-fingerprintjs' в массиве зависимостей. Проверяем в нормализованном виде,
		// чтобы перенос строк не ломал матчер.
		$normalized = (string) preg_replace('/\s+/', ' ', $content);

		self::assertMatchesRegularExpression(
			"#wp_enqueue_script\s*\(\s*'cashback-fraud-fingerprint'.*?'cashback-fingerprintjs'#",
			$normalized,
			'Handle "cashback-fraud-fingerprint" должен иметь "cashback-fingerprintjs" в $deps (порядок загрузки: сначала bundle, потом наш wrapper)'
		);
	}
}
