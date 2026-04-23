<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты централизованного enqueue-хелпера Cashback_Assets — Группа 9 ADR.
 *
 * Цели (TDD RED phase):
 *  1. Класс Cashback_Assets существует и подключён в load_dependencies().
 *  2. Метод enqueue_safe_html() регистрирует handle 'dompurify' + 'cashback-safe-html'.
 *  3. Handle 'cashback-safe-html' зависит от 'dompurify'.
 *  4. Файл safe-html.js переехал в общий assets/js/ (вне support/).
 *  5. Фолбэк в safe-html.js — fail-closed: при отсутствии DOMPurify возвращает '',
 *     а не сырую строку (исключает passive XSS при сбое загрузки DOMPurify).
 *  6. purify.min.js найден по пути, указанному в src handle 'dompurify' (версия 3.3.2).
 *
 * См. ADR Группа 9, findings F-33-003, F-38-001, F-38-002, F-40-001.
 *
 * Запуск:
 *   ./vendor/bin/phpunit --filter CashbackAssetsTest tests/CashbackAssetsTest.php
 */
#[Group('security')]
#[Group('group9')]
#[Group('xss')]
class CashbackAssetsTest extends TestCase
{
    private string $plugin_root;

    protected function setUp(): void
    {
        $this->plugin_root = dirname(__DIR__, 3);

        // Чистый слепок перехватчика enqueue перед каждым тестом.
        $GLOBALS['_cb_test_enqueued_scripts'] = array();
        $GLOBALS['_cb_test_enqueued_styles']  = array();

        $file = $this->plugin_root . '/includes/class-cashback-assets.php';
        if (file_exists($file) && ! class_exists('Cashback_Assets')) {
            require_once $file;
        }
    }

    // ================================================================
    // Класс + метод
    // ================================================================

    public function test_cashback_assets_class_exists(): void
    {
        self::assertTrue(
            class_exists('Cashback_Assets'),
            'Cashback_Assets class must exist in includes/class-cashback-assets.php'
        );
    }

    public function test_enqueue_safe_html_method_exists_and_is_public_static(): void
    {
        self::assertTrue(class_exists('Cashback_Assets'));
        self::assertTrue(
            method_exists('Cashback_Assets', 'enqueue_safe_html'),
            'Cashback_Assets::enqueue_safe_html() must exist'
        );

        $ref = new ReflectionMethod('Cashback_Assets', 'enqueue_safe_html');
        self::assertTrue($ref->isPublic(), 'Метод должен быть public — вызывается из модулей');
        self::assertTrue($ref->isStatic(), 'Метод должен быть static — нет инстанса/DI');
    }

    // ================================================================
    // Регистрация handle'ов
    // ================================================================

    public function test_enqueue_safe_html_registers_dompurify_handle(): void
    {
        self::assertTrue(class_exists('Cashback_Assets'));

        Cashback_Assets::enqueue_safe_html();

        $enqueued = $GLOBALS['_cb_test_enqueued_scripts'] ?? array();
        self::assertArrayHasKey(
            'dompurify',
            $enqueued,
            'Handle "dompurify" должен быть зарегистрирован/enqueue-нут'
        );

        $dompurify = $enqueued['dompurify'];
        self::assertStringContainsString(
            'purify.min.js',
            (string) $dompurify['src'],
            'src handle "dompurify" должен указывать на purify.min.js'
        );
        self::assertSame(
            '3.3.2',
            (string) $dompurify['ver'],
            'Версия DOMPurify должна быть пин 3.3.2 (как в support-модуле)'
        );
    }

    public function test_enqueue_safe_html_registers_cashback_safe_html_handle(): void
    {
        self::assertTrue(class_exists('Cashback_Assets'));

        Cashback_Assets::enqueue_safe_html();

        $enqueued = $GLOBALS['_cb_test_enqueued_scripts'] ?? array();
        self::assertArrayHasKey(
            'cashback-safe-html',
            $enqueued,
            'Handle "cashback-safe-html" должен быть зарегистрирован/enqueue-нут'
        );

        $handle = $enqueued['cashback-safe-html'];
        self::assertStringContainsString(
            'safe-html.js',
            (string) $handle['src'],
            'src handle "cashback-safe-html" должен указывать на safe-html.js'
        );
        self::assertContains(
            'dompurify',
            (array) $handle['deps'],
            'Handle "cashback-safe-html" должен иметь "dompurify" в зависимостях'
        );
    }

