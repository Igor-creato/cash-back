<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('frontend')]
#[Group('my-cashback-account')]
final class MyCashbackAccountTabsTest extends TestCase
{
    private function plugin_root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function read_file(string $relative): string
    {
        $path = $this->plugin_root() . '/' . ltrim($relative, '/');
        $src  = file_get_contents($path);
        $this->assertIsString($src, "{$relative} must be readable");
        return $src;
    }

    public function test_withdrawal_menu_item_is_renamed_to_my_cashback_and_collapses_old_items(): void
    {
        $src = $this->read_file('cashback-withdrawal.php');

        $this->assertStringContainsString(
            "'cashback-withdrawal'] = __('Мой кэшбэк'",
            $src,
            'The WooCommerce My Account menu label for cashback-withdrawal must be "Мой кэшбэк".'
        );
        $this->assertStringContainsString(
            "add_filter('woocommerce_account_menu_items', array( \$this, 'collapse_my_cashback_menu_items' ), 99)",
            $src,
            'The withdrawal module must register a late menu filter to hide legacy cashback menu items.'
        );

        foreach ([ 'cashback-history', 'history-payout', 'cashback_lost_cashback' ] as $legacy_key) {
            $this->assertMatchesRegularExpression(
                '/unset\(\$items\[[\'"]' . preg_quote($legacy_key, '/') . '[\'"]\]\)/',
                $src,
                "Legacy menu item {$legacy_key} must be hidden from the side navigation."
            );
        }
    }

    public function test_my_cashback_page_declares_expected_top_level_tabs_in_order(): void
    {
        $src = $this->read_file('cashback-withdrawal.php');

        preg_match_all('/class=["\'][^"\']*\bcashback-tab\b[^"\']*["\'][^>]*data-tab=["\'](tab-[^"\']+)["\']/', $src, $matches);

        $this->assertSame(
            [ 'tab-history', 'tab-withdrawal', 'tab-settings', 'tab-payouts', 'tab-lost' ],
            $matches[1],
            'Top-level My Cashback tabs must be history, withdrawal, settings, payouts, lost in that order.'
        );
        $this->assertStringContainsString("esc_html__('Мой кэшбэк'", $src);
        $this->assertStringContainsString("esc_html__('История покупок'", $src);
        $this->assertStringContainsString("esc_html__('Потерянный кэшбэк'", $src);
    }

    public function test_my_cashback_page_supports_deep_link_query_parameter(): void
    {
        $src = $this->read_file('cashback-withdrawal.php');

        $this->assertStringContainsString('cashback_tab', $src);
        foreach ([ 'history', 'withdrawal', 'settings', 'payouts', 'lost' ] as $tab) {
            $this->assertStringContainsString("'{$tab}'", $src);
        }
    }

    public function test_legacy_endpoints_remain_registered(): void
    {
        $this->assertStringContainsString(
            "add_rewrite_endpoint('cashback-history'",
            $this->read_file('cashback-history.php')
        );
        $this->assertStringContainsString(
            "add_rewrite_endpoint('history-payout'",
            $this->read_file('history-payout.php')
        );
        $this->assertStringContainsString(
            "add_rewrite_endpoint('cashback_lost_cashback'",
            $this->read_file('claims/class-claims-frontend.php')
        );
    }

    public function test_purchase_history_displays_purchase_amount_instead_of_order_number(): void
    {
        $src = $this->read_file('cashback-history.php');

        $this->assertStringContainsString('sum_order', $src);
        $this->assertStringContainsString("esc_html__('Сумма покупки'", $src);
        $this->assertStringNotContainsString("esc_html__('Номер заказа'", $src);
        $this->assertStringNotContainsString('order_number ??', $src);
    }

    public function test_history_and_payout_pagination_containers_are_unique(): void
    {
        $history = $this->read_file('cashback-history.php');
        $payouts = $this->read_file('history-payout.php');
        $history_js = $this->read_file('assets/js/cashback-history.js');
        $payouts_js = $this->read_file('assets/js/history-payout.js');

        $this->assertStringContainsString('id="history-pagination-container"', $history);
        $this->assertStringContainsString('id="payout-pagination-container"', $payouts);
        $this->assertStringContainsString('#history-pagination-container', $history_js);
        $this->assertStringContainsString('#payout-pagination-container', $payouts_js);
        $this->assertStringNotContainsString('#pagination-container', $history_js);
        $this->assertStringNotContainsString('#pagination-container', $payouts_js);
    }
}
