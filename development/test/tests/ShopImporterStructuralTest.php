<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные + чистые helper-тесты на Cashback_Shop_Importer (v12, Этап 5).
 *
 * Проверяет:
 *  - наличие метода run() с корректной сигнатурой;
 *  - константы AS HOOK_RUN, AS_GROUP;
 *  - чистые helpers parse_domain() и compute_signature() (без БД).
 *
 * Полный functional-тест run() (с моком adapter, wpdb, wp_insert_post)
 * добавится в Этапе 8 когда admin-trigger будет готов.
 *
 * @group shop-import
 * @group importer
 */
#[Group('shop-import')]
#[Group('importer')]
final class ShopImporterStructuralTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        if (!class_exists('Cashback_Campaign_Detail_DTO')) {
            require_once self::$plugin_root . '/includes/adapters/class-cashback-campaign-detail-dto.php';
        }
        if (!class_exists('Cashback_Shop_Importer')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-shop-importer.php';
        }
    }

    public function test_class_exists_with_expected_constants(): void
    {
        $this->assertTrue(class_exists('Cashback_Shop_Importer'));
        $this->assertSame('cashback_shops_import_run', Cashback_Shop_Importer::HOOK_RUN);
        $this->assertSame('cashback', Cashback_Shop_Importer::AS_GROUP);
    }

    public function test_meta_key_constants_are_canonical(): void
    {
        $this->assertSame('_affiliate_network_id', Cashback_Shop_Importer::META_NETWORK_ID);
        $this->assertSame('_offer_id', Cashback_Shop_Importer::META_OFFER_ID);
        $this->assertSame('_store_domain', Cashback_Shop_Importer::META_STORE_DOMAIN);
        $this->assertSame('_cashback_import_signature', Cashback_Shop_Importer::META_SIGNATURE);
        $this->assertSame('_rate_locked', Cashback_Shop_Importer::META_RATE_LOCKED);
        $this->assertSame('_cashback_last_seen_at', Cashback_Shop_Importer::META_LAST_SEEN_AT);
    }

    public function test_run_method_signature(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $this->assertTrue($reflection->hasMethod('run'));

        $method = $reflection->getMethod('run');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());

        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('network_id', $params[0]->getName());
        $this->assertSame('run_id', $params[1]->getName());
        $this->assertSame('offset', $params[2]->getName());
    }

    public function test_init_method_exists(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $this->assertTrue($reflection->hasMethod('init'));
        $method = $reflection->getMethod('init');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    // ============================================================
    // parse_domain — чистая функция, без БД.
    // ============================================================

    public function test_parse_domain_strips_www_and_lowercases(): void
    {
        $this->assertSame('joom.com', Cashback_Shop_Importer::parse_domain('https://www.JOOM.com/ru'));
        $this->assertSame('joom.com', Cashback_Shop_Importer::parse_domain('http://www.joom.com/'));
        $this->assertSame('joom.com', Cashback_Shop_Importer::parse_domain('https://joom.com/?ref=x'));
    }

    public function test_parse_domain_handles_url_without_scheme(): void
    {
        $this->assertSame('aliexpress.com', Cashback_Shop_Importer::parse_domain('aliexpress.com'));
        $this->assertSame('aliexpress.com', Cashback_Shop_Importer::parse_domain('//aliexpress.com'));
    }

    public function test_parse_domain_returns_empty_for_invalid_url(): void
    {
        $this->assertSame('', Cashback_Shop_Importer::parse_domain(''));
    }

    public function test_parse_domain_handles_subdomain(): void
    {
        $this->assertSame('shop.joom.com', Cashback_Shop_Importer::parse_domain('https://shop.joom.com/'));
    }

    // ============================================================
    // compute_signature — детерминирован, разные DTO дают разные хэши.
    // ============================================================

    public function test_compute_signature_is_stable_for_same_dto(): void
    {
        $dto = Cashback_Campaign_Detail_DTO::from_array(array(
            'id'       => '1',
            'name'     => 'Joom',
            'site_url' => 'https://joom.com',
            'status_raw' => 'active',
        ));

        $a = Cashback_Shop_Importer::compute_signature($dto);
        $b = Cashback_Shop_Importer::compute_signature($dto);
        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a), 'sha256 hex = 64 chars');
    }

    public function test_compute_signature_differs_when_name_changes(): void
    {
        $a = Cashback_Shop_Importer::compute_signature(Cashback_Campaign_Detail_DTO::from_array(array(
            'id' => '1', 'name' => 'Joom', 'site_url' => 'https://joom.com',
        )));
        $b = Cashback_Shop_Importer::compute_signature(Cashback_Campaign_Detail_DTO::from_array(array(
            'id' => '1', 'name' => 'Joom Ru', 'site_url' => 'https://joom.com',
        )));
        $this->assertNotSame($a, $b);
    }

    public function test_compute_signature_ignores_id_and_raw(): void
    {
        // signature не должна зависеть от id (ключ для lookup) и raw (отладочный).
        $a = Cashback_Shop_Importer::compute_signature(Cashback_Campaign_Detail_DTO::from_array(array(
            'id' => '1', 'name' => 'Joom', 'raw' => array('a' => 1),
        )));
        $b = Cashback_Shop_Importer::compute_signature(Cashback_Campaign_Detail_DTO::from_array(array(
            'id' => '999', 'name' => 'Joom', 'raw' => array('b' => 2),
        )));
        $this->assertSame($a, $b, 'signature не должна зависеть от id и raw');
    }

    public function test_compute_signature_differs_when_categories_change(): void
    {
        $a = Cashback_Shop_Importer::compute_signature(Cashback_Campaign_Detail_DTO::from_array(array(
            'id'         => '1',
            'name'       => 'Joom',
            'categories' => array('A'),
        )));
        $b = Cashback_Shop_Importer::compute_signature(Cashback_Campaign_Detail_DTO::from_array(array(
            'id'         => '1',
            'name'       => 'Joom',
            'categories' => array('A', 'B'),
        )));
        $this->assertNotSame($a, $b);
    }
}
