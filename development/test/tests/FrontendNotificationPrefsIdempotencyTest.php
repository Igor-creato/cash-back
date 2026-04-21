<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Контрактные тесты для `notifications/class-cashback-notifications-frontend.php::handle_save_preferences` —
 * server-side дедуп request_id (Группа 5 ADR, F-39-003).
 *
 * Frontend-хендлер (нет manage_options): идемпотентность нужна на случай
 * двойного submit или retry прокси — повторный UPDATE безопасен, но даёт
 * лишний DB round-trip и потенциальные side-effects.
 */
#[Group('idempotency')]
#[Group('group-5')]
final class FrontendNotificationPrefsIdempotencyTest extends TestCase
{
    private const HANDLER_FILE = __DIR__ . '/../../../notifications/class-cashback-notifications-frontend.php';
    private const JS_FILE      = __DIR__ . '/../../../assets/js/cashback-notifications.js';

    private function method_body(): string
    {
        $src = file_get_contents(self::HANDLER_FILE);
        $this->assertIsString($src);
        if (!preg_match('/public function handle_save_preferences\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('handle_save_preferences() not found');
        }
        $start = $m[0][1];
        return substr($src, $start);
    }

    public function test_handler_extracts_request_id(): void
    {
        $body = $this->method_body();
        $this->assertStringContainsString("\$_POST['request_id']", $body);
        $this->assertStringContainsString('Cashback_Idempotency::normalize_request_id', $body);
    }

    public function test_handler_returns_stored_result_on_retry(): void
    {
        $this->assertMatchesRegularExpression(
            '/Cashback_Idempotency::get_stored_result\s*\(/',
            $this->method_body()
        );
    }

    public function test_handler_uses_frontend_scope(): void
    {
        $this->assertStringContainsString(
            "'frontend_notification_prefs_save'",
            $this->method_body()
        );
    }

    public function test_handler_uses_current_user_id_for_isolation(): void
    {
        $body = $this->method_body();

        // Scope-ключ должен включать $user_id — разные пользователи не должны
        // конфликтовать на одинаковом request_id (UUID-коллизия маловероятна,
        // но per-user isolation — canonical pattern из Cashback_Idempotency).
        $this->assertMatchesRegularExpression(
            '/\$idem_user_id\s*=\s*\$user_id/',
            $body
        );
    }

    public function test_handler_stores_result_before_success(): void
    {
        $body = $this->method_body();

        $store_pos   = strrpos($body, 'Cashback_Idempotency::store_result');
        $success_pos = strrpos($body, 'wp_send_json_success');

        $this->assertNotFalse($store_pos);
        $this->assertLessThan($success_pos, $store_pos);
    }

    public function test_handler_returns_409_on_concurrent_claim(): void
    {
        $this->assertMatchesRegularExpression(
            "/Cashback_Idempotency::claim[\s\S]{0,800}?'in_progress'[\s\S]{0,300}?\),\s*409\)/",
            $this->method_body()
        );
    }

    public function test_js_sends_request_id_via_makeRequestId(): void
    {
        $js = file_get_contents(self::JS_FILE);
        $this->assertIsString($js);

        $this->assertStringContainsString('cashback_save_notification_prefs', $js);
        $this->assertMatchesRegularExpression(
            "/request_id=.{0,50}makeRequestId\(\)/",
            $js
        );
    }
}
