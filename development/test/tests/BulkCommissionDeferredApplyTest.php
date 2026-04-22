<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * RED-тесты для Группы 8 ADR — Step 4, F-23-003.
 *
 * handle_bulk_update_commission_rate() оркестрирует:
 *   1. START TRANSACTION → UPDATE профилей → rate_history_log + write_audit_log → COMMIT
 *   2. После COMMIT — set_global_rate() через update_option()
 *
 * Риск F-23-003: option-write вне TX. Если update_option() упадёт ПОСЛЕ успешного
 * COMMIT — профили в БД с новой ставкой, `cashback_affiliate_global_rate` option
 * остался со старой → desync. Комментарий в коде декларирует «не критично», но
 * следов (error_log / audit_log) нет → админ не узнает что случилось.
 *
 * Step 4 контракт:
 *   - option-write строго ПОСЛЕ COMMIT (подтверждаем);
 *   - set_global_rate() вызывается через apply_post_commit() helper;
 *   - helper оборачивает вызов в try/catch с error_log + audit_log desync-маркером;
 *   - внутри-TX логи (rate_history + audit_log rate_update) не тронуты;
 *   - request_id-идемпотентность (Group 5) сохранена.
 *
 * Методика: source-string + regex checks (bootstrap не поднимает реальную БД).
 */
#[Group('bulk-commission-deferred')]
final class BulkCommissionDeferredApplyTest extends TestCase
{
    private const ADMIN_FILE = __DIR__ . '/../../../affiliate/class-affiliate-admin.php';

    private function read_source(): string
    {
        $src = file_get_contents(self::ADMIN_FILE);
        $this->assertIsString($src, 'Source must be readable: ' . self::ADMIN_FILE);
        return $src;
    }

    private function extract_method_body(string $src, string $method_name): string
    {
        $pos = strpos($src, 'function ' . $method_name . '(');
        $this->assertIsInt($pos, $method_name . '() method must exist in source.');

        $brace = strpos($src, '{', $pos);
        $this->assertIsInt($brace, 'Opening brace must follow signature of ' . $method_name);

        $depth = 0;
        $len   = strlen($src);
        for ($i = $brace; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }

        $this->fail('Could not find closing brace for ' . $method_name);
    }

    // ════════════════════════════════════════════════════════════════
    // 1. Внутри TX нет update_option() / set_global_rate()
    // ════════════════════════════════════════════════════════════════

