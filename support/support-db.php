<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс для работы с базой данных модуля поддержки
 */
class Cashback_Support_DB {

    /**
     * Создание таблиц модуля поддержки
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset_collate = 'ENGINE=InnoDB ' . $wpdb->get_charset_collate();
        $tickets_table   = $wpdb->prefix . 'cashback_support_tickets';
        $messages_table  = $wpdb->prefix . 'cashback_support_messages';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_tickets = "CREATE TABLE IF NOT EXISTS `{$tickets_table}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) unsigned NOT NULL,
            `subject` varchar(255) NOT NULL,
            `priority` enum('urgent','normal','not_urgent') NOT NULL DEFAULT 'not_urgent',
            `status` enum('open','answered','closed') NOT NULL DEFAULT 'open',
            `related_type` enum('cashback_tx','affiliate_accrual','payout') DEFAULT NULL,
            `related_id` bigint(20) unsigned DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `closed_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_status` (`status`),
            KEY `idx_priority` (`priority`),
            KEY `idx_status_updated` (`status`, `updated_at`),
            KEY `idx_related` (`related_type`, `related_id`)
        ) {$charset_collate};";

        $sql_messages = "CREATE TABLE IF NOT EXISTS `{$messages_table}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `ticket_id` bigint(20) unsigned NOT NULL,
            `user_id` bigint(20) unsigned NOT NULL,
            `message` text NOT NULL,
            `is_admin` tinyint(1) NOT NULL DEFAULT 0,
            `is_read` tinyint(1) NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ticket_id` (`ticket_id`),
            KEY `idx_is_read_admin` (`is_admin`, `is_read`)
        ) {$charset_collate};";

        $attachments_table = $wpdb->prefix . 'cashback_support_attachments';

        $sql_attachments = "CREATE TABLE IF NOT EXISTS `{$attachments_table}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `message_id` bigint(20) unsigned NOT NULL,
            `ticket_id` bigint(20) unsigned NOT NULL,
            `user_id` bigint(20) unsigned NOT NULL,
            `file_name` varchar(255) NOT NULL,
            `stored_name` varchar(255) NOT NULL,
            `file_size` bigint(20) unsigned NOT NULL,
            `mime_type` varchar(100) NOT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_message_id` (`message_id`),
            KEY `idx_ticket_id` (`ticket_id`)
        ) {$charset_collate};";

        dbDelta($sql_tickets);
        dbDelta($sql_messages);
        dbDelta($sql_attachments);

        // Миграция для существующих установок: добавляем колонки и индекс привязки тикета
        // к сущности (покупка/начисление кэшбэка или партнёрское начисление).
        $has_related_type = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'related_type'",
            $tickets_table
        ));
        if (!$has_related_type) {
            $wpdb->query($wpdb->prepare(
                "ALTER TABLE %i
                ADD COLUMN `related_type` ENUM('cashback_tx','affiliate_accrual','payout') DEFAULT NULL AFTER `status`,
                ADD COLUMN `related_id` BIGINT(20) UNSIGNED DEFAULT NULL AFTER `related_type`,
                ADD KEY `idx_related` (`related_type`, `related_id`)",
                $tickets_table
            ));
        } else {
            // Расширяем ENUM на случай, если колонка создавалась старой версией без 'payout'.
            $enum_def = $wpdb->get_var($wpdb->prepare(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'related_type'",
                $tickets_table
            ));
            if ($enum_def && strpos((string) $enum_def, "'payout'") === false) {
                $wpdb->query($wpdb->prepare(
                    "ALTER TABLE %i
                    MODIFY COLUMN `related_type` ENUM('cashback_tx','affiliate_accrual','payout') DEFAULT NULL",
                    $tickets_table
                ));
            }
        }

        // Добавляем внешние ключи после создания таблиц
        $fk_exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_NAME = 'fk_support_ticket_user'
             AND TABLE_SCHEMA = DATABASE()"
        );
        if (!$fk_exists) {
            $users_table = $wpdb->users;
            $wpdb->query($wpdb->prepare(
                'ALTER TABLE %i
                ADD CONSTRAINT `fk_support_ticket_user`
                FOREIGN KEY (`user_id`) REFERENCES %i (`ID`) ON DELETE CASCADE',
                $tickets_table,
                $users_table
            ));
        }

        $fk_exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_NAME = 'fk_support_message_ticket'
             AND TABLE_SCHEMA = DATABASE()"
        );
        if (!$fk_exists) {
            $users_table = $wpdb->users;
            $wpdb->query($wpdb->prepare(
                'ALTER TABLE %i
                ADD CONSTRAINT `fk_support_message_ticket`
                FOREIGN KEY (`ticket_id`) REFERENCES %i (`id`) ON DELETE CASCADE',
                $messages_table,
                $tickets_table
            ));
            $wpdb->query($wpdb->prepare(
                'ALTER TABLE %i
                ADD CONSTRAINT `fk_support_message_user`
                FOREIGN KEY (`user_id`) REFERENCES %i (`ID`) ON DELETE CASCADE',
                $messages_table,
                $users_table
            ));
        }

        // FK для таблицы вложений
        $fk_exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_NAME = 'fk_attachment_message'
             AND TABLE_SCHEMA = DATABASE()"
        );
        if (!$fk_exists) {
            $wpdb->query($wpdb->prepare(
                'ALTER TABLE %i
                ADD CONSTRAINT `fk_attachment_message`
                FOREIGN KEY (`message_id`) REFERENCES %i (`id`) ON DELETE CASCADE',
                $attachments_table,
                $messages_table
            ));
            $wpdb->query($wpdb->prepare(
                'ALTER TABLE %i
                ADD CONSTRAINT `fk_attachment_ticket`
                FOREIGN KEY (`ticket_id`) REFERENCES %i (`id`) ON DELETE CASCADE',
                $attachments_table,
                $tickets_table
            ));
        }
    }

    /**
     * Проверить, включен ли модуль поддержки
     */
    public static function is_module_enabled(): bool {
        return (bool) get_option('cashback_support_module_enabled', 0);
    }

