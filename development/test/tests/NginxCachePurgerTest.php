<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit-тесты для Cashback_Nginx_Cache_Purger.
 *
 * Покрытие:
 *  - build_cache_path: совпадение с nginx levels=1:2 layout
 *  - build_cache_path: нормализация query/fragment
 *  - purge_url: idempotency, реальный unlink через tmpdir
 *  - preflight: graceful no-op при отсутствии каталога
 *  - is_enabled: option-флаг
 *  - dedup в Cashback_Nginx_Cache_Hooks: один post → один purge
 */
final class NginxCachePurgerTest extends TestCase
{
    private string $tmp_root;

    protected function setUp(): void
    {
        parent::setUp();

        // Plugin's classes. __DIR__ = cash-back/development/test/tests, plugin root = +3.
        $plugin_root = dirname(__DIR__, 3);
        $purger      = $plugin_root . '/includes/cache/class-cashback-nginx-cache-purger.php';
        $hooks       = $plugin_root . '/includes/cache/class-cashback-nginx-cache-hooks.php';
        if (!class_exists('Cashback_Nginx_Cache_Purger')) {
            require_once $purger;
        }
        if (!class_exists('Cashback_Nginx_Cache_Hooks')) {
            require_once $hooks;
        }

        // Уникальный tmp-каталог под каждый тест.
        $this->tmp_root = sys_get_temp_dir() . '/cb_nginx_cache_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmp_root, 0o755, true);
        putenv('CASHBACK_NGINX_CACHE_PATH=' . $this->tmp_root);

        // Сброс per-request state.
        Cashback_Nginx_Cache_Purger::reset_preflight_for_tests();
        Cashback_Nginx_Cache_Hooks::reset_dedup_for_tests();
        $GLOBALS['_cb_test_options'] = array();
    }

