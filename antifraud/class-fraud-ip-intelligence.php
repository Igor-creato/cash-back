<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Fraud_Ip_Intelligence
 *
 * Резолв IP-адреса в ASN + connection_type (mobile/residential/hosting/vpn/cgnat/private)
 * для антифрод-скоринга.
 *
 * Зачем: текущий детектор банит легитимных мобильных пользователей РФ, потому что
 * сотовые операторы (МТС, Билайн, МегаФон, Tele2, Yota) сидят на CGNAT
 * (RFC 6598, диапазон 100.64.0.0/10) — один IP обслуживает тысячи абонентов.
 * Этот модуль классифицирует IP, чтобы выдать соответствующий weight_multiplier
 * для сигнала "shared IP" в Cashback_Fraud_Detector.
 *
 * Источник данных: MaxMind GeoLite2-ASN.mmdb (бесплатная база, требует регистрации).
 * Зависимости: composer geoip2/geoip2 (рекомендуется) либо maxmind-db/reader
 * (минимальный reader). Если ни одной из библиотек нет — модуль gracefully
 * деградирует к classify type='unknown' (weight=1.0) без ошибок.
 *
 * @since 1.3.0
 */
class Cashback_Fraud_Ip_Intelligence {

    /**
     * IPv4-диапазоны (start, end) и IPv6-префиксы (prefix, length), которые
     * считаются исключёнными из IP-fingerprint антифрода.
     *
     * Источники:
     *  - RFC 1918: 10/8, 172.16/12, 192.168/16 — приватные сети
     *  - RFC 6598: 100.64/10 — CGNAT (используется мобильными операторами РФ)
     *  - RFC 3927: 169.254/16 — link-local
     *  - RFC 1122: 127/8 — loopback, 0/8 — unspecified
     *  - RFC 4193: fc00::/7 — IPv6 ULA
     *  - RFC 4291: fe80::/10 — IPv6 link-local, ::1/128 — loopback
     *  - RFC 3849: 2001:db8::/32 — documentation
     */
    public const EXCLUDED_RANGES = array(
        'ipv4' => array(
            array( '10.0.0.0', '10.255.255.255', 'private' ),
            array( '172.16.0.0', '172.31.255.255', 'private' ),
            array( '192.168.0.0', '192.168.255.255', 'private' ),
            array( '100.64.0.0', '100.127.255.255', 'cgnat' ),
            array( '169.254.0.0', '169.254.255.255', 'private' ),
            array( '127.0.0.0', '127.255.255.255', 'private' ),
            array( '0.0.0.0', '0.255.255.255', 'private' ),
        ),
        'ipv6' => array(
            array( 'fc00::', 7, 'private' ),
            array( 'fe80::', 10, 'private' ),
            array( '::1', 128, 'private' ),
            array( '2001:db8::', 32, 'private' ),
        ),
    );

    /**
     * ASN российских мобильных операторов.
     * Источник: PeeringDB / RIPE whois, обновлено 2025-Q1.
     */
    public const RU_MOBILE_ASNS = array(
        8359   => 'МТС',
        3216   => 'Билайн',
        8402   => 'Билайн (CORBINA-AS)',
        31133  => 'МегаФон',
        31213  => 'Tele2 (T2 Mobile)',
        41733  => 'Yota',
        25513  => 'МГТС',
        8369   => 'Скартел / Yota',
        21183  => 'TELE2-AS',
        43278  => 'Tinkoff Mobile (Тинькофф Мобайл)',
        205577 => 'СберМобайл',
    );

    /**
     * ASN крупных дата-центров и облачных хостингов.
     * Здесь — только заведомо хостинговые AS; CDN (Cloudflare 13335) попадает
     * сюда тоже, потому что в нашей задаче CDN/Hosting различать не нужно —
     * оба считаем "не residential".
     */
    public const KNOWN_DC_ASNS = array(
        24940  => 'Hetzner',
        16276  => 'OVH',
        14061  => 'DigitalOcean',
        16509  => 'AWS',
        14618  => 'AWS',
        13335  => 'Cloudflare',
        49505  => 'Selectel',
        9123   => 'Timeweb',
        197695 => 'Beget',
        198610 => 'FirstByte',
        199524 => 'GCore',
        63949  => 'Linode/Akamai',
    );

