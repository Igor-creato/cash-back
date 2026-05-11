<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурный тест: унификация табличных стилей личного кабинета.
 *
 * Закрывает рефакторинг 2026-05-11:
 * 1) В `assets/css/cashback-account-base.css` объявлены общий компонент
 *    `.cashback-data-table` + 2 модификатора (`--boxed`, `--mobile-cards`)
 *    + 5 семантических status-классов (`cashback-status--success/info/
 *    warning/danger/neutral`).
 * 2) Все 7 таблиц данных в 5 фронтенд-модулях ЛК помечены классом
 *    `cashback-data-table` + соответствующим модификатором.
 * 3) В 4 индивидуальных CSS-файлах ЛК удалены дублирующиеся блоки
 *    (table base, mobile card-layout, status-цвета через `td.status-X`).
 *
 * Защищает от случайного восстановления дублей при будущих правках:
 * любой re-add `td.status-paid { color: ... }` в cashback-history.css
 * или удаление класса `cashback-data-table` из <table> уронит тест.
 */
#[Group('account-base')]
#[Group('css')]
final class AccountBaseDataTableStructuralTest extends TestCase
{
    private function plugin_root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function read_file(string $relative): string
    {
        $path = $this->plugin_root() . '/' . ltrim($relative, '/');
        $c    = file_get_contents($path);
        $this->assertIsString($c, "{$relative} must be readable");
        return $c;
    }

    /* ----------------------------------------------------------------
       1) Базовый CSS-файл содержит унифицированный data-table компонент
       ---------------------------------------------------------------- */

    public function test_base_css_declares_data_table_component(): void
    {
        $css = $this->read_file('assets/css/cashback-account-base.css');

        $this->assertMatchesRegularExpression(
            '/\.cashback-data-table\s*\{/',
            $css,
            'cashback-account-base.css должен объявлять базовый компонент .cashback-data-table'
        );
    }

    public function test_base_css_declares_boxed_modifier(): void
    {
        $css = $this->read_file('assets/css/cashback-account-base.css');

        $this->assertMatchesRegularExpression(
            '/\.cashback-data-table--boxed\s*\{/',
            $css,
            'cashback-account-base.css должен объявлять модификатор --boxed для notifications'
        );
    }

    public function test_base_css_declares_mobile_cards_modifier(): void
    {
        $css = $this->read_file('assets/css/cashback-account-base.css');

        $this->assertMatchesRegularExpression(
            '/\.cashback-data-table--mobile-cards\s*\{/',
            $css,
            'cashback-account-base.css должен объявлять модификатор --mobile-cards для history/payouts/affiliate/claims (mobile card-layout @media ≤768px)'
        );
    }

    public function test_base_css_declares_all_five_semantic_status_classes(): void
    {
        $css = $this->read_file('assets/css/cashback-account-base.css');

        foreach ([ 'success', 'info', 'warning', 'danger', 'neutral' ] as $semantic) {
            $this->assertMatchesRegularExpression(
                '/\.cashback-status--' . preg_quote($semantic, '/') . '\s*\{/',
                $css,
                "cashback-account-base.css должен объявлять семантический класс .cashback-status--{$semantic}"
            );
        }
    }

    /* ----------------------------------------------------------------
       2) HTML-шаблоны 5 фронтенд-модулей помечены классом + модификатором
       ---------------------------------------------------------------- */

    public function test_history_template_has_cashback_data_table_class(): void
    {
        $src = $this->read_file('cashback-history.php');

        $this->assertMatchesRegularExpression(
            '/id=["\']transactions-table["\'][^>]*class=["\'][^"\']*cashback-data-table[^"\']*cashback-data-table--mobile-cards/',
            $src,
            'cashback-history.php: <table id="transactions-table"> должен иметь классы cashback-data-table + cashback-data-table--mobile-cards'
        );
    }

