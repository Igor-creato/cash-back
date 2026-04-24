<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты Cashback_Balance_Issue_Renderer — UI-помощник для расшифровки
 * результатов Mariadb_Plugin::validate_user_balance_consistency.
 *
 * Покрывает:
 *  - перевод всех 9 технических issue-паттернов в русские фразы;
 *  - render_mismatch_details_html() — «бухгалтерский» вид с таблицей
 *    сравнения бакетов и разбивкой операций ledger'а;
 *  - безопасный fallback при неизвестном формате issue;
 *  - Russian number formatting (NBSP как разделитель тысяч).
 */
#[Group('ledger')]
#[Group('group-15')]
final class BalanceIssueRendererTest extends TestCase
{
    protected function setUp(): void
    {
        if ( ! class_exists( 'Cashback_Balance_Issue_Renderer' ) ) {
            $path = dirname(__DIR__, 3) . '/includes/class-cashback-balance-issue-renderer.php';
            if ( file_exists( $path ) ) {
                require_once $path;
            }
        }
    }

    // =========================================================================
    // translate_issue — все 9 паттернов из validate_user_balance_consistency
    // =========================================================================

    public function test_translates_total_balance_mismatch(): void
    {
        $this->assertTrue( class_exists( 'Cashback_Balance_Issue_Renderer' ) );
        $out = Cashback_Balance_Issue_Renderer::translate_issue(
            'total balance mismatch: ledger=22160.00, cache=93292.73 (available=92292.73, pending=0.00, paid=1000.00, frozen=0.00)'
        );
        $this->assertStringContainsString( 'Общая сумма', $out );
        $this->assertStringContainsString( '22', $out );
        $this->assertStringContainsString( '93', $out );
        $this->assertStringContainsString( 'разница', mb_strtolower( $out ) );
    }

    public function test_translates_available_balance_mismatch(): void
    {
        $out = Cashback_Balance_Issue_Renderer::translate_issue(
            'available_balance mismatch: ledger=83392.73, cache=92292.73'
        );
        $this->assertStringContainsString( 'Доступный баланс', $out );
        $this->assertStringContainsString( '83', $out );
        $this->assertStringContainsString( '92', $out );
    }

    public function test_translates_pending_balance_mismatch(): void
    {
        $out = Cashback_Balance_Issue_Renderer::translate_issue(
            'pending_balance mismatch: ledger=-62232.73, cache=0.00'
        );
        $this->assertStringContainsString( 'ожидани', mb_strtolower( $out ) );
        $this->assertStringContainsString( '−62', $out, 'Отрицательная сумма должна отображаться с типографским знаком минуса или дефисом' );
    }

    public function test_translates_paid_balance_mismatch(): void
    {
        $out = Cashback_Balance_Issue_Renderer::translate_issue(
            'paid_balance mismatch: ledger=1000.00, cache=900.00'
        );
        $this->assertStringContainsString( 'Выплачен', $out );
    }

    public function test_translates_frozen_banned_mismatch(): void
    {
        $out = Cashback_Balance_Issue_Renderer::translate_issue(
            'frozen_balance mismatch (banned): ledger(available+pending+declined)=500.00, cache frozen=100.00'
        );
        $this->assertStringContainsString( 'забан', mb_strtolower( $out ) );
        $this->assertStringContainsString( 'Заморожен', $out );
    }

    public function test_translates_frozen_regular_mismatch(): void
    {
        $out = Cashback_Balance_Issue_Renderer::translate_issue(
            'frozen_balance mismatch: ledger(declined)=50.00, cache=0.00'
        );
        $this->assertStringContainsString( 'Заморожен', $out );
    }

    public function test_translates_duplicate_accrual_entries(): void
    {
        $out = Cashback_Balance_Issue_Renderer::translate_issue(
            'duplicate accrual entries: 3 transaction_ids with multiple accruals'
        );
        $this->assertStringContainsString( 'дубл', mb_strtolower( $out ) );
        $this->assertStringContainsString( '3', $out );
    }

    public function test_translates_negative_calculated_available(): void
    {
        $out = Cashback_Balance_Issue_Renderer::translate_issue(
            'negative calculated available balance: -150.00'
        );
        $this->assertStringContainsString( 'отрицательн', mb_strtolower( $out ) );
    }

    public function test_translates_payout_complete_without_hold(): void
    {
        $out = Cashback_Balance_Issue_Renderer::translate_issue(
            'payout_complete without payout_hold: 1 payouts'
        );
        $this->assertStringContainsString( 'без', mb_strtolower( $out ) );
        $this->assertStringContainsString( 'блокиров', mb_strtolower( $out ) );
    }

    public function test_translate_issue_falls_back_to_raw_for_unknown_pattern(): void
    {
        $raw = 'some future unknown issue: blah blah';
        $out = Cashback_Balance_Issue_Renderer::translate_issue( $raw );
        $this->assertStringContainsString( $raw, $out, 'Незнакомые issue должны показываться as-is, не теряться' );
    }

    // =========================================================================
    // render_mismatch_details_html — «бухгалтерский» вид
    // =========================================================================