    public function test_enqueue_safe_html_is_idempotent(): void
    {
        self::assertTrue(class_exists('Cashback_Assets'));

        Cashback_Assets::enqueue_safe_html();
        Cashback_Assets::enqueue_safe_html();

        // Повторный вызов не должен бросать Warning от wp_register_script, handle всё ещё один.
        $enqueued = $GLOBALS['_cb_test_enqueued_scripts'] ?? array();
        self::assertArrayHasKey('dompurify', $enqueued);
        self::assertArrayHasKey('cashback-safe-html', $enqueued);
    }

    // ================================================================
    // Физическое расположение файлов
    // ================================================================

    public function test_safe_html_js_moved_to_shared_assets_dir(): void
    {
        $new_path = $this->plugin_root . '/assets/js/safe-html.js';
        self::assertFileExists(
            $new_path,
            'safe-html.js должен лежать в общем assets/js/ (перенос из support/assets/js/)'
        );
    }

    public function test_purify_min_js_still_accessible_by_enqueue_src(): void
    {
        // Путь файла purify.min.js может остаться в support/assets/js/, но тогда
        // src handle должен указывать на существующий файл. Проверяем, что файл,
        // на который указывает enqueue, реально существует.
        self::assertTrue(class_exists('Cashback_Assets'));

        Cashback_Assets::enqueue_safe_html();
        $enqueued  = $GLOBALS['_cb_test_enqueued_scripts'] ?? array();
        $dompurify = $enqueued['dompurify'] ?? null;
        self::assertNotNull($dompurify);

        $src = (string) $dompurify['src'];
        // plugins_url в тестах возвращает http://localhost/wp-content/plugins/...,
        // вытаскиваем относительный путь после plugins/ и проверяем реальный файл.
        $relative = (string) preg_replace('#^https?://[^/]+/wp-content/plugins/#', '', $src);

        // Возможные варианты: 'cash-back/support/assets/js/purify.min.js' или 'cash-back/assets/js/purify.min.js'.
        // Вырезаем префикс имени плагина, если есть.
        $relative = (string) preg_replace('#^[^/]+/#', '', $relative, 1);

        $file = $this->plugin_root . '/' . $relative;
        self::assertFileExists(
            $file,
            sprintf('Файл purify.min.js по src="%s" должен существовать (resolved: %s)', $src, $file)
        );
    }

    // ================================================================
    // Fail-closed fallback в safe-html.js
    // ================================================================

    public function test_safe_html_js_has_fail_closed_fallback(): void
    {
        $new_path = $this->plugin_root . '/assets/js/safe-html.js';
        if (!file_exists($new_path)) {
            self::fail('safe-html.js ещё не перенесён в assets/js/ — невозможно проверить fail-closed');
        }

        $content = (string) file_get_contents($new_path);

        // Fail-closed: функция НЕ должна в конце возвращать dirty-аргумент passthrough.
        // Ищем старый паттерн `return dirty;` вне блока if-DOMPurify — его быть не должно.
        // Используем normalized whitespace-check.
        $normalized = (string) preg_replace('/\s+/', ' ', $content);

        self::assertStringNotContainsString(
            '} return dirty; }',
            $normalized,
            'Fallback "return dirty" вне if-DOMPurify = passthrough → XSS. Должен быть fail-closed.'
        );

        // Должен присутствовать console.error для dev-визибилити.
        self::assertMatchesRegularExpression(
            '/console\.(error|warn)/i',
            $content,
            'При отсутствии DOMPurify safe-html должен писать в console.error/warn (dev-visibility)'
        );

        // Должен возвращать пустую строку (либо бросать) как fail-closed.
        self::assertMatchesRegularExpression(
            "/return\s+['\"]{2}\s*;/",
            $content,
            'При отсутствии DOMPurify safe-html должен возвращать пустую строку (fail-closed)'
        );
    }

    // ================================================================
    // Подключение в основной файл плагина
    // ================================================================

    public function test_class_is_wired_into_plugin_load_dependencies(): void
    {
        $plugin_file = $this->plugin_root . '/cashback-plugin.php';
        self::assertFileExists($plugin_file);

        $content = (string) file_get_contents($plugin_file);
        self::assertStringContainsString(
            'class-cashback-assets.php',
            $content,
            'cashback-plugin.php::load_dependencies() должен подключать includes/class-cashback-assets.php'
        );
    }
}
