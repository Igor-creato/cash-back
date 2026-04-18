<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Утилитный класс шифрования реквизитов пользователей.
 *
 * v2: AES-256-GCM (authenticated encryption) — новые записи.
 * v1: AES-256-CBC (legacy) — обратная совместимость для чтения старых данных.
 *
 * Ключ хранится в wp-config.php как CB_ENCRYPTION_KEY (64 hex-символа).
 * IV уникален для каждой записи (12 байт для GCM, 16 байт для CBC).
 */
class Cashback_Encryption {

    private const CIPHER_GCM     = 'aes-256-gcm';
    private const CIPHER_CBC     = 'aes-256-cbc';
    private const GCM_IV_LENGTH  = 12;
    private const GCM_TAG_LENGTH = 16;
    private const CBC_IV_LENGTH  = 16;

    /** Текущая версия для новых шифрований (GCM с auth tag) */
    private const KEY_VERSION = 'v2:';
    /** Legacy версия (CBC без auth tag) */
    private const LEGACY_KEY_VERSION = 'v1:';

    /**
     * Проверяет, настроен ли ключ шифрования
     */
    public static function is_configured(): bool {
        return defined('CB_ENCRYPTION_KEY')
            && strlen(CB_ENCRYPTION_KEY) === 64
            && ctype_xdigit(CB_ENCRYPTION_KEY);
    }

