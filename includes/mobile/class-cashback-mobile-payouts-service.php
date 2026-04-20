<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Выплаты для мобильного клиента.
 *
 * Реализовано:
 *   - list_methods()          — активные методы выплаты (СБП, карта и т.п.)
 *   - search_banks(q)         — поиск банков для выбора (СБП bank_id)
 *   - paginate_history()      — история заявок пользователя
 *   - get_settings()          — сохранённые реквизиты пользователя (masked)
 *
 * НЕ реализовано в этой фазе (намеренно — требует рефакторинга `CashbackWithdrawal`):
 *   - POST /me/payouts (create) — транзакционная логика c FOR UPDATE + idempotency + AES-GCM
 *   - PUT  /me/payouts/settings — обновление реквизитов через AES-GCM
 *
 * Для их безопасной реализации в мобильном контексте существующий AJAX-handler
 * `CashbackWithdrawal::process_cashback_withdrawal()` должен быть извлечён
 * в сервисный класс `Cashback_Withdrawal_Service` с сигнатурой, не завязанной на `$_POST`.
 *
 * @since 1.1.0
 */
class Cashback_Mobile_Payouts_Service {

    public const MAX_PER_PAGE = 50;

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function list_methods(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_payout_methods';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reference data read.
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, slug, name, bank_required, sort_order FROM %i WHERE is_active = 1 ORDER BY sort_order ASC, name ASC',
                $table
            ),
            ARRAY_A
        );
        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = array(
                'id'            => (int) $row['id'],
                'slug'          => (string) $row['slug'],
                'name'          => (string) $row['name'],
                'bank_required' => (bool) ( (int) $row['bank_required'] ),
                'sort_order'    => (int) $row['sort_order'],
            );
        }
        return $items;
    }

    /**
     * Поиск активных банков (LIKE по name).
     * Если $q пустой — первые 10 активных по sort_order.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function search_banks( string $q ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_banks';
        $q     = trim($q);

        if ('' === $q) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reference data.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id, bank_code, name, short_name FROM %i WHERE is_active = 1 ORDER BY sort_order ASC, name ASC LIMIT 10',
                    $table
                ),
                ARRAY_A
            );
        } else {
            $like = '%' . $wpdb->esc_like($q) . '%';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reference data.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id, bank_code, name, short_name FROM %i
                     WHERE is_active = 1 AND (name LIKE %s OR short_name LIKE %s)
                     ORDER BY sort_order ASC, name ASC LIMIT 20',
                    $table,
                    $like,
                    $like
                ),
                ARRAY_A
            );
        }

        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = array(
                'id'         => (int) $row['id'],
                'bank_code'  => (string) $row['bank_code'],
                'name'       => (string) $row['name'],
                'short_name' => (string) ( $row['short_name'] ?? '' ),
            );
        }
        return $items;
    }

    /**
     * История заявок пользователя.
     *
     * @return array {items, total, page, per_page, pages}
     * @throws InvalidArgumentException Если user_id не положительный (защита от IDOR при future-refactor).
     */
    public static function paginate_history( int $user_id, array $args ): array {
        if ($user_id <= 0) {
            throw new InvalidArgumentException('paginate_history: user_id must be > 0');
        }
        $page     = max(1, (int) ( $args['page'] ?? 1 ));
        $per_page = max(1, min(self::MAX_PER_PAGE, (int) ( $args['per_page'] ?? 10 )));
        $offset   = ( $page - 1 ) * $per_page;

        $where  = array( 'user_id = %d' );
        $params = array( $user_id );

        $status = (string) ( $args['status'] ?? '' );
        if ('' !== $status) {
            $allowed = array( 'waiting', 'processing', 'paid', 'failed', 'declined', 'needs_retry' );
            if (in_array($status, $allowed, true)) {
                $where[]  = 'status = %s';
                $params[] = $status;
            }
        }

        global $wpdb;
        $table     = $wpdb->prefix . 'cashback_payout_requests';
        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $table/$where_sql controlled; values bound.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-scoped count.
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));

        $list_sql = "SELECT id, reference_id, total_amount, payout_method, payout_account, masked_details,
                            provider, status, status_reason, created_at, paid_at
                     FROM `{$table}`
                     WHERE {$where_sql}
                     ORDER BY created_at DESC, id DESC
                     LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- static + controlled fragments.

        $list_params = array_merge($params, array( $per_page, $offset ));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- dynamic WHERE, all params prepared.
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, ...$list_params), ARRAY_A);

        $items = array();
        foreach ((array) $rows as $row) {
            // Никогда не возвращаем raw encrypted_details — только masked.
            $items[] = array(
                'id'            => (int) $row['id'],
                'reference_id'  => (string) $row['reference_id'],
                'amount'        => (float) $row['total_amount'],
                'method'        => (string) $row['payout_method'],
                'account'       => '' !== (string) $row['masked_details'] ? (string) $row['masked_details'] : (string) $row['payout_account'],
                'bank_code'     => (string) ( $row['provider'] ?? '' ),
                'status'        => (string) $row['status'],
                'status_reason' => (string) ( $row['status_reason'] ?? '' ),
                'created_at'    => (string) $row['created_at'],
                'paid_at'       => null === $row['paid_at'] ? null : (string) $row['paid_at'],
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
     * Сохранённые реквизиты пользователя (masked).
     *
     * @return array
     * @throws InvalidArgumentException Если user_id не положительный.
     */
    public static function get_settings( int $user_id ): array {
        if ($user_id <= 0) {
            throw new InvalidArgumentException('get_settings: user_id must be > 0');
        }
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_user_profile';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-scoped read.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT payout_method_id, bank_id, payout_full_name, masked_details, payout_details_updated_at, min_payout_amount
                 FROM %i WHERE user_id = %d',
                $table,
                $user_id
            ),
            ARRAY_A
        );

        $method_id = (int) ( $row['payout_method_id'] ?? 0 );
        $bank_id   = (int) ( $row['bank_id'] ?? 0 );

        return array(
            'payout_method_id' => $method_id > 0 ? $method_id : null,
            'bank_id'          => $bank_id > 0 ? $bank_id : null,
            'full_name'        => (string) ( $row['payout_full_name'] ?? '' ),
            'masked_details'   => (string) ( $row['masked_details'] ?? '' ),
            'updated_at'       => null === ( $row['payout_details_updated_at'] ?? null ) ? null : (string) $row['payout_details_updated_at'],
            'min_payout'       => (float) ( $row['min_payout_amount'] ?? 100.0 ),
        );
    }
}