    private function sample_details(): array
    {
        return array(
            'issues' => array(
                'total balance mismatch: ledger=22160.00, cache=93292.73 (available=92292.73, pending=0.00, paid=1000.00, frozen=0.00)',
                'available_balance mismatch: ledger=83392.73, cache=92292.73',
                'pending_balance mismatch: ledger=-62232.73, cache=0.00',
                'payout_complete without payout_hold: 1 payouts',
            ),
            'ledger' => array(
                'available' => '83392.73',
                'pending'   => '-62232.73',
                'paid'      => '1000.00',
                'sums'      => array(
                    'accrual'         => '95592.73',
                    'payout_hold'     => '-62232.73',
                    'payout_complete' => '-1000.00',
                    'payout_cancel'   => '0.00',
                    'payout_declined' => '0.00',
                    'adjustment'      => '0.00',
                ),
                'counts'    => array(
                    'accrual'         => 5,
                    'payout_hold'     => 1,
                    'payout_complete' => 1,
                ),
            ),
            'cache'  => array(
                'available_balance' => '92292.73',
                'pending_balance'   => '0.00',
                'paid_balance'      => '1000.00',
                'frozen_balance'    => '0.00',
            ),
        );
    }

    public function test_render_html_returns_string(): void
    {
        $html = Cashback_Balance_Issue_Renderer::render_mismatch_details_html( $this->sample_details() );
        $this->assertIsString( $html );
        $this->assertNotEmpty( $html );
    }

    public function test_render_html_contains_buckets_compare_table(): void
    {
        $html = Cashback_Balance_Issue_Renderer::render_mismatch_details_html( $this->sample_details() );
        $this->assertStringContainsString( 'Доступно', $html );
        $this->assertStringContainsString( 'ожидан', mb_strtolower( $html ) );
        $this->assertStringContainsString( 'Выплачен', $html );
        $this->assertStringContainsString( 'Заморожен', $html );
        $this->assertStringContainsString( 'ИТОГО', $html );
    }

    public function test_render_html_shows_per_bucket_ledger_cache_diff(): void
    {
        $html = Cashback_Balance_Issue_Renderer::render_mismatch_details_html( $this->sample_details() );
        // Колонки: по ledger / в кэше / разница.
        $this->assertStringContainsString( 'По журналу', $html );
        $this->assertStringContainsString( 'В кэше', $html );
        $this->assertStringContainsString( 'Разница', $html );
    }

    public function test_render_html_shows_human_readable_issues(): void
    {
        $html = Cashback_Balance_Issue_Renderer::render_mismatch_details_html( $this->sample_details() );
        $this->assertStringContainsString( 'Общая сумма', $html );
        $this->assertStringContainsString( 'блокиров', mb_strtolower( $html ) );
        $this->assertStringNotContainsString( 'payout_complete without payout_hold', $html, 'Raw технические строки не должны утекать в UI' );
    }

    public function test_render_html_shows_operations_breakdown(): void
    {
        $html = Cashback_Balance_Issue_Renderer::render_mismatch_details_html( $this->sample_details() );
        $this->assertStringContainsString( 'Приход', $html );
        $this->assertStringContainsString( 'Расход', $html );
        $this->assertStringContainsString( 'Начислен', $html );
        $this->assertStringContainsString( 'Заявк', $html );
        $this->assertStringContainsString( 'Выплачен', $html );
    }

    public function test_render_html_shows_operation_counts(): void
    {
        $html = Cashback_Balance_Issue_Renderer::render_mismatch_details_html( $this->sample_details() );
        // 5 начислений кэшбэка — количество должно быть видно.
        $this->assertMatchesRegularExpression( '/5\s*(операц|опер)/ui', $html, 'Кол-во операций по типу должно показываться' );
    }

    public function test_render_html_hides_zero_operation_types(): void
    {
        $details = $this->sample_details();
        // payout_cancel = 0 операций, 0.00 ₽ — не должен захламлять вывод.
        $html = Cashback_Balance_Issue_Renderer::render_mismatch_details_html( $details );
        $this->assertStringNotContainsString( 'Отмены выплат', $html,
            'Нулевые типы операций должны скрываться, чтобы сфокусировать внимание' );
    }

    public function test_render_html_tolerates_missing_details_sections(): void
    {
        // Минимальный вход: только issues, без ledger/cache. Не должен fatal-ить.
        $html = Cashback_Balance_Issue_Renderer::render_mismatch_details_html( array(
            'issues' => array( 'duplicate accrual entries: 2 transaction_ids with multiple accruals' ),
        ) );
        $this->assertIsString( $html );
        $this->assertStringContainsString( 'дубл', mb_strtolower( $html ) );
    }

    public function test_render_html_escapes_unknown_issue_strings(): void
    {
        // Injection-защита: даже если в issue попал <script>, он должен быть эскейпнут.
        $html = Cashback_Balance_Issue_Renderer::render_mismatch_details_html( array(
            'issues' => array( '<script>alert(1)</script>' ),
        ) );
        $this->assertStringNotContainsString( '<script>', $html );
    }

    // =========================================================================
    // Payouts admin переиспользует тот же helper
    // =========================================================================

    public function test_payouts_admin_delegates_to_shared_renderer(): void
    {
        $src = file_get_contents( dirname(__DIR__, 3) . '/admin/payouts.php' );
        $this->assertIsString( $src );
        $this->assertStringContainsString(
            'Cashback_Balance_Issue_Renderer::translate_issue',
            $src,
            'admin/payouts.php должен делегировать перевод issue shared-helper\'у (DRY)'
        );
    }
}
