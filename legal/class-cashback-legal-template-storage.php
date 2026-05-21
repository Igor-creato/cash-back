<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Legal_Template_Storage
 *
 * DAO для wp_cashback_legal_template_versions — хранилища текстов юр.документов
 * с историей версий. Замена статичных PHP-шаблонов: после первого редактирования
 * через админку текст хранится в БД, PHP-файл остаётся seed-источником и
 * fallback'ом для свежеустановленных инстансов.
 *
 * Инвариант: на тип может быть 0 или 1 строка status='draft' и 0 или 1 строка
 * status='published'. Все ранее опубликованные → status='superseded'. Журнал
 * согласий wp_cashback_consent_log хранит {consent_type, document_version,
 * document_hash}; сопоставление с конкретным текстом всегда выполнимо через
 * get_by_version().
 *
 * @since 1.7.0 (UI редактирования юр.документов)
 */
class Cashback_Legal_Template_Storage {

    public const TABLE_SLUG = 'cashback_legal_template_versions';

    public const DB_VERSION_OPTION  = 'cashback_legal_template_db_version';
    public const CURRENT_DB_VERSION = '1.0';

    public const STATUS_DRAFT      = 'draft';
    public const STATUS_PUBLISHED  = 'published';
    public const STATUS_SUPERSEDED = 'superseded';

    public const IDEMPOTENCY_SCOPE      = 'legal_template_publish';
    public const IDEMPOTENCY_TTL        = 600;
    public const ACTION_BUMP_BATCH_HOOK = 'cashback_legal_bump_batch';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    /**
     * DDL: создание таблицы wp_cashback_legal_template_versions.
     *
     * Идемпотентно через CREATE TABLE IF NOT EXISTS + post-verify SHOW COLUMNS.
     * Raw $wpdb->query (НЕ prepare с %i) — DDL с non-ASCII COMMENT'ами хрупок
     * на prepare (см. memory feedback_alter_table_no_prepare).
     *
     * Charset: текстовое body — utf8mb4_unicode_520_ci, hash/version — utf8mb4_bin
     * (binary-safe equality для SHA-256 и semver).
     */
    public static function install_table(): void {
        global $wpdb;
        $table = self::table_name();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL: имя таблицы из $wpdb->prefix, не user-controlled.
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `consent_type` VARCHAR(50) NOT NULL COLLATE utf8mb4_bin,
            `version` VARCHAR(20) NOT NULL COLLATE utf8mb4_bin,
            `body_html` LONGTEXT NOT NULL COLLATE utf8mb4_unicode_520_ci,
            `body_hash` CHAR(64) NOT NULL COLLATE utf8mb4_bin,
            `status` ENUM('draft','published','superseded') NOT NULL,
            `created_at` DATETIME NOT NULL,
            `created_by` BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            `published_at` DATETIME NULL DEFAULT NULL,
            `published_by` BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_type_version` (`consent_type`, `version`),
            KEY `idx_type_status` (`consent_type`, `status`),
            KEY `idx_consent_type` (`consent_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Версии текстов юр.документов с историей publish/superseded';";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL with $wpdb->prefix.
        $result = $wpdb->query($sql);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($result === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log('[Cashback Legal Template] Failed to create table: ' . $wpdb->last_error);
            return;
        }

        // Post-verify: убеждаемся что критичные колонки на месте (защита от
        // частичной DDL при странных прерываниях/permissions).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW COLUMNS с table-name из $wpdb->prefix; имя таблицы не user-controlled.
        $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
        $names   = array_column(is_array($columns) ? $columns : array(), 'Field');
        $required = array( 'id', 'consent_type', 'version', 'body_html', 'body_hash', 'status', 'created_at', 'published_at' );
        foreach ($required as $col) {
            if (!in_array($col, $names, true)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
                error_log('[Cashback Legal Template] Missing column after install: ' . $col);
                return;
            }
        }

        update_option(self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION, false);
    }

    /**
     * Runtime-миграция (вызывается из CashbackPlugin::maybe_run_migrations).
     *
     * Fast-path по cashback_legal_template_db_version. Безопасно вызывать на каждом init.
     */
    public static function migrate(): void {
        global $wpdb;
        $table  = self::table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepare ниже.
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

        $current = (string) get_option(self::DB_VERSION_OPTION, '0.0');
        if ($exists && version_compare($current, self::CURRENT_DB_VERSION, '>=')) {
            return;
        }

        if (!$exists && version_compare($current, self::CURRENT_DB_VERSION, '>=')) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log('[Cashback Legal Template] db_version=' . $current . ' but table missing — forcing install_table');
        }

