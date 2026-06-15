<?php

declare(strict_types=1);

// phpcs:disable PEAR.ControlStructures.MultiLineCondition.SpacingAfterOpenBrace -- Project safe standard conflicts with WPCS control spacing for simple guards.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cashback_Price_Assistant_Account {

    private const ENDPOINT = 'price-assistant';
    private const SCRIPT_HANDLE = 'cashback-price-assistant-account';

    public static function init(): void {
        $account = new self();

        add_action('init', [$account, 'register_endpoint']);
        add_action('wp_enqueue_scripts', [$account, 'enqueue_assets']);
        add_filter('woocommerce_account_menu_items', [$account, 'add_menu_item']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [$account, 'render_endpoint']);
    }

    public function register_endpoint(): void {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public function add_menu_item(array $items): array {
        $label = __('Price Assistant', 'cashback');
        if ( isset( $items['customer-logout'] ) ) {
            $logout = $items['customer-logout'];
            unset($items['customer-logout']);
            $items[ self::ENDPOINT ] = $label;
            $items['customer-logout'] = $logout;
            return $items;
        }

        $items[ self::ENDPOINT ] = $label;
        return $items;
    }

    public function enqueue_assets(): void {
        if ( ! $this->should_enqueue_assets() ) {
            return;
        }

        wp_enqueue_style(
            'cashback-price-assistant-account',
            cashback_asset_url('assets/css/price-assistant-account.css'),
            [],
            null
        );
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            cashback_asset_url('assets/js/price-assistant-account.js'),
            [],
            null,
            true
        );

        wp_localize_script(self::SCRIPT_HANDLE, 'CashbackPriceAssistantAccount', [
            'restBase'        => rtrim(home_url('/wp-json/cashback/v1/price-assistant'), '/'),
            'nonce'           => wp_create_nonce('wp_rest'),
            'consentVersion'  => 'price-assistant-session-v1',
            'scope'           => ['cart_read', 'favorites_read'],
            'connectorAction' => 'cashbackPriceAssistant.captureSession',
            'marketplaces'    => $this->marketplaces_by_code(),
            'statuses'        => $this->statuses(),
        ]);
    }

    public function render_endpoint(): void {
        $marketplaces = $this->marketplaces_by_code();
        ?>
        <section class="cashback-price-assistant" data-price-assistant-account>
            <header class="cashback-price-assistant__header">
                <h2><?php echo esc_html(__('Price Assistant', 'cashback')); ?></h2>
                <p><?php echo esc_html(__('Подключите корзину или избранное маркетплейса после входа на настоящей странице магазина и явного согласия.', 'cashback')); ?></p>
            </header>

            <div class="cashback-price-assistant__statuses" aria-label="<?php echo esc_attr(__('Статусы синхронизации', 'cashback')); ?>">
                <?php foreach ( $this->statuses() as $status => $label ) : ?>
                    <span class="cashback-price-assistant__status" data-price-assistant-status="<?php echo esc_attr($status); ?>">
                        <?php echo esc_html($label); ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <div class="cashback-price-assistant__marketplaces">
                <?php foreach ( $marketplaces as $code => $marketplace ) : ?>
                    <article class="cashback-price-assistant__marketplace" data-marketplace-card="<?php echo esc_attr($code); ?>">
                        <h3><?php echo esc_html((string) $marketplace['label']); ?></h3>
                        <p class="cashback-price-assistant__state" data-marketplace-state="<?php echo esc_attr($code); ?>">
                            <?php echo esc_html(__('disconnected', 'cashback')); ?>
                        </p>
                        <div class="cashback-price-assistant__actions">
                            <button
                                type="button"
                                class="button cashback-price-assistant__connect"
                                data-marketplace="<?php echo esc_attr($code); ?>"
                                data-marketplace-page="login"
                                <?php echo empty($marketplace['enabled']) ? 'disabled' : ''; ?>
                            >
                                <?php echo esc_html(__('Авторизоваться', 'cashback')); ?>
                            </button>
                            <button
                                type="button"
                                class="button cashback-price-assistant__open"
                                data-marketplace="<?php echo esc_attr($code); ?>"
                                data-marketplace-page="cart"
                                <?php echo empty($marketplace['enabled']) ? 'disabled' : ''; ?>
                            >
                                <?php echo esc_html(__('Корзина', 'cashback')); ?>
                            </button>
                            <button
                                type="button"
                                class="button cashback-price-assistant__open"
                                data-marketplace="<?php echo esc_attr($code); ?>"
                                data-marketplace-page="favorites"
                                <?php echo empty($marketplace['enabled']) ? 'disabled' : ''; ?>
                            >
                                <?php echo esc_html(__('Избранное', 'cashback')); ?>
                            </button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    private function should_enqueue_assets(): bool {
        if ( function_exists( 'is_account_page' ) && ! is_account_page() ) {
            return false;
        }

        if ( function_exists( 'get_query_var' ) ) {
            $value = get_query_var( self::ENDPOINT, null );
            return $value !== null && $value !== false;
        }

        return true;
    }

    private function marketplaces_by_code(): array {
        $by_code = [];
        foreach ( Cashback_Price_Assistant_REST_Controller::connector_marketplaces() as $marketplace ) {
            $by_code[ (string) $marketplace['code'] ] = $marketplace;
        }
        return $by_code;
    }

    private function statuses(): array {
        return [
            'connected'          => __('connected', 'cashback'),
            'sync ok'            => __('sync ok', 'cashback'),
            'reconnect_required' => __('reconnect required', 'cashback'),
            'disconnected'       => __('disconnected', 'cashback'),
        ];
    }
}
