<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Механизм аварийного восстановления при утере / ротации ключа шифрования.
 *
 * Когда файл `wp-content/.cashback-encryption-key.php` удалён или константа
 * `CB_ENCRYPTION_KEY` изменена — ранее зашифрованные реквизиты пользователей
 * становятся нерасшифруемы. Класс:
 *   1. Фиксирует fingerprint текущего ключа в wp_option → позволяет детектировать
 *      изменение ключа между запусками.
 *   2. Предоставляет admin-страницу, где администратор подтверждает (word-typed)
 *      вычистку "мёртвых" ciphertexts в таблице cashback_user_profile.
 *   3. Очистка идёт батчами по 500 строк через Action Scheduler —
 *      не блокирует БД на большом количестве пользователей.
 *
 * После очистки fail-closed guard (get_payout_account) продолжает возвращать
 * null до тех пор, пока пользователи не введут новые реквизиты.
 *
 * См. ADR Группа 4 (F-1-001, recovery-flow).
 */
class Cashback_Encryption_Recovery {

    public const FINGERPRINT_OPTION = 'cashback_encryption_key_fingerprint';
    public const AS_HOOK            = 'cashback_encryption_recovery_batch';
    public const AS_GROUP           = 'cashback';
    public const ADMIN_PAGE_SLUG    = 'cashback-encryption-recovery';
    public const BATCH_SIZE         = 500;
    public const NONCE_ACTION       = 'cashback_encryption_recovery';
    public const CONFIRMATION_WORD  = 'DELETE_ALL_PAYOUT_CREDENTIALS';

    /**
     * Регистрация хуков. Вызывается из cashback-plugin.php при загрузке admin-слоя.
     */
    public static function init(): void {
        add_action('admin_init', array( __CLASS__, 'record_fingerprint_if_missing' ));
        add_action('admin_notices', array( __CLASS__, 'maybe_render_mismatch_notice' ));
        add_action('admin_menu', array( __CLASS__, 'register_admin_page' ));
        add_action('admin_post_cashback_encryption_recovery', array( __CLASS__, 'handle_admin_post' ));
        add_action(self::AS_HOOK, array( __CLASS__, 'run_batch_action_handler' ));
    }

    // ================================================================
    // Fingerprint
    // ================================================================

    /**
     * Fingerprint текущего ключа шифрования.
     * Не раскрывает сам ключ; HMAC-SHA256 с фиксированной солью.
     */
    public static function get_current_fingerprint(): string {
        if (!class_exists('Cashback_Encryption') || !Cashback_Encryption::is_configured()) {
            return '';
        }
        return hash_hmac('sha256', CB_ENCRYPTION_KEY, 'cashback_fingerprint_v1');
    }

    public static function get_stored_fingerprint(): string {
        return (string) get_option(self::FINGERPRINT_OPTION, '');
    }

    /**
     * true — сохранённый fingerprint есть и НЕ совпадает с текущим (подозрение на подмену ключа).
     * false — fingerprint отсутствует (первичный install) или совпадает.
     */
    public static function is_key_mismatch(): bool {
        $stored = self::get_stored_fingerprint();
        if ($stored === '') {
            return false;
        }
        $current = self::get_current_fingerprint();
        if ($current === '') {
            // Ключ есть в wp_option, но сейчас не настроен — это не классический mismatch,
            // это "ключ пропал". Admin-notice про отсутствие ключа рисует главный плагин.
            return false;
        }
        return !hash_equals($stored, $current);
    }

    /**
     * Записывает текущий fingerprint, если он ещё не зафиксирован.
     * Идемпотентна — повторные вызовы no-op.
     */
    public static function record_fingerprint_if_missing(): void {
        if (self::get_stored_fingerprint() !== '') {
            return;
        }
        $current = self::get_current_fingerprint();
        if ($current === '') {
            return;
        }
        update_option(self::FINGERPRINT_OPTION, $current, false);
    }

    /**
     * Обновляет сохранённый fingerprint на текущий.
     * Вызывается после успешной очистки данных, когда мы принимаем новый ключ.
     */
    public static function update_stored_fingerprint(): void {
        $current = self::get_current_fingerprint();
        if ($current === '') {
            return;
        }
        update_option(self::FINGERPRINT_OPTION, $current, false);
    }

    // ================================================================
    // Batch purge
    // ================================================================

    /**
     * Сколько строк в cashback_user_profile ещё содержат зашифрованные реквизиты.
     */
    public static function count_rows_with_ciphertext(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_user_profile';
        $sql   = "SELECT COUNT(*) FROM `{$table}` WHERE encrypted_details <> ''";
        return (int) $wpdb->get_var($sql);
    }

    /**
     * Одна партия очистки. Возвращает количество затронутых строк.
     * Каждый батч — отдельный UPDATE, атомарный на уровне InnoDB.
     */
    public static function run_batch( int $limit = self::BATCH_SIZE ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_user_profile';
        $limit = max(1, min(5000, $limit));
        $sql   = "UPDATE `{$table}` SET "
            . "encrypted_details = '', "
            . "masked_details = '', "
            . "details_hash = '', "
            . "payout_account = '', "
            . "payout_full_name = '', "
            . "payout_method_id = 0, "
            . "bank_id = 0 "
            . "WHERE encrypted_details <> '' "
            . "LIMIT {$limit}";

        $affected = $wpdb->query($sql);
        return is_int($affected) ? $affected : 0;
    }

