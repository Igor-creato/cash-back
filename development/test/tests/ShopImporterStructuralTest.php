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

    /**
     * F-P1-002: try_lock/release_lock используют MySQL `GET_LOCK`/`RELEASE_LOCK`,
     * а НЕ `get_transient`/`set_transient`. Transient SELECT-then-SET даёт TOCTOU
     * race между двумя AS-tick'ами на параллельный импорт одной CPA-сети →
     * дубль WC-product (F-P1-003), дубль reconcile_group, лишний SVG-download.
     */
    public function test_try_lock_uses_mysql_get_lock_not_transient(): void
    {
        $rm    = new ReflectionMethod('Cashback_Shop_Importer', 'try_lock');
        $body  = self::method_source($rm);
        $rm2   = new ReflectionMethod('Cashback_Shop_Importer', 'release_lock');
        $body2 = self::method_source($rm2);

        $this->assertStringContainsString('GET_LOCK', $body,
            'F-P1-002: try_lock() должен использовать GET_LOCK (атомарный mysql-level lock)');
        $this->assertStringContainsString('RELEASE_LOCK', $body2,
            'F-P1-002: release_lock() должен использовать RELEASE_LOCK');
        $this->assertStringNotContainsString('get_transient', $body,
            'F-P1-002: try_lock() не должен использовать get_transient (TOCTOU)');
        $this->assertStringNotContainsString('set_transient', $body,
            'F-P1-002: try_lock() не должен использовать set_transient (TOCTOU)');
    }

    private static function method_source(ReflectionMethod $rm): string
    {
        $file  = (string) $rm->getFileName();
        $start = (int) $rm->getStartLine();
        $end   = (int) $rm->getEndLine();
        $lines = file($file);
        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
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
        // network_id, run_id, offset, page_cursor, log_id. Последние два
        // опциональны для BC старых AS-jobs и прямых 3-arg вызовов.
        $this->assertCount(5, $params);
        $this->assertSame('network_id', $params[0]->getName());
        $this->assertSame('run_id', $params[1]->getName());
        $this->assertSame('offset', $params[2]->getName());
        $this->assertSame('page_cursor', $params[3]->getName());
        $this->assertTrue(
            $params[3]->isDefaultValueAvailable(),
            'page_cursor должен иметь default value (= 0) для BC: старый код вызывает run() с 3 args'
        );
        $this->assertSame(0, $params[3]->getDefaultValue());
        $this->assertSame('log_id', $params[4]->getName());
        $this->assertTrue(
            $params[4]->isDefaultValueAvailable(),
            'log_id должен иметь default value (= null) для BC: старый код вызывает run() без него'
        );
        $this->assertNull($params[4]->getDefaultValue());
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

    public function test_compute_signature_ignores_name_changes_for_existing_store_title(): void
    {
        $a = Cashback_Shop_Importer::compute_signature(Cashback_Campaign_Detail_DTO::from_array(array(
            'id' => '1', 'name' => 'Joom', 'site_url' => 'https://joom.com',
        )));
        $b = Cashback_Shop_Importer::compute_signature(Cashback_Campaign_Detail_DTO::from_array(array(
            'id' => '1', 'name' => 'Joom Ru', 'site_url' => 'https://joom.com',
        )));
        $this->assertSame(
            $a,
            $b,
            'Название магазина задаётся только при первом импорте и не должно триггерить update существующего товара.'
        );
    }

    public function test_update_existing_product_does_not_write_post_title(): void
    {
        $body = self::method_source(new ReflectionMethod('Cashback_Shop_Importer', 'update_existing_product'));

        $this->assertStringNotContainsString(
            "'post_title'",
            $body,
            'Повторный импорт не должен менять название существующего магазина.'
        );
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
        $GLOBALS['_cb_test_download_url_timeouts']   = array();
        $GLOBALS['_cb_test_handle_sideload_calls']   = array();
        $GLOBALS['_cb_test_insert_attachment_calls'] = array();
        $GLOBALS['_cb_test_filters']                 = array();
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
        $this->assertSame(
            array(),
            $GLOBALS['_cb_test_filters']['safe_svg_current_user_can_upload'] ?? array(),
            'safe_svg filter снимается даже при sideload-error.'
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

        // Happy-path должен снять все три фильтра в finally.
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
        $this->assertSame(
            array(),
            $GLOBALS['_cb_test_filters']['safe_svg_current_user_can_upload'] ?? array(),
            'safe_svg_current_user_can_upload filter снят после happy-path.'
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
        $this->assertSame('1', $bucket['_woodmart_product_custom_tab_priority'] ?? null);
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

    // ============================================================
    // fix/advcake-import-hang: жёсткие таймауты на download_url /
    // media_sideload_image. Без cap'а один медленный CDN-image
    // съедает все 300с AS-задачи (см. план 4.1).
    // ============================================================

    public function test_image_download_timeout_constant_is_15_seconds(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $this->assertTrue(
            $reflection->hasConstant('IMAGE_DOWNLOAD_TIMEOUT_SECONDS'),
            'IMAGE_DOWNLOAD_TIMEOUT_SECONDS должен существовать (cap image-download'
                . " timeout — главный фикс зависания Advcake-импортов на проде)."
        );
        $this->assertSame(
            15,
            $reflection->getConstant('IMAGE_DOWNLOAD_TIMEOUT_SECONDS'),
            '15с — порог: медленный SVG/raster CDN не должен блокировать всю AS-задачу.'
        );
    }

    public function test_safe_run_budget_constants_present(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $this->assertTrue($reflection->hasConstant('SAFE_RUN_BUDGET_SECONDS'));
        $this->assertSame(240, $reflection->getConstant('SAFE_RUN_BUDGET_SECONDS'),
            '240с = 80% от AS failure_period 300с. До конца — буфер на финализацию + re-enqueue.');
    }

    public function test_memory_budget_constants_present(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $this->assertTrue(
            $reflection->hasConstant('SAFE_MEMORY_USAGE_RATIO'),
            'Импортёр должен иметь memory guard: на проде Advcake падал от cgroup OOM до завершения run().'
        );
        $this->assertSame(0.70, $reflection->getConstant('SAFE_MEMORY_USAGE_RATIO'));
    }

    public function test_svg_sideload_passes_short_timeout_to_download_url(): void
    {
        $this->reset_sideload_globals();

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method     = $reflection->getMethod('attach_featured_image_from_url');
        $method->setAccessible(true);

        $method->invoke(null, 91, 'https://cdn.example.com/x.svg', 'adm', '500');

        $this->assertCount(1, $GLOBALS['_cb_test_download_url_calls']);
        $timeouts = $GLOBALS['_cb_test_download_url_timeouts'] ?? array();
        $this->assertCount(1, $timeouts);
        $this->assertSame(
            15,
            $timeouts[0],
            'download_url() должен вызываться со 2-м аргументом = IMAGE_DOWNLOAD_TIMEOUT_SECONDS (15с),'
                . ' а не дефолтным 300с — иначе один зависший CDN убивает весь батч импорта.'
        );
    }

    public function test_raster_sideload_caps_http_request_timeout_via_scoped_filter(): void
    {
        $this->reset_sideload_globals();
        $GLOBALS['_cb_test_filters'] = array();

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method     = $reflection->getMethod('attach_featured_image_from_url');
        $method->setAccessible(true);

        // Raster-путь: media_sideload_image-стуб внутри прогоняет дефолтный
        // timeout=300 через apply_filters('http_request_args', ...) — при
        // активном scoped-фильтре он должен прийти равным 15.
        $method->invoke(null, 33, 'https://cdn.example.com/logo.png', 'adm', '26006');

        $this->assertCount(1, $GLOBALS['_cb_test_media_sideload_calls']);
        $this->assertSame(
            15,
            $GLOBALS['_cb_test_media_sideload_calls'][0]['timeout'] ?? null,
            'Importer должен добавить scoped http_request_args фильтр, режущий'
                . ' timeout до IMAGE_DOWNLOAD_TIMEOUT_SECONDS вокруг media_sideload_image.'
        );

        $this->assertSame(
            array(),
            $GLOBALS['_cb_test_filters']['http_request_args'] ?? array(),
            'Scoped-фильтр должен быть снят в finally — иначе он повлияет на'
                . ' все последующие HTTP-запросы плагина в этом запросе/процессе.'
        );
    }

    public function test_raster_sideload_filter_is_removed_after_wp_error(): void
    {
        $this->reset_sideload_globals();
        $GLOBALS['_cb_test_filters']               = array();
        $GLOBALS['_cb_test_media_sideload_return'] = new \WP_Error('http_request_failed', 'CDN unreachable');

        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $method     = $reflection->getMethod('attach_featured_image_from_url');
        $method->setAccessible(true);

        $method->invoke(null, 34, 'https://cdn.example.com/logo.png', 'adm', '500');

        $this->assertSame(
            array(),
            $GLOBALS['_cb_test_filters']['http_request_args'] ?? array(),
            'http_request_args filter должен сниматься даже когда sideload вернул WP_Error.'
        );
    }

    public function test_run_source_contains_time_budget_guard(): void
    {
        $rm   = new ReflectionMethod('Cashback_Shop_Importer', 'run');
        $body = self::method_source($rm);

        $this->assertStringContainsString(
            'SAFE_RUN_BUDGET_SECONDS',
            $body,
            'run() должен использовать SAFE_RUN_BUDGET_SECONDS для time-budget guard.'
        );
        $this->assertMatchesRegularExpression(
            '/microtime\s*\(\s*true\s*\)/i',
            $body,
            'run() должен мерить wallclock через microtime(true) — без этого нет early-break.'
        );
        $this->assertStringContainsString(
            'as_enqueue_async_action',
            $body,
            'При превышении бюджета run() должен re-enqueue текущую страницу.'
        );
    }

    public function test_run_source_contains_memory_budget_guard(): void
    {
        $rm   = new ReflectionMethod('Cashback_Shop_Importer', 'run');
        $body = self::method_source($rm);

        $this->assertStringContainsString(
            'memory_get_usage(true)',
            $body,
            'run() должен проверять реальную выделенную память перед продолжением обработки пачки.'
        );
        $this->assertStringContainsString(
            'should_pause_for_memory_budget',
            $body,
            'Проверка памяти должна быть вынесена в helper, чтобы её можно было тестировать отдельно.'
        );
    }

    public function test_run_updates_progress_after_each_processed_offer(): void
    {
        $rm   = new ReflectionMethod('Cashback_Shop_Importer', 'run');
        $body = self::method_source($rm);

        $foreach_pos = strpos($body, 'foreach ($campaigns as $idx => $campaign)');
        $this->assertNotFalse($foreach_pos);

        $budget_pos   = strpos($body, 'SAFE_RUN_BUDGET_SECONDS', $foreach_pos);
        $progress_pos = strpos($body, 'Cashback_Shop_Import_Log::update_progress', $foreach_pos);

        $this->assertNotFalse($progress_pos, 'update_progress должен вызываться внутри foreach, а не только после всей страницы.');
        $this->assertNotFalse($budget_pos);
        $this->assertLessThan(
            $budget_pos,
            $progress_pos,
            'Прогресс нужно записывать до time/memory early-break, иначе AS/OOM оставляет fetched=0.'
        );
    }

    public function test_run_source_skips_processed_offers_via_cursor(): void
    {
        $rm   = new ReflectionMethod('Cashback_Shop_Importer', 'run');
        $body = self::method_source($rm);

        $this->assertStringContainsString(
            'page_cursor',
            $body,
            'run() должен принимать page_cursor и пропускать уже обработанные'
                . ' офферы при re-enqueue той же страницы (forward progress).'
        );
    }

    public function test_run_source_applies_throttle_between_offers(): void
    {
        $rm   = new ReflectionMethod('Cashback_Shop_Importer', 'run');
        $body = self::method_source($rm);

        $this->assertStringContainsString(
            'get_import_throttle_ms',
            $body,
            'run() должен реально использовать опцию cashback_shop_import_throttle_ms.'
        );
        $this->assertStringContainsString(
            'usleep',
            $body,
            'throttle применяется через usleep между офферами.'
        );
    }

    public function test_budget_resume_reuses_log_row_and_leaves_it_running_until_page_complete(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/shops/class-cashback-shop-importer.php');

        $this->assertMatchesRegularExpression(
            '/run\s*\([^)]*\$log_id\s*=\s*null/s',
            $source,
            'run() должен принимать optional log_id, чтобы resume текущей страницы не создавал новый import_log row.'
        );
        $this->assertMatchesRegularExpression(
            '/array\s*\(\s*\$network_id\s*,\s*\$run_id\s*,\s*\$offset\s*,\s*\$next_page_cursor\s*,\s*\$log_id\s*\)/s',
            $source,
            'budget-exceeded re-enqueue должен пробрасывать тот же log_id.'
        );
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$budget_exceeded\s*\)\s*\{[^}]*finish_page\s*\(\s*\$log_id\s*,\s*null\s*\)/s',
            $source,
            'finish_page(success) нельзя вызывать для partial-run: log row должен оставаться running до полного завершения страницы.'
        );
    }

    public function test_budget_resume_uses_page_cache_and_exposes_cursor_in_result(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/shops/class-cashback-shop-importer.php');

        $this->assertStringContainsString(
            'get_cached_import_page',
            $source,
            'resume с page_cursor > 0 должен читать уже скачанную страницу из transient-cache, а не повторять дорогой /offers?with_bids=1 fetch.'
        );
        $this->assertStringContainsString(
            'set_cached_import_page',
            $source,
            'первый fetch страницы должен класть payload в transient-cache для следующих resume ticks.'
        );
        $this->assertStringContainsString(
            'delete_cached_import_page',
            $source,
            'после полного завершения страницы transient-cache нужно удалить.'
        );
        $this->assertMatchesRegularExpression(
            "/'page_cursor'\\s*=>\\s*\\\$next_page_cursor/s",
            $source,
            'budget-exceeded return должен явно отдавать page_cursor для синхронных callers/debug.'
        );
    }

    public function test_run_uses_network_aware_batch_size(): void
    {
        $rm   = new ReflectionMethod('Cashback_Shop_Importer', 'run');
        $body = self::method_source($rm);

        $this->assertStringContainsString(
            'get_import_batch_size_for_network',
            $body,
            'run() должен выбирать batch size с учётом slug сети; Advcake нельзя импортировать общим batch=100.'
        );
    }

    public function test_tariff_side_effects_are_deferred_out_of_inner_sync(): void
    {
        $sync = self::method_source(new ReflectionMethod('Cashback_Shop_Importer', 'sync_tariffs_for_campaign'));
        $run  = self::method_source(new ReflectionMethod('Cashback_Shop_Importer', 'run'));

        $this->assertStringNotContainsString(
            "do_action('cashback_tariffs_changed'",
            $sync,
            'sync_tariffs_for_campaign не должен дергать тяжёлые side effects на каждый offer внутри bulk import.'
        );
        $this->assertStringContainsString(
            'flush_deferred_tariff_side_effects',
            $run,
            'run() должен сбрасывать накопленные product_id после пачки/partial-run отдельным шагом.'
        );
    }

    public function test_tariff_change_event_busts_dynamic_display_cache(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/cashback-plugin.php');

        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'cashback_tariffs_changed'\s*,\s*array\s*\(\s*'Cashback_Cashback_Display_Calculator'\s*,\s*'bust_cache_for_product'\s*\)/",
            $source,
            'cashback_tariffs_changed должен инвалидировать dynamic display cache, иначе витрина может показывать старую ставку до TTL.'
        );
    }

    public function test_importer_logs_memory_and_reschedule_diagnostics(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/shops/class-cashback-shop-importer.php');

        $this->assertStringContainsString('log_run_diagnostics', $source);
        $this->assertStringContainsString('memory_before', $source);
        $this->assertStringContainsString('memory_after', $source);
        $this->assertStringContainsString('reschedule_reason', $source);
    }
}
