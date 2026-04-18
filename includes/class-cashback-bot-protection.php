<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Оркестратор защиты от ботов и брутфорса.
 *
 * Хук admin_init (приоритет 1) — срабатывает ДО всех wp_ajax_* обработчиков.
 *
 * Порядок проверок:
 *   1. Это AJAX? Действие в реестре плагина? → нет — пропускаем
 *   2. IP заблокирован (grey score ≥ 80)? → 429
 *   3. Bot User-Agent? → grey score + reject
 *   4. Honeypot заполнен? → grey score + reject
 *   5. Timing < 2с? → grey score (не блокирует, но добавляет баллы)
 *   6. Rate limit → check tier
 *   7. CAPTCHA gate (для серых IP на critical/write)
 *
 * @since 2.1.0
 */
class Cashback_Bot_Protection {

    /** Минимальное время от загрузки формы до отправки (секунды). */
    private const MIN_SUBMIT_TIME = 2;

    /** Тиры, требующие CAPTCHA для серых IP. */
    private const CAPTCHA_TIERS = array( 'critical', 'write' );

    /**
     * Инициализация бот-защиты.
     */
    public static function init(): void {
        if (!(bool) get_option('cashback_bot_protection_enabled', true)) {
            return;
        }

        // Guard AJAX-запросов (приоритет 1 — до обработчиков)
        add_action('admin_init', array( __CLASS__, 'guard_ajax_requests' ), 1);

        // Фронтенд скрипты и стили
        add_action('wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ));
    }

    /**
     * Центральный guard для всех AJAX-запросов плагина.
     */
    public static function guard_ajax_requests(): void {
        if (!wp_doing_ajax()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing-only read in AJAX guard; nonce verified by individual action handlers.
        $action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';

        // Только действия нашего плагина
        if (!Cashback_Rate_Limiter::is_plugin_action($action)) {
            return;
        }

        $ip      = self::get_ip();
        $user_id = get_current_user_id();
        $tier    = Cashback_Rate_Limiter::get_tier($action);

        // 1. IP заблокирован (score ≥ 80)
        if (Cashback_Rate_Limiter::is_blocked_ip($ip)) {
            wp_send_json_error(
                array(
                    'code'    => 'blocked',
                    'message' => 'Слишком много подозрительных запросов. Попробуйте позже.',
                ),
                429
            );
        }

        // 2. Bot User-Agent
        if (self::is_bot_user_agent()) {
            Cashback_Rate_Limiter::record_violation($ip, 'bot_ua');
            wp_send_json_error(
                array(
                    'code'    => 'blocked',
                    'message' => 'Запрос отклонён.',
                ),
                403
            );
        }

        // 3. Honeypot (поле cb_website_url должно быть пустым)
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Honeypot field checked before nonce verification by caller; this is an anti-bot pre-check on a public endpoint protected by rate-limit and CAPTCHA.
        if (isset($_POST['cb_website_url']) && $_POST['cb_website_url'] !== '') {
            Cashback_Rate_Limiter::record_violation($ip, 'honeypot');
            // Тихий reject — бот не должен знать что его поймали
            wp_send_json_error(
                array(
                    'code'    => 'error',
                    'message' => 'Произошла ошибка. Попробуйте ещё раз.',
                )
            );
        }

        // 4. Timing check (если поле передано)
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Anti-bot timing field pre-checked before form nonce validation by caller; public endpoint protected by rate-limit and CAPTCHA.
        if (isset($_POST['cb_form_ts'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Anti-bot timing field read as int before form nonce validation by caller; public endpoint protected by rate-limit and CAPTCHA.
            $form_ts = (int) $_POST['cb_form_ts'];
            $now_ms  = (int) ( microtime(true) * 1000 );
            $delta_s = ( $now_ms - $form_ts ) / 1000;

            if ($form_ts > 0 && $delta_s < self::MIN_SUBMIT_TIME) {
                Cashback_Rate_Limiter::record_violation($ip, 'timing');
                // Не блокируем, только добавляем баллы — может стать серым
            }
        }

        // 5. Rate limit
        $rate_result = Cashback_Rate_Limiter::check($action, $user_id, $ip);

        if (!$rate_result['allowed']) {
            wp_send_json_error(
                array(
                    'code'        => 'rate_limited',
                    'message'     => 'Слишком много запросов. Попробуйте через минуту.',
                    'retry_after' => $rate_result['retry_after'],
                ),
                429
            );
        }

        // 6. CAPTCHA gate для серых IP на critical/write
        if ($tier !== null
            && in_array($tier, self::CAPTCHA_TIERS, true)
            && Cashback_Captcha::should_require($ip)
        ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- CAPTCHA token read before form nonce validation by caller; token itself is independently verified via Cashback_Captcha::verify_token() on a public endpoint protected by rate-limit.
            $captcha_token = isset($_POST['cb_captcha_token'])
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- CAPTCHA token value independently verified via Cashback_Captcha::verify_token() before the form nonce is checked by caller.
                ? sanitize_text_field(wp_unslash($_POST['cb_captcha_token']))
                : '';

            if ($captcha_token === '') {
                // Токена нет — запрашиваем CAPTCHA
                wp_send_json_error(
                    array(
                        'code'       => 'captcha_required',
                        'message'    => 'Пожалуйста, пройдите проверку.',
                        'client_key' => Cashback_Captcha::get_client_key(),
                    )
                );
            }

            if (!Cashback_Captcha::verify_token($captcha_token, $ip)) {
                wp_send_json_error(
                    array(
                        'code'    => 'captcha_failed',
                        'message' => 'Проверка не пройдена. Попробуйте ещё раз.',
                    )
                );
            }
        }

        // Все проверки пройдены — запрос передаётся wp_ajax_* обработчику
    }

    /**
     * Подключение frontend-скриптов и стилей.
     */
    public static function enqueue_frontend_assets(): void {
        if (!is_user_logged_in()) {
            return;
        }

        $plugin_url = plugin_dir_url(__DIR__);
        $version    = '2.1.0';

        wp_enqueue_style(
            'cashback-bot-protection',
            $plugin_url . 'assets/css/cashback-bot-protection.css',
            array(),
            $version
        );

        wp_enqueue_script(
            'cashback-bot-protection',
            $plugin_url . 'assets/js/cashback-bot-protection.js',
            array( 'jquery' ),
            $version,
            true
        );

        $ip = self::get_ip();

        wp_localize_script('cashback-bot-protection', 'cbBotProtection', array(
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'captchaRequired'  => Cashback_Captcha::should_require($ip),
            'captchaClientKey' => Cashback_Captcha::get_client_key(),
            'captchaJsUrl'     => 'https://smartcaptcha.yandexcloud.net/captcha.js',
        ));
    }

    /**
     * Получить IP через Cashback_Encryption если доступен.
     *
     * @return string
     */
    private static function get_ip(): string {
        if (class_exists('Cashback_Encryption') && method_exists('Cashback_Encryption', 'get_client_ip')) {
            return Cashback_Encryption::get_client_ip();
        }

        // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- REMOTE_ADDR set by webserver from TCP connection, not client-controlled; per-request only.
        return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    }

    /**
     * Проверка User-Agent на признаки бота.
     *
     * Извлечено из WC_Affiliate_URL_Params::is_bot_user_agent() для переиспользования.
     *
     * @return bool true если User-Agent похож на бота.
     */
    private static function is_bot_user_agent(): bool {
        $user_agent = isset($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
            : '';

        if (trim($user_agent) === '') {
            return true;
        }

        if (strlen($user_agent) < 20) {
            return true;
        }

        $bot_signatures = array(
            'curl/',
            'wget/',
            'python-requests',
            'python-urllib',
            'python/',
            'httpie/',
            'java/',
            'apache-httpclient',
            'go-http-client',
            'node-fetch',
            'axios/',
            'undici/',
            'scrapy',
            'mechanize',
            'libwww-perl',
            'lwp-trivial',
            'php/',
            'guzzlehttp',
            'okhttp',
            'headlesschrome',
            'phantomjs',
            'selenium',
            'puppeteer',
            'playwright',
        );

        $ua_lower = strtolower($user_agent);

        foreach ($bot_signatures as $sig) {
            if (str_contains($ua_lower, $sig)) {
                return true;
            }
        }

        return false;
    }
}