    /**
     * Включить/выключить модуль поддержки
     */
    public static function set_module_enabled( bool $enabled ): void {
        update_option('cashback_support_module_enabled', $enabled ? 1 : 0);
    }

    // ========= Настройки вложений =========

    /**
     * Включены ли вложения
     */
    public static function is_attachments_enabled(): bool {
        return (bool) get_option('cashback_support_attachments_enabled', 1);
    }

    /**
     * Максимальный размер файла в КБ
     */
    public static function get_max_file_size(): int {
        return (int) get_option('cashback_support_max_file_size', 5120);
    }

    /**
     * Максимальное количество файлов на одно сообщение
     */
    public static function get_max_files_per_message(): int {
        return (int) get_option('cashback_support_max_files_per_message', 3);
    }

    /**
     * Допустимые расширения файлов
     *
     * @return string[]
     */
    public static function get_allowed_extensions(): array {
        $raw = get_option('cashback_support_allowed_extensions', 'jpg,jpeg,png,gif,pdf,doc,docx,txt');
        return array_map('trim', explode(',', strtolower((string) $raw)));
    }

    /**
     * Сохранить настройки вложений
     *
     * @param array<string, mixed> $settings
     */
    public static function save_attachment_settings( array $settings ): void {
        if (isset($settings['enabled'])) {
            update_option('cashback_support_attachments_enabled', $settings['enabled'] ? 1 : 0);
        }
        if (isset($settings['max_file_size'])) {
            update_option('cashback_support_max_file_size', max(1, absint($settings['max_file_size'])));
        }
        if (isset($settings['max_files_per_message'])) {
            update_option('cashback_support_max_files_per_message', max(1, min(10, absint($settings['max_files_per_message']))));
        }
        if (isset($settings['allowed_extensions'])) {
            $extensions = sanitize_text_field($settings['allowed_extensions']);
            $extensions = preg_replace('/[^a-z0-9,]/', '', strtolower($extensions));
            update_option('cashback_support_allowed_extensions', $extensions);
        }
    }

