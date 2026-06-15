<?php
/**
 * Cashback → Настройки — admin страница с глобальными опциями плагина (v12).
 *
 * Регистрирует submenu и register_setting с sanitize-callback для:
 *   - cashback_guest_display_rate (float 0..100, default 60.0) — гостевая ставка
 *     для рендера в карточке товара.
 *   - cashback_display_cache_ttl (int 60..86400, default 43200) — TTL кеша.
 *   - cashback_shop_import_batch_size (int 10..500, default 100).
 *   - cashback_shop_import_throttle_ms (int 0..5000, default 200).
 *
 * После save bumps cashback_display_rate_version → лениво invalidates все
 * кеши Display_Calculator.
 *
 * @package CashbackPlugin
 * @since   12.0.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cashback_Settings_Admin {

    public const PAGE_SLUG = 'cashback-settings';
    public const OPTION_GROUP = 'cashback_settings_group';

    public static function init(): void {
        add_action('admin_menu', array( self::class, 'register_menu' ), 30);
        add_action('admin_init', array( self::class, 'register_settings' ));
        add_action('update_option_cashback_guest_display_rate', array( self::class, 'on_guest_rate_changed' ), 10, 2);
    }

    public static function register_menu(): void {
        add_submenu_page(
            'cashback-overview',
            'Настройки',
            'Настройки',
            'manage_options',
            self::PAGE_SLUG,
            array( self::class, 'render_page' )
        );
    }

    public static function register_settings(): void {
        register_setting(
            self::OPTION_GROUP,
            Cashback_Shop_Options::OPT_GUEST_DISPLAY_RATE,
            array(
                'type'              => 'number',
                'default'           => Cashback_Shop_Options::DEFAULT_GUEST_RATE,
                'sanitize_callback' => array( self::class, 'sanitize_guest_rate' ),
            )
        );
        register_setting(
            self::OPTION_GROUP,
            Cashback_Shop_Options::OPT_DISPLAY_CACHE_TTL,
            array(
                'type'              => 'integer',
                'default'           => Cashback_Shop_Options::DEFAULT_CACHE_TTL,
                'sanitize_callback' => array( self::class, 'sanitize_int_range_60_86400' ),
            )
        );
        register_setting(
            self::OPTION_GROUP,
            Cashback_Shop_Options::OPT_IMPORT_BATCH_SIZE,
            array(
                'type'              => 'integer',
                'default'           => Cashback_Shop_Options::DEFAULT_BATCH_SIZE,
                'sanitize_callback' => array( self::class, 'sanitize_int_range_10_500' ),
            )
        );
        register_setting(
            self::OPTION_GROUP,
            Cashback_Shop_Options::OPT_IMPORT_THROTTLE_MS,
            array(
                'type'              => 'integer',
                'default'           => Cashback_Shop_Options::DEFAULT_THROTTLE_MS,
                'sanitize_callback' => array( self::class, 'sanitize_int_range_0_5000' ),
            )
        );

        // Регистрация: тоггл «Разрешить регистрацию новых пользователей». Источник
        // правды — стандартная WP-опция users_can_register (двусторонняя
        // синхронизация с Settings → General → Членство).
        register_setting(
            self::OPTION_GROUP,
            'users_can_register',
            array(
                'type'              => 'integer',
                'default'           => 0,
                'sanitize_callback' => array( self::class, 'sanitize_bool_to_int' ),
            )
        );

        register_setting(
            self::OPTION_GROUP,
            Cashback_Price_Assistant_Proxy_Client::OPTION_ENABLED,
            array(
                'type'              => 'integer',
                'default'           => 0,
                'sanitize_callback' => array( 'Cashback_Price_Assistant_Proxy_Client', 'sanitize_enabled' ),
            )
        );
        register_setting(
            self::OPTION_GROUP,
            Cashback_Price_Assistant_Proxy_Client::OPTION_BASE_URL,
            array(
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => array( 'Cashback_Price_Assistant_Proxy_Client', 'sanitize_base_url' ),
            )
        );
        register_setting(
            self::OPTION_GROUP,
            Cashback_Price_Assistant_Proxy_Client::OPTION_SITE_ID,
            array(
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => array( 'Cashback_Price_Assistant_Proxy_Client', 'sanitize_site_id' ),
            )
        );
        register_setting(
            self::OPTION_GROUP,
            Cashback_Price_Assistant_Proxy_Client::OPTION_HMAC_SECRET,
            array(
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => array( 'Cashback_Price_Assistant_Proxy_Client', 'sanitize_hmac_secret' ),
                'show_in_rest'      => false,
            )
        );
        foreach (Cashback_Price_Assistant_REST_Controller::marketplaces() as $marketplace => $label) {
            unset($label);
            register_setting(
                self::OPTION_GROUP,
                Cashback_Price_Assistant_REST_Controller::marketplace_option_name($marketplace),
                array(
                    'type'              => 'integer',
                    'default'           => 0,
                    'sanitize_callback' => array( self::class, 'sanitize_bool_to_int' ),
                )
            );
        }

        add_settings_section('cashback_settings_display', 'Отображение кэшбэка', '__return_false', self::PAGE_SLUG);
        add_settings_field(
            Cashback_Shop_Options::OPT_GUEST_DISPLAY_RATE,
            'Ставка для гостей (%)',
            array( self::class, 'render_field_guest_rate' ),
            self::PAGE_SLUG,
            'cashback_settings_display'
        );
        add_settings_field(
            Cashback_Shop_Options::OPT_DISPLAY_CACHE_TTL,
            'TTL кеша рендера (сек)',
            array( self::class, 'render_field_cache_ttl' ),
            self::PAGE_SLUG,
            'cashback_settings_display'
        );

        add_settings_section('cashback_settings_import', 'Импорт магазинов', '__return_false', self::PAGE_SLUG);
        add_settings_field(
            Cashback_Shop_Options::OPT_IMPORT_BATCH_SIZE,
            'Размер batch (кампаний на страницу)',
            array( self::class, 'render_field_batch_size' ),
            self::PAGE_SLUG,
            'cashback_settings_import'
        );
        add_settings_field(
            Cashback_Shop_Options::OPT_IMPORT_THROTTLE_MS,
            'Throttle между запросами (мс)',
            array( self::class, 'render_field_throttle' ),
            self::PAGE_SLUG,
            'cashback_settings_import'
        );

        add_settings_section('cashback_settings_registration', 'Регистрация', '__return_false', self::PAGE_SLUG);
        add_settings_field(
            'users_can_register',
            'Разрешить регистрацию новых пользователей',
            array( self::class, 'render_field_registration' ),
            self::PAGE_SLUG,
            'cashback_settings_registration'
        );

        add_settings_section('cashback_settings_price_monitor', 'Price Assistant proxy', '__return_false', self::PAGE_SLUG);
        add_settings_field(
            Cashback_Price_Assistant_Proxy_Client::OPTION_ENABLED,
            'Включить proxy',
            array( self::class, 'render_field_price_monitor_enabled' ),
            self::PAGE_SLUG,
            'cashback_settings_price_monitor'
        );
        add_settings_field(
            Cashback_Price_Assistant_Proxy_Client::OPTION_BASE_URL,
            'FastAPI base URL',
            array( self::class, 'render_field_price_monitor_base_url' ),
            self::PAGE_SLUG,
            'cashback_settings_price_monitor'
        );
        add_settings_field(
            Cashback_Price_Assistant_Proxy_Client::OPTION_SITE_ID,
            'Site ID',
            array( self::class, 'render_field_price_monitor_site_id' ),
            self::PAGE_SLUG,
            'cashback_settings_price_monitor'
        );
        add_settings_field(
            Cashback_Price_Assistant_Proxy_Client::OPTION_HMAC_SECRET,
            'HMAC secret',
            array( self::class, 'render_field_price_monitor_hmac_secret' ),
            self::PAGE_SLUG,
            'cashback_settings_price_monitor'
        );
        add_settings_field(
            'price_monitor_marketplaces',
            'Marketplace flags',
            array( self::class, 'render_field_price_monitor_marketplaces' ),
            self::PAGE_SLUG,
            'cashback_settings_price_monitor'
        );
    }

    public static function sanitize_guest_rate( $value ): float {
        $f = is_numeric($value) ? (float) $value : Cashback_Shop_Options::DEFAULT_GUEST_RATE;
        return max(0.0, min(100.0, $f));
    }

    public static function sanitize_int_range_60_86400( $value ): int {
        $i = is_numeric($value) ? (int) $value : Cashback_Shop_Options::DEFAULT_CACHE_TTL;
        return max(60, min(86400, $i));
    }

    public static function sanitize_int_range_10_500( $value ): int {
        $i = is_numeric($value) ? (int) $value : Cashback_Shop_Options::DEFAULT_BATCH_SIZE;
        return max(10, min(500, $i));
    }

    public static function sanitize_int_range_0_5000( $value ): int {
        $i = is_numeric($value) ? (int) $value : Cashback_Shop_Options::DEFAULT_THROTTLE_MS;
        return max(0, min(5000, $i));
    }

    public static function sanitize_bool_to_int( $value ): int {
        return (int) (bool) $value;
    }

    /**
     * После смены guest rate — bump rate_version (lazy invalidation
     * всех cached display). update_option_* hook signature принимает 2 аргумента
     * (старое + новое), нам они не нужны — bump_display_rate_version() работает
     * без параметров.
     *
     * @param mixed $old_value
     * @param mixed $new_value
     */
    public static function on_guest_rate_changed( $old_value, $new_value ): void {
        unset($old_value, $new_value); // signature-only, не используются.
        if (class_exists('Cashback_Shop_Options')) {
            Cashback_Shop_Options::bump_display_rate_version();
        }
    }

    public static function render_page(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback'));
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Настройки кэшбэка', 'cashback'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public static function render_field_guest_rate(): void {
        $value = Cashback_Shop_Options::get_guest_display_rate();
        echo '<input type="number" step="0.1" min="0" max="100" name="'
            . esc_attr(Cashback_Shop_Options::OPT_GUEST_DISPLAY_RATE)
            . '" value="' . esc_attr((string) $value)
            . '" class="small-text" /> %';
        echo '<p class="description">'
            . esc_html__('Применяется к неавторизованным пользователям при рендере динамического кэшбэка в карточке товара. Default: 60.0%.', 'cashback')
            . '</p>';
    }

    public static function render_field_cache_ttl(): void {
        $value = Cashback_Shop_Options::get_display_cache_ttl();
        echo '<input type="number" min="60" max="86400" step="60" name="'
            . esc_attr(Cashback_Shop_Options::OPT_DISPLAY_CACHE_TTL)
            . '" value="' . esc_attr((string) $value) . '" class="small-text" /> сек';
        echo '<p class="description">'
            . esc_html__('Кеш рендера динамического display. Clamp 60..86400 (12h default).', 'cashback')
            . '</p>';
    }

    public static function render_field_batch_size(): void {
        $value = Cashback_Shop_Options::get_import_batch_size();
        echo '<input type="number" min="10" max="500" step="10" name="'
            . esc_attr(Cashback_Shop_Options::OPT_IMPORT_BATCH_SIZE)
            . '" value="' . esc_attr((string) $value) . '" class="small-text" />';
        echo '<p class="description">'
            . esc_html__('Кампаний за один HTTP-запрос. Admitad/EPN limit = 500. Default: 100.', 'cashback')
            . '</p>';
    }

    public static function render_field_throttle(): void {
        $value = Cashback_Shop_Options::get_import_throttle_ms();
        echo '<input type="number" min="0" max="5000" step="50" name="'
            . esc_attr(Cashback_Shop_Options::OPT_IMPORT_THROTTLE_MS)
            . '" value="' . esc_attr((string) $value) . '" class="small-text" /> мс';
        echo '<p class="description">'
            . esc_html__('Задержка между HTTP-запросами при многостраничном импорте. Default: 200.', 'cashback')
            . '</p>';
    }

    public static function render_field_registration(): void {
        $value = (int) get_option('users_can_register', 0);
        echo '<label>';
        echo '<input type="hidden" name="users_can_register" value="0" />';
        echo '<input type="checkbox" name="users_can_register" value="1" ' . checked(1, $value, false) . ' /> ';
        echo esc_html__('Включено — гости могут регистрироваться через /register/, /my-account/ и социальную авторизацию.', 'cashback');
        echo '</label>';
        echo '<p class="description">'
            . esc_html__('Эта же опция управляется стандартным WordPress-чекбоксом Settings → Общие → Членство → «Любой может зарегистрироваться». При выключении: /register/ показывает уведомление, social-логин для новых аккаунтов возвращает ошибку, уже зарегистрированные через соцсети входят как обычно.', 'cashback')
            . '</p>';
    }

    public static function render_field_price_monitor_enabled(): void {
        $value = (int) get_option(Cashback_Price_Assistant_Proxy_Client::OPTION_ENABLED, 0);
        echo '<label>';
        echo '<input type="hidden" name="' . esc_attr(Cashback_Price_Assistant_Proxy_Client::OPTION_ENABLED) . '" value="0" />';
        echo '<input type="checkbox" name="' . esc_attr(Cashback_Price_Assistant_Proxy_Client::OPTION_ENABLED) . '" value="1" ' . checked(1, $value, false) . ' /> ';
        echo esc_html__('Включено — браузер обращается только к WordPress REST proxy, FastAPI URL и secret не отдаются клиенту.', 'cashback');
        echo '</label>';
    }

    public static function render_field_price_monitor_base_url(): void {
        $value = (string) get_option(Cashback_Price_Assistant_Proxy_Client::OPTION_BASE_URL, '');
        echo '<input type="url" class="regular-text" name="'
            . esc_attr(Cashback_Price_Assistant_Proxy_Client::OPTION_BASE_URL)
            . '" value="' . esc_attr($value) . '" placeholder="https://price-monitor.example" />';
    }

    public static function render_field_price_monitor_site_id(): void {
        $value = (string) get_option(Cashback_Price_Assistant_Proxy_Client::OPTION_SITE_ID, '');
        echo '<input type="text" class="regular-text" name="'
            . esc_attr(Cashback_Price_Assistant_Proxy_Client::OPTION_SITE_ID)
            . '" value="' . esc_attr($value) . '" placeholder="savelloclub.ru" />';
    }

    public static function render_field_price_monitor_hmac_secret(): void {
        echo '<input type="password" class="regular-text" name="'
            . esc_attr(Cashback_Price_Assistant_Proxy_Client::OPTION_HMAC_SECRET)
            . '" value="" autocomplete="new-password" />';
        echo '<p class="description">'
            . esc_html__('Оставьте пустым, чтобы сохранить текущий secret. Значение не выводится обратно в HTML.', 'cashback')
            . '</p>';
    }

    public static function render_field_price_monitor_marketplaces(): void {
        foreach (Cashback_Price_Assistant_REST_Controller::marketplaces() as $marketplace => $label) {
            $option = Cashback_Price_Assistant_REST_Controller::marketplace_option_name($marketplace);
            $value  = (int) get_option($option, 0);
            echo '<label style="display:block;margin:4px 0;">';
            echo '<input type="hidden" name="' . esc_attr($option) . '" value="0" />';
            echo '<input type="checkbox" name="' . esc_attr($option) . '" value="1" ' . checked(1, $value, false) . ' /> ';
            echo esc_html($label);
            echo '</label>';
        }
    }
}
