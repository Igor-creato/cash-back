<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renderer социальных кнопок.
 *
 * Формирует HTML списка кнопок для контекстов:
 *  - login        — форма входа WooCommerce / shortcode
 *  - register     — форма регистрации WooCommerce
 *  - checkout     — blocks/checkout (не залогинен)
 *  - wp_login     — wp-login.php
 *  - account_link — страница ЛК «Социальная авторизация» (привязка)
 *
 * Ничего не выводит напрямую: методы render_buttons() возвращают HTML.
 * Для хуков WC есть тонкие обёртки print_*_buttons().
 *
 * @since 1.1.0
 */
class Cashback_Social_Auth_Renderer {

    /**
     * Кэш прочитанного содержимого SVG-иконок по provider_id.
     *
     * @var array<string, string>
     */
    private static array $svg_cache = array();

    /**
     * Был ли стиль уже enqueued (чтобы не дублировать ручным enqueue на каждом render).
     */
    private static bool $style_registered = false;

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
     * Зарегистрировать стиль (не подгружает, только register).
     */
    public static function register_assets(): void {
        if (self::$style_registered) {
            return;
        }
        self::$style_registered = true;

        // Путь к CSS относительно корня плагина; plugins_url() превращает его в URL.
        $plugin_root_file = dirname(__DIR__, 2) . '/cashback-plugin.php';
        $css_url          = plugins_url('assets/social-auth/css/buttons.css', $plugin_root_file);

        wp_register_style(
            'cashback-social-buttons',
            $css_url,
            array(),
            '1.1.2'
        );
    }

    /**
     * Включить стиль на фронтенде (conditional).
     */
    public function maybe_enqueue_front(): void {
        if ((int) get_option('cashback_social_enabled', 0) !== 1) {
            return;
        }
        self::register_assets();
        wp_enqueue_style('cashback-social-buttons');
    }

    /**
     * Включить стиль на wp-login.php.
     */
    public function maybe_enqueue_login(): void {
        if ((int) get_option('cashback_social_enabled', 0) !== 1) {
            return;
        }
        self::register_assets();
        wp_enqueue_style('cashback-social-buttons');
    }

    /**
     * Вернуть HTML набора кнопок. Если нечего показывать — пустая строка.
     *
     * @param string $context login|register|checkout|wp_login|account_link
     */
    public function render_buttons( string $context ): string {
        $context = in_array($context, array( 'login', 'register', 'checkout', 'wp_login', 'account_link' ), true)
            ? $context
            : 'login';

        if ((int) get_option('cashback_social_enabled', 0) !== 1) {
            return '';
        }

        if (!class_exists('Cashback_Social_Auth_Providers')) {
            return '';
        }

        $providers = Cashback_Social_Auth_Providers::instance()->all();
        if (empty($providers)) {
            return '';
        }

        /** @var array<int, string> $items */
        $items = array();
        foreach ($providers as $provider_id => $provider) {
            if (!$provider->is_enabled()) {
                continue;
            }

            // Для вкладки ЛК «Привязать» — не показываем провайдеров, уже привязанных.
            if ($context === 'account_link' && is_user_logged_in()) {
                $existing = Cashback_Social_Auth_DB::get_links_for_user((int) get_current_user_id());
                $linked   = false;
                foreach ($existing as $row) {
                    if (isset($row['provider']) && (string) $row['provider'] === (string) $provider_id) {
                        $linked = true;
                        break;
                    }
                }
                if ($linked) {
                    continue;
                }
            }

            $items[] = $this->render_single_button((string) $provider_id, $context);
        }

        if (empty($items)) {
            return '';
        }

        // Включить стиль (на всякий случай — если enqueue ещё не случился).
        self::register_assets();
        if (function_exists('wp_style_is') && !wp_style_is('cashback-social-buttons', 'enqueued')) {
            wp_enqueue_style('cashback-social-buttons');
        }

        $label_or = esc_attr__('или', 'cashback-plugin');
        $show_or  = ( $context !== 'account_link' );

        $html  = '<div class="cashback-social-buttons cashback-social-buttons--' . esc_attr($context) . '"';
        $html .= $show_or ? ' data-label="' . $label_or . '"' : '';
        $html .= '>';
        $html .= implode('', $items);
        $html .= '</div>';

        return $html;
    }

    /**
     * Сформировать одну кнопку провайдера.
     */
    private function render_single_button( string $provider_id, string $context ): string {
        $options = get_option('cashback_social_provider_' . $provider_id, array());
        if (!is_array($options)) {
            $options = array();
        }

        $label = $this->resolve_label($provider_id, $options, $context);
        $icon  = $this->resolve_icon_html($provider_id, $options);

        $start_url = $this->build_start_url($provider_id, $context);

        $classes = 'cashback-social-btn cashback-social-btn--' . sanitize_html_class($provider_id);

        // Иконка — pre-escaped HTML (SVG или img от wp_get_attachment_image_url).
        $html = '<a href="' . esc_url($start_url) . '" class="' . esc_attr($classes) . '" rel="nofollow">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icon HTML is pre-sanitized (wp_kses SVG или esc_url img).
        $html .= $icon;
        $html .= '<span class="cashback-social-btn__label">' . esc_html($label) . '</span>';
        $html .= '</a>';

        return $html;
    }

