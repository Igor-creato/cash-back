<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Мобильный фасад над модулем Support (тикеты пользователя).
 *
 * Переиспользует `Cashback_Support_DB` для валидации файлов, лимитов, upload/serve.
 * Нормальные CRUD-операции (create ticket/message, close) — через прямой INSERT/UPDATE
 * в транзакции, без дублирования бизнес-логики, но без AJAX-зависимостей (nonce/HTML).
 *
 * Вложения скачиваются через HMAC-подписанный URL (см. {@see Cashback_Mobile_Signed_URL}).
 *
 * @since 1.1.0
 */
class Cashback_Mobile_Support_Service {

    public const MAX_PER_PAGE    = 50;
    public const SIGNED_RESOURCE = 'ticket_attachment';

    /**
     * Список тикетов пользователя.
     */
    public static function paginate_tickets( int $user_id, int $page, int $per_page, string $status = '' ): array {
        $page     = max(1, $page);
        $per_page = max(1, min(self::MAX_PER_PAGE, $per_page));
        $offset   = ( $page - 1 ) * $per_page;

        global $wpdb;
        $tickets  = $wpdb->prefix . 'cashback_support_tickets';
        $messages = $wpdb->prefix . 'cashback_support_messages';

        $where  = array( 'user_id = %d' );
        $params = array( $user_id );

        if ('' !== $status && in_array($status, array( 'open', 'answered', 'closed' ), true)) {
            $where[]  = 'status = %s';
            $params[] = $status;
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM `{$tickets}` WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $tickets/$where_sql controlled.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-scoped count.
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));

        $list_sql = "SELECT t.*,
                         (SELECT COUNT(*) FROM `{$messages}` m
                          WHERE m.ticket_id = t.id AND m.is_admin = 1 AND m.is_read = 0) AS unread_count
                     FROM `{$tickets}` t
                     WHERE {$where_sql}
                     ORDER BY t.updated_at DESC, t.id DESC
                     LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- static fragments + controlled $where_sql.

