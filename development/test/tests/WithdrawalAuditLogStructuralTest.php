<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурный тест: withdrawal-create обязан писать audit_log запись.
 *
 * Закрывает OBS-07 (E2E run B 2026-04-30, P1 compliance gap):
 * после успешного INSERT в `cashback_payout_requests` и COMMIT транзакции
 * хендлер обязан вызвать `Cashback_Encryption::write_audit_log('withdrawal_created', ...)`,
 * иначе финансовая операция остаётся без следа в audit_log
 * (нарушение требований 152/161-ФЗ + ЦБ).
 *
 * Source-based regression: предохраняет от случайного удаления вызова
 * при будущих рефакторингах. Не проверяет рабочее состояние БД — это
 * задача E2E + intgeration тестов (AuditTrailReconciliationTest и cron).
 */
#[Group('withdrawal')]
#[Group('audit')]
final class WithdrawalAuditLogStructuralTest extends TestCase
{
    private function withdrawal_source(): string
    {
        $path = dirname(__DIR__, 3) . '/cashback-withdrawal.php';
        $c    = file_get_contents($path);
        $this->assertIsString($c, 'cashback-withdrawal.php must be readable');
        return $c;
    }

    public function test_process_cashback_withdrawal_writes_audit_log_on_success(): void
    {
        $src = $this->withdrawal_source();

        $this->assertMatchesRegularExpression(
            '/Cashback_Encryption::write_audit_log\s*\(\s*[\'"]withdrawal_created[\'"]/s',
            $src,
            'process_cashback_withdrawal должен вызывать Cashback_Encryption::write_audit_log("withdrawal_created", ...) для compliance audit-trail (OBS-07)'
        );
    }

    public function test_audit_log_call_is_after_commit(): void
    {
        $src = $this->withdrawal_source();

        // Извлекаем тело process_cashback_withdrawal от COMMIT до конца try-блока
        $this->assertSame(
            1,
            preg_match(
                '/\$commit_result\s*=\s*\$wpdb->query\(\s*[\'"]COMMIT[\'"]\s*\)\s*;\s*'
                . '.*?'
                . 'Cashback_Encryption::write_audit_log\s*\(\s*[\'"]withdrawal_created[\'"]/s',
                $src
            ),
            'write_audit_log("withdrawal_created", ...) должен вызываться ПОСЛЕ COMMIT, чтобы failed audit не откатил payout (cron-сверка ловит orphan-ledger как defense-in-depth)'
        );
    }

    public function test_audit_log_call_passes_payout_request_entity(): void
    {
        $src = $this->withdrawal_source();

        $this->assertMatchesRegularExpression(
            '/Cashback_Encryption::write_audit_log\s*\([^)]*[\'"]withdrawal_created[\'"][^)]*[\'"]payout_request[\'"]/s',
            $src,
            'audit-запись withdrawal_created должна содержать entity_type="payout_request" — нужно для cron-сверки матча ledger.payout_request_id ↔ audit.entity_id'
        );
    }
}
