<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Group 11b-3 ADR — post-OAuth conditional consent для social-auth (VK/Yandex).
 *
 * Эволюция модели (1.x.0): pre-OAuth checkbox убран в пользу post-OAuth flow
 * (Auth0/GDPR pattern, см. Cashback_Social_Auth_Register_Bridge). Существующие
 * юзеры логинятся тихо. Новые юзеры через social-auth редиректятся на
 * стандартную register-форму /my-account/?cashback_social_register=<token>,
 * где собирают 3 явных consent-чекбокса (152-ФЗ ст. 9 + ПД+Политика + ГК 437).
 *
 * Контракт (source-grep, 1.x.0):
 *  1. `Cashback_Fraud_Consent::init()` НЕ вешает hook на `user_register` для
 *     `maybe_save_consent_for_social` — авто-consent на OAuth регистрацию удалён.
 *  2. `Cashback_Social_Auth_Account_Manager::create_pending_user_and_link()`
 *     остаётся определённым (используется branch C / email-prompt-form) и
 *     по-прежнему вызывает `Cashback_Fraud_Consent::record_consent()` при
 *     session consent_given=true.
 *  3. `Cashback_Social_Auth_Renderer::render_consent_checkbox()` всегда
 *     возвращает '' — pre-OAuth checkbox убран.
 *  4. Кнопки активны по умолчанию (build_start_url + cashback_social_consent=1
 *     добавляется к href через render_single_button).
 *  5. consent-toggle.js файл сохранён (deprecated шапка) — handle тоже
 *     зарегистрирован для обратной совместимости.
 *  6. `Cashback_Social_Auth_Account_Manager::KIND_REGISTER_VIA_SOCIAL`
 *     и `Cashback_Social_Auth_Account_Manager::REGISTER_TOKEN_PARAM`
 *     определены — Branch D в handle_callback редиректит на register-форму.
 *  7. `Cashback_Social_Auth_Register_Bridge` подключает hooks
 *     `woocommerce_register_form_start`, `woocommerce_new_customer_data`,
 *     `woocommerce_created_customer`, `woocommerce_registration_redirect`.
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
	// 4. Renderer: post-OAuth model — checkbox убран, кнопки активны
	// ================================================================

	public function test_renderer_does_not_render_pre_oauth_checkbox(): void
	{
		// Pre-OAuth consent-checkbox удалён в пользу post-OAuth conditional consent
		// (Cashback_Social_Auth_Register_Bridge собирает explicit consent на
		// стандартной register-форме после OAuth-callback'а для новых юзеров).
		$path    = $this->plugin_root . '/includes/social-auth/class-social-auth-renderer.php';
		$content = (string) file_get_contents($path);

		// render_consent_checkbox должен всегда возвращать '' (без HTML).
		$matched = preg_match(
			"/private\s+function\s+render_consent_checkbox\s*\([^)]*\)\s*:\s*string\s*\{([\s\S]*?)^\s*\}/m",
			$content,
			$m
		);
		if ($matched !== 1) {
			self::fail('Не найден render_consent_checkbox() — структура renderer\'а изменилась?');
		}
		$body = $m[1];

		self::assertDoesNotMatchRegularExpression(
			'/data-cashback-social-consent|cashback-social-consent__checkbox/',
			$body,
			'render_consent_checkbox больше не должен выводить pre-OAuth checkbox HTML'
		);
		self::assertMatchesRegularExpression(
			"/return\s+''\s*;/",
			$body,
			'render_consent_checkbox должен возвращать пустую строку (post-OAuth model, 1.x.0)'
		);
	}

	public function test_renderer_anchor_has_data_consent_href(): void
	{
		// Атрибут data-consent-href сохранён для обратной совместимости с
		// legacy consent-toggle.js (deprecated). Может быть удалён в 2.0.
		$path    = $this->plugin_root . '/includes/social-auth/class-social-auth-renderer.php';
		$content = (string) file_get_contents($path);

		self::assertStringContainsString(
			'data-consent-href',
			$content,
			'render_single_button сохраняет data-consent-href для bc legacy JS'
		);
	}

	public function test_renderer_default_anchor_href_lacks_consent_param(): void
	{
		// Базовый href, формируемый build_start_url(), не должен содержать
		// cashback_social_consent=1 — параметр добавляется отдельно
		// в render_single_button уже к итоговому URL кнопки.
		$path    = $this->plugin_root . '/includes/social-auth/class-social-auth-renderer.php';
		$content = (string) file_get_contents($path);

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
			'build_start_url НЕ должен добавлять cashback_social_consent=1 (param добавляется отдельно в render_single_button)'
		);
	}

	// ================================================================
	// 6. Post-OAuth model: Account_Manager / Register_Bridge
	// ================================================================

	public function test_account_manager_has_register_via_social_constants(): void
	{
		$path    = $this->plugin_root . '/includes/social-auth/class-social-auth-account-manager.php';
		$content = (string) file_get_contents($path);

		self::assertStringContainsString(
			'KIND_REGISTER_VIA_SOCIAL',
			$content,
			'Cashback_Social_Auth_Account_Manager должен иметь константу KIND_REGISTER_VIA_SOCIAL для post-OAuth flow'
		);
		// После 2026-05-06 (Social-auth post-OAuth consent CLOSED) flow
		// переехал с pre-OAuth checkbox + REGISTER_TOKEN_PARAM на отдельный
		// REST endpoint cashback/v1/social/register-consent-form?token=<X>.
		// Имя query-param `token` теперь не требует именованной константы;
		// инвариант дизайна — наличие самого endpoint'а.
		self::assertStringContainsString(
			'cashback/v1/social/register-consent-form',
			$content,
			'Account_Manager должен ссылаться на REST endpoint /social/register-consent-form для post-OAuth consent flow'
		);
	}

	public function test_account_manager_branch_d_redirects_to_register_form(): void
	{
		$path    = $this->plugin_root . '/includes/social-auth/class-social-auth-account-manager.php';
		$content = (string) file_get_contents($path);

		self::assertMatchesRegularExpression(
			"/'action'\s*=>\s*'redirect_register'/",
			$content,
			'Branch D handle_callback должен возвращать action=redirect_register вместо авто-создания юзера'
		);
	}

	public function test_register_consent_template_exists(): void
	{
		$path = $this->plugin_root . '/includes/social-auth/templates/register-consent.php';
		self::assertFileExists(
			$path,
			'Шаблон выделенной consent-страницы register-consent.php должен существовать (post-OAuth Branch D)'
		);
	}

	public function test_router_registers_register_consent_routes(): void
	{
		$path    = $this->plugin_root . '/includes/social-auth/class-social-auth-router.php';
		$content = (string) file_get_contents($path);

		self::assertStringContainsString(
			"'/social/register-consent-form'",
			$content,
			'Router должен регистрировать GET /social/register-consent-form (отрисовка выделенной страницы)'
		);
		self::assertStringContainsString(
			"'/social/register-consent'",
			$content,
			'Router должен регистрировать POST /social/register-consent (обработка submit)'
		);
	}

	public function test_account_manager_has_handle_register_consent_submission(): void
	{
		$path    = $this->plugin_root . '/includes/social-auth/class-social-auth-account-manager.php';
		$content = (string) file_get_contents($path);

		self::assertStringContainsString(
			'public function handle_register_consent_submission',
			$content,
			'Account_Manager::handle_register_consent_submission() обрабатывает submit формы согласий'
		);
	}

	public function test_db_has_peek_pending_method(): void
	{
		$path    = $this->plugin_root . '/includes/social-auth/class-social-auth-db.php';
		$content = (string) file_get_contents($path);

		self::assertStringContainsString(
			'public static function peek_pending',
			$content,
			'Cashback_Social_Auth_DB::peek_pending() нужен для read-only инспекции токена в Register_Bridge'
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
