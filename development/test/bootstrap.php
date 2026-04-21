<?php

/**
 * Bootstrap для тестов плагина Cashback.
 *
 * Мокирует WordPress функции и константы, чтобы тесты могли работать
 * в автономном режиме без реальной инсталляции WordPress.
 */

declare(strict_types=1);

// ============================================================
// Определяем базовые WordPress константы
// ============================================================
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 3) . '/');
}

if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

if (!defined('DB_NAME')) {
    define('DB_NAME', 'test_db');
}

// Тестовый ключ шифрования (64 hex-символа = 32 байта)
if (!defined('CB_ENCRYPTION_KEY')) {
    define('CB_ENCRYPTION_KEY', 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2');
}

// WordPress time constants
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// WordPress endpoint bitmasks (used in add_rewrite_endpoint)
if (!defined('EP_ROOT')) {
    define('EP_ROOT', 1);
}

if (!defined('EP_PAGES')) {
    define('EP_PAGES', 4);
}

// ============================================================
// Мок WordPress функций
// ============================================================

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $flags = 0, int $depth = 512): string|false
    {
        return json_encode($data, $flags, $depth);
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim(strip_tags($str));
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string
    {
        return date('Y-m-d H:i:s');
    }
}

/**
 * Stateful моки wp_options для тестов: позволяют round-trip'ить значения
 * через update_option → get_option. Значения хранятся в $GLOBALS['_cb_test_options'].
 * Сбрасывается каждый тест через tearDown (тесты сами управляют состоянием).
 */
if (!isset($GLOBALS['_cb_test_options'])) {
    $GLOBALS['_cb_test_options'] = array();
}

if (!function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        return array_key_exists($option, $GLOBALS['_cb_test_options'])
            ? $GLOBALS['_cb_test_options'][ $option ]
            : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $option, mixed $value, mixed $autoload = null): bool
    {
        $GLOBALS['_cb_test_options'][ $option ] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        if (array_key_exists($option, $GLOBALS['_cb_test_options'])) {
            unset($GLOBALS['_cb_test_options'][ $option ]);
            return true;
        }
        return false;
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        return true;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return false;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return 0;
    }
}

if (!function_exists('get_userdata')) {
    function get_userdata(int $user_id): object|false
    {
        return false;
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return false;
    }
}

if (!function_exists('is_account_page')) {
    function is_account_page(): bool
    {
        return false;
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action = ''): int|false
    {
        return 1;
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer(string $action = '', string $query_arg = '', bool $die = true): int|false
    {
        return 1;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = ''): string
    {
        return 'test_nonce_' . md5($action);
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action = '', string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
    {
        $field = '<input type="hidden" name="' . $name . '" value="test_nonce_' . md5($action) . '" />';
        if ($echo) {
            echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Тестовый стаб wp_nonce_field; HTML-строка собрана локально.
        }
        return $field;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $transient): mixed
    {
        return false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool
    {
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        return true;
    }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], mixed $ver = false, string $media = 'all'): void {}
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, bool $in_footer = false): void {}
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script(string $handle, string $object_name, array $l10n): bool
    {
        return true;
    }
}

