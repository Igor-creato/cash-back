<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Cashback_CPA_Approval_Rate_Provider::save_manual_rate (v4.4.28).
 *
 * Ручной ввод approval rate в редакторе товара для Advcake/EPN/любых сетей,
 * у которых нет `fetch_campaign_by_id` на адаптере. Те же post_meta что
 * API-режим (`_cashback_rate_of_approve` + `_fetched_at` + `_source='manual'`).
 *
 * @group shops
 * @group rate-of-approve
 * @group manual-entry
 */
#[Group('shops')]
#[Group('rate-of-approve')]
#[Group('manual-entry')]
final class CpaApprovalRateManualSaveTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        require_once dirname(__DIR__) . '/Shop_Test_Wpdb_Stub.php';
        foreach (array(
            '/includes/shops/class-cashback-shop-importer.php',
            '/includes/shops/class-cashback-shop-rate-of-approve-refresher.php',
            '/includes/shops/class-cashback-cpa-approval-rate-provider.php',
        ) as $rel) {
            $path = self::$plugin_root . $rel;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new Shop_Test_Wpdb_Stub();
        // Сеть Advcake (id=9, slug='advcake', is_active=1) — отвечает на
        // `network_supports_manual_entry` через get_var ниже.
        $wpdb->next_get_var = 'advcake';

        $GLOBALS['_cb_test_post_meta'] = array();
        $GLOBALS['_cb_test_filters']   = array();
    }

    protected function tearDown(): void
    {
        $GLOBALS['_cb_test_filters']   = array();
        $GLOBALS['_cb_test_post_meta'] = array();
    }

    private function seed_advcake_product(int $pid = 4293, string $offer_id = '972'): void
    {
        update_post_meta($pid, '_affiliate_network_id', '9');
        update_post_meta($pid, '_offer_id', $offer_id);
    }

    public function test_invalid_product_id_returns_error(): void
    {
        $r = Cashback_CPA_Approval_Rate_Provider::save_manual_rate(0, '50');
        $this->assertFalse($r['success']);
        $this->assertNull($r['rate']);
        $this->assertStringContainsString('product_id', (string) $r['error']);
    }

    public function test_product_without_network_returns_error(): void
    {
        $r = Cashback_CPA_Approval_Rate_Provider::save_manual_rate(101, '50');
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('не привязан', (string) $r['error']);
    }

    public function test_network_not_supporting_manual_returns_error(): void
    {
        // wpdb stub возвращает '' → network_supports_manual_entry=false
        global $wpdb;
        $wpdb->next_get_var = ''; // emulate inactive network
        $this->seed_advcake_product();

        $r = Cashback_CPA_Approval_Rate_Provider::save_manual_rate(4293, '50');
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('не поддерживает', (string) $r['error']);
    }

    public function test_admitad_network_does_not_support_manual_entry(): void
    {
        global $wpdb;
        $wpdb->next_get_var = 'admitad';
        $this->seed_advcake_product();

        $this->assertFalse(Cashback_CPA_Approval_Rate_Provider::network_supports_manual_entry(9));

        $r = Cashback_CPA_Approval_Rate_Provider::save_manual_rate(4293, '50');
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('не поддерживает', (string) $r['error']);
    }

    public function test_happy_path_saves_and_returns_rate(): void
    {
        $this->seed_advcake_product();

        $r = Cashback_CPA_Approval_Rate_Provider::save_manual_rate(4293, '54.0');

        $this->assertTrue($r['success']);
        $this->assertNull($r['error']);
        $this->assertSame(54.0, $r['rate']);
        $this->assertSame('manual', $r['source']);
        $this->assertIsInt($r['fetched_at']);
        $this->assertGreaterThan(0, $r['fetched_at']);
        $this->assertSame('54', get_post_meta(4293, '_cashback_rate_of_approve', true));
        $this->assertSame('manual', get_post_meta(4293, '_cashback_rate_of_approve_source', true));
    }

    public function test_empty_string_clears_post_meta(): void
    {
        $this->seed_advcake_product();
        update_post_meta(4293, '_cashback_rate_of_approve', '75.5');
        update_post_meta(4293, '_cashback_rate_of_approve_source', 'manual');
        update_post_meta(4293, '_cashback_rate_of_approve_fetched_at', '1700000000');

        $r = Cashback_CPA_Approval_Rate_Provider::save_manual_rate(4293, '');

        $this->assertTrue($r['success']);
        $this->assertNull($r['rate']);
        $this->assertNull($r['fetched_at']);
        $this->assertSame('', $r['source']);
        $this->assertSame('', (string) get_post_meta(4293, '_cashback_rate_of_approve', true));
        $this->assertSame('', (string) get_post_meta(4293, '_cashback_rate_of_approve_source', true));
        $this->assertSame('', (string) get_post_meta(4293, '_cashback_rate_of_approve_fetched_at', true));
    }

    public function test_null_clears_post_meta(): void
    {
        $this->seed_advcake_product();
        update_post_meta(4293, '_cashback_rate_of_approve', '60');

        $r = Cashback_CPA_Approval_Rate_Provider::save_manual_rate(4293, null);

        $this->assertTrue($r['success']);
        $this->assertNull($r['rate']);
        $this->assertSame('', (string) get_post_meta(4293, '_cashback_rate_of_approve', true));
    }

    public function test_non_numeric_returns_error(): void
    {
        $this->seed_advcake_product();
        $r = Cashback_CPA_Approval_Rate_Provider::save_manual_rate(4293, 'foo');
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('число', (string) $r['error']);
    }

    /**
     * @return array<string, array{0: mixed, 1: bool, 2: ?float}>
     */
    public static function range_provider(): array
    {
        return array(
            'zero edge'         => array('0', true, 0.0),
            'negative zero'     => array('-0', true, 0.0),
            'low'               => array('0.5', true, 0.5),
            'half'              => array('50', true, 50.0),
            'high'              => array('99.99', true, 99.99),
            'hundred edge'      => array('100', true, 100.0),
            'float 54.0'        => array('54.0', true, 54.0),
            'rounding 2 dec'    => array('45.555', true, 45.56),
            'negative'          => array('-0.01', false, null),
            'over 100'          => array('100.01', false, null),
            'way over'          => array('150', false, null),
            'numeric int 5'     => array(5, true, 5.0),
            'numeric float 7.5' => array(7.5, true, 7.5),
        );
    }

    /**
     * @param mixed      $input
     * @param float|null $expected_rate
     */
    #[DataProvider('range_provider')]
    public function test_range_validation(mixed $input, bool $expected_success, ?float $expected_rate): void
    {
        $this->seed_advcake_product();
        $r = Cashback_CPA_Approval_Rate_Provider::save_manual_rate(4293, $input);

        $this->assertSame($expected_success, $r['success']);
        if ($expected_success) {
            $this->assertSame($expected_rate, $r['rate']);
        } else {
            $this->assertNull($r['rate']);
            $this->assertStringContainsString('диапазон', (string) $r['error']);
        }
    }

    public function test_network_supports_manual_entry_requires_active_network(): void
    {
        global $wpdb;
        $wpdb->next_get_var = 'advcake';
        $this->assertTrue(Cashback_CPA_Approval_Rate_Provider::network_supports_manual_entry(9));

        $wpdb->next_get_var = 'epn';
        $this->assertTrue(Cashback_CPA_Approval_Rate_Provider::network_supports_manual_entry(9));

        $wpdb->next_get_var = 'admitad';
        $this->assertFalse(Cashback_CPA_Approval_Rate_Provider::network_supports_manual_entry(9));

        $wpdb->next_get_var = ''; // inactive / not found
        $this->assertFalse(Cashback_CPA_Approval_Rate_Provider::network_supports_manual_entry(9));

        $this->assertFalse(Cashback_CPA_Approval_Rate_Provider::network_supports_manual_entry(0));
        $this->assertFalse(Cashback_CPA_Approval_Rate_Provider::network_supports_manual_entry(-1));
    }
}
