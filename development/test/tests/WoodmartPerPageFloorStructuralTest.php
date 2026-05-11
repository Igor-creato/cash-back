<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тест Cashback_Woodmart_Per_Page_Floor — defense-in-depth фильтр на
 * woodmart_get_min_per_page. Поднимает минимум до 9 (нижняя граница admin-списка
 * 9/12/18/24 в WoodMart Theme Options), отвергая мусорные значения вроде 5,
 * которые WoodMart-функция функции `woodmart_get_current_products_per_page()`
 * иначе принимает (валидирует только диапазон [-1, 500]).
 *
 * @see knowledge/debugging/woodmart-rest-per_page-leak.md
 */
#[Group('woodmart')]
#[Group('per-page')]
class WoodmartPerPageFloorStructuralTest extends TestCase
{
    private string $plugin_root;

    protected function setUp(): void
    {
        $this->plugin_root = dirname(__DIR__, 3);

        $file = $this->plugin_root . '/includes/class-cashback-woodmart-per-page-floor.php';
        if (file_exists($file) && !class_exists('Cashback_Woodmart_Per_Page_Floor')) {
            require_once $file;
        }

        // Чистим test-filter-state перед каждым тестом — глобальный bootstrap.
        $GLOBALS['_cb_test_filters'] = array();
    }

    public function test_class_file_exists(): void
    {
        self::assertFileExists(
            $this->plugin_root . '/includes/class-cashback-woodmart-per-page-floor.php',
            'includes/class-cashback-woodmart-per-page-floor.php должен существовать'
        );
    }

    public function test_class_exists(): void
    {
        self::assertTrue(
            class_exists('Cashback_Woodmart_Per_Page_Floor'),
            'Класс Cashback_Woodmart_Per_Page_Floor должен быть определён'
        );
    }

    public function test_init_is_public_static(): void
    {
        $ref = new ReflectionMethod('Cashback_Woodmart_Per_Page_Floor', 'init');
        self::assertTrue($ref->isPublic(), 'init() должен быть public');
        self::assertTrue($ref->isStatic(), 'init() должен быть static');
    }

    public function test_init_registers_filter(): void
    {
        Cashback_Woodmart_Per_Page_Floor::init();

        self::assertArrayHasKey(
            'woodmart_get_min_per_page',
            $GLOBALS['_cb_test_filters'],
            'init() должен зарегистрировать фильтр woodmart_get_min_per_page'
        );
        self::assertNotEmpty(
            $GLOBALS['_cb_test_filters']['woodmart_get_min_per_page'],
            'Фильтр woodmart_get_min_per_page должен иметь хотя бы один callback'
        );
    }

    public function test_floor_raises_low_values_to_min_allowed(): void
    {
        self::assertSame(
            9,
            Cashback_Woodmart_Per_Page_Floor::floor(-1),
            'WoodMart дефолт -1 должен быть поднят до 9 (нижняя граница admin-списка 9/12/18/24)'
        );
        self::assertSame(9, Cashback_Woodmart_Per_Page_Floor::floor(0));
        self::assertSame(9, Cashback_Woodmart_Per_Page_Floor::floor(5));
        self::assertSame(9, Cashback_Woodmart_Per_Page_Floor::floor(8));
    }

    public function test_floor_passes_through_allowed_values(): void
    {
        self::assertSame(9, Cashback_Woodmart_Per_Page_Floor::floor(9));
        self::assertSame(12, Cashback_Woodmart_Per_Page_Floor::floor(12));
        self::assertSame(18, Cashback_Woodmart_Per_Page_Floor::floor(18));
        self::assertSame(24, Cashback_Woodmart_Per_Page_Floor::floor(24));
        self::assertSame(
            36,
            Cashback_Woodmart_Per_Page_Floor::floor(36),
            'Значения выше 9 (включая legit shop-extras типа 36) не трогаем'
        );
    }

    public function test_filter_callback_applies_floor(): void
    {
        Cashback_Woodmart_Per_Page_Floor::init();

        $result = apply_filters('woodmart_get_min_per_page', -1);

        self::assertSame(9, $result, 'Через apply_filters низкое значение должно быть поднято до 9');
    }

    public function test_filter_callback_idempotent_on_already_high_values(): void
    {
        Cashback_Woodmart_Per_Page_Floor::init();

        $result = apply_filters('woodmart_get_min_per_page', 12);

        self::assertSame(12, $result, 'apply_filters не должен понижать значения >= 9');
    }

    public function test_class_wired_into_plugin_load(): void
    {
        $plugin_file = $this->plugin_root . '/cashback-plugin.php';
        $content     = (string) file_get_contents($plugin_file);

        self::assertStringContainsString(
            'class-cashback-woodmart-per-page-floor.php',
            $content,
            'cashback-plugin.php должен подключать новый класс'
        );
        self::assertStringContainsString(
            'Cashback_Woodmart_Per_Page_Floor::init()',
            $content,
            'cashback-plugin.php должен вызывать Cashback_Woodmart_Per_Page_Floor::init()'
        );
    }
}
