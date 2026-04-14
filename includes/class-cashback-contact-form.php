<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Контактная форма обратной связи.
 *
 * Шорткод: [cashback_contact_form]
 * AJAX: cashback_contact_submit (wp_ajax + wp_ajax_nopriv)
 *
 * Защита:
 *   - Honeypot (через cashback-bot-protection.js, data-cb-protected)
 *   - Rate limiting: 3 отправки / час по IP
 *   - Яндекс SmartCaptcha: всегда на публичной форме (если настроена)
 *   - Nonce (для залогиненных), referer check
 *   - Sanitization всех полей
 *
 * @since 2.1.0
 */
class Cashback_Contact_Form {

    /** @var self|null */
    private static ?self $instance = null;

    /** Rate limit: макс. отправок за окно. */
    private const RATE_LIMIT = 3;

    /** Rate limit: окно в секундах (1 час). */
    private const RATE_WINDOW = 3600;

    /** Максимальные длины полей. */
    private const MAX_NAME    = 100;
    private const MAX_EMAIL   = 254;
    private const MAX_SUBJECT = 255;
    private const MAX_MESSAGE = 5000;

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('cashback_contact_form', array( $this, 'render_shortcode' ));

        add_action('wp_ajax_cashback_contact_submit', array( $this, 'handle_submit' ));
        add_action('wp_ajax_nopriv_cashback_contact_submit', array( $this, 'handle_submit' ));

