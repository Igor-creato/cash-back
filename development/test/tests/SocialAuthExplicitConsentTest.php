<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Группа 11b-3 ADR — explicit consent checkbox для social-auth (VK/Yandex).
 *
 * Closes iter-11 (social-auth consent-basis).
 *
 * Контракт (source-grep для server-side refactor'а, 2026-04-23):
 *  1. `Cashback_Fraud_Consent::init()` НЕ вешает hook на `user_register` для
 *     `maybe_save_consent_for_social` — авто-consent на OAuth регистрацию удалён.
 *  2. `Cashback_Social_Auth_Router::handle_start()` требует query-param
 *     `cashback_social_consent=1`; без него — fail-closed (error/redirect).
 *  3. Session-data, сохраняемая в `handle_start`, содержит ключ `consent_given` (bool).
 *  4. `Cashback_Social_Auth_Account_Manager::create_pending_user_and_link()` вызывает
 *     `Cashback_Fraud_Consent::record_consent()` explicit'но при session consent_given=true.
 *  5. Client-side: render_single_button генерирует href БЕЗ consent-param, и
 *     атрибут data-consent-href С consent-param — JS включает OAuth только при checked
 *     checkbox, переключая href.
 *  6. render_buttons добавляет checkbox ДО массива кнопок (контекст register/login/
 *     checkout/wp_login — все register-capable; account_link исключается).
 */
#[Group('security')]
#[Group('group11')]
#[Group('consent')]
#[Group('social-auth')]
class SocialAuthExplicitConsentTest extends TestCase
{
	private string $plugin_root;

	protected function setUp(): void
	{
		$this->plugin_root = dirname(__DIR__, 3);
	}

	// ================================================================
	// 1. Cashback_Fraud_Consent: auto-hook на user_register удалён
	// ================================================================

	public function test_fraud_consent_init_does_not_hook_maybe_save_consent_for_social(): void
	{
		$path    = $this->plugin_root . '/includes/class-cashback-fraud-consent.php';
		$content = (string) file_get_contents($path);

		// Ищем init() body и проверяем что там нет add_action('user_register', ..., 'maybe_save_consent_for_social').
		$matched = preg_match(
			"/public\s+static\s+function\s+init\s*\([^)]*\)\s*:\s*void\s*\{([\s\S]*?)^\s*\}/m",
			$content,
			$m
		);

		if ($matched !== 1) {
			self::fail('Не найден init()-метод Cashback_Fraud_Consent — структура файла изменилась?');
		}

		$init_body = $m[1];
		self::assertDoesNotMatchRegularExpression(
			"/add_action\s*\(\s*'user_register'[\s\S]*?maybe_save_consent_for_social/",
			$init_body,
			'11b-3: авто-consent для OAuth через user_register priority 30 должен быть удалён (iter-11). Consent записывается explicit'
			. 'но в create_pending_user_and_link() на основании session consent_given.'
		);
	}

	// ================================================================
	// 2. Router: handle_start требует consent param
	// ================================================================

	public function test_router_handle_start_reads_consent_param(): void
	{
		$path    = $this->plugin_root . '/includes/social-auth/class-social-auth-router.php';
		$content = (string) file_get_contents($path);

		self::assertStringContainsString(
			'cashback_social_consent',
			$content,
			'handle_start должен читать параметр cashback_social_consent (11b-3)'
		);
	}

	public function test_router_handle_start_stores_consent_given_in_session(): void
	{
		$path    = $this->plugin_root . '/includes/social-auth/class-social-auth-router.php';
		$content = (string) file_get_contents($path);

		// Session data должна иметь ключ 'consent_given' при сохранении.
		self::assertMatchesRegularExpression(
			"/'consent_given'\s*=>/",
			$content,
			'handle_start должен записывать consent_given в session-data (OAuth round-trip)'
		);
	}

	// ================================================================
	// 3. Account Manager: explicit record_consent при create user
	// ================================================================

	public function test_account_manager_records_consent_from_session(): void
	{
		$path    = $this->plugin_root . '/includes/social-auth/class-social-auth-account-manager.php';
		$content = (string) file_get_contents($path);

		// Должен быть explicit вызов record_consent из create_pending_user_and_link,
		// завязанный на session consent_given flag.
		self::assertMatchesRegularExpression(
			"/Cashback_Fraud_Consent::record_consent/",
			$content,
			'create_pending_user_and_link должен вызывать Cashback_Fraud_Consent::record_consent() когда session consent_given=true'
		);

		self::assertMatchesRegularExpression(
			"/consent_given/",
			$content,
			'account-manager должен читать session consent_given flag перед записью consent'
		);
	}

	// ================================================================
	// 4. Renderer: checkbox + data-consent-href
	// ================================================================

	public function test_renderer_renders_consent_checkbox(): void
	{
		$path    = $this->plugin_root . '/includes/social-auth/class-social-auth-renderer.php';
		$content = (string) file_get_contents($path);

		self::assertMatchesRegularExpression(
			'/data-cashback-social-consent|cashback-social-consent__checkbox/',
			$content,
			'render_buttons должен вставлять checkbox с маркером data-cashback-social-consent (или CSS-class)'
		);
	}

	public function test_renderer_anchor_has_data_consent_href(): void
	{
		$path    = $this->plugin_root . '/includes/social-auth/class-social-auth-renderer.php';
		$content = (string) file_get_contents($path);

		self::assertStringContainsString(
			'data-consent-href',
			$content,
			'render_single_button должен ставить data-consent-href на <a> — JS переключит href при checked checkbox'
		);
	}

	public function test_renderer_default_anchor_href_lacks_consent_param(): void
	{
		// Базовый href не должен содержать cashback_social_consent=1 — иначе checkbox
		// теряет смысл (любой прямой GET сработает).
		$path    = $this->plugin_root . '/includes/social-auth/class-social-auth-renderer.php';
		$content = (string) file_get_contents($path);

		// build_start_url() не должен добавлять consent=1 в URL по умолчанию.
		$matched = preg_match(
			"/private\s+function\s+build_start_url\s*\([^)]*\)\s*:\s*string\s*\{([\s\S]*?)^\s*\}/m",
			$content,
			$m
		);
		if ($matched !== 1) {
			self::fail('Не найден build_start_url() — структура renderer\'а изменилась?');
		}

		$body = $m[1];
		self::assertDoesNotMatchRegularExpression(
			"/'cashback_social_consent'\s*=>\s*'?1/",
			$body,
			'build_start_url НЕ должен добавлять cashback_social_consent=1 (базовый href без consent); параметр добавляется только JS\'ом при checked checkbox'
		);
	}

	// ================================================================
	// 5. JS + enqueue
	// ================================================================

	public function test_consent_toggle_js_file_exists(): void
	{
		$path = $this->plugin_root . '/assets/social-auth/js/consent-toggle.js';
		self::assertFileExists(
			$path,
			'Новый JS-файл assets/social-auth/js/consent-toggle.js должен существовать — управляет state кнопок через checkbox'
		);
	}

	public function test_consent_toggle_js_wires_checkbox_to_buttons(): void
	{
		$path = $this->plugin_root . '/assets/social-auth/js/consent-toggle.js';
		if (!file_exists($path)) {
			self::markTestSkipped('consent-toggle.js ещё не создан (покрыт предыдущим тестом)');
		}
		$content = (string) file_get_contents($path);

		self::assertStringContainsString(
			'data-consent-href',
			$content,
			'consent-toggle.js должен читать data-consent-href для переключения href кнопок'
		);
		self::assertStringContainsString(
			'data-cashback-social-consent',
			$content,
			'consent-toggle.js должен слушать checkbox[data-cashback-social-consent]'
		);
	}

	public function test_renderer_registers_consent_toggle_script(): void
	{
		$path    = $this->plugin_root . '/includes/social-auth/class-social-auth-renderer.php';
		$content = (string) file_get_contents($path);

		self::assertStringContainsString(
			"'cashback-social-consent'",
			$content,
			'register_assets должен регистрировать handle cashback-social-consent для JS (11b-3)'
		);
	}
}