if (!function_exists('plugins_url')) {
    function plugins_url(string $path = '', string $plugin = ''): string
    {
        return 'http://localhost/wp-content/plugins/' . $path;
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = '', string $scheme = 'admin'): string
    {
        return 'http://localhost/wp-admin/' . $path;
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}

if (!function_exists('wc_price')) {
    function wc_price(float $price, array $args = []): string
    {
        return number_format($price, 2, '.', ' ') . ' ₽';
    }
}

if (!function_exists('wc_get_logger')) {
    function wc_get_logger(): object
    {
        return new class {
            public function info(string $message, array $context = []): void {}
            public function error(string $message, array $context = []): void {}
            public function warning(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
        };
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success(mixed $data = null, int $status_code = 200, int $flags = 0): void
    {
        throw new \RuntimeException('wp_send_json_success: ' . json_encode(['success' => true, 'data' => $data]));
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error(mixed $data = null, int $status_code = 200, int $flags = 0): void
    {
        throw new \RuntimeException('wp_send_json_error: ' . json_encode(['success' => false, 'data' => $data]));
    }
}

if (!function_exists('wp_die')) {
    function wp_die(mixed $message = '', mixed $title = '', mixed $args = []): never
    {
        throw new \RuntimeException('wp_die: ' . (is_string($message) ? $message : json_encode($message)));
    }
}

if (!function_exists('add_rewrite_endpoint')) {
    function add_rewrite_endpoint(string $name, int $places, mixed $query_var = true): void {}
}

if (!function_exists('number_format_i18n')) {
    function number_format_i18n(float $number, int $decimals = 0): string
    {
        return number_format($number, $decimals, '.', ' ');
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook_name, mixed ...$arg): void {}
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename(string $plugin_file): string
    {
        return basename(dirname($plugin_file)) . '/' . basename($plugin_file);
    }
}

if (!function_exists('sprintf')) {
    // sprintf уже есть в PHP — не переопределяем
}

// ============================================================
// Подключаем файлы плагина для тестирования.
// ВАЖНО: реальные файлы подключаются ДО мок-классов,
//        чтобы избежать "Cannot declare class ... already in use"
// ============================================================
$plugin_root = dirname(__DIR__, 2);

// Шифрование
require_once $plugin_root . '/includes/class-cashback-encryption.php';

// PHP-фолбэки триггеров
require_once $plugin_root . '/includes/class-cashback-trigger-fallbacks.php';

// Основной класс плагина (для generate_reference_id)
require_once $plugin_root . '/mariadb.php';

// Антифрод: настройки и детектор
require_once $plugin_root . '/antifraud/class-fraud-settings.php';
require_once $plugin_root . '/antifraud/class-fraud-detector.php';

// CAPTCHA (использует настройки бот-защиты — зашифрованный server_key)
$_cb_captcha_file = $plugin_root . '/includes/class-cashback-captcha.php';
if (file_exists($_cb_captcha_file) && !class_exists('Cashback_Captcha')) {
    require_once $_cb_captcha_file;
}
unset($_cb_captcha_file);

// Класс статуса пользователя (реальный файл — загружаем до мок-класса)
$_cb_user_status_file = $plugin_root . '/includes/class-cashback-user-status.php';
if (file_exists($_cb_user_status_file) && !class_exists('Cashback_User_Status')) {
    require_once $_cb_user_status_file;
}
unset($_cb_user_status_file);

// Антифрод коллектор (реальный файл)
$_cb_fraud_collector_file = $plugin_root . '/antifraud/class-fraud-collector.php';
if (file_exists($_cb_fraud_collector_file) && !class_exists('Cashback_Fraud_Collector')) {
    require_once $_cb_fraud_collector_file;
}
unset($_cb_fraud_collector_file);

// CashbackWithdrawal
$_cb_withdrawal_file = $plugin_root . '/cashback-withdrawal.php';
if (file_exists($_cb_withdrawal_file) && !class_exists('CashbackWithdrawal')) {
    require_once $_cb_withdrawal_file;
}
unset($_cb_withdrawal_file);

// ============================================================
// Мок-классы (только если реальные файлы не загрузили их)
// ============================================================

if (!class_exists('Cashback_User_Status')) {
    class Cashback_User_Status
    {
        public static function is_user_banned(int $user_id): bool
        {
            return false;
        }

        public static function get_ban_info(int $user_id): array
        {
            return [];
        }

        public static function get_banned_message(array $ban_info): string
        {
            return 'User is banned';
        }
    }
}

if (!class_exists('Cashback_Fraud_Collector')) {
    class Cashback_Fraud_Collector
    {
        public static function record_withdrawal_event(int $user_id): void {}
    }
}
