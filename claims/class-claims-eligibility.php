<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Eligibility Engine
 *
 * Validates whether a user can submit a missing cashback claim for a specific click.
 * Checks:
 * - Click exists and belongs to user
 * - 48 hours minimum since click
 * - 30 days maximum since click
 * - No cashback already credited for this click
 * - No existing claim for this click
 * - Merchant allows claims
 */
class Cashback_Claims_Eligibility {

    private const MIN_HOURS_AFTER_CLICK = 48;
    private const MAX_DAYS_AFTER_CLICK  = 30;

    /**
     * Check if user is eligible to claim for a specific click.
     *
     * @param int $user_id WordPress user ID
     * @param string $click_id Click UUID (32 hex chars)
     * @return array{eligible: bool, reasons: string[], data: ?array}
     */
    public static function check( int $user_id, string $click_id ): array {
        global $wpdb;

        if ($user_id <= 0) {
            return array(
				'eligible' => false,
				'reasons'  => array( __('Необходима авторизация.', 'cashback-plugin') ),
				'data'     => null,
			);
        }

        $click = self::get_click($user_id, $click_id);
        if (!$click) {
            return array(
				'eligible' => false,
				'reasons'  => array( __('Переход не найден или не принадлежит вам.', 'cashback-plugin') ),
				'data'     => null,
			);
        }

        $reasons = array();

        $time_check = self::check_time_window($click['created_at']);
        if ($time_check !== true) {
            $reasons[] = $time_check;
        }

        $cashback_check = self::check_no_cashback($click_id);
        if ($cashback_check !== true) {
            $reasons[] = $cashback_check;
        }

        $claim_check = self::check_no_existing_claim($user_id, $click_id);
        if ($claim_check !== true) {
            $reasons[] = $claim_check;
        }

        $merchant_check = self::check_merchant_allows_claims((int) ( $click['offer_id'] ?? 0 ));
        if ($merchant_check !== true) {
            $reasons[] = $merchant_check;
        }

        if (!empty($reasons)) {
            return array(
				'eligible' => false,
				'reasons'  => $reasons,
				'data'     => null,
			);
        }

        $product      = wc_get_product((int) $click['product_id']);
        $product_name = $product ? $product->get_name() : __('Магазин удалён', 'cashback-plugin');

        if (!$product || $product->get_status() !== 'publish') {
            return array(
                'eligible' => false,
                'reasons'  => array( __('Заявка невозможна, магазин отключен от кэшбэка.', 'cashback-plugin') ),
                'data'     => null,
            );
        }

        return array(
            'eligible' => true,
            'reasons'  => array(),
            'data'     => array(
                'click_id'      => $click_id,
                'product_id'    => (int) $click['product_id'],
                'product_name'  => $product_name,
                'click_date'    => $click['created_at'],
                'merchant_id'   => (int) ( $click['offer_id'] ?? 0 ),
                'merchant_name' => $click['cpa_network'] ?? '',
            ),
        );
    }

    /**
     * Get user's clicks that are eligible for claims (no cashback, no existing claim, within time window).
     *
     * @param int $user_id
     * @param int $page
     * @param int $per_page
     * @return array{clicks: array[], total: int, pages: int}
     */
    public static function get_eligible_clicks( int $user_id, int $page = 1, int $per_page = 20 ): array {
        global $wpdb;

        $cutoff_min = gmdate('Y-m-d H:i:s', strtotime('-' . self::MAX_DAYS_AFTER_CLICK . ' days'));
        $cutoff_max = gmdate('Y-m-d H:i:s', strtotime('-' . self::MIN_HOURS_AFTER_CLICK . ' hours'));

        $offset = ( $page - 1 ) * $per_page;

        $eligible_where = "cl.user_id = %d
               AND cl.created_at >= %s
               AND cl.created_at <= %s
               AND cl.spam_click = 0
               AND NOT EXISTS (
                   SELECT 1 FROM `{$wpdb->prefix}cashback_transactions` t
                   WHERE t.click_id = cl.click_id AND t.user_id = cl.user_id
                   AND t.order_status IN ('waiting', 'completed', 'balance', 'hold')
               )
               AND NOT EXISTS (
                   SELECT 1 FROM `{$wpdb->prefix}cashback_claims` c
                   WHERE c.click_id = cl.click_id AND c.user_id = cl.user_id
                   AND c.status IN ('draft', 'submitted', 'sent_to_network', 'approved')
               )";

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from $wpdb->prefix; $eligible_where is a static fragment with only %s/%d placeholders, values bound via $wpdb->prepare().
        $clicks = $wpdb->get_results($wpdb->prepare(
            "SELECT cl.click_id, cl.product_id, cl.created_at, cl.cpa_network, cl.offer_id,
                    cl.ip_address, cl.user_agent
             FROM `{$wpdb->prefix}cashback_click_log` cl
             WHERE {$eligible_where}
             ORDER BY cl.created_at DESC
             LIMIT %d OFFSET %d",
            $user_id,
            $cutoff_min,
            $cutoff_max,
            $per_page,
            $offset
        ), ARRAY_A);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM `{$wpdb->prefix}cashback_click_log` cl
             WHERE {$eligible_where}",
            $user_id,
            $cutoff_min,
            $cutoff_max
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $pages = (int) ceil($total / $per_page);

        $enriched = array();
        foreach ($clicks as $click) {
            $product               = wc_get_product((int) $click['product_id']);
            $click['product_name'] = $product ? $product->get_name() : __('Магазин удалён', 'cashback-plugin');
            $enriched[]            = $click;
        }

        return array(
			'clicks' => $enriched,
			'total'  => $total,
			'pages'  => max(1, (int) $pages),
		);
    }

