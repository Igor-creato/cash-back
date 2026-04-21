<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Настройки уведомлений в личном кабинете WooCommerce
 *
 * Endpoint: /my-account/cashback-notifications/
 */
class Cashback_Notifications_Frontend {

    private static ?self $instance = null;

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Endpoint
        add_action('init', array( $this, 'register_endpoint' ));
        add_filter('woocommerce_account_menu_items', array( $this, 'add_menu_item' ), 40);
        add_filter('query_vars', array( $this, 'add_query_vars' ));
        add_action('woocommerce_account_cashback-notifications_endpoint', array( $this, 'render_page' ));

        // AJAX
        add_action('wp_ajax_cashback_save_notification_prefs', array( $this, 'handle_save_preferences' ));

        // Стили и скрипты
        add_action('wp_enqueue_scripts', array( $this, 'enqueue_assets' ));
    }

    public function register_endpoint(): void {
        add_rewrite_endpoint('cashback-notifications', EP_ROOT | EP_PAGES);
    }

    /**
     * @param array<string,string> $items
     * @return array<string,string>
     */
    public function add_menu_item( array $items ): array {
        $logout = array();
        if (isset($items['customer-logout'])) {
            $logout = array( 'customer-logout' => $items['customer-logout'] );
            unset($items['customer-logout']);
        }

        $items['cashback-notifications'] = __('Уведомления', 'cashback-plugin');

        return array_merge($items, $logout);
    }

    /**
     * @param string[] $vars
     * @return string[]
     */
    public function add_query_vars( array $vars ): array {
        $vars[] = 'cashback-notifications';
        return $vars;
    }

    public function enqueue_assets(): void {
        if (!is_account_page()) {
            return;
        }

        $ver = defined('CASHBACK_PLUGIN_VERSION') ? CASHBACK_PLUGIN_VERSION : '1.0.0';

        wp_enqueue_style(
            'cashback-notifications',
            plugins_url('assets/css/cashback-notifications.css', __DIR__),
            array(),
            $ver
        );

        $brand_color = Cashback_Theme_Color::get_brand_color();
        $brand_text  = Cashback_Theme_Color::get_contrast_text_color($brand_color);
        wp_add_inline_style(
            'cashback-notifications',
            sprintf(
                ':root{--cashback-brand:%s;--cashback-brand-text:%s;}',
                esc_attr($brand_color),
                esc_attr($brand_text)
            )
        );

        wp_enqueue_script(
            'cashback-notifications',
            plugins_url('assets/js/cashback-notifications.js', __DIR__),
            array( 'jquery' ),
            $ver,
            true
        );

        wp_localize_script('cashback-notifications', 'cashbackNotifications', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('cashback_notification_prefs_nonce'),
        ));
    }

    /**
     * Рендер страницы настроек уведомлений
     */
    public function render_page(): void {
        $user_id = get_current_user_id();
        if (!$user_id) {
            echo '<p>' . esc_html__('Необходимо авторизоваться.', 'cashback-plugin') . '</p>';
            return;
        }

        $types = Cashback_Notifications_DB::get_user_notification_types();
        $prefs = Cashback_Notifications_DB::get_user_preferences($user_id);

        ?>
        <div class="cashback-notifications-settings">
            <h3><?php esc_html_e('Настройки уведомлений', 'cashback-plugin'); ?></h3>
            <p class="cashback-notifications-desc">
                <?php esc_html_e('Выберите, какие уведомления вы хотите получать на электронную почту.', 'cashback-plugin'); ?>
            </p>

            <form id="cashback-notification-prefs-form">
                <table class="cashback-notifications-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Тип уведомления', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Email', 'cashback-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $required_types = Cashback_Notifications_DB::get_required_notification_types();
                    foreach ($types as $slug => $label) :
                        // Если глобально выключено — показываем как disabled
                        $globally_enabled = Cashback_Notifications_DB::is_globally_enabled($slug);
                        $is_required      = in_array($slug, $required_types, true);
                        // Пользовательская настройка (по умолчанию — включено).
                        // Обязательные типы отправляются всегда — рендерим как checked+disabled.
                        $user_enabled = $prefs[ $slug ] ?? true;
                        $checked      = $is_required ? true : ( $globally_enabled && $user_enabled );
                        $locked       = $is_required || !$globally_enabled;
                        ?>
                        <tr>
                            <td>
                                <?php echo esc_html($label); ?>
                                <?php if ($is_required) : ?>
                                    <span class="cashback-notifications-disabled-hint">
                                        <?php esc_html_e('(отключить нельзя — нужно для входа)', 'cashback-plugin'); ?>
                                    </span>
                                <?php elseif (!$globally_enabled) : ?>
                                    <span class="cashback-notifications-disabled-hint">
                                        <?php esc_html_e('(отключено администратором)', 'cashback-plugin'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <label class="cashback-toggle">
                                    <input
                                        type="checkbox"
                                        name="notifications[<?php echo esc_attr($slug); ?>]"
                                        value="1"
                                        <?php checked($checked); ?>
                                        <?php disabled($locked); ?>
                                    />
                                    <span class="cashback-toggle-slider"></span>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="cashback-notifications-actions">
                    <button type="submit" class="button cashback-btn-primary" id="cashback-save-notification-prefs">
                        <?php esc_html_e('Сохранить', 'cashback-plugin'); ?>
                    </button>
                    <span class="cashback-notifications-status" id="cashback-notification-status"></span>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * AJAX: сохранение предпочтений
     */
    public function handle_save_preferences(): void {
        if (!check_ajax_referer('cashback_notification_prefs_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => __('Ошибка безопасности, обновите страницу.', 'cashback-plugin') ));
            return;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(array( 'message' => __('Необходимо авторизоваться.', 'cashback-plugin') ));
            return;
        }

        // Server-side дедуп request_id (Группа 5 ADR, F-39-003).
        // Frontend-хендлер (нет manage_options): идемпотентность нужна на случай
        // двойного submit или retry прокси — повторный UPDATE настроек безопасен,
        // но даёт лишний DB round-trip и (при подключённых хуках) лишние side-effects.
        $idem_scope      = 'frontend_notification_prefs_save';
        $idem_user_id    = $user_id;
        $idem_request_id = '';
        if (isset($_POST['request_id']) && is_string($_POST['request_id'])) {
            $idem_request_id = Cashback_Idempotency::normalize_request_id(
                sanitize_text_field(wp_unslash($_POST['request_id']))
            );
        }
        if ($idem_request_id !== '') {
            $idem_stored = Cashback_Idempotency::get_stored_result($idem_scope, $idem_user_id, $idem_request_id);
            if ($idem_stored !== null) {
                wp_send_json_success($idem_stored);
                return;
            }
            if (!Cashback_Idempotency::claim($idem_scope, $idem_user_id, $idem_request_id)) {
                wp_send_json_error(array(
                    'code'    => 'in_progress',
                    'message' => __('Запрос уже обрабатывается. Повторите через несколько секунд.', 'cashback-plugin'),
                ), 409);
                return;
            }
        }

        $types = Cashback_Notifications_DB::get_user_notification_types();

        // Парсим: если чекбокс не отправлен — значит выключен
        $raw = isset($_POST['notifications']) && is_array($_POST['notifications'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['notifications']))
            : array();

        $prefs = array();
        foreach (array_keys($types) as $slug) {
            $prefs[ $slug ] = isset($raw[ $slug ]);
        }

        Cashback_Notifications_DB::save_preferences($user_id, $prefs);

        $response_data = array( 'message' => __('Настройки сохранены.', 'cashback-plugin') );
        if ($idem_request_id !== '') {
            Cashback_Idempotency::store_result($idem_scope, $idem_user_id, $idem_request_id, $response_data);
        }

        wp_send_json_success($response_data);
    }
}
