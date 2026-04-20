<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Брендированное письмо сброса пароля.
 *
 * Перехватывает два сценария:
 *  - WordPress core / wp-login.php (filter retrieve_password_notification_email)
 *  - WooCommerce My Account (action woocommerce_reset_password_notification)
 *
 * Формирует тело через Cashback_Email_Builder, обёртка (шапка+футер) —
 * общий шаблон Cashback_Email_Sender::send_critical (bypass opt-out,
 * т.к. password reset — критично для восстановления доступа).
 */
class Cashback_Password_Reset_Email {

    public static function init(): void {
        add_filter('retrieve_password_notification_email', array( __CLASS__, 'filter_wp_reset_email' ), 10, 4);

        // WooCommerce фаерит woocommerce_loaded на plugins_loaded @ -1, наш плагин грузится на @ 10 —
        // к этому моменту действие уже отработало, и add_action('woocommerce_loaded', ...) был бы no-op.
        if (did_action('woocommerce_loaded') || function_exists('WC')) {
            self::register_wc_handler();
        } else {
            add_action('woocommerce_loaded', array( __CLASS__, 'register_wc_handler' ));
        }
    }

    /**
     * Фильтр письма WP core (wp-login.php → retrieve_password()).
     *
     * Мы подменяем стандартное plain-text письмо WP на брендированное:
     * subject/message/headers заменяются целиком, но wp_mail() всё равно
     * вызывается самим WordPress — т.е. никакой двойной отправки.
     *
     * @param array        $defaults   to/subject/message/headers.
     * @param string       $key        Токен сброса.
     * @param string       $user_login Логин пользователя.
     * @param WP_User|null $user_data  Объект пользователя.
     * @return array
     */
    public static function filter_wp_reset_email( $defaults, $key, $user_login, $user_data ): array {
        if (!is_array($defaults)) {
            $defaults = array();
        }
        if (!( $user_data instanceof WP_User )) {
            return $defaults;
        }

        if (!class_exists('Cashback_Email_Sender')) {
            return $defaults;
        }

        $reset_url = self::build_reset_url($key, $user_login);
        $subject   = self::get_subject();
        $body      = self::render_body($user_data, $reset_url);

        $defaults['subject'] = $subject;
        $defaults['message'] = Cashback_Email_Sender::get_instance()->preview_html(
            $subject,
            $body,
            (int) $user_data->ID
        );
        $defaults['headers'] = self::ensure_html_headers($defaults['headers'] ?? '');

        return $defaults;
    }

    /**
     * Регистрация обработчика для WooCommerce-потока.
     *
     * Подавляем дефолтное письмо WC_Email_Customer_Reset_Password и отдаём своё.
     */
    public static function register_wc_handler(): void {
        add_filter('woocommerce_email_enabled_customer_reset_password', '__return_false');
        add_action('woocommerce_reset_password_notification', array( __CLASS__, 'handle_wc_reset_notification' ), 10, 2);
    }

    /**
     * Отправка брендированного письма в WC-сценарии.
     */
    public static function handle_wc_reset_notification( $user_login, $reset_key ): void {
        $user_login = is_string($user_login) ? $user_login : '';
        $reset_key  = is_string($reset_key) ? $reset_key : '';
        if ($user_login === '' || $reset_key === '') {
            return;
        }

        $user = get_user_by('login', $user_login);
        if (!( $user instanceof WP_User )) {
            return;
        }

        if (!class_exists('Cashback_Email_Sender')) {
            return;
        }

        $reset_url = self::build_reset_url($reset_key, $user_login);
        $subject   = self::get_subject();
        $body      = self::render_body($user, $reset_url);

        Cashback_Email_Sender::get_instance()->send_critical(
            $user->user_email,
            $subject,
            $body,
            (int) $user->ID
        );
    }

    /**
     * Сформировать URL сброса. На WC-сайте ведём на страницу My Account,
     * иначе — на стандартный wp-login.php.
     */
    private static function build_reset_url( string $key, string $user_login ): string {
        if (function_exists('wc_get_page_permalink')) {
            $myaccount = wc_get_page_permalink('myaccount');
            if (is_string($myaccount) && $myaccount !== '') {
                return add_query_arg(
                    array(
                        'action' => 'rp',
                        'key'    => $key,
                        'login'  => rawurlencode($user_login),
                    ),
                    $myaccount
                );
            }
        }

        return network_site_url(
            'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode($user_login),
            'login'
        );
    }

    private static function get_subject(): string {
        return __('Сброс пароля', 'cashback-plugin');
    }

    /**
     * Тело письма: приветствие → описание → кнопка → подсказка.
     */
    private static function render_body( WP_User $user, string $reset_url ): string {
        $user_name = $user->display_name !== '' ? $user->display_name : $user->user_login;

        $html  = Cashback_Email_Builder::greeting($user_name);
        $html .= Cashback_Email_Builder::paragraph(
            esc_html__('Вы запросили сброс пароля для вашего аккаунта. Нажмите на кнопку ниже, чтобы задать новый пароль.', 'cashback-plugin')
        );
        $html .= Cashback_Email_Builder::button(
            __('Сбросить пароль', 'cashback-plugin'),
            $reset_url
        );
        $html .= Cashback_Email_Builder::note(
            esc_html__('Если это были не вы — просто проигнорируйте письмо, пароль не изменится.', 'cashback-plugin')
        );

        return $html;
    }

    /**
     * Гарантировать Content-Type: text/html в заголовках (не теряя уже заданные).
     *
     * Нужен только для WP-фильтра retrieve_password_notification_email:
     * сам wp_mail() вызывает WordPress, и без Content-Type наш HTML-body
     * уйдёт как plain text. В WC-сценарии заголовки формирует Email_Sender.
     *
     * @param string|array $headers
     * @return array
     */
    private static function ensure_html_headers( $headers ): array {
        if (is_string($headers)) {
            $headers = $headers === '' ? array() : array( $headers );
        }
        if (!is_array($headers)) {
            $headers = array();
        }

        foreach ($headers as $h) {
            if (is_string($h) && stripos($h, 'content-type:') === 0) {
                return $headers;
            }
        }
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        return $headers;
    }
}

Cashback_Password_Reset_Email::init();
