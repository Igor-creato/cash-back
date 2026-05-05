<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты на Cashback_Coupons_Icons_Shortcode — рендер [cashback_coupons_icons].
 *
 * Шорткод выводит SVG-иконки активных купонов товара, по одной на УНИКАЛЬНЫЙ
 * icon_type (discount/gift/free_shipping). Иконки берутся из опции
 * cashback_coupons_icons_settings (attachment_id из Media Library).
 * При клике — переход на permalink товара с ?cb_tab=coupons.
 *
 * @group promocodes
 * @group coupons-icons
 * @group shortcode
 */
#[Group('promocodes')]
#[Group('coupons-icons')]
#[Group('shortcode')]
final class CouponsIconsShortcodeTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        $files = array(
            '/includes/promocodes/dto/class-coupon-dto.php',
            '/includes/promocodes/class-cashback-promocodes-repository.php',
            '/includes/promocodes/class-cashback-coupons-icon-resolver.php',
            '/includes/promocodes/class-cashback-coupons-icons-shortcode.php',
        );
        foreach ($files as $f) {
            $path = self::$plugin_root . $f;
            if (!file_exists($path)) {
                self::markTestSkipped("File missing: {$f}");
            }
            require_once $path;
        }

        if (!function_exists('shortcode_atts')) {
            function shortcode_atts(array $pairs, array $atts, string $shortcode = ''): array {
                return array_merge($pairs, is_array($atts) ? $atts : array());
            }
        }
        if (!function_exists('add_shortcode')) {
            function add_shortcode(string $tag, callable $callback): bool {
                $GLOBALS['_cb_test_shortcodes'][$tag] = $callback;
                return true;
            }
        }
        if (!function_exists('get_the_ID')) {
            function get_the_ID() {
                return $GLOBALS['_cb_test_get_the_id'] ?? 0;
            }
        }
        if (!function_exists('get_post_meta')) {
            function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed {
                $store = $GLOBALS['_cb_test_meta'][$post_id] ?? array();
                if ($key === '') return $store;
                if (!isset($store[$key])) return $single ? '' : array();
                return $single ? $store[$key] : array($store[$key]);
            }
        }
        if (!function_exists('get_option')) {
            function get_option(string $name, mixed $default = false): mixed {
                return $GLOBALS['_cb_test_options'][$name] ?? $default;
            }
        }
        if (!function_exists('get_permalink')) {
            function get_permalink( int $post_id = 0 ): string {
                return 'http://localhost/?p=' . $post_id;
            }
        }
        if (!function_exists('add_query_arg')) {
            function add_query_arg(...$args): string {
                if (is_array($args[0])) {
                    $params = $args[0];
                    $url    = (string) ($args[1] ?? '');
                } else {
                    $params = array((string) $args[0] => (string) $args[1]);
                    $url    = (string) ($args[2] ?? '');
                }
                $sep = strpos($url, '?') === false ? '?' : '&';
                $qs  = http_build_query($params);
                return $url . $sep . $qs;
            }
        }
        if (!function_exists('esc_url')) {
            function esc_url(string $url): string {
                return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            }
        }
        if (!function_exists('esc_attr')) {
            function esc_attr(string $text): string {
                return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }
        if (!function_exists('esc_html')) {
            function esc_html(string $text): string {
                return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }
        if (!function_exists('esc_html__')) {
            function esc_html__(string $text, string $domain = ''): string {
                return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }
        if (!function_exists('__')) {
            function __(string $text, string $domain = ''): string {
                return $text;
            }
        }
        if (!function_exists('sanitize_html_class')) {
            function sanitize_html_class(string $class, string $fallback = ''): string {
                $class = preg_replace('/[^A-Za-z0-9_-]/', '', $class) ?? '';
                return ($class !== '') ? $class : $fallback;
            }
        }
        if (!function_exists('wp_get_attachment_image_url')) {
            function wp_get_attachment_image_url(int $aid, string $size = 'thumbnail'): string|false {
                $store = $GLOBALS['_cb_test_attachments'] ?? array();
                return isset($store[$aid]) ? (string) $store[$aid] : false;
            }
        }
        if (!function_exists('wp_get_attachment_image')) {
            function wp_get_attachment_image(int $aid, mixed $size = 'thumbnail', bool $icon = false, array $attr = array()): string {
                $url = wp_get_attachment_image_url($aid, is_string($size) ? $size : 'thumbnail');
                if ($url === false) return '';
                $w = is_array($size) ? (int) ($size[0] ?? 32) : 32;
                $h = is_array($size) ? (int) ($size[1] ?? 32) : 32;
                $alt = isset($attr['alt']) ? (string) $attr['alt'] : '';
                $loading = isset($attr['loading']) ? ' loading="' . esc_attr((string) $attr['loading']) . '"' : '';
                return '<img src="' . esc_url($url) . '" width="' . $w . '" height="' . $h . '" alt="' . esc_attr($alt) . '"' . $loading . ' />';
            }
        }
        if (!function_exists('wp_enqueue_style')) {
            function wp_enqueue_style(string $handle, string $src = '', array $deps = array(), mixed $ver = false): void {
                $GLOBALS['_cb_test_enqueued_styles'][$handle] = compact('handle', 'src', 'deps', 'ver');
            }
        }
        if (!function_exists('plugins_url')) {
            function plugins_url(string $path = '', string $base = ''): string {
                return 'https://example.test/wp-content/plugins/cash-back/' . ltrim($path, '/');
            }
        }
        if (!function_exists('wc_get_product')) {
            function wc_get_product(int $id) {
                $store = $GLOBALS['_cb_test_products'] ?? array();
                return $store[$id] ?? null;
            }
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_shortcodes']      = array();
        $GLOBALS['_cb_test_get_the_id']      = 0;
        $GLOBALS['_cb_test_meta']            = array();
        $GLOBALS['_cb_test_options']         = array();
        $GLOBALS['_cb_test_attachments']     = array();
        $GLOBALS['_cb_test_products']        = array();
        $GLOBALS['_cb_test_enqueued_styles'] = array();

        // Сброс per-request кэша шорткода.
        if (class_exists('Cashback_Coupons_Icons_Shortcode')) {
            $ref = new ReflectionClass(Cashback_Coupons_Icons_Shortcode::class);
            if ($ref->hasProperty('request_cache')) {
                $prop = $ref->getProperty('request_cache');
                $prop->setAccessible(true);
                $prop->setValue(null, array());
            }
        }
    }

    private function make_repo_stub(array $rows): object
    {
        return new class($rows) extends Cashback_Promocodes_Repository {
            public array $calls = array();
            public function __construct(public array $rows) {}
            public function get_distinct_species_for_campaign(int $network_id, string $advcampaign_id): array {
                $this->calls[] = array($network_id, $advcampaign_id);
                return $this->rows;
            }
        };
    }

    private function setup_product_with_meta(int $product_id = 123, int $network_id = 5, string $offer = '35530'): void
    {
        $GLOBALS['_cb_test_meta'][$product_id] = array(
            '_affiliate_network_id' => (string) $network_id,
            '_offer_id'             => $offer,
        );
    }

    private function setup_all_icons(): void
    {
        $GLOBALS['_cb_test_options']['cashback_coupons_icons_settings'] = array(
            'discount'      => 101,
            'gift'          => 102,
            'free_shipping' => 103,
        );
        $GLOBALS['_cb_test_attachments'][101] = 'https://example.test/uploads/coupon.svg';
        $GLOBALS['_cb_test_attachments'][102] = 'https://example.test/uploads/gift.svg';
        $GLOBALS['_cb_test_attachments'][103] = 'https://example.test/uploads/free-delivery.svg';
    }

    public function test_render_returns_empty_when_no_product_id_and_no_post_context(): void
    {
        $repo = $this->make_repo_stub(array(array('species' => 'promocode', 'name' => '', 'description' => '')));
        $sc   = new Cashback_Coupons_Icons_Shortcode($repo);

        $this->assertSame('', $sc->render(array()));
        $this->assertCount(0, $repo->calls, 'Без product_id repository не должен вызываться');
    }

    public function test_render_returns_empty_when_no_postmeta(): void
    {
        $repo = $this->make_repo_stub(array(array('species' => 'promocode', 'name' => '', 'description' => '')));
        $sc   = new Cashback_Coupons_Icons_Shortcode($repo);

        $this->assertSame('', $sc->render(array('id' => '123')));
        $this->assertCount(0, $repo->calls);
    }

    public function test_render_returns_empty_when_no_active_rows(): void
    {
        $this->setup_product_with_meta();
        $this->setup_all_icons();
        $repo = $this->make_repo_stub(array());
        $sc   = new Cashback_Coupons_Icons_Shortcode($repo);

        $this->assertSame('', $sc->render(array('id' => '123')));
    }

    public function test_render_returns_empty_when_no_options_set(): void
    {
        $this->setup_product_with_meta();
        // Опция не задана.
        $repo = $this->make_repo_stub(array(
            array('species' => 'promocode', 'name' => '', 'description' => ''),
        ));
        $sc   = new Cashback_Coupons_Icons_Shortcode($repo);

        $this->assertSame('', $sc->render(array('id' => '123')));
    }

    public function test_render_outputs_three_icons_in_stable_order_for_three_species(): void
    {
        $this->setup_product_with_meta();
        $this->setup_all_icons();

        $repo = $this->make_repo_stub(array(
            array('species' => 'gift',          'name' => '', 'description' => ''),
            array('species' => 'promocode',     'name' => '', 'description' => ''),
            array('species' => 'free_shipping', 'name' => '', 'description' => ''),
        ));
        $sc = new Cashback_Coupons_Icons_Shortcode($repo);

        $html = $sc->render(array('id' => '123'));

        // Стабильный порядок: discount → gift → free_shipping.
        $pos_discount = strpos($html, 'cashback-coupons-icons__item--discount');
        $pos_gift     = strpos($html, 'cashback-coupons-icons__item--gift');
        $pos_shipping = strpos($html, 'cashback-coupons-icons__item--free_shipping');

        $this->assertNotFalse($pos_discount);
        $this->assertNotFalse($pos_gift);
        $this->assertNotFalse($pos_shipping);
        $this->assertLessThan($pos_gift, $pos_discount, 'discount должна идти раньше gift');
        $this->assertLessThan($pos_shipping, $pos_gift, 'gift должна идти раньше free_shipping');
    }

    public function test_render_distinct_collapses_duplicate_icon_types(): void
    {
        $this->setup_product_with_meta();
        $this->setup_all_icons();

        // 5 promocode + 1 gift → 2 иконки (discount + gift), не 6.
        $rows = array_fill(0, 5, array('species' => 'promocode', 'name' => '', 'description' => ''));
        $rows[] = array('species' => 'gift', 'name' => '', 'description' => '');
        $repo = $this->make_repo_stub($rows);

        $sc   = new Cashback_Coupons_Icons_Shortcode($repo);
        $html = $sc->render(array('id' => '123'));

        $count_discount = substr_count($html, 'cashback-coupons-icons__item--discount');
        $count_gift     = substr_count($html, 'cashback-coupons-icons__item--gift');

        $this->assertSame(1, $count_discount);
        $this->assertSame(1, $count_gift);
    }

    public function test_render_href_contains_cb_tab_query_param(): void
    {
        $this->setup_product_with_meta();
        $this->setup_all_icons();
        $repo = $this->make_repo_stub(array(array('species' => 'promocode', 'name' => '', 'description' => '')));
        $sc   = new Cashback_Coupons_Icons_Shortcode($repo);

        $html = $sc->render(array('id' => '123'));

        $this->assertStringContainsString('cb_tab=coupons', $html);
        $this->assertStringContainsString('p=123', $html);
    }

    public function test_render_skips_icon_when_attachment_missing(): void
    {
        $this->setup_product_with_meta();
        // Только опция discount задана, остальные пусты.
        $GLOBALS['_cb_test_options']['cashback_coupons_icons_settings'] = array(
            'discount'      => 101,
            'gift'          => 0,
            'free_shipping' => 0,
        );
        $GLOBALS['_cb_test_attachments'][101] = 'https://example.test/uploads/coupon.svg';

        $repo = $this->make_repo_stub(array(
            array('species' => 'promocode',     'name' => '', 'description' => ''),
            array('species' => 'gift',          'name' => '', 'description' => ''),
            array('species' => 'free_shipping', 'name' => '', 'description' => ''),
        ));
        $sc   = new Cashback_Coupons_Icons_Shortcode($repo);
        $html = $sc->render(array('id' => '123'));

        $this->assertStringContainsString('cashback-coupons-icons__item--discount', $html);
        $this->assertStringNotContainsString('cashback-coupons-icons__item--gift', $html);
        $this->assertStringNotContainsString('cashback-coupons-icons__item--free_shipping', $html);
    }

    public function test_render_appends_class_attribute(): void
    {
        $this->setup_product_with_meta();
        $this->setup_all_icons();
        $repo = $this->make_repo_stub(array(array('species' => 'promocode', 'name' => '', 'description' => '')));
        $sc   = new Cashback_Coupons_Icons_Shortcode($repo);

        $html = $sc->render(array('id' => '123', 'class' => 'my-extra'));

        $this->assertStringContainsString('my-extra', $html);
        $this->assertStringContainsString('cashback-coupons-icons', $html);
    }

    public function test_render_uses_get_the_id_fallback_when_id_zero(): void
    {
        $GLOBALS['_cb_test_get_the_id'] = 555;
        $this->setup_product_with_meta(555);
        $this->setup_all_icons();
        $repo = $this->make_repo_stub(array(array('species' => 'promocode', 'name' => '', 'description' => '')));
        $sc   = new Cashback_Coupons_Icons_Shortcode($repo);

        $html = $sc->render(array());

        $this->assertStringContainsString('cashback-coupons-icons__item--discount', $html);
        $this->assertStringContainsString('p=555', $html);
    }

    public function test_render_includes_tooltip_label_for_each_icon(): void
    {
        $this->setup_product_with_meta();
        $this->setup_all_icons();
        $repo = $this->make_repo_stub(array(
            array('species' => 'promocode',     'name' => '', 'description' => ''),
            array('species' => 'gift',          'name' => '', 'description' => ''),
            array('species' => 'free_shipping', 'name' => '', 'description' => ''),
        ));
        $sc   = new Cashback_Coupons_Icons_Shortcode($repo);
        $html = $sc->render(array('id' => '123'));

        // title= НЕ выставляем (иначе браузер показывает дублирующий native tooltip).
        // Текст подсказки доступен через aria-label (a11y) + CSS-tooltip span.
        $this->assertStringContainsString('aria-label="Купон на скидку"', $html);
        $this->assertStringContainsString('aria-label="Подарок при покупке"', $html);
        $this->assertStringContainsString('aria-label="Бесплатная доставка"', $html);
        $this->assertStringContainsString('cashback-coupons-icons__tooltip">Купон на скидку</span>', $html);
        $this->assertStringNotContainsString(' title="', $html, 'native-tooltip атрибут title не должен присутствовать');
    }

    public function test_render_icons_attribute_filters_to_subset(): void
    {
        $this->setup_product_with_meta();
        $this->setup_all_icons();
        $repo = $this->make_repo_stub(array(
            array('species' => 'promocode',     'name' => '', 'description' => ''),
            array('species' => 'gift',          'name' => '', 'description' => ''),
            array('species' => 'free_shipping', 'name' => '', 'description' => ''),
        ));
        $sc   = new Cashback_Coupons_Icons_Shortcode($repo);
        $html = $sc->render(array('id' => '123', 'icons' => 'discount,gift'));

        $this->assertStringContainsString('cashback-coupons-icons__item--discount', $html);
        $this->assertStringContainsString('cashback-coupons-icons__item--gift', $html);
        $this->assertStringNotContainsString('cashback-coupons-icons__item--free_shipping', $html);
    }

    public function test_register_in_source_uses_correct_shortcode_tag(): void
    {
        $source = file_get_contents(self::$plugin_root . '/includes/promocodes/class-cashback-coupons-icons-shortcode.php');
        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            "/add_shortcode\(\s*['\"]cashback_coupons_icons['\"]/",
            (string) $source,
            'Регистрация [cashback_coupons_icons] через add_shortcode обязательна'
        );
    }
}
