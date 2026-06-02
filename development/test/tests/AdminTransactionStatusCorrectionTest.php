<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Контракт безопасной ручной корректировки ошибочного CPA decline.
 *
 * Обычный редактор транзакций не должен разрешать declined -> waiting:
 * переход блокируется MariaDB trigger'ом. Для подтверждённых админом случаев
 * нужна отдельная узкая операция с причиной, аудитом и scoped trigger bypass.
 */
#[Group('admin')]
#[Group('transactions')]
#[Group('status-correction')]
final class AdminTransactionStatusCorrectionTest extends TestCase
{
    private const PHP_FILE = __DIR__ . '/../../../admin/transactions.php';
    private const JS_FILE  = __DIR__ . '/../../../assets/js/admin-transactions.js';
    private const API_PHP_FILE = __DIR__ . '/../../../admin/class-cashback-admin-api-validation.php';
    private const API_JS_FILE  = __DIR__ . '/../../../admin/js/api-validation.js';

    private string $source = '';

    protected function setUp(): void
    {
        $src = file_get_contents(self::PHP_FILE);
        self::assertIsString($src);
        $this->source = $src;
    }

    private function method_body( string $method_name ): string
    {
        $pattern = '/public\s+function\s+' . preg_quote($method_name, '/')
            . '\s*\([^)]*\)\s*:\s*void\s*\{([\s\S]*?)^\s{4}\}/m';

        if (preg_match($pattern, $this->source, $m) !== 1) {
            self::fail("Метод {$method_name}() не найден в admin/transactions.php");
        }

        return $m[1];
    }

    public function test_ajax_action_is_registered_with_dedicated_nonce(): void
    {
        self::assertStringContainsString(
            "add_action('wp_ajax_cashback_correct_declined_transaction_status'",
            $this->source
        );
        self::assertStringContainsString(
            "'statusCorrectionNonce' => wp_create_nonce('cashback_correct_transaction_status_nonce')",
            $this->source
        );
    }

    public function test_correction_handler_requires_capability_nonce_and_reason(): void
    {
        $body = $this->method_body('handle_correct_declined_transaction_status');

        self::assertStringContainsString("wp_verify_nonce", $body);
        self::assertStringContainsString("'cashback_correct_transaction_status_nonce'", $body);
        self::assertStringContainsString("current_user_can('manage_options')", $body);
        self::assertStringContainsString("correction_reason", $body);
        self::assertMatchesRegularExpression('/mb_strlen\s*\(\s*\$reason\s*\)\s*<\s*self::MIN_STATUS_CORRECTION_REASON_LENGTH/', $body);
    }

    public function test_correction_handler_allows_only_declined_to_waiting_before_ledger(): void
    {
        $body = $this->method_body('handle_correct_declined_transaction_status');

        self::assertStringContainsString("order_status = %s", $body);
        self::assertStringContainsString("'declined'", $body);
        self::assertStringContainsString("'waiting'", $body);
        self::assertStringContainsString('processed_at', $body);
        self::assertStringContainsString('already_accrued', $body);
        self::assertStringNotContainsString("order_status = 'completed'", $body);
    }

    public function test_correction_handler_uses_session_scoped_trigger_bypass(): void
    {
        $body = $this->method_body('handle_correct_declined_transaction_status');

        self::assertStringContainsString('START TRANSACTION', $body);
        self::assertMatchesRegularExpression('/FOR\s+UPDATE/i', $body);
        self::assertStringContainsString('@cashback_allow_declined_to_waiting_tx_id', $body);
        self::assertStringContainsString('recreate_status_transition_trigger', $body);
        self::assertStringContainsString('CREATE OR REPLACE TRIGGER', $this->source);
        self::assertStringContainsString('finally', $body);
        self::assertStringNotContainsString('DROP TRIGGER', $body);
    }

    public function test_correction_handler_writes_audit_log_with_reason(): void
    {
        $body = $this->method_body('handle_correct_declined_transaction_status');

        self::assertStringContainsString("'transaction_status_correction'", $body);
        self::assertStringContainsString("'old_status' => 'declined'", $body);
        self::assertStringContainsString("'new_status' => 'waiting'", $body);
        self::assertStringContainsString("'reason'     => \$reason", $body);
        self::assertStringContainsString('Cashback_Encryption::write_audit_log', $body);
    }

    public function test_js_exposes_button_and_posts_reason(): void
    {
        $js = file_get_contents(self::JS_FILE);
        self::assertIsString($js);

        self::assertStringContainsString('correct-status-btn', $js);
        self::assertStringContainsString("action: 'cashback_correct_declined_transaction_status'", $js);
        self::assertStringContainsString('statusCorrectionNonce', $js);
        self::assertStringContainsString('correction_reason', $js);
    }

    public function test_api_validation_ui_can_call_same_status_correction_action(): void
    {
        $php = file_get_contents(self::API_PHP_FILE);
        $js  = file_get_contents(self::API_JS_FILE);
        self::assertIsString($php);
        self::assertIsString($js);

        self::assertStringContainsString("'statusCorrectionNonce' => wp_create_nonce('cashback_correct_transaction_status_nonce')", $php);
        self::assertStringContainsString('cashback-correct-status-btn', $js);
        self::assertStringContainsString("action: 'cashback_correct_declined_transaction_status'", $js);
        self::assertStringContainsString('config.statusCorrectionNonce', $js);
        self::assertStringContainsString('correction_reason', $js);
    }
}
