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

    // ============================================================
    // Сигнатуры приватных методов (после фиксов inline_tariffs / media / slug).
    // ============================================================

    public function test_sync_tariffs_for_campaign_takes_dto(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $this->assertTrue($reflection->hasMethod('sync_tariffs_for_campaign'));

        $method = $reflection->getMethod('sync_tariffs_for_campaign');
        $params = $method->getParameters();
        $this->assertCount(5, $params, 'sync_tariffs_for_campaign принимает 5 параметров');

        $dto_param = $params[4];
        $this->assertSame('dto', $dto_param->getName());
        $type = $dto_param->getType();
        $this->assertNotNull($type);
        $this->assertSame('Cashback_Campaign_Detail_DTO', $type->getName());
    }

    public function test_write_product_meta_takes_adapter_slug(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method = $reflection->getMethod('write_product_meta');
        $params = $method->getParameters();
        $this->assertCount(7, $params, 'write_product_meta принимает 7 параметров');

        $slug_param = $params[6];
        $this->assertSame('adapter_slug', $slug_param->getName());
        $this->assertSame('string', $slug_param->getType()->getName());
    }

    public function test_attach_featured_image_helper_exists(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $this->assertTrue(
            $reflection->hasMethod('attach_featured_image_from_url'),
            'attach_featured_image_from_url должен существовать как private helper'
        );
        $method = $reflection->getMethod('attach_featured_image_from_url');
        $this->assertTrue($method->isPrivate());
        $this->assertTrue($method->isStatic());
    }

    public function test_upsert_product_takes_optional_adapter_slug(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method = $reflection->getMethod('upsert_product');
        $params = $method->getParameters();
        $this->assertCount(3, $params, 'upsert_product теперь принимает 3 параметра (slug опциональный)');
        $this->assertSame('adapter_slug', $params[2]->getName());
        $this->assertTrue($params[2]->isDefaultValueAvailable(), 'adapter_slug имеет default ""');
    }

    // ============================================================
    // Functional smoke: attach_featured_image_from_url через Reflection
    // (приватный, нужно invoke напрямую).
    // ============================================================

    public function test_attach_featured_image_calls_media_sideload_and_set_thumbnail(): void
    {
        $GLOBALS['_cb_test_media_sideload_calls'] = array();
        $GLOBALS['_cb_test_post_thumbnails']      = array();

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method = $reflection->getMethod('attach_featured_image_from_url');
        $method->setAccessible(true);

        $method->invoke(null, 42, 'https://cdn.example.com/logo.png', 'adm', '2381');

        $this->assertCount(1, $GLOBALS['_cb_test_media_sideload_calls']);
        $this->assertSame('https://cdn.example.com/logo.png', $GLOBALS['_cb_test_media_sideload_calls'][0]['url']);
        $this->assertSame(42, $GLOBALS['_cb_test_media_sideload_calls'][0]['post_id']);
        $this->assertSame('id', $GLOBALS['_cb_test_media_sideload_calls'][0]['return_format']);

        $this->assertArrayHasKey(42, $GLOBALS['_cb_test_post_thumbnails']);
        $this->assertSame(100042, $GLOBALS['_cb_test_post_thumbnails'][42]);
    }

    public function test_attach_featured_image_skips_empty_url(): void
    {
        $GLOBALS['_cb_test_media_sideload_calls'] = array();
        $GLOBALS['_cb_test_post_thumbnails']      = array();

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method = $reflection->getMethod('attach_featured_image_from_url');
        $method->setAccessible(true);

        $method->invoke(null, 99, '', 'adm', 'X');

        $this->assertSame(array(), $GLOBALS['_cb_test_media_sideload_calls']);
        $this->assertArrayNotHasKey(99, $GLOBALS['_cb_test_post_thumbnails']);
    }

    public function test_attach_featured_image_handles_wp_error_silently(): void
    {
        $GLOBALS['_cb_test_media_sideload_calls'] = array();
        $GLOBALS['_cb_test_post_thumbnails']      = array();
        $GLOBALS['_cb_test_media_sideload_return'] = new \WP_Error('http_request_failed', 'CDN unreachable');

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method = $reflection->getMethod('attach_featured_image_from_url');
        $method->setAccessible(true);

        // Не должен бросать исключение.
        $method->invoke(null, 7, 'https://broken.example.com/x.png', 'adm', '500');

        $this->assertCount(1, $GLOBALS['_cb_test_media_sideload_calls']);
        $this->assertArrayNotHasKey(7, $GLOBALS['_cb_test_post_thumbnails'], 'thumbnail НЕ ставится при WP_Error');
    }

    // ============================================================
    // apply_first_import_defaults — дефолты при первичном импорте.
    // Используем in-memory mock update_post_meta через $GLOBALS.
    // ============================================================

    public function test_default_constants_match_user_spec(): void
    {
        $this->assertSame('Перейти', Cashback_Shop_Importer::DEFAULT_BUTTON_TEXT);
        $this->assertSame('hide', Cashback_Shop_Importer::DEFAULT_POPUP_MODE);
        $this->assertSame('Кэшбэк', Cashback_Shop_Importer::DEFAULT_DISPLAY_LABEL);
        $this->assertSame('Условия', Cashback_Shop_Importer::DEFAULT_TAB1_TITLE);
        $this->assertSame('Промокоды', Cashback_Shop_Importer::DEFAULT_TAB2_TITLE);
        $this->assertSame('[cashback_coupons_icons]', Cashback_Shop_Importer::DEFAULT_TAB2_CONTENT);
    }

    public function test_apply_first_import_defaults_writes_all_metas(): void
    {
        $GLOBALS['_cb_test_post_meta'] = array();

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method = $reflection->getMethod('apply_first_import_defaults');
        $method->setAccessible(true);

        $method->invoke(null, 555);

        $bucket = $GLOBALS['_cb_test_post_meta'][555] ?? array();

        // Сторонние / WC поля.
        $this->assertSame('Перейти', $bucket['_button_text'] ?? null);
        $this->assertSame('hide', $bucket['_store_popup_mode'] ?? null);
        $this->assertSame('Кэшбэк', $bucket['_cashback_display_label'] ?? null);

        // Tab 1 — Условия (пустой content).
        $this->assertSame('Условия', $bucket['_woodmart_product_custom_tab_title'] ?? null);
        $this->assertSame('80', $bucket['_woodmart_product_custom_tab_priority'] ?? null);
        $this->assertSame('text', $bucket['_woodmart_product_custom_tab_content_type'] ?? null);
        $this->assertSame('', $bucket['_woodmart_product_custom_tab_content'] ?? null);

        // Tab 2 — Промокоды + шорткод.
        $this->assertSame('Промокоды', $bucket['_woodmart_product_custom_tab_title_2'] ?? null);
        $this->assertSame('90', $bucket['_woodmart_product_custom_tab_priority_2'] ?? null);
        $this->assertSame('text', $bucket['_woodmart_product_custom_tab_content_type_2'] ?? null);
        $this->assertSame('[cashback_coupons_icons]', $bucket['_woodmart_product_custom_tab_content_2'] ?? null);
    }

    public function test_apply_first_import_defaults_skips_invalid_product_id(): void
    {
        $GLOBALS['_cb_test_post_meta'] = array();

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method = $reflection->getMethod('apply_first_import_defaults');
        $method->setAccessible(true);

        $method->invoke(null, 0);
        $method->invoke(null, -1);

        $this->assertSame(array(), $GLOBALS['_cb_test_post_meta'], 'на product_id <= 0 ничего не пишется');
    }
}
