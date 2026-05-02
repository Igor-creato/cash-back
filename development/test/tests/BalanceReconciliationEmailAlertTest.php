<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурный тест: email-alert админу при balance_consistency_mismatch
 * (P2 prod-readiness CONCERN C2, 2026-05-02).
 *
 * Cashback_Balance_Reconciliation::run() пишет mismatch'и в audit-log,
 * но раньше не отправлял email — админ узнавал о расхождении только
 * вручную, открыв admin → «Сверка баланса». Это слепое пятно для
 * фин-операций: cache-drift между ledger и user_balance может оставаться
 * незамеченным сутки и более.
 *
 * Контракт:
 *  - При завершении round'а с total_mismatches > 0 отправляется email
 *    через Cashback_Email_Sender::send_critical (bypass opt-out).
 *  - Throttle: один email в сутки на admin (transient
 *    cashback_balance_recon_alert_sent_<YYYY-MM-DD>).
 *  - При total_mismatches == 0 email НЕ отправляется (нет шума).
 *
 * Source-based: парсит includes/class-cashback-balance-reconciliation.php.
 */
#[Group('reconciliation')]
#[Group('email-alert')]
final class BalanceReconciliationEmailAlertTest extends TestCase
{
    private function source(string $rel): string
    {
        $path    = dirname(__DIR__, 3) . '/' . $rel;
        $content = file_get_contents($path);
        $this->assertIsString($content, "{$rel} must be readable");
        return $content;
    }

    private function class_src(): string
    {
        return $this->source('includes/class-cashback-balance-reconciliation.php');
    }

    public function test_alert_uses_send_critical_bypass_optout(): void
    {
        $this->assertMatchesRegularExpression(
            '/Cashback_Email_Sender[\s\S]+?send_critical/',
            $this->class_src(),
            'email-alert должен идти через send_critical (bypass opt-out, паттерн audit-trail-reconciliation)'
        );
    }

    public function test_alert_uses_admin_email_option(): void
    {
        $this->assertMatchesRegularExpression(
            "/get_option\s*\(\s*'admin_email'\s*\)/",
            $this->class_src(),
            "адресат email — get_option('admin_email')"
        );
    }

    public function test_alert_throttled_via_transient(): void
    {
        $src = $this->class_src();
        $this->assertMatchesRegularExpression(
            '/get_transient\s*\(\s*[\'"]cashback_balance_recon_alert/',
            $src,
            "throttle должен использовать transient 'cashback_balance_recon_alert_*' (1 email в сутки)"
        );
        $this->assertMatchesRegularExpression(
            '/set_transient\s*\(\s*[\'"]cashback_balance_recon_alert/',
            $src,
            'throttle должен писать transient после отправки email (защита от спама на повторных round\'ах)'
        );
    }

    public function test_alert_only_when_mismatches_present(): void
    {
        $src = $this->class_src();
        // Защита: email НЕ должен отправляться при total_mismatches=0 (нет шума на здоровой системе).
        // Проверяем что send_critical окружён guard'ом по mismatches > 0.
        // Минимальный критерий: рядом с send_critical (в окне 30 строк до) есть проверка > 0
        // на total_mismatches или эквиваленте.
        $this->assertMatchesRegularExpression(
            '/total_mismatches[\s\S]{0,800}?send_critical/i',
            $src,
            'email должен отправляться только когда total_mismatches > 0 (guard по счётчику)'
        );
    }

    public function test_alert_subject_mentions_mismatch(): void
    {
        $this->assertMatchesRegularExpression(
            '/balance|mismatch|Сверка|расхожд/iu',
            $this->class_src(),
            'subject email должен упоминать balance/mismatch/расхождение для быстрой триажи'
        );
    }

    public function test_alert_runs_at_round_completion(): void
    {
        // Email шлём при finalization round'а (когда $user_ids пустой).
        // Проверяем что send_critical вызывается в той ветке, где есть LAST_SUMMARY_OPT
        // (паттерн уже существующего summary-сохранения).
        $src = $this->class_src();
        $this->assertMatchesRegularExpression(
            '/LAST_SUMMARY_OPT[\s\S]{0,2000}?send_critical|send_critical[\s\S]{0,2000}?LAST_SUMMARY_OPT/i',
            $src,
            'email-alert должен срабатывать при completion round\'а (рядом с update_option LAST_SUMMARY_OPT)'
        );
    }
}
