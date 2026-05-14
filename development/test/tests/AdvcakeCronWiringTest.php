<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Structural тесты: cron-актуализация Advcake без новых hook'ов.
 *
 * В этом окружении add_action — no-op stub (см. development/test/bootstrap.php),
 * поэтому проверка идёт двумя путями:
 *  1. **Structural** — bootstrap-файл содержит ожидаемые add_action('cashback_register_coupons_code_adapters', …)
 *     и регистрирует именно `Cashback_Advcake_Coupons_Adapter` для slug='advcake'.
 *  2. **Behavioural** — Cashback_Coupons_Adapter_Registry получает adapter и
 *     отдаёт его через get_for_network() для slug='advcake' с приоритетом
 *     над generic factory.
 *
 * Existing cron-hook константы экспонированы — fetcher (каждые 6 ч) и
 * shop-importer (daily 03:00 UTC) автоматически подхватывают Advcake.
 *
 * @group advcake
 * @group cron
 * @group wiring
 */
#[Group('advcake')]
#[Group('cron')]
#[Group('wiring')]
final class AdvcakeCronWiringTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        self::require_if_missing('/includes/class-cashback-outbound-http-guard.php', 'Cashback_Outbound_HTTP_Guard');
        self::require_if_missing('/includes/adapters/interface-cashback-network-adapter.php', null);
        self::require_if_missing('/includes/adapters/abstract-cashback-network-adapter.php', 'Cashback_Network_Adapter_Base');
        self::require_if_missing('/includes/adapters/class-cashback-advcake-adapter.php', 'Cashback_Advcake_Adapter');

        self::require_if_missing('/includes/promocodes/contracts/interface-coupons-adapter.php', 'Cashback_Coupons_Adapter_Interface');
        self::require_if_missing('/includes/promocodes/dto/class-coupon-dto.php', 'Cashback_Coupon_DTO');
        self::require_if_missing('/includes/promocodes/class-coupons-adapter-registry.php', 'Cashback_Coupons_Adapter_Registry');
        self::require_if_missing('/includes/promocodes/adapters/class-cashback-advcake-coupons-adapter.php', 'Cashback_Advcake_Coupons_Adapter');

        // Stub Cashback_API_Client — нужен конструктору Cashback_Advcake_Coupons_Adapter.
        if (!class_exists('Cashback_API_Client', false)) {
            // phpcs:ignore Squiz.PHP.Eval -- Test-only stub, no untrusted input.
            eval('class Cashback_API_Client { public static function get_instance(): self { static $i = null; if ($i === null) { $i = new self(); } return $i; } public function get_credentials(int $id): ?array { return array("api_key" => "k"); } public function get_network_config(string $slug): ?array { return array("id" => 9, "slug" => $slug, "api_base_url" => "https://api.advcake.ru"); } }');
        }

        self::require_if_missing('/includes/promocodes/class-cashback-promocodes-fetcher.php', 'Cashback_Promocodes_Fetcher');
        if (!class_exists('Cashback_Shop_Tariff_Sync')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-shop-tariff-sync.php';
        }
        if (!class_exists('Cashback_Shop_Group_Resolver')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-shop-group-resolver.php';
        }
        if (!class_exists('Cashback_Campaign_Detail_DTO')) {
            require_once self::$plugin_root . '/includes/adapters/class-cashback-campaign-detail-dto.php';
        }
        if (!class_exists('Cashback_Shop_Import_Log')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-shop-import-log.php';
        }
        if (!class_exists('Cashback_Shop_Importer')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-shop-importer.php';
        }
    }

    private static function require_if_missing(string $relative, ?string $class): void
    {
        if ($class !== null && ( class_exists($class) || interface_exists($class) )) {
            return;
        }
        $path = self::$plugin_root . $relative;
        if (!file_exists($path)) {
            self::markTestSkipped("File missing: {$relative}");
            return;
        }
        require_once $path;
    }

    private function bootstrap_source(): string
    {
        $path = self::$plugin_root . '/includes/promocodes/bootstrap-advcake-coupons.php';
        return (string) file_get_contents($path);
    }

    // ----------------------------------------------------------------------
    // Structural: bootstrap-файл подписан на правильный hook
    // ----------------------------------------------------------------------

    public function test_bootstrap_file_subscribes_to_coupons_code_adapters_hook(): void
    {
        $source = $this->bootstrap_source();
        $this->assertNotSame('', $source, 'bootstrap-advcake-coupons.php должен существовать');
        $this->assertMatchesRegularExpression(
            "/add_action\(\s*['\"]cashback_register_coupons_code_adapters['\"]/",
            $source,
            'bootstrap должен подписаться на cashback_register_coupons_code_adapters'
        );
    }

    public function test_bootstrap_registers_cashback_advcake_coupons_adapter_for_advcake_slug(): void
    {
        $source = $this->bootstrap_source();
        $this->assertMatchesRegularExpression(
            "/register_code_adapter\(\s*['\"]advcake['\"]/",
            $source,
            'bootstrap должен зарегистрировать adapter для slug=advcake'
        );
        $this->assertStringContainsString(
            'new Cashback_Advcake_Coupons_Adapter',
            $source,
            'bootstrap должен создать инстанс Cashback_Advcake_Coupons_Adapter'
        );
    }

    public function test_bootstrap_guards_against_missing_dependencies(): void
    {
        // Без guard'ов require'ы в неправильном порядке (например в WP-CLI с
        // частично загруженным плагином) вызывают fatal error. Проверяем что
        // bootstrap явно проверяет class_exists.
        $source = $this->bootstrap_source();
        $this->assertStringContainsString(
            "class_exists('Cashback_Advcake_Coupons_Adapter')",
            $source
        );
        $this->assertStringContainsString(
            "class_exists('Cashback_Advcake_Adapter')",
            $source
        );
        $this->assertStringContainsString(
            "class_exists('Cashback_API_Client')",
            $source
        );
    }

    // ----------------------------------------------------------------------
    // Behavioural: registry правильно отдаёт adapter
    // ----------------------------------------------------------------------

    public function test_registry_returns_advcake_code_adapter_with_priority_over_generic_factory(): void
    {
        $factory_called = false;
        $factory        = function () use (&$factory_called) {
            $factory_called = true;
            return null;
        };
        $registry = new Cashback_Coupons_Adapter_Registry($factory);
        $registry->register_code_adapter(
            'advcake',
            new Cashback_Advcake_Coupons_Adapter(
                new Cashback_Advcake_Adapter(),
                Cashback_API_Client::get_instance()
            )
        );

        $adapter = $registry->get_for_network(array(
            'slug'                 => 'advcake',
            'api_coupons_endpoint' => '/coupons',  // generic factory был бы пригоден
        ));

        $this->assertInstanceOf(Cashback_Advcake_Coupons_Adapter::class, $adapter);
        $this->assertSame('advcake', $adapter->get_network_slug());
        $this->assertFalse($factory_called, 'code-adapter побеждает — factory не дёргается');
    }

    public function test_registry_unregister_removes_advcake_adapter(): void
    {
        $registry = new Cashback_Coupons_Adapter_Registry();
        $registry->register_code_adapter(
            'advcake',
            new Cashback_Advcake_Coupons_Adapter(
                new Cashback_Advcake_Adapter(),
                Cashback_API_Client::get_instance()
            )
        );
        $this->assertNotNull($registry->get_for_network(array( 'slug' => 'advcake' )));

        $registry->unregister_code_adapter('advcake');
        $this->assertNull($registry->get_for_network(array( 'slug' => 'advcake' )));
    }

    // ----------------------------------------------------------------------
    // Cron-hook constants — existing hooks без новых
    // ----------------------------------------------------------------------

    public function test_promocodes_fetch_all_hook_constant_exposed(): void
    {
        // 6-часовой cron Cashback_Promocodes_Fetcher — fetcher итерирует все
        // is_active=1 сети через registry. Advcake попадает в обход после
        // bootstrap-регистрации (см. structural-тесты выше).
        $this->assertSame(
            'cashback_promocodes_fetch_all',
            Cashback_Promocodes_Fetcher::CRON_HOOK
        );
    }

    public function test_shop_importer_recurring_hook_constant_exposed(): void
    {
        // Daily 03:00 UTC import — shop-importer вызывает adapter->fetch_campaigns_detailed
        // у каждой is_active=1 сети. Advcake реализует этот метод с inline_tariffs
        // из bids (v4.3.3); дополнительный hook не нужен.
        $this->assertSame(
            'cashback_shops_import_recurring',
            Cashback_Shop_Importer::HOOK_RECURRING
        );
    }
}