    /**
     * Сформировать URL /social/{provider}/start с redirect_to + nonce.
     */
    private function build_start_url( string $provider_id, string $context ): string {
        $base = rest_url('cashback/v1/social/' . rawurlencode($provider_id) . '/start');

        $redirect = '';
        if ($context === 'account_link') {
            $redirect = function_exists('wc_get_account_endpoint_url')
                ? (string) wc_get_account_endpoint_url('cashback-social')
                : home_url('/my-account/cashback-social/');
        } else {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect hint from query.
            if (isset($_GET['redirect_to'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw санитизирует URL.
                $raw      = (string) wp_unslash($_GET['redirect_to']);
                $redirect = (string) esc_url_raw($raw);
            } elseif (!empty($_SERVER['HTTP_REFERER'])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw санитизирует URL-строку.
                $raw      = (string) wp_unslash($_SERVER['HTTP_REFERER']);
                $redirect = (string) esc_url_raw($raw);
            }

            if ($redirect !== '') {
                $redirect = (string) wp_validate_redirect($redirect, home_url('/'));
            }
            if ($redirect === '') {
                $redirect = home_url('/');
            }
        }

        $url = add_query_arg(
            array(
                'redirect_to'           => rawurlencode($redirect),
                'cashback_social_nonce' => wp_create_nonce('cashback_social_start'),
                'context'               => $context,
            ),
            $base
        );

        return $url;
    }

    /**
     * Подобрать текст label с учётом контекста.
     *
     * @param array<string, mixed> $options
     */
    private function resolve_label( string $provider_id, array $options, string $context ): string {
        $provider_label = $this->provider_human_label($provider_id);

        if ($context === 'account_link') {
            /* translators: %s: provider label, e.g. "Яндекс ID". */
            return sprintf(__('Привязать %s', 'cashback-plugin'), $provider_label);
        }

        $key = ( $context === 'register' ) ? 'label_register' : 'label_login';

        $custom = isset($options[ $key ]) ? trim((string) $options[ $key ]) : '';
        if ($custom !== '') {
            return $custom;
        }

        if ($context === 'register') {
            /* translators: %s: provider label. */
            return sprintf(__('Зарегистрироваться через %s', 'cashback-plugin'), $provider_label);
        }
        /* translators: %s: provider label. */
        return sprintf(__('Войти через %s', 'cashback-plugin'), $provider_label);
    }

    /**
     * Человеко-читаемое название провайдера (из реестра labels).
     */
    private function provider_human_label( string $provider_id ): string {
        if (class_exists('Cashback_Social_Auth_Providers')) {
            $labels = Cashback_Social_Auth_Providers::labels();
            if (isset($labels[ $provider_id ])) {
                return (string) $labels[ $provider_id ];
            }
        }
        return ucfirst($provider_id);
    }

    /**
     * Получить HTML иконки: img (если attachment) или inline SVG.
     *
     * @param array<string, mixed> $options
     */
    private function resolve_icon_html( string $provider_id, array $options ): string {
        $icon_id = isset($options['icon_id']) ? (int) $options['icon_id'] : 0;
        if ($icon_id > 0) {
            $url = wp_get_attachment_image_url($icon_id, 'thumbnail');
            if (is_string($url) && $url !== '') {
                return '<img src="' . esc_url($url) . '" alt="" width="20" height="20" loading="lazy" />';
            }
        }

        return $this->inline_svg($provider_id);
    }

    /**
     * Встроить SVG-иконку провайдера (с кэшем в static property).
     */
    private function inline_svg( string $provider_id ): string {
        if (isset(self::$svg_cache[ $provider_id ])) {
            return self::$svg_cache[ $provider_id ];
        }

        $file = $this->icon_path($provider_id);
        if ($file === '' || !file_exists($file)) {
            self::$svg_cache[ $provider_id ] = '';
            return '';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local plugin asset (SVG bundled with plugin); @-silence защищает от warnings при race-condition.
        $raw = @file_get_contents($file);
        if ($raw === false) {
            self::$svg_cache[ $provider_id ] = '';
            return '';
        }

        // Минимальная санитизация через wp_kses.
        $allowed = array(
            'svg'    => array(
                'xmlns'       => true,
                'width'       => true,
                'height'      => true,
                'viewbox'     => true,
                'aria-hidden' => true,
                'focusable'   => true,
                'fill'        => true,
                'role'        => true,
                'class'       => true,
            ),
            'circle' => array(
				'cx'   => true,
				'cy'   => true,
				'r'    => true,
				'fill' => true,
			),
            'rect'   => array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
				'ry'     => true,
				'fill'   => true,
			),
            'path'   => array(
				'd'            => true,
				'fill'         => true,
				'stroke'       => true,
				'stroke-width' => true,
			),
            'g'      => array(
				'fill'      => true,
				'transform' => true,
			),
        );
        $clean   = wp_kses($raw, $allowed);

        self::$svg_cache[ $provider_id ] = $clean;
        return $clean;
    }

    /**
     * Абсолютный путь до файла иконки по provider_id.
     */
    private function icon_path( string $provider_id ): string {
        $provider_id = sanitize_key($provider_id);
        if ($provider_id === '') {
            return '';
        }

        // __FILE__ = .../includes/social-auth/class-social-auth-renderer.php
        $plugin_root = dirname(__DIR__, 2);
        return $plugin_root . '/assets/social-auth/icons/' . $provider_id . '.svg';
    }

    // =========================================================================
    // Хук-обёртки (echo)
    // =========================================================================

    public function print_login_buttons(): void {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML composed from escaped primitives внутри render_buttons().
        echo $this->render_buttons('login');
    }

    public function print_register_buttons(): void {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML composed from escaped primitives внутри render_buttons().
        echo $this->render_buttons('register');
    }

    public function print_checkout_buttons(): void {
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML composed from escaped primitives внутри render_buttons().
        echo $this->render_buttons('checkout');
    }

    public function print_wp_login_buttons(): void {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML composed from escaped primitives внутри render_buttons().
        echo $this->render_buttons('wp_login');
    }

    public function print_account_link_buttons(): void {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML composed from escaped primitives внутри render_buttons().
        echo $this->render_buttons('account_link');
    }
}