    /**
     * ASN коммерческих VPN-провайдеров.
     */
    public const KNOWN_VPN_ASNS = array(
        39351  => 'Mullvad',
        51852  => 'NordVPN',
        62240  => 'Proton AG',
        209103 => 'ProtonVPN',
        60068  => 'ExpressVPN (CDN77)',
        212238 => 'Surfshark',
    );

    /**
     * Regex по полю organization (из MMDB).
     * Используются только если ASN не нашёлся в явных списках выше.
     */
    public const ORG_REGEX_MOBILE  = '/(?:mobile|cellular|wireless|4g|lte|gsm|gprs|mts|beeline|megafon|tele2|yota|t2 mobile|тин(?:ьк|ьковский) мобайл|сбермобайл)/i';
    public const ORG_REGEX_HOSTING = '/(?:hosting|data\s*center|datacent[er]|cloud|vps|vds|dedicated|server|colo|colocation|netfront|reg\.ru|timeweb|beget|selectel|hetzner|digitalocean|ovh|aws|amazon|google\s+cloud|gcp|azure|linode|vultr)/i';
    public const ORG_REGEX_VPN     = '/(?:vpn|proxy|anonymizer|tor\b|nordvpn|expressvpn|mullvad|proton|surfshark|cyberghost|protonmail)/i';

    /**
     * Кэш результатов classify() в object cache (1 час).
     * Этого достаточно для большинства сайтов; при росте нагрузки
     * можно будет добавить персистентную таблицу (Этап 5 плана).
     */
    public const CACHE_TTL_SECONDS = 3600;
    public const CACHE_GROUP       = 'cashback_fraud_ip';

    /**
     * Веса по типам соединений. Применяются как множитель к базовому
     * весу IP-сигнала в детекторе.
     *
     *  - mobile     = 0.2  — несколько аккаунтов на CGNAT-IP это норма
     *  - cgnat      = 0.0  — IP вообще не учитывается как fingerprint
     *  - private    = 0.0  — служебный диапазон, никогда не публичный
     *  - residential= 1.0  — обычный пользователь, базовый вес
     *  - hosting    = 2.0  — VPS/hosting почти всегда signal автоматизации
     *  - vpn        = 1.8  — VPN снижает достоверность IP, но не нулит
     *  - tor        = 3.0  — почти всегда автоматизация / fraud
     *  - unknown    = 1.0  — БД недоступна или ASN не резолвится — fallback
     */
    private const WEIGHT_TABLE = array(
        'mobile'      => 0.2,
        'cgnat'       => 0.0,
        'private'     => 0.0,
        'residential' => 1.0,
        'hosting'     => 2.0,
        'vpn'         => 1.8,
        'tor'         => 3.0,
        'unknown'     => 1.0,
    );

    private const LABEL_TABLE = array(
        'mobile'      => 'MOBILE',
        'cgnat'       => 'CGNAT',
        'private'     => 'PRIVATE',
        'residential' => 'RESIDENTIAL',
        'hosting'     => 'HOSTING',
        'vpn'         => 'VPN',
        'tor'         => 'TOR',
        'unknown'     => 'UNKNOWN',
    );

    /**
     * Главная точка входа. Возвращает классификацию IP.
     *
     * @return array{
     *   type: string,
     *   asn: ?int,
     *   org: ?string,
     *   label: string,
     *   weight_multiplier: float,
     *   is_excluded: bool
     * }
     */
    public static function classify( string $ip ): array {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return self::build_result('unknown', null, null);
        }

        // 1. Проверка диапазонов до похода в БД — самые частые случаи.
        $excluded_type = self::resolve_excluded_type($ip);
        if ($excluded_type !== null) {
            return self::build_result($excluded_type, null, null);
        }

        $cache_key = 'classify_' . md5($ip);
        $cached    = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        $asn_info = self::lookup_asn($ip);
        if ($asn_info === null) {
            $result = self::build_result('unknown', null, null);
            // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- CACHE_TTL_SECONDS = 3600 (объявлено константой класса).
            wp_cache_set($cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL_SECONDS);
            return $result;
        }

        $type   = self::classify_by_asn($asn_info['asn'], $asn_info['org']);
        $result = self::build_result($type, $asn_info['asn'], $asn_info['org']);

        // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- CACHE_TTL_SECONDS = 3600 (объявлено константой класса).
        wp_cache_set($cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL_SECONDS);
        return $result;
    }

    /**
     * true, если IP попадает в EXCLUDED_RANGES (приватка / CGNAT / loopback / etc).
     */
    public static function is_excluded( string $ip ): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        return self::resolve_excluded_type($ip) !== null;
    }

    /**
     * Алиас is_excluded() для читабельности call-site'а в детекторе.
     */
    public static function is_private_or_cgnat( string $ip ): bool {
        return self::is_excluded($ip);
    }

    /**
     * Возвращает множитель веса для типа соединения.
     */
    public static function get_weight_multiplier( string $type ): float {
        return self::WEIGHT_TABLE[ $type ] ?? self::WEIGHT_TABLE['unknown'];
    }

    /**
     * UI label для типа.
     */
    public static function get_type_label( string $type ): string {
        return self::LABEL_TABLE[ $type ] ?? self::LABEL_TABLE['unknown'];
    }

    /**
     * Путь к MaxMind GeoLite2-ASN.mmdb. Можно переопределить через
     * константу CASHBACK_MAXMIND_ASN_DB_PATH в wp-config.php.
     */
    public static function get_database_path(): string {
        if (defined('CASHBACK_MAXMIND_ASN_DB_PATH')) {
            $path = (string) constant('CASHBACK_MAXMIND_ASN_DB_PATH');
            if ($path !== '') {
                return $path;
            }
        }
        return WP_CONTENT_DIR . '/uploads/cashback-fraud/GeoLite2-ASN.mmdb';
    }

    public static function is_database_available(): bool {
        $path = self::get_database_path();
        return $path !== '' && is_readable($path);
    }

    // ------------------------------------------------------------------
    // private helpers
    // ------------------------------------------------------------------

    /**
     * Строит унифицированный результат classify(); сюда стекаются все ветки.
     */
    private static function build_result( string $type, ?int $asn, ?string $org ): array {
        $is_excluded = in_array($type, array( 'private', 'cgnat' ), true);
        return array(
            'type'              => $type,
            'asn'               => $asn,
            'org'               => $org,
            'label'             => self::get_type_label($type),
            'weight_multiplier' => self::get_weight_multiplier($type),
            'is_excluded'       => $is_excluded,
        );
    }

    /**
     * Возвращает 'cgnat'/'private', если IP попадает в EXCLUDED_RANGES,
     * иначе null.
     */
    private static function resolve_excluded_type( string $ip ): ?string {
        $is_v6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if ($is_v6) {
            foreach (self::EXCLUDED_RANGES['ipv6'] as $row) {
                [$prefix, $prefix_len, $type] = $row;
                if (self::is_ipv6_in_prefix($ip, $prefix, $prefix_len)) {
                    return $type;
                }
            }
            return null;
        }

        foreach (self::EXCLUDED_RANGES['ipv4'] as $row) {
            [$start, $end, $type] = $row;
            if (self::ip_in_range($ip, $start, $end)) {
                return $type;
            }
        }
        return null;
    }

    /**
     * Лукап ASN через одну из MaxMind-библиотек. Если ни одной нет —
     * graceful null. Никаких throw наружу.
     */
    private static function lookup_asn( string $ip ): ?array {
        if (!self::is_database_available()) {
            return null;
        }

        $db_path = self::get_database_path();

        try {
            // Предпочитаем geoip2/geoip2 — у неё типизированный API.
            if (class_exists('\\GeoIp2\\Database\\Reader')) {
                $reader_class = '\\GeoIp2\\Database\\Reader';
                /** @var object $reader */
                $reader = new $reader_class($db_path);
                /** @var object $record */
                $record = $reader->asn($ip);
                // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MaxMind GeoIP2 SDK exposes camelCase properties; not configurable.
                $asn = isset($record->autonomousSystemNumber) ? (int) $record->autonomousSystemNumber : 0;
                // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MaxMind GeoIP2 SDK exposes camelCase properties; not configurable.
                $org = isset($record->autonomousSystemOrganization) ? (string) $record->autonomousSystemOrganization : '';
                if ($asn <= 0) {
                    return null;
                }
                return array(
					'asn' => $asn,
					'org' => $org,
				);
            }

            // Минимальный reader: maxmind-db/reader. Возвращает массив.
            if (class_exists('\\MaxMind\\Db\\Reader')) {
                $reader_class = '\\MaxMind\\Db\\Reader';
                $reader       = new $reader_class($db_path);
                /** @var array<string, mixed>|null $data */
                $data = $reader->get($ip);
                if (!is_array($data)) {
                    return null;
                }
                $asn = isset($data['autonomous_system_number']) ? (int) $data['autonomous_system_number'] : 0;
                $org = isset($data['autonomous_system_organization']) ? (string) $data['autonomous_system_organization'] : '';
                if ($asn <= 0) {
                    return null;
                }
                return array(
					'asn' => $asn,
					'org' => $org,
				);
            }
        } catch (\Throwable $e) {
            $ip_hash = hash('sha256', (string) $ip);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Cashback Fraud IP Intelligence: лог ошибки лукапа (без PII).
            error_log(sprintf('Cashback Fraud IP Intelligence: lookup failed [ip_hash=%s] [type=%s]', $ip_hash, get_class($e)));
            return null;
        }

        // Ни одна из MaxMind-библиотек не установлена — упомянуть один раз
        // в логе нет смысла на каждый запрос; молча возвращаем null.
        return null;
    }

    /**
     * Применяет ASN/org к таблицам и regex'ам и решает тип.
     */
    private static function classify_by_asn( int $asn, string $org ): string {
        if (isset(self::RU_MOBILE_ASNS[ $asn ])) {
            return 'mobile';
        }
        if (isset(self::KNOWN_DC_ASNS[ $asn ])) {
            return 'hosting';
        }
        if (isset(self::KNOWN_VPN_ASNS[ $asn ])) {
            return 'vpn';
        }

        if ($org !== '') {
            if (preg_match(self::ORG_REGEX_MOBILE, $org) === 1) {
                return 'mobile';
            }
            if (preg_match(self::ORG_REGEX_VPN, $org) === 1) {
                // VPN-проверка раньше hosting, потому что многие VPN-провайдеры
                // арендуют VPS у hosting-AS — иначе их пометит как hosting.
                return 'vpn';
            }
            if (preg_match(self::ORG_REGEX_HOSTING, $org) === 1) {
                return 'hosting';
            }
        }

        return 'residential';
    }

    /**
     * Сравнение IPv4/IPv6 в диапазоне [start, end] через inet_pton —
     * binary-сравнение байт за байт. Не использует строковые трюки.
     */
    private static function ip_in_range( string $ip, string $start, string $end ): bool {
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton emits E_WARNING on invalid IP; false-return is checked below.
        $ip_bin = @inet_pton($ip);
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton emits E_WARNING on invalid IP; false-return is checked below.
        $start_bin = @inet_pton($start);
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton emits E_WARNING on invalid IP; false-return is checked below.
        $end_bin = @inet_pton($end);

        if ($ip_bin === false || $start_bin === false || $end_bin === false) {
            return false;
        }

        // Сравниваем только адреса одной длины (одной семейства).
        if (strlen($ip_bin) !== strlen($start_bin) || strlen($ip_bin) !== strlen($end_bin)) {
            return false;
        }

        return strcmp($ip_bin, $start_bin) >= 0 && strcmp($ip_bin, $end_bin) <= 0;
    }

    /**
     * Проверка попадания IPv6-адреса в префикс заданной длины.
     * Реализация: побайтово сравниваем целые байты префикса, затем
     * накладываем маску на хвостовой байт.
     */
    private static function is_ipv6_in_prefix( string $ip, string $prefix, int $prefix_len ): bool {
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton emits E_WARNING on invalid IP; false-return is checked below.
        $ip_bin = @inet_pton($ip);
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton emits E_WARNING on invalid IP; false-return is checked below.
        $prefix_bin = @inet_pton($prefix);

        if ($ip_bin === false || $prefix_bin === false) {
            return false;
        }
        // Только IPv6 (16 байт).
        if (strlen($ip_bin) !== 16 || strlen($prefix_bin) !== 16) {
            return false;
        }
        if ($prefix_len < 0 || $prefix_len > 128) {
            return false;
        }

        $full_bytes = intdiv($prefix_len, 8);
        $rem_bits   = $prefix_len % 8;

        if ($full_bytes > 0 && substr($ip_bin, 0, $full_bytes) !== substr($prefix_bin, 0, $full_bytes)) {
            return false;
        }

        if ($rem_bits === 0) {
            return true;
        }

        $mask        = chr(( 0xFF << ( 8 - $rem_bits ) ) & 0xFF);
        $ip_byte     = substr($ip_bin, $full_bytes, 1);
        $prefix_byte = substr($prefix_bin, $full_bytes, 1);

        return ( $ip_byte & $mask ) === ( $prefix_byte & $mask );
    }
}
