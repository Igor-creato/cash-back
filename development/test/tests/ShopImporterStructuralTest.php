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
    // SVG-путь: WP-core media_sideload_image отказывает SVG («Неверный URL
    // изображения») — adapter Admitad отдаёт ~50% магазинов как .svg
    // (cdn.admitad-connect.com/.../*.svg). Импортёр должен выбрать
    // download_url + sanitize + wp_handle_sideload + wp_insert_attachment
    // путь и НЕ вызывать media_sideload_image для SVG.
    // ============================================================

    private function reset_sideload_globals(): void
    {
        $GLOBALS['_cb_test_media_sideload_calls']    = array();
        $GLOBALS['_cb_test_post_thumbnails']         = array();
        $GLOBALS['_cb_test_download_url_calls']      = array();
        $GLOBALS['_cb_test_handle_sideload_calls']   = array();
        $GLOBALS['_cb_test_insert_attachment_calls'] = array();
        unset(
            $GLOBALS['_cb_test_media_sideload_return'],
            $GLOBALS['_cb_test_download_url_return'],
            $GLOBALS['_cb_test_handle_sideload_return'],
            $GLOBALS['_cb_test_insert_attachment_return'],
            $GLOBALS['_cb_test_svg_payload']
        );
    }

    public function test_attach_featured_image_uses_sideload_pipeline_for_svg(): void
    {
        $this->reset_sideload_globals();

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method     = $reflection->getMethod('attach_featured_image_from_url');
        $method->setAccessible(true);

        $svg_url = 'https://cdn.admitad-connect.com/campaign/images/2025/7/24/45856-ab.svg';
        $method->invoke(null, 91, $svg_url, 'adm', '45856');

        $this->assertSame(
            array(),
            $GLOBALS['_cb_test_media_sideload_calls'],
            'media_sideload_image НЕ должен вызываться для SVG (WP-core regex его отклоняет).'
        );
        $this->assertSame(
            array($svg_url),
            $GLOBALS['_cb_test_download_url_calls'],
            'SVG-путь должен скачивать через download_url.'
        );
        $this->assertCount(1, $GLOBALS['_cb_test_handle_sideload_calls'], 'wp_handle_sideload должен быть вызван 1 раз.');
        $sideload_args = $GLOBALS['_cb_test_handle_sideload_calls'][0];
        $this->assertSame('image/svg+xml', $sideload_args['file_array']['type']);
        $this->assertArrayHasKey('mimes', $sideload_args['overrides']);
        $this->assertSame('image/svg+xml', $sideload_args['overrides']['mimes']['svg'] ?? null);
        $this->assertFalse($sideload_args['overrides']['test_form'] ?? true);

        $this->assertCount(1, $GLOBALS['_cb_test_insert_attachment_calls']);
        $insert_args = $GLOBALS['_cb_test_insert_attachment_calls'][0];
        $this->assertSame('image/svg+xml', $insert_args['args']['post_mime_type']);
        $this->assertSame(91, $insert_args['parent_post_id']);

        $this->assertArrayHasKey(91, $GLOBALS['_cb_test_post_thumbnails']);
        $this->assertSame(91 + 200000, $GLOBALS['_cb_test_post_thumbnails'][91]);
    }

    public function test_attach_featured_image_keeps_legacy_path_for_png(): void
    {
        $this->reset_sideload_globals();

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method     = $reflection->getMethod('attach_featured_image_from_url');
        $method->setAccessible(true);

        $method->invoke(null, 33, 'https://cdn.admitad-connect.com/campaign/images/2024/1/1/26006.png', 'adm', '26006');

        $this->assertSame(array(), $GLOBALS['_cb_test_download_url_calls'], 'PNG идёт legacy-путём, не через download_url.');
        $this->assertSame(array(), $GLOBALS['_cb_test_handle_sideload_calls']);
        $this->assertCount(1, $GLOBALS['_cb_test_media_sideload_calls']);
        $this->assertSame(33, $GLOBALS['_cb_test_media_sideload_calls'][0]['post_id']);
    }

    public function test_attach_featured_image_svg_returns_silently_on_download_error(): void
    {
        $this->reset_sideload_globals();
        $GLOBALS['_cb_test_download_url_return'] = new \WP_Error('http_request_failed', 'CDN unreachable');

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method     = $reflection->getMethod('attach_featured_image_from_url');
        $method->setAccessible(true);

        $method->invoke(null, 7, 'https://cdn.admitad-connect.com/x.svg', 'adm', '500');

        $this->assertSame(array(), $GLOBALS['_cb_test_handle_sideload_calls']);
        $this->assertSame(array(), $GLOBALS['_cb_test_insert_attachment_calls']);
        $this->assertArrayNotHasKey(7, $GLOBALS['_cb_test_post_thumbnails']);
    }

    public function test_attach_featured_image_svg_handles_sideload_error(): void
    {
        $this->reset_sideload_globals();
        $GLOBALS['_cb_test_filters']                = array();
        $GLOBALS['_cb_test_handle_sideload_return'] = array('error' => 'Sorry, this file type is not permitted.');

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method     = $reflection->getMethod('attach_featured_image_from_url');
        $method->setAccessible(true);

        $method->invoke(null, 8, 'https://cdn.admitad-connect.com/x.svg', 'adm', '501');

        $this->assertSame(array(), $GLOBALS['_cb_test_insert_attachment_calls']);
        $this->assertArrayNotHasKey(8, $GLOBALS['_cb_test_post_thumbnails']);
        $this->assertSame(
            array(),
            $GLOBALS['_cb_test_filters']['wp_check_filetype_and_ext'] ?? array(),
            'wp_check_filetype_and_ext filter снимается даже при sideload-error.'
        );
        $this->assertSame(
            array(),
            $GLOBALS['_cb_test_filters']['upload_mimes'] ?? array(),
            'upload_mimes filter снимается даже при sideload-error.'
        );
    }

    public function test_attach_featured_image_svg_registers_and_removes_check_filter(): void
    {
        $this->reset_sideload_globals();
        $GLOBALS['_cb_test_filters'] = array();

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method     = $reflection->getMethod('attach_featured_image_from_url');
        $method->setAccessible(true);

        $method->invoke(null, 13, 'https://cdn.admitad-connect.com/x.svg', 'adm', '777');

        // Happy-path должен снять оба фильтра в finally.
        $this->assertSame(
            array(),
            $GLOBALS['_cb_test_filters']['wp_check_filetype_and_ext'] ?? array(),
            'wp_check_filetype_and_ext filter снят после happy-path.'
        );
        $this->assertSame(
            array(),
            $GLOBALS['_cb_test_filters']['upload_mimes'] ?? array(),
            'upload_mimes filter снят после happy-path.'
        );
    }

    public function test_force_svg_check_callback_normalizes_filetype_for_svg(): void
    {
        // Контрактный тест на форсящий callback: ext/type/proper_filename
        // должны быть svg/image-svg-xml для .svg, иначе данные не трогаем.
        // Реконструируем callback такой же как в sideload_svg_attachment().
        $cb = static function (array $check, string $file_path, string $filename, $mimes, string $real_mime = ''): array {
            unset($file_path);
            if (! preg_match('/\.svgz?$/i', $filename)) {
                return $check;
            }
            if (! is_array($mimes) || ! isset($mimes['svg'])) {
                return $check;
            }
            $rm = strtolower($real_mime);
            $is_safe_real_mime = $rm === ''
                || str_starts_with($rm, 'image/svg')
                || str_starts_with($rm, 'text/')
                || $rm === 'application/xml'
                || $rm === 'application/octet-stream';
            if (! $is_safe_real_mime) {
                return $check;
            }
            $check['ext']             = 'svg';
            $check['type']            = 'image/svg+xml';
            $check['proper_filename'] = $filename;
            return $check;
        };

        $reset    = array('ext' => false, 'type' => false, 'proper_filename' => false);
        $allowed  = array('svg' => 'image/svg+xml');

        // SVG + caller разрешил svg + real_mime безопасный → форсим.
        $svg = $cb($reset, '/tmp/x', 'logo.svg', $allowed, 'text/xml');
        $this->assertSame('svg', $svg['ext']);
        $this->assertSame('image/svg+xml', $svg['type']);

        // Non-SVG не трогаем.
        $png = $cb($reset, '/tmp/x', 'logo.png', $allowed, 'image/png');
        $this->assertFalse($png['ext'], 'callback не должен вмешиваться в non-SVG.');

        // SVG, но caller НЕ разрешил svg в overrides — не подменяем.
        $svg_no_caller = $cb($reset, '/tmp/x', 'logo.svg', null, 'text/xml');
        $this->assertFalse($svg_no_caller['ext'], 'callback не активируется без svg в overrides[mimes].');

        // SVG с подозрительным real_mime (PHP/исполняемый) — не подменяем.
        $svg_php = $cb($reset, '/tmp/x', 'logo.svg', $allowed, 'application/x-php');
        $this->assertFalse($svg_php['ext'], 'callback не активируется при подозрительном real_mime.');
    }

    public function test_sanitize_svg_strips_script_tag(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method     = $reflection->getMethod('sanitize_svg');
        $method->setAccessible(true);

        $payload = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><circle cx="5" cy="5" r="4"/></svg>';
        $clean   = $method->invoke(null, $payload);

        $this->assertIsString($clean);
        $this->assertStringNotContainsString('<script', (string) $clean);
        $this->assertStringNotContainsString('alert(1)', (string) $clean);
        $this->assertStringContainsString('<circle', (string) $clean);
    }

    public function test_sanitize_svg_strips_event_handler_attributes(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method     = $reflection->getMethod('sanitize_svg');
        $method->setAccessible(true);

        $payload = '<svg xmlns="http://www.w3.org/2000/svg"><rect onclick="evil()" onload="x()" width="10" height="10"/></svg>';
        $clean   = (string) $method->invoke(null, $payload);

        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onload', $clean);
        $this->assertStringNotContainsString('evil()', $clean);
        $this->assertStringContainsString('<rect', $clean);
        $this->assertStringContainsString('width="10"', $clean);
    }

    public function test_sanitize_svg_strips_javascript_href(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method     = $reflection->getMethod('sanitize_svg');
        $method->setAccessible(true);

        $payload = '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)" xlink:href="javascript:bad()"><circle r="1"/></a></svg>';
        $clean   = (string) $method->invoke(null, $payload);

        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('<circle', $clean);
    }

    public function test_sanitize_svg_strips_foreign_object(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method     = $reflection->getMethod('sanitize_svg');
        $method->setAccessible(true);

        $payload = '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><iframe src="evil"/></foreignObject><circle r="1"/></svg>';
        $clean   = (string) $method->invoke(null, $payload);

        $this->assertStringNotContainsString('<foreignObject', $clean);
        $this->assertStringNotContainsString('<iframe', $clean);
        $this->assertStringContainsString('<circle', $clean);
    }

    public function test_sanitize_svg_rejects_non_svg_payload(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method     = $reflection->getMethod('sanitize_svg');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(null, '<html>not svg</html>'));
        $this->assertNull($method->invoke(null, ''));
    }

    public function test_sanitize_svg_keeps_clean_payload_intact(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method     = $reflection->getMethod('sanitize_svg');
        $method->setAccessible(true);

        $payload = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M1 2 L3 4"/></svg>';
        $clean   = (string) $method->invoke(null, $payload);

        $this->assertStringContainsString('<svg', $clean);
        $this->assertStringContainsString('viewBox="0 0 24 24"', $clean);
        $this->assertStringContainsString('M1 2 L3 4', $clean);
    }

    public function test_is_svg_url_recognizes_admitad_paths(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method     = $reflection->getMethod('is_svg_url');
        $method->setAccessible(true);

        $this->assertTrue((bool) $method->invoke(null, 'https://cdn.admitad-connect.com/campaign/images/2024/1/1/45856-ab.svg'));
        $this->assertTrue((bool) $method->invoke(null, 'https://cdn.x/x.SVG?v=1'));
        $this->assertFalse((bool) $method->invoke(null, 'https://cdn.admitad-connect.com/campaign/images/2024/1/1/26006.png'));
        $this->assertFalse((bool) $method->invoke(null, 'https://cdn/logo.jpg'));
        $this->assertFalse((bool) $method->invoke(null, ''));
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
        $this->assertSame('[cashback_promocodes]', Cashback_Shop_Importer::DEFAULT_TAB2_CONTENT);
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
        $this->assertSame('[cashback_promocodes]', $bucket['_woodmart_product_custom_tab_content_2'] ?? null);
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

    // ============================================================
    // set_product_type_external — корневой фикс отображения External Product
    // полей в WC-метабоксе (без taxonomy term WC считает товар simple).
    // ============================================================

    public function test_set_product_type_external_writes_taxonomy_term(): void
    {
        $GLOBALS['_cb_test_object_terms'] = array();

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method = $reflection->getMethod('set_product_type_external');
        $method->setAccessible(true);

        $method->invoke(null, 777);

        $this->assertSame(
            array('external'),
            $GLOBALS['_cb_test_object_terms'][777]['product_type'] ?? null,
            'product_type taxonomy должен содержать term "external"'
        );
    }

    public function test_set_product_type_external_skips_invalid_id(): void
    {
        $GLOBALS['_cb_test_object_terms'] = array();

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method = $reflection->getMethod('set_product_type_external');
        $method->setAccessible(true);

        $method->invoke(null, 0);
        $method->invoke(null, -5);

        $this->assertSame(array(), $GLOBALS['_cb_test_object_terms']);
    }

    // ============================================================
    // backfill_missing_admin_fields — idempotent: пишет только если пусто.
    // ============================================================

    public function test_backfill_writes_taxonomy_when_missing(): void
    {
        $GLOBALS['_cb_test_object_terms'] = array();
        $GLOBALS['_cb_test_post_meta']    = array();

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method = $reflection->getMethod('backfill_missing_admin_fields');
        $method->setAccessible(true);

        $method->invoke(null, 333);

        $this->assertSame(
            array('external'),
            $GLOBALS['_cb_test_object_terms'][333]['product_type'] ?? null
        );
    }

    public function test_backfill_does_not_overwrite_existing_taxonomy(): void
    {
        $GLOBALS['_cb_test_object_terms'] = array(
            333 => array('product_type' => array('simple')),
        );
        $GLOBALS['_cb_test_post_meta']    = array();

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method = $reflection->getMethod('backfill_missing_admin_fields');
        $method->setAccessible(true);

        $method->invoke(null, 333);

        $this->assertSame(
            array('simple'),
            $GLOBALS['_cb_test_object_terms'][333]['product_type'],
            'admin/manual term не должен затираться'
        );
    }

    public function test_backfill_writes_defaults_only_for_empty_metas(): void
    {
        $GLOBALS['_cb_test_object_terms'] = array();
        $GLOBALS['_cb_test_post_meta']    = array(
            42 => array(
                '_button_text'             => 'Купить',                  // admin override — не трогать
                '_cashback_display_label'  => '',                        // empty — заполнить
                '_woodmart_product_custom_tab_title' => 'Своё название', // admin override — не трогать
            ),
        );

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method = $reflection->getMethod('backfill_missing_admin_fields');
        $method->setAccessible(true);

        $method->invoke(null, 42);

        $bucket = $GLOBALS['_cb_test_post_meta'][42];
        // Не перезаписаны
        $this->assertSame('Купить', $bucket['_button_text']);
        $this->assertSame('Своё название', $bucket['_woodmart_product_custom_tab_title']);
        // Заполнены дефолтами
        $this->assertSame('Кэшбэк', $bucket['_cashback_display_label']);
        $this->assertSame('hide', $bucket['_store_popup_mode']);
        $this->assertSame('Промокоды', $bucket['_woodmart_product_custom_tab_title_2']);
        $this->assertSame('[cashback_promocodes]', $bucket['_woodmart_product_custom_tab_content_2']);
    }

    public function test_backfill_skips_invalid_product_id(): void
    {
        $GLOBALS['_cb_test_object_terms'] = array();
        $GLOBALS['_cb_test_post_meta']    = array();

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method = $reflection->getMethod('backfill_missing_admin_fields');
        $method->setAccessible(true);

        $method->invoke(null, 0);

        $this->assertSame(array(), $GLOBALS['_cb_test_object_terms']);
        $this->assertSame(array(), $GLOBALS['_cb_test_post_meta']);
    }
}
