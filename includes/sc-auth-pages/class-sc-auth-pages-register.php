<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_SC_Auth_Pages_Register
 *
 * Обработчик POST-запросов формы /register/.
 *
 * Использует **wc_create_new_customer** — это запускает стандартный WooCommerce
 * pipeline, благодаря которому автоматически срабатывают:
 *   - woocommerce_register_post (валидация)
 *   - woocommerce_registration_errors (legal/fraud consent в cash-back)
 *   - woocommerce_created_customer (запись в cashback_consent_log)
 *
 * Это ключевой архитектурный выбор: мы НЕ дублируем consent-логику,
 * а полагаемся на существующие cash-back хуки.
 *
 * Безопасность:
 *  - nonce 'sc_auth_pages_register'
 *  - rate-limit per-IP 3 регистрации / час
 *  - honeypot (скрытое поле email_2): silent reject
 *  - валидация: email, password match, length >= 8
 *  - auto-login через filter sc_auth_pages_auto_login (default true)
 *  - wp_safe_redirect + wp_validate_redirect
 *
 * @since 1.3.0
 */
class Cashback_SC_Auth_Pages_Register {

    public const NONCE_ACTION       = 'sc_auth_pages_register';
    public const NONCE_FIELD        = '_sc_auth_nonce';
    public const RATE_LIMIT_PREFIX  = 'cb_sc_auth_register_rl_';
    public const RATE_LIMIT_MAX     = 3;
    public const RATE_LIMIT_WINDOW  = HOUR_IN_SECONDS;
    public const MIN_PASSWORD_LEN   = 8;

    /**
     * Точка входа из template_redirect (prio 5).
     */
    public static function maybe_handle(): void {
        if (!self::is_register_page_post()) {
            return;
        }

        // Gate: уважаем стандартную WP-опцию users_can_register.
        // НЕ пишем notice здесь — wc_add_notice инициализирует WC session
        // (запись в wp_woocommerce_sessions). Без silent-reject бот может
        // amplification-атаковать сервер через unauthenticated POST'ы. Юзер при
        // редиректе попадёт на GET /register/, где shortcode сам отрендерит
        // disabled-страницу с понятным сообщением.
        if (class_exists('Cashback_Registration_Gate') && !Cashback_Registration_Gate::is_allowed()) {
            self::redirect_back();
            return;
        }

        if (!self::verify_nonce()) {
            self::add_error_notice(__('Сессия истекла. Заполните форму заново.', 'cashback-plugin'));
            self::redirect_back();
            return;
        }

        if (self::is_honeypot_filled()) {
            // Silent reject — не даём боту обратной связи.
            self::register_violation(self::resolve_client_ip());
            self::redirect_back();
            return;
        }

        $client_ip = self::resolve_client_ip();
        if (!self::rate_limit_check($client_ip)) {
            self::add_error_notice(__('Слишком много регистраций с этого IP. Попробуйте через час.', 'cashback-plugin'));
            self::redirect_back();
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен в verify_nonce().
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash((string) $_POST['email'])) : '';

        $auto_password = self::is_auto_password_mode();

        if ($auto_password) {
            // WC сам сгенерирует пароль через wp_generate_password() и отправит
            // customer_new_account email со ссылкой на установку пароля.
            $password = '';
            $password_confirm = '';
        } else {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce проверен; пароль НЕ sanitize и НЕ unslash (WC ждёт raw).
            $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- то же.
            $password_confirm = isset($_POST['password_confirm']) ? (string) $_POST['password_confirm'] : '';
        }

        $error = self::validate_inputs($email, $password, $password_confirm, $auto_password);
        if ($error !== null) {
            self::add_error_notice($error);
            self::register_violation($client_ip);
            self::redirect_back();
            return;
        }

        $user_id = self::create_user($email, $password);
        if (is_wp_error($user_id)) {
            self::register_violation($client_ip);
            self::add_error_notice(self::flatten_wp_error($user_id));
            self::redirect_back();
            return;
        }

        if (!is_int($user_id) || $user_id <= 0) {
            self::register_violation($client_ip);
            self::add_error_notice(__('Не удалось создать аккаунт. Попробуйте позже.', 'cashback-plugin'));
            self::redirect_back();
            return;
        }

        self::clear_rate_limit($client_ip);

        // Auto-login возможен только если юзер сам задал пароль. При сгенерированном
        // пароле логин невозможен — юзер должен пройти по ссылке из welcome email.
        if (!$auto_password && (bool) apply_filters('sc_auth_pages_auto_login', true, $user_id)) {
            self::auto_login($user_id);
        }

        if ($auto_password) {
            self::add_success_notice(__('Регистрация успешна! Мы отправили вам письмо со ссылкой на установку пароля.', 'cashback-plugin'));
        }

        $redirect = self::resolve_register_redirect_target($user_id);
        Cashback_SC_Auth_Pages_Redirect_Helper::send($redirect);
    }

