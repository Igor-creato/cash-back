<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс для работы с базой данных модуля поддержки
 */
class Cashback_Support_DB
{
    /**
     * Создание таблиц модуля поддержки
     */
    public static function create_tables(): void
    {
        global $wpdb;

        $charset_collate = 'ENGINE=InnoDB ' . $wpdb->get_charset_collate();
        $tickets_table = $wpdb->prefix . 'cashback_support_tickets';
        $messages_table = $wpdb->prefix . 'cashback_support_messages';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_tickets = "CREATE TABLE IF NOT EXISTS `{$tickets_table}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) unsigned NOT NULL,
            `subject` varchar(255) NOT NULL,
            `priority` enum('urgent','normal','not_urgent') NOT NULL DEFAULT 'not_urgent',
            `status` enum('open','answered','closed') NOT NULL DEFAULT 'open',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `closed_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_status` (`status`),
            KEY `idx_priority` (`priority`),
            KEY `idx_status_updated` (`status`, `updated_at`)
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

        // Добавляем внешние ключи после создания таблиц
        $fk_exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_NAME = 'fk_support_ticket_user'
             AND TABLE_SCHEMA = DATABASE()"
        );
        if (!$fk_exists) {
            $wpdb->query("ALTER TABLE `{$tickets_table}`
                ADD CONSTRAINT `fk_support_ticket_user`
                FOREIGN KEY (`user_id`) REFERENCES `{$wpdb->users}` (`ID`) ON DELETE CASCADE");
        }

        $fk_exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_NAME = 'fk_support_message_ticket'
             AND TABLE_SCHEMA = DATABASE()"
        );
        if (!$fk_exists) {
            $wpdb->query("ALTER TABLE `{$messages_table}`
                ADD CONSTRAINT `fk_support_message_ticket`
                FOREIGN KEY (`ticket_id`) REFERENCES `{$tickets_table}` (`id`) ON DELETE CASCADE");
            $wpdb->query("ALTER TABLE `{$messages_table}`
                ADD CONSTRAINT `fk_support_message_user`
                FOREIGN KEY (`user_id`) REFERENCES `{$wpdb->users}` (`ID`) ON DELETE CASCADE");
        }

        // FK для таблицы вложений
        $fk_exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_NAME = 'fk_attachment_message'
             AND TABLE_SCHEMA = DATABASE()"
        );
        if (!$fk_exists) {
            $wpdb->query("ALTER TABLE `{$attachments_table}`
                ADD CONSTRAINT `fk_attachment_message`
                FOREIGN KEY (`message_id`) REFERENCES `{$messages_table}` (`id`) ON DELETE CASCADE");
            $wpdb->query("ALTER TABLE `{$attachments_table}`
                ADD CONSTRAINT `fk_attachment_ticket`
                FOREIGN KEY (`ticket_id`) REFERENCES `{$tickets_table}` (`id`) ON DELETE CASCADE");
        }
    }

    /**
     * Проверить, включен ли модуль поддержки
     */
    public static function is_module_enabled(): bool
    {
        return (bool) get_option('cashback_support_module_enabled', 0);
    }

    /**
     * Включить/выключить модуль поддержки
     */
    public static function set_module_enabled(bool $enabled): void
    {
        update_option('cashback_support_module_enabled', $enabled ? 1 : 0);
    }

    // ========= Настройки вложений =========

    /**
     * Включены ли вложения
     */
    public static function is_attachments_enabled(): bool
    {
        return (bool) get_option('cashback_support_attachments_enabled', 1);
    }

    /**
     * Максимальный размер файла в КБ
     */
    public static function get_max_file_size(): int
    {
        return (int) get_option('cashback_support_max_file_size', 5120);
    }

    /**
     * Максимальное количество файлов на одно сообщение
     */
    public static function get_max_files_per_message(): int
    {
        return (int) get_option('cashback_support_max_files_per_message', 3);
    }

    /**
     * Допустимые расширения файлов
     *
     * @return string[]
     */
    public static function get_allowed_extensions(): array
    {
        $raw = get_option('cashback_support_allowed_extensions', 'jpg,jpeg,png,gif,pdf,doc,docx,txt');
        return array_map('trim', explode(',', strtolower((string) $raw)));
    }

    /**
     * Сохранить настройки вложений
     *
     * @param array<string, mixed> $settings
     */
    public static function save_attachment_settings(array $settings): void
    {
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
    public static function record_attachment(array $data): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_support_attachments';

        $wpdb->insert($table, [
            'message_id'  => $data['message_id'],
            'ticket_id'   => $data['ticket_id'],
            'user_id'     => $data['user_id'],
            'file_name'   => $data['file_name'],
            'stored_name' => $data['stored_name'],
            'file_size'   => $data['file_size'],
            'mime_type'   => $data['mime_type'],
        ], ['%d', '%d', '%d', '%s', '%s', '%d', '%s']);

        return (int) $wpdb->insert_id;
    }

    /**
     * Получить одно вложение по ID
     */
    public static function get_attachment(int $attachment_id): ?object
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_support_attachments';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE id = %d",
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
    public static function get_attachments_for_messages(array $message_ids): array
    {
        if (empty($message_ids)) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cashback_support_attachments';

        $placeholders = implode(',', array_fill(0, count($message_ids), '%d'));
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE message_id IN ({$placeholders}) ORDER BY message_id, id ASC",
            ...$message_ids
        ));

        $grouped = [];
        foreach ($results as $row) {
            $grouped[(int) $row->message_id][] = $row;
        }
        return $grouped;
    }

