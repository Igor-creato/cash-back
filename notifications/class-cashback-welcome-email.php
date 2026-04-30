<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Брендированное приветственное письмо при регистрации.
 *
 * Перехватывает два сценария:
 *  - WordPress core (admin создаёт пользователя из wp-admin) — filter wp_new_user_notification_email
 *  - WooCommerce регистрация (My Account form, checkout, admin via WC) — action woocommerce_created_customer_notification
 *
 * Шаблон обёртки (шапка+футер) — общий Cashback_Email_Sender (как у password reset),
 * тело — Cashback_Email_Builder. send_critical bypass'ит opt-out: welcome — это
 * критическое уведомление о создании учётки (пользователь ещё не управлял
 * своими предпочтениями уведомлений).
 */
class Cashback_Welcome_Email {

    public static function init(): void {
        add_filter('wp_new_user_notification_email', array( __CLASS__, 'filter_wp_new_user_email' ), 10, 3);

        // Аналогично password-reset: WC уже мог быть инициализирован к моменту require_file.
        if (did_action('woocommerce_loaded') || function_exists('WC')) {
            self::register_wc_handler();
        } else {
            add_action('woocommerce_loaded', array( __CLASS__, 'register_wc_handler' ));
        }
    }

    /**
     * Фильтр welcome-письма WP core (wp_new_user_notification → user-side).
     *
     * Подменяем стандартное plain-text письмо WP на брендированное:
     * subject/message/headers заменяются целиком, но wp_mail() всё равно
     * вызывается самим WordPress — никакой двойной отправки.
     *
     * @param array        $defaults Массив с ключами to/subject/message/headers.
     * @param WP_User|null $user     Объект созданного пользователя.
     * @param string       $blogname Имя сайта (не используем — берём из настроек плагина).
     * @return array
     */
    public static function filter_wp_new_user_email( $defaults, $user, $blogname ): array {
        unset($blogname);

        if (!is_array($defaults)) {
            $defaults = array();
        }
        if (!( $user instanceof WP_User )) {
            return $defaults;
        }
        if (!class_exists('Cashback_Email_Sender') || !class_exists('Cashback_Email_Builder')) {
            return $defaults;
        }

        $set_password_url = self::try_build_set_password_url($user);
        $subject          = self::get_subject();
        $body             = self::render_body($user, $set_password_url, true);

        $defaults['subject'] = $subject;
        $defaults['message'] = Cashback_Email_Sender::get_instance()->preview_html(
            $subject,
            $body,
            (int) $user->ID
        );
        $defaults['headers'] = self::ensure_html_headers($defaults['headers'] ?? '');

        return $defaults;
    }

    /**
     * Регистрация обработчика для WooCommerce-потока.
     *
     * Подавляем дефолтное письмо WC_Email_Customer_New_Account и отдаём своё.
     */
    public static function register_wc_handler(): void {
        add_filter('woocommerce_email_enabled_customer_new_account', '__return_false');
        add_action('woocommerce_created_customer_notification', array( __CLASS__, 'handle_wc_created_customer' ), 10, 3);
    }

    /**
     * Отправка брендированного welcome-письма для WC-сценария.
     *
     * @param int   $user_id            ID созданного пользователя.
     * @param array $new_customer_data  Не используется (оставлен для совместимости с сигнатурой WC action).
     * @param bool  $password_generated true, если пароль сгенерирован WC и нужна ссылка «Задать пароль».
     */
    public static function handle_wc_created_customer( $user_id, $new_customer_data = array(), $password_generated = false ): void {
        unset($new_customer_data);

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }

        $user = get_user_by('id', $user_id);
        if (!( $user instanceof WP_User )) {
            return;
        }

        if (!class_exists('Cashback_Email_Sender') || !class_exists('Cashback_Email_Builder')) {
            return;
        }

        $set_password_url = '';
        if ($password_generated) {
            $set_password_url = self::try_build_set_password_url($user);
        }