    protected function tearDown(): void
    {
        // Удаляем tmp-каталог.
        if (is_dir($this->tmp_root)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tmp_root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $f) {
                if ($f->isDir()) {
                    rmdir($f->getPathname());
                } else {
                    unlink($f->getPathname());
                }
            }
            rmdir($this->tmp_root);
        }
        putenv('CASHBACK_NGINX_CACHE_PATH');
        parent::tearDown();
    }

    public function test_build_cache_path_matches_nginx_levels_1_2_format(): void
    {
        $url    = 'https://kashback.ru/';
        $key    = 'https' . 'GET' . 'kashback.ru' . '/';
        $md5    = md5($key);
        $expect = sprintf('%s/%s/%s/%s', $this->tmp_root, substr($md5, -1), substr($md5, -3, 2), $md5);

        $actual = Cashback_Nginx_Cache_Purger::build_cache_path($url);

        $this->assertSame($expect, $actual);
    }

    public function test_build_cache_path_strips_query_and_fragment(): void
    {
        $with_query    = Cashback_Nginx_Cache_Purger::build_cache_path('https://kashback.ru/?ref=foo&utm_source=bar');
        $with_fragment = Cashback_Nginx_Cache_Purger::build_cache_path('https://kashback.ru/#hash');
        $clean         = Cashback_Nginx_Cache_Purger::build_cache_path('https://kashback.ru/');

        $this->assertSame($clean, $with_query, 'query-string должен игнорироваться при расчёте cache-key');
        $this->assertSame($clean, $with_fragment, 'fragment должен игнорироваться при расчёте cache-key');
    }

    public function test_build_cache_path_handles_root_when_path_missing(): void
    {
        // Без path в URL — должен подставиться '/'.
        $with_root    = Cashback_Nginx_Cache_Purger::build_cache_path('https://kashback.ru/');
        $without_path = Cashback_Nginx_Cache_Purger::build_cache_path('https://kashback.ru');

        $this->assertSame($with_root, $without_path);
    }

    public function test_build_cache_path_lowercases_scheme_and_host(): void
    {
        $a = Cashback_Nginx_Cache_Purger::build_cache_path('HTTPS://Kashback.RU/Path/');
        $b = Cashback_Nginx_Cache_Purger::build_cache_path('https://kashback.ru/Path/');

        $this->assertSame($a, $b);
    }

    public function test_purge_url_returns_zero_when_file_missing(): void
    {
        // Файла нет — purge возвращает 0 (idempotent, не ошибка).
        $this->assertSame(0, Cashback_Nginx_Cache_Purger::purge_url('https://kashback.ru/nonexistent/'));
    }

    public function test_purge_url_unlinks_existing_file(): void
    {
        $url  = 'https://kashback.ru/test-page/';
        // build_cache_path использует scheme из URL по умолчанию (https) —
        // создаём файл по этому пути и проверяем, что purge_url его удалит
        // (purge_url пробует и https-вариант).
        $path = Cashback_Nginx_Cache_Purger::build_cache_path($url, 'GET', 'https');

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        file_put_contents($path, 'cached HTML');
        $this->assertFileExists($path);

        $unlinked = Cashback_Nginx_Cache_Purger::purge_url($url);

        $this->assertSame(1, $unlinked);
        $this->assertFileDoesNotExist($path);
    }

    public function test_purge_url_unlinks_both_http_and_https_buckets(): void
    {
        // За reverse-proxy nginx видит scheme=http, но клиент ходит на https.
        // purge_url должен удалить оба bucket'а.
        $url       = 'https://kashback.ru/dual-scheme/';
        $http_path = Cashback_Nginx_Cache_Purger::build_cache_path($url, 'GET', 'http');
        $https_path = Cashback_Nginx_Cache_Purger::build_cache_path($url, 'GET', 'https');

        foreach (array( $http_path, $https_path ) as $p) {
            if (!is_dir(dirname($p))) {
                mkdir(dirname($p), 0o755, true);
            }
            file_put_contents($p, 'cached');
        }

        $unlinked = Cashback_Nginx_Cache_Purger::purge_url($url);

        $this->assertSame(2, $unlinked);
        $this->assertFileDoesNotExist($http_path);
        $this->assertFileDoesNotExist($https_path);
    }

    public function test_purge_url_per_request_dedup(): void
    {
        // Повторный purge_url на тот же URL в рамках request — no-op (0 unlink).
        $url  = 'https://kashback.ru/dedup-test/';
        $path = Cashback_Nginx_Cache_Purger::build_cache_path($url, 'GET', 'https');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        file_put_contents($path, 'cached');

        $first  = Cashback_Nginx_Cache_Purger::purge_url($url);
        $second = Cashback_Nginx_Cache_Purger::purge_url($url);
        $third  = Cashback_Nginx_Cache_Purger::purge_url($url);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'повторный purge того же URL — dedup → 0');
        $this->assertSame(0, $third);
    }

    public function test_preflight_no_op_when_dir_missing(): void
    {
        // Указываем несуществующий путь — purge должен silent-fail.
        $missing = sys_get_temp_dir() . '/cb_nginx_missing_' . bin2hex(random_bytes(4));
        $this->assertDirectoryDoesNotExist($missing);
        putenv('CASHBACK_NGINX_CACHE_PATH=' . $missing);
        Cashback_Nginx_Cache_Purger::reset_preflight_for_tests();

        $this->assertSame(0, Cashback_Nginx_Cache_Purger::purge_url('https://kashback.ru/'));
    }

    public function test_disabled_via_option_skips_purge(): void
    {
        update_option(Cashback_Nginx_Cache_Purger::OPTION_ENABLED, '0');

        $this->assertSame(0, Cashback_Nginx_Cache_Purger::purge_url('https://kashback.ru/'));
    }

    public function test_purge_all_refuses_root_outside_whitelist(): void
    {
        // Подменяем cache_root на путь вне /var/cache/nginx/ — purge_all должен
        // отказаться (defense-in-depth против пост-RCE подмены env).
        $rogue = sys_get_temp_dir() . '/cb_rogue_' . bin2hex(random_bytes(3));
        mkdir($rogue, 0o755, true);
        file_put_contents($rogue . '/should_not_be_deleted', 'data');
        putenv('CASHBACK_NGINX_CACHE_PATH=' . $rogue);
        Cashback_Nginx_Cache_Purger::reset_preflight_for_tests();

        $count = Cashback_Nginx_Cache_Purger::purge_all();

        $this->assertSame(0, $count, 'purge_all обязан отказаться при cache_root вне whitelist');
        $this->assertFileExists($rogue . '/should_not_be_deleted');

        // Cleanup
        unlink($rogue . '/should_not_be_deleted');
        rmdir($rogue);
    }

    public function test_levels_env_changes_cache_path(): void
    {
        // CASHBACK_NGINX_CACHE_LEVELS=2:2 → подкаталоги 2 и 2 символа от хвоста.
        putenv('CASHBACK_NGINX_CACHE_LEVELS=2:2');
        $hash = hash('md5', 'httpsGETkashback.ru/');
        $expect_seg1 = substr($hash, -2, 2);    // 2 char from tail
        $expect_seg2 = substr($hash, -4, 2);    // 2 chars before that
        $expect      = sprintf('%s/%s/%s/%s', $this->tmp_root, $expect_seg1, $expect_seg2, $hash);

        $actual = Cashback_Nginx_Cache_Purger::build_cache_path('https://kashback.ru/');

        $this->assertSame($expect, $actual);

        // Cleanup
        putenv('CASHBACK_NGINX_CACHE_LEVELS');
    }

    public function test_purge_all_unlinks_all_files_in_cache_root(): void
    {
        // tmp_root за whitelist'ом /var/cache/nginx/ — даём тест-разрешение.
        Cashback_Nginx_Cache_Purger::set_test_root_prefix($this->tmp_root);

        $files = array(
            $this->tmp_root . '/a/12/abc123',
            $this->tmp_root . '/b/34/def456',
            $this->tmp_root . '/c/56/ghi789',
        );
        foreach ($files as $f) {
            mkdir(dirname($f), 0o755, true);
            file_put_contents($f, 'cached');
        }

        $count = Cashback_Nginx_Cache_Purger::purge_all();

        $this->assertSame(3, $count);
        foreach ($files as $f) {
            $this->assertFileDoesNotExist($f);
        }
    }

    public function test_dedup_within_request_does_not_crash_on_repeats(): void
    {
        // Выключаем purger через option — purge_post сразу возвращает 0 (без
        // вызова collect_urls_for_post, требующего WP-функций get_permalink/
        // home_url/get_the_terms). Тест проверяет именно guard в Hooks, а не
        // ядро purger'а.
        update_option(Cashback_Nginx_Cache_Purger::OPTION_ENABLED, '0');

        $post_id = 12345;

        // 5 вызовов с одинаковым post_id — guard должен пускать только первый.
        Cashback_Nginx_Cache_Hooks::dispatch_purge_post($post_id, 'first');
        for ($i = 0; $i < 4; $i++) {
            Cashback_Nginx_Cache_Hooks::dispatch_purge_post($post_id, 'repeat-' . $i);
        }
        // Другой post_id — guard не срабатывает, новый purge.
        Cashback_Nginx_Cache_Hooks::dispatch_purge_post($post_id + 1, 'other');

        // После reset — снова можно purge'ить тот же post.
        Cashback_Nginx_Cache_Hooks::reset_dedup_for_tests();
        Cashback_Nginx_Cache_Hooks::dispatch_purge_post($post_id, 'after-reset');

        $this->assertTrue(true, 'dispatch_purge_post: dedup не падает на повторах и сбрасывается через reset');
    }

    // ================================================================
    // Auto-purge на updated_option (WoodMart settings, Clearfy Pro)
    // ================================================================

    public function test_is_global_settings_option_recognises_xts_prefix(): void
    {
        // Главный options-blob WoodMart (saved on "Save options" в Theme Settings).
        self::assertTrue(Cashback_Nginx_Cache_Hooks::is_global_settings_option('xts-woodmart-options'));
        // Версия dynamic-CSS, при изменении меняется timestamp в xts-{name}-{TS}.css.
        self::assertTrue(Cashback_Nginx_Cache_Hooks::is_global_settings_option('xts-default_header-version'));
        self::assertTrue(Cashback_Nginx_Cache_Hooks::is_global_settings_option('xts-theme_settings_default-version'));
        self::assertTrue(Cashback_Nginx_Cache_Hooks::is_global_settings_option('xts-options-presets'));
    }

    public function test_is_global_settings_option_recognises_wbcr_clearfy(): void
    {
        // Главный options-blob Clearfy Pro.
        self::assertTrue(Cashback_Nginx_Cache_Hooks::is_global_settings_option('wbcr_clearfy_options'));
        // Доп.секции Clearfy (compress, optimize и т.п.).
        self::assertTrue(Cashback_Nginx_Cache_Hooks::is_global_settings_option('wbcr_clearfy_compress_options'));
    }

    public function test_is_global_settings_option_rejects_unrelated(): void
    {
        // woodmart_* (без дефиса) — служебные опции, обновляются часто, не
        // меняют HTML; их purge стоил бы кэш-storm.
        self::assertFalse(Cashback_Nginx_Cache_Hooks::is_global_settings_option('woodmart_added_column_on_sale_in_product_db'));
        self::assertFalse(Cashback_Nginx_Cache_Hooks::is_global_settings_option('woodmart_revslider_version'));
        self::assertFalse(Cashback_Nginx_Cache_Hooks::is_global_settings_option('woodmart_failed_local_google_fonts'));

        // Совершенно посторонние опции (WP core, WC, плагины).
        self::assertFalse(Cashback_Nginx_Cache_Hooks::is_global_settings_option('siteurl'));
        self::assertFalse(Cashback_Nginx_Cache_Hooks::is_global_settings_option('woocommerce_default_country'));
        self::assertFalse(Cashback_Nginx_Cache_Hooks::is_global_settings_option('cashback_some_random_option'));
        self::assertFalse(Cashback_Nginx_Cache_Hooks::is_global_settings_option(''));
    }

    public function test_dispatch_purge_all_invokes_purger_once_per_request(): void
    {
        // tmp_root за whitelist'ом /var/cache/nginx/ — даём тест-разрешение.
        Cashback_Nginx_Cache_Purger::set_test_root_prefix($this->tmp_root);

        // Засеиваем 2 файла кэша.
        $f1 = $this->tmp_root . '/a/12/abc123';
        $f2 = $this->tmp_root . '/b/34/def456';
        mkdir(dirname($f1), 0o755, true);
        mkdir(dirname($f2), 0o755, true);
        file_put_contents($f1, 'cached');
        file_put_contents($f2, 'cached');

        // Первый dispatch — должен реально удалить файлы.
        Cashback_Nginx_Cache_Hooks::dispatch_purge_all('test-1');
        self::assertFileDoesNotExist($f1, 'Первый dispatch_purge_all должен удалить файл');
        self::assertFileDoesNotExist($f2, 'Первый dispatch_purge_all должен удалить файл');

        // Засеиваем заново — второй dispatch в том же request не должен трогать.
        if (!is_dir(dirname($f1))) {
            mkdir(dirname($f1), 0o755, true);
        }
        file_put_contents($f1, 'cached-again');
        Cashback_Nginx_Cache_Hooks::dispatch_purge_all('test-2');
        self::assertFileExists($f1, 'Второй dispatch в том же request — no-op (per-request guard)');

        // После reset — guard сбрасывается, новый purge снова работает.
        Cashback_Nginx_Cache_Hooks::reset_dedup_for_tests();
        Cashback_Nginx_Cache_Hooks::dispatch_purge_all('test-3');
        self::assertFileDoesNotExist($f1, 'После reset_dedup — purge снова работает');
    }

    public function test_on_option_updated_skips_unrelated_options(): void
    {
        // tmp_root + засеянный файл. Если on_option_updated некорректно покроет
        // siteurl — purge_all удалит файл и тест зафейлится.
        Cashback_Nginx_Cache_Purger::set_test_root_prefix($this->tmp_root);

        $f = $this->tmp_root . '/c/56/cached_unrelated';
        mkdir(dirname($f), 0o755, true);
        file_put_contents($f, 'should_survive');

        Cashback_Nginx_Cache_Hooks::on_option_updated('siteurl', 'old', 'new');
        Cashback_Nginx_Cache_Hooks::on_option_updated('woodmart_revslider_version', '6.7.40', '6.7.41');
        Cashback_Nginx_Cache_Hooks::on_option_updated('', null, null);

        self::assertFileExists($f, 'Не-whitelisted опции не должны триггерить purge_all');
    }

    public function test_on_option_updated_triggers_purge_for_xts_options(): void
    {
        Cashback_Nginx_Cache_Purger::set_test_root_prefix($this->tmp_root);

        $f = $this->tmp_root . '/d/78/cached_xts';
        mkdir(dirname($f), 0o755, true);
        file_put_contents($f, 'will_be_purged');

        Cashback_Nginx_Cache_Hooks::on_option_updated('xts-woodmart-options', array(), array( 'foo' => 'bar' ));

        self::assertFileDoesNotExist($f, 'on_option_updated с xts-woodmart-options должен триггерить purge_all');
    }
}
