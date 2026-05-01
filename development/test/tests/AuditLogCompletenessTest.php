<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Структурный тест: 8 audit_log actions добавлены в соответствующие call-sites.
 *
 * Закрывает audit-log gap inventory из run-h Stage 10.12 (run-h, 2026-05-01):
 *   present=2/12 (withdrawal_created, fraud_alert_created)
 *   + Session 2 add =4/12 (user_registered, consent_granted)
 *   + Session 3 add =12/12 (этот sweep).
 *
 * Регрессионная защита: предохраняет от случайного удаления любого из 8 вызовов
 * при будущих рефакторингах. Не проверяет работу runtime — это E2E задача.
 *
 * ADR: obsidian/knowledge/decisions/audit-log-completeness.md
 */
#[Group('audit')]
#[Group('compliance')]
final class AuditLogCompletenessTest extends TestCase
{
    private static function plugin_root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read_source( string $relative_path ): string
    {
        $path = self::plugin_root() . '/' . ltrim($relative_path, '/');
        $src  = file_get_contents($path);
        if (!is_string($src)) {
            throw new RuntimeException(sprintf('Cannot read source file: %s', $path));
        }
        return $src;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     *   key   = subtest label (audit-action name)
     *   tuple = [relative file path, expected audit action name, rationale]
     */
    public static function audit_action_provider(): array
    {
        return array(
            'payout_rate_limited (BUG-01)' => array(
                'cashback-withdrawal.php',
                'payout_rate_limited',
                'cap-reject (>=3/24h) должен писать audit перед wp_send_json_error для compliance traceability',
            ),
            'banned_login_attempt (VULN-03)' => array(
                'includes/class-cashback-user-status.php',
                'banned_login_attempt',
                'block_banned_login() должен фиксировать попытку входа забаненного юзера до WP_Error (152-ФЗ ст. 9)',
            ),
            'affiliate_signup' => array(
                'affiliate/class-affiliate-service.php',
                'affiliate_signup',
                'bind_referral_on_registration после успешного UPDATE должен писать affiliate_signup в централизованный audit_log (catalog-action)',
            ),
            'referral_assigned' => array(
                'affiliate/class-affiliate-service.php',
                'referral_assigned',
                'bind_referral_on_registration после успешного UPDATE должен писать referral_assigned (sibling pair с affiliate_signup)',
            ),
            'transaction_created (F-S3-01 partial — admin path)' => array(
                'admin/class-cashback-balance-reconciliation-admin.php',
                'transaction_created',
                'admin manual stuck-claim INSERT должен писать transaction_created (catalog-action) рядом с manual_tx_from_stuck_claim. Webhook-path owned by Session 5 receiver.',
            ),
            'claim_created' => array(
                'claims/class-claims-manager.php',
                'claim_created',
                'submit_claim после do_action(cashback_claim_created) должен писать claim_created в audit_log (152-ФЗ user-action evidence)',
            ),
            'claim_approved (F-S7-AUDIT-CLAIMS)' => array(
                'claims/class-claims-manager.php',
                'claim_approved',
                'transition_status approved branch после COMMIT должен писать claim_approved (admin-action на финансовом пути)',
            ),
            'support_ticket_created (F-S10-AUDIT-SUPPORT)' => array(
                'support/user-support.php',
                'support_ticket_created',
                'handle_create_ticket после COMMIT должен писать support_ticket_created (152-ФЗ ст. 9 + audit ticketing)',
            ),
            'self_referral_attempt (VULN-05)' => array(
                'affiliate/class-affiliate-antifraud.php',
                'self_referral_attempt',
                'validate_referral self-ref branch должен писать self_referral_attempt в централизованный audit_log (hard-block evidence)',
            ),
        );
    }

    #[DataProvider('audit_action_provider')]
    public function test_audit_action_is_present( string $relative_file, string $action, string $rationale ): void
    {
        $src = self::read_source($relative_file);

        $pattern = sprintf(
            '/Cashback_Encryption::write_audit_log\s*\(\s*[\'"]%s[\'"]/s',
            preg_quote($action, '/')
        );

        $this->assertMatchesRegularExpression(
            $pattern,
            $src,
            sprintf(
                '%s — call-site должен содержать Cashback_Encryption::write_audit_log("%s", ...). %s',
                $relative_file,
                $action,
                $rationale
            )
        );
    }

    /**
     * Все 8 новых вызовов обёрнуты в try/Throwable per G2 (ADR audit-log-completeness §G2).
     * Audit — telemetry, не должен ронять основной flow при DB-сбое.
     *
     * Heuristic: ищем `try {` ... `Cashback_Encryption::write_audit_log('action', ...)` ... `catch (\Throwable`.
     */
    #[DataProvider('audit_action_provider')]
    public function test_audit_call_is_wrapped_in_try_throwable( string $relative_file, string $action, string $rationale ): void
    {
        $src = self::read_source($relative_file);

        $pattern = sprintf(
            '/try\s*\{[^{}]*?Cashback_Encryption::write_audit_log\s*\(\s*[\'"]%s[\'"][\s\S]*?\}\s*catch\s*\(\s*\\\\?Throwable/s',
            preg_quote($action, '/')
        );

        $this->assertSame(
            1,
            preg_match($pattern, $src),
            sprintf(
                '%s: вызов write_audit_log("%s", ...) должен быть в try { ... } catch (\\Throwable $e) { ... } per G2 ADR audit-log-completeness — failed audit не должен ронять основной flow.',
                $relative_file,
                $action
            )
        );
    }
}
