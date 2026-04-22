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
 *
 * Dual-key ротация: во время ротации класс поддерживает до трёх ключей:
 *  - primary  (CB_ENCRYPTION_KEY) — основной ключ.
 *  - new      (CB_ENCRYPTION_KEY_NEW) — staging-ключ в фазах ротации, становится write-key
 *             при state ∈ {migrating, migrated}.
 *  - previous (CB_ENCRYPTION_KEY_PREVIOUS) — предыдущий ключ в окне отката после finalize.
 *
 * encrypt() всегда использует write-key (get_write_key_role). decrypt() делает trial-decrypt
 * по всем активным ролям. См. Cashback_Key_Rotation.
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
     * Префикс-маркер для зашифрованных значений wp_options.
     * Формат wp_option: "ENC:v1:" . encrypt($plaintext) = "ENC:v1:v2:base64(iv|tag|ct)".
     * Внешний префикс нужен чтобы отличить «зашифровано helper'ом» от любого
     * plaintext (который может случайно начинаться с "v2:" и т.п.).
     */
    private const OPTION_CIPHERTEXT_PREFIX = 'ENC:v1:';

    /**
     * Роли ключей в dual-key ротации.
     * - primary  — основной ключ, загружается из CB_ENCRYPTION_KEY. По умолчанию используется и для read, и для write.
     * - new      — staging-ключ во время ротации (CB_ENCRYPTION_KEY_NEW). Write-ключ в фазах migrating/migrated.
     * - previous — предыдущий ключ в окне отката (CB_ENCRYPTION_KEY_PREVIOUS). Read-only fallback.
     */
    public const KEY_ROLE_PRIMARY  = 'primary';
    public const KEY_ROLE_NEW      = 'new';
    public const KEY_ROLE_PREVIOUS = 'previous';

    /** Имена констант ключей для каждой роли. */
    private const KEY_CONSTANTS = array(
        self::KEY_ROLE_PRIMARY  => 'CB_ENCRYPTION_KEY',
        self::KEY_ROLE_NEW      => 'CB_ENCRYPTION_KEY_NEW',
        self::KEY_ROLE_PREVIOUS => 'CB_ENCRYPTION_KEY_PREVIOUS',
    );

    /** Соль HMAC для fingerprint — та же, что у Cashback_Encryption_Recovery. */
    private const FINGERPRINT_SALT = 'cashback_fingerprint_v1';

    /** wp_option с состоянием ротации — см. Cashback_Key_Rotation::STATE_OPTION. */
    private const ROTATION_STATE_OPTION = 'cashback_key_rotation_state';

    /**
     * In-process cache: хэши значений, для которых уже записана audit-запись
     * о сбое fail-safe дешифровки. Защита от спама аудит-лога при множественных
     * вызовах `decrypt_if_ciphertext()` на одном запросе (например, get_all_bot_settings +
     * get_captcha_server_key читают тот же wp_option).
     *
     * @var array<string,bool>
     */
    private static $reported_decrypt_failures = array();

    /**
     * Проверяет, настроен ли ключ шифрования
     */
    public static function is_configured(): bool {
        return defined('CB_ENCRYPTION_KEY')
            && strlen(CB_ENCRYPTION_KEY) === 64
            && ctype_xdigit(CB_ENCRYPTION_KEY);
    }

    /**
     * Проверяет, валиден ли ключ конкретной роли (64 hex-символа).
     * Роль primary валидна через is_configured(); роли new/previous — через соответствующие константы.
     */
    public static function is_key_role_configured( string $role ): bool {
        $constant = self::KEY_CONSTANTS[ $role ] ?? null;
        if ($constant === null) {
            return false;
        }
        if (!defined($constant)) {
            return false;
        }
        $hex = (string) constant($constant);
        return strlen($hex) === 64 && ctype_xdigit($hex);
    }

    /**
     * Возвращает бинарный ключ указанной роли. Бросает исключение, если роль не настроена.
     */
    private static function get_key_binary( string $role ): string {
        if (!self::is_key_role_configured($role)) {
            $constant = (string) ( self::KEY_CONSTANTS[ $role ] ?? '(unknown)' );
            throw new \RuntimeException(esc_html($constant . ' is not configured or invalid (expected 64 hex characters).'));
        }
        $hex    = (string) constant(self::KEY_CONSTANTS[ $role ]);
        $binary = hex2bin($hex);
        if ($binary === false || strlen($binary) !== 32) {
            throw new \RuntimeException(esc_html(self::KEY_CONSTANTS[ $role ] . ' contains invalid hex characters.'));
        }
        return $binary;
    }

    /**
     * Возвращает ассоциативный массив [role => binary_key] для всех сконфигурированных ролей.
     * Порядок ключей при trial-decrypt: primary (базовое чтение), new (во время ротации),
     * previous (окно отката). Write-key добавляется отдельно в decrypt().
     *
     * @return array<string,string>
     */
    public static function get_active_keys(): array {
        $keys = array();
        foreach (array( self::KEY_ROLE_PRIMARY, self::KEY_ROLE_NEW, self::KEY_ROLE_PREVIOUS ) as $role) {
            if (self::is_key_role_configured($role)) {
                $keys[ $role ] = self::get_key_binary($role);
            }
        }
        return $keys;
    }

    /**
     * Возвращает роль ключа, которым нужно шифровать новые/обновляемые значения.
     *
     * Правило: если сконфигурирован CB_ENCRYPTION_KEY_NEW И state ротации ∈ {migrating, migrated},
     * то write-key = NEW. Иначе — PRIMARY.
     *
     * Fallback на PRIMARY безопасен: если процесс стартовал до создания .new.php и CB_ENCRYPTION_KEY_NEW
     * не определена — пользовательские данные уйдут в БД OLD-шифртекстом, но row-lock + sanity-pass
     * в Cashback_Key_Rotation гарантируют, что batch либо обработает их до state=migrated, либо
     * подчистит sanity-проходом. См. план, секция «Конкурентная запись».
     */
    public static function get_write_key_role(): string {
        if (!self::is_key_role_configured(self::KEY_ROLE_NEW)) {
            return self::KEY_ROLE_PRIMARY;
        }
        $state = self::read_rotation_state();
        if ($state === 'migrating' || $state === 'migrated') {
            return self::KEY_ROLE_NEW;
        }
        return self::KEY_ROLE_PRIMARY;
    }

    /**
     * Прямое чтение state-поля из wp_option ротации. Пустая строка, если ротация не инициализирована.
     */
    private static function read_rotation_state(): string {
        $raw = get_option(self::ROTATION_STATE_OPTION, '');
        if (is_array($raw)) {
            return isset($raw['state']) ? (string) $raw['state'] : '';
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['state'])) {
                return (string) $decoded['state'];
            }
        }
        return '';
    }

    /**
     * HMAC-SHA256 fingerprint ключа указанной роли (не раскрывает сам ключ).
     * Используется для отображения в UI и детекции смены ключа. Пустая строка, если роль не настроена.
     */
    public static function get_fingerprint( string $role = self::KEY_ROLE_PRIMARY ): string {
        if (!self::is_key_role_configured($role)) {
            return '';
        }
        $hex = (string) constant(self::KEY_CONSTANTS[ $role ]);
        return hash_hmac('sha256', $hex, self::FINGERPRINT_SALT);
    }

    /**
     * Шифрует строку AES-256-GCM (authenticated encryption).
     * Результат: "v2:" . base64(iv . tag . ciphertext)
     *
     * Во время ротации (state=migrating/migrated и определён CB_ENCRYPTION_KEY_NEW) использует NEW-ключ,
     * иначе — PRIMARY. Это гарантирует: новые пользовательские записи во время ротации сразу идут
     * NEW-ключом, batch-job'у не нужно их переобрабатывать.
     */
    public static function encrypt( string $plaintext ): string {
        return self::encrypt_with_role($plaintext, self::get_write_key_role());
    }

    /**
     * Шифрует строку ключом конкретной роли. Нужен ротатору (Cashback_Key_Rotation) и тестам.
     * Публичный для покрытия sanity-pass и rollback-путей.
     */
    public static function encrypt_with_role( string $plaintext, string $role ): string {
        $key        = self::get_key_binary($role);
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
     *
     * Во время dual-key ротации пытается расшифровать всеми активными ключами
     * в порядке: write-key → primary → new → previous. Если все не подошли —
     * RuntimeException с числом перепробованных ключей.
     *
     * Для v2 (GCM) trial-decrypt надёжен: auth tag гарантированно не совпадёт на чужом ключе.
     * Для v1 (CBC) auth tag отсутствует → openssl_decrypt может иногда вернуть «мусорную» строку,
     * но это приемлемый риск для legacy-хвоста; новые шифрования все v2.
     */
    public static function decrypt( string $encrypted ): string {
        $keys = self::get_keys_in_read_order();

        if (empty($keys)) {
            throw new \RuntimeException('No encryption keys configured for decrypt.');
        }

        // v2: AES-256-GCM (authenticated)
        if (strpos($encrypted, self::KEY_VERSION) === 0) {
            $payload = substr($encrypted, strlen(self::KEY_VERSION));
            foreach ($keys as $role => $key) {
                $plaintext = self::try_decrypt_gcm($payload, $key);
                if ($plaintext !== null) {
                    return $plaintext;
                }
            }
            throw new \RuntimeException('Decryption failed: tag mismatch on ' . count($keys) . ' active key(s).');
        }

        // v1: AES-256-CBC (legacy) или без префикса
        $payload = $encrypted;
        if (strpos($encrypted, self::LEGACY_KEY_VERSION) === 0) {
            $payload = substr($encrypted, strlen(self::LEGACY_KEY_VERSION));
        }

        foreach ($keys as $role => $key) {
            $plaintext = self::try_decrypt_cbc($payload, $key);
            if ($plaintext !== null) {
                return $plaintext;
            }
        }
        throw new \RuntimeException('Decryption failed: invalid data on ' . count($keys) . ' active key(s).');
    }

    /**
     * Пытается расшифровать ciphertext только указанной ролью. Возвращает plaintext или null.
     * Используется sanity-pass ротатором для проверки «зашифровано ли NEW ключом».
     */
    public static function try_decrypt_with_role( string $encrypted, string $role ): ?string {
        if (!self::is_key_role_configured($role)) {
            return null;
        }
        $key = self::get_key_binary($role);

        if (strpos($encrypted, self::KEY_VERSION) === 0) {
            return self::try_decrypt_gcm(substr($encrypted, strlen(self::KEY_VERSION)), $key);
        }

        $payload = $encrypted;
        if (strpos($encrypted, self::LEGACY_KEY_VERSION) === 0) {
            $payload = substr($encrypted, strlen(self::LEGACY_KEY_VERSION));
        }
        return self::try_decrypt_cbc($payload, $key);
    }

    /**
     * Перешифровывает ciphertext текущим write-key'ом (decrypt старым, encrypt новым).
     * Основной инструмент batch-job'а ротации. Если расшифровать не удалось — RuntimeException.
     */
    public static function rotate_value( string $encrypted ): string {
        $plaintext = self::decrypt($encrypted);
        return self::encrypt($plaintext);
    }

    /**
     * Возвращает ключи в порядке пробования для trial-decrypt: write → primary → new → previous.
     * Дубликаты исключаются (обычный случай: write_role == primary).
     *
     * @return array<string,string>
     */
    private static function get_keys_in_read_order(): array {
        $ordered = array();
        $write   = self::get_write_key_role();

        foreach (array( $write, self::KEY_ROLE_PRIMARY, self::KEY_ROLE_NEW, self::KEY_ROLE_PREVIOUS ) as $role) {
            if (isset($ordered[ $role ])) {
                continue;
            }
            if (!self::is_key_role_configured($role)) {
                continue;
            }
            $ordered[ $role ] = self::get_key_binary($role);
        }
        return $ordered;
    }

    /**
     * AES-256-GCM decrypt. Возвращает plaintext или null при несовпадении auth tag / битых данных.
     */
    private static function try_decrypt_gcm( string $encoded, string $key ): ?string {
        $data = base64_decode($encoded, true);
        $min  = self::GCM_IV_LENGTH + self::GCM_TAG_LENGTH;

        if ($data === false || strlen($data) < $min) {
            return null;
        }

        $iv         = substr($data, 0, self::GCM_IV_LENGTH);
        $tag        = substr($data, self::GCM_IV_LENGTH, self::GCM_TAG_LENGTH);
        $ciphertext = substr($data, self::GCM_IV_LENGTH + self::GCM_TAG_LENGTH);
        $plaintext  = openssl_decrypt($ciphertext, self::CIPHER_GCM, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * AES-256-CBC decrypt (legacy). Возвращает plaintext или null при битом padding.
     * Внимание: CBC без auth tag — на чужом ключе иногда может вернуть «мусорный» plaintext,
     * это приемлемо для legacy-данных; новые шифрования все v2 (GCM).
     */
    private static function try_decrypt_cbc( string $encoded, string $key ): ?string {
        $data = base64_decode($encoded, true);

        if ($data === false || strlen($data) < self::CBC_IV_LENGTH + 1) {
            return null;
        }

        $iv         = substr($data, 0, self::CBC_IV_LENGTH);
        $ciphertext = substr($data, self::CBC_IV_LENGTH);
        $plaintext  = openssl_decrypt($ciphertext, self::CIPHER_CBC, $key, OPENSSL_RAW_DATA, $iv);

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * Шифрует значение wp_option, если шифрование настроено и значение не пустое.
     *
     * Пустая строка интерпретируется как «не настроено» и возвращается как есть —
     * шифровать нечего, а пустота в wp_option — валидное состояние.
     *
     * При отсутствии ключа (CB_ENCRYPTION_KEY) — graceful fallback, возвращаем plaintext.
     * Жёсткий fail-closed для секретов в payout_settings — это Группа 4 ADR.
     */
    public static function encrypt_if_needed( string $plaintext ): string {
        if ($plaintext === '') {
            return '';
        }
        if (!self::is_configured()) {
            return $plaintext;
        }
        return self::OPTION_CIPHERTEXT_PREFIX . self::encrypt($plaintext);
    }

    /**
     * Расшифровывает значение wp_option, если оно помечено префиксом ENC:v1:.
     * Иначе возвращает как есть (backward-compat для plaintext-значений до миграции).
     *
     * Fail-safe семантика (отличается от прямого decrypt()):
     *  - Ключ не настроен → возвращаем ''. Лог `option_decrypt_key_missing`.
     *  - Ключ настроен, но decrypt() бросил (ротация ключа, порча данных) → возвращаем ''.
     *    Лог `option_decrypt_failed`.
     *
     * Причина: helper используется для сервисных секретов в wp_options
     * (captcha_server_key, EPN refresh_token). Потеря возможности расшифровать
     * означает «фича временно выключена», а не «сайт упал». Для финансовых
     * реквизитов пользователя используется decrypt_details() с fail-loud
     * семантикой под is_configured() guard на callsite.
     */
    public static function decrypt_if_ciphertext( string $value ): string {
        if (!self::is_option_ciphertext($value)) {
            return $value;
        }

        $payload = substr($value, strlen(self::OPTION_CIPHERTEXT_PREFIX));

        if (!self::is_configured()) {
            self::report_decrypt_failure_once($value, 'option_decrypt_key_missing', 'CB_ENCRYPTION_KEY not configured');
            return '';
        }

        try {
            return self::decrypt($payload);
        } catch (\Throwable $e) {
            self::report_decrypt_failure_once($value, 'option_decrypt_failed', $e->getMessage());
            return '';
        }
    }

    /**
     * Пишет audit-запись о сбое дешифровки wp_option один раз за процесс для
     * данного значения. Защищает лог от спама при множественных вызовах
     * decrypt_if_ciphertext() на одном запросе.
     */
    private static function report_decrypt_failure_once( string $value, string $action, string $reason ): void {
        $fingerprint = hash('sha256', $action . '|' . $value);
        if (isset(self::$reported_decrypt_failures[ $fingerprint ])) {
            return;
        }
        self::$reported_decrypt_failures[ $fingerprint ] = true;

        // Audit log insert может бросить, если таблица недоступна (например, в ранних хуках).
        // Fail-safe — не дать диагностике самой уложить сайт.
        try {
            self::write_audit_log(
                $action,
                0,
                'cashback_encryption',
                null,
                array(
                    'reason'       => $reason,
                    'value_sha256' => hash('sha256', $value),
                )
            );
        } catch (\Throwable $e) {
            // Диагностика через error_log как последний рубеж.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- fail-safe fallback когда audit log недоступен.
            error_log('[cashback] ' . $action . ': ' . $reason);
        }
    }

    /**
     * true, если значение начинается с префикса ENC:v1: (регистр важен).
     * Используется миграцией и диагностикой для проверки «уже зашифровано».
     */
    public static function is_option_ciphertext( string $value ): bool {
        return strncmp($value, self::OPTION_CIPHERTEXT_PREFIX, strlen(self::OPTION_CIPHERTEXT_PREFIX)) === 0;
    }

    /**
     * One-time миграция plaintext-секретов в wp_options → ciphertext с префиксом ENC:v1:.
     *
     * Запускается на хуке plugins_loaded (prio=100). Идемпотентна:
     * флаг cashback_options_encrypted_v1='1' + проверка is_option_ciphertext()
     * на каждом значении защищают от повторного запуска / double-encrypt.
     *
     * Без CB_ENCRYPTION_KEY — early return (ничего не шифруем, флаг не ставим;
     * администратор должен сначала настроить ключ).
     *
     * Покрываемые опции:
     * - cashback_captcha_server_key (статический ключ)
     * - cashback_epn_refresh_<md5(client_id)> (wildcard через $wpdb)
     */
    public static function migrate_plaintext_options(): void {
        if (!self::is_configured()) {
            return;
        }
        if (get_option('cashback_options_encrypted_v1') === '1') {
            return;
        }

        $migrated = 0;

        // 1) Статические ключи.
        $fixed_keys = array( 'cashback_captcha_server_key' );
        foreach ($fixed_keys as $option_name) {
            $current = (string) get_option($option_name, '');
            if ($current === '' || self::is_option_ciphertext($current)) {
                continue;
            }
            update_option($option_name, self::encrypt_if_needed($current), false);
            ++$migrated;
        }

        // 2) Wildcard-ключи через wpdb: все EPN refresh_token (по одному на client_id).
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb)) {
            $options_table = property_exists($wpdb, 'options') ? (string) $wpdb->options : 'wp_options';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name passed via %i; LIKE wildcard через аргумент.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- %i для имени таблицы.
                    "SELECT option_name, option_value FROM %i WHERE option_name LIKE %s AND option_value <> '' AND option_value NOT LIKE %s",
                    $options_table,
                    'cashback_epn_refresh_%',
                    self::OPTION_CIPHERTEXT_PREFIX . '%'
                )
            );

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $option_name  = is_object($row) ? (string) ( $row->option_name ?? '' ) : (string) ( $row['option_name'] ?? '' );
                    $option_value = is_object($row) ? (string) ( $row->option_value ?? '' ) : (string) ( $row['option_value'] ?? '' );
                    if ($option_name === '' || $option_value === '' || self::is_option_ciphertext($option_value)) {
                        continue;
                    }
                    update_option($option_name, self::encrypt_if_needed($option_value), false);
                    ++$migrated;
                }
            }
        }

        update_option('cashback_options_encrypted_v1', '1', false);
        self::write_audit_log(
            'option_encryption_migration_v1',
            0,
            'cashback_encryption',
            null,
            array( 'migrated_count' => $migrated )
        );
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

        if (!is_object($wpdb)) {
            // wpdb ещё не инициализирован (ранний хук / тест без БД). Audit некритичен — тихо выходим.
            return;
        }

        $table = $wpdb->prefix . 'cashback_audit_log';

        $wpdb->insert(
            $table,
            array(
                'action'      => $action,
                'actor_id'    => $actor_id,
                'entity_type' => $entity_type,
                'entity_id'   => $entity_id,
                'ip_address'  => self::get_client_ip(),
                'user_agent'  => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : null,
                'details'     => $extra_details ? wp_json_encode(self::redact_audit_details($extra_details), JSON_UNESCAPED_UNICODE) : null,
                'created_at'  => current_time('mysql'),
            ),
            array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Редактирует чувствительные поля в details перед записью в аудит-лог.
     *
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private static function redact_audit_details( array $details ): array {
        $sensitive_keys = array(
            'account',
            'payout_account',
            'full_name',
            'card',
            'pan',
            'cvv',
            'token',
            'secret',
            'password',
            'pwd',
            'api_key',
            'apikey',
            'encrypted_details',
            'auth_tag',
            'iv',
            'nonce',
        );
        foreach ($details as $key => $value) {
            if (is_array($value)) {
                $details[ $key ] = self::redact_audit_details($value);
                continue;
            }
            if (in_array(strtolower((string) $key), $sensitive_keys, true)) {
                $details[ $key ] = '[REDACTED]';
            }
        }
        return $details;
    }

    /**
     * Получает IP-адрес клиента.
     *
     * Прокси-заголовки читаются ТОЛЬКО если REMOTE_ADDR в CASHBACK_TRUSTED_PROXIES.
     * Из прокси-заголовков принимаются только публичные IP (не приватные/зарезервированные),
     * чтобы предотвратить спуфинг через X-Forwarded-For: 10.0.0.1.
     */
    public static function get_client_ip(): string {
        // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- REMOTE_ADDR set by webserver from TCP connection, not client-controlled; per-request only.
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
