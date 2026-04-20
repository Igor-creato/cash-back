<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Конфигурация мобильного приложения.
 *
 * Возвращает:
 *   - version-требования (minimum_app_version для force-update, latest_app_version)
 *   - feature flags
 *   - URL-ы политик (terms, privacy, support)
 *   - список активных методов выплат
 *   - признаки доступности модулей (support, push, affiliate)
 *
 * Все значения — через WP options, админ может менять без деплоя.
 *
 * @since 1.1.0
 */
class Cashback_Mobile_App_Config {

    /**
     * @return array<string,mixed>
     */
    public static function get(): array {
        $min_ios           = self::opt('cashback_mobile_min_ios_version', '1.0.0');
        $min_android       = self::opt('cashback_mobile_min_android_version', '1.0.0');
        $latest_ios        = self::opt('cashback_mobile_latest_ios_version', $min_ios);
        $latest_android    = self::opt('cashback_mobile_latest_android_version', $min_android);
        $store_url_ios     = (string) self::opt('cashback_mobile_store_url_ios', '');
        $store_url_android = (string) self::opt('cashback_mobile_store_url_android', '');

        $payout_methods = array();
        if (class_exists('Cashback_Mobile_Payouts_Service')) {
            $payout_methods = Cashback_Mobile_Payouts_Service::list_methods();
        }

        return array(
            'app'                => array(
                'min_version'    => array(
                    'ios'     => (string) $min_ios,
                    'android' => (string) $min_android,
                ),
                'latest_version' => array(
                    'ios'     => (string) $latest_ios,
                    'android' => (string) $latest_android,
                ),
                'store_urls'     => array(
                    'ios'     => (string) $store_url_ios,
                    'android' => (string) $store_url_android,
                ),
            ),
            'policies'           => array(
                'terms'         => (string) self::opt('cashback_mobile_terms_url', home_url('/terms')),
                'privacy'       => (string) self::opt('cashback_mobile_privacy_url', home_url('/privacy')),
                'support_email' => (string) self::opt('cashback_mobile_support_email', get_option('admin_email', '')),
                'offer'         => (string) self::opt('cashback_mobile_offer_url', home_url('/offer')),
            ),
            'features'           => array(
                'support_enabled'   => self::is_support_enabled(),
                'affiliate_enabled' => true,
                'push_enabled'      => true,
                'claims_enabled'    => class_exists('Cashback_Claims_Manager'),
                'social_providers'  => self::enabled_social_providers(),
            ),
            'payout_methods'     => $payout_methods,
            'notification_types' => self::notification_types(),
            'min_payout_default' => (float) self::opt('cashback_default_min_payout', 100.0),
            'max_withdrawal'     => (float) self::opt('cashback_max_withdrawal_amount', 50000.0),
            'server_time_utc'    => gmdate('c'),
        );
    }

    private static function opt( string $key, $fallback = '' ) {
        return get_option($key, $fallback);
    }

    private static function is_support_enabled(): bool {
        if (class_exists('Cashback_Support_DB')) {
            return (bool) Cashback_Support_DB::is_module_enabled();
        }
        return false;
    }

    /**
     * @return array<int,string>
     */
    private static function enabled_social_providers(): array {
        if (!class_exists('Cashback_Social_Mobile_Exchange')) {
            return array();
        }
        $active = array();
        foreach (Cashback_Social_Mobile_Exchange::SUPPORTED_PROVIDERS as $provider_id) {
            if (class_exists('Cashback_Social_Auth_Providers')) {
                $instance = Cashback_Social_Auth_Providers::instance()->get($provider_id);
                if (null !== $instance) {
                    $active[] = $provider_id;
                }
            }
        }
        return $active;
    }

    /**
     * @return array<string,string>
     */
    private static function notification_types(): array {
        if (class_exists('Cashback_Notifications_DB') && method_exists('Cashback_Notifications_DB', 'get_user_notification_types')) {
            return (array) Cashback_Notifications_DB::get_user_notification_types();
        }
        return array();
    }
}
