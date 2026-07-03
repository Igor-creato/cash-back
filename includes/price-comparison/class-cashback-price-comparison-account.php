<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Price_Comparison_Account {

    public const ENDPOINT = 'price-comparison';

    public static function init(): void {
        $account = new self();
        add_action('init', array( $account, 'register_endpoint' ));
        add_filter('woocommerce_account_menu_items', array( $account, 'add_menu_item' ), 30);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $account, 'render_page' ));
        add_action('wp_enqueue_scripts', array( $account, 'enqueue_assets' ));
    }

    public function register_endpoint(): void {
        if (function_exists('add_rewrite_endpoint')) {
            add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
        }
    }

    public function add_menu_item( array $items ): array {
        $logout = array();
        if (isset($items['customer-logout'])) {
            $logout = array( 'customer-logout' => $items['customer-logout'] );
            unset($items['customer-logout']);
        }

        $items[ self::ENDPOINT ] = 'Сравнить цену';

        return array_merge($items, $logout);
    }

    public function render_page(): void {
        $this->enqueue_assets();
        ?>
        <section class="cashback-price-comparison" data-cashback-price-comparison>
            <h2><?php echo esc_html('Сравнить цену'); ?></h2>
            <form class="cashback-price-comparison__form" data-price-comparison-form>
                <label class="cashback-price-comparison__field">
                    <span><?php echo esc_html('Город'); ?></span>
                    <input type="text" name="city" required autocomplete="address-level2" />
                </label>
                <label class="cashback-price-comparison__field">
                    <span><?php echo esc_html('Название товара'); ?></span>
                    <input type="search" name="query" required autocomplete="off" />
                </label>
                <button type="submit" class="cashback-btn-primary"><?php echo esc_html('Поиск'); ?></button>
            </form>
            <div class="cashback-price-comparison__message" data-price-comparison-message></div>
            <div class="cashback-price-comparison__results" data-price-comparison-results></div>
        </section>
        <?php
    }

    public function enqueue_assets(): void {
        $script_url = function_exists('cashback_asset_url')
            ? cashback_asset_url('assets/js/cashback-price-comparison.js')
            : plugins_url('assets/js/cashback-price-comparison.js', dirname(__DIR__, 2) . '/cashback-plugin.php');
        $style_url = function_exists('cashback_asset_url')
            ? cashback_asset_url('assets/css/cashback-price-comparison.css')
            : plugins_url('assets/css/cashback-price-comparison.css', dirname(__DIR__, 2) . '/cashback-plugin.php');

        wp_enqueue_style('cashback-price-comparison', $style_url, array(), '1.0.0');
        wp_enqueue_script('cashback-price-comparison', $script_url, array(), '1.0.0', true);
        wp_localize_script(
            'cashback-price-comparison',
            'CashbackPriceComparison',
            array(
                'restUrl' => $this->rest_url('cashback/v1/price-comparison/search'),
                'nonce'   => wp_create_nonce('wp_rest'),
                'copy'    => array(
                    'emptyCity' => 'Укажите город для поиска',
                    'emptyQuery' => 'Укажите название товара',
                    'notFound'  => 'Товаров не нашлось',
                    'error'     => 'Ошибка поиска',
                ),
            )
        );
    }

    private function rest_url( string $path ): string {
        if (function_exists('rest_url')) {
            return rest_url($path);
        }

        return rtrim(home_url('/wp-json/' . ltrim($path, '/')), '/');
    }
}
