<?php
/**
 * Guard для исходящих HTTP-запросов — защита от SSRF.
 *
 * Единая точка валидации URL перед вызовом wp_remote_*. Проверяет:
 * 1. URL парсится, scheme === https, host непустой.
 * 2. Host не является приватным IP-литералом (RFC1918, 127/8, 169.254/16,
 *    IPv6 ::1, fc00::/7, fe80::/10, 0.0.0.0).
 * 3. Host входит в allowlist (baseline + admin-добавленные + фильтр),
 *    либо совпадает с одним из точечных суффиксов.
 *
 * При deny возвращает WP_Error и пишет audit-log (action='outbound_request_denied').
 *
 * Dev-override: константа CASHBACK_OUTBOUND_ALLOWLIST_RELAX = true релаксирует
 * только шаг 3 (allowlist хостов). Шаги 1 и 2 активны всегда.
 *
 * @package CashbackPlugin
 * @since   7.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Outbound_HTTP_Guard {

    /** @var array{hosts:string[],suffixes:string[]}|null Runtime-кеш allowlist'а. */
    private static ?array $cache = null;

    /**
     * Проверить URL перед исходящим HTTP-запросом.
     *
     * @param string $url
     * @return true|WP_Error  true если разрешён; WP_Error('outbound_denied') с message=reason.
     */
    public static function validate_url( string $url ) {
        $parts = wp_parse_url($url);

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            $host_fallback = is_array($parts) ? (string) ( $parts['host'] ?? '' ) : '';
            return self::deny('malformed', $host_fallback);
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'https') {
            return self::deny('scheme', (string) $parts['host']);
        }

        $host_raw = strtolower((string) $parts['host']);
        // parse_url для IPv6 возвращает host с квадратными скобками — снимаем для проверок.
        $host_inner = trim($host_raw, '[]');

        if ($host_inner === '') {
            return self::deny('malformed', $host_raw);
        }

        if (self::is_private_ip_literal($host_inner)) {
            return self::deny('private_ip', $host_raw);
        }

        // Dev-relax: пропускаем только шаг allowlist'а. Private-IP guard выше уже отработал.
        if (defined('CASHBACK_OUTBOUND_ALLOWLIST_RELAX') && CASHBACK_OUTBOUND_ALLOWLIST_RELAX === true) {
            return true;
        }

        $allowlist = self::get_allowlist();

        if (in_array($host_raw, $allowlist['hosts'], true)) {
            return true;
        }

        // IP-литерал (публичный — private уже выше) НЕ матчится суффиксами.
        if (filter_var($host_inner, FILTER_VALIDATE_IP) !== false) {
            return self::deny('not_in_allowlist', $host_raw);
        }

        foreach ($allowlist['suffixes'] as $suffix) {
            $normalized = '.' . ltrim((string) $suffix, '.');
            if ($normalized === '.') {
                continue;
            }
            if (str_ends_with($host_raw, $normalized)) {
                return true;
            }
        }

        return self::deny('not_in_allowlist', $host_raw);
    }

    /**
     * Вернуть мерджнутый allowlist (baseline + custom-опция + фильтр).
     *
     * @return array{hosts:string[],suffixes:string[]}
     */
    public static function get_allowlist(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $baseline = array(
            'hosts'    => array(),
            'suffixes' => array(),
        );

        $config_file = __DIR__ . '/config/cashback-outbound-allowlist.php';
        if (file_exists($config_file)) {
            $loaded = require $config_file;
            if (is_array($loaded)) {
                if (isset($loaded['hosts']) && is_array($loaded['hosts'])) {
                    $baseline['hosts'] = array_map('strtolower', array_map('strval', $loaded['hosts']));
                }
                if (isset($loaded['suffixes']) && is_array($loaded['suffixes'])) {
                    $baseline['suffixes'] = array_map('strtolower', array_map('strval', $loaded['suffixes']));
                }
            }
        }

        // Custom hosts из wp_option (наполняется admin-UI в коммите 2).
        $custom = get_option('cashback_outbound_allowlist_custom', array());
        if (is_array($custom)) {
            foreach ($custom as $entry) {
                if (is_array($entry) && isset($entry['host']) && is_string($entry['host']) && $entry['host'] !== '') {
                    $baseline['hosts'][] = strtolower($entry['host']);
                }
            }
        }

        $baseline['hosts']    = array_values(array_unique($baseline['hosts']));
        $baseline['suffixes'] = array_values(array_unique($baseline['suffixes']));

        $filtered = apply_filters('cashback_outbound_allowlist', $baseline);
        if (!is_array($filtered)) {
            $filtered = $baseline;
        }
        if (!isset($filtered['hosts']) || !is_array($filtered['hosts'])) {
            $filtered['hosts'] = array();
        }
        if (!isset($filtered['suffixes']) || !is_array($filtered['suffixes'])) {
            $filtered['suffixes'] = array();
        }
        $filtered['hosts']    = array_values(array_unique(array_map('strtolower', array_map('strval', $filtered['hosts']))));
        $filtered['suffixes'] = array_values(array_unique(array_map('strtolower', array_map('strval', $filtered['suffixes']))));

        self::$cache = $filtered;
        return self::$cache;
    }

    /**
     * Сбросить runtime-кеш (для тестов и для hook'а updated_option_*).
     */
    public static function invalidate_cache(): void {
        self::$cache = null;
    }

    /**
     * Проверка: является ли строка host'а приватным IP-литералом.
     *
     * Public для тестирования — вне тестов не вызывается напрямую.
     *
     * @param string $host Без квадратных скобок.
     * @return bool
     */
    public static function is_private_ip_literal( string $host ): bool {
        $host = trim($host, '[]');
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if ($host === '0.0.0.0') {
                return true;
            }
            // Нет флага -> не прошёл NO_PRIV + NO_RES -> приватный/зарезервированный.
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // PHP покрывает ::1, ::, fc00::/7, fe80::/10 через NO_PRIV_RANGE|NO_RES_RANGE,
            // но между версиями бывают расхождения — добавляем ручной fallback.
            if (
                filter_var(
                    $host,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                ) === false
            ) {
                return true;
            }

            $lower = strtolower($host);
            if ($lower === '::1' || $lower === '::' || $lower === '::0') {
                return true;
            }
            // fc00::/7 (Unique local) → первый октет 0xfc или 0xfd.
            if (preg_match('/^f[cd][0-9a-f]{0,2}:/', $lower) === 1) {
                return true;
            }
            // fe80::/10 (Link-local) → fe80..febf.
            if (preg_match('/^fe[89ab][0-9a-f]?:/', $lower) === 1) {
                return true;
            }
            return false;
        }

        return false;
    }

    /**
     * Построить WP_Error + записать audit-log.
     *
     * @param string $reason
     * @param string $host
     */
    private static function deny( string $reason, string $host ): WP_Error {
        if (class_exists('Cashback_Encryption') && isset($GLOBALS['wpdb'])) {
            try {
                $actor = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
                Cashback_Encryption::write_audit_log(
                    'outbound_request_denied',
                    $actor,
                    'http',
                    null,
                    array(
                        'host'   => $host,
                        'reason' => $reason,
                    )
                );
            } catch ( \Throwable $e ) {
                // Audit-log не должен ломать основной flow.
                if (function_exists('error_log')) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log('[Cashback Outbound Guard] audit-log error: ' . $e->getMessage());
                }
            }
        }

        return new WP_Error('outbound_denied', $reason, array( 'host' => $host ));
    }
}