        self::install_table();
    }

    /**
     * Идемпотентно создаёт published копией PHP-шаблона если на тип нет строки
     * published. Безопасен: повторный вызов — no-op.
     *
     * Версия для seed выбирается так:
     *   1) authoritative значение из опции cashback_legal_consent_versions (если есть);
     *   2) иначе "1.0.0".
     *
     * Если выбранная версия уже занята в БД (например осталась superseded-запись
     * v1.0.0 после прошлых bump'ов, а published-строки нет — историческая
     * рассинхронизация опции и таблицы) — бампаем major до первой свободной,
     * чтобы не словить UNIQUE (consent_type, version) на INSERT.
     */
    public static function seed_if_missing( string $type ): void {
        if (!self::is_known_type($type)) {
            return;
        }
        if (self::find_row_by_status($type, self::STATUS_PUBLISHED) !== null) {
            return;
        }

        $body = self::php_template_body($type);
        if ($body === '') {
            return;
        }

        $version = '1.0.0';
        if (class_exists('Cashback_Legal_Documents')) {
            $opt_ver = (string) Cashback_Legal_Documents::get_active_version($type);
            if ($opt_ver !== '') {
                $version = $opt_ver;
            }
        }

        $existing_versions = self::all_versions($type);
        while (in_array($version, $existing_versions, true)) {
            $version = self::bump_major($version);
        }

        self::insert_row(array(
            'consent_type' => $type,
            'version'      => $version,
            'body_html'    => $body,
            'body_hash'    => hash('sha256', $body),
            'status'       => self::STATUS_PUBLISHED,
            'created_at'   => self::utc_now(),
            'created_by'   => self::current_user_id_or_null(),
            'published_at' => self::utc_now(),
            'published_by' => self::current_user_id_or_null(),
        ));

        // Подтверждаем что published-строка реально появилась (insert_row молча
        // глотает $wpdb->insert ошибки). Только тогда синхронизируем опцию —
        // иначе оставляем как есть, дальнейшие seed-вызовы попробуют снова.
        if (self::find_row_by_status($type, self::STATUS_PUBLISHED) === null) {
            return;
        }

        // Sync опции: если seed зашёл по bumpup-ветке (опция показывала версию,
        // которой нет среди existing published — например залипло после ручного
        // rollback'а), синхронизируем опцию с реально вставленной версией.
        if (class_exists('Cashback_Legal_Documents')) {
            $current_opt = (string) Cashback_Legal_Documents::get_active_version($type);
            if ($current_opt !== $version) {
                $opt = get_option('cashback_legal_consent_versions', array());
                if (!is_array($opt)) {
                    $opt = array();
                }
                $opt[ $type ] = $version;
                update_option('cashback_legal_consent_versions', $opt);
            }
        }
    }

    /**
     * @return list<string> Все версии всех статусов для типа (исторические +
     *                     текущие), для проверки UNIQUE-конфликта перед INSERT.
     */
    private static function all_versions( string $type ): array {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_col')) {
            return array();
        }
        $table = self::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Имя таблицы из $wpdb->prefix.
        $sql = $wpdb->prepare(
            "SELECT version FROM {$table} WHERE consent_type = %s",
            $type
        );
        // phpcs:enable
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql собран через $wpdb->prepare выше.
        $rows = $wpdb->get_col($sql);
        return is_array($rows) ? array_values(array_map('strval', $rows)) : array();
    }

    private static function bump_major( string $semver ): string {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $semver, $m)) {
            return ((int) $m[1] + 1) . '.0.0';
        }
        return '2.0.0';
    }

    /**
     * Возвращает body_html текущего published, либо null если нет.
     * Caller'у (Cashback_Legal_Documents::load_template) делать fallback на PHP.
     */
    public static function get_active_body( string $type ): ?string {
        $row = self::find_row_by_status($type, self::STATUS_PUBLISHED);
        if ($row === null) {
            return null;
        }
        return isset($row['body_html']) ? (string) $row['body_html'] : null;
    }

    /**
     * Возвращает текущий draft (если есть) или null.
     *
     * @return array<string, mixed>|null
     */
    public static function get_draft( string $type ): ?array {
        return self::find_row_by_status($type, self::STATUS_DRAFT);
    }

    /**
     * Сохранить draft. Поведение:
     *   - draft не существует → INSERT
     *   - существует, body совпадает → no-op
     *   - существует, body отличается → UPDATE существующей строки
     *
     * @return array<string, mixed>|WP_Error  {id, hash, body_html, status, created_at} либо WP_Error.
     */
    public static function save_draft( string $type, string $body, int $user_id ) {
        if (!self::is_known_type($type)) {
            return new WP_Error('unknown_type', 'Unknown consent_type: ' . $type);
        }

        $hash    = hash('sha256', $body);
        $existing = self::find_row_by_status($type, self::STATUS_DRAFT);
        $now     = self::utc_now();

        if ($existing === null) {
            self::insert_row(array(
                'consent_type' => $type,
                'version'      => '0.0.0-draft',
                'body_html'    => $body,
                'body_hash'    => $hash,
                'status'       => self::STATUS_DRAFT,
                'created_at'   => $now,
                'created_by'   => $user_id > 0 ? $user_id : null,
            ));
            global $wpdb;
            return array(
                'id'         => (int) $wpdb->insert_id,
                'hash'       => $hash,
                'body_html'  => $body,
                'status'     => self::STATUS_DRAFT,
                'created_at' => $now,
            );
        }

        if (($existing['body_hash'] ?? '') === $hash) {
            return array(
                'id'         => (int) ($existing['id'] ?? 0),
                'hash'       => $hash,
                'body_html'  => $body,
                'status'     => self::STATUS_DRAFT,
                'created_at' => (string) ($existing['created_at'] ?? $now),
            );
        }

        self::update_row(
            array(
                'body_html'  => $body,
                'body_hash'  => $hash,
                'created_at' => $now,
                'created_by' => $user_id > 0 ? $user_id : null,
            ),
            array( 'id' => (int) ($existing['id'] ?? 0) )
        );

        return array(
            'id'         => (int) ($existing['id'] ?? 0),
            'hash'       => $hash,
            'body_html'  => $body,
            'status'     => self::STATUS_DRAFT,
            'created_at' => $now,
        );
    }

    /**
     * Атомарная публикация draft → новая major-версия.
     *
     * 1. Idempotency claim по (scope, user_id, idempotency_key) — replay
     *    того же запроса возвращает stored result без побочных эффектов.
     * 2. Optimistic concurrency: caller передаёт hash текущего published; если
     *    БД содержит другой hash — другой админ опубликовал параллельно,
     *    отвечаем 'concurrent_modification'.
     * 3. Validation через Cashback_Legal_Template_Validator::validate_for_publish.
     * 4. В транзакции: старый published → superseded, draft → published с
     *    bumped major-version и published_at/by.
     * 5. После COMMIT: audit log + wp_schedule_single_event для batch
     *    superseded в журнале согласий (использует существующий cron-callback,
     *    что и handle_bump_version).
     *
     * @return array{old_version:string, new_version:string, body_hash:string, published_at:string}|WP_Error
     */
    public static function publish_draft( string $type, int $user_id, string $idempotency_key, string $expected_published_hash ) {
        if (!self::is_known_type($type)) {
            return new WP_Error('unknown_type', 'Неизвестный тип документа: ' . $type);
        }
        if ($idempotency_key === '') {
            return new WP_Error('idempotency_key_required', 'Idempotency key обязателен.');
        }

        $claimed = Cashback_Idempotency::claim(self::IDEMPOTENCY_SCOPE, $user_id, $idempotency_key, self::IDEMPOTENCY_TTL);
        if (!$claimed) {
            $stored = Cashback_Idempotency::get_stored_result(self::IDEMPOTENCY_SCOPE, $user_id, $idempotency_key);
            if (is_array($stored)) {
                return $stored;
            }
            return new WP_Error('idempotency_busy', 'Параллельный запрос ещё не завершён.');
        }

        global $wpdb;

        try {
            $wpdb->query('START TRANSACTION');

            $draft = self::find_row_by_status($type, self::STATUS_DRAFT);
            if ($draft === null) {
                $wpdb->query('ROLLBACK');
                Cashback_Idempotency::forget(self::IDEMPOTENCY_SCOPE, $user_id, $idempotency_key);
                return new WP_Error('no_draft', 'Нет draft для публикации.');
            }

            $published         = self::find_row_by_status($type, self::STATUS_PUBLISHED);
            $current_pub_hash  = is_array($published) ? (string) ($published['body_hash'] ?? '') : '';

            if ($current_pub_hash !== $expected_published_hash) {
                $wpdb->query('ROLLBACK');
                Cashback_Idempotency::forget(self::IDEMPOTENCY_SCOPE, $user_id, $idempotency_key);
                return new WP_Error(
                    'concurrent_modification',
                    'Текст уже изменён другим админом. Перезагрузите страницу и попробуйте снова.'
                );
            }

            $body       = (string) ($draft['body_html'] ?? '');
            $validation = Cashback_Legal_Template_Validator::validate_for_publish($type, $body);
            if ($validation instanceof WP_Error) {
                $wpdb->query('ROLLBACK');
                Cashback_Idempotency::forget(self::IDEMPOTENCY_SCOPE, $user_id, $idempotency_key);
                return $validation;
            }

            $old_version = Cashback_Legal_Documents::get_active_version($type);
            $new_version = Cashback_Legal_Documents::bump_major($type);
            $now         = self::utc_now();
            $body_hash   = (string) ($draft['body_hash'] ?? hash('sha256', $body));

            if (is_array($published)) {
                $wpdb->update(
                    self::table_name(),
                    array( 'status' => self::STATUS_SUPERSEDED ),
                    array( 'id' => (int) ($published['id'] ?? 0) ),
                    array( '%s' ),
                    array( '%d' )
                );
            }

            $wpdb->update(
                self::table_name(),
                array(
                    'status'       => self::STATUS_PUBLISHED,
                    'version'      => $new_version,
                    'published_at' => $now,
                    'published_by' => $user_id > 0 ? $user_id : null,
                ),
                array( 'id' => (int) ($draft['id'] ?? 0) ),
                array( '%s', '%s', '%s', '%d' ),
                array( '%d' )
            );

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            Cashback_Idempotency::forget(self::IDEMPOTENCY_SCOPE, $user_id, $idempotency_key);
            return new WP_Error('publish_failed', $e->getMessage());
        }

        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'legal_template_published',
                $user_id,
                'legal_template_versions',
                (int) ($draft['id'] ?? 0),
                array(
                    'consent_type' => $type,
                    'from_version' => $old_version,
                    'to_version'   => $new_version,
                    'body_hash'    => $body_hash,
                    'body_size'    => strlen($body),
                )
            );
        }

        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(
                time() + 30,
                self::ACTION_BUMP_BATCH_HOOK,
                array( $type, $new_version )
            );
        }

        $result = array(
            'old_version'  => $old_version,
            'new_version'  => $new_version,
            'body_hash'    => $body_hash,
            'published_at' => $now,
        );

        Cashback_Idempotency::store_result(
            self::IDEMPOTENCY_SCOPE,
            $user_id,
            $idempotency_key,
            $result,
            self::IDEMPOTENCY_TTL
        );

        return $result;
    }

    /**
     * Удалить draft. Возвращает true если был draft, false если не было.
     */
    public static function discard_draft( string $type ): bool {
        if (!self::is_known_type($type)) {
            return false;
        }
        if (self::find_row_by_status($type, self::STATUS_DRAFT) === null) {
            return false;
        }

        global $wpdb;
        $wpdb->delete(
            self::table_name(),
            array(
                'consent_type' => $type,
                'status'       => self::STATUS_DRAFT,
            ),
            array( '%s', '%s' )
        );
        return true;
    }

    // ────────────────────────────────────────────────────────────
    // private
    // ────────────────────────────────────────────────────────────

    private static function is_known_type( string $type ): bool {
        if (!class_exists('Cashback_Legal_Documents')) {
            return false;
        }
        return in_array($type, Cashback_Legal_Documents::all_types(), true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function find_row_by_status( string $type, string $status ): ?array {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_row')) {
            return null;
        }
        $table = self::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Имя таблицы из $wpdb->prefix; values через prepare ниже.
        $sql = $wpdb->prepare(
            "SELECT id, consent_type, version, body_html, body_hash, status, created_at, created_by, published_at, published_by
             FROM {$table}
             WHERE consent_type = %s AND status = %s
             LIMIT 1",
            $type,
            $status
        );
        // phpcs:enable
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql собран через $wpdb->prepare выше.
        $row = $wpdb->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function insert_row( array $data ): void {
        global $wpdb;
        $wpdb->insert(self::table_name(), $data);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    private static function update_row( array $data, array $where ): void {
        global $wpdb;
        $wpdb->update(self::table_name(), $data, $where);
    }

    private static function php_template_body( string $type ): string {
        if (!class_exists('Cashback_Legal_Documents')) {
            return '';
        }
        // ВАЖНО: вызываем raw метод чтения PHP-файла, минуя DB-first интерсепт,
        // чтобы избежать рекурсии и получить именно baseline.
        return self::raw_php_template($type);
    }

    private static function raw_php_template( string $type ): string {
        $meta = Cashback_Legal_Documents::get_meta($type);
        if (empty($meta['template_path'])) {
            return '';
        }
        $plugin_root = dirname(__DIR__);
        $path        = $plugin_root . '/' . $meta['template_path'];
        if (!file_exists($path)) {
            return '';
        }
        $content = include $path;
        return is_string($content) ? $content : '';
    }

    private static function utc_now(): string {
        return gmdate('Y-m-d H:i:s');
    }

    private static function current_user_id_or_null(): ?int {
        if (!function_exists('get_current_user_id')) {
            return null;
        }
        $uid = (int) get_current_user_id();
        return $uid > 0 ? $uid : null;
    }
}
