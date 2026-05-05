<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Auto-suppress классического фильтра append_cashback_to_price когда в
 * Woodmart Custom Loop Layout есть шорткод [cashback_display] —
 * избегаем дубля цена + шорткод.
 */
#[Group('shortcodes')]
#[Group('cashback-display')]
final class CashbackPriceFilterSuppressTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        if (!function_exists('shortcode_atts')) {
            function shortcode_atts( array $pairs, array $atts, string $shortcode = '' ): array {
                return array_merge($pairs, is_array($atts) ? $atts : array());
            }
        }
        // Mock'и WP hooks API: тип callback намеренно не typed (callable),
        // потому что remove_action() в реальном плагине передаёт имя функции
        // строкой ('woocommerce_external_add_to_cart'), которой в test-env
        // нет → strict callable check кинул бы TypeError.
        if (!function_exists('add_action')) {
            function add_action( string $tag, $callback, int $priority = 10, int $args = 1 ): bool {
                return true;
            }
        }
        if (!function_exists('add_filter')) {
            function add_filter( string $tag, $callback, int $priority = 10, int $args = 1 ): bool {
                return true;
            }
        }
        if (!function_exists('add_shortcode')) {
            function add_shortcode( string $tag, $callback ): bool {
                return true;
            }
        }
        if (!function_exists('remove_action')) {
            function remove_action( string $tag, $callback, int $priority = 10 ): bool {
                return true;
            }
        }
        if (!function_exists('get_the_ID')) {
            function get_the_ID() {
                return $GLOBALS['_cb_test_get_the_id'] ?? 0;
            }
        }
        if (!function_exists('get_post_field')) {
            function get_post_field( string $field, int $post_id, string $context = 'display' ) {
                return $GLOBALS['_cb_test_post_field'][ $post_id ][ $field ] ?? '';
            }
        }
        if (!function_exists('has_shortcode')) {
            function has_shortcode( string $content, string $tag ): bool {
                return false !== strpos($content, '[' . $tag);
            }
        }

        require_once $plugin_root . '/wc-affiliate-url-params.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_get_the_id'] = 0;
        $GLOBALS['_cb_test_post_field'] = array();
        WC_Affiliate_URL_Params::reset_suppress_state();
    }

    public function test_suppress_set_when_layout_contains_shortcode(): void
    {
        $layout_id  = 474;
        $product_id = 100;

        $GLOBALS['_cb_test_post_field'][ $layout_id ] = array(
            'post_content' => '<!-- wp:woocommerce/product-price /--><!-- wp:shortcode -->[cashback_display]<!-- /wp:shortcode -->',
        );
        $GLOBALS['_cb_test_get_the_id']               = $product_id;

        $instance = new WC_Affiliate_URL_Params();
        $instance->maybe_suppress_filter_for_layout($layout_id);

        $this->assertTrue(
            WC_Affiliate_URL_Params::is_filter_suppressed_for_product($product_id),
            'Если в content layout есть [cashback_display], suppress-флаг должен быть выставлен'
        );
    }

    public function test_suppress_not_set_when_layout_has_no_shortcode(): void
    {
        $layout_id  = 475;
        $product_id = 101;

        $GLOBALS['_cb_test_post_field'][ $layout_id ] = array(
            'post_content' => '<!-- wp:woocommerce/product-price /--><!-- wp:woocommerce/product-title /-->',
        );
        $GLOBALS['_cb_test_get_the_id']               = $product_id;

        $instance = new WC_Affiliate_URL_Params();
        $instance->maybe_suppress_filter_for_layout($layout_id);

        $this->assertFalse(
            WC_Affiliate_URL_Params::is_filter_suppressed_for_product($product_id),
            'Без шорткода в layout — suppress-флаг НЕ должен ставиться (классический фильтр работает как раньше)'
        );
    }

    public function test_reset_clears_suppress_after_layout_render(): void
    {
        $layout_id  = 474;
        $product_id = 100;

        $GLOBALS['_cb_test_post_field'][ $layout_id ] = array(
            'post_content' => '[cashback_display]',
        );
        $GLOBALS['_cb_test_get_the_id']               = $product_id;

        $instance = new WC_Affiliate_URL_Params();
        $instance->maybe_suppress_filter_for_layout($layout_id);
        $this->assertTrue(WC_Affiliate_URL_Params::is_filter_suppressed_for_product($product_id));

        $instance->reset_suppress_filter($layout_id);
        $this->assertFalse(
            WC_Affiliate_URL_Params::is_filter_suppressed_for_product($product_id),
            'reset_suppress_filter должен очистить состояние, чтобы следующий товар loop не наследовал флаг'
        );
    }

    public function test_layout_zero_id_no_op(): void
    {
        $instance = new WC_Affiliate_URL_Params();
        $instance->maybe_suppress_filter_for_layout(0);

        $this->assertFalse(
            WC_Affiliate_URL_Params::is_filter_suppressed_for_product(123),
            'layout_post_id=0 — no-op, никаких флагов не выставляется'
        );
    }

    public function test_cache_avoids_double_parse_for_same_layout(): void
    {
        $layout_id   = 474;
        $product_id_a = 200;
        $product_id_b = 201;

        $GLOBALS['_cb_test_post_field'][ $layout_id ] = array(
            'post_content' => '[cashback_display]',
        );

        $instance = new WC_Affiliate_URL_Params();

        // Первый product
        $GLOBALS['_cb_test_get_the_id'] = $product_id_a;
        $instance->maybe_suppress_filter_for_layout($layout_id);
        $this->assertTrue(WC_Affiliate_URL_Params::is_filter_suppressed_for_product($product_id_a));

        // Сбрасываем content в global — кэш has_shortcode должен помнить
        // результат, и повторная проверка не должна перечитывать post_content.
        unset($GLOBALS['_cb_test_post_field'][ $layout_id ]);

        $GLOBALS['_cb_test_get_the_id'] = $product_id_b;
        $instance->maybe_suppress_filter_for_layout($layout_id);
        $this->assertTrue(
            WC_Affiliate_URL_Params::is_filter_suppressed_for_product($product_id_b),
            'Повторный вызов для того же layout должен использовать кэш has_shortcode (не перечитывать content)'
        );
    }
}
