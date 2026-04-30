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
        // Подтверждение «Пароль изменён» (wp_update_user / профиль / после reset_password).
        add_filter('password_change_email', array( __CLASS__, 'filter_password_change_email' ), 10, 3);

        // WooCommerce фаерит woocommerce_loaded на plugins_loaded @ -1, наш плагин грузится на @ 10 —
        // к этому моменту действие уже отработало, и add_action('woocommerce_loaded', ...) был бы no-op.
        if (did_action('woocommerce_loaded') || function_exists('WC')) {
            self::register_wc_handler();
        } else {
            add_action('woocommerce_loaded', array( __CLASS__, 'register_wc_handler' ));
        }
    }

    /**
     * Фильтр уведомления «Пароль изменён» (filter password_change_email).
     *
     * WP core шлёт это письмо после wp_update_user() при смене пароля
     * (UI-профиль, reset-flow, wp-cli `wp user update --user_pass`).
     * Брендируем тело и оборачиваем в общий шаблон Cashback_Email_Sender.
     *
     * @param array        $pass_change_email Массив to/subject/message/headers.
     * @param array|null   $user              Массив с обновлёнными данными (associative WP_User).
     * @param array|null   $userdata          Сырой массив userdata, переданный в wp_update_user.
     * @return array
     */
    public static function filter_password_change_email( $pass_change_email, $user, $userdata ): array {
        unset($userdata);

        if (!is_array($pass_change_email)) {
            $pass_change_email = array();
        }
        if (!is_array($user) || empty($user['ID'])) {
            return $pass_change_email;
        }
        if (!class_exists('Cashback_Email_Sender') || !class_exists('Cashback_Email_Builder')) {
            return $pass_change_email;
        }

        $user_id = (int) $user['ID'];
        $wp_user = get_userdata($user_id);
        if (!( $wp_user instanceof WP_User )) {
            return $pass_change_email;
        }

        $subject = self::get_change_subject();
        $body    = self::render_change_body($wp_user);

        $pass_change_email['subject'] = $subject;
        $pass_change_email['message'] = Cashback_Email_Sender::get_instance()->preview_html(
            $subject,
            $body,
            $user_id
        );
        $pass_change_email['headers'] = self::ensure_html_headers($pass_change_email['headers'] ?? '');

        return $pass_change_email;
    }

    private static function get_change_subject(): string {
        return __('Пароль изменён', 'cashback-plugin');
    }

    /**
     * Тело письма «Пароль изменён»: приветствие → подтверждение → security-advice
     * (если это были не вы — обратитесь к админу) → email получателя для прозрачности.
     */
    private static function render_change_body( WP_User $user ): string {
        $user_name   = $user->display_name !== '' ? $user->display_name : $user->user_login;
        $blogname    = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
        $admin_email = (string) get_option('admin_email');

        $html  = Cashback_Email_Builder::greeting($user_name);

        if ($blogname !== '') {
            $html .= Cashback_Email_Builder::paragraph(
                sprintf(
                    /* translators: %s: site name */
                    esc_html__('Это уведомление подтверждает успешную смену пароля на сайте «%s».', 'cashback-plugin'),
                    esc_html($blogname)
                )
            );
        } else {
            $html .= Cashback_Email_Builder::paragraph(
                esc_html__('Это уведомление подтверждает успешную смену пароля.', 'cashback-plugin')
            );
        }

        if ($admin_email !== '') {
            /* translators: %1$s: admin email (used twice — текст и href) */
            $tpl = __('Если вы не меняли пароль — напишите администратору на <a href="mailto:%1$s">%1$s</a>.', 'cashback-plugin');
            $html .= Cashback_Email_Builder::note(
                sprintf(
                    wp_kses($tpl, array( 'a' => array( 'href' => array() ) )),
                    esc_attr($admin_email)
                )
            );
        }

        $html .= Cashback_Email_Builder::note(
            sprintf(
                /* translators: %s: user email */
                esc_html__('Письмо отправлено на %s.', 'cashback-plugin'),
                esc_html((string) $user->user_email)
            )
        );

        return $html;
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
