<?php

/**
 * Регистрация Cashback_Advcake_Coupons_Adapter в реестре code-adapter'ов промокодов.
 *
 * Подписывается на extension-point hook `cashback_register_coupons_code_adapters`,
 * который Cashback_Promocodes_Bootstrap::get_registry() вызывает один раз при
 * первой инициализации реестра. Адаптер регистрируется для slug='advcake' и
 * получает приоритет над generic JSON-фабрикой (см. Cashback_Coupons_Adapter_Registry).
 *
 * @package CashbackPlugin
 * @since   12.3.0
 */

declare(strict_types=1);

if (!defined('ABSPATH') && !defined('PHPUNIT_RUNNING')) {
    exit;
}

add_action('cashback_register_coupons_code_adapters', static function ( $registry ) {
    if (!$registry instanceof Cashback_Coupons_Adapter_Registry) {
        return;
    }
    if (!class_exists('Cashback_Advcake_Coupons_Adapter')
        || !class_exists('Cashback_Advcake_Adapter')
        || !class_exists('Cashback_API_Client')
    ) {
        return;
    }
    $registry->register_code_adapter(
        'advcake',
        new Cashback_Advcake_Coupons_Adapter(
            new Cashback_Advcake_Adapter(),
            Cashback_API_Client::get_instance()
        )
    );
}, 10, 1);
