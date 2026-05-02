<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты на Cashback_Coupons_Adapter_Registry — выбирает адаптер для сети
 * с приоритетом code-adapter > generic-adapter.
 *
 * @group promocodes
 * @group adapters
 */
#[Group('promocodes')]
#[Group('adapters')]
final class CouponsAdapterRegistryTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
        $files = array(
            '/includes/promocodes/contracts/interface-coupons-adapter.php',
            '/includes/promocodes/dto/class-coupon-dto.php',
            '/includes/promocodes/class-coupons-adapter-registry.php',
        );
        foreach ($files as $f) {
            $path = self::$plugin_root . $f;
            if (!file_exists($path)) {
                self::markTestSkipped("File missing: {$f}");
            }
            require_once $path;
        }
    }

    private function make_network_config(string $slug, ?string $coupons_endpoint = null): object
    {
        return (object) array(
            'id'                   => 1,
            'slug'                 => $slug,
            'name'                 => ucfirst($slug),
            'api_coupons_endpoint' => $coupons_endpoint,
        );
    }

    private function make_fake_adapter(string $slug): Cashback_Coupons_Adapter_Interface
    {
        return new class($slug) implements Cashback_Coupons_Adapter_Interface {
            public function __construct(private string $slug) {}
            public function get_network_slug(): string { return $this->slug; }
            public function fetch_coupons( string $advcampaign_id, array $context = array() ): array { return array(); }
            public function supports_campaign_filter(): bool { return true; }
            public function get_required_scope(): ?string { return null; }
        };
    }

    /**
     * Stub factory: возвращает fake-адаптер с тем же slug что и в config.
     */
    private function generic_factory_stub(): callable
    {
        return function ($config) {
            $slug = is_object($config) ? $config->slug : ($config['slug'] ?? '');
            return $this->make_fake_adapter($slug);
        };
    }

    public function test_returns_null_without_factory_and_without_code_adapter(): void
    {
        $registry = new Cashback_Coupons_Adapter_Registry();
        $config   = $this->make_network_config('unknown', null);

        $this->assertNull($registry->get_for_network($config));
    }

    public function test_returns_null_when_no_code_adapter_and_no_generic_endpoint(): void
    {
        $registry = new Cashback_Coupons_Adapter_Registry($this->generic_factory_stub());
        $config   = $this->make_network_config('unknown', null);

        $this->assertNull(
            $registry->get_for_network($config),
            'Без code-adapter и без api_coupons_endpoint — null даже с factory'
        );
    }

    public function test_returns_generic_when_endpoint_set_and_no_code_adapter(): void
    {
        $registry = new Cashback_Coupons_Adapter_Registry($this->generic_factory_stub());
        $config   = $this->make_network_config('admitad', '/coupons/?campaign={advcampaign_id}');

        $adapter = $registry->get_for_network($config);
        $this->assertInstanceOf(Cashback_Coupons_Adapter_Interface::class, $adapter);
        $this->assertSame('admitad', $adapter->get_network_slug());
    }

    public function test_code_adapter_takes_priority_over_generic(): void
    {
        $registry = new Cashback_Coupons_Adapter_Registry($this->generic_factory_stub());
        $custom   = $this->make_fake_adapter('admitad');

        $registry->register_code_adapter('admitad', $custom);

        $config  = $this->make_network_config('admitad', '/coupons/?campaign={advcampaign_id}');
        $adapter = $registry->get_for_network($config);

        $this->assertSame($custom, $adapter, 'Code-adapter должен иметь приоритет над generic');
    }

    public function test_code_adapter_returned_even_without_endpoint(): void
    {
        $registry = new Cashback_Coupons_Adapter_Registry();
        $custom   = $this->make_fake_adapter('legacy_xml_network');

        $registry->register_code_adapter('legacy_xml_network', $custom);

        $config  = $this->make_network_config('legacy_xml_network', null);
        $adapter = $registry->get_for_network($config);

        $this->assertSame($custom, $adapter);
    }

    public function test_register_code_adapter_returns_self_for_chaining(): void
    {
        $registry = new Cashback_Coupons_Adapter_Registry();
        $custom   = $this->make_fake_adapter('foo');
        $result   = $registry->register_code_adapter('foo', $custom);

        $this->assertSame($registry, $result);
    }

    public function test_unregister_clears_priority(): void
    {
        $registry = new Cashback_Coupons_Adapter_Registry($this->generic_factory_stub());
        $custom   = $this->make_fake_adapter('admitad');

        $registry->register_code_adapter('admitad', $custom);
        $registry->unregister_code_adapter('admitad');

        $config  = $this->make_network_config('admitad', '/coupons/?campaign={advcampaign_id}');
        $adapter = $registry->get_for_network($config);

        $this->assertNotSame($custom, $adapter, 'После unregister code-adapter не должен возвращаться');
        $this->assertSame('admitad', $adapter->get_network_slug());
    }

    public function test_handles_array_network_config_shape(): void
    {
        $registry = new Cashback_Coupons_Adapter_Registry($this->generic_factory_stub());
        $config_array = array(
            'id'                   => 1,
            'slug'                 => 'admitad',
            'api_coupons_endpoint' => '/coupons/?campaign={advcampaign_id}',
        );

        $adapter = $registry->get_for_network($config_array);
        $this->assertInstanceOf(Cashback_Coupons_Adapter_Interface::class, $adapter);
    }
}
