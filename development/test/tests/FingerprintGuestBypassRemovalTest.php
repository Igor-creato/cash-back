<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Группа 11b-2 ADR — удаление guest-bypass из server-side AJAX handler.
 *
 * Closes F-34-006 (server-side часть) + iter-6 (guest device_id basis).
 *
 * Контракт (TDD RED phase):
 *  1. Конструктор `Cashback_Fraud_Collector` НЕ регистрирует action
 *     `wp_ajax_nopriv_cashback_fraud_fingerprint` — гости не достигают endpoint.
 *  2. `handle_fingerprint_ajax()` при !is_user_logged_in() отвечает
 *     `wp_send_json_error('Not logged in')` и halt'ится (defense-in-depth,
 *     если nopriv-регистрация где-то восстановится).
 *  3. Код consent-проверки не содержит паттерна `$is_guest || ...` для bypass'а.
 *  4. Rate-limit и record_fingerprint не ветвятся по `$is_guest` (guest flow уничтожен).
 *  5. Regression: для logged-in с nonce всё ещё проходит (до consent-check).
 */
#[Group('security')]
#[Group('group11')]
#[Group('consent')]
class FingerprintGuestBypassRemovalTest extends TestCase
{
	private string $plugin_root;

	protected function setUp(): void
	{
		$this->plugin_root = dirname(__DIR__, 3);

		$GLOBALS['_cb_test_is_logged_in']       = false;
		$GLOBALS['_cb_test_user_id']            = 0;
		$GLOBALS['_cb_test_last_json_response'] = null;
		$GLOBALS['_cb_test_options']            = array();
		$GLOBALS['_cb_test_user_meta']          = array();
		$_POST                                  = array();

		$consent_file = $this->plugin_root . '/includes/class-cashback-fraud-consent.php';
		if (file_exists($consent_file) && !class_exists('Cashback_Fraud_Consent')) {
			require_once $consent_file;
		}
	}

	// ================================================================
	// 1. Source: nopriv registration удалён
	// ================================================================

	public function test_constructor_does_not_register_nopriv_action(): void
	{
		$path    = $this->plugin_root . '/antifraud/class-fraud-collector.php';
		$content = (string) file_get_contents($path);

		self::assertStringNotContainsString(
			'wp_ajax_nopriv_cashback_fraud_fingerprint',
			$content,
			'Гости не должны иметь доступ к AJAX endpoint — action wp_ajax_nopriv_cashback_fraud_fingerprint должен быть удалён (11b-2)'
		);
	}

	public function test_constructor_still_registers_authenticated_ajax_action(): void
	{
		// Sanity check — не удалили лишнего.
		$path    = $this->plugin_root . '/antifraud/class-fraud-collector.php';
		$content = (string) file_get_contents($path);

		self::assertStringContainsString(
			"add_action('wp_ajax_cashback_fraud_fingerprint'",
			$content,
			'Authenticated AJAX action должен остаться зарегистрирован (regression guard)'
		);
	}

	// ================================================================
	// 2. Behaviour: guest AJAX отвергается
	// ================================================================

	public function test_handler_rejects_guest_with_not_logged_in_error(): void
	{
		$GLOBALS['_cb_test_is_logged_in'] = false;
		$GLOBALS['_cb_test_user_id']      = 0;

		try {
			Cashback_Fraud_Collector::get_instance()->handle_fingerprint_ajax();
			self::fail('Ожидался halt через wp_send_json_error для гостя');
		} catch (Cashback_Test_Halt_Signal $e) {
			$response = $GLOBALS['_cb_test_last_json_response'] ?? null;
			self::assertIsArray($response, 'wp_send_json_error должен был записать JSON-ответ');
			self::assertFalse((bool) ( $response['success'] ?? true ), 'Ответ должен быть error');
			self::assertStringContainsString(
				'Not logged in',
				(string) ( $response['data'] ?? '' ),
				'Сообщение должно быть "Not logged in" для fail-closed гостя'
			);
		}
	}

	// ================================================================
	// 3. Source: consent-check без guest-bypass
	// ================================================================

	public function test_consent_check_removed_guest_bypass_pattern(): void
	{
		$path    = $this->plugin_root . '/antifraud/class-fraud-collector.php';
		$content = (string) file_get_contents($path);

		// Паттерн $consent_ok = $is_guest || ... — главный bypass, который 11b-2 убирает.
		self::assertDoesNotMatchRegularExpression(
			'/\$consent_ok\s*=\s*\$is_guest\s*\|\|/',
			$content,
			'Паттерн `$consent_ok = $is_guest || ...` должен быть удалён (11b-2 устраняет guest-bypass)'
		);
	}

	public function test_is_guest_variable_not_used_for_consent_decision(): void
	{
		// После 11b-2 файл может всё ещё упоминать `$is_guest` в legacy-коде или дебаге,
		// но в consent-контексте его не должно быть. Строгая проверка:
		// после фразы "consent" в разумном окне не должно быть $is_guest.
		$path    = $this->plugin_root . '/antifraud/class-fraud-collector.php';
		$content = (string) file_get_contents($path);

		// Если встречается $is_guest одновременно с consent_ok в одном блоке — это старый код.
		// Достаточное условие: нигде нет `$consent_ok` и `$is_guest` в соседних строках.
		// Split на block'и по 5 строк — примитив, но работает на грубую проверку.
		$matched = preg_match(
			'/\$consent_ok[\s\S]{0,200}\$is_guest|\$is_guest[\s\S]{0,200}\$consent_ok/',
			$content
		);
		self::assertSame(
			0,
			$matched,
			'$is_guest и $consent_ok не должны встречаться в одном блоке — consent-decision не должен зависеть от guest-флага'
		);
	}
}