        add_action('wp_enqueue_scripts', array( $this, 'enqueue_assets' ));
    }

    /**
     * Подключение CSS/JS на страницах с шорткодом.
     */
    public function enqueue_assets(): void {
        // Регистрируем, но не подключаем — подключим в render_shortcode
        $plugin_url = plugin_dir_url(__DIR__);
        $version    = '2.1.0';

        wp_register_style(
            'cashback-contact-form',
            $plugin_url . 'assets/css/cashback-contact-form.css',
            array(),
            $version
        );

        wp_register_script(
            'cashback-contact-form',
            $plugin_url . 'assets/js/cashback-contact-form.js',
            array( 'jquery' ),
            $version,
            true
        );
    }

    /**
     * Рендер шорткода [cashback_contact_form].
     *
     * @param array|string $atts Атрибуты шорткода.
     * @return string HTML формы.
     */
    public function render_shortcode( $atts = array() ): string {
        // Подключаем стили и скрипты
        wp_enqueue_style('cashback-contact-form');
        wp_enqueue_script('cashback-contact-form');

        // Подключаем bot-protection если ещё не подключён
        if (wp_script_is('cashback-bot-protection', 'registered')) {
            wp_enqueue_style('cashback-bot-protection');
            wp_enqueue_script('cashback-bot-protection');
        }

        $nonce = wp_create_nonce('cashback_contact_form_nonce');

        // CAPTCHA: на публичной форме показываем всегда (если настроена)
        $show_captcha = class_exists('Cashback_Captcha') && Cashback_Captcha::is_configured();
        $captcha_key  = $show_captcha ? Cashback_Captcha::get_client_key() : '';

        wp_localize_script('cashback-contact-form', 'cbContactForm', array(
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'nonce'            => $nonce,
            'captchaRequired'  => $show_captcha,
            'captchaClientKey' => $captcha_key,
            'captchaJsUrl'     => 'https://smartcaptcha.yandexcloud.net/captcha.js',
        ));

        ob_start();
        ?>
        <div class="cb-contact-form-wrap">
            <form id="cb-contact-form" method="post" data-cb-protected="1" novalidate>
                <div class="cb-contact-field">
                    <label for="cb-contact-name"><?php esc_html_e('Ваше имя', 'cashback-plugin'); ?> <span class="required">*</span></label>
                    <input type="text" id="cb-contact-name" name="contact_name" required
                            maxlength="<?php echo self::MAX_NAME; ?>"
                            autocomplete="name"
                            placeholder="<?php esc_attr_e('Введите ваше имя', 'cashback-plugin'); ?>">
                </div>

                <div class="cb-contact-field">
                    <label for="cb-contact-email"><?php esc_html_e('Ваш e-mail', 'cashback-plugin'); ?> <span class="required">*</span></label>
                    <input type="email" id="cb-contact-email" name="contact_email" required
                            maxlength="<?php echo self::MAX_EMAIL; ?>"
                            autocomplete="email"
                            placeholder="<?php esc_attr_e('example@mail.ru', 'cashback-plugin'); ?>">
                </div>

                <div class="cb-contact-field">
                    <label for="cb-contact-subject"><?php esc_html_e('Тема', 'cashback-plugin'); ?> <span class="required">*</span></label>
                    <input type="text" id="cb-contact-subject" name="contact_subject" required
                            maxlength="<?php echo self::MAX_SUBJECT; ?>"
                            placeholder="<?php esc_attr_e('Тема сообщения', 'cashback-plugin'); ?>">
                </div>

                <div class="cb-contact-field">
                    <label for="cb-contact-message"><?php esc_html_e('Ваше сообщение', 'cashback-plugin'); ?> <span class="required">*</span></label>
                    <textarea id="cb-contact-message" name="contact_message" required
                                maxlength="<?php echo self::MAX_MESSAGE; ?>" rows="6"
                                placeholder="<?php esc_attr_e('Введите ваше сообщение...', 'cashback-plugin'); ?>"></textarea>
                </div>

                <?php if ($show_captcha) : ?>
                <div class="cb-contact-field cb-contact-captcha">
                    <div id="cb-contact-captcha-widget"></div>
                    <input type="hidden" name="cb_captcha_token" id="cb-contact-captcha-token" value="">
                </div>
                <?php endif; ?>

                <div class="cb-contact-field cb-contact-submit-row">
                    <button type="submit" id="cb-contact-submit" class="button btn">
                        <?php esc_html_e('Отправить', 'cashback-plugin'); ?>
                    </button>
                </div>

                <div id="cb-contact-messages" class="cb-contact-messages" style="display:none;"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Обработка AJAX-отправки формы.
     */
    public function handle_submit(): void {
        // Rate limit по IP
        $ip         = $this->get_ip();
        $rate_key   = 'cb_contact_rate_' . substr(md5($ip), 0, 12);
        $rate_count = (int) get_transient($rate_key);

        if ($rate_count >= self::RATE_LIMIT) {
            wp_send_json_error(array(
                'code'    => 'rate_limited',
                'message' => 'Слишком много сообщений. Попробуйте через час.',
            ), 429);
        }

        // Bot checks
        // Honeypot
        if (isset($_POST['cb_website_url']) && $_POST['cb_website_url'] !== '') {
            if (class_exists('Cashback_Rate_Limiter')) {
                Cashback_Rate_Limiter::record_violation($ip, 'honeypot');
            }
            wp_send_json_error(array(
                'code'    => 'error',
                'message' => 'Произошла ошибка. Попробуйте ещё раз.',
            ));
        }

        // Timing
        if (isset($_POST['cb_form_ts'])) {
            $form_ts = (int) $_POST['cb_form_ts'];
            $now_ms  = (int) ( microtime(true) * 1000 );
            $delta_s = ( $now_ms - $form_ts ) / 1000;

            if ($form_ts > 0 && $delta_s < 2) {
                if (class_exists('Cashback_Rate_Limiter')) {
                    Cashback_Rate_Limiter::record_violation($ip, 'timing');
                }
                wp_send_json_error(array(
                    'code'    => 'error',
                    'message' => 'Произошла ошибка. Попробуйте ещё раз.',
                ));
            }
        }

        // Bot UA
        if ($this->is_bot_user_agent()) {
            if (class_exists('Cashback_Rate_Limiter')) {
                Cashback_Rate_Limiter::record_violation($ip, 'bot_ua');
            }
            wp_send_json_error(array(
                'code'    => 'blocked',
                'message' => 'Запрос отклонён.',
            ), 403);
        }

        // CAPTCHA — на публичной форме обязательна (если настроена)
        if (class_exists('Cashback_Captcha') && Cashback_Captcha::is_configured()) {
            $captcha_token = isset($_POST['cb_captcha_token'])
                ? sanitize_text_field(wp_unslash($_POST['cb_captcha_token']))
                : '';

            if ($captcha_token === '' || !Cashback_Captcha::verify_token($captcha_token, $ip)) {
                wp_send_json_error(array(
                    'code'    => 'captcha_failed',
                    'message' => 'Проверка не пройдена. Пожалуйста, подтвердите, что вы не робот.',
                ));
            }
        }

        // Nonce (для залогиненных — проверяем, для гостей — мягко)
        $nonce_valid = check_ajax_referer('cashback_contact_form_nonce', 'nonce', false);
        if (is_user_logged_in() && !$nonce_valid) {
            wp_send_json_error(array(
                'code'    => 'error',
                'message' => 'Сессия истекла. Обновите страницу и попробуйте снова.',
            ));
        }

        // Sanitize fields
        $name    = sanitize_text_field(wp_unslash($_POST['contact_name'] ?? ''));
        $email   = sanitize_email(wp_unslash($_POST['contact_email'] ?? ''));
        $subject = sanitize_text_field(wp_unslash($_POST['contact_subject'] ?? ''));
        $message = sanitize_textarea_field(wp_unslash($_POST['contact_message'] ?? ''));

        // Validation
        $errors = array();

        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            $errors[] = 'Введите ваше имя (до ' . self::MAX_NAME . ' символов).';
        }

        if (!is_email($email)) {
            $errors[] = 'Введите корректный адрес электронной почты.';
        }

        if ($subject === '' || mb_strlen($subject) > self::MAX_SUBJECT) {
            $errors[] = 'Введите тему сообщения.';
        }

        if ($message === '' || mb_strlen($message) < 10) {
            $errors[] = 'Сообщение должно содержать не менее 10 символов.';
        }

        if (mb_strlen($message) > self::MAX_MESSAGE) {
            $errors[] = 'Сообщение слишком длинное (макс. ' . self::MAX_MESSAGE . ' символов).';
        }

        if (!empty($errors)) {
            wp_send_json_error(array(
                'code'    => 'validation',
                'message' => implode("\n", $errors),
            ));
        }

        // Отправка email
        $admin_email = get_option('admin_email');
        $site_name   = get_option('blogname');

        $email_subject = sprintf('[%s] %s', $site_name, $subject);

        $email_body = sprintf(
            "От: %s <%s>\nТема: %s\n\nСообщение:\n%s\n\n--\nОтправлено через форму обратной связи на сайте %s\nIP: %s",
            $name,
            $email,
            $subject,
            $message,
            $site_name,
            $ip
        );

        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            sprintf('Reply-To: %s <%s>', $name, $email),
        );

        $sent = wp_mail($admin_email, $email_subject, $email_body, $headers);

        if (!$sent) {
            wp_send_json_error(array(
                'code'    => 'mail_error',
                'message' => 'При отправке сообщения произошла ошибка. Попробуйте позже.',
            ));
        }

        // Увеличиваем счётчик rate limit
        set_transient($rate_key, $rate_count + 1, self::RATE_WINDOW);

        wp_send_json_success(array(
            'message' => 'Спасибо! Ваше сообщение успешно отправлено.',
        ));
    }

    /**
     * Получить IP-адрес.
     */
    private function get_ip(): string {
        if (class_exists('Cashback_Encryption') && method_exists('Cashback_Encryption', 'get_client_ip')) {
            return Cashback_Encryption::get_client_ip();
        }
        return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    }

    /**
     * Проверка User-Agent на бота.
     */
    private function is_bot_user_agent(): bool {
        $ua = isset($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
            : '';

        if (trim($ua) === '' || strlen($ua) < 20) {
            return true;
        }

        $sigs = array(
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

        $lower = strtolower($ua);
        foreach ($sigs as $s) {
            if (str_contains($lower, $s)) {
                return true;
            }
        }

        return false;
    }
}
