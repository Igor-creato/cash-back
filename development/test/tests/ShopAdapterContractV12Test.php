<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Контрактные тесты на расширение интерфейса адаптера CPA-сети (v12):
 *   - 2 новых метода в Cashback_Network_Adapter_Interface
 *     (fetch_campaigns_detailed, fetch_shop_tariffs);
 *   - дефолтные реализации в Cashback_Network_Adapter_Base возвращают
 *     success=false с понятной ошибкой (никто не сломан до Этапа 3/4);
 *   - конкретные адаптеры (Admitad, EPN) всё ещё реализуют интерфейс.
 *
 * Цель Этапа 2: расширить контракт без breaking change для существующих
 * адаптеров. Реальная имплементация — Этапы 3 (Admitad) и 4 (EPN).
 *
 * @group shop-import
 * @group adapters
 * @group contract
 */
#[Group('shop-import')]
#[Group('adapters')]
#[Group('contract')]
final class ShopAdapterContractV12Test extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        self::require_if_missing('/includes/class-cashback-outbound-http-guard.php', 'Cashback_Outbound_HTTP_Guard');
        self::require_if_missing('/includes/oauth/class-oauth2-client-credentials-helper.php', 'Cashback_OAuth2_Client_Credentials_Helper');
        self::require_if_missing('/includes/adapters/interface-cashback-network-adapter.php', null);
        self::require_if_missing('/includes/adapters/abstract-cashback-network-adapter.php', 'Cashback_Network_Adapter_Base');
        self::require_if_missing('/includes/adapters/class-admitad-adapter.php', 'Cashback_Admitad_Adapter');
        self::require_if_missing('/includes/adapters/class-epn-adapter.php', 'Cashback_Epn_Adapter');
    }

    private static function require_if_missing(string $relative, ?string $class): void
    {
        if ($class !== null && (class_exists($class) || interface_exists($class))) {
            return;
        }
        $path = self::$plugin_root . $relative;
        if (!file_exists($path)) {
            self::markTestSkipped("File missing: {$relative}");
            return;
        }
        require_once $path;
    }

    // ============================================================
    // 1. Интерфейс декларирует 2 новых метода.
    // ============================================================

    public function test_interface_declares_fetch_campaigns_detailed(): void
    {
        $this->assertTrue(
            interface_exists('Cashback_Network_Adapter_Interface'),
            'Cashback_Network_Adapter_Interface должен быть загружен'
        );

        $reflection = new \ReflectionClass('Cashback_Network_Adapter_Interface');
        $this->assertTrue(
            $reflection->hasMethod('fetch_campaigns_detailed'),
            'Интерфейс должен декларировать fetch_campaigns_detailed'
        );

        $method = $reflection->getMethod('fetch_campaigns_detailed');
        $params = $method->getParameters();
        $this->assertCount(4, $params, 'fetch_campaigns_detailed принимает 4 параметра');
        $this->assertSame('credentials', $params[0]->getName());
        $this->assertSame('network_config', $params[1]->getName());
        $this->assertSame('offset', $params[2]->getName());
        $this->assertSame('limit', $params[3]->getName());
    }

    public function test_interface_declares_fetch_shop_tariffs(): void
    {
        $reflection = new \ReflectionClass('Cashback_Network_Adapter_Interface');
        $this->assertTrue(
            $reflection->hasMethod('fetch_shop_tariffs'),
            'Интерфейс должен декларировать fetch_shop_tariffs'
        );

        $method = $reflection->getMethod('fetch_shop_tariffs');
        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('credentials', $params[0]->getName());
        $this->assertSame('network_config', $params[1]->getName());
        $this->assertSame('campaign_id', $params[2]->getName());
    }

    // ============================================================
    // 2. Abstract Base реализует методы по умолчанию (success=false).
    // ============================================================

    public function test_abstract_base_default_fetch_campaigns_detailed_returns_failure(): void
    {
        $adapter = $this->makeStubAdapter('fakeshop');

        $result = $adapter->fetch_campaigns_detailed(array(), array(), 0, 100);

        $this->assertIsArray($result);
        $this->assertFalse($result['success'], 'default fetch_campaigns_detailed.success = false');
        $this->assertSame(array(), $result['campaigns']);
        $this->assertFalse($result['has_next']);
        $this->assertSame(0, $result['next_offset']);
        $this->assertIsString($result['error']);
        $this->assertStringContainsString('not implemented', $result['error']);
        $this->assertStringContainsString('fakeshop', $result['error'], 'ошибка содержит slug адаптера');
    }

    public function test_abstract_base_default_fetch_shop_tariffs_returns_failure(): void
    {
        $adapter = $this->makeStubAdapter('fakeshop');

        $result = $adapter->fetch_shop_tariffs(array(), array(), 'campaign-1');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame(array(), $result['tariffs']);
        $this->assertIsString($result['error']);
        $this->assertStringContainsString('not implemented', $result['error']);
        $this->assertStringContainsString('fakeshop', $result['error']);
    }

    // ============================================================
    // 3. Admitad / EPN всё ещё реализуют интерфейс (no breaking change).
    // ============================================================

    public function test_admitad_adapter_implements_full_interface(): void
    {
        $adapter = new Cashback_Admitad_Adapter();
        $this->assertInstanceOf('Cashback_Network_Adapter_Interface', $adapter);

        // Все методы интерфейса доступны.
        $this->assertTrue(method_exists($adapter, 'fetch_campaigns_detailed'));
        $this->assertTrue(method_exists($adapter, 'fetch_shop_tariffs'));
        $this->assertTrue(method_exists($adapter, 'fetch_campaigns'));
        $this->assertTrue(method_exists($adapter, 'fetch_all_actions'));
    }

    public function test_epn_adapter_implements_full_interface(): void
    {
        $adapter = new Cashback_Epn_Adapter();
        $this->assertInstanceOf('Cashback_Network_Adapter_Interface', $adapter);

        $this->assertTrue(method_exists($adapter, 'fetch_campaigns_detailed'));
        $this->assertTrue(method_exists($adapter, 'fetch_shop_tariffs'));
    }

    // ============================================================
    // 4. На Этапе 2 — Admitad/EPN наследуют default success=false.
    //    На Этапах 3/4 эти тесты заменятся на реальные функциональные.
    // ============================================================

    public function test_admitad_default_fetch_campaigns_detailed_inherits_failure(): void
    {
        $adapter = new Cashback_Admitad_Adapter();
        $reflection = new \ReflectionClass($adapter);

        if ($reflection->hasMethod('fetch_campaigns_detailed')) {
            $declaring = $reflection->getMethod('fetch_campaigns_detailed')->getDeclaringClass()->getName();
            if ($declaring !== 'Cashback_Network_Adapter_Base') {
                // Admitad уже переопределил метод (Этап 3 уже пройден) — пропускаем.
                $this->markTestSkipped('Admitad has overridden fetch_campaigns_detailed (Этап 3 уже выполнен)');
                return;
            }
        }

        $result = $adapter->fetch_campaigns_detailed(array(), array(), 0, 10);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('admitad', $result['error']);
    }

    public function test_epn_default_fetch_shop_tariffs_inherits_failure(): void
    {
        $adapter = new Cashback_Epn_Adapter();
        $reflection = new \ReflectionClass($adapter);

        if ($reflection->hasMethod('fetch_shop_tariffs')) {
            $declaring = $reflection->getMethod('fetch_shop_tariffs')->getDeclaringClass()->getName();
            if ($declaring !== 'Cashback_Network_Adapter_Base') {
                $this->markTestSkipped('EPN has overridden fetch_shop_tariffs (Этап 4 уже выполнен)');
                return;
            }
        }

        $result = $adapter->fetch_shop_tariffs(array(), array(), 'offer-1');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('epn', strtolower($result['error']));
    }

    // ============================================================
    // 5. Подключение DTO/абстракта в cashback-plugin.php (require).
    // ============================================================

    public function test_dtos_loaded_in_plugin_bootstrap(): void
    {
        $plugin_php = file_get_contents(self::$plugin_root . '/cashback-plugin.php');
        $this->assertStringContainsString(
            'class-cashback-campaign-detail-dto.php',
            $plugin_php,
            'cashback-plugin.php должен подключать campaign-detail-dto'
        );
        $this->assertStringContainsString(
            'class-cashback-shop-tariff-dto.php',
            $plugin_php,
            'cashback-plugin.php должен подключать shop-tariff-dto'
        );
    }

    // ============================================================
    // Helper: создаёт минимальный stub поверх Cashback_Network_Adapter_Base.
    // ============================================================

    private function makeStubAdapter(string $slug): Cashback_Network_Adapter_Base
    {
        return new class($slug) extends Cashback_Network_Adapter_Base {
            private string $slug;
            public function __construct(string $slug)
            {
                $this->slug = $slug;
            }
            public function get_slug(): string
            {
                return $this->slug;
            }
            public function get_token(array $credentials, array $network_config): ?string
            {
                return null;
            }
            public function build_auth_headers(array $credentials, array $network_config): ?array
            {
                return array();
            }
            public function fetch_all_actions(array $credentials, array $params, int $max_pages, array $network_config): array
            {
                return $this->fetch_error('stub');
            }
            public function get_default_status_map(): array
            {
                return array();
            }
        };
    }
}
