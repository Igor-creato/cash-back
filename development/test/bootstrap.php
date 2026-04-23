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

// WP_CONTENT_DIR: тесты не пишут реальные файлы в wp-content; для file-path-зависимых
// тестов (Cashback_Key_Rotation) переопределяется через фильтры per-test в setUp.
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir());
}

// Тестовый ключ шифрования (64 hex-символа = 32 байта).
// Отдельный bootstrap-no-encryption-key.php выставляет $GLOBALS['_cb_test_skip_encryption_key']
// для проверки fail-closed веток, где is_configured() должен вернуть false.
if (!defined('CB_ENCRYPTION_KEY') && empty($GLOBALS['_cb_test_skip_encryption_key'])) {
    define('CB_ENCRYPTION_KEY', 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2');
}

// Вторичные ключи для покрытия dual-key ротации в EncryptionTest.
// Их определение не меняет поведение существующих тестов: get_write_key_role()
// возвращает 'primary' пока state ротации не стал migrating/migrated (он пустой по умолчанию).
if (!defined('CB_ENCRYPTION_KEY_NEW') && empty($GLOBALS['_cb_test_skip_encryption_key'])) {
    define('CB_ENCRYPTION_KEY_NEW', 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef');
}
if (!defined('CB_ENCRYPTION_KEY_PREVIOUS') && empty($GLOBALS['_cb_test_skip_encryption_key'])) {
    define('CB_ENCRYPTION_KEY_PREVIOUS', 'cafebabecafebabecafebabecafebabecafebabecafebabecafebabecafebabe');
}

// WordPress DB output modes (wp-includes/wp-db.php)
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('OBJECT_K')) {
    define('OBJECT_K', 'OBJECT_K');
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

if (!isset($GLOBALS['_cb_test_filters'])) {
    $GLOBALS['_cb_test_filters'] = array();
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['_cb_test_filters'][ $hook ][] = array(
            'cb'       => $callback,
            'priority' => $priority,
        );
        // Стабильная сортировка по priority (меньше = раньше).
        usort(
            $GLOBALS['_cb_test_filters'][ $hook ],
            static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']
        );
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
        return (bool) ($GLOBALS['_cb_test_is_logged_in'] ?? false);
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['_cb_test_user_id'] ?? 0);
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

/**
 * Stateful in-memory моки transient API + Object Cache API.
 * Требуются Cashback_Idempotency (Группа 5 ADR) для functional-тестов дедупа
 * request_id: helper вызывает wp_cache_add/get + set_transient, и в тестах
 * нужен честный round-trip с учётом TTL.
 *
 * Сброс между тестами — через $GLOBALS['_cb_test_transients'] = [] / $GLOBALS['_cb_test_cache'] = [].
 */
if (!isset($GLOBALS['_cb_test_transients'])) {
    $GLOBALS['_cb_test_transients'] = array();
}
if (!isset($GLOBALS['_cb_test_cache'])) {
    $GLOBALS['_cb_test_cache'] = array();
}

if (!function_exists('get_transient')) {
    function get_transient(string $transient): mixed
    {
        $store = &$GLOBALS['_cb_test_transients'];
        if (!array_key_exists($transient, $store)) {
            return false;
        }
        $entry = $store[ $transient ];
        if ($entry['expires_at'] !== 0 && $entry['expires_at'] < time()) {
            unset($store[ $transient ]);
            return false;
        }
        return $entry['value'];
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool
    {
        $GLOBALS['_cb_test_transients'][ $transient ] = array(
            'value'      => $value,
            'expires_at' => $expiration > 0 ? time() + $expiration : 0,
        );
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        if (array_key_exists($transient, $GLOBALS['_cb_test_transients'])) {
            unset($GLOBALS['_cb_test_transients'][ $transient ]);
            return true;
        }
        return false;
    }
}

if (!function_exists('wp_cache_add')) {
    function wp_cache_add(string $key, mixed $data, string $group = '', int $expire = 0): bool
    {
        $store    = &$GLOBALS['_cb_test_cache'];
        $bucket   = $group !== '' ? $group : 'default';
        if (isset($store[ $bucket ][ $key ])) {
            $entry = $store[ $bucket ][ $key ];
            if ($entry['expires_at'] === 0 || $entry['expires_at'] >= time()) {
                return false;
            }
        }
        $store[ $bucket ][ $key ] = array(
            'value'      => $data,
            'expires_at' => $expire > 0 ? time() + $expire : 0,
        );
        return true;
    }
}

if (!function_exists('wp_cache_get')) {
    function wp_cache_get(string $key, string $group = '', bool $force = false, ?bool &$found = null): mixed
    {
        $store  = &$GLOBALS['_cb_test_cache'];
        $bucket = $group !== '' ? $group : 'default';
        if (!isset($store[ $bucket ][ $key ])) {
            $found = false;
            return false;
        }
        $entry = $store[ $bucket ][ $key ];
        if ($entry['expires_at'] !== 0 && $entry['expires_at'] < time()) {
            unset($store[ $bucket ][ $key ]);
            $found = false;
            return false;
        }
        $found = true;
        return $entry['value'];
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set(string $key, mixed $data, string $group = '', int $expire = 0): bool
    {
        $bucket = $group !== '' ? $group : 'default';
        $GLOBALS['_cb_test_cache'][ $bucket ][ $key ] = array(
            'value'      => $data,
            'expires_at' => $expire > 0 ? time() + $expire : 0,
        );
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        $bucket = $group !== '' ? $group : 'default';
        if (isset($GLOBALS['_cb_test_cache'][ $bucket ][ $key ])) {
            unset($GLOBALS['_cb_test_cache'][ $bucket ][ $key ]);
            return true;
        }
        return false;
    }
}

if (!isset($GLOBALS['_cb_test_enqueued_styles'])) {
    $GLOBALS['_cb_test_enqueued_styles'] = array();
}
if (!isset($GLOBALS['_cb_test_enqueued_scripts'])) {
    $GLOBALS['_cb_test_enqueued_scripts'] = array();
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], mixed $ver = false, string $media = 'all'): void
    {
        // Повторяем семантику WP: enqueue без src для уже зарегистрированного handle
        // не затирает его регистрационные данные, только помечает как enqueued.
        if ($src === '' && isset($GLOBALS['_cb_test_enqueued_styles'][ $handle ])) {
            $GLOBALS['_cb_test_enqueued_styles'][ $handle ]['enqueued'] = true;
            return;
        }
        $GLOBALS['_cb_test_enqueued_styles'][ $handle ] = array(
            'handle'   => $handle,
            'src'      => $src,
            'deps'     => $deps,
            'ver'      => $ver,
            'media'    => $media,
            'enqueued' => true,
        );
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, bool $in_footer = false): void
    {
        if ($src === '' && isset($GLOBALS['_cb_test_enqueued_scripts'][ $handle ])) {
            $GLOBALS['_cb_test_enqueued_scripts'][ $handle ]['enqueued'] = true;
            return;
        }
        $GLOBALS['_cb_test_enqueued_scripts'][ $handle ] = array(
            'handle'    => $handle,
            'src'       => $src,
            'deps'      => $deps,
            'ver'       => $ver,
            'in_footer' => $in_footer,
            'enqueued'  => true,
        );
    }
}

if (!function_exists('wp_register_script')) {
    function wp_register_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, bool $in_footer = false): bool
    {
        $GLOBALS['_cb_test_enqueued_scripts'][ $handle ] = array(
            'handle'     => $handle,
            'src'        => $src,
            'deps'       => $deps,
            'ver'        => $ver,
            'in_footer'  => $in_footer,
            'registered' => true,
        );
        return true;
    }
}

if (!function_exists('wp_script_is')) {
    function wp_script_is(string $handle, string $list = 'enqueued'): bool
    {
        return isset($GLOBALS['_cb_test_enqueued_scripts'][ $handle ]);
    }
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

if (!interface_exists('WC_Logger_Interface')) {
    interface WC_Logger_Interface
    {
        public function info(string $message, array $context = []): void;
        public function error(string $message, array $context = []): void;
        public function warning(string $message, array $context = []): void;
        public function debug(string $message, array $context = []): void;
    }
}

if (!function_exists('wc_get_logger')) {
    function wc_get_logger(): WC_Logger_Interface
    {
        return new class implements WC_Logger_Interface {
            public function info(string $message, array $context = []): void {}
            public function error(string $message, array $context = []): void {}
            public function warning(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
        };
    }
}

/**
 * Сигнал прерывания запроса, эмулирующий wp_send_json_success / wp_send_json_error / wp_die в тестах.
 * Наследник \Error (не \Exception) — так продакшен-код с `catch (\Exception)`
 * внутри AJAX-хендлеров НЕ перехватывает signal случайно, ломая flow-control.
 * Тесты ловят его через catch (\Throwable) или конкретный класс.
 */
if (!class_exists('Cashback_Test_Halt_Signal')) {
    class Cashback_Test_Halt_Signal extends \Error {}
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success(mixed $data = null, int $status_code = 200, int $flags = 0): void
    {
        $GLOBALS['_cb_test_last_json_response'] = array(
            'success'     => true,
            'data'        => $data,
            'status_code' => $status_code,
        );
        throw new Cashback_Test_Halt_Signal('wp_send_json_success: ' . json_encode(['success' => true, 'data' => $data]));
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error(mixed $data = null, int $status_code = 200, int $flags = 0): void
    {
        $GLOBALS['_cb_test_last_json_response'] = array(
            'success'     => false,
            'data'        => $data,
            'status_code' => $status_code,
        );
        throw new Cashback_Test_Halt_Signal('wp_send_json_error: ' . json_encode(['success' => false, 'data' => $data]));
    }
}

if (!function_exists('wp_die')) {
    function wp_die(mixed $message = '', mixed $title = '', mixed $args = []): never
    {
        throw new Cashback_Test_Halt_Signal('wp_die: ' . (is_string($message) ? $message : json_encode($message)));
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

if (!isset($GLOBALS['_cb_test_actions_fired'])) {
    $GLOBALS['_cb_test_actions_fired'] = array();
}
if (!function_exists('do_action')) {
    function do_action(string $hook_name, mixed ...$arg): void
    {
        $GLOBALS['_cb_test_actions_fired'][] = array(
            'hook' => $hook_name,
            'args' => $arg,
        );
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed
    {
        $filters = $GLOBALS['_cb_test_filters'][ $hook_name ] ?? array();
        foreach ($filters as $entry) {
            $value = call_user_func($entry['cb'], $value, ...$args);
        }
        return $value;
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename(string $plugin_file): string
    {
        return basename(dirname($plugin_file)) . '/' . basename($plugin_file);
    }
}

if (!function_exists('maybe_serialize')) {
    function maybe_serialize(mixed $data): mixed
    {
        if (is_array($data) || is_object($data)) {
            return serialize($data);
        }
        return $data;
    }
}

if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize(mixed $data): mixed
    {
        if (is_string($data) && $data !== '') {
            $result = @unserialize($data, array( 'allowed_classes' => false )); // phpcs:ignore
            if ($result !== false || $data === 'b:0;') {
                return $result;
            }
        }
        return $data;
    }
}

// Action Scheduler стабы: запоминают планирование через $GLOBALS['_cb_test_as_scheduled'].
if (!isset($GLOBALS['_cb_test_as_scheduled'])) {
    $GLOBALS['_cb_test_as_scheduled'] = false;
}

if (!function_exists('as_enqueue_async_action')) {
    function as_enqueue_async_action(string $hook, array $args = array(), string $group = ''): int
    {
        $GLOBALS['_cb_test_as_scheduled'] = array(
            'hook'  => $hook,
            'args'  => $args,
            'group' => $group,
        );
        return 1;
    }
}

if (!function_exists('as_has_scheduled_action')) {
    function as_has_scheduled_action(string $hook, ?array $args = null, string $group = ''): bool
    {
        return false;
    }
}

if (!function_exists('as_schedule_single_action')) {
    function as_schedule_single_action(int $timestamp, string $hook, array $args = array(), string $group = ''): int
    {
        $GLOBALS['_cb_test_as_scheduled'] = array(
            'hook'      => $hook,
            'args'      => $args,
            'group'     => $group,
            'timestamp' => $timestamp,
        );
        return 1;
    }
}

if (!isset($GLOBALS['_cb_test_as_unscheduled'])) {
    $GLOBALS['_cb_test_as_unscheduled'] = array();
}

if (!function_exists('as_unschedule_all_actions')) {
    function as_unschedule_all_actions(string $hook, array $args = array(), string $group = ''): int
    {
        $GLOBALS['_cb_test_as_unscheduled'][] = array(
            'hook'  => $hook,
            'args'  => $args,
            'group' => $group,
        );
        return 1;
    }
}

// ============================================================
// HTTP-стабы: WP_Error + wp_remote_* с перехватом вызовов.
//
// Тесты управляют ответом через $GLOBALS['_cb_test_http_response'] (массив
// в формате WP: ['body' => ..., 'response' => ['code' => 200, 'message' => 'OK']]).
// Каждый вызов wp_remote_get/post пушится в $GLOBALS['_cb_test_http_calls'].
// Чтобы вернуть WP_Error — положите его в _cb_test_http_response.
// ============================================================

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string,string[]> */
        protected array $errors = array();
        /** @var array<string,mixed> */
        protected array $error_data = array();

        public function __construct(string $code = '', string $message = '', mixed $data = null)
        {
            if ($code !== '') {
                $this->errors[ $code ][] = $message;
                if ($data !== null) {
                    $this->error_data[ $code ] = $data;
                }
            }
        }

        public function get_error_code(): string
        {
            $codes = array_keys($this->errors);
            return (string) ($codes[0] ?? '');
        }

        public function get_error_message(string $code = ''): string
        {
            if ($code === '') {
                $code = $this->get_error_code();
            }
            $messages = $this->errors[ $code ] ?? array();
            return (string) ($messages[0] ?? '');
        }

        public function get_error_data(string $code = ''): mixed
        {
            if ($code === '') {
                $code = $this->get_error_code();
            }
            return $this->error_data[ $code ] ?? null;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!isset($GLOBALS['_cb_test_http_calls'])) {
    $GLOBALS['_cb_test_http_calls'] = array();
}

if (!isset($GLOBALS['_cb_test_http_response'])) {
    $GLOBALS['_cb_test_http_response'] = array(
        'body'     => '',
        'response' => array( 'code' => 200, 'message' => 'OK' ),
        'headers'  => array(),
    );
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = array()): array|WP_Error
    {
        $GLOBALS['_cb_test_http_calls'][] = array(
            'method' => 'GET',
            'url'    => $url,
            'args'   => $args,
        );
        return $GLOBALS['_cb_test_http_response'];
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = array()): array|WP_Error
    {
        $GLOBALS['_cb_test_http_calls'][] = array(
            'method' => 'POST',
            'url'    => $url,
            'args'   => $args,
        );
        return $GLOBALS['_cb_test_http_response'];
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(array|WP_Error $response): string
    {
        if ($response instanceof WP_Error) {
            return '';
        }
        return (string) ($response['body'] ?? '');
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(array|WP_Error $response): int|string
    {
        if ($response instanceof WP_Error) {
            return '';
        }
        return (int) ($response['response']['code'] ?? 0);
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url): string
    {
        $query = http_build_query($args);
        if ($query === '') {
            return $url;
        }
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . $query;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        if ($component === -1) {
            return parse_url($url);
        }
        return parse_url($url, $component);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        $key = strtolower($key);
        return (string) preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}

// ============================================================
// Admin-стабы: capabilities, email, users.
// Тесты управляют состоянием через:
//   $GLOBALS['_cb_test_current_user_can'] — bool (default true)
//   $GLOBALS['_cb_test_mail_calls']       — массив вызовов wp_mail
//   $GLOBALS['_cb_test_admin_users']      — список (id,email) для get_users
// ============================================================

if (!isset($GLOBALS['_cb_test_current_user_can'])) {
    $GLOBALS['_cb_test_current_user_can'] = true;
}
if (!isset($GLOBALS['_cb_test_mail_calls'])) {
    $GLOBALS['_cb_test_mail_calls'] = array();
}
if (!isset($GLOBALS['_cb_test_admin_users'])) {
    $GLOBALS['_cb_test_admin_users'] = array();
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, mixed ...$args): bool
    {
        return (bool) $GLOBALS['_cb_test_current_user_can'];
    }
}

if (!function_exists('wp_mail')) {
    function wp_mail(string|array $to, string $subject, string $message, string|array $headers = '', array|string $attachments = array()): bool
    {
        $GLOBALS['_cb_test_mail_calls'][] = array(
            'to'      => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
        );
        return true;
    }
}

if (!function_exists('get_users')) {
    function get_users(array $args = array()): array
    {
        return $GLOBALS['_cb_test_admin_users'];
    }
}

if (!function_exists('absint')) {
    function absint(mixed $maybeint): int
    {
        return abs((int) $maybeint);
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email(string $email): string
    {
        $email = trim($email);
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $str): string
    {
        return trim(strip_tags($str));
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

// Ротация ключа шифрования (admin)
require_once $plugin_root . '/admin/class-cashback-key-rotation.php';

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
