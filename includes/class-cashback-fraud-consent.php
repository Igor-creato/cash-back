<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Fraud_Consent
 *
 * Управление согласием пользователя на сбор технических данных устройства
 * (IP, fingerprint браузера, device ID) для антифрод-системы согласно
 * Федеральному закону № 152-ФЗ ст. 9 (информированное согласие на обработку ПДн).
 *
 * Хранение:
 * - user_meta `cashback_fraud_consent_at` — datetime (mysql) момента согласия
 * - user_meta `cashback_fraud_consent_version` — версия текста согласия
 * - user_meta `cashback_fraud_consent_ip` — IP клиента в момент согласия (доказательство)
 *
 * Legacy-юзеры (зарегистрированные до даты `cashback_fraud_consent_required_after`)
 * получают подразумеваемое согласие — это нужно для мягкой миграции, чтобы существующая
 * база не блокировалась без явного opt-in.
 *
 * @since 1.1.0
 */
class Cashback_Fraud_Consent {

    public const META_KEY_CONSENT_AT      = 'cashback_fraud_consent_at';
    public const META_KEY_CONSENT_VERSION = 'cashback_fraud_consent_version';
    public const META_KEY_CONSENT_IP      = 'cashback_fraud_consent_ip';
    public const CURRENT_CONSENT_VERSION  = '1.0';

    public const OPTION_REQUIRED_AFTER    = 'cashback_fraud_consent_required_after';
    public const POST_FIELD               = 'cashback_fraud_consent';

    /**
     * Регистрация хуков WooCommerce + социальной авторизации.
     */
    public static function init(): void {
        // Форма регистрации WooCommerce.
        add_action('woocommerce_register_form', array( __CLASS__, 'render_checkbox' ), 20);
        add_filter('woocommerce_registration_errors', array( __CLASS__, 'validate_consent' ), 10, 3);
        add_action('woocommerce_created_customer', array( __CLASS__, 'save_consent_on_registration' ), 10, 2);

        // Social Auth: при регистрации через VK/Yandex явное согласие даётся
        // на стороне OAuth-провайдера (политика платформы) — фиксируем consent
        // автоматически. В модуле social-auth нет собственного хука создания юзера,
        // поэтому опираемся на стандартный user_register с проверкой meta-маркера
        // Cashback_Social_Auth_Account_Manager::META_VIA (выставляется сразу после
        // wp_insert_user в create_pending_user_and_link). См. файл
        // includes/social-auth/class-social-auth-account-manager.php:599.
        add_action('user_register', array( __CLASS__, 'maybe_save_consent_for_social' ), 30, 1);
    }

    /**
     * Возвращает true, если у пользователя есть действующее согласие
     * (либо legacy-подразумеваемое до даты введения требования).
     */
    public static function has_consent( int $user_id ): bool {
        if ($user_id <= 0) {
            return false;
        }

        // Тумблер consent_required: если отключён — считаем что согласие не требуется
        // и все пользователи проходят проверку (legacy/dev-сценарий).
        if (class_exists('Cashback_Fraud_Settings')
            && !Cashback_Fraud_Settings::is_consent_required()) {
            return true;
        }

        $consent_at = (string) get_user_meta($user_id, self::META_KEY_CONSENT_AT, true);
        if ($consent_at !== '') {
            $version = (string) get_user_meta($user_id, self::META_KEY_CONSENT_VERSION, true);
            // Если в будущем версия CURRENT_CONSENT_VERSION повысится, старые согласия
            // с меньшей версией будут считаться неактуальными — потребуется reconsent.
            return version_compare($version !== '' ? $version : '0.0', self::CURRENT_CONSENT_VERSION, '>=');
        }

        // Legacy fallback: пользователь зарегистрирован ДО даты введения требования.
        $required_after = (string) get_option(self::OPTION_REQUIRED_AFTER, '');
        if ($required_after === '') {
            return false;
        }

        $user = get_userdata($user_id);
        if (!$user instanceof WP_User || empty($user->user_registered)) {
            return false;
        }

        return strtotime((string) $user->user_registered) < strtotime($required_after);
    }