    /**
     * Возвращает бинарный ключ из hex-константы
     */
    private static function get_key(): string {
        if (!self::is_configured()) {
            throw new \RuntimeException('CB_ENCRYPTION_KEY is not configured or invalid (expected 64 hex characters).');
        }
        $key = hex2bin(CB_ENCRYPTION_KEY);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('CB_ENCRYPTION_KEY contains invalid hex characters.');
        }
        return $key;
    }

    /**
     * Шифрует строку AES-256-GCM (authenticated encryption).
     * Результат: "v2:" . base64(iv . tag . ciphertext)
     */
    public static function encrypt( string $plaintext ): string {
        $key        = self::get_key();
        $iv         = random_bytes(self::GCM_IV_LENGTH);
        $tag        = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER_GCM, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::GCM_TAG_LENGTH);

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return self::KEY_VERSION . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Расшифровывает строку. Поддерживает форматы:
     *  - "v2:base64(iv . tag . ciphertext)" — AES-256-GCM (authenticated)
     *  - "v1:base64(iv . ciphertext)" — AES-256-CBC (legacy)
     *  - "base64(iv . ciphertext)" — legacy без префикса
     */
    public static function decrypt( string $encrypted ): string {
        $key = self::get_key();

        // v2: AES-256-GCM (authenticated)
        if (strpos($encrypted, self::KEY_VERSION) === 0) {
            return self::decrypt_gcm(substr($encrypted, strlen(self::KEY_VERSION)), $key);
        }

        // v1: AES-256-CBC (legacy) или без префикса
        $payload = $encrypted;
        if (strpos($encrypted, self::LEGACY_KEY_VERSION) === 0) {
            $payload = substr($encrypted, strlen(self::LEGACY_KEY_VERSION));
        }

        return self::decrypt_cbc($payload, $key);
    }

    /**
     * Расшифровка AES-256-GCM: base64(iv[12] . tag[16] . ciphertext)
     */
    private static function decrypt_gcm( string $encoded, string $key ): string {
        $data = base64_decode($encoded, true);
        // Минимум: 12 байт IV + 16 байт auth tag. Ciphertext может быть пустым (пустая строка).
        $min_length = self::GCM_IV_LENGTH + self::GCM_TAG_LENGTH;

        if ($data === false || strlen($data) < $min_length) {
            throw new \RuntimeException('Decryption failed: invalid data.');
        }

        $iv         = substr($data, 0, self::GCM_IV_LENGTH);
        $tag        = substr($data, self::GCM_IV_LENGTH, self::GCM_TAG_LENGTH);
        $ciphertext = substr($data, self::GCM_IV_LENGTH + self::GCM_TAG_LENGTH);
        $plaintext  = openssl_decrypt($ciphertext, self::CIPHER_GCM, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed: invalid data.');
        }

        return $plaintext;
    }

    /**
     * Расшифровка AES-256-CBC (legacy): base64(iv[16] . ciphertext)
     */
    private static function decrypt_cbc( string $encoded, string $key ): string {
        $data = base64_decode($encoded, true);

        if ($data === false || strlen($data) < self::CBC_IV_LENGTH + 1) {
            throw new \RuntimeException('Decryption failed: invalid data.');
        }

        $iv         = substr($data, 0, self::CBC_IV_LENGTH);
        $ciphertext = substr($data, self::CBC_IV_LENGTH);
        $plaintext  = openssl_decrypt($ciphertext, self::CIPHER_CBC, $key, OPENSSL_RAW_DATA, $iv);

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed: invalid data.');
        }

        return $plaintext;
    }

    /**
     * Проверяет, зашифрована ли строка устаревшим форматом v1 (CBC без auth tag).
     *
     * Для строк без префикса версии дополнительно проверяется валидность base64
     * и минимальная длина (IV 16 байт + минимум 1 байт ciphertext = 17 байт raw).
     */
    public static function is_legacy_encrypted( string $encrypted ): bool {
        // Явный префикс v1:
        if (strpos($encrypted, self::LEGACY_KEY_VERSION) === 0) {
            return true;
        }

        // Это v2 или пустая строка — точно не legacy
        if (strpos($encrypted, self::KEY_VERSION) === 0 || empty($encrypted)) {
            return false;
        }

        // Без префикса: проверяем что это валидный base64 достаточной длины
        // (IV 16 байт + хотя бы 1 байт ciphertext = 17 байт → base64 ≥ 24 символа)
        $decoded = base64_decode($encrypted, true);
        return $decoded !== false && strlen($decoded) >= ( self::CBC_IV_LENGTH + 1 );
    }

    /**
     * Шифрует массив реквизитов и возвращает encrypted_details, masked_details, details_hash
     *
     * @param array{account: string, full_name?: string, bank?: string} $details
     * @return array{encrypted_details: string, masked_details: string, details_hash: string}
     */
    public static function encrypt_details( array $details ): array {
        $account   = $details['account'] ?? '';
        $full_name = $details['full_name'] ?? '';
        $bank      = $details['bank'] ?? '';

        // Формируем JSON для шифрования
        $plaintext_data = array(
            'account'   => $account,
            'full_name' => $full_name,
            'bank'      => $bank,
        );
        $json           = wp_json_encode($plaintext_data, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode details to JSON.');
        }

        // Шифруем
        $encrypted_details = self::encrypt($json);

        // Формируем маскированное представление
        $masked_data    = array(
            'account'   => self::mask_account($account),
            'full_name' => self::mask_name($full_name),
            'bank'      => $bank, // Название банка не секретное
        );
        $masked_details = wp_json_encode($masked_data, JSON_UNESCAPED_UNICODE);

        // Хеш для антифрода (каноническое представление)
        $details_hash = self::hash_details($plaintext_data);

        return array(
            'encrypted_details' => $encrypted_details,
            'masked_details'    => $masked_details,
            'details_hash'      => $details_hash,
        );
    }

    /**
     * Расшифровывает encrypted_details обратно в массив
     *
     * @return array{account: string, full_name: string, bank: string}
     */
    public static function decrypt_details( string $encrypted_details ): array {
        $json = self::decrypt($encrypted_details);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new \RuntimeException('Decrypted data is not valid JSON.');
        }

        return array(
            'account'   => $data['account'] ?? '',
            'full_name' => $data['full_name'] ?? '',
            'bank'      => $data['bank'] ?? '',
        );
    }

    /**
     * Маскирует номер счёта/карты/телефона: оставляет последние 4 символа
     *
     * Примеры:
     *  "4276 1234 5678 4523" → "**** **** **** 4523"
     *  "+79031234567"        → "+7903***4567"
     *  "410012345678"        → "********5678"
     */
    public static function mask_account( string $account ): string {
        if ($account === '') {
            return '';
        }

        // Убираем пробелы для вычисления длины
        $clean = preg_replace('/\s+/', '', $account);
        $len   = mb_strlen($clean);

        if ($len <= 4) {
            return $account; // Слишком короткий для маскирования
        }

        // Если номер содержит пробелы (формат карты: "4276 1234 5678 4523")
        if (strpos($account, ' ') !== false) {
            $parts          = explode(' ', $account);
            $last_part      = array_pop($parts);
            $masked_parts   = array_map(function () {
                return '****';
            }, $parts);
            $masked_parts[] = $last_part;
            return implode(' ', $masked_parts);
        }

        // Без пробелов: маскируем всё кроме последних 4
        $visible    = mb_substr($clean, -4);
        $hidden_len = $len - 4;
        return str_repeat('*', $hidden_len) . $visible;
    }

    /**
     * Маскирует ФИО: первая буква каждого слова + ****
     *
     * Примеры:
     *  "Иванов Петр Сидорович" → "И**** П**** С****"
     *  "Иванов Петр"           → "И**** П****"
     *  ""                       → ""
     */
    public static function mask_name( string $name ): string {
        if ($name === '') {
            return '';
        }

        $words        = preg_split('/\s+/', trim($name));
        $masked_words = array_map(function ( string $word ): string {
            if (mb_strlen($word) === 0) {
                return '';
            }
            return mb_substr($word, 0, 1) . '****';
        }, $words);

        return implode(' ', array_filter($masked_words));
    }

    /**
     * SHA-256 хеш от канонического представления реквизитов
     */
    public static function hash_details( array $details ): string {
        // Канонический формат: отсортированный JSON без пробелов, lowercase account
        // bank включён чтобы разные банки с одним номером счёта не давали одинаковый хеш
        $canonical = array(
            'account'   => mb_strtolower(preg_replace('/\s+/', '', $details['account'] ?? '')),
            'bank'      => mb_strtolower(trim($details['bank'] ?? '')),
            'full_name' => mb_strtolower(trim($details['full_name'] ?? '')),
        );
        ksort($canonical);

        $json = wp_json_encode($canonical, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode canonical details to JSON for hashing.');
        }

        return hash('sha256', $json);
    }

    /**
     * Извлекает маскированный номер счёта из masked_details JSON.
     * Fallback на plaintext payout_account.
     */
    public static function get_masked_account( ?string $masked_details_json, ?string $fallback_payout_account = null ): string {
        if (!empty($masked_details_json)) {
            $data = json_decode($masked_details_json, true);
            if (is_array($data) && isset($data['account'])) {
                return $data['account'];
            }
        }

        // Fallback: маскируем plaintext если есть
        if (!empty($fallback_payout_account)) {
            return self::mask_account($fallback_payout_account);
        }

        return '';
    }

    /**
     * Записывает событие в аудит-лог
     */
    public static function write_audit_log( string $action, int $actor_id, ?string $entity_type = null, ?int $entity_id = null, ?array $extra_details = null ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_audit_log';

        $wpdb->insert(
            $table,
            array(
                'action'      => $action,
                'actor_id'    => $actor_id,
                'entity_type' => $entity_type,
                'entity_id'   => $entity_id,
                'ip_address'  => self::get_client_ip(),
                // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- Request-scoped UA for bot detection/logging; not cacheable across requests.
                'user_agent'  => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : null,
                'details'     => $extra_details ? wp_json_encode($extra_details, JSON_UNESCAPED_UNICODE) : null,
                'created_at'  => current_time('mysql'),
            ),
            array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Получает IP-адрес клиента.
     *
     * Прокси-заголовки читаются ТОЛЬКО если REMOTE_ADDR в CASHBACK_TRUSTED_PROXIES.
     * Из прокси-заголовков принимаются только публичные IP (не приватные/зарезервированные),
     * чтобы предотвратить спуфинг через X-Forwarded-For: 10.0.0.1.
     */
    public static function get_client_ip(): string {
        // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders -- REMOTE_ADDR is set by the web server from the TCP connection, not a client-controlled HTTP header; proxy headers trusted only via CASHBACK_TRUSTED_PROXIES allowlist.
        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';

        // Доверять прокси-заголовкам только если REMOTE_ADDR — доверенный прокси
        $trusted_proxies = defined('CASHBACK_TRUSTED_PROXIES') ? (array) CASHBACK_TRUSTED_PROXIES : array();

        if (!empty($trusted_proxies) && in_array($remote_addr, $trusted_proxies, true)) {
            // Приоритет: CF-Connecting-IP (Cloudflare) → X-Forwarded-For → X-Real-IP
            $proxy_headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' );
            foreach ($proxy_headers as $header) {
                if (!empty($_SERVER[ $header ])) {
                    $ip = sanitize_text_field(wp_unslash($_SERVER[ $header ]));
                    // X-Forwarded-For может содержать цепочку: client, proxy1, proxy2
                    if (strpos($ip, ',') !== false) {
                        $ip = trim(explode(',', $ip)[0]);
                    }
                    // Принимаем только публичные IP — приватные/зарезервированные = спуфинг
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
        }

        return filter_var($remote_addr, FILTER_VALIDATE_IP) ? $remote_addr : '0.0.0.0';
    }
}