        $subject = self::get_subject();
        $body    = self::render_body($user, $set_password_url, (bool) $password_generated);

        Cashback_Email_Sender::get_instance()->send_critical(
            $user->user_email,
            $subject,
            $body,
            (int) $user->ID
        );
    }

    /**
     * Сгенерировать одноразовый ключ + URL для «Задать пароль».
     * Возвращает '' при сбое (например, если get_password_reset_key() вернул WP_Error).
     */
    private static function try_build_set_password_url( WP_User $user ): string {
        $key = get_password_reset_key($user);
        if (is_wp_error($key) || !is_string($key) || $key === '') {
            return '';
        }
        return self::build_set_password_url($key, (string) $user->user_login);
    }

    /**
     * URL для установки пароля. На WC-сайте ведём на My Account, иначе wp-login.php.
     * Совпадает по логике с Cashback_Password_Reset_Email::build_reset_url().
     */
    private static function build_set_password_url( string $key, string $user_login ): string {
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

    private static function build_my_account_url(): string {
        if (function_exists('wc_get_page_permalink')) {
            $myaccount = wc_get_page_permalink('myaccount');
            if (is_string($myaccount) && $myaccount !== '') {
                return $myaccount;
            }
        }
        return home_url('/my-account/');
    }

    private static function get_subject(): string {
        $blogname = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
        if ($blogname === '') {
            return __('Добро пожаловать', 'cashback-plugin');
        }
        return sprintf(
            /* translators: %s: site name */
            __('Добро пожаловать в «%s»', 'cashback-plugin'),
            $blogname
        );
    }

    /**
     * Тело письма: приветствие → благодарность → реквизиты учётки →
     * (опционально) кнопка «Задать пароль» → кнопка «Мой аккаунт».
     */
    private static function render_body( WP_User $user, string $set_password_url, bool $show_set_password ): string {
        $user_name = $user->display_name !== '' ? $user->display_name : $user->user_login;
        $blogname  = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);

        $html  = Cashback_Email_Builder::greeting($user_name);

        if ($blogname !== '') {
            $html .= Cashback_Email_Builder::paragraph(
                sprintf(
                    /* translators: %s: site name */
                    esc_html__('Благодарим вас за создание учётной записи на «%s». Ниже — копия сведений о пользователе.', 'cashback-plugin'),
                    esc_html($blogname)
                )
            );
        } else {
            $html .= Cashback_Email_Builder::paragraph(
                esc_html__('Благодарим вас за создание учётной записи. Ниже — копия сведений о пользователе.', 'cashback-plugin')
            );
        }

        $html .= Cashback_Email_Builder::definition_list(array(
            __('Имя пользователя', 'cashback-plugin') => (string) $user->user_login,
            __('Email', 'cashback-plugin')             => (string) $user->user_email,
        ));

        if ($show_set_password && $set_password_url !== '') {
            $html .= Cashback_Email_Builder::paragraph(
                esc_html__('Чтобы войти в личный кабинет, задайте пароль:', 'cashback-plugin')
            );
            $html .= Cashback_Email_Builder::button(
                __('Задать пароль', 'cashback-plugin'),
                $set_password_url
            );
        }

        $html .= Cashback_Email_Builder::paragraph(
            esc_html__('В личном кабинете доступны заказы, история кэшбэка и выплат, настройки уведомлений и реферальная ссылка.', 'cashback-plugin')
        );
        $html .= Cashback_Email_Builder::button(
            __('Перейти в личный кабинет', 'cashback-plugin'),
            self::build_my_account_url()
        );

        return $html;
    }

    /**
     * Гарантировать Content-Type: text/html в заголовках.
     *
     * Нужен только для WP-фильтра wp_new_user_notification_email: WordPress
     * сам вызывает wp_mail(), и без Content-Type наш HTML-body уйдёт как plain
     * text. В WC-сценарии заголовки формирует Email_Sender.
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

Cashback_Welcome_Email::init();
