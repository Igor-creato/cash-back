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
     * Максимальное суммарное количество строк (ciphertext + waiting payouts),
     * при котором разрешён синхронный прогон. Больше — обязательно Action Scheduler.
     */
    public const SYNC_RUN_LIMIT     = 500;

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
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM %i WHERE encrypted_details <> ''", $table)
        );
    }

    /**
     * Одна партия очистки. Возвращает количество затронутых строк.
     * Каждый батч — отдельный UPDATE, атомарный на уровне InnoDB.
     */
    public static function run_batch( int $limit = self::BATCH_SIZE ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_user_profile';
        $limit = max(1, min(5000, $limit));

        $affected = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET'
                . " encrypted_details = '',"
                . " masked_details = '',"
                . " details_hash = '',"
                . " payout_account = '',"
                . " payout_full_name = '',"
                . ' payout_method_id = 0,'
                . ' bank_id = 0'
                . " WHERE encrypted_details <> ''"
                . ' LIMIT %d',
                $table,
                $limit
            )
        );
        return is_int($affected) ? $affected : 0;
    }

    /**
     * Сколько активных заявок на выплату (status='waiting') ещё ждут отмены.
     * Используется в двухфазном job, чтобы определить завершена ли phase A.
     */
    public static function count_waiting_payouts(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_payout_requests';
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status = 'waiting'", $table)
        );
    }

    /**
     * Phase A: отменить waiting-заявки батчем.
     *
     * Каждая заявка обрабатывается в отдельной транзакции через
     * Cashback_Payouts_Admin::cancel_payout_with_refund() — используется
     * существующий защищённый путь (FOR UPDATE + ledger + refunded_at).
     *
     * @param int $limit  Максимум заявок за один вызов.
     * @return int        Число успешно отменённых.
     */
    public static function cancel_waiting_payouts_batch( int $limit = 100 ): int {
        global $wpdb, $payouts_admin;

        $limit = max(1, min(1000, $limit));
        $table = $wpdb->prefix . 'cashback_payout_requests';
        $rows  = $wpdb->get_results(
            $wpdb->prepare("SELECT id FROM %i WHERE status = 'waiting' LIMIT %d", $table, $limit)
        );

        if (empty($rows)) {
            return 0;
        }

        // В AS-job is_admin() может быть false → admin/payouts.php не подключён при load_dependencies.
        if (!class_exists('Cashback_Payouts_Admin')) {
            $payouts_file = plugin_dir_path(__DIR__) . 'admin/payouts.php';
            if (!file_exists($payouts_file)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Recovery] admin/payouts.php missing; cannot cancel payouts.');
                return 0;
            }
            require_once $payouts_file;
        }

        if ( ! $payouts_admin instanceof Cashback_Payouts_Admin ) {
            $payouts_admin = new Cashback_Payouts_Admin();
        }

        $cancelled = 0;
        foreach ($rows as $row) {
            $payout_id = (int) ( $row->id ?? 0 );
            if ($payout_id <= 0) {
                continue;
            }
            if ($payouts_admin->cancel_payout_with_refund($payout_id, 'encryption_recovery', 0)) {
                ++$cancelled;
            }
        }

        return $cancelled;
    }

    /**
     * Handler для Action Scheduler — двухфазный:
     *
     *   Phase A: отмена waiting-заявок с возвратом pending → available.
     *   Phase B: обнуление encrypted_details в cashback_user_profile.
     *   Phase C: обновление fingerprint + audit-log завершения.
     *
     * Phase B запускается только после того, как в phase A не осталось
     * заявок для отмены — это гарантирует, что админ не потеряет ciphertext
     * payout_account из заявки до того, как заявка будет отменена.
     */
    public static function run_batch_action_handler(): void {
        // ---- Phase A: cancel waiting payouts ----
        $waiting_remaining = self::count_waiting_payouts();
        if ($waiting_remaining > 0) {
            $cancelled = self::cancel_waiting_payouts_batch(100);

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log(sprintf(
                '[Cashback Recovery] Phase A: cancelled %d waiting payout(s); %d remaining',
                $cancelled,
                max(0, $waiting_remaining - $cancelled)
            ));

            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(self::AS_HOOK, array(), self::AS_GROUP);
            }
            return;
        }

        // ---- Phase B: purge profile ciphertexts ----
        $affected = self::run_batch(self::BATCH_SIZE);

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log(sprintf('[Cashback Recovery] Phase B: purged %d profile row(s)', $affected));

        if (self::count_rows_with_ciphertext() > 0) {
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(self::AS_HOOK, array(), self::AS_GROUP);
            }
            return;
        }

        // ---- Phase C: finalize — принимаем текущий ключ как канонический ----
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
                unset($e);
            }
        }
    }

    /**
     * Синхронное выполнение всех трёх фаз без Action Scheduler.
     *
     * Используется на малых инсталляциях (< ~500 строк) и там, где
     * WP-Cron отключён — админ не должен ждать тика, чтобы увидеть,
     * что очистка прошла. На больших объёмах вызывать не стоит —
     * max_execution_time отвалится.
     */
    public static function run_synchronously(): void {
        // Phase A: крутимся, пока есть waiting-заявки.
        $safety = 200;
        while (self::count_waiting_payouts() > 0 && $safety-- > 0) {
            if (self::cancel_waiting_payouts_batch(100) === 0) {
                break;
            }
        }

        // Phase B: крутимся, пока есть ciphertext.
        $safety = 200;
        while (self::count_rows_with_ciphertext() > 0 && $safety-- > 0) {
            if (self::run_batch(self::BATCH_SIZE) === 0) {
                break;
            }
        }

        // Phase C: финализация.
        self::update_stored_fingerprint();

        if (class_exists('Cashback_Encryption') && method_exists('Cashback_Encryption', 'write_audit_log')) {
            try {
                Cashback_Encryption::write_audit_log(
                    'encryption_recovery_completed',
                    (int) get_current_user_id(),
                    'encryption',
                    null,
                    array( 'sync' => true )
                );
            } catch (\Throwable $e) {
                unset($e);
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

        // Во время легальной ротации ключа (migrating/rolling_back/completed)
        // fingerprint намеренно не совпадает — это не mismatch, а ожидаемое состояние.
        // Не рисуем notice, чтобы не сбивать админа кнопкой "Запустите восстановление".
        if (class_exists('Cashback_Key_Rotation')) {
            $rotation_state = Cashback_Key_Rotation::current_state_name();
            if (in_array($rotation_state, array( 'migrating', 'migrated', 'rolling_back', 'completed' ), true)) {
                return;
            }
        }

        printf(
            '<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
            esc_html__('Cashback Plugin: обнаружено несовпадение ключа шифрования', 'cashback-plugin'),
            esc_html__('Ключ шифрования был изменён после предыдущей активности плагина. Ранее зашифрованные реквизиты пользователей больше не могут быть расшифрованы. Запустите процедуру восстановления — зашифрованные записи будут безопасно обнулены, пользователям будет предложено ввести новые реквизиты.', 'cashback-plugin'),
            esc_url(admin_url('options-general.php?page=' . self::ADMIN_PAGE_SLUG)),
            esc_html__('Перейти к восстановлению', 'cashback-plugin')
        );
    }

    public static function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'));
        }

        $remaining = self::count_rows_with_ciphertext();
        $mismatch  = self::is_key_mismatch();
        $as_queued = self::is_batch_queued();

        $flag_scheduled           = isset($_GET['scheduled']) && '1' === $_GET['scheduled'];
        $flag_completed           = isset($_GET['completed']) && '1' === $_GET['completed'];
        $flag_confirmation_failed = isset($_GET['confirmation_failed']) && '1' === $_GET['confirmation_failed'];

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Восстановление шифрования Cashback', 'cashback-plugin') . '</h1>';

        // ---- Баннеры по результату прошлого submit'а ----
        if ($flag_confirmation_failed) {
            echo '<div class="notice notice-error is-dismissible"><p>' .
                esc_html__('Неверное слово-подтверждение. Введите его буква в букву и попробуйте ещё раз.', 'cashback-plugin') .
                '</p></div>';
        }

        if ($flag_completed && 0 === $remaining && !$mismatch) {
            echo '<div class="notice notice-success is-dismissible"><p>' .
                esc_html__('Очистка завершена. Fingerprint обновлён, предупреждение снято.', 'cashback-plugin') .
                '</p></div>';
        }

        if ($flag_scheduled) {
            if ($as_queued) {
                echo '<div class="notice notice-info is-dismissible"><p>' .
                    esc_html__('Задача очистки поставлена в очередь Action Scheduler. Она обработается на ближайшем тике WP-Cron. Обновите страницу через минуту — если warning всё ещё виден, проверьте что WP-Cron активен.', 'cashback-plugin') .
                    '</p></div>';
            } elseif (0 === $remaining && !$mismatch) {
                echo '<div class="notice notice-success is-dismissible"><p>' .
                    esc_html__('Очистка уже завершена. Fingerprint обновлён.', 'cashback-plugin') .
                    '</p></div>';
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p>' .
                    esc_html__('Задача больше не висит в очереди, но данные ещё не вычищены. Возможно, WP-Cron не запускался либо job завершился с ошибкой — проверьте PHP error_log.', 'cashback-plugin') .
                    '</p></div>';
            }
        }

        // ---- Текущее состояние (inline error, если mismatch) ----
        if ($mismatch) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('Обнаружено несовпадение fingerprint ключа. Ранее зашифрованные данные не могут быть расшифрованы текущим ключом.', 'cashback-plugin') . '</p></div>';
        }

        // ---- Inline-статус AS-job'а (Fix 2) ----
        if ($as_queued) {
            echo '<div class="notice notice-info inline"><p>' .
                esc_html__('В очереди Action Scheduler уже есть задача очистки — она ожидает запуска WP-Cron. Повторный запуск не нужен.', 'cashback-plugin') .
                '</p></div>';
        }

        // ---- Диагностика (Fix 5) ----
        self::render_diagnostics_block();

        echo '<p>' . sprintf(
            /* translators: %d — количество пользователей с нерасшифруемыми реквизитами, ожидающих очистки. */
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

        // Sync-режим доступен только на малых инсталляциях — иначе max_execution_time.
        $sync_allowed  = ($remaining + self::count_waiting_payouts()) <= self::SYNC_RUN_LIMIT;
        $sync_disabled = $sync_allowed ? '' : ' disabled';
        echo '<p><label><input type="checkbox" name="run_sync" value="1"' . esc_attr($sync_disabled) . ' /> ';
        echo esc_html(sprintf(
            /* translators: %d — максимальное число строк для sync-режима. */
            __('Выполнить сейчас синхронно (без Action Scheduler/WP-Cron). Доступно только при ≤ %d строк суммарно.', 'cashback-plugin'),
            self::SYNC_RUN_LIMIT
        ));
        echo '</label></p>';

        submit_button(esc_html__('Запустить очистку', 'cashback-plugin'), 'primary delete');
        echo '</form>';
        echo '</div>';
    }

    /**
     * Возвращает true, если задача очистки уже висит в очереди Action Scheduler.
     * При отсутствии AS возвращает false (тогда нельзя сказать наверняка,
     * но и очередь сама по себе отсутствует).
     */
    public static function is_batch_queued(): bool {
        if (!function_exists('as_has_scheduled_action')) {
            return false;
        }
        return (bool) as_has_scheduled_action(self::AS_HOOK, null, self::AS_GROUP);
    }

    /**
     * Диагностический блок (Fix 5): первые 8 символов текущего и сохранённого
     * fingerprint + состояние очереди. Только для админов, только на странице
     * recovery. Полный fingerprint наружу не уходит.
     */
    private static function render_diagnostics_block(): void {
        $current = self::get_current_fingerprint();
        $stored  = self::get_stored_fingerprint();

        $current_short = '' !== $current ? substr($current, 0, 8) : '—';
        $stored_short  = '' !== $stored ? substr($stored, 0, 8) : '—';
        $match         = ('' !== $current && '' !== $stored && hash_equals($stored, $current)) ? 'match' : 'mismatch';
        $queue_state   = self::is_batch_queued()
            ? __('в очереди', 'cashback-plugin')
            : __('пусто', 'cashback-plugin');

        echo '<div style="background:#f6f7f7;border-left:4px solid #72aee6;padding:8px 12px;margin:12px 0;font-family:monospace;">';
        echo '<strong>' . esc_html__('Диагностика', 'cashback-plugin') . ':</strong><br />';
        echo esc_html__('Fingerprint текущего ключа', 'cashback-plugin') . ': <code>' . esc_html($current_short) . '…</code><br />';
        echo esc_html__('Fingerprint сохранённый', 'cashback-plugin') . ': <code>' . esc_html($stored_short) . '…</code> (' . esc_html($match) . ')<br />';
        echo esc_html__('Очередь Action Scheduler', 'cashback-plugin') . ': ' . esc_html($queue_state);
        echo '</div>';
    }

    /**
     * Обработчик admin-post. Делегирует в handle_admin_form_submit для тестируемости.
     *
     * Fix 3: если слово подтверждения введено неверно, редиректим на ту же страницу
     * с `&confirmation_failed=1` вместо `wp_die()` — админ не теряет контекст.
     * Fix 4: если чекбокс run_sync установлен и объём < SYNC_RUN_LIMIT —
     * выполняем синхронно и редиректим на `&completed=1`.
     */
    public static function handle_admin_post(): void {
        check_admin_referer(self::NONCE_ACTION);

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'));
        }

        $confirmation = isset($_POST['confirmation']) ? (string) wp_unslash($_POST['confirmation']) : '';
        if (!hash_equals(self::CONFIRMATION_WORD, $confirmation)) {
            wp_safe_redirect(admin_url('options-general.php?page=' . self::ADMIN_PAGE_SLUG . '&confirmation_failed=1'));
            exit;
        }

        $run_sync = !empty($_POST['run_sync']);
        // Жёсткий guard от больших объёмов в sync-режиме.
        if ($run_sync) {
            $total = self::count_rows_with_ciphertext() + self::count_waiting_payouts();
            if ($total > self::SYNC_RUN_LIMIT) {
                $run_sync = false;
            }
        }

        self::handle_admin_form_submit($_POST, $run_sync);

        $redirect_flag = $run_sync ? '&completed=1' : '&scheduled=1';
        wp_safe_redirect(admin_url('options-general.php?page=' . self::ADMIN_PAGE_SLUG . $redirect_flag));
        exit;
    }

    /**
     * Валидация формы + планирование AS-job (или синхронный прогон при $run_sync).
     * Бросает Throwable на отказ по capability/confirmation (через wp_die), чтобы
     * тесты могли перехватить. Snake-path контролируется `handle_admin_post()`.
     *
     * @param array<string,mixed> $post
     * @param bool                $run_sync  true → выполнить синхронно без AS (см. SYNC_RUN_LIMIT).
     */
    public static function handle_admin_form_submit( array $post, bool $run_sync = false ): void {
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
                    array(
                        'rows_queued' => self::count_rows_with_ciphertext(),
                        'sync'        => $run_sync,
                    )
                );
            } catch (\Throwable $e) {
                // Не ломаем flow, если аудит недоступен.
                unset($e);
            }
        }

        if ($run_sync) {
            // Синхронный прогон: все три фазы в текущем request'е.
            self::run_synchronously();
            return;
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