    public function test_payouts_template_has_cashback_data_table_class(): void
    {
        $src = $this->read_file('history-payout.php');

        $this->assertMatchesRegularExpression(
            '/id=["\']payouts-table["\'][^>]*class=["\'][^"\']*cashback-data-table[^"\']*cashback-data-table--mobile-cards/',
            $src,
            'history-payout.php: <table id="payouts-table"> должен иметь классы cashback-data-table + cashback-data-table--mobile-cards'
        );
    }

    public function test_affiliate_template_has_cashback_data_table_class(): void
    {
        $src = $this->read_file('affiliate/class-affiliate-frontend.php');

        // Обе таблицы (accruals + referrals) — оба <table class="cashback-affiliate-table ...">
        $count = preg_match_all(
            '/<table[^>]*class=["\'][^"\']*cashback-affiliate-table[^"\']*cashback-data-table[^"\']*cashback-data-table--mobile-cards/',
            $src
        );
        $this->assertSame(
            2,
            $count,
            'affiliate/class-affiliate-frontend.php: обе таблицы (accruals + referrals) должны иметь классы cashback-data-table + cashback-data-table--mobile-cards'
        );
    }

    public function test_notifications_template_has_cashback_data_table_boxed_class(): void
    {
        $src = $this->read_file('notifications/class-cashback-notifications-frontend.php');

        $this->assertMatchesRegularExpression(
            '/<table[^>]*class=["\'][^"\']*cashback-notifications-table[^"\']*cashback-data-table[^"\']*cashback-data-table--boxed/',
            $src,
            'notifications/class-cashback-notifications-frontend.php: <table> должен иметь классы cashback-data-table + cashback-data-table--boxed'
        );
    }

    public function test_claims_template_has_cashback_data_table_class(): void
    {
        $src = $this->read_file('claims/class-claims-frontend.php');

        // Обе таблицы (clicks + claims) — render_clicks_table + render_claims_table
        $count = preg_match_all(
            '/<table[^>]*class=["\'][^"\']*cashback-data-table[^"\']*cashback-data-table--mobile-cards/',
            $src
        );
        $this->assertSame(
            2,
            $count,
            'claims/class-claims-frontend.php: обе таблицы (render_clicks_table + render_claims_table) должны иметь классы cashback-data-table + cashback-data-table--mobile-cards'
        );
    }

    /* ----------------------------------------------------------------
       3) Status-цвета установлены через cashback-status--{semantic}
          параллельно со старым status-X
       ---------------------------------------------------------------- */

    public function test_history_template_emits_semantic_status_class(): void
    {
        $src = $this->read_file('cashback-history.php');

        $this->assertMatchesRegularExpression(
            '/cashback-status--/',
            $src,
            'cashback-history.php: статусные <td> должны содержать класс cashback-status--{semantic}'
        );
    }

    public function test_payouts_template_emits_semantic_status_class(): void
    {
        $src = $this->read_file('history-payout.php');

        $this->assertMatchesRegularExpression(
            '/cashback-status--/',
            $src,
            'history-payout.php: статусные <td> должны содержать класс cashback-status--{semantic}'
        );
    }

    public function test_affiliate_template_emits_semantic_status_class(): void
    {
        $src = $this->read_file('affiliate/class-affiliate-frontend.php');

        $this->assertMatchesRegularExpression(
            '/cashback-status--/',
            $src,
            'affiliate/class-affiliate-frontend.php: <span class="cashback-affiliate-status status-X"> должны иметь cashback-status--{semantic}'
        );
    }

    /* ----------------------------------------------------------------
       4) В индивидуальных CSS-файлах удалены дублирующиеся блоки.
          Если этот тест RED — значит в файл вернули дубль, который
          теперь должен жить только в base.css.
       ---------------------------------------------------------------- */

