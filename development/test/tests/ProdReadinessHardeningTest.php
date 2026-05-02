<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты для prod-readiness CONCERN C3 + C4 (2026-05-02).
 *
 * C3: DOMPurify enqueue (Cashback_Assets::enqueue_safe_html()) на трёх
 *     admin-страницах с балансом / транзакциями. Defense-in-depth: даже
 *     при будущем рефакторе с client-side template'ами raw HTML не попадёт
 *     в DOM (fail-closed возврат '' при отсутствии DOMPurify).
 *
 * C4: Key rotation rollback window 7 → 14 дней. Стандартный фин-операций
 *     incident-response window. Override через константу
 *     CASHBACK_KEY_ROTATION_PREVIOUS_TTL_DAYS (wp-config / MU-plugin).
 */
#[Group('prod-readiness')]
final class ProdReadinessHardeningTest extends TestCase
{
    private function source(string $rel): string
    {
        $path    = dirname(__DIR__, 3) . '/' . $rel;
        $content = file_get_contents($path);
        $this->assertIsString($content, "{$rel} must be readable");
        return $content;
    }

    // =========================================================================
    // C3 — DOMPurify enqueue на admin-страницах
    // =========================================================================

    public function test_c3_balance_reconciliation_admin_enqueues_safe_html(): void
    {
        $this->assertMatchesRegularExpression(
            '/Cashback_Assets::enqueue_safe_html\s*\(/',
            $this->source('admin/class-cashback-balance-reconciliation-admin.php'),
            'admin/class-cashback-balance-reconciliation-admin.php должен подключать enqueue_safe_html для defense-in-depth'
        );
    }

    public function test_c3_users_management_enqueues_safe_html_on_balance_adjust(): void
    {
        $this->assertMatchesRegularExpression(
            '/Cashback_Assets::enqueue_safe_html\s*\(/',
            $this->source('admin/users-management.php'),
            'admin/users-management.php должен подключать enqueue_safe_html (modal balance-adjust)'
        );
    }

    public function test_c3_transactions_admin_enqueues_safe_html(): void
    {
        $this->assertMatchesRegularExpression(
            '/Cashback_Assets::enqueue_safe_html\s*\(/',
            $this->source('admin/transactions.php'),
            'admin/transactions.php должен подключать enqueue_safe_html (S3 confirm-flow с balance-adjust modal)'
        );
    }

    // =========================================================================
    // C4 — Key rotation rollback window 14 дней + override
    // =========================================================================

    public function test_c4_rollback_window_default_14_days(): void
    {
        $this->assertMatchesRegularExpression(
            '/ROLLBACK_WINDOW_DAYS\s*=\s*14\b/',
            $this->source('admin/class-cashback-key-rotation.php'),
            'ROLLBACK_WINDOW_DAYS должна быть = 14 (industry-standard incident-response window)'
        );
    }

    public function test_c4_supports_override_via_constant(): void
    {
        $src = $this->source('admin/class-cashback-key-rotation.php');
        $this->assertMatchesRegularExpression(
            "/defined\s*\(\s*'CASHBACK_KEY_ROTATION_PREVIOUS_TTL_DAYS'\s*\)/",
            $src,
            "должен быть override через константу CASHBACK_KEY_ROTATION_PREVIOUS_TTL_DAYS"
        );
    }

    public function test_c4_resolver_method_is_used_in_finalize(): void
    {
        $src = $this->source('admin/class-cashback-key-rotation.php');
        // Контракт: вместо self::ROLLBACK_WINDOW_DAYS * DAY_IN_SECONDS должен
        // использоваться resolver-метод (например, rollback_window_days()), который
        // учитывает override-константу.
        $this->assertMatchesRegularExpression(
            '/rollback_window_days\s*\(\s*\)/',
            $src,
            'finalize/cleanup должны вычислять окно через rollback_window_days() resolver'
        );
    }
}