    /**
     * Handler для Action Scheduler: запускает один батч и перепланирует себя,
     * если ещё остались строки.
     */
    public static function run_batch_action_handler(): void {
        $affected = self::run_batch(self::BATCH_SIZE);

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log(sprintf('[Cashback Recovery] Purged %d row(s) in current batch', $affected));

        // Проверяем, осталось ли что-то очищать.
        if (self::count_rows_with_ciphertext() > 0) {
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(self::AS_HOOK, array(), self::AS_GROUP);
            }
            return;
        }

        // Очистка завершена. Принимаем текущий ключ как канонический и пишем аудит.
        self::update_stored_fingerprint();

        if (class_exists('Cashback_Encryption') && method_exists('Cashback_Encryption', 'write_audit_log')) {
            try {
                Cashback_Encryption::write_audit_log(
                    'encryption_recovery_completed',
                    0,
                    'encryption',
                    null,
                    array( 'rows_purged_last_batch' => $affected )
                );
            } catch (\Throwable $e) {
                // Не ломаем основной поток, если аудит-таблица недоступна.
            }
        }
    }

    // ================================================================
    // Admin page
    // ================================================================

    public static function register_admin_page(): void {
        add_submenu_page(
            'options-general.php',
            __('Восстановление шифрования Cashback', 'cashback-plugin'),
            __('Cashback: восстановление', 'cashback-plugin'),
            'manage_options',
            self::ADMIN_PAGE_SLUG,
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function maybe_render_mismatch_notice(): void {
        if (!self::is_key_mismatch()) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $url = esc_url(admin_url('options-general.php?page=' . self::ADMIN_PAGE_SLUG));
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
            esc_html__('Cashback Plugin: обнаружено несовпадение ключа шифрования', 'cashback-plugin'),
            esc_html__('Ключ шифрования был изменён после предыдущей активности плагина. Ранее зашифрованные реквизиты пользователей больше не могут быть расшифрованы. Запустите процедуру восстановления — зашифрованные записи будут безопасно обнулены, пользователям будет предложено ввести новые реквизиты.', 'cashback-plugin'),
            $url,
            esc_html__('Перейти к восстановлению', 'cashback-plugin')
        );
    }

    public static function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'));
        }

        $remaining = self::count_rows_with_ciphertext();
        $mismatch  = self::is_key_mismatch();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Восстановление шифрования Cashback', 'cashback-plugin') . '</h1>';

        if ($mismatch) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('Обнаружено несовпадение fingerprint ключа. Ранее зашифрованные данные не могут быть расшифрованы текущим ключом.', 'cashback-plugin') . '</p></div>';
        }

        echo '<p>' . sprintf(
            esc_html__('Пользователей с зашифрованными реквизитами в очереди на очистку: %d', 'cashback-plugin'),
            (int) $remaining
        ) . '</p>';

        echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('Внимание:', 'cashback-plugin') . '</strong> ';
        echo esc_html__('перед запуском сделайте резервную копию таблицы wp_cashback_user_profile. Операция обнуляет поля encrypted_details, masked_details, details_hash, payout_account, payout_full_name, payout_method_id, bank_id — восстановить их из текущего состояния будет невозможно.', 'cashback-plugin') . '</p></div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="cashback_encryption_recovery" />';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<p><label>' . esc_html__('Введите слово-подтверждение:', 'cashback-plugin') . ' <code>' . esc_html(self::CONFIRMATION_WORD) . '</code></label><br />';
        echo '<input type="text" name="confirmation" value="" style="width:360px;" autocomplete="off" /></p>';
        submit_button(esc_html__('Запустить очистку', 'cashback-plugin'), 'primary delete');
        echo '</form>';
        echo '</div>';
    }

    /**
     * Обработчик admin-post. Делегирует в handle_admin_form_submit для тестируемости.
     */
    public static function handle_admin_post(): void {
        check_admin_referer(self::NONCE_ACTION);

        self::handle_admin_form_submit($_POST);

        wp_safe_redirect(admin_url('options-general.php?page=' . self::ADMIN_PAGE_SLUG . '&scheduled=1'));
        exit;
    }

    /**
     * Валидация формы + планирование AS-job. Бросает RuntimeException на отказ
     * (через wp_die), чтобы тесты могли перехватить.
     *
     * @param array<string,mixed> $post
     */
    public static function handle_admin_form_submit( array $post ): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'));
        }

        $confirmation = isset($post['confirmation']) ? (string) $post['confirmation'] : '';
        if ($confirmation !== self::CONFIRMATION_WORD) {
            wp_die(esc_html__('Неверное слово-подтверждение. Операция отменена.', 'cashback-plugin'));
        }

        // Audit-log: кто и когда запустил recovery.
        if (class_exists('Cashback_Encryption') && method_exists('Cashback_Encryption', 'write_audit_log')) {
            try {
                Cashback_Encryption::write_audit_log(
                    'encryption_recovery_started',
                    (int) get_current_user_id(),
                    'encryption',
                    null,
                    array( 'rows_queued' => self::count_rows_with_ciphertext() )
                );
            } catch (\Throwable $e) {
                // Не ломаем flow, если аудит недоступен.
            }
        }

        // Ставим первый батч в очередь AS.
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::AS_HOOK, array(), self::AS_GROUP);
        } else {
            // Fallback (dev/standalone): синхронный прогон.
            self::run_batch_action_handler();
        }
    }
}