    public function test_history_css_does_not_redeclare_table_status_colors(): void
    {
        $css = $this->read_file('assets/css/cashback-history.css');

        // Старые селекторы вида `#transactions-table tbody tr td.status-X`
        // должны быть удалены — цвета теперь через .cashback-status--Y из base.css.
        $this->assertDoesNotMatchRegularExpression(
            '/#transactions-table\s+tbody\s+tr\s+td\.status-/',
            $css,
            'cashback-history.css не должен содержать `#transactions-table tbody tr td.status-X` — status-цвета теперь через .cashback-status--{semantic} из base.css'
        );
    }

    public function test_history_css_does_not_redeclare_mobile_card_layout(): void
    {
        $css = $this->read_file('assets/css/cashback-history.css');

        $this->assertDoesNotMatchRegularExpression(
            '/#transactions-table\s+tbody\s+tr\s+td::before/',
            $css,
            'cashback-history.css не должен содержать `td::before { content: attr(data-title) }` — mobile card-layout теперь через .cashback-data-table--mobile-cards из base.css'
        );
    }

    public function test_payouts_css_does_not_redeclare_table_status_colors(): void
    {
        $css = $this->read_file('assets/css/history-payout.css');

        $this->assertDoesNotMatchRegularExpression(
            '/#payouts-table\s+tbody\s+tr\s+td\.status-/',
            $css,
            'history-payout.css не должен содержать `#payouts-table tbody tr td.status-X` — теперь через .cashback-status--{semantic} из base.css'
        );
    }

    public function test_payouts_css_does_not_redeclare_mobile_card_layout(): void
    {
        $css = $this->read_file('assets/css/history-payout.css');

        $this->assertDoesNotMatchRegularExpression(
            '/#payouts-table\s+tbody\s+tr\s+td::before/',
            $css,
            'history-payout.css не должен содержать mobile card-layout — теперь в base.css'
        );
    }

    public function test_affiliate_css_does_not_redeclare_table_base(): void
    {
        $css = $this->read_file('assets/css/affiliate-frontend.css');

        // Старый блок `.cashback-affiliate-table { width: 100%; border-collapse: ... }`
        // должен быть удалён — базовая структура теперь через .cashback-data-table из base.css.
        $this->assertDoesNotMatchRegularExpression(
            '/\.cashback-affiliate-table\s*\{[^}]*border-collapse\s*:/s',
            $css,
            'affiliate-frontend.css не должен переопределять `.cashback-affiliate-table { border-collapse: ... }` — теперь через .cashback-data-table из base.css'
        );
    }

    public function test_affiliate_css_does_not_redeclare_status_colors(): void
    {
        $css = $this->read_file('assets/css/affiliate-frontend.css');

        // Старые `.cashback-affiliate-status.status-X { color: ... }` должны быть удалены.
        $this->assertDoesNotMatchRegularExpression(
            '/\.cashback-affiliate-status\.status-/',
            $css,
            'affiliate-frontend.css не должен содержать `.cashback-affiliate-status.status-X` — теперь через .cashback-status--{semantic} из base.css'
        );
    }

    public function test_notifications_css_does_not_redeclare_table_base(): void
    {
        $css = $this->read_file('assets/css/cashback-notifications.css');

        // Старый блок `.cashback-notifications-table { ... border-radius ... box-shadow ... }`
        // должен быть удалён — boxed-стиль теперь через .cashback-data-table--boxed.
        $this->assertDoesNotMatchRegularExpression(
            '/\.cashback-notifications-table\s*\{[^}]*border-radius\s*:/s',
            $css,
            'cashback-notifications.css не должен переопределять `.cashback-notifications-table { border-radius: ... }` — теперь через .cashback-data-table--boxed из base.css'
        );
    }

    public function test_notifications_css_does_not_redeclare_table_header_bg(): void
    {
        $css = $this->read_file('assets/css/cashback-notifications.css');

        $this->assertDoesNotMatchRegularExpression(
            '/\.cashback-notifications-table\s+thead\s+th\s*\{/',
            $css,
            'cashback-notifications.css не должен переопределять header-bg — теперь через .cashback-data-table из base.css'
        );
    }
}