    /**
     * Возвращает datetime фиксации согласия (mysql) или null, если не задано.
     */
    public static function get_consent_at( int $user_id ): ?string {
        if ($user_id <= 0) {
            return null;
        }
        $value = (string) get_user_meta($user_id, self::META_KEY_CONSENT_AT, true);
        return $value !== '' ? $value : null;
    }

    /**
     * Записывает согласие пользователя.
     *
     * IP сохраняется как доказательство акта согласия (ст. 9 ч. 4 152-ФЗ
     * требует возможности подтвердить факт получения согласия).
     */
    public static function record_consent(
        int $user_id,
        string $ip = '',
        string $version = self::CURRENT_CONSENT_VERSION
    ): void {
        if ($user_id <= 0) {
            return;
        }

        update_user_meta($user_id, self::META_KEY_CONSENT_AT, current_time('mysql'));
        update_user_meta($user_id, self::META_KEY_CONSENT_VERSION, $version);
        update_user_meta($user_id, self::META_KEY_CONSENT_IP, self::sanitize_ip($ip));
    }

    /**
     * Отзыв согласия (право субъекта ПДн, 152-ФЗ ст. 9 ч. 2).
     * Удаляет всё meta — has_consent() начнёт возвращать false.
     */
    public static function withdraw_consent( int $user_id ): void {
        if ($user_id <= 0) {
            return;
        }
        delete_user_meta($user_id, self::META_KEY_CONSENT_AT);
        delete_user_meta($user_id, self::META_KEY_CONSENT_VERSION);
        delete_user_meta($user_id, self::META_KEY_CONSENT_IP);
    }

