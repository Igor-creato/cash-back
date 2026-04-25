<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Faq_Account_Link
 *
 * Добавляет пункт «Помощь» в меню WC My Account, ведущий на public-страницу
 * /faq/. Реализуется через псевдо-endpoint в фильтре
 * `woocommerce_account_menu_items` + override URL через
 * `woocommerce_get_endpoint_url`.
 *
 * Не использует add_rewrite_endpoint (FAQ доступен и гостям, отдельная
 * под-страница в кабинете для него не нужна — только пункт в меню как
 * быстрый путь).
 *
 * @since 1.7.0
 */
class Cashback_Faq_Account_Link {

    public const MENU_ENDPOINT = 'cashback-faq-link';

    public static function init(): void {
        if (!function_exists('add_filter')) {
            return;
        }
        add_filter('woocommerce_account_menu_items', array( __CLASS__, 'add_menu_item' ), 80);
        add_filter('woocommerce_get_endpoint_url', array( __CLASS__, 'override_endpoint_url' ), 10, 4);
    }

    /**
     * Добавление пункта «Помощь» в меню My Account.
     *
     * @param array<string, string> $items
     * @return array<string, string>
     */
    public static function add_menu_item( $items ) {
        if (!is_array($items)) {
            return $items;
        }

        if (!class_exists('Cashback_Faq_Page_Installer')) {
            return $items;
        }

        $url = Cashback_Faq_Page_Installer::get_url();
        if ($url === '') {
            return $items;
        }

        // Logout всегда последний — вставим перед ним.
        $logout_label = isset($items['customer-logout']) ? $items['customer-logout'] : null;
        if ($logout_label !== null) {
            unset($items['customer-logout']);
        }

        $items[ self::MENU_ENDPOINT ] = __('Помощь', 'cashback-plugin');

        if ($logout_label !== null) {
            $items['customer-logout'] = $logout_label;
        }

        return $items;
    }

    /**
     * Подмена URL для нашего псевдо-endpoint'а на FAQ-страницу.
     *
     * @param string $url
     * @param string $endpoint
     * @param string $value
     * @param string $permalink
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by woocommerce_get_endpoint_url filter signature.
    public static function override_endpoint_url( $url, $endpoint, $value = '', $permalink = '' ) {
        if ($endpoint !== self::MENU_ENDPOINT) {
            return $url;
        }
        if (!class_exists('Cashback_Faq_Page_Installer')) {
            return $url;
        }
        $faq_url = Cashback_Faq_Page_Installer::get_url();
        return $faq_url !== '' ? $faq_url : $url;
    }
}