    // ========= Файловые операции =========

    /**
     * Путь к директории загрузок для тикета
     */
    public static function get_upload_dir(int $ticket_id): string
    {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/cashback-support/' . $ticket_id;
    }

    /**
     * Создать базовую директорию для вложений и защитить её.
     * Вызывается при активации плагина.
     */
    public static function ensure_upload_dir(): void
    {
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
    public static function delete_ticket_files(int $ticket_id): void
    {
        $dir = self::get_upload_dir($ticket_id);
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        // Удаляем защитные файлы директории
        @unlink($dir . '/.htaccess');
        @unlink($dir . '/web.config');
        @unlink($dir . '/index.php');
        @unlink($dir . '/index.html');
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
    private static function protect_upload_directory(string $dir): void
    {
        self::write_protection_files($dir);

        // Защита родительской директории
        $parent = dirname($dir);
        self::write_protection_files($parent);
    }

    /**
     * Записать защитные файлы в указанную директорию
     */
    private static function write_protection_files(string $dir): void
    {
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
    private static function get_allowed_mimes(): array
    {
        return [
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'gif'  => ['image/gif'],
            'pdf'  => ['application/pdf'],
            'doc'  => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'txt'  => ['text/plain'],
        ];
    }

    /**
     * Валидация одного файла из $_FILES
     *
     * @param array<string, mixed> $file Элемент из $_FILES
     * @return true|string true если OK, строка с ошибкой если нет
     */
    public static function validate_file(array $file)
    {
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

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
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
        if (!isset($allowed_mimes[$ext])) {
            return sprintf(
                'Расширение "%s" не поддерживается системой загрузки.',
                esc_html($ext)
            );
        }
        if (!in_array($detected_mime, $allowed_mimes[$ext], true)) {
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
    public static function handle_file_upload(array $file, int $ticket_id, int $message_id, int $user_id)
    {
        // Defense-in-depth: проверяем что тикет существует и принадлежит пользователю (или это админ)
        if ($ticket_id <= 0) {
            return 'Некорректный ID тикета.';
        }
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'cashback_support_tickets';
        $ticket_owner = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM `{$tickets_table}` WHERE id = %d",
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
        $dest_path = $dir . '/' . $stored_name;

        if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
            return 'Не удалось сохранить файл.';
        }

        // MIME-тип определяем через finfo (по содержимому файла), а не из $_FILES['type'] (браузер)
        $detected_mime = '';
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected_mime = finfo_file($finfo, $dest_path);
            finfo_close($finfo);
        }
        if (!$detected_mime) {
            @unlink($dest_path);
            return 'Не удалось определить тип файла. Убедитесь, что расширение fileinfo включено.';
        }

        $attachment_id = self::record_attachment([
            'message_id'  => $message_id,
            'ticket_id'   => $ticket_id,
            'user_id'     => $user_id,
            'file_name'   => sanitize_file_name($file['name']),
            'stored_name' => $stored_name,
            'file_size'   => $file['size'],
            'mime_type'   => sanitize_text_field($detected_mime),
        ]);

        if (!$attachment_id) {
            @unlink($dest_path);
            return 'Не удалось записать данные о файле.';
        }

        return $attachment_id;
    }

    /**
     * Отдача файла с проверкой прав доступа
     */
    public static function serve_file(int $attachment_id, int $requesting_user_id, bool $is_admin = false): void
    {
        // Rate limiting: максимум 10 скачиваний в минуту на пользователя
        $dl_rate_key = 'cb_file_dl_' . $requesting_user_id;
        $dl_count = (int) get_transient($dl_rate_key);
        if ($dl_count >= 10) {
            wp_die('Слишком много запросов на скачивание. Попробуйте через минуту.', 'Ошибка', ['response' => 429]);
        }
        set_transient($dl_rate_key, $dl_count + 1, 60);

        $attachment = self::get_attachment($attachment_id);
        if (!$attachment) {
            wp_die('Файл не найден.', 'Ошибка', ['response' => 404]);
        }

        if (!$is_admin) {
            global $wpdb;
            $tickets_table = $wpdb->prefix . 'cashback_support_tickets';
            $owner = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM `{$tickets_table}` WHERE id = %d",
                $attachment->ticket_id
            ));
            if ((int) $owner !== $requesting_user_id) {
                wp_die('У вас нет доступа к этому файлу.', 'Доступ запрещён', ['response' => 403]);
            }
        }

        // Defence-in-depth: stored_name должен быть hex-строкой из 32 символов (bin2hex(random_bytes(16)))
        if (!preg_match('/^[a-f0-9]{32}$/', $attachment->stored_name)) {
            wp_die('Некорректное имя файла.', 'Ошибка', ['response' => 400]);
        }

        $file_path = self::get_upload_dir((int) $attachment->ticket_id) . '/' . $attachment->stored_name;
        if (!file_exists($file_path)) {
            wp_die('Файл не найден на диске.', 'Ошибка', ['response' => 404]);
        }

        // Whitelist безопасных MIME-типов для Content-Type.
        // Если MIME из БД не в списке — отдаём как application/octet-stream
        // чтобы предотвратить inline-рендеринг потенциально опасного контента.
        $safe_mimes = [
            'image/jpeg', 'image/png', 'image/gif',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
        ];
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
    public static function get_unread_tickets_count(): int
    {
        global $wpdb;

        $tickets_table = $wpdb->prefix . 'cashback_support_tickets';
        $messages_table = $wpdb->prefix . 'cashback_support_messages';

        $count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT t.id)
             FROM `{$tickets_table}` t
             INNER JOIN `{$messages_table}` m ON t.id = m.ticket_id
             WHERE m.is_admin = 0
             AND m.is_read = 0"
        );

        return (int) $count;
    }

