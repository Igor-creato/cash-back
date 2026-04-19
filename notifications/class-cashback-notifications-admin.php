<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Административная страница настроек уведомлений
 *
 * Подменю: Кэшбэк → Уведомления
 * Глобальное управление: вкл/выкл по типам
 */
class Cashback_Notifications_Admin {

    public function __construct() {
        add_action('admin_menu', array( $this, 'add_menu_page' ));
        add_action('wp_ajax_cashback_admin_save_notification_settings', array( $this, 'handle_save_settings' ));
        add_action('admin_enqueue_scripts', array( $this, 'enqueue_assets' ));
    }

    public function add_menu_page(): void {
        add_submenu_page(
            'cashback-overview',
            __('Уведомления', 'cashback-plugin'),
            __('Уведомления', 'cashback-plugin'),
            'manage_options',
            'cashback-notifications',
            array( $this, 'render_page' )
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ($hook !== 'cashback_page_cashback-notifications' && strpos($hook, 'cashback-notifications') === false) {
            return;
        }

        // Для вкладки «Рассылка» подгружаем ассеты визуального редактора.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Чтение ?tab= для переключения вкладок в UI.
        $tab_query = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
        if ($tab_query === 'broadcast' && function_exists('wp_enqueue_editor')) {
            wp_enqueue_editor();
        }

        $ver = defined('CASHBACK_PLUGIN_VERSION') ? CASHBACK_PLUGIN_VERSION : '1.0.0';

        wp_enqueue_style(
            'cashback-admin-notifications',
            plugins_url('assets/css/admin-notifications.css', __DIR__),
            array(),
            $ver
        );

        wp_enqueue_script(
            'cashback-admin-notifications',
            plugins_url('assets/js/admin-notifications.js', __DIR__),
            array( 'jquery' ),
            $ver,
            true
        );

        wp_localize_script('cashback-admin-notifications', 'cashbackAdminNotifications', array(
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('cashback_admin_notification_settings_nonce'),
            'broadcastNonce' => wp_create_nonce('cashback_broadcast_nonce'),
            'i18n'           => array(
                'confirmSend'   => __('Отправить письмо всем выбранным получателям? Действие нельзя отменить после старта.', 'cashback-plugin'),
                'confirmCancel' => __('Отменить эту рассылку? Оставшиеся письма не будут отправлены.', 'cashback-plugin'),
                'networkError'  => __('Ошибка сети', 'cashback-plugin'),
                'sending'       => __('Отправка...', 'cashback-plugin'),
                'calculating'   => __('Считаем...', 'cashback-plugin'),
                'emptySubject'  => __('Заполните тему письма.', 'cashback-plugin'),
                'emptyBody'     => __('Заполните тело письма.', 'cashback-plugin'),
            ),
        ));
    }

    /**
     * Рендер страницы настроек
     */
    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Чтение ?tab= для переключения вкладок в UI.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
        if (!in_array($tab, array( 'settings', 'broadcast' ), true)) {
            $tab = 'settings';
        }

        $tabs_base = admin_url('admin.php?page=cashback-notifications');

        ?>
        <div class="wrap cashback-admin-notifications">
            <h1><?php esc_html_e('Уведомления', 'cashback-plugin'); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(add_query_arg('tab', 'settings', $tabs_base)); ?>"
                    class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Настройки', 'cashback-plugin'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'broadcast', $tabs_base)); ?>"
                    class="nav-tab <?php echo $tab === 'broadcast' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Рассылка', 'cashback-plugin'); ?>
                </a>
            </h2>

            <?php
            if ($tab === 'broadcast') {
                if (class_exists('Cashback_Broadcast')) {
                    Cashback_Broadcast::get_instance()->render_broadcast_tab();
                } else {
                    echo '<p>' . esc_html__('Модуль рассылки недоступен.', 'cashback-plugin') . '</p>';
                }
                ?>
                </div>
                <?php
                return;
            }

            $all_types = Cashback_Notifications_DB::get_all_notification_types();

            // Группируем типы для удобства
            $user_types  = Cashback_Notifications_DB::get_user_notification_types();
            $admin_types = array_diff_key($all_types, $user_types);
            ?>

            <form id="cashback-admin-notification-form">

                <!-- Уведомления пользователям -->
                <div class="cashback-admin-card">
                    <h2><?php esc_html_e('Уведомления пользователям', 'cashback-plugin'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Глобальное управление уведомлениями, которые получают пользователи. Отключённые уведомления не будут отправляться никому.', 'cashback-plugin'); ?>
                    </p>

                    <table class="form-table cashback-notifications-admin-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Тип уведомления', 'cashback-plugin'); ?></th>
                                <th><?php esc_html_e('Статус', 'cashback-plugin'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        foreach ($user_types as $slug => $label) :
                            $enabled = Cashback_Notifications_DB::is_globally_enabled($slug);
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($label); ?></strong></td>
                                <td>
                                    <label class="cashback-admin-toggle">
                                        <input
                                            type="checkbox"
                                            name="notify_<?php echo esc_attr($slug); ?>"
                                            value="1"
                                            <?php checked($enabled); ?>
                                        />
                                        <span class="cashback-admin-toggle-slider"></span>
                                        <span class="cashback-admin-toggle-label">
                                            <?php
                                            echo $enabled
                                                ? esc_html__('Включено', 'cashback-plugin')
                                                : esc_html__('Выключено', 'cashback-plugin');
                                                ?>
                                        </span>
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Уведомления администраторам -->
                <div class="cashback-admin-card">
                    <h2><?php esc_html_e('Уведомления администраторам', 'cashback-plugin'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Уведомления, которые получают администраторы и менеджеры магазина.', 'cashback-plugin'); ?>
                    </p>

                    <table class="form-table cashback-notifications-admin-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Тип уведомления', 'cashback-plugin'); ?></th>
                                <th><?php esc_html_e('Статус', 'cashback-plugin'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        foreach ($admin_types as $slug => $label) :
                            $enabled = Cashback_Notifications_DB::is_globally_enabled($slug);
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($label); ?></strong></td>
                                <td>
                                    <label class="cashback-admin-toggle">
                                        <input
                                            type="checkbox"
                                            name="notify_<?php echo esc_attr($slug); ?>"
                                            value="1"
                                            <?php checked($enabled); ?>
                                        />
                                        <span class="cashback-admin-toggle-slider"></span>
                                        <span class="cashback-admin-toggle-label">
                                            <?php
                                            echo $enabled
                                                ? esc_html__('Включено', 'cashback-plugin')
                                                : esc_html__('Выключено', 'cashback-plugin');
                                                ?>
                                        </span>
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Настройки отправителя -->
                <div class="cashback-admin-card">
                    <h2><?php esc_html_e('Настройки отправителя', 'cashback-plugin'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="cashback_email_sender_name">
                                    <?php esc_html_e('Имя отправителя', 'cashback-plugin'); ?>
                                </label>
                            </th>
                            <td>
                                <input
                                    type="text"
                                    id="cashback_email_sender_name"
                                    name="email_sender_name"
                                    value="<?php echo esc_attr(get_option('cashback_email_sender_name', get_option('blogname', ''))); ?>"
                                    class="regular-text"
                                />
                                <p class="description">
                                    <?php esc_html_e('Имя, от которого будут отправляться письма.', 'cashback-plugin'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="cashback_email_sender_email">
                                    <?php esc_html_e('Email отправителя', 'cashback-plugin'); ?>
                                </label>
                            </th>
                            <td>
                                <input
                                    type="email"
                                    id="cashback_email_sender_email"
                                    name="email_sender_email"
                                    value="<?php echo esc_attr(get_option('cashback_email_sender_email', '')); ?>"
                                    class="regular-text"
                                    placeholder="<?php echo esc_attr(get_option('admin_email', '')); ?>"
                                />
                                <p class="description">
                                    <?php esc_html_e('Email-адрес в поле «От кого». Должен быть рабочим ящиком на вашем сервере. Если не задан — используется email администратора WordPress.', 'cashback-plugin'); ?>
                                </p>
                                <?php
                                $admin_email  = get_option('admin_email', '');
                                $sender_email = get_option('cashback_email_sender_email', '');
                                $current_from = $sender_email ?: $admin_email;
                                ?>
                                <p class="description" style="margin-top:6px;">
                                    <strong><?php esc_html_e('Сейчас используется:', 'cashback-plugin'); ?></strong>
                                    <code><?php echo esc_html($current_from); ?></code>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="cashback_email_signature">
                                    <?php esc_html_e('Подпись', 'cashback-plugin'); ?>
                                </label>
                            </th>
                            <td>
                                <textarea
                                    id="cashback_email_signature"
                                    name="email_signature"
                                    rows="4"
                                    class="large-text"
                                ><?php echo esc_textarea(get_option('cashback_email_signature', '')); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('Текст подписи, который будет выводиться внизу письма пользователю.', 'cashback-plugin'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="cashback-save-notification-admin-settings">
                        <?php esc_html_e('Сохранить настройки', 'cashback-plugin'); ?>
                    </button>
                    <span class="cashback-admin-notification-status" id="cashback-admin-notification-status"></span>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * AJAX: сохранение настроек
     */
    public function handle_save_settings(): void {
        if (!check_ajax_referer('cashback_admin_notification_settings_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => __('Ошибка безопасности.', 'cashback-plugin') ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => __('Недостаточно прав.', 'cashback-plugin') ));
            return;
        }

        $all_types = Cashback_Notifications_DB::get_all_notification_types();

        foreach (array_keys($all_types) as $slug) {
            $key     = 'notify_' . $slug;
            $enabled = !empty($_POST[ $key ]);
            update_option('cashback_notify_' . $slug, $enabled ? 1 : 0);
        }

        // Имя отправителя
        if (isset($_POST['email_sender_name'])) {
            $sender_name = sanitize_text_field(wp_unslash($_POST['email_sender_name']));
            update_option('cashback_email_sender_name', $sender_name);
        }

        // Email отправителя
        if (isset($_POST['email_sender_email'])) {
            $sender_email = sanitize_email(wp_unslash($_POST['email_sender_email']));
            update_option('cashback_email_sender_email', $sender_email);
        }

        // Подпись в письмах пользователям
        if (isset($_POST['email_signature'])) {
            $signature = sanitize_textarea_field(wp_unslash($_POST['email_signature']));
            update_option('cashback_email_signature', $signature);
        }

        wp_send_json_success(array( 'message' => __('Настройки сохранены.', 'cashback-plugin') ));
    }
}
