<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Массовая email-рассылка от администрации.
 *
 * Администратор создаёт кампанию (тема + HTML-тело + фильтры аудитории),
 * получатели попадают в очередь, Action Scheduler действие
 * `cashback_broadcast_process` (группа `cashback`) каждую минуту берёт батч
 * и отправляет через Cashback_Email_Sender.
 */
class Cashback_Broadcast {

    public const NOTIFICATION_TYPE  = 'broadcast';
    public const BATCH_SIZE_DEFAULT = 50;
    public const MAX_ATTEMPTS       = 3;

    private static ?self $instance = null;

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        if (self::$instance === null) {
            self::$instance = $this;
        }

        add_action('wp_ajax_cashback_broadcast_preview_count', array( $this, 'handle_ajax_preview_count' ));
        add_action('wp_ajax_cashback_broadcast_preview_html', array( $this, 'handle_ajax_preview_html' ));
        add_action('wp_ajax_cashback_broadcast_create', array( $this, 'handle_ajax_create_campaign' ));
        add_action('wp_ajax_cashback_broadcast_cancel', array( $this, 'handle_ajax_cancel_campaign' ));
        add_action('wp_ajax_cashback_broadcast_status', array( $this, 'handle_ajax_status' ));
        add_action('cashback_broadcast_process', array( $this, 'process_queue' ));

