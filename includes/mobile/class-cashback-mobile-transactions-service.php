<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * История транзакций пользователя.
 *
 * Источник: `cashback_transactions` (per-user).
 * Фильтры: status, partner/network, date_from, date_to, store (по offer_name / order_number).
 *
 * @since 1.1.0
 */
class Cashback_Mobile_Transactions_Service {

    public const MAX_PER_PAGE = 50;

    public const STATUS_LABELS = array(
        'waiting'   => 'waiting',
        'hold'      => 'hold',
        'completed' => 'completed',
        'balance'   => 'balance',
        'declined'  => 'declined',
    );

    /**
     * @param array $args {
     *   page, per_page, status, partner, store, date_from (YYYY-MM-DD), date_to (YYYY-MM-DD).
     * }
     * @return array {items, total, page, per_page, pages}
     * @throws InvalidArgumentException Если user_id не положительный (защита от IDOR).
     */
    public static function paginate( int $user_id, array $args ): array {
        if ($user_id <= 0) {
            throw new InvalidArgumentException('paginate: user_id must be > 0');
        }
        $page     = max(1, (int) ( $args['page'] ?? 1 ));
        $per_page = max(1, min(self::MAX_PER_PAGE, (int) ( $args['per_page'] ?? 10 )));
        $offset   = ( $page - 1 ) * $per_page;

        $where   = array( 't.user_id = %d' );
        $params  = array( $user_id );
        $status  = (string) ( $args['status'] ?? '' );
        $partner = (string) ( $args['partner'] ?? '' );
        $store   = (string) ( $args['store'] ?? '' );
        $from    = (string) ( $args['date_from'] ?? '' );
        $to      = (string) ( $args['date_to'] ?? '' );

        if ('' !== $status && isset(self::STATUS_LABELS[ $status ])) {
            $where[]  = 't.order_status = %s';
            $params[] = $status;
        }
        if ('' !== $partner) {
            $where[]  = 't.partner = %s';
            $params[] = $partner;
        }
        if ('' !== $store) {
            $where[]  = '(t.offer_name LIKE %s OR t.order_number LIKE %s)';
            $like     = '%' . $store . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ('' !== $from && self::is_valid_date($from)) {
            $where[]  = 't.action_date >= %s';
            $params[] = $from . ' 00:00:00';
        }
        if ('' !== $to && self::is_valid_date($to)) {
            $where[]  = 't.action_date <= %s';
            $params[] = $to . ' 23:59:59';
        }

        global $wpdb;
        $table     = $wpdb->prefix . 'cashback_transactions';
        $where_sql = implode(' AND ', $where);

        // Count.
        $count_sql = "SELECT COUNT(*) FROM `{$table}` t WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $table/$where_sql composed from controlled constants; values bound via $params.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- pagination count.
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));

        // Page slice.
        $list_sql = "SELECT t.id, t.offer_name, t.order_number, t.cashback, t.currency,
                            t.partner, t.order_status, t.action_date, t.created_at
                     FROM `{$table}` t
                     WHERE {$where_sql}
                     ORDER BY t.action_date DESC, t.id DESC
                     LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- static fragments + $where_sql; all params prepared.

        $list_params = array_merge($params, array( $per_page, $offset ));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- dynamic WHERE fragments counted into replacement array.
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, ...$list_params), ARRAY_A);

        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = array(
                'id'           => (int) $row['id'],
                'store'        => (string) $row['offer_name'],
                'order_number' => (string) $row['order_number'],
                'cashback'     => (float) $row['cashback'],
                'currency'     => (string) $row['currency'],
                'partner'      => (string) $row['partner'],
                'status'       => (string) $row['order_status'],
                'action_date'  => (string) $row['action_date'],
                'created_at'   => (string) $row['created_at'],
            );
        }

        $pages = max(1, (int) ceil($total / $per_page));

        return array(
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => $pages,
        );
    }

    private static function is_valid_date( string $date ): bool {
        $ts = strtotime($date);
        return false !== $ts && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
    }
}
