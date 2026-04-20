<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Брендированные email-уведомления модуля социальной авторизации.
 *
 * Все методы формируют HTML с кнопкой подтверждения в цвет активной темы
 * и делегируют отправку Cashback_Email_Sender::send() (брендированная шапка
 * с логотипом сайта, footer, проверка предпочтений пользователя).
 *
 * Типы уведомлений (slug — для opt-out в ЛК):
 *  - social_confirm_link     — «Подтвердите привязку соцсети» (ветка B, критично)
 *  - social_verify_email     — Double opt-in после регистрации (ветка D, критично)
 *  - social_account_linked   — Security-уведомление об успешной привязке
 *  - social_account_unlinked — Security-уведомление об отвязке
 *
 * @since 1.1.0
 */
class Cashback_Social_Auth_Emails {

    /**
     * Типы уведомлений.
     */
    public const NOTIFY_CONFIRM_LINK     = 'social_confirm_link';
    public const NOTIFY_VERIFY_EMAIL     = 'social_verify_email';
    public const NOTIFY_ACCOUNT_LINKED   = 'social_account_linked';
    public const NOTIFY_ACCOUNT_UNLINKED = 'social_account_unlinked';

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
    }

    /**
     * Отправить письмо «Подтвердите привязку соцсети» (ветка B).
     *
     * @param WP_User $user           Существующий WP-пользователь.
     * @param string  $provider_label Человеко-читаемое имя провайдера (напр. «Яндекс ID»).
     * @param string  $confirm_url    Полный URL для подтверждения (с token).
     * @param int     $ttl_minutes    Срок действия ссылки (для текста письма).
     * @return bool
     */
    public function send_confirm_link( WP_User $user, string $provider_label, string $confirm_url, int $ttl_minutes = 15 ): bool {
        $subject = __('Подтвердите привязку соцсети', 'cashback-plugin');

        $intro = sprintf(
            /* translators: 1: provider label, 2: user email */
            esc_html__('Вы запросили привязку %1$s к аккаунту %2$s.', 'cashback-plugin'),
            esc_html($provider_label),
            esc_html($user->user_email)
        );

        $body = $this->build_button_body(
            $user->display_name !== '' ? $user->display_name : $user->user_login,
            $intro,
            __('Подтвердить привязку', 'cashback-plugin'),
            $confirm_url,
            $ttl_minutes,
            '',
            '',
            false
        );

        if (!class_exists('Cashback_Email_Sender')) {
            return false;
        }

        return Cashback_Email_Sender::get_instance()->send(
            $user->user_email,
            $subject,
            $body,
            self::NOTIFY_CONFIRM_LINK,
            (int) $user->ID
        );
    }

    /**
     * Отправить письмо «Подтвердите email» (ветка D, double opt-in).
     *
     * @param int    $user_id        ID только что созданного пользователя (pending=1).
     * @param string $provider_label Имя провайдера.
     * @param string $confirm_url    URL подтверждения.
     * @param int    $ttl_minutes    TTL в минутах (для текста).
     */
    public function send_verify_email( int $user_id, string $provider_label, string $confirm_url, int $ttl_minutes = 15 ): bool {
        $user = get_userdata($user_id);
        if (!( $user instanceof WP_User )) {
            $this->log_missing_user('send_verify_email', $user_id);
            return false;
        }

        $subject = __('Подтвердите ваш email', 'cashback-plugin');

        $intro = sprintf(
            /* translators: %s: provider label */
            esc_html__('Спасибо за регистрацию через %s. Осталось только подтвердить ваш email.', 'cashback-plugin'),
            esc_html($provider_label)
        );

        $body = $this->build_button_body(
            $user->display_name !== '' ? $user->display_name : $user->user_login,
            $intro,
            __('Подтвердить email', 'cashback-plugin'),
            $confirm_url,
            $ttl_minutes,
            '',
            '',
            false
        );

        if (!class_exists('Cashback_Email_Sender')) {
            return false;
        }

        return Cashback_Email_Sender::get_instance()->send(
            $user->user_email,
            $subject,
            $body,
            self::NOTIFY_VERIFY_EMAIL,
            $user_id
        );
    }

    /**
     * Security-уведомление об успешной привязке соцсети.
     *
     * @param int    $user_id        ID пользователя.
     * @param string $provider_label Имя провайдера.
     * @param string $ip             IP-адрес, с которого произошла привязка.
     * @param string $user_agent     User-Agent браузера.
     */
    public function send_account_linked( int $user_id, string $provider_label, string $ip, string $user_agent ): bool {
        $user = get_userdata($user_id);
        if (!( $user instanceof WP_User )) {
            $this->log_missing_user('send_account_linked', $user_id);
            return false;
        }

        $subject = sprintf(
            /* translators: %s: provider label */
            __('%s успешно привязан к вашему аккаунту', 'cashback-plugin'),
            $provider_label
        );

        $message = sprintf(
            /* translators: %s: provider label */
            esc_html__('К вашему аккаунту была успешно привязана социальная сеть: %s. Теперь вы можете входить через неё.', 'cashback-plugin'),
            esc_html($provider_label)
        );

        $body = $this->build_info_body(
            $user->display_name !== '' ? $user->display_name : $user->user_login,
            $message,
            $ip,
            $user_agent,
            true
        );

        if (!class_exists('Cashback_Email_Sender')) {
            return false;
        }

        return Cashback_Email_Sender::get_instance()->send(
            $user->user_email,
            $subject,
            $body,
            self::NOTIFY_ACCOUNT_LINKED,
            $user_id
        );
    }

    /**
     * Security-уведомление об отвязке соцсети.
     *
     * @param int    $user_id        ID пользователя.
     * @param string $provider_label Имя провайдера.
     * @param string $ip             IP-адрес, с которого произошла отвязка.
     * @param string $user_agent     User-Agent браузера.
     */
    public function send_account_unlinked( int $user_id, string $provider_label, string $ip, string $user_agent ): bool {
        $user = get_userdata($user_id);
        if (!( $user instanceof WP_User )) {
            $this->log_missing_user('send_account_unlinked', $user_id);
            return false;
        }

        $subject = sprintf(
            /* translators: %s: provider label */
            __('%s отвязан от вашего аккаунта', 'cashback-plugin'),
            $provider_label
        );

        $message = sprintf(
            /* translators: %s: provider label */
            esc_html__('Социальная сеть %s была отвязана от вашего аккаунта. Войти через неё больше нельзя.', 'cashback-plugin'),
            esc_html($provider_label)
        );

        $body = $this->build_info_body(
            $user->display_name !== '' ? $user->display_name : $user->user_login,
            $message,
            $ip,
            $user_agent,
            true
        );

        if (!class_exists('Cashback_Email_Sender')) {
            return false;
        }

        return Cashback_Email_Sender::get_instance()->send(
            $user->user_email,
            $subject,
            $body,
            self::NOTIFY_ACCOUNT_UNLINKED,
            $user_id
        );
    }

    // =========================================================================
    // HTML builders
    // =========================================================================

    /**
     * Сформировать HTML-тело письма с кнопкой действия.
     *
     * @param string $user_name   Отображаемое имя.
     * @param string $intro       Основная строка (уже escape-нутая, или пустая).
     * @param string $button_text Текст кнопки.
     * @param string $button_url  URL кнопки.
     * @param int    $ttl_minutes TTL (для строки «Ссылка действительна N минут»).
     * @param string $ip          IP (опционально, если пусто — не показываем блок).
     * @param string $user_agent  User-Agent (опционально).
     * @param bool   $with_meta   Показывать ли IP/UA/timestamp блок.
     * @return string
     */
    private function build_button_body(
        string $user_name,
        string $intro,
        string $button_text,
        string $button_url,
        int $ttl_minutes,
        string $ip,
        string $user_agent,
        bool $with_meta
    ): string {
        $html = Cashback_Email_Builder::greeting($user_name);

        if ($intro !== '') {
            $html .= Cashback_Email_Builder::paragraph($intro);
        }

        $html .= Cashback_Email_Builder::button($button_text, $button_url);
        $html .= Cashback_Email_Builder::ttl_note($ttl_minutes);

        if ($with_meta) {
            $html .= Cashback_Email_Builder::meta_block($ip, $user_agent);
        }

        return $html;
    }

    /**
     * Информационное тело письма (без кнопки, с блоком IP/UA/timestamp и advice).
     */
    private function build_info_body( string $user_name, string $message, string $ip, string $user_agent, bool $with_security_advice ): string {
        $html  = Cashback_Email_Builder::greeting($user_name);
        $html .= Cashback_Email_Builder::paragraph($message);

        if ($with_security_advice) {
            $html .= Cashback_Email_Builder::security_advice();
        }

        $html .= Cashback_Email_Builder::meta_block($ip, $user_agent);

        return $html;
    }

    /**
     * Залогировать ошибку «пользователь не найден» через Audit (не падаем).
     */
    private function log_missing_user( string $method, int $user_id ): void {
        if (class_exists('Cashback_Social_Auth_Audit')) {
            Cashback_Social_Auth_Audit::log('email_user_missing', array(
                'method'  => $method,
                'user_id' => $user_id,
            ));
        }
    }
}