    /**
     * Рендер чекбокса согласия в форме регистрации WooCommerce.
     */
    public static function render_checkbox(): void {
        // Тумблер consent_required: если отключён — чекбокс не показываем
        // (требование 152-ФЗ временно снято, например для миграции legacy-юзеров).
        if (class_exists('Cashback_Fraud_Settings')
            && !Cashback_Fraud_Settings::is_consent_required()) {
            return;
        }

        $privacy_url = self::get_privacy_url();
        // POST_FIELD приходит сырым из $_POST — для preserve состояния при ошибке.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce обрабатывает nonce самостоятельно для register формы.
        $checked = isset($_POST[ self::POST_FIELD ]) && (string) wp_unslash($_POST[ self::POST_FIELD ]) === '1';

        $label_text  = esc_html__('Согласен на сбор технических данных устройства (IP, fingerprint браузера, device ID) для защиты от мошенничества согласно', 'cashback-plugin');
        $link_text   = esc_html__('152-ФЗ ст. 9', 'cashback-plugin');

        echo '<p class="form-row form-row-wide cashback-fraud-consent">';
        echo '<label for="' . esc_attr(self::POST_FIELD) . '" class="checkbox">';
        echo '<input type="checkbox" name="' . esc_attr(self::POST_FIELD) . '" id="' . esc_attr(self::POST_FIELD) . '" value="1" ' . checked($checked, true, false) . ' required /> ';
        echo esc_html($label_text) . ' ';

        if ($privacy_url !== '') {
            echo '<a href="' . esc_url($privacy_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($link_text) . '</a>';
        } else {
            echo esc_html($link_text);
        }

        echo '&nbsp;<span class="required">*</span>';
        echo '</label>';
        echo '</p>';
    }

    /**
     * Валидация: чекбокс должен быть отмечен.
     *
     * @param WP_Error $errors
     */
    public static function validate_consent( $errors, $username, $email ) {
        // Тумблер consent_required: при отключённом — ошибка валидации не выставляется.
        if (class_exists('Cashback_Fraud_Settings')
            && !Cashback_Fraud_Settings::is_consent_required()) {
            return $errors;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce валидирует woocommerce-register-nonce централизованно перед вызовом этого фильтра.
        $value = isset($_POST[ self::POST_FIELD ]) ? (string) wp_unslash($_POST[ self::POST_FIELD ]) : '';

        if ($value !== '1') {
            if ($errors instanceof WP_Error) {
                $errors->add(
                    'cashback_fraud_consent_required',
                    esc_html__('Для регистрации необходимо согласие на обработку технических данных устройства (IP, fingerprint браузера) для защиты от мошенничества согласно 152-ФЗ ст. 9.', 'cashback-plugin')
                );
            }
        }

        return $errors;
    }

    /**
     * Сохранение согласия после успешной регистрации через WooCommerce.
     *
     * @param array<string, mixed> $data
     */
    public static function save_consent_on_registration( int $user_id, $data = array() ): void {
        unset($data); // не используется, но требуется сигнатурой WC.

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce уже проверен WooCommerce до этого хука.
        $value = isset($_POST[ self::POST_FIELD ]) ? (string) wp_unslash($_POST[ self::POST_FIELD ]) : '';
        if ($value !== '1') {
            return;
        }

        self::record_consent($user_id, self::detect_client_ip());
    }

    /**
     * Хук-обёртка для социальной регистрации (VK/Yandex):
     * фиксируем consent, если юзер только что создан через soc-auth модуль.
     *
     * Маркером служит user_meta cashback_social_via, выставляемая в
     * Cashback_Social_Auth_Account_Manager::create_pending_user_and_link
     * сразу после wp_insert_user.
     */
    public static function maybe_save_consent_for_social( int $user_id ): void {
        if ($user_id <= 0) {
            return;
        }
        // Если уже есть consent (например, из woocommerce-формы) — не перезаписываем.
        if (self::get_consent_at($user_id) !== null) {
            return;
        }
        // Признак, что юзер создан модулем social-auth.
        $via = (string) get_user_meta($user_id, 'cashback_social_via', true);
        if ($via === '' || (int) $via !== 1) {
            return;
        }
        self::save_consent_for_social($user_id, null);
    }

    /**
     * Запись согласия для пользователей, прошедших OAuth.
     * Подтверждение принципа: соглашаясь с условиями VK/Yandex (включая обработку
     * технических данных у платформы и передачу нашему сайту), пользователь даёт
     * информированное согласие на обработку ПДн при регистрации.
     *
     * @param mixed $oauth_data произвольная мета (профиль, токены) — не используется.
     */
    public static function save_consent_for_social( int $user_id, $oauth_data = null ): void {
        unset($oauth_data);
        if ($user_id <= 0) {
            return;
        }
        if (self::get_consent_at($user_id) !== null) {
            return;
        }
        self::record_consent($user_id, self::detect_client_ip());
    }

    /**
     * Текст согласия для UI/email/политики конфиденциальности.
     */
    public static function get_consent_text(): string {
        return __(
            'Я даю согласие на сбор и обработку технических данных моего устройства (IP-адрес, fingerprint браузера, device ID, User-Agent) в целях защиты сервиса от мошенничества и обеспечения безопасности учётной записи. Правовое основание — ст. 9 Федерального закона от 27.07.2006 № 152-ФЗ. Я понимаю, что могу отозвать согласие в личном кабинете; отзыв согласия может повлечь ограничение функциональности кэшбэк-сервиса.',
            'cashback-plugin'
        );
    }

    /**
     * URL политики конфиденциальности WordPress (если задана в админке).
     */
    public static function get_privacy_url(): string {
        $page_id = (int) get_option('wp_page_for_privacy_policy', 0);
        if ($page_id <= 0) {
            return '';
        }
        $url = get_privacy_policy_url();
        return is_string($url) ? $url : '';
    }

    /**
     * Гарантирует, что опция-флаг "требовать consent для регистраций после X"
     * установлена. Вызывается при активации плагина.
     */
    public static function ensure_required_after_option(): void {
        if (get_option(self::OPTION_REQUIRED_AFTER, null) === null) {
            // first-install / first-activation: фиксируем дату — все юзеры до неё
            // считаются legacy и получают подразумеваемое согласие.
            add_option(self::OPTION_REQUIRED_AFTER, current_time('mysql'));
        }
    }

    // -----------------------------------------------------------------
    // private helpers
    // -----------------------------------------------------------------

    private static function detect_client_ip(): string {
        // REMOTE_ADDR — самое надёжное на бэкенде; X-Forwarded-For не доверяем
        // без явной настройки доверенного proxy (выходит за рамки этого этапа).
        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return self::sanitize_ip($remote);
    }

    private static function sanitize_ip( string $ip ): string {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }
        $filtered = filter_var($ip, FILTER_VALIDATE_IP);
        return is_string($filtered) ? $filtered : '';
    }
}