    public function test_bulk_commission_no_update_option_before_commit(): void
    {
        $body = $this->extract_method_body($this->read_source(), 'handle_bulk_update_commission_rate');

        $tx_start = stripos($body, 'START TRANSACTION');
        $this->assertIsInt($tx_start, 'handle_bulk_update_commission_rate должен открывать TRANSACTION.');

        $commit_pos = strpos($body, "query('COMMIT')");
        $this->assertIsInt($commit_pos, "handle_bulk_update_commission_rate должен вызывать query('COMMIT').");

        $between = substr($body, $tx_start, $commit_pos - $tx_start);

        $this->assertDoesNotMatchRegularExpression(
            '/\bupdate_option\s*\(/',
            $between,
            'Внутри TX (между START TRANSACTION и COMMIT) не должно быть вызовов update_option() — F-23-003.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/set_global_rate\s*\(/',
            $between,
            'Внутри TX не должно быть вызовов set_global_rate() (он делает update_option() — F-23-003).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 2. set_global_rate() позиционно ПОСЛЕ COMMIT
    // ════════════════════════════════════════════════════════════════

    public function test_bulk_commission_set_global_rate_called_after_commit(): void
    {
        $body = $this->extract_method_body($this->read_source(), 'handle_bulk_update_commission_rate');

        $commit_pos = strpos($body, "query('COMMIT')");
        $set_pos    = strpos($body, 'set_global_rate(');

        $this->assertIsInt($commit_pos, "COMMIT должен присутствовать в теле метода.");
        $this->assertIsInt($set_pos, 'set_global_rate() должен вызываться в теле метода.');

        $this->assertGreaterThan(
            $commit_pos,
            $set_pos,
            'set_global_rate() должен вызываться ПОСЛЕ COMMIT (deferred-apply).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 3. apply_post_commit helper — существует и используется
    // ════════════════════════════════════════════════════════════════

    public function test_bulk_commission_uses_apply_post_commit_helper(): void
    {
        $src = $this->read_source();

        $this->assertMatchesRegularExpression(
            '/private\s+function\s+apply_post_commit\s*\(\s*callable\s+\$\w+\s*,\s*string\s+\$\w+/',
            $src,
            'Должен существовать приватный apply_post_commit(callable $fn, string $context) helper.'
        );

        $body = $this->extract_method_body($src, 'handle_bulk_update_commission_rate');

        $this->assertMatchesRegularExpression(
            '/apply_post_commit\s*\(/',
            $body,
            'handle_bulk_update_commission_rate должен использовать apply_post_commit() для set_global_rate.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 4. helper имеет try/catch + error_log
    // ════════════════════════════════════════════════════════════════

    public function test_bulk_commission_handles_post_commit_option_failure(): void
    {
        $src        = $this->read_source();
        $helper_body = $this->extract_method_body($src, 'apply_post_commit');

        $this->assertMatchesRegularExpression(
            '/\bcatch\s*\(\s*\\\\?Throwable/i',
            $helper_body,
            'apply_post_commit должен ловить \Throwable (set_global_rate могут упасть с любым Exception/Error).'
        );

        $this->assertMatchesRegularExpression(
            '/error_log\s*\(/',
            $helper_body,
            'apply_post_commit должен логировать сбой в error_log.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 5. Audit-log desync-маркер
    // ════════════════════════════════════════════════════════════════

    public function test_bulk_commission_audit_logs_desync_on_option_failure(): void
    {
        $src         = $this->read_source();
        $helper_body = $this->extract_method_body($src, 'apply_post_commit');

        $this->assertMatchesRegularExpression(
            "/Cashback_Encryption\s*::\s*write_audit_log\s*\(/",
            $helper_body,
            'apply_post_commit должен записывать audit_log на сбой (для видимости admin-у).'
        );

        // desync-маркер в названии audit-события, чтобы отличать от штатных.
        $this->assertMatchesRegularExpression(
            '/desync/i',
            $helper_body,
            "Audit-событие должно содержать маркер 'desync' — чтобы admin видел рассинхрон."
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 6. Внутри-TX логи не потеряны при рефакторе
    // ════════════════════════════════════════════════════════════════

    public function test_bulk_commission_preserves_in_tx_logs(): void
    {
        $body = $this->extract_method_body($this->read_source(), 'handle_bulk_update_commission_rate');

        $tx_start   = stripos($body, 'START TRANSACTION');
        $commit_pos = strpos($body, "query('COMMIT')");

        $between = substr($body, $tx_start, $commit_pos - $tx_start);

        $this->assertMatchesRegularExpression(
            '/Cashback_Rate_History_Admin\s*::\s*log_rate_change\s*\(/',
            $between,
            'Cashback_Rate_History_Admin::log_rate_change должен оставаться внутри TX (атомарность с UPDATE).'
        );

        $this->assertMatchesRegularExpression(
            "/Cashback_Encryption\s*::\s*write_audit_log\s*\([^)]*['\"]bulk_affiliate_commission_rate_update['\"]/s",
            $between,
            "Audit-лог 'bulk_affiliate_commission_rate_update' должен оставаться внутри TX."
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 7. Request_id-идемпотентность (Group 5 контракт) не сломан
    // ════════════════════════════════════════════════════════════════

    public function test_bulk_commission_preserves_request_id_idempotency(): void
    {
        $body = $this->extract_method_body($this->read_source(), 'handle_bulk_update_commission_rate');

        $this->assertMatchesRegularExpression(
            '/Cashback_Idempotency\s*::\s*claim\s*\(/',
            $body,
            'Cashback_Idempotency::claim() должен оставаться в handler (Group 5).'
        );
        $this->assertMatchesRegularExpression(
            '/Cashback_Idempotency\s*::\s*store_result\s*\(/',
            $body,
            'Cashback_Idempotency::store_result() должен оставаться на success-пути.'
        );
        $this->assertMatchesRegularExpression(
            '/Cashback_Idempotency\s*::\s*forget\s*\(/',
            $body,
            'Cashback_Idempotency::forget() должен оставаться на error-путях.'
        );
    }
}