        // Планирование через Action Scheduler (WooCommerce — жёсткая зависимость плагина,
        // AS загружается им автоматически). Тик каждую минуту, батч 50 писем внутри действия.
        add_action('init', static function (): void {
            if (function_exists('as_has_scheduled_action')
                && function_exists('as_schedule_recurring_action')
                && !as_has_scheduled_action('cashback_broadcast_process')
            ) {
                as_schedule_recurring_action(time(), 60, 'cashback_broadcast_process', array(), 'cashback');
            }
        });
    }

    // =====================================================================
    // Хелперы
    // =====================================================================

    public static function table_campaigns(): string {
        global $wpdb;
        return $wpdb->prefix . 'cashback_broadcast_campaigns';
    }

    public static function table_queue(): string {
        global $wpdb;
        return $wpdb->prefix . 'cashback_broadcast_queue';
    }

    public static function batch_size(): int {
        if (defined('CASHBACK_BROADCAST_BATCH_SIZE')) {
            $val = (int) constant('CASHBACK_BROADCAST_BATCH_SIZE');
            return max(1, min(500, $val));
        }
        return self::BATCH_SIZE_DEFAULT;
    }

    /**
     * Допустимые статусы профиля для фильтра аудитории.
     *
     * @return array<string,string>
     */
    public static function allowed_statuses(): array {
        return array(
            'active'   => __('Активные', 'cashback-plugin'),
            'noactive' => __('Неактивные', 'cashback-plugin'),
            'banned'   => __('Забаненные', 'cashback-plugin'),
            'deleted'  => __('Удалённые', 'cashback-plugin'),
        );
    }

    /**
     * Допустимые роли для фильтра аудитории.
     *
     * @return array<string,string>
     */
    public static function allowed_roles(): array {
        return array(
            'customer'      => __('Покупатели (customer)', 'cashback-plugin'),
            'subscriber'    => __('Подписчики (subscriber)', 'cashback-plugin'),
            'administrator' => __('Администраторы', 'cashback-plugin'),
            'shop_manager'  => __('Менеджеры магазина', 'cashback-plugin'),
        );
    }

    /**
     * Санитизация и валидация фильтров.
     *
     * @param array<string,mixed> $raw
     * @return array{statuses: string[], roles: string[], has_cashback: bool}
     */
    private function sanitize_filters( array $raw ): array {
        $allowed_statuses = array_keys(self::allowed_statuses());
        $allowed_roles    = array_keys(self::allowed_roles());

        $statuses = isset($raw['statuses']) && is_array($raw['statuses'])
            ? array_values(array_intersect(array_map('sanitize_key', $raw['statuses']), $allowed_statuses))
            : array();
        $roles    = isset($raw['roles']) && is_array($raw['roles'])
            ? array_values(array_intersect(array_map('sanitize_key', $raw['roles']), $allowed_roles))
            : array();

        if (empty($statuses)) {
            $statuses = array( 'active' );
        }
        if (empty($roles)) {
            $roles = array( 'customer' );
        }

        return array(
            'statuses'     => $statuses,
            'roles'        => $roles,
            'has_cashback' => !empty($raw['has_cashback']),
        );
    }

    // =====================================================================
    // Построение списка получателей
    // =====================================================================

    /**
     * Получить карту [user_id => email] по фильтрам.
     *
     * @param array{statuses: string[], roles: string[], has_cashback: bool} $filters
     * @return array<int,string>
     */
    private function build_recipients( array $filters ): array {
        global $wpdb;

        $query = new WP_User_Query(array(
            'role__in' => $filters['roles'],
            'fields'   => array( 'ID', 'user_email' ),
            'number'   => -1,
        ));
        $users = $query->get_results();
        if (empty($users)) {
            return array();
        }

        $candidates = array();
        foreach ($users as $u) {
            $email = (string) ( $u->user_email ?? '' );
            if ($email !== '' && is_email($email)) {
                $candidates[ (int) $u->ID ] = $email;
            }
        }
        if (empty($candidates)) {
            return array();
        }

        // Фильтр по статусу профиля.
        $ids           = array_keys($candidates);
        $profile_table = $wpdb->prefix . 'cashback_user_profile';
        $placeholders  = implode(',', array_fill(0, count($ids), '%d'));
        $profile_sql   = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders dynamically built из int id; значения биндятся через prepare().
            "SELECT user_id, status FROM %i WHERE user_id IN ({$placeholders})",
            array_merge(array( $profile_table ), $ids)
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Already prepared above.
        $profile_rows     = $wpdb->get_results($profile_sql, ARRAY_A);
        $profile_statuses = array();
        if ($profile_rows) {
            foreach ($profile_rows as $row) {
                $profile_statuses[ (int) $row['user_id'] ] = (string) $row['status'];
            }
        }

        $treat_missing_as_active = in_array('active', $filters['statuses'], true);

        $filtered = array();
        foreach ($candidates as $uid => $email) {
            if (isset($profile_statuses[ $uid ])) {
                if (in_array($profile_statuses[ $uid ], $filters['statuses'], true)) {
                    $filtered[ $uid ] = $email;
                }
            } elseif ($treat_missing_as_active) {
                $filtered[ $uid ] = $email;
            }
        }

        if ($filtered && $filters['has_cashback']) {
            $tx_table       = $wpdb->prefix . 'cashback_transactions';
            $ids2           = array_keys($filtered);
            $placeholders_t = implode(',', array_fill(0, count($ids2), '%d'));
            $tx_sql         = $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders из int id; значения биндятся через prepare().
                "SELECT DISTINCT user_id FROM %i WHERE user_id IN ({$placeholders_t})",
                array_merge(array( $tx_table ), $ids2)
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Already prepared above.
            $rows_tx  = $wpdb->get_col($tx_sql);
            $with_tx  = array_flip(array_map('intval', $rows_tx));
            $filtered = array_intersect_key($filtered, $with_tx);
        }

        return $filtered;
    }

    // =====================================================================
    // AJAX: подсчёт получателей
    // =====================================================================

    public function handle_ajax_preview_count(): void {
        if (!check_ajax_referer('cashback_broadcast_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => __('Ошибка безопасности.', 'cashback-plugin') ));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => __('Недостаточно прав.', 'cashback-plugin') ));
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Rекурсивно валидируется в sanitize_filters() (whitelist).
        $filters_raw = isset($_POST['filters']) && is_array($_POST['filters']) ? wp_unslash($_POST['filters']) : array();
        $filters     = $this->sanitize_filters($filters_raw);
        $recipients  = $this->build_recipients($filters);

        wp_send_json_success(array(
            'count'   => count($recipients),
            'filters' => $filters,
        ));
    }

    // =====================================================================
    // AJAX: HTML-предпросмотр письма
    // =====================================================================

    public function handle_ajax_preview_html(): void {
        if (!check_ajax_referer('cashback_broadcast_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => __('Ошибка безопасности.', 'cashback-plugin') ));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => __('Недостаточно прав.', 'cashback-plugin') ));
        }

        $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
        $body    = isset($_POST['body']) ? wp_kses_post(wp_unslash($_POST['body'])) : '';

        if ($subject === '' || $body === '') {
            wp_send_json_error(array( 'message' => __('Заполните тему и тело.', 'cashback-plugin') ));
        }

        if (!class_exists('Cashback_Email_Sender')) {
            wp_send_json_error(array( 'message' => __('Email Sender недоступен.', 'cashback-plugin') ));
        }

        $html = Cashback_Email_Sender::get_instance()->preview_html($subject, $body, get_current_user_id());
        wp_send_json_success(array( 'html' => $html ));
    }

    // =====================================================================
    // AJAX: создание кампании
    // =====================================================================

    public function handle_ajax_create_campaign(): void {
        global $wpdb;

        if (!check_ajax_referer('cashback_broadcast_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => __('Ошибка безопасности.', 'cashback-plugin') ));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => __('Недостаточно прав.', 'cashback-plugin') ));
        }

        $uuid_raw = isset($_POST['campaign_uuid']) ? sanitize_text_field(wp_unslash($_POST['campaign_uuid'])) : '';
        $uuid     = preg_match('/^[a-f0-9]{32}$/', $uuid_raw) === 1 ? $uuid_raw : cashback_generate_uuid7(false);

        // Идемпотентность: если UUID уже существует — возвращаем существующую кампанию.
        $existing = $wpdb->get_row($wpdb->prepare(
            'SELECT id, status FROM %i WHERE campaign_uuid = %s',
            self::table_campaigns(),
            $uuid
        ), ARRAY_A);
        if ($existing) {
            wp_send_json_success(array(
                'campaign_id' => (int) $existing['id'],
                'status'      => (string) $existing['status'],
                'message'     => __('Кампания уже создана.', 'cashback-plugin'),
            ));
        }

        // Одна активная кампания за раз.
        $active = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i WHERE status IN ('queued','sending') LIMIT 1",
            self::table_campaigns()
        ));
        if ($active) {
            wp_send_json_error(array(
                'message' => __('Уже есть активная рассылка. Дождитесь её завершения или отмените.', 'cashback-plugin'),
            ));
        }

        $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
        $body    = isset($_POST['body'])
            ? wp_kses_post(wp_unslash($_POST['body']))
            : '';
        $subject = trim($subject);
        $body    = trim($body);

        if (mb_strlen($subject) < 3 || mb_strlen($subject) > 200) {
            wp_send_json_error(array( 'message' => __('Тема должна содержать 3–200 символов.', 'cashback-plugin') ));
        }
        if ($body === '') {
            wp_send_json_error(array( 'message' => __('Тело письма не может быть пустым.', 'cashback-plugin') ));
        }

        if (class_exists('Cashback_Notifications_DB')
            && !Cashback_Notifications_DB::is_globally_enabled(self::NOTIFICATION_TYPE)) {
            wp_send_json_error(array(
                'message' => __('Тип «Массовые рассылки» выключен в настройках уведомлений. Включите его, иначе письма не будут отправляться.', 'cashback-plugin'),
            ));
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Rекурсивно валидируется в sanitize_filters() (whitelist).
        $filters_raw = isset($_POST['filters']) && is_array($_POST['filters']) ? wp_unslash($_POST['filters']) : array();
        $filters     = $this->sanitize_filters($filters_raw);
        $recipients  = $this->build_recipients($filters);

        if (empty($recipients)) {
            wp_send_json_error(array( 'message' => __('По выбранным фильтрам не нашлось ни одного получателя.', 'cashback-plugin') ));
        }

        $now = current_time('mysql');

        // Вставка кампании.
        $ok = $wpdb->insert(
            self::table_campaigns(),
            array(
                'campaign_uuid'    => $uuid,
                'subject'          => $subject,
                'body_html'        => $body,
                'audience_filters' => wp_json_encode($filters, JSON_UNESCAPED_UNICODE),
                'total_recipients' => count($recipients),
                'sent_count'       => 0,
                'failed_count'     => 0,
                'status'           => 'queued',
                'created_by'       => get_current_user_id(),
                'created_at'       => $now,
            ),
            array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s' )
        );
        if (!$ok) {
            wp_send_json_error(array( 'message' => __('Не удалось создать кампанию.', 'cashback-plugin') ));
        }
        $campaign_id = (int) $wpdb->insert_id;

        // Наполнение очереди батчами (мультирядные INSERT).
        $queue_table = self::table_queue();
        $batch       = array();
        $batch_size  = 500;
        foreach ($recipients as $user_id => $email) {
            $batch[] = array( $campaign_id, (int) $user_id, $email );
            if (count($batch) >= $batch_size) {
                $this->insert_queue_batch($queue_table, $batch);
                $batch = array();
            }
        }
        if (!empty($batch)) {
            $this->insert_queue_batch($queue_table, $batch);
        }

        // Audit-log.
        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'broadcast_created',
                get_current_user_id(),
                'broadcast',
                $campaign_id,
                array(
                    'subject'          => $subject,
                    'total_recipients' => count($recipients),
                    'filters'          => $filters,
                )
            );
        }

        wp_send_json_success(array(
            'campaign_id'      => $campaign_id,
            'status'           => 'queued',
            'total_recipients' => count($recipients),
            'message'          => __('Кампания создана и поставлена в очередь. Отправка начнётся в течение минуты.', 'cashback-plugin'),
        ));
    }

    /**
     * Мультирядный INSERT в очередь.
     *
     * @param string                      $table Имя таблицы.
     * @param array<int,array{0:int,1:int,2:string}> $rows  Батч: [[campaign_id, user_id, email], ...]
     */
    private function insert_queue_batch( string $table, array $rows ): void {
        global $wpdb;

        if (empty($rows)) {
            return;
        }

        $placeholders = array();
        $values       = array();
        foreach ($rows as $row) {
            $placeholders[] = '(%d, %d, %s)';
            $values[]       = $row[0];
            $values[]       = $row[1];
            $values[]       = $row[2];
        }

        $sql = 'INSERT IGNORE INTO %i (campaign_id, user_id, email) VALUES '
            . implode(',', $placeholders);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Placeholders статически сформированы; значения биндятся через prepare().
        $wpdb->query($wpdb->prepare($sql, array_merge(array( $table ), $values)));
    }

    // =====================================================================
    // AJAX: отмена кампании
    // =====================================================================

    public function handle_ajax_cancel_campaign(): void {
        global $wpdb;

        if (!check_ajax_referer('cashback_broadcast_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => __('Ошибка безопасности.', 'cashback-plugin') ));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => __('Недостаточно прав.', 'cashback-plugin') ));
        }

        $campaign_id = isset($_POST['campaign_id']) ? (int) $_POST['campaign_id'] : 0;
        if ($campaign_id <= 0) {
            wp_send_json_error(array( 'message' => __('Неверный ID кампании.', 'cashback-plugin') ));
        }

        $status = $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM %i WHERE id = %d',
            self::table_campaigns(),
            $campaign_id
        ));
        if ($status === null) {
            wp_send_json_error(array( 'message' => __('Кампания не найдена.', 'cashback-plugin') ));
        }
        if (!in_array($status, array( 'queued', 'sending' ), true)) {
            wp_send_json_error(array( 'message' => __('Эту кампанию нельзя отменить.', 'cashback-plugin') ));
        }

        // Пометить оставшиеся pending как failed с причиной 'cancelled'.
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status = 'failed', error = 'cancelled', processed_at = %s
              WHERE campaign_id = %d AND status = 'pending'",
            self::table_queue(),
            current_time('mysql'),
            $campaign_id
        ));

        // Обновить счётчик failed и статус кампании.
        $failed_total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE campaign_id = %d AND status = 'failed'",
            self::table_queue(),
            $campaign_id
        ));
        $sent_total   = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE campaign_id = %d AND status = 'sent'",
            self::table_queue(),
            $campaign_id
        ));
        $wpdb->update(
            self::table_campaigns(),
            array(
                'status'       => 'cancelled',
                'failed_count' => $failed_total,
                'sent_count'   => $sent_total,
                'completed_at' => current_time('mysql'),
            ),
            array( 'id' => $campaign_id ),
            array( '%s', '%d', '%d', '%s' ),
            array( '%d' )
        );

        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'broadcast_cancelled',
                get_current_user_id(),
                'broadcast',
                $campaign_id,
                array(
					'sent_count'   => $sent_total,
					'failed_count' => $failed_total,
				)
            );
        }

        wp_send_json_success(array( 'message' => __('Кампания отменена.', 'cashback-plugin') ));
    }

    // =====================================================================
    // AJAX: статус кампании (для polling)
    // =====================================================================

    public function handle_ajax_status(): void {
        global $wpdb;

        if (!check_ajax_referer('cashback_broadcast_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => __('Ошибка безопасности.', 'cashback-plugin') ));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => __('Недостаточно прав.', 'cashback-plugin') ));
        }

        $campaign_id = isset($_GET['campaign_id']) ? (int) $_GET['campaign_id'] : 0;
        if ($campaign_id <= 0) {
            wp_send_json_error(array( 'message' => __('Неверный ID.', 'cashback-plugin') ));
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, status, total_recipients, sent_count, failed_count, completed_at
               FROM %i WHERE id = %d',
            self::table_campaigns(),
            $campaign_id
        ), ARRAY_A);

        if (!$row) {
            wp_send_json_error(array( 'message' => __('Кампания не найдена.', 'cashback-plugin') ));
        }

        wp_send_json_success(array(
            'campaign_id'      => (int) $row['id'],
            'status'           => (string) $row['status'],
            'total_recipients' => (int) $row['total_recipients'],
            'sent_count'       => (int) $row['sent_count'],
            'failed_count'     => (int) $row['failed_count'],
            'completed_at'     => $row['completed_at'],
        ));
    }

    // =====================================================================
    // Cron: обработка очереди
    // =====================================================================

    public function process_queue(): void {
        global $wpdb;

        // Найти активную кампанию (queued или sending, ближайшая).
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT id, subject, body_html, status FROM %i
              WHERE status IN ('queued','sending')
              ORDER BY created_at ASC
              LIMIT 1",
            self::table_campaigns()
        ), ARRAY_A);
        if (!$campaign) {
            return;
        }

        $campaign_id = (int) $campaign['id'];
        $subject     = (string) $campaign['subject'];
        $body        = (string) $campaign['body_html'];

        // Переводим queued → sending.
        if ($campaign['status'] === 'queued') {
            $wpdb->update(
                self::table_campaigns(),
                array( 'status' => 'sending' ),
                array( 'id' => $campaign_id ),
                array( '%s' ),
                array( '%d' )
            );
        }

        $batch_size = self::batch_size();
        $max_att    = self::MAX_ATTEMPTS;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, email, attempts FROM %i
              WHERE campaign_id = %d AND status = 'pending' AND attempts < %d
              ORDER BY id ASC
              LIMIT %d",
            self::table_queue(),
            $campaign_id,
            $max_att,
            $batch_size
        ), ARRAY_A);

        if (!empty($rows) && class_exists('Cashback_Email_Sender')) {
            $sender       = Cashback_Email_Sender::get_instance();
            $sent_delta   = 0;
            $failed_delta = 0;
            $now          = current_time('mysql');

            foreach ($rows as $row) {
                $queue_id = (int) $row['id'];
                $user_id  = (int) $row['user_id'];
                $email    = (string) $row['email'];
                $attempts = (int) $row['attempts'] + 1;
                $is_last  = $attempts >= $max_att;

                $ok = false;
                try {
                    $ok = $sender->send($email, $subject, $body, self::NOTIFICATION_TYPE, $user_id);
                } catch (\Throwable $e) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging for broadcast send failure.
                    error_log('[Cashback Broadcast] send failed: ' . $e->getMessage());
                    $ok = false;
                }

                if ($ok) {
                    $wpdb->update(
                        self::table_queue(),
                        array(
                            'status'       => 'sent',
                            'attempts'     => $attempts,
                            'processed_at' => $now,
                        ),
                        array( 'id' => $queue_id ),
                        array( '%s', '%d', '%s' ),
                        array( '%d' )
                    );
                    ++$sent_delta;
                } else {
                    $new_status = $is_last ? 'failed' : 'pending';
                    $error      = $this->resolve_send_error($user_id);
                    $wpdb->update(
                        self::table_queue(),
                        array(
                            'status'       => $new_status,
                            'attempts'     => $attempts,
                            'error'        => $error,
                            'processed_at' => $is_last ? $now : null,
                        ),
                        array( 'id' => $queue_id ),
                        array( '%s', '%d', '%s', '%s' ),
                        array( '%d' )
                    );
                    if ($is_last) {
                        ++$failed_delta;
                    }
                }
            }

            if ($sent_delta > 0 || $failed_delta > 0) {
                $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET sent_count = sent_count + %d, failed_count = failed_count + %d
                      WHERE id = %d',
                    self::table_campaigns(),
                    $sent_delta,
                    $failed_delta,
                    $campaign_id
                ));
            }
        }

        // Есть ли ещё pending для этой кампании?
        $still_pending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE campaign_id = %d AND status = 'pending' AND attempts < %d",
            self::table_queue(),
            $campaign_id,
            $max_att
        ));

        if ($still_pending === 0) {
            // Переводим ретраибельные, у которых attempts>=max в failed окончательно.
            $wpdb->query($wpdb->prepare(
                "UPDATE %i SET status = 'failed', processed_at = %s
                  WHERE campaign_id = %d AND status = 'pending' AND attempts >= %d",
                self::table_queue(),
                current_time('mysql'),
                $campaign_id,
                $max_att
            ));

            // Финализируем счётчики и статус кампании.
            $sent_total   = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE campaign_id = %d AND status = 'sent'",
                self::table_queue(),
                $campaign_id
            ));
            $failed_total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE campaign_id = %d AND status = 'failed'",
                self::table_queue(),
                $campaign_id
            ));

            $wpdb->update(
                self::table_campaigns(),
                array(
                    'status'       => 'done',
                    'sent_count'   => $sent_total,
                    'failed_count' => $failed_total,
                    'completed_at' => current_time('mysql'),
                ),
                array( 'id' => $campaign_id ),
                array( '%s', '%d', '%d', '%s' ),
                array( '%d' )
            );

            if (class_exists('Cashback_Encryption')) {
                Cashback_Encryption::write_audit_log(
                    'broadcast_completed',
                    0,
                    'broadcast',
                    $campaign_id,
                    array(
						'sent_count'   => $sent_total,
						'failed_count' => $failed_total,
					)
                );
            }
        }
    }

    /**
     * Вычисляем причину отказа (для колонки `error` в очереди).
     */
    private function resolve_send_error( int $user_id ): string {
        if (class_exists('Cashback_Notifications_DB')) {
            if (!Cashback_Notifications_DB::is_globally_enabled(self::NOTIFICATION_TYPE)) {
                return 'type_disabled_globally';
            }
            if ($user_id > 0 && !Cashback_Notifications_DB::is_enabled($user_id, self::NOTIFICATION_TYPE)) {
                return 'user_opted_out';
            }
        }
        return 'wp_mail_failed';
    }

    // =====================================================================
    // Рендер вкладки «Рассылка»
    // =====================================================================

    public function render_broadcast_tab(): void {
        global $wpdb;

        if (!current_user_can('manage_options')) {
            return;
        }

        // История кампаний (пагинация).
        $per_page = 10;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Пагинация истории в UI, не обработка форм.
        $paged  = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $offset = ( $paged - 1 ) * $per_page;

        $total     = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i',
            self::table_campaigns()
        ));
        $campaigns = $wpdb->get_results($wpdb->prepare(
            'SELECT id, subject, audience_filters, total_recipients, sent_count, failed_count,
                    status, created_by, created_at, completed_at
               FROM %i
              ORDER BY created_at DESC
              LIMIT %d OFFSET %d',
            self::table_campaigns(),
            $per_page,
            $offset
        ), ARRAY_A);

        $total_pages = (int) ceil($total / $per_page);

        $default_filters = array(
            'statuses'     => array( 'active' ),
            'roles'        => array( 'customer' ),
            'has_cashback' => false,
        );
        ?>
        <div class="cashback-broadcast-wrap">

            <div class="cashback-admin-card">
                <h2><?php esc_html_e('Создать рассылку', 'cashback-plugin'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Одноразовая email-рассылка выбранной аудитории. Письмо будет оформлено в стандартный шаблон плагина. Пользователи, отключившие «Массовые рассылки» в настройках уведомлений, не получат письмо.', 'cashback-plugin'); ?>
                </p>

                <form id="cashback-broadcast-form">
                    <input type="hidden" name="campaign_uuid" id="cashback-broadcast-uuid" value="<?php echo esc_attr(cashback_generate_uuid7(false)); ?>" />

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="cashback-broadcast-subject"><?php esc_html_e('Тема письма', 'cashback-plugin'); ?> <span style="color:#d63638;">*</span></label>
                            </th>
                            <td>
                                <input type="text" id="cashback-broadcast-subject" name="subject" class="regular-text" maxlength="200" required />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="cashback-broadcast-body"><?php esc_html_e('Тело письма', 'cashback-plugin'); ?> <span style="color:#d63638;">*</span></label>
                            </th>
                            <td>
                                <?php
                                wp_editor(
                                    '',
                                    'cashback-broadcast-body',
                                    array(
                                        'textarea_name' => 'body',
                                        'textarea_rows' => 12,
                                        'media_buttons' => false,
                                        'teeny'         => true,
                                        'quicktags'     => true,
                                    )
                                );
                                ?>
                                <p class="description"><?php esc_html_e('Допустимы HTML-теги, разрешённые wp_kses_post. Письмо будет обёрнуто в общий шаблон плагина (шапка, футер с ссылкой на настройки уведомлений).', 'cashback-plugin'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Аудитория', 'cashback-plugin'); ?></th>
                            <td>
                                <fieldset class="cashback-broadcast-filter-group">
                                    <legend><strong><?php esc_html_e('Статус профиля:', 'cashback-plugin'); ?></strong></legend>
                                    <?php
                                    foreach (self::allowed_statuses() as $slug => $label) :
                                        $checked = in_array($slug, $default_filters['statuses'], true);
                                        ?>
                                        <label class="cashback-broadcast-check">
                                            <input type="checkbox" name="filters[statuses][]" value="<?php echo esc_attr($slug); ?>" <?php checked($checked); ?> />
                                            <?php echo esc_html($label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>

                                <fieldset class="cashback-broadcast-filter-group">
                                    <legend><strong><?php esc_html_e('Роль:', 'cashback-plugin'); ?></strong></legend>
                                    <?php
                                    foreach (self::allowed_roles() as $slug => $label) :
                                        $checked = in_array($slug, $default_filters['roles'], true);
                                        ?>
                                        <label class="cashback-broadcast-check">
                                            <input type="checkbox" name="filters[roles][]" value="<?php echo esc_attr($slug); ?>" <?php checked($checked); ?> />
                                            <?php echo esc_html($label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>

                                <fieldset class="cashback-broadcast-filter-group">
                                    <label class="cashback-broadcast-check">
                                        <input type="checkbox" name="filters[has_cashback]" value="1" />
                                        <?php esc_html_e('Только пользователи, у которых была хотя бы одна транзакция кэшбэка', 'cashback-plugin'); ?>
                                    </label>
                                </fieldset>

                                <p class="cashback-broadcast-count">
                                    <?php esc_html_e('Получателей:', 'cashback-plugin'); ?>
                                    <strong id="cashback-broadcast-count-value">—</strong>
                                    <button type="button" class="button" id="cashback-broadcast-count-btn"><?php esc_html_e('Пересчитать', 'cashback-plugin'); ?></button>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="button" class="button" id="cashback-broadcast-preview-btn"><?php esc_html_e('Предпросмотр', 'cashback-plugin'); ?></button>
                        <button type="submit" class="button button-primary" id="cashback-broadcast-submit-btn"><?php esc_html_e('Отправить рассылку', 'cashback-plugin'); ?></button>
                        <span class="cashback-broadcast-status" id="cashback-broadcast-status"></span>
                    </p>
                </form>
            </div>

            <div class="cashback-admin-card">
                <h2><?php esc_html_e('История рассылок', 'cashback-plugin'); ?></h2>

                <?php if (empty($campaigns)) : ?>
                    <p class="description"><?php esc_html_e('Рассылок пока не было.', 'cashback-plugin'); ?></p>
                <?php else : ?>
                    <table class="widefat striped cashback-broadcast-history">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Дата', 'cashback-plugin'); ?></th>
                                <th><?php esc_html_e('Тема', 'cashback-plugin'); ?></th>
                                <th><?php esc_html_e('Получатели', 'cashback-plugin'); ?></th>
                                <th><?php esc_html_e('Отправлено', 'cashback-plugin'); ?></th>
                                <th><?php esc_html_e('Ошибок', 'cashback-plugin'); ?></th>
                                <th><?php esc_html_e('Статус', 'cashback-plugin'); ?></th>
                                <th><?php esc_html_e('Действия', 'cashback-plugin'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($campaigns as $c) :
                                $cid        = (int) $c['id'];
                                $status     = (string) $c['status'];
                                $filters    = json_decode((string) ( $c['audience_filters'] ?? '' ), true);
                                $filters    = is_array($filters) ? $filters : array();
                                $author     = get_userdata((int) $c['created_by']);
                                $author_str = $author ? $author->display_name : '#' . (int) $c['created_by'];
                                ?>
                                <tr data-campaign-id="<?php echo esc_attr((string) $cid); ?>">
                                    <td>
                                        <?php echo esc_html(mysql2date('d.m.Y H:i', (string) $c['created_at'])); ?>
                                        <br><small><?php echo esc_html($author_str); ?></small>
                                    </td>
                                    <td><?php echo esc_html((string) $c['subject']); ?></td>
                                    <td><?php echo esc_html(number_format_i18n((int) $c['total_recipients'])); ?></td>
                                    <td class="cashback-broadcast-sent"><?php echo esc_html(number_format_i18n((int) $c['sent_count'])); ?></td>
                                    <td class="cashback-broadcast-failed"><?php echo esc_html(number_format_i18n((int) $c['failed_count'])); ?></td>
                                    <td>
                                        <span class="cashback-broadcast-badge cashback-broadcast-badge-<?php echo esc_attr($status); ?>">
                                            <?php echo esc_html(self::status_label($status)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (in_array($status, array( 'queued', 'sending' ), true)) : ?>
                                            <button type="button" class="button button-small cashback-broadcast-cancel" data-campaign-id="<?php echo esc_attr((string) $cid); ?>">
                                                <?php esc_html_e('Отмена', 'cashback-plugin'); ?>
                                            </button>
                                        <?php else : ?>
                                            <span class="description">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php
                    if ($total_pages > 1 && class_exists('Cashback_Pagination')) :
                        Cashback_Pagination::render(array(
                            'mode'         => 'link',
                            'total_items'  => $total,
                            'current_page' => $paged,
                            'total_pages'  => $total_pages,
                            'page_slug'    => 'cashback-notifications',
                            'add_args'     => array( 'tab' => 'broadcast' ),
                        ));
                    endif;
                    ?>
                <?php endif; ?>
            </div>

            <div id="cashback-broadcast-preview-modal" class="cashback-broadcast-modal" style="display:none;">
                <div class="cashback-broadcast-modal-inner">
                    <div class="cashback-broadcast-modal-head">
                        <h3><?php esc_html_e('Предпросмотр письма', 'cashback-plugin'); ?></h3>
                        <button type="button" class="button" id="cashback-broadcast-preview-close">&times;</button>
                    </div>
                    <div class="cashback-broadcast-modal-body">
                        <iframe id="cashback-broadcast-preview-frame" title="preview" sandbox></iframe>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Человекочитаемое имя статуса кампании.
     */
    public static function status_label( string $status ): string {
        switch ($status) {
            case 'queued':
                return __('В очереди', 'cashback-plugin');
            case 'sending':
                return __('Отправляется', 'cashback-plugin');
            case 'done':
                return __('Завершено', 'cashback-plugin');
            case 'cancelled':
                return __('Отменено', 'cashback-plugin');
            default:
                return $status;
        }
    }
}
