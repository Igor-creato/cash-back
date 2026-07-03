<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Price_Comparison_Admin {

    public const PAGE_SLUG    = 'cashback-price-comparison';
    public const OPTION_GROUP = 'cashback_price_comparison';

    public static function init(): void {
        add_action('admin_menu', array( self::class, 'register_menu' ), 32);
        add_action('admin_init', array( self::class, 'register_settings' ));
        add_action('admin_post_cashback_price_comparison_store', array( self::class, 'handle_store_action' ));
    }

    public static function register_menu(): void {
        add_submenu_page(
            'cashback-overview',
            'Сравнение цен',
            'Сравнение цен',
            'manage_options',
            self::PAGE_SLUG,
            array( self::class, 'render_page' )
        );
    }

    public static function register_settings(): void {
        register_setting(self::OPTION_GROUP, Cashback_Price_Comparison_Client::OPTION_ENABLED, array(
            'type'              => 'integer',
            'sanitize_callback' => array( self::class, 'sanitize_enabled' ),
            'show_in_rest'      => false,
        ));
        register_setting(self::OPTION_GROUP, Cashback_Price_Comparison_Client::OPTION_BASE_URL, array(
            'type'              => 'string',
            'sanitize_callback' => array( self::class, 'sanitize_base_url' ),
            'show_in_rest'      => false,
        ));
        register_setting(self::OPTION_GROUP, Cashback_Price_Comparison_Client::OPTION_HMAC_SECRET, array(
            'type'              => 'string',
            'sanitize_callback' => array( self::class, 'sanitize_secret' ),
            'show_in_rest'      => false,
        ));
        register_setting(self::OPTION_GROUP, Cashback_Price_Comparison_Client::OPTION_TIMEOUT, array(
            'type'              => 'integer',
            'sanitize_callback' => array( self::class, 'sanitize_timeout' ),
            'show_in_rest'      => false,
        ));
    }

    public static function sanitize_enabled( mixed $value ): int {
        return empty($value) ? 0 : 1;
    }

    public static function sanitize_base_url( mixed $value ): string {
        return esc_url_raw((string) $value);
    }

    public static function sanitize_secret( mixed $value ): string {
        return sanitize_text_field((string) $value);
    }

    public static function sanitize_timeout( mixed $value ): int {
        $timeout = (int) $value;
        if ($timeout < 1) {
            return 1;
        }
        if ($timeout > 15) {
            return 15;
        }
        return $timeout;
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback'), '', array( 'response' => 403 ));
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html('Сравнение цен'); ?></h1>
            <form method="post" action="options.php">
                <?php
                if (function_exists('settings_fields')) {
                    settings_fields(self::OPTION_GROUP);
                }
                ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo esc_html('Включить поиск'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(Cashback_Price_Comparison_Client::OPTION_ENABLED); ?>" value="1" <?php checked(1, (int) get_option(Cashback_Price_Comparison_Client::OPTION_ENABLED, 0)); ?> />
                                    <?php echo esc_html('Показывать страницу "Сравнить цену" пользователям'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html('URL микросервиса'); ?></th>
                            <td>
                                <input class="regular-text" type="url" name="<?php echo esc_attr(Cashback_Price_Comparison_Client::OPTION_BASE_URL); ?>" value="<?php echo esc_attr((string) get_option(Cashback_Price_Comparison_Client::OPTION_BASE_URL, '')); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html('HMAC secret'); ?></th>
                            <td>
                                <input class="regular-text" type="password" name="<?php echo esc_attr(Cashback_Price_Comparison_Client::OPTION_HMAC_SECRET); ?>" value="" autocomplete="new-password" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html('Timeout, seconds'); ?></th>
                            <td>
                                <input type="number" min="1" max="15" name="<?php echo esc_attr(Cashback_Price_Comparison_Client::OPTION_TIMEOUT); ?>" value="<?php echo esc_attr((string) get_option(Cashback_Price_Comparison_Client::OPTION_TIMEOUT, 5)); ?>" />
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php
                if (function_exists('submit_button')) {
                    submit_button('Сохранить');
                }
                ?>
            </form>
            <?php self::render_store_section(); ?>
        </div>
        <?php
    }

    public static function handle_store_action(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback'), '', array( 'response' => 403 ));
        }
        check_admin_referer('cashback_price_comparison_store');

        $result = self::process_store_action($_POST);
        $status = $result instanceof WP_Error ? 'error' : 'updated';
        $target = add_query_arg(
            array(
                'page'                         => self::PAGE_SLUG,
                'price_comparison_store_saved' => $status,
            ),
            admin_url('admin.php')
        );
        wp_safe_redirect($target);
        exit;
    }

    public static function process_store_action( array $post ): array|WP_Error {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'Недостаточно прав.', array( 'status' => 403 ));
        }

        $client = new Cashback_Price_Comparison_Client();
        $action = sanitize_key((string) ( $post['store_action'] ?? '' ));
        $store_id = absint($post['store_id'] ?? 0);

        if ($action === 'create') {
            return $client->create_store(self::store_payload_from_post($post, true));
        }
        if ($action === 'update' && $store_id > 0) {
            return $client->update_store($store_id, self::store_payload_from_post($post, false));
        }
        if ($action === 'deactivate' && $store_id > 0) {
            return $client->deactivate_store($store_id);
        }

        return new WP_Error('invalid_store_action', 'Некорректное действие магазина.', array( 'status' => 400 ));
    }

    private static function render_store_section(): void {
        $stores = (new Cashback_Price_Comparison_Client())->list_stores();
        ?>
        <hr />
        <h2><?php echo esc_html('Магазины поиска'); ?></h2>
        <?php if ($stores instanceof WP_Error) : ?>
            <div class="notice notice-warning inline">
                <p><?php echo esc_html($stores->get_error_message()); ?></p>
            </div>
        <?php endif; ?>
        <h3><?php echo esc_html('Добавить магазин'); ?></h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="cashback_price_comparison_store" />
            <input type="hidden" name="store_action" value="create" />
            <?php wp_nonce_field('cashback_price_comparison_store'); ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="price-compare-domain"><?php echo esc_html('Домен магазина'); ?></label></th>
                        <td><input id="price-compare-domain" class="regular-text" name="domain" type="text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="price-compare-display-name"><?php echo esc_html('Название магазина'); ?></label></th>
                        <td><input id="price-compare-display-name" class="regular-text" name="display_name" type="text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="price-compare-logo-url"><?php echo esc_html('Логотип'); ?></label></th>
                        <td><input id="price-compare-logo-url" class="regular-text" name="logo_url" type="url" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="price-compare-aliases"><?php echo esc_html('Aliases доменов'); ?></label></th>
                        <td><input id="price-compare-aliases" class="regular-text" name="aliases" type="text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="price-compare-source-type"><?php echo esc_html('CPA network source'); ?></label></th>
                        <td><?php self::render_source_select('source_type', 'custom', 'price-compare-source-type'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="price-compare-feed-id"><?php echo esc_html('Feed identifier'); ?></label></th>
                        <td><input id="price-compare-feed-id" class="regular-text" name="feed_identifier" type="text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html('Региональность'); ?></th>
                        <td><label><input type="checkbox" name="supports_region" value="1" /> <?php echo esc_html('Источник поддерживает город/регион'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="price-compare-fallback"><?php echo esc_html('Fallback behavior'); ?></label></th>
                        <td><?php self::render_fallback_select('fallback_behavior', 'status_only', 'price-compare-fallback'); ?></td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button('Сохранить магазин'); ?>
        </form>
        <?php
        if (is_array($stores)) {
            self::render_store_table($stores['items'] ?? array());
        }
    }

    private static function render_store_table( array $stores ): void {
        ?>
        <h3><?php echo esc_html('Список магазинов'); ?></h3>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php echo esc_html('Магазин'); ?></th>
                    <th><?php echo esc_html('Домен'); ?></th>
                    <th><?php echo esc_html('Источник'); ?></th>
                    <th><?php echo esc_html('Товаров'); ?></th>
                    <th><?php echo esc_html('Последний импорт'); ?></th>
                    <th><?php echo esc_html('Действия'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stores as $store) : ?>
                    <?php
                    if (!is_array($store)) {
                        continue;
                    }
                    ?>
                    <?php self::render_store_row($store); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function render_store_row( array $store ): void {
        $store_id = absint($store['id'] ?? 0);
        $active = !empty($store['active']);
        $aliases = array_map('strval', (array) ( $store['aliases'] ?? array() ));
        $source_config = is_array($store['source_config'] ?? null) ? $store['source_config'] : array();
        $import_status = is_array($store['import_status'] ?? null) ? $store['import_status'] : array();
        ?>
        <tr>
            <td>
                <strong><?php echo esc_html((string) ( $store['display_name'] ?? '' )); ?></strong>
                <?php if (!empty($store['logo_url'])) : ?>
                    <br />
                    <img src="<?php echo esc_url((string) $store['logo_url']); ?>" alt="" style="max-width:48px;max-height:32px;" />
                <?php endif; ?>
            </td>
            <td>
                <code><?php echo esc_html((string) ( $store['domain'] ?? '' )); ?></code>
                <?php if ($aliases !== array()) : ?>
                    <br />
                    <?php echo esc_html(implode(', ', $aliases)); ?>
                <?php endif; ?>
            </td>
            <td>
                <?php echo esc_html((string) ( $store['source_type'] ?? 'custom' )); ?>
                <br />
                <?php echo esc_html('Priority: ' . (string) ( $store['priority'] ?? 100 )); ?>
                <br />
                <?php echo !empty($store['supports_region']) ? esc_html('Город поддерживается') : esc_html('Город не поддерживается'); ?>
            </td>
            <td><?php echo esc_html((string) ( $store['offer_count'] ?? 0 )); ?></td>
            <td>
                <?php echo esc_html((string) ( $import_status['status'] ?? 'idle' )); ?>
                <br />
                <?php echo esc_html('Импортировано: ' . (string) ( $import_status['imported_count'] ?? 0 )); ?>
            </td>
            <td>
                <details>
                    <summary><?php echo esc_html('Редактировать'); ?></summary>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="cashback_price_comparison_store" />
                        <input type="hidden" name="store_action" value="update" />
                        <input type="hidden" name="store_id" value="<?php echo esc_attr((string) $store_id); ?>" />
                        <?php wp_nonce_field('cashback_price_comparison_store'); ?>
                        <p><input class="regular-text" name="display_name" type="text" value="<?php echo esc_attr((string) ( $store['display_name'] ?? '' )); ?>" /></p>
                        <p><input class="regular-text" name="logo_url" type="url" value="<?php echo esc_attr((string) ( $store['logo_url'] ?? '' )); ?>" placeholder="<?php echo esc_attr('Логотип'); ?>" /></p>
                        <p><input class="regular-text" name="aliases" type="text" value="<?php echo esc_attr(implode(', ', $aliases)); ?>" placeholder="<?php echo esc_attr('Aliases'); ?>" /></p>
                        <p><?php self::render_source_select('source_type', (string) ( $store['source_type'] ?? 'custom' )); ?></p>
                        <p><input class="regular-text" name="feed_identifier" type="text" value="<?php echo esc_attr((string) ( $source_config['feed_identifier'] ?? '' )); ?>" placeholder="<?php echo esc_attr('Feed identifier'); ?>" /></p>
                        <p><input class="small-text" name="priority" type="number" value="<?php echo esc_attr((string) ( $store['priority'] ?? 100 )); ?>" /></p>
                        <p><label><input type="checkbox" name="supports_region" value="1" <?php checked(true, !empty($store['supports_region'])); ?> /> <?php echo esc_html('Город'); ?></label></p>
                        <p><label><input type="checkbox" name="active" value="1" <?php checked(true, $active); ?> /> <?php echo esc_html('Активен'); ?></label></p>
                        <p><?php self::render_fallback_select('fallback_behavior', (string) ( $store['fallback_behavior'] ?? 'status_only' )); ?></p>
                        <button type="submit" class="button button-primary"><?php echo esc_html('Сохранить'); ?></button>
                    </form>
                </details>
                <?php if ($active) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="cashback_price_comparison_store" />
                        <input type="hidden" name="store_action" value="deactivate" />
                        <input type="hidden" name="store_id" value="<?php echo esc_attr((string) $store_id); ?>" />
                        <?php wp_nonce_field('cashback_price_comparison_store'); ?>
                        <button type="submit" class="button"><?php echo esc_html('Деактивировать'); ?></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private static function render_source_select( string $name, string $current, string $id = '' ): void {
        $sources = array( 'admitad', 'advcake', 'custom', 'disabled' );
        ?>
        <select name="<?php echo esc_attr($name); ?>"<?php echo $id !== '' ? ' id="' . esc_attr($id) . '"' : ''; ?>>
            <?php foreach ($sources as $source) : ?>
                <option value="<?php echo esc_attr($source); ?>" <?php selected($source, $current); ?>><?php echo esc_html($source); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    private static function render_fallback_select( string $name, string $current, string $id = '' ): void {
        $options = array( 'status_only', 'skip', 'custom_api' );
        ?>
        <select name="<?php echo esc_attr($name); ?>"<?php echo $id !== '' ? ' id="' . esc_attr($id) . '"' : ''; ?>>
            <?php foreach ($options as $option) : ?>
                <option value="<?php echo esc_attr($option); ?>" <?php selected($option, $current); ?>><?php echo esc_html($option); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    private static function store_payload_from_post( array $post, bool $include_domain ): array {
        $payload = array(
            'display_name'      => sanitize_text_field((string) ( $post['display_name'] ?? '' )),
            'aliases'           => self::csv_to_list((string) ( $post['aliases'] ?? '' )),
            'logo_url'          => esc_url_raw((string) ( $post['logo_url'] ?? '' )),
            'source_type'       => sanitize_key((string) ( $post['source_type'] ?? 'custom' )),
            'source_config'     => array(
                'feed_identifier' => sanitize_text_field((string) ( $post['feed_identifier'] ?? '' )),
            ),
            'priority'          => absint($post['priority'] ?? 100),
            'supports_region'   => !empty($post['supports_region']),
            'fallback_behavior' => sanitize_key((string) ( $post['fallback_behavior'] ?? 'status_only' )),
            'active'            => !empty($post['active']) || $include_domain,
        );
        if ($include_domain) {
            $payload['domain'] = sanitize_text_field((string) ( $post['domain'] ?? '' ));
        }
        return $payload;
    }

    private static function csv_to_list( string $value ): array {
        return array_values(array_filter(array_map(
            static fn( string $item ): string => sanitize_text_field(trim($item)),
            explode(',', $value)
        )));
    }
}