    /**
     * Включён ли режим автоматической генерации пароля (WC настройка
     * «При создании аккаунта автоматически генерировать пароль»).
     *
     * Можно override через filter sc_auth_pages_auto_generate_password.
     */
    public static function is_auto_password_mode(): bool {
        $wc_setting = (string) get_option('woocommerce_registration_generate_password', 'no');
        $enabled    = $wc_setting === 'yes';
        return (bool) apply_filters('sc_auth_pages_auto_generate_password', $enabled);
    }

    /**
     * Создание пользователя через WooCommerce. Триггерит цепочку cash-back хуков.
     *
     * @return int|WP_Error
     */
    private static function create_user( string $email, string $password ) {
        if (function_exists('wc_create_new_customer')) {
            // wc_create_new_customer($email, $username, $password)
            // Передача '' username — WC сам сгенерирует.
            return wc_create_new_customer($email, '', $password);
        }

        // Fallback: WooCommerce не загружен — используем wp_create_user, но
        // без legal/fraud consent (этот path не должен сработать в проде).
        if (function_exists('wp_create_user')) {
            return wp_create_user($email, $password, $email);
        }

        return new WP_Error('wp_create_user_unavailable', __('Сервис временно недоступен.', 'cashback-plugin'));
    }

    private static function auto_login( int $user_id ): void {
        // F-P2-006: очищаем любые предыдущие auth-cookie ДО установки новой.
        // Защита от session-fixation: WP-стандартный wp_signon делает то же.
        if (function_exists('wp_clear_auth_cookie')) {
            wp_clear_auth_cookie();
        }
        if (function_exists('wp_set_current_user')) {
            wp_set_current_user($user_id);
        }
        if (function_exists('wp_set_auth_cookie')) {
            wp_set_auth_cookie($user_id, true);
        }
        // Сигнал «юзер залогинился» (некоторые плагины слушают login для metrics).
        if (function_exists('do_action')) {
            $user = function_exists('get_userdata') ? get_userdata($user_id) : null;
            do_action('wp_login', $user instanceof WP_User ? $user->user_login : '', $user);
        }
    }

    /**
     * @return string|null Сообщение об ошибке или null если всё ок.
     */
    private static function validate_inputs( string $email, string $password, string $password_confirm, bool $auto_password ): ?string {
        if ($email === '' || (function_exists('is_email') && !is_email($email))) {
            return __('Введите корректный email.', 'cashback-plugin');
        }

        // В режиме автогенерации поля password в форме нет — валидация не нужна.
        if ($auto_password) {
            return null;
        }

        if ($password === '' || strlen($password) < self::MIN_PASSWORD_LEN) {
            /* translators: %d: minimum password length. */
            return sprintf(__('Пароль должен быть минимум %d символов.', 'cashback-plugin'), self::MIN_PASSWORD_LEN);
        }

        if ($password !== $password_confirm) {
            return __('Пароли не совпадают.', 'cashback-plugin');
        }

        return null;
    }

