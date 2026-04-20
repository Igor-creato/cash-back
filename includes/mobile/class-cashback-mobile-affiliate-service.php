<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Партнёрская программа для мобильного клиента.
 *
 * Тонкая обёртка над существующим `Cashback_Affiliate_Service`:
 *   - get_overview()  — статистика + реф-ссылка (на экран «Пригласи друга»).
 *   - paginate_accruals() — история начислений за рефералов.
 *   - paginate_referrals() — список рефералов.
 *
 * @since 1.1.0
 */
class Cashback_Mobile_Affiliate_Service {

    public const MAX_PER_PAGE = 50;

    /**
     * Сводная статистика + реф-ссылка.
     *
     * @return array
     * @throws InvalidArgumentException Если user_id не положительный.
     */
    public static function get_overview( int $user_id ): array {
        if ($user_id <= 0) {
            throw new InvalidArgumentException('get_overview: user_id must be > 0');
        }
        $stats = array();
        if (class_exists('Cashback_Affiliate_Service')) {
            try {
                $stats = (array) Cashback_Affiliate_Service::get_referrer_stats($user_id);
            } catch (\Throwable $e) {
                $stats = array();
            }
        }

        // Ensure affiliate profile exists + referral_link.
        if (class_exists('Cashback_Affiliate_DB') && method_exists('Cashback_Affiliate_DB', 'ensure_profile')) {
            try {
                Cashback_Affiliate_DB::ensure_profile($user_id);
            } catch (\Throwable $e) {
                unset($e); // non-fatal: профиль создастся позже при следующем обращении.
            }
        }

        $link = '';
        if (class_exists('Cashback_Affiliate_Service') && method_exists('Cashback_Affiliate_Service', 'get_referral_link')) {
            try {
                $link = (string) Cashback_Affiliate_Service::get_referral_link($user_id);
            } catch (\Throwable $e) {
                $link = '';
            }
        }
        if ('' === $link) {
            $link = add_query_arg('ref', (string) $user_id, home_url('/'));
        }

        return array(
            'referral_link'   => $link,
            'total_referrals' => (int) ( $stats['total_referrals'] ?? 0 ),
            'total_earned'    => (float) ( $stats['total_earned'] ?? 0 ),
            'total_available' => (float) ( $stats['total_available'] ?? 0 ),
            'total_pending'   => (float) ( $stats['total_pending'] ?? 0 ),
            'total_frozen'    => (float) ( $stats['total_frozen'] ?? 0 ),
            'total_declined'  => (float) ( $stats['total_declined'] ?? 0 ),
        );
    }

    /**
     * Постраничный список начислений.
     *
     * @return array {items, total, page, per_page, pages}
     * @throws InvalidArgumentException Если user_id не положительный.
     */
    public static function paginate_accruals( int $user_id, int $page, int $per_page ): array {
        if ($user_id <= 0) {
            throw new InvalidArgumentException('paginate_accruals: user_id must be > 0');
        }
        $page     = max(1, $page);
        $per_page = max(1, min(self::MAX_PER_PAGE, $per_page));
        $offset   = ( $page - 1 ) * $per_page;

        global $wpdb;
        $table = $wpdb->prefix . 'cashback_affiliate_accruals';
        $users = $wpdb->users;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-scoped count.
        $total = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE referrer_id = %d', $table, $user_id)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-scoped read.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT a.id, a.commission_amount, a.commission_rate, a.cashback_amount,
                        a.status, a.created_at, a.reference_id, u.display_name AS referred_user
                 FROM %i a
                 LEFT JOIN %i u ON u.ID = a.referred_user_id
                 WHERE a.referrer_id = %d
                 ORDER BY a.created_at DESC
                 LIMIT %d OFFSET %d',
                $table,
                $users,
                $user_id,
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = array(
                'id'                => (int) $row['id'],
                'commission_amount' => (float) $row['commission_amount'],
                'commission_rate'   => (float) $row['commission_rate'],
                'cashback_amount'   => (float) $row['cashback_amount'],
                'status'            => (string) $row['status'],
                'created_at'        => (string) $row['created_at'],
                'reference_id'      => (string) $row['reference_id'],
                'referred_user'     => (string) ( $row['referred_user'] ?? '' ),
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
     * Постраничный список рефералов.
     *
     * @return array {items, total, page, per_page, pages}
     * @throws InvalidArgumentException Если user_id не положительный.
     */
    public static function paginate_referrals( int $user_id, int $page, int $per_page ): array {
        if ($user_id <= 0) {
            throw new InvalidArgumentException('paginate_referrals: user_id must be > 0');
        }
        $page     = max(1, $page);
        $per_page = max(1, min(self::MAX_PER_PAGE, $per_page));
        $offset   = ( $page - 1 ) * $per_page;

        global $wpdb;
        $profiles = $wpdb->prefix . 'cashback_affiliate_profiles';
        $accruals = $wpdb->prefix . 'cashback_affiliate_accruals';
        $users    = $wpdb->users;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-scoped count.
        $total = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE referred_by_user_id = %d', $profiles, $user_id)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-scoped read.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT p.user_id, p.referred_at, u.display_name, u.user_registered,
                        COALESCE(SUM(CASE WHEN a.status IN (\'available\',\'frozen\',\'paid\') THEN a.commission_amount ELSE 0 END),0) AS total_earned
                 FROM %i p
                 INNER JOIN %i u ON u.ID = p.user_id
                 LEFT JOIN %i a ON a.referred_user_id = p.user_id AND a.referrer_id = %d
                 WHERE p.referred_by_user_id = %d
                 GROUP BY p.user_id
                 ORDER BY p.referred_at DESC
                 LIMIT %d OFFSET %d',
                $profiles,
                $users,
                $accruals,
                $user_id,
                $user_id,
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = array(
                'user_id'       => (int) $row['user_id'],
                'display_name'  => (string) $row['display_name'],
                'registered_at' => (string) $row['user_registered'],
                'referred_at'   => (string) $row['referred_at'],
                'total_earned'  => (float) $row['total_earned'],
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
}