    /**
     * Get all user clicks with their cashback status.
     *
     * @param int    $user_id
     * @param int    $page
     * @param int    $per_page
     * @param array  $filters {date_from?: string, date_to?: string, search?: string, can_claim?: string}
     * @return array{clicks: array[], total: int, pages: int}
     */
    public static function get_user_clicks( int $user_id, int $page = 1, int $per_page = 20, array $filters = array() ): array {
        global $wpdb;

        $offset = ( $page - 1 ) * $per_page;

        $cutoff_min = gmdate('Y-m-d H:i:s', strtotime('-' . self::MAX_DAYS_AFTER_CLICK . ' days'));
        $cutoff_max = gmdate('Y-m-d H:i:s', strtotime('-' . self::MIN_HOURS_AFTER_CLICK . ' hours'));

        $where_extra  = '';
        $prepare_args = array( $user_id );

        // Date filters
        $date_from = $filters['date_from'] ?? '';
        $date_to   = $filters['date_to'] ?? '';
        if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $where_extra   .= ' AND cl.created_at >= %s';
            $prepare_args[] = $date_from . ' 00:00:00';
        }
        if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $where_extra   .= ' AND cl.created_at <= %s';
            $prepare_args[] = $date_to . ' 23:59:59';
        }

        // Search by product name — filter product_ids first to keep query efficient
        $search             = $filters['search'] ?? '';
        $search_product_ids = null;
        if ($search !== '') {
            $search_product_ids = self::find_product_ids_by_name($search);
            if (empty($search_product_ids)) {
                return array(
					'clicks' => array(),
					'total'  => 0,
					'pages'  => 1,
				);
            }
            $placeholders = implode(',', array_fill(0, count($search_product_ids), '%d'));
            $where_extra .= " AND cl.product_id IN ($placeholders)";
            $prepare_args = array_merge($prepare_args, $search_product_ids);
        }

        // can_claim filter — restrict to eligible time window and no cashback/claim
        $can_claim_filter = $filters['can_claim'] ?? '';
        if ($can_claim_filter === 'yes') {
            $where_extra   .= ' AND cl.spam_click = 0';
            $where_extra   .= ' AND cl.created_at >= %s AND cl.created_at <= %s';
            $prepare_args[] = $cutoff_min;
            $prepare_args[] = $cutoff_max;
            $where_extra   .= ' AND t.id IS NULL';
            $where_extra   .= ' AND c_active.claim_id IS NULL';
        }

        $select_query = "SELECT cl.click_id, cl.product_id, cl.created_at, cl.cpa_network, cl.offer_id,
                    cl.ip_address, cl.user_agent, cl.spam_click,
                    CASE WHEN t.id IS NOT NULL THEN 1 ELSE 0 END AS has_cashback,
                    t.order_status AS cashback_status,
                    CASE WHEN c_active.claim_id IS NOT NULL THEN 1 ELSE 0 END AS has_active_claim,
                    c_active.status AS claim_status
             FROM `{$wpdb->prefix}cashback_click_log` cl
             LEFT JOIN `{$wpdb->prefix}cashback_transactions` t
                 ON t.click_id = cl.click_id AND t.user_id = cl.user_id
                 AND t.order_status IN ('waiting', 'completed', 'balance', 'hold')
             LEFT JOIN `{$wpdb->prefix}cashback_claims` c_active
                 ON c_active.click_id = cl.click_id AND c_active.user_id = cl.user_id
                 AND c_active.status IN ('draft', 'submitted', 'sent_to_network', 'approved', 'declined')
             WHERE cl.user_id = %d{$where_extra}
             ORDER BY cl.created_at DESC
             LIMIT %d OFFSET %d";

        $data_args = array_merge($prepare_args, array( $per_page, $offset ));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $select_query assembled from static fragments with %s/%d placeholders (table names from $wpdb->prefix, IN-list via array_fill), values bound via $wpdb->prepare().
        $clicks = $wpdb->get_results($wpdb->prepare($select_query, ...$data_args), ARRAY_A);

        $count_query = "SELECT COUNT(*)
             FROM `{$wpdb->prefix}cashback_click_log` cl
             LEFT JOIN `{$wpdb->prefix}cashback_transactions` t
                 ON t.click_id = cl.click_id AND t.user_id = cl.user_id
                 AND t.order_status IN ('waiting', 'completed', 'balance', 'hold')
             LEFT JOIN `{$wpdb->prefix}cashback_claims` c_active
                 ON c_active.click_id = cl.click_id AND c_active.user_id = cl.user_id
                 AND c_active.status IN ('draft', 'submitted', 'sent_to_network', 'approved', 'declined')
             WHERE cl.user_id = %d{$where_extra}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $count_query assembled from static fragments with %s/%d placeholders (table names from $wpdb->prefix, IN-list via array_fill), values bound via $wpdb->prepare().
        $total = (int) $wpdb->get_var($wpdb->prepare($count_query, ...$prepare_args));

        $pages = (int) ceil($total / $per_page);

        $blocked_merchants = get_option('cashback_claims_blocked_merchants', array());
        if (!is_array($blocked_merchants)) {
            $blocked_merchants = array();
        }

        $enriched = array();
        foreach ($clicks as $click) {
            $product               = wc_get_product((int) $click['product_id']);
            $click['product_name'] = $product ? $product->get_name() : __('Магазин удалён', 'cashback-plugin');

            // Determine eligibility inline (avoid N+1 queries)
            $click_time  = strtotime($click['created_at']);
            $hours_since = ( time() - $click_time ) / 3600;
            $offer_id    = (int) ( $click['offer_id'] ?? 0 );

            $reasons = array();

            if (!$product || $product->get_status() !== 'publish') {
                $reasons[] = __('Заявка невозможна, магазин отключен от кэшбэка.', 'cashback-plugin');
            }

            if ((int) $click['spam_click'] === 1) {
                $reasons[] = __('Подозрительный клик.', 'cashback-plugin');
            }

            if ($hours_since < self::MIN_HOURS_AFTER_CLICK) {
                $remaining = (int) ceil(self::MIN_HOURS_AFTER_CLICK - $hours_since);
                $reasons[] = sprintf(
                    _n('Подождите ещё %d час.', 'Подождите ещё %d часа.', $remaining, 'cashback-plugin'),
                    $remaining
                );
            } elseif ($hours_since > self::MAX_DAYS_AFTER_CLICK * 24) {
                $reasons[] = __('Более 30 дней с момента перехода.', 'cashback-plugin');
            }

            if ((int) $click['has_cashback']) {
                $reasons[] = __('Кэшбэк уже начислен.', 'cashback-plugin');
            }

            if ((int) $click['has_active_claim']) {
                $claim_st = $click['claim_status'];
                if ($claim_st === 'approved') {
                    $reasons[] = __('Заявка уже одобрена.', 'cashback-plugin');
                } elseif ($claim_st === 'declined') {
                    $reasons[] = __('Заявка отклонена. Повторная подача невозможна.', 'cashback-plugin');
                } else {
                    $reasons[] = __('Заявка уже подана, дождитесь решения.', 'cashback-plugin');
                }
            }

            if ($offer_id > 0 && in_array($offer_id, $blocked_merchants, true)) {
                $reasons[] = __('Мерчант не поддерживает заявки.', 'cashback-plugin');
            }

            $click['can_claim']    = empty($reasons);
            $click['claim_reason'] = implode(' ', $reasons);
            $click['merchant_id']  = $offer_id;

            $enriched[] = $click;
        }

        return array(
			'clicks' => $enriched,
			'total'  => $total,
			'pages'  => max(1, $pages),
		);
    }

    /**
     * Find WooCommerce product IDs by name search.
     *
     * @param string $search
     * @return int[]
     */
    private static function find_product_ids_by_name( string $search ): array {
        global $wpdb;

        $like = '%' . $wpdb->esc_like($search) . '%';

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM `{$wpdb->posts}`
             WHERE post_type IN ('product', 'product_variation')
             AND post_title LIKE %s
             LIMIT 500",
            $like
        ));

        return array_map('intval', $ids);
    }

    /**
     * Fetch click record belonging to user.
     *
     * @param int $user_id
     * @param string $click_id
     * @return array|null
     */
    private static function get_click( int $user_id, string $click_id ): ?array {
        global $wpdb;

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT click_id, user_id, product_id, offer_id, cpa_network, created_at, ip_address, user_agent
             FROM `{$wpdb->prefix}cashback_click_log`
             WHERE click_id = %s AND user_id = %d",
            $click_id,
            $user_id
        ), ARRAY_A);

        return $result ?: null;
    }

    /**
     * Check time window: 48h < age < 30 days.
     *
     * @param string $click_datetime
     * @return true|string
     */
    private static function check_time_window( string $click_datetime ) {
        $click_time  = strtotime($click_datetime);
        $now         = time();
        $hours_since = ( $now - $click_time ) / 3600;

        if ($hours_since < self::MIN_HOURS_AFTER_CLICK) {
            $remaining = ceil(self::MIN_HOURS_AFTER_CLICK - $hours_since);
            return sprintf(
                /* translators: %d: hours remaining */
                _n('Подождите ещё %d час перед подачей заявки.', 'Подождите ещё %d часа перед подачей заявки.', $remaining, 'cashback-plugin'),
                $remaining
            );
        }

        if ($hours_since > self::MAX_DAYS_AFTER_CLICK * 24) {
            return __('Слишком много времени прошло с момента перехода (более 30 дней).', 'cashback-plugin');
        }

        return true;
    }

    /**
     * Check no cashback already credited for this click.
     *
     * @param string $click_id
     * @return true|string
     */
    private static function check_no_cashback( string $click_id ) {
        global $wpdb;

        $has_cashback = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->prefix}cashback_transactions`
             WHERE click_id = %s AND order_status IN ('waiting', 'completed', 'balance', 'hold')",
            $click_id
        ));

        if ((int) $has_cashback > 0) {
            return __('Кэшбэк уже начислен или в процессе по этому переходу.', 'cashback-plugin');
        }

        return true;
    }

    /**
     * Check no existing claim for this click by this user.
     *
     * @param int $user_id
     * @param string $click_id
     * @return true|string
     */
    private static function check_no_existing_claim( int $user_id, string $click_id ) {
        global $wpdb;

        $has_active_claim = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->prefix}cashback_claims`
             WHERE click_id = %s AND user_id = %d AND status IN ('draft', 'submitted', 'sent_to_network')",
            $click_id,
            $user_id
        ));

        if ((int) $has_active_claim > 0) {
            return __('Заявка по этому переходу уже существует.', 'cashback-plugin');
        }

        $has_approved_claim = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->prefix}cashback_claims`
             WHERE click_id = %s AND user_id = %d AND status = 'approved'",
            $click_id,
            $user_id
        ));

        if ((int) $has_approved_claim > 0) {
            return __('Заявка по этому переходу уже одобрена.', 'cashback-plugin');
        }

        $has_declined_claim = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->prefix}cashback_claims`
             WHERE click_id = %s AND user_id = %d AND status = 'declined'",
            $click_id,
            $user_id
        ));

        if ((int) $has_declined_claim > 0) {
            return __('Заявка по этому переходу уже подана и отклонена. Повторная подача невозможна.', 'cashback-plugin');
        }

        return true;
    }

    /**
     * Check if merchant (offer) allows claims.
     * By default all allow; can be extended with post_meta or option.
     *
     * @param int $offer_id
     * @return true|string
     */
    private static function check_merchant_allows_claims( int $offer_id ) {
        if ($offer_id <= 0) {
            return true;
        }

        $blocked = get_option('cashback_claims_blocked_merchants', array());
        if (is_array($blocked) && in_array($offer_id, $blocked, true)) {
            return __('Мерчант не поддерживает заявки на кэшбэк.', 'cashback-plugin');
        }

        return true;
    }
}