    private static function is_register_page_post(): bool {
        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD'])) : '';
        if ($method !== 'POST') {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- маркер до nonce-проверки.
        if (!isset($_POST['sc_auth_action']) || $_POST['sc_auth_action'] !== 'register') {
            return false;
        }
        if (defined('CASHBACK_SC_AUTH_PAGES_TEST_BYPASS_PAGE_CHECK')) {
            return true;
        }
        if (!function_exists('is_page')) {
            return false;
        }
        $register_id = (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_REGISTER_PAGE_ID, 0);
        return $register_id > 0 && is_page($register_id);
    }

    private static function verify_nonce(): bool {
        if (!function_exists('wp_verify_nonce')) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- мы и есть nonce-проверка.
        $raw = isset($_POST[ self::NONCE_FIELD ]) ? sanitize_text_field(wp_unslash((string) $_POST[ self::NONCE_FIELD ])) : '';
        return $raw !== '' && (bool) wp_verify_nonce($raw, self::NONCE_ACTION);
    }

    private static function is_honeypot_filled(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- маркер honeypot.
        return !empty($_POST['email_2']);
    }

    private static function rate_limit_check( string $client_ip ): bool {
        if ($client_ip === '') {
            return true;
        }
        $key   = self::RATE_LIMIT_PREFIX . md5($client_ip);
        $count = (int) get_transient($key);
        return $count < self::RATE_LIMIT_MAX;
    }

    private static function register_violation( string $client_ip ): void {
        if ($client_ip === '') {
            return;
        }
        $key     = self::RATE_LIMIT_PREFIX . md5($client_ip);
        $current = (int) get_transient($key);
        set_transient($key, $current + 1, self::RATE_LIMIT_WINDOW);
    }

    private static function clear_rate_limit( string $client_ip ): void {
        if ($client_ip === '') {
            return;
        }
        $key = self::RATE_LIMIT_PREFIX . md5($client_ip);
        delete_transient($key);
    }

    private static function resolve_client_ip(): string {
        // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- request-scoped IP, нужен для rate-limit.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    private static function flatten_wp_error( WP_Error $error ): string {
        $msg = $error->get_error_message();
        // WC возвращает HTML внутри сообщений (<a href="...">), и wc_add_notice
        // их корректно рендерит. Не модифицируем.
        return $msg !== '' ? (string) $msg : __('Ошибка регистрации.', 'cashback-plugin');
    }

    private static function resolve_register_redirect_target( int $user_id ): string {
        $default = Cashback_SC_Auth_Pages_Redirect_Helper::get_my_account_url();
        $target  = (string) apply_filters('sc_auth_pages_register_redirect', $default, $user_id);

        if (function_exists('wp_validate_redirect')) {
            return (string) wp_validate_redirect($target, $default);
        }
        return $target !== '' ? $target : $default;
    }

    private static function redirect_back(): void {
        Cashback_SC_Auth_Pages_Redirect_Helper::send(self::get_register_url());
    }

    public static function get_register_url(): string {
        $url = '';
        $id  = (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_REGISTER_PAGE_ID, 0);
        if ($id > 0 && function_exists('get_permalink')) {
            $url = (string) get_permalink($id);
        }
        if ($url === '') {
            $url = function_exists('home_url') ? (string) home_url('/register/') : '/register/';
        }
        return (string) apply_filters('sc_auth_pages_register_url', $url, $id);
    }

    private static function add_error_notice( string $message ): void {
        if (function_exists('wc_add_notice')) {
            wc_add_notice($message, 'error');
            return;
        }
        $bucket = (array) get_transient('cb_sc_auth_pages_notices') ?: array();
        $bucket[] = $message;
        set_transient('cb_sc_auth_pages_notices', $bucket, MINUTE_IN_SECONDS);
    }

    private static function add_success_notice( string $message ): void {
        if (function_exists('wc_add_notice')) {
            wc_add_notice($message, 'success');
        }
    }
}