    // ========= Данные вложений =========

    /**
     * Записать вложение в БД
     *
     * @param array<string, mixed> $data
     */
    public static function record_attachment( array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_support_attachments';

        $wpdb->insert($table, array(
            'message_id'  => $data['message_id'],
            'ticket_id'   => $data['ticket_id'],
            'user_id'     => $data['user_id'],
            'file_name'   => $data['file_name'],
            'stored_name' => $data['stored_name'],
            'file_size'   => $data['file_size'],
            'mime_type'   => $data['mime_type'],
        ), array( '%d', '%d', '%d', '%s', '%s', '%d', '%s' ));

        return (int) $wpdb->insert_id;
    }

    /**
     * Получить одно вложение по ID
     */
    public static function get_attachment( int $attachment_id ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_support_attachments';

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d',
            $table,
            $attachment_id
        ));

        return $row ?: null;
    }

    /**
     * Получить вложения для набора сообщений (batch), сгруппированные по message_id
     *
     * @param int[] $message_ids
     * @return array<int, object[]>
     */
    public static function get_attachments_for_messages( array $message_ids ): array {
        if (empty($message_ids)) {
            return array();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cashback_support_attachments';

        $placeholders = implode(',', array_fill(0, count($message_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders — статический список %d из array_fill, длина по count($message_ids); значения биндятся спред-оператором.
        $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE message_id IN ({$placeholders}) ORDER BY message_id, id ASC", $table, ...$message_ids));

        $grouped = array();
        foreach ($results as $row) {
            $grouped[ (int) $row->message_id ][] = $row;
        }
        return $grouped;
    }

    // ========= Файловые операции =========

    /**
     * Путь к директории загрузок для тикета
     */
    public static function get_upload_dir( int $ticket_id ): string {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/cashback-support/' . $ticket_id;
    }

    /**
     * Создать базовую директорию для вложений и защитить её.
     * Вызывается при активации плагина.
     */
    public static function ensure_upload_dir(): void {
        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'] . '/cashback-support';

        if (!is_dir($base_dir)) {
            wp_mkdir_p($base_dir);
        }

        self::write_protection_files($base_dir);
    }

    /**
     * Удалить файлы тикета с диска
     */
    public static function delete_ticket_files( int $ticket_id ): void {
        $dir = self::get_upload_dir($ticket_id);
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Intentional silence; best-effort cleanup during uninstall.
                    @unlink($file);
                }
            }
        }
        // Удаляем защитные файлы директории
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Intentional silence; best-effort cleanup during uninstall.
        @unlink($dir . '/.htaccess');
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Intentional silence; best-effort cleanup during uninstall.
        @unlink($dir . '/web.config');
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Intentional silence; best-effort cleanup during uninstall.
        @unlink($dir . '/index.php');
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Intentional silence; best-effort cleanup during uninstall.
        @unlink($dir . '/index.html');
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Intentional silence; best-effort cleanup during uninstall.
        @rmdir($dir);
    }

    /**
     * Защита директории загрузок от прямого доступа.
     *
     * Универсальная защита для любого веб-сервера:
     * - .htaccess (Apache)
     * - web.config (IIS)
     * - index.php + index.html (предотвращение листинга директорий)
     * - Файлы хранятся без расширений (nginx и др. не определят MIME)
     */
    private static function protect_upload_directory( string $dir ): void {
        self::write_protection_files($dir);

        // Защита родительской директории
        $parent = dirname($dir);
        self::write_protection_files($parent);
    }

    /**
     * Записать защитные файлы в указанную директорию
     */
    private static function write_protection_files( string $dir ): void {
        // Apache: запрет доступа
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }

        // IIS: запрет доступа
        $webconfig = $dir . '/web.config';
        if (!file_exists($webconfig)) {
            $webconfig_content = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
                . '<configuration>' . "\n"
                . '  <system.webServer>' . "\n"
                . '    <authorization>' . "\n"
                . '      <deny users="*" />' . "\n"
                . '    </authorization>' . "\n"
                . '  </system.webServer>' . "\n"
                . '</configuration>' . "\n";
            file_put_contents($webconfig, $webconfig_content);
        }

        // Предотвращение листинга директорий
        $index_php = $dir . '/index.php';
        if (!file_exists($index_php)) {
            file_put_contents($index_php, "<?php\n// Silence is golden.\n");
        }

        $index_html = $dir . '/index.html';
        if (!file_exists($index_html)) {
            file_put_contents($index_html, '');
        }
    }

    /**
     * Карта допустимых MIME-типов по расширениям
     *
     * @return array<string, string[]>
     */
    private static function get_allowed_mimes(): array {
        return array(
            'jpg'  => array( 'image/jpeg' ),
            'jpeg' => array( 'image/jpeg' ),
            'png'  => array( 'image/png' ),
            'gif'  => array( 'image/gif' ),
            'pdf'  => array( 'application/pdf' ),
            'doc'  => array( 'application/msword' ),
            'docx' => array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ),
            'txt'  => array( 'text/plain' ),
        );
    }

    /**
     * Валидация одного файла из $_FILES
     *
     * @param array<string, mixed> $file Элемент из $_FILES
     * @return true|string true если OK, строка с ошибкой если нет
     */
    public static function validate_file( array $file ) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return 'Ошибка загрузки файла.';
        }

        if ($file['size'] === 0) {
            return 'Пустой файл не может быть загружен.';
        }

        $max_size = self::get_max_file_size() * 1024;
        if ($file['size'] > $max_size) {
            return sprintf(
                'Файл "%s" превышает максимальный размер (%s КБ).',
                esc_html($file['name']),
                number_format_i18n(self::get_max_file_size())
            );
        }

        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = self::get_allowed_extensions();
        if (!in_array($ext, $allowed, true)) {
            return sprintf(
                'Расширение "%s" не разрешено. Допустимые: %s',
                esc_html($ext),
                esc_html(implode(', ', $allowed))
            );
        }

        // Проверка MIME-типа (обязательна — без finfo загрузка запрещена)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) {
            return 'Проверка MIME-типа недоступна (расширение fileinfo отключено). Загрузка запрещена.';
        }

        $detected_mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowed_mimes = self::get_allowed_mimes();
        if (!isset($allowed_mimes[ $ext ])) {
            return sprintf(
                'Расширение "%s" не поддерживается системой загрузки.',
                esc_html($ext)
            );
        }
        if (!in_array($detected_mime, $allowed_mimes[ $ext ], true)) {
            return sprintf('Тип файла "%s" не соответствует расширению.', esc_html($file['name']));
        }

        return true;
    }

    /**
     * Обработка загрузки файла: валидация, перемещение, запись в БД
     *
     * @param array<string, mixed> $file Элемент из $_FILES
     * @return int|string ID вложения или строка ошибки
     */
    public static function handle_file_upload( array $file, int $ticket_id, int $message_id, int $user_id ) {
        // Defense-in-depth: проверяем что тикет существует и принадлежит пользователю (или это админ)
        if ($ticket_id <= 0) {
            return 'Некорректный ID тикета.';
        }
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'cashback_support_tickets';
        $ticket_owner  = $wpdb->get_var($wpdb->prepare(
            'SELECT user_id FROM %i WHERE id = %d',
            $tickets_table,
            $ticket_id
        ));
        if (!$ticket_owner) {
            return 'Тикет не найден.';
        }
        if ((int) $ticket_owner !== $user_id && !current_user_can('manage_options')) {
            return 'Нет доступа к этому тикету.';
        }

        $validation = self::validate_file($file);
        if ($validation !== true) {
            return $validation;
        }

        $dir = self::get_upload_dir($ticket_id);

        if (!is_dir($dir)) {
            if (!wp_mkdir_p($dir)) {
                return 'Не удалось создать директорию для загрузки.';
            }
            self::protect_upload_directory($dir);
        }

        // Файлы хранятся БЕЗ расширений — универсальная защита от прямого доступа:
        // nginx/IIS не смогут определить MIME-тип и не исполнят PHP/не отрендерят HTML
        $stored_name = bin2hex(random_bytes(16));
        $dest_path   = $dir . '/' . $stored_name;

        if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
            return 'Не удалось сохранить файл.';
        }

        // MIME-тип определяем через finfo (по содержимому файла), а не из $_FILES['type'] (браузер)
        $detected_mime = '';
        $finfo         = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected_mime = finfo_file($finfo, $dest_path);
            finfo_close($finfo);
        }
        if (!$detected_mime) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Intentional silence; best-effort cleanup of partial upload.
            @unlink($dest_path);
            return 'Не удалось определить тип файла. Убедитесь, что расширение fileinfo включено.';
        }

        $attachment_id = self::record_attachment(array(
            'message_id'  => $message_id,
            'ticket_id'   => $ticket_id,
            'user_id'     => $user_id,
            'file_name'   => sanitize_file_name($file['name']),
            'stored_name' => $stored_name,
            'file_size'   => $file['size'],
            'mime_type'   => sanitize_text_field($detected_mime),
        ));

        if (!$attachment_id) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Intentional silence; best-effort cleanup of orphaned upload.
            @unlink($dest_path);
            return 'Не удалось записать данные о файле.';
        }

        return $attachment_id;
    }

    /**
     * Отдача файла с проверкой прав доступа
     */
    public static function serve_file( int $attachment_id, int $requesting_user_id, bool $is_admin = false ): void {
        // Rate limiting: максимум 10 скачиваний в минуту на пользователя
        $dl_rate_key = 'cb_file_dl_' . $requesting_user_id;
        $dl_count    = (int) get_transient($dl_rate_key);
        if ($dl_count >= 10) {
            wp_die('Слишком много запросов на скачивание. Попробуйте через минуту.', 'Ошибка', array( 'response' => 429 ));
        }
        set_transient($dl_rate_key, $dl_count + 1, 60);

        $attachment = self::get_attachment($attachment_id);
        if (!$attachment) {
            wp_die('Файл не найден.', 'Ошибка', array( 'response' => 404 ));
        }

        if (!$is_admin) {
            global $wpdb;
            $tickets_table = $wpdb->prefix . 'cashback_support_tickets';
            $owner         = $wpdb->get_var($wpdb->prepare(
                'SELECT user_id FROM %i WHERE id = %d',
                $tickets_table,
                $attachment->ticket_id
            ));
            if ((int) $owner !== $requesting_user_id) {
                wp_die('У вас нет доступа к этому файлу.', 'Доступ запрещён', array( 'response' => 403 ));
            }
        }

        // Defence-in-depth: stored_name должен быть hex-строкой из 32 символов (bin2hex(random_bytes(16)))
        if (!preg_match('/^[a-f0-9]{32}$/', $attachment->stored_name)) {
            wp_die('Некорректное имя файла.', 'Ошибка', array( 'response' => 400 ));
        }

        $file_path = self::get_upload_dir((int) $attachment->ticket_id) . '/' . $attachment->stored_name;
        if (!file_exists($file_path)) {
            wp_die('Файл не найден на диске.', 'Ошибка', array( 'response' => 404 ));
        }

        // Whitelist безопасных MIME-типов для Content-Type.
        // Если MIME из БД не в списке — отдаём как application/octet-stream
        // чтобы предотвратить inline-рендеринг потенциально опасного контента.
        $safe_mimes   = array(
            'image/jpeg',
			'image/png',
			'image/gif',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
        );
        $content_type = in_array($attachment->mime_type, $safe_mimes, true)
            ? $attachment->mime_type
            : 'application/octet-stream';

        // Санитизация имени файла для заголовка Content-Disposition
        // rawurlencode предотвращает инъекцию через кавычки, переводы строк, точки с запятой, null-байты
        $safe_filename = sanitize_file_name($attachment->file_name);

        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . $safe_filename . '"; filename*=UTF-8\'\'' . rawurlencode($safe_filename));
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: private, no-cache, no-store, must-revalidate');
        header('X-Content-Type-Options: nosniff');

        readfile($file_path);
        exit;
    }

    /**
     * Получить количество тикетов с непрочитанными сообщениями от пользователей
     */
    public static function get_unread_tickets_count(): int {
        global $wpdb;

        $tickets_table  = $wpdb->prefix . 'cashback_support_tickets';
        $messages_table = $wpdb->prefix . 'cashback_support_messages';

        $count = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(DISTINCT t.id)
             FROM %i t
             INNER JOIN %i m ON t.id = m.ticket_id
             WHERE m.is_admin = 0
             AND m.is_read = 0',
            $tickets_table,
            $messages_table
        ));

        return (int) $count;
    }

    /**
     * Получить количество тикетов с непрочитанными ответами от админа для конкретного пользователя
     */
    public static function get_unread_admin_replies_count( int $user_id ): int {
        global $wpdb;

        $tickets_table  = $wpdb->prefix . 'cashback_support_tickets';
        $messages_table = $wpdb->prefix . 'cashback_support_messages';

        $count = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(DISTINCT t.id)
             FROM %i t
             INNER JOIN %i m ON t.id = m.ticket_id
             WHERE t.user_id = %d
             AND m.is_admin = 1
             AND m.is_read = 0',
            $tickets_table,
            $messages_table,
            $user_id
        ));

        return (int) $count;
    }

    /**
     * Форматировать номер тикета для отображения
     */
    public static function format_ticket_number( int $ticket_id ): string {
        return '№' . str_pad((string) $ticket_id, 3, '0', STR_PAD_LEFT);
    }

    // ========= Привязка тикета к сущности =========

    /**
     * Допустимые типы привязки тикета к сущности.
     *
     * @return string[]
     */
    public static function get_allowed_related_types(): array {
        return array( 'cashback_tx', 'affiliate_accrual', 'payout' );
    }

    /**
     * Человекочитаемая метка для типа привязки.
     */
    public static function get_related_type_label( string $type ): string {
        $labels = array(
            'cashback_tx'       => 'Покупка',
            'affiliate_accrual' => 'Партнёрское начисление',
            'payout'            => 'Выплата',
        );
        return $labels[ $type ] ?? '';
    }

    /**
     * Проверить, что сущность принадлежит пользователю.
     * Используется при создании тикета с привязкой (защита от IDOR).
     */
    public static function validate_related_ownership( int $user_id, string $type, int $entity_id ): bool {
        if ($user_id <= 0 || $entity_id <= 0) {
            return false;
        }
        if (!in_array($type, self::get_allowed_related_types(), true)) {
            return false;
        }

        global $wpdb;

        if ($type === 'cashback_tx') {
            $owner = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM `{$wpdb->prefix}cashback_transactions` WHERE id = %d",
                $entity_id
            ));
            return $owner !== null && (int) $owner === $user_id;
        }

        if ($type === 'affiliate_accrual') {
            $owner = $wpdb->get_var($wpdb->prepare(
                "SELECT referrer_id FROM `{$wpdb->prefix}cashback_affiliate_accruals` WHERE id = %d",
                $entity_id
            ));
            return $owner !== null && (int) $owner === $user_id;
        }

        if ($type === 'payout') {
            $owner = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM `{$wpdb->prefix}cashback_payout_requests` WHERE id = %d",
                $entity_id
            ));
            return $owner !== null && (int) $owner === $user_id;
        }

        return false;
    }

    /**
     * Получить краткий снимок связанной сущности для отображения в UI (ЛК и админка).
     *
     * @return array<string, mixed>|null
     */
    public static function get_related_entity( string $type, int $entity_id ): ?array {
        if ($entity_id <= 0 || !in_array($type, self::get_allowed_related_types(), true)) {
            return null;
        }

        global $wpdb;

        if ($type === 'cashback_tx') {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, reference_id, offer_name, sum_order, cashback, currency, order_status, created_at
                 FROM `{$wpdb->prefix}cashback_transactions` WHERE id = %d",
                $entity_id
            ));
            if (!$row) {
                return null;
            }
            return array(
                'type'         => 'cashback_tx',
                'id'           => (int) $row->id,
                'reference_id' => (string) $row->reference_id,
                'title'        => (string) ( $row->offer_name ?? '' ),
                'amount'       => (float) ( $row->sum_order ?? 0 ),
                'cashback'     => (float) ( $row->cashback ?? 0 ),
                'currency'     => (string) ( $row->currency ?? 'RUB' ),
                'status'       => (string) $row->order_status,
                'created_at'   => (string) $row->created_at,
            );
        }

        if ($type === 'affiliate_accrual') {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, reference_id, commission_amount, status, created_at
                 FROM `{$wpdb->prefix}cashback_affiliate_accruals` WHERE id = %d",
                $entity_id
            ));
            if (!$row) {
                return null;
            }
            return array(
                'type'         => 'affiliate_accrual',
                'id'           => (int) $row->id,
                'reference_id' => (string) $row->reference_id,
                'title'        => '',
                'amount'       => (float) $row->commission_amount,
                'cashback'     => 0.0,
                'currency'     => 'RUB',
                'status'       => (string) $row->status,
                'created_at'   => (string) $row->created_at,
            );
        }

        if ($type === 'payout') {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, reference_id, total_amount, payout_method, status, created_at
                 FROM `{$wpdb->prefix}cashback_payout_requests` WHERE id = %d",
                $entity_id
            ));
            if (!$row) {
                return null;
            }
            return array(
                'type'         => 'payout',
                'id'           => (int) $row->id,
                'reference_id' => (string) $row->reference_id,
                'title'        => (string) ( $row->payout_method ?? '' ),
                'amount'       => (float) $row->total_amount,
                'cashback'     => 0.0,
                'currency'     => 'RUB',
                'status'       => (string) $row->status,
                'created_at'   => (string) $row->created_at,
            );
        }

        return null;
    }

    /**
     * Префикс для темы тикета, сгенерированный по привязке: "[Покупка TX-XXXXXXXX]",
     * "[Покупка TU-XXXXXXXX]" (unregistered) или "[Выплата WD-XXXXXXXX]".
     */
    public static function format_related_prefix( string $type, array $entity ): string {
        $label = self::get_related_type_label($type);
        $ref   = !empty($entity['reference_id']) ? (string) $entity['reference_id'] : '#' . (int) ( $entity['id'] ?? 0 );
        if ($label === '' || $ref === '') {
            return '';
        }
        return '[' . $label . ' ' . $ref . ']';
    }

    /**
     * Удалить закрытые тикеты старше 1 месяца.
     * Сообщения удаляются каскадно через FK.
     */
    public static function delete_old_closed_tickets(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_support_tickets';

        // iter-27 F-27-003: сначала DB-delete в транзакции под row-lock, затем удаление
        // файлов с диска. Раньше файлы удалялись до DELETE — при сбое DB тикет оставался
        // в БД без вложений (потеря доказательной базы по спорам/выплатам).
        $wpdb->query('START TRANSACTION');

        $ticket_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM %i WHERE status = 'closed' AND closed_at IS NOT NULL AND closed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MONTH) FOR UPDATE",
            $table
        ));

        if (empty($ticket_ids)) {
            $wpdb->query('COMMIT');
            return 0;
        }

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM %i WHERE status = 'closed' AND closed_at IS NOT NULL AND closed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MONTH)",
            $table
        ));

        if ($deleted === false) {
            $wpdb->query('ROLLBACK');
            return 0;
        }

        $wpdb->query('COMMIT');

        // Файлы удаляем только после подтверждённого COMMIT.
        foreach ($ticket_ids as $tid) {
            self::delete_ticket_files((int) $tid);
        }

        return max(0, (int) $deleted);
    }
}

// Регистрация WP Cron хука для автоудаления
add_action('cashback_support_auto_delete_cron', function (): void {
    Cashback_Support_DB::delete_old_closed_tickets();
});
