<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Link_Checker_Shortcode {

    private const SHORTCODE = 'cashback_link_checker';
    private const HANDLE    = 'cashback-link-checker';

    public static function init(): void {
        $callback = array( __CLASS__, 'render' );
        add_shortcode(self::SHORTCODE, $callback);
    }

    /**
     * @param array<string,mixed> $atts
     */
    public static function render( array $atts = array() ): string {
        $atts = shortcode_atts(array(
            'placeholder' => 'Вставьте ссылку на товар или магазин',
        ), $atts, self::SHORTCODE);

        self::enqueue_assets();

        $placeholder = (string) $atts['placeholder'];

        ob_start();
        ?>
        <form class="cashback-link-checker" data-cashback-link-checker-form>
            <label class="cashback-link-checker__field">
                <span class="cashback-link-checker__label"><?php echo esc_html__('Ссылка для проверки', 'cashback-plugin'); ?></span>
                <input
                    class="cashback-link-checker__input"
                    type="url"
                    name="direct_url"
                    placeholder="<?php echo esc_attr($placeholder); ?>"
                    autocomplete="url"
                    required
                />
            </label>
            <button class="cashback-link-checker__button cashback-btn-primary" type="submit">
                <?php echo esc_html__('Проверить кэшбэк', 'cashback-plugin'); ?>
            </button>
            <div class="cashback-link-checker__result" data-cashback-link-checker-result aria-live="polite"></div>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    private static function enqueue_assets(): void {
        $script_deps = array();

        if (class_exists('Cashback_Assets') && method_exists('Cashback_Assets', 'enqueue_safe_html')) {
            Cashback_Assets::enqueue_safe_html();
            $script_deps[] = Cashback_Assets::safe_html_handle();
        }

        if (!is_user_logged_in()) {
            wp_enqueue_script(
                'wc-affiliate-url-params',
                cashback_asset_url('assets/js/affiliate-guest-warning.js'),
                array( 'jquery' ),
                '4.2.1',
                true
            );

            wp_localize_script('wc-affiliate-url-params', 'wcAffiliateParams', array(
                'isLoggedIn'     => false,
                'warningMessage' => self::guest_warning_message(),
                'loginUrl'       => self::guest_login_url(),
            ));

            $script_deps[] = 'wc-affiliate-url-params';
        }

        wp_enqueue_style(
            'cashback-account-base',
            cashback_asset_url('assets/css/cashback-account-base.css'),
            array(),
            '1.0.0'
        );

        wp_enqueue_style(
            self::HANDLE,
            cashback_asset_url('assets/css/cashback-link-checker.css'),
            array( 'cashback-account-base' ),
            '1.0.0'
        );

        wp_enqueue_script(
            self::HANDLE,
            cashback_asset_url('assets/js/cashback-link-checker.js'),
            $script_deps,
            '1.0.0',
            true
        );

        wp_localize_script(self::HANDLE, 'CashbackLinkChecker', array(
            'restBase'            => rest_url('cashback/v1/link-checker'),
            'nonce'               => wp_create_nonce('wp_rest'),
            'isLoggedIn'          => is_user_logged_in(),
            'loginUrl'            => self::guest_login_url(),
            'guestWarningMessage' => self::guest_warning_message(),
            'i18n'                => array(
                'checking' => __('Проверяем...', 'cashback-plugin'),
                'error'    => __('Не удалось проверить ссылку.', 'cashback-plugin'),
            ),
        ));
    }

    private static function guest_warning_message(): string {
        return __(
            'Вы не авторизованы, при переходе покупка не будет учтена сервисом. Продолжить?',
            'cashback-plugin'
        );
    }

    private static function guest_login_url(): string {
        $url = function_exists('home_url') ? home_url('/') : '/';
        if (function_exists('wc_get_page_id') && function_exists('get_permalink')) {
            $account_page_id = (int) wc_get_page_id('myaccount');
            $permalink       = $account_page_id > 0 ? get_permalink($account_page_id) : '';
            if (is_string($permalink) && $permalink !== '') {
                $url = $permalink;
            }
        }

        return add_query_arg(array( 'action' => 'register' ), $url);
    }
}