    /**
     * Получить количество тикетов с непрочитанными ответами от админа для конкретного пользователя
     */
    public static function get_unread_admin_replies_count(int $user_id): int
    {
        global $wpdb;

        $tickets_table = $wpdb->prefix . 'cashback_support_tickets';
        $messages_table = $wpdb->prefix . 'cashback_support_messages';

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT t.id)
             FROM `{$tickets_table}` t
             INNER JOIN `{$messages_table}` m ON t.id = m.ticket_id
             WHERE t.user_id = %d
             AND m.is_admin = 1
             AND m.is_read = 0",
            $user_id
        ));

        return (int) $count;
    }

    /**
     * Форматировать номер тикета для отображения
     */
    public static function format_ticket_number(int $ticket_id): string
    {
        return '№' . str_pad((string) $ticket_id, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Удалить закрытые тикеты старше 1 месяца.
     * Сообщения удаляются каскадно через FK.
     */
    public static function delete_old_closed_tickets(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_support_tickets';

        // Собираем ID тикетов для удаления файлов с диска
        $ticket_ids = $wpdb->get_col(
            "SELECT id FROM `{$table}` WHERE status = 'closed' AND closed_at IS NOT NULL AND closed_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)"
        );

        if (!empty($ticket_ids)) {
            foreach ($ticket_ids as $tid) {
                self::delete_ticket_files((int) $tid);
            }
        }

        $deleted = $wpdb->query(
            "DELETE FROM `{$table}` WHERE status = 'closed' AND closed_at IS NOT NULL AND closed_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)"
        );

        return max(0, (int) $deleted);
    }
}

// Регистрация WP Cron хука для автоудаления
add_action('cashback_support_auto_delete_cron', function (): void {
    Cashback_Support_DB::delete_old_closed_tickets();
});