        $list_params = array_merge($params, array( $per_page, $offset ));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- dynamic WHERE, params prepared.
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, ...$list_params), ARRAY_A);

        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = array(
                'id'           => (int) $row['id'],
                'subject'      => (string) $row['subject'],
                'priority'     => (string) $row['priority'],
                'status'       => (string) $row['status'],
                'related_type' => $row['related_type'] ? (string) $row['related_type'] : null,
                'related_id'   => null === $row['related_id'] ? null : (int) $row['related_id'],
                'created_at'   => (string) $row['created_at'],
                'updated_at'   => (string) $row['updated_at'],
                'closed_at'    => null === $row['closed_at'] ? null : (string) $row['closed_at'],
                'unread_count' => (int) $row['unread_count'],
            );
        }

        return array(
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => max(1, (int) ceil($total / $per_page)),
        );
    }

    /**
     * Детали тикета + сообщения + вложения + auto mark admin messages as read.
     *
     * @return array|WP_Error
     */
    public static function get_ticket( int $user_id, int $ticket_id ) {
        if ($ticket_id <= 0) {
            return new WP_Error('invalid_ticket', __('Invalid ticket id.', 'cashback'), array( 'status' => 400 ));
        }

        global $wpdb;
        $tickets  = $wpdb->prefix . 'cashback_support_tickets';
        $messages = $wpdb->prefix . 'cashback_support_messages';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- ownership check.
        $ticket = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE id = %d AND user_id = %d',
                $tickets,
                $ticket_id,
                $user_id
            ),
            ARRAY_A
        );
        if (!$ticket) {
            return new WP_Error('ticket_not_found', __('Ticket not found.', 'cashback'), array( 'status' => 404 ));
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-owned messages.
        $msg_rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, user_id, message, is_admin, is_read, created_at
                 FROM %i WHERE ticket_id = %d ORDER BY created_at ASC, id ASC',
                $messages,
                $ticket_id
            ),
            ARRAY_A
        );

        $attachments_map = array();
        if (!empty($msg_rows) && class_exists('Cashback_Support_DB')) {
            $ids             = array_map(static fn( $m ) => (int) $m['id'], $msg_rows);
            $attachments_map = (array) Cashback_Support_DB::get_attachments_for_messages($ids);
        }

        $out_messages = array();
        foreach ((array) $msg_rows as $m) {
            $mid   = (int) $m['id'];
            $attfs = isset($attachments_map[ $mid ]) && is_array($attachments_map[ $mid ]) ? $attachments_map[ $mid ] : array();
            $att   = array();
            foreach ($attfs as $a) {
                $att_id = (int) ( is_array($a) ? ( $a['id'] ?? 0 ) : ( $a->id ?? 0 ) );
                $name   = (string) ( is_array($a) ? ( $a['file_name'] ?? '' ) : ( $a->file_name ?? '' ) );
                $size   = (int) ( is_array($a) ? ( $a['file_size'] ?? 0 ) : ( $a->file_size ?? 0 ) );
                $mime   = (string) ( is_array($a) ? ( $a['mime_type'] ?? '' ) : ( $a->mime_type ?? '' ) );
                if ($att_id <= 0) {
                    continue;
                }
                $att[] = array(
                    'id'           => $att_id,
                    'file_name'    => $name,
                    'file_size'    => $size,
                    'mime_type'    => $mime,
                    'download_url' => self::sign_download_url($user_id, $ticket_id, $att_id),
                );
            }
            $out_messages[] = array(
                'id'          => $mid,
                'is_admin'    => (bool) $m['is_admin'],
                'is_read'     => (bool) $m['is_read'],
                'message'     => (string) $m['message'],
                'created_at'  => (string) $m['created_at'],
                'attachments' => $att,
            );
        }

        // Отмечаем все admin-сообщения прочитанными (юзер открыл тикет).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- mark as read by owner.
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET is_read = 1 WHERE ticket_id = %d AND is_admin = 1 AND is_read = 0',
                $messages,
                $ticket_id
            )
        );

        return array(
            'ticket'       => array(
                'id'           => (int) $ticket['id'],
                'subject'      => (string) $ticket['subject'],
                'priority'     => (string) $ticket['priority'],
                'status'       => (string) $ticket['status'],
                'related_type' => $ticket['related_type'] ? (string) $ticket['related_type'] : null,
                'related_id'   => null === $ticket['related_id'] ? null : (int) $ticket['related_id'],
                'created_at'   => (string) $ticket['created_at'],
                'updated_at'   => (string) $ticket['updated_at'],
                'closed_at'    => null === $ticket['closed_at'] ? null : (string) $ticket['closed_at'],
            ),
            'messages'     => $out_messages,
            'unread_count' => 0, // После mark-as-read.
        );
    }

    /**
     * Создание нового тикета + первое сообщение (+ опциональные файлы).
     *
     * @param array $data {subject, body, priority?, related_type?, related_id?}
     * @param array $files Сырой $_FILES['support_files'] массив (может быть пустым).
     * @return array|WP_Error
     */
    public static function create_ticket( int $user_id, array $data, array $files = array() ) {
        if (!class_exists('Cashback_Support_DB') || !Cashback_Support_DB::is_module_enabled()) {
            return new WP_Error('support_disabled', __('Support module is not enabled.', 'cashback'), array( 'status' => 503 ));
        }

        $subject = trim((string) ( $data['subject'] ?? '' ));
        $body    = trim((string) ( $data['body'] ?? '' ));
        if ('' === $subject || '' === $body) {
            return new WP_Error('invalid_params', __('subject and body are required.', 'cashback'), array( 'status' => 400 ));
        }
        if (mb_strlen($subject) > 255) {
            $subject = mb_substr($subject, 0, 255);
        }

        $priority = (string) ( $data['priority'] ?? 'not_urgent' );
        if (!in_array($priority, array( 'urgent', 'normal', 'not_urgent' ), true)) {
            $priority = 'not_urgent';
        }

        $related_type = (string) ( $data['related_type'] ?? '' );
        $related_id   = (int) ( $data['related_id'] ?? 0 );
        if ('' !== $related_type && !in_array($related_type, array( 'cashback_tx', 'affiliate_accrual', 'payout' ), true)) {
            return new WP_Error('invalid_related_type', __('Invalid related_type.', 'cashback'), array( 'status' => 400 ));
        }

        global $wpdb;
        $tickets  = $wpdb->prefix . 'cashback_support_tickets';
        $messages = $wpdb->prefix . 'cashback_support_messages';

        // Rate limit: 5 тикетов/час.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- rate-limit guard.
        $recent = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE user_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
                $tickets,
                $user_id
            )
        );
        if ($recent >= 5) {
            return new WP_Error('rate_limited', __('Too many new tickets. Try again later.', 'cashback'), array( 'status' => 429 ));
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema transaction.
        $wpdb->query('START TRANSACTION');
        try {
            $insert = array(
                'user_id'      => $user_id,
                'subject'      => $subject,
                'priority'     => $priority,
                'status'       => 'open',
                'related_type' => '' === $related_type ? null : $related_type,
                'related_id'   => $related_id > 0 ? $related_id : null,
                'created_at'   => current_time('mysql'),
                'updated_at'   => current_time('mysql'),
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional insert.
            $ok = $wpdb->insert($tickets, $insert, array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ));
            if (false === $ok) {
                throw new RuntimeException('Failed to create ticket: ' . esc_html((string) $wpdb->last_error));
            }
            $ticket_id = (int) $wpdb->insert_id;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional insert.
            $ok_msg = $wpdb->insert(
                $messages,
                array(
                    'ticket_id'  => $ticket_id,
                    'user_id'    => $user_id,
                    'message'    => $body,
                    'is_admin'   => 0,
                    'is_read'    => 0,
                    'created_at' => current_time('mysql'),
                ),
                array( '%d', '%d', '%s', '%d', '%d', '%s' )
            );
            if (false === $ok_msg) {
                throw new RuntimeException('Failed to create message: ' . esc_html((string) $wpdb->last_error));
            }
            $message_id = (int) $wpdb->insert_id;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction commit.
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction rollback.
            $wpdb->query('ROLLBACK');
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback][Mobile][Support] create_ticket failed: ' . $e->getMessage());
            return new WP_Error('ticket_create_failed', __('Could not create ticket.', 'cashback'), array( 'status' => 500 ));
        }

        // Загрузка файлов (после commit, чтобы не откатывать БД при сбое ввода-вывода).
        $uploaded = self::attach_uploaded_files($files, $ticket_id, $message_id, $user_id);

        do_action('cashback_mobile_ticket_created', $user_id, $ticket_id);

        return array(
            'ok'                => true,
            'ticket_id'         => $ticket_id,
            'message_id'        => $message_id,
            'attachments'       => $uploaded['success_count'],
            'attachment_errors' => $uploaded['errors'],
        );
    }

    /**
     * Ответ пользователя в существующем тикете (+ опциональные файлы).
     *
     * @return array|WP_Error
     */
    public static function reply( int $user_id, int $ticket_id, string $body, array $files = array() ) {
        if (!class_exists('Cashback_Support_DB') || !Cashback_Support_DB::is_module_enabled()) {
            return new WP_Error('support_disabled', __('Support module is not enabled.', 'cashback'), array( 'status' => 503 ));
        }
        $body = trim($body);
        if ('' === $body) {
            return new WP_Error('invalid_params', __('Message body is required.', 'cashback'), array( 'status' => 400 ));
        }

        global $wpdb;
        $tickets  = $wpdb->prefix . 'cashback_support_tickets';
        $messages = $wpdb->prefix . 'cashback_support_messages';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- ownership + status check.
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT user_id, status FROM %i WHERE id = %d', $tickets, $ticket_id),
            ARRAY_A
        );
        if (!$row || (int) $row['user_id'] !== $user_id) {
            return new WP_Error('ticket_not_found', __('Ticket not found.', 'cashback'), array( 'status' => 404 ));
        }
        if ('closed' === (string) $row['status']) {
            return new WP_Error('ticket_closed', __('Ticket is closed.', 'cashback'), array( 'status' => 409 ));
        }

        // Rate limit: 20 ответов/час.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- rate-limit guard.
        $recent = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE user_id = %d AND is_admin = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
                $messages,
                $user_id
            )
        );
        if ($recent >= 20) {
            return new WP_Error('rate_limited', __('Too many replies. Try again later.', 'cashback'), array( 'status' => 429 ));
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction start.
        $wpdb->query('START TRANSACTION');
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional insert.
            $ok = $wpdb->insert(
                $messages,
                array(
                    'ticket_id'  => $ticket_id,
                    'user_id'    => $user_id,
                    'message'    => $body,
                    'is_admin'   => 0,
                    'is_read'    => 0,
                    'created_at' => current_time('mysql'),
                ),
                array( '%d', '%d', '%s', '%d', '%d', '%s' )
            );
            if (false === $ok) {
                throw new RuntimeException('Failed to add message');
            }
            $message_id = (int) $wpdb->insert_id;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional update.
            $wpdb->update(
                $tickets,
                array(
					'status'     => 'open',
					'updated_at' => current_time('mysql'),
				),
                array( 'id' => $ticket_id ),
                array( '%s', '%s' ),
                array( '%d' )
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction commit.
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction rollback.
            $wpdb->query('ROLLBACK');
            return new WP_Error('reply_failed', __('Could not post reply.', 'cashback'), array( 'status' => 500 ));
        }

        $uploaded = self::attach_uploaded_files($files, $ticket_id, $message_id, $user_id);

        do_action('cashback_mobile_ticket_replied', $user_id, $ticket_id, $message_id);

        return array(
            'ok'                => true,
            'message_id'        => $message_id,
            'attachments'       => $uploaded['success_count'],
            'attachment_errors' => $uploaded['errors'],
        );
    }

    /**
     * Закрыть тикет (пользователем).
     */
    public static function close_ticket( int $user_id, int $ticket_id ) {
        global $wpdb;
        $tickets = $wpdb->prefix . 'cashback_support_tickets';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- ownership check.
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT user_id, status FROM %i WHERE id = %d', $tickets, $ticket_id),
            ARRAY_A
        );
        if (!$row || (int) $row['user_id'] !== $user_id) {
            return new WP_Error('ticket_not_found', __('Ticket not found.', 'cashback'), array( 'status' => 404 ));
        }
        if ('closed' === (string) $row['status']) {
            return array(
				'ok'             => true,
				'already_closed' => true,
			);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- status update.
        $wpdb->update(
            $tickets,
            array(
                'status'     => 'closed',
                'closed_at'  => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array( 'id' => $ticket_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );

        do_action('cashback_mobile_ticket_closed', $user_id, $ticket_id);

        return array( 'ok' => true );
    }

    /**
     * Скачивание вложения по HMAC-подписанному токену.
     * Выполняет wp_die/exit через serve_file — не возвращается при успехе.
     */
    public static function serve_attachment_by_token( string $token ): void {
        $verify = Cashback_Mobile_Signed_URL::verify($token);
        if (is_wp_error($verify)) {
            status_header((int) ( $verify->get_error_data()['status'] ?? 400 ));
            wp_send_json(array(
                'code'    => $verify->get_error_code(),
                'message' => $verify->get_error_message(),
            ), (int) ( $verify->get_error_data()['status'] ?? 400 ));
            return;
        }

        if (self::SIGNED_RESOURCE !== $verify['resource']) {
            wp_send_json(array(
				'code'    => 'invalid_token',
				'message' => 'Wrong token scope',
			), 400);
            return;
        }

        if (!class_exists('Cashback_Support_DB')) {
            wp_send_json(array(
				'code'    => 'support_disabled',
				'message' => 'Support not available',
			), 503);
            return;
        }

        Cashback_Support_DB::serve_file((int) $verify['resource_id'], (int) $verify['user_id'], false);
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /**
     * Пропускает $files через `Cashback_Support_DB::handle_file_upload()` с учётом лимитов.
     *
     * @return array{success_count:int, errors:array<int,string>}
     */
    private static function attach_uploaded_files( array $files, int $ticket_id, int $message_id, int $user_id ): array {
        if (empty($files) || !class_exists('Cashback_Support_DB')) {
            return array(
				'success_count' => 0,
				'errors'        => array(),
			);
        }

        $max_per_message = Cashback_Support_DB::get_max_files_per_message();
        $success         = 0;
        $errors          = array();

        // Разворачиваем PHP-структуру $_FILES (name[], tmp_name[], ...) в массив entries.
        $normalized = self::normalize_files_array($files);
        $normalized = array_slice($normalized, 0, max(1, $max_per_message));

        foreach ($normalized as $entry) {
            $result = Cashback_Support_DB::handle_file_upload($entry, $ticket_id, $message_id, $user_id);
            if (true === $result) {
                ++$success;
            } else {
                $errors[] = is_string($result) ? $result : 'upload_failed';
            }
        }

        return array(
			'success_count' => $success,
			'errors'        => $errors,
		);
    }

    /**
     * Преобразует многомерный $_FILES['support_files'] в массив entries.
     *
     * @param array $files REST-массив файлов (одиночный файл или массив).
     * @return array<int,array<string,mixed>>
     */
    private static function normalize_files_array( array $files ): array {
        // Случай 1: одиночный файл — ассоциативный массив.
        if (isset($files['name']) && !is_array($files['name'])) {
            return array( $files );
        }
        // Случай 2: массив файлов (multiple).
        if (isset($files['name']) && is_array($files['name'])) {
            $count = count($files['name']);
            $out   = array();
            for ($i = 0; $i < $count; $i++) {
                $out[] = array(
                    'name'     => $files['name'][ $i ] ?? '',
                    'type'     => $files['type'][ $i ] ?? '',
                    'tmp_name' => $files['tmp_name'][ $i ] ?? '',
                    'error'    => $files['error'][ $i ] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $files['size'][ $i ] ?? 0,
                );
            }
            return $out;
        }
        // Случай 3: список ассоциативных массивов (уже нормализован).
        $out = array();
        foreach ($files as $f) {
            if (is_array($f) && isset($f['tmp_name'])) {
                $out[] = $f;
            }
        }
        return $out;
    }

    private static function sign_download_url( int $user_id, int $ticket_id, int $attachment_id ): string {
        try {
            $token = Cashback_Mobile_Signed_URL::sign($user_id, self::SIGNED_RESOURCE, $attachment_id);
        } catch (\Throwable $e) {
            return '';
        }
        return Cashback_Mobile_Signed_URL::build_download_url($ticket_id, $attachment_id, $token);
    }
}
