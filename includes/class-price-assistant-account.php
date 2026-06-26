<?php

declare(strict_types=1);

// phpcs:disable PEAR.ControlStructures.MultiLineCondition.SpacingAfterOpenBrace -- Project safe standard conflicts with WPCS control spacing for simple guards.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cashback_Price_Assistant_Account {

    private const ENDPOINT = 'price-assistant';
    private const REWRITE_VERSION = 'price-assistant-account-v1';
    private const REWRITE_VERSION_OPTION = 'cashback_price_assistant_rewrite_version';
    private const SCRIPT_HANDLE = 'cashback-price-assistant-account';
    private const PRODUCT_LINK_FORM_SCRIPT_HANDLE = 'cashback-product-link-form';

    public static function init(): void {
        $account = new self();

        add_action('init', array( $account, 'register_endpoint' ));
        add_action('init', array( $account, 'maybe_schedule_rewrite_flush' ), 20);
        add_action('wp_enqueue_scripts', array( $account, 'enqueue_assets' ));
        add_filter('woocommerce_account_menu_items', array( $account, 'add_menu_item' ));
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $account, 'render_endpoint' ));
    }

    public function register_endpoint(): void {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public function maybe_schedule_rewrite_flush(): void {
        if ( get_option( self::REWRITE_VERSION_OPTION, '' ) === self::REWRITE_VERSION ) {
            return;
        }

        set_transient('cashback_flush_rewrite_rules', 1, HOUR_IN_SECONDS);
        update_option(self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION);
    }

    public function add_menu_item( array $items ): array {
        $label = __('Мониторинг цен', 'cashback');
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
            array( 'cashback-account-base' ),
            // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version embedded via cashback_asset_url() ?cv=<filemtime>
            null
        );
        wp_enqueue_script(
            'cashback-pagination',
            cashback_asset_url('assets/js/cashback-pagination.js'),
            array(),
            // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version embedded via cashback_asset_url() ?cv=<filemtime>
            null,
            true
        );
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            cashback_asset_url('assets/js/price-assistant-account.js'),
            array( 'cashback-pagination' ),
            // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version embedded via cashback_asset_url() ?cv=<filemtime>
            null,
            true
        );

        $marketplaces = $this->marketplaces_by_code();
        wp_localize_script(self::SCRIPT_HANDLE, 'CashbackPriceAssistantAccount', array(
            'restBase'        => rtrim(home_url('/wp-json/cashback/v1/price-assistant'), '/'),
            'nonce'           => wp_create_nonce('wp_rest'),
            'consentVersion'  => 'price-assistant-session-v1',
            'scope'           => array( 'cart_read', 'favorites_read' ),
            'connectorAction' => 'cashbackPriceAssistant.captureSession',
            'marketplaces'    => $marketplaces,
            'initialMarketplace' => $this->first_active_marketplace($marketplaces),
            'statuses'        => $this->statuses(),
        ));

        $this->enqueue_product_link_form_assets();
    }

    public function render_endpoint(): void {
        $marketplaces = $this->marketplaces_by_code();
        $active_marketplace = $this->first_active_marketplace($marketplaces);
        ?>
        <section class="cashback-price-assistant" data-price-assistant-account>
            <header class="cashback-price-assistant__header">
                <div>
                    <h2><?php echo esc_html(__('Мониторинг цен', 'cashback')); ?></h2>
                    <p><?php echo esc_html(__('Отслеживайте товар по ссылке, подключайте корзины маркетплейсов и сравнивайте цены в отдельных вкладках.', 'cashback')); ?></p>
                </div>
            </header>

            <nav class="cashback-price-assistant__view-tabs cashback-support-tabs" data-price-assistant-view-tabs aria-label="<?php echo esc_attr__('Разделы мониторинга цен', 'cashback'); ?>">
                <button type="button" class="cashback-support-tab active is-active" data-price-assistant-view="link">
                    <?php echo esc_html__('Мониторинг по ссылке', 'cashback'); ?>
                </button>
                <button type="button" class="cashback-support-tab" data-price-assistant-view="cart">
                    <?php echo esc_html__('Мониторинг по корзине', 'cashback'); ?>
                </button>
                <button type="button" class="cashback-support-tab" data-price-assistant-view="compare">
                    <?php echo esc_html__('Сравнение цен', 'cashback'); ?>
                </button>
            </nav>

            <div class="cashback-price-assistant__notice" data-price-assistant-message aria-live="polite"></div>

            <section class="cashback-price-assistant__panel" data-price-assistant-panel="link">
                <form class="cashback-price-assistant__form cashback-price-assistant__form--link" data-price-assistant-add-form>
                    <label>
                        <span><?php echo esc_html(__('Ссылка на товар', 'cashback')); ?></span>
                        <input type="url" name="product_url" required placeholder="https://..." autocomplete="off">
                    </label>
                    <label>
                        <span><?php echo esc_html(__('Целевая цена', 'cashback')); ?></span>
                        <input type="number" name="target_price" min="0" step="0.01" data-price-assistant-target-price>
                    </label>
                    <button type="submit" class="button cashback-btn-primary"><?php echo esc_html(__('Добавить', 'cashback')); ?></button>
                </form>

                <?php $this->render_product_link_check_form(); ?>

                <div class="cashback-price-assistant__workspace">
                    <section class="cashback-price-assistant__panel" data-price-assistant-manual-panel>
                    <h3><?php echo esc_html(__('Ручные товары', 'cashback')); ?></h3>
                    <div class="cashback-price-assistant__list" data-price-assistant-manual-list></div>
                    </section>
                </div>
            </section>

            <section class="cashback-price-assistant__panel" data-price-assistant-panel="cart" hidden>
                <nav class="cashback-price-assistant__tabs cashback-support-tabs" data-price-assistant-marketplace-tabs aria-label="<?php echo esc_attr__('Маркетплейсы', 'cashback'); ?>">
                    <?php foreach ( $marketplaces as $code => $marketplace ) : ?>
                        <?php $is_active = $code === $active_marketplace; ?>
                        <button type="button" class="cashback-support-tab<?php echo $is_active ? ' active is-active' : ''; ?>" data-price-assistant-tab="<?php echo esc_attr($code); ?>">
                            <?php echo esc_html($this->marketplace_label($code, (string) $marketplace['label'])); ?>
                        </button>
                    <?php endforeach; ?>
                </nav>

                <div class="cashback-price-assistant__marketplaces">
                    <?php foreach ( $marketplaces as $code => $marketplace ) : ?>
                        <article
                            class="cashback-price-assistant__marketplace"
                            data-marketplace-card="<?php echo esc_attr($code); ?>"
                            data-price-assistant-source="<?php echo esc_attr($code); ?>"
                            data-marketplace-access-status="<?php echo esc_attr((string) ( $marketplace['access_status'] ?? 'available' )); ?>"
                            <?php echo $code === $active_marketplace ? '' : 'hidden'; ?>
                        >
                            <h3><?php echo esc_html($this->marketplace_label($code, (string) $marketplace['label'])); ?></h3>
                            <p class="cashback-price-assistant__state" data-marketplace-state="<?php echo esc_attr($code); ?>">
                                <?php echo esc_html($this->initial_marketplace_state($marketplace)); ?>
                            </p>
                            <div class="cashback-price-assistant__actions">
                                <button
                                    type="button"
                                    class="button cashback-btn-primary cashback-price-assistant__connect"
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
                                    hidden
                                    <?php echo empty($marketplace['enabled']) ? 'disabled' : ''; ?>
                                >
                                    <?php echo esc_html(__('Корзина', 'cashback')); ?>
                                </button>
                                <button
                                    type="button"
                                    class="button cashback-price-assistant__open"
                                    data-marketplace="<?php echo esc_attr($code); ?>"
                                    data-marketplace-page="favorites"
                                    hidden
                                    <?php echo empty($marketplace['enabled']) ? 'disabled' : ''; ?>
                                >
                                    <?php echo esc_html(__('Избранное', 'cashback')); ?>
                                </button>
                                <button
                                    type="button"
                                    class="button cashback-price-assistant__disconnect"
                                    data-price-assistant-disconnect
                                    data-marketplace="<?php echo esc_attr($code); ?>"
                                    hidden
                                    disabled
                                >
                                    <?php echo esc_html(__('Отключить', 'cashback')); ?>
                                </button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="cashback-price-assistant__workspace">

                <section class="cashback-price-assistant__panel" data-price-assistant-collection-panel="cart">
                    <h3><?php echo esc_html(__('Корзина', 'cashback')); ?></h3>
                    <div class="cashback-price-assistant__list" data-price-assistant-collection-list="cart" data-price-assistant-delete-import="cart"></div>
                    <div class="cashback-price-assistant__pagination" data-price-assistant-pagination="cart"></div>
                </section>

                <section class="cashback-price-assistant__panel" data-price-assistant-collection-panel="favorites" hidden>
                    <h3><?php echo esc_html(__('Избранное', 'cashback')); ?></h3>
                    <div class="cashback-price-assistant__list" data-price-assistant-collection-list="favorites" data-price-assistant-delete-import="favorites"></div>
                    <div class="cashback-price-assistant__pagination" data-price-assistant-pagination="favorites"></div>
                </section>
                </div>
            </section>

            <section class="cashback-price-assistant__panel" data-price-assistant-panel="compare" hidden>
                <form class="cashback-price-assistant__search" data-price-assistant-search-form>
                    <label class="screen-reader-text" for="price-assistant-search-query">
                        <?php echo esc_html__('Поиск товаров по магазинам', 'cashback'); ?>
                    </label>
                    <span class="cashback-price-assistant__search-icon" aria-hidden="true"><?php echo esc_html__('Поиск', 'cashback'); ?></span>
                    <input id="price-assistant-search-query" type="search" name="q" placeholder="<?php echo esc_attr__('смартфон iPhone 15', 'cashback'); ?>" autocomplete="off" />
                    <button type="submit" class="button cashback-btn-primary"><?php echo esc_html__('Найти', 'cashback'); ?></button>
                </form>

                <section class="cashback-price-assistant__search-results" data-price-assistant-search-results aria-live="polite"></section>

                <section class="cashback-price-assistant__panel cashback-price-assistant__panel--wide">
                    <h3><?php echo esc_html(__('График выбранного товара', 'cashback')); ?></h3>
                    <div class="cashback-price-assistant__chart" data-price-assistant-chart></div>
                </section>

                <section class="cashback-price-assistant__panel cashback-price-assistant__panel--wide">
                    <h3><?php echo esc_html(__('Где дешевле', 'cashback')); ?></h3>
                    <div class="cashback-price-assistant__compare" data-price-assistant-compare></div>
                </section>
            </section>
        </section>
        <?php
    }

    private function render_product_link_check_form(): void {
        ?>
        <section class="cashback-price-assistant__cashback-check">
            <h3><?php echo esc_html__('Проверить кэшбэк по ссылке', 'cashback'); ?></h3>
            <form class="cashback-product-link-form" data-cashback-product-link-form>
                <label class="cashback-product-link-form__label">
                    <span><?php echo esc_html__('Ссылка на товар', 'cashback'); ?></span>
                    <input
                        class="cashback-product-link-form__input"
                        type="url"
                        name="direct_url"
                        inputmode="url"
                        required
                        placeholder="https://"
                    />
                </label>
                <button class="cashback-product-link-form__submit button cashback-btn-primary" type="submit">
                    <?php echo esc_html__('Проверить кэшбэк', 'cashback'); ?>
                </button>
                <p class="cashback-product-link-form__warning" data-cashback-product-link-warning hidden>
                    <?php echo esc_html__('Кэшбэк не начисляется по этому товару', 'cashback'); ?>
                </p>
                <div class="cashback-product-link-form__result" data-cashback-product-link-result aria-live="polite"></div>
            </form>
        </section>
        <?php
    }

    private function enqueue_product_link_form_assets(): void {
        wp_enqueue_script(
            self::PRODUCT_LINK_FORM_SCRIPT_HANDLE,
            cashback_asset_url('assets/js/cashback-product-link-form.js'),
            array(),
            // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version embedded via cashback_asset_url() ?cv=<filemtime>.
            null,
            true
        );
        wp_localize_script(self::PRODUCT_LINK_FORM_SCRIPT_HANDLE, 'CashbackProductLinkForm', array(
            'endpoint' => home_url('/wp-json/cashback/v1/product-link/resolve'),
            'nonce'    => wp_create_nonce('wp_rest'),
        ));
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
        $by_code = array();
        foreach ( Cashback_Price_Assistant_REST_Controller::connector_marketplaces() as $marketplace ) {
            $by_code[ (string) $marketplace['code'] ] = $marketplace;
        }
        return $by_code;
    }

    private function first_active_marketplace( array $marketplaces ): string {
        foreach ( $marketplaces as $code => $marketplace ) {
            if ( ! empty($marketplace['enabled']) ) {
                return (string) $code;
            }
        }

        if ( isset($marketplaces['ozon']) ) {
            return 'ozon';
        }

        return (string) array_key_first($marketplaces);
    }

    private function marketplace_label( string $code, string $fallback ): string {
        if ( 'yandex_market' === $code ) {
            return __('Яндекс Маркет', 'cashback');
        }

        return $fallback;
    }

    private function statuses(): array {
        return array(
            'connected'          => __('Авторизован', 'cashback'),
            'sync ok'            => __('Синхронизировано', 'cashback'),
            'sync_ok'            => __('Синхронизировано', 'cashback'),
            'connecting'         => __('Подключение', 'cashback'),
            'requires_official_access' => __('Требуется официальный доступ Ozon', 'cashback'),
            'reconnect_required' => __('Требуется повторная авторизация', 'cashback'),
            'disconnected'       => __('Не авторизован', 'cashback'),
        );
    }

    private function initial_marketplace_state( array $marketplace ): string {
        if ( 'requires_official_access' === (string) ( $marketplace['access_status'] ?? '' ) ) {
            return __('Требуется официальный доступ Ozon', 'cashback');
        }

        return __('Не авторизован', 'cashback');
    }
}
