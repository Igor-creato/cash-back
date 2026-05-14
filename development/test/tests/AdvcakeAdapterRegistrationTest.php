<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Адаптер Advcake должен быть зарегистрирован в Cashback_API_Client
 * автоматически после конструктора (без явных хуков).
 *
 * Регрессия: при добавлении новой сети легко забыть строчку
 * `$this->register_adapter(new Cashback_Advcake_Adapter())` в конструкторе.
 *
 * @group adapters
 * @group advcake
 * @group registration
 */
#[Group('adapters')]
#[Group('advcake')]
#[Group('registration')]
final class AdvcakeAdapterRegistrationTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        $files = array(
            '/includes/class-cashback-outbound-http-guard.php',
            '/includes/oauth/class-oauth2-client-credentials-helper.php',
            '/includes/adapters/interface-cashback-network-adapter.php',
            '/includes/adapters/abstract-cashback-network-adapter.php',
            '/includes/adapters/class-cashback-campaign-detail-dto.php',
            '/includes/adapters/class-cashback-shop-tariff-dto.php',
            '/includes/adapters/class-admitad-adapter.php',
            '/includes/adapters/class-epn-adapter.php',
            '/includes/adapters/class-cashback-advcake-adapter.php',
            '/includes/class-cashback-encryption.php',
            '/includes/class-cashback-api-client.php',
        );
        foreach ($files as $rel) {
            $path = self::$plugin_root . $rel;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    protected function setUp(): void
    {
        // wpdb-mock с обязательным префиксом для constructor'а API-клиента.
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';

            public function prepare(string $q, mixed ...$args): string
            {
                return $q;
            }
            public function get_row(string $q): ?object
            {
                return null;
            }
            public function get_results(string $q): array
            {
                return array();
            }
            public function get_var(string $q): mixed
            {
                return 0;
            }
            public function query(string $q): int
            {
                return 0;
            }
            public function insert(string $t, array $d, array $f = array()): int
            {
                return 1;
            }
            public function update(string $t, array $d, array $w, array $df = array(), array $wf = array()): int
            {
                return 1;
            }
        };
    }

    public function test_advcake_adapter_resolves_by_slug(): void
    {
        $client  = $this->fresh_api_client();
        $adapter = $client->get_adapter('advcake');

        $this->assertNotNull($adapter, 'Cashback_API_Client должен резолвить адаптер для slug=advcake');
        $this->assertInstanceOf(Cashback_Advcake_Adapter::class, $adapter);
    }

    public function test_advcake_adapter_resolves_by_aliases(): void
    {
        $client = $this->fresh_api_client();

        $by_alias_short = $client->get_adapter('adv');
        $this->assertInstanceOf(Cashback_Advcake_Adapter::class, $by_alias_short);

        $by_alias_domain = $client->get_adapter('advcake.ru');
        $this->assertInstanceOf(Cashback_Advcake_Adapter::class, $by_alias_domain);
    }

    public function test_has_adapter_returns_true_for_advcake(): void
    {
        $client = $this->fresh_api_client();
        $this->assertTrue($client->has_adapter('advcake'));
    }

    public function test_unknown_slug_returns_null(): void
    {
        $client = $this->fresh_api_client();
        $this->assertNull($client->get_adapter('nonexistent-network-xyz'));
    }

    public function test_admitad_and_epn_still_register(): void
    {
        $client = $this->fresh_api_client();
        $this->assertTrue($client->has_adapter('admitad'));
        $this->assertTrue($client->has_adapter('epn'));
    }

    /**
     * Cashback_API_Client — singleton. Чтобы каждый тест работал на чистом
     * инстансе, сбрасываем static property через reflection.
     */
    private function fresh_api_client(): Cashback_API_Client
    {
        if (class_exists('Cashback_API_Client')) {
            $ref       = new ReflectionClass('Cashback_API_Client');
            if ($ref->hasProperty('instance')) {
                $instance = $ref->getProperty('instance');
                $instance->setAccessible(true);
                $instance->setValue(null, null);
            }
        }
        return Cashback_API_Client::get_instance();
    }
}
