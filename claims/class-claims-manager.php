<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Claims Manager
 *
 * Core business logic for missing cashback claims:
 * - Create claims
 * - Status transitions with event logging
 * - Query claims
 */
class Cashback_Claims_Manager {

    private const VALID_TRANSITIONS = array(
        'draft'           => array( 'submitted' ),
        'submitted'       => array( 'sent_to_network', 'approved', 'declined' ),
        'sent_to_network' => array( 'approved', 'declined' ),
        'approved'        => array(),
        'declined'        => array(),
    );

    /**
     * Create a new claim.
     *
     * @param array $data Claim data
     * @return array{success: bool, claim_id?: int, error?: string}
     */
    public static function create( array $data ): array {
        global $wpdb;

        $user_id = get_current_user_id();
        if (!$user_id) {
            return array(
				'success' => false,
				'error'   => __('Необходима авторизация.', 'cashback-plugin'),
			);
        }

        $eligibility = Cashback_Claims_Eligibility::check($user_id, $data['click_id'] ?? '');
        if (!$eligibility['eligible']) {
            return array(
				'success' => false,
				'error'   => implode(' ', $eligibility['reasons']),
			);
        }

        $antifraud = Cashback_Claims_Antifraud::pre_submit_check($user_id, $data['order_id'] ?? '');
        if ($antifraud['blocked']) {
            return array(
				'success' => false,
				'error'   => implode(' ', $antifraud['reasons']),
			);
        }

        $scoring = Cashback_Claims_Scoring::calculate(array(
            'user_id'     => $user_id,
            'click_id'    => $data['click_id'],
            'order_date'  => $data['order_date'],
            'order_value' => $data['order_value'],
            'merchant_id' => $eligibility['data']['merchant_id'] ?? 0,
            'comment'     => $data['comment'] ?? '',
        ));

        $ip = class_exists('Cashback_Encryption') ? Cashback_Encryption::get_client_ip() : ( isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0' );
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        $insert_data = array(
            'user_id'           => $user_id,
            'click_id'          => $data['click_id'],
            'product_id'        => $eligibility['data']['product_id'] ?? 0,
            'product_name'      => $eligibility['data']['product_name'] ?? null,
            'order_id'          => sanitize_text_field($data['order_id']),
            'order_value'       => (float) $data['order_value'],
            'order_date'        => sanitize_text_field($data['order_date']),
            'comment'           => sanitize_textarea_field($data['comment'] ?? ''),
            'status'            => 'submitted',
            'probability_score' => $scoring['score'],
            'is_suspicious'     => $antifraud['suspicious'] ? 1 : 0,
            'ip_address'        => $ip,
            'user_agent'        => $ua,
        );

        $insert_format = array( '%d', '%s', '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%f', '%d', '%s', '%s' );

        $merchant_id = $eligibility['data']['merchant_id'] ?? 0;
        if ($merchant_id > 0) {
            $insert_data['merchant_id'] = $merchant_id;
            $insert_format[]            = '%d';
        }

        $merchant_name = $eligibility['data']['merchant_name'] ?? '';
        if ($merchant_name !== '') {
            $insert_data['merchant_name'] = $merchant_name;
            $insert_format[]              = '%s';
        }

        if (!empty($data['evidence_urls'])) {
            $insert_data['evidence_urls'] = wp_json_encode($data['evidence_urls']);
            $insert_format[]              = '%s';
        }

        if (!empty($antifraud['suspicious_reasons'])) {
            $insert_data['suspicious_reasons'] = wp_json_encode($antifraud['suspicious_reasons']);
            $insert_format[]                   = '%s';
        }

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}cashback_claims",
            $insert_data,
            $insert_format
        );

        if (!$inserted) {
            if (strpos($wpdb->last_error, 'Duplicate entry') !== false && strpos($wpdb->last_error, 'uk_click_user') !== false) {
                return array(
					'success' => false,
					'error'   => __('Заявка по этому переходу уже подана. Повторная заявка невозможна, дождитесь решения сервиса.', 'cashback-plugin'),
				);
            }
            return array(
				'success' => false,
				'error'   => __('Не удалось создать заявку. Попробуйте позже.', 'cashback-plugin'),
			);
        }

        $claim_id = (int) $wpdb->insert_id;

        self::log_event($claim_id, 'submitted', __('Заявка создана пользователем.', 'cashback-plugin'), $user_id, 'user');

        $post_analysis = Cashback_Claims_Antifraud::post_submit_analysis($claim_id, $user_id, $data['order_id'], $ip, $ua);
        if ($post_analysis['is_suspicious']) {
            self::log_event($claim_id, 'submitted', __('Антифрод: подозрительная заявка. Причины: ', 'cashback-plugin') . implode('; ', $post_analysis['reasons']), null, 'system');
        }

        $event_data = array_merge($data, array(
            'product_name'  => $eligibility['data']['product_name'] ?? '—',
            'merchant_name' => $eligibility['data']['merchant_name'] ?? '—',
        ));

        do_action('cashback_claim_created', $claim_id, $user_id, $event_data);

        return array(
			'success'  => true,
			'claim_id' => $claim_id,
		);
    }

    /**
     * Transition claim status.
     *
     * @param int $claim_id
     * @param string $new_status
     * @param string $note
     * @param string $actor_type 'admin' or 'system'
     * @param int|null $actor_id
     * @return array{success: bool, error?: string}
     */
    public static function transition_status( int $claim_id, string $new_status, string $note = '', string $actor_type = 'admin', ?int $actor_id = null ): array {
        global $wpdb;

        $allowed_statuses = array( 'draft', 'submitted', 'sent_to_network', 'approved', 'declined' );
        if (!in_array($new_status, $allowed_statuses, true)) {
            return array(
				'success' => false,
				'error'   => __('Недопустимый статус.', 'cashback-plugin'),
			);
        }

        $claims_table = $wpdb->prefix . 'cashback_claims';
        $claim        = $wpdb->get_row($wpdb->prepare(
            'SELECT claim_id, user_id, status FROM %i WHERE claim_id = %d',
            $claims_table,
            $claim_id
        ), ARRAY_A);

        if (!$claim) {
            return array(
				'success' => false,
				'error'   => __('Заявка не найдена.', 'cashback-plugin'),
			);
        }

        $current_status = $claim['status'];
        $allowed        = self::VALID_TRANSITIONS[ $current_status ] ?? array();

        if (!in_array($new_status, $allowed, true)) {
            return array(
				'success' => false,
				'error'   => sprintf(
								/* translators: 1: current status, 2: new status */
								__('Недопустимый переход: из "%1$s" в "%2$s".', 'cashback-plugin'),
								$current_status,
								$new_status
							),
			);
        }

        $updated = $wpdb->update(
            "{$wpdb->prefix}cashback_claims",
            array( 'status' => $new_status ),
            array( 'claim_id' => $claim_id ),
            array( '%s' ),
            array( '%d' )
        );

        if ($updated === false) {
            return array(
				'success' => false,
				'error'   => $wpdb->last_error,
			);
        }

        self::log_event($claim_id, $new_status, $note, $actor_id, $actor_type);

        do_action('cashback_claim_status_changed', $claim_id, $current_status, $new_status, $note, $actor_type, $actor_id);

        return array( 'success' => true );
    }

    /**
     * Log an event for a claim.
     *
     * @param int $claim_id
     * @param string $status
     * @param string $note
     * @param int|null $actor_id
     * @param string $actor_type
     */
    public static function log_event( int $claim_id, string $status, string $note = '', ?int $actor_id = null, string $actor_type = 'system' ): void {
        global $wpdb;

        $wpdb->insert(
            "{$wpdb->prefix}cashback_claim_events",
            array(
                'claim_id'      => $claim_id,
                'status'        => $status,
                'note'          => $note,
                'actor_id'      => $actor_id,
                'actor_type'    => $actor_type,
                'is_read_admin' => $actor_type === 'user' ? 0 : 1,
            ),
            array( '%d', '%s', '%s', '%d', '%s', '%d' )
        );
    }

    /**
     * Get a single claim with events.
     *
     * @param int $claim_id
     * @param int $user_id If provided, verify ownership
     * @return array|null
     */
    public static function get_claim( int $claim_id, ?int $user_id = null ): ?array {
        global $wpdb;

        $claims_table = $wpdb->prefix . 'cashback_claims';
        $events_table = $wpdb->prefix . 'cashback_claim_events';

        $sql = 'SELECT c.*, u.display_name as user_display_name, u.user_email as user_email
                FROM %i c
                LEFT JOIN %i u ON u.ID = c.user_id
                WHERE c.claim_id = %d';

        $params = array( $claims_table, $wpdb->users, $claim_id );

        if ($user_id) {
            $sql     .= ' AND c.user_id = %d';
            $params[] = $user_id;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built above from static fragments with %d/%i placeholders, values bound via $wpdb->prepare().
        $claim = $wpdb->get_row($wpdb->prepare($sql, ...$params), ARRAY_A);

        if (!$claim) {
            return null;
        }

        $events = $wpdb->get_results($wpdb->prepare(
            'SELECT e.*, u.display_name as actor_name
             FROM %i e
             LEFT JOIN %i u ON u.ID = e.actor_id
             WHERE e.claim_id = %d
             ORDER BY e.created_at ASC',
            $events_table,
            $wpdb->users,
            $claim_id
        ), ARRAY_A);

        $claim['events'] = $events;

        if ($claim['suspicious_reasons']) {
            $claim['suspicious_reasons_decoded'] = json_decode($claim['suspicious_reasons'], true) ?: array();
        }

        return $claim;
    }

    /**
     * Get user's claims with pagination.
     *
     * @param int $user_id
     * @param int $page
     * @param int $per_page
     * @param string $status_filter
     * @return array{claims: array[], total: int, pages: int}
     */
    public static function get_user_claims( int $user_id, int $page = 1, int $per_page = 20, string $status_filter = '', array $filters = array() ): array {
        global $wpdb;

        $offset = ( $page - 1 ) * $per_page;
        $where  = 'c.user_id = %d';
        $params = array( $user_id );

        if ($status_filter && $status_filter !== 'all') {
            $where   .= ' AND c.status = %s';
            $params[] = $status_filter;
        }

        $date_from = $filters['date_from'] ?? '';
        $date_to   = $filters['date_to'] ?? '';
        if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $where   .= ' AND c.created_at >= %s';
            $params[] = $date_from . ' 00:00:00';
        }
        if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $where   .= ' AND c.created_at <= %s';
            $params[] = $date_to . ' 23:59:59';
        }

        $search = $filters['search'] ?? '';
        if ($search !== '') {
            $where   .= ' AND c.product_name LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $claims_table = $wpdb->prefix . 'cashback_claims';
        $events_table = $wpdb->prefix . 'cashback_claim_events';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where from allowlist fragments with %s/%d placeholders; sniff can't count spread args.
        $claims = $wpdb->get_results( $wpdb->prepare( "SELECT c.claim_id, c.product_name, c.order_id, c.order_value, c.order_date, c.status, c.probability_score, c.is_suspicious, c.created_at, (SELECT COUNT(*) FROM %i e WHERE e.claim_id = c.claim_id AND e.is_read = 0 AND e.actor_type IN ('admin', 'system') AND e.note IS NOT NULL AND e.note != '' AND NOT (e.actor_type = 'system' AND e.note LIKE %s)) AS unread_count FROM %i c WHERE {$where} ORDER BY (unread_count > 0) DESC, c.created_at DESC LIMIT %d OFFSET %d", ...array_merge( array( $events_table, '%Антифрод:%', $claims_table ), $params, array( $per_page, $offset ) ) ), ARRAY_A );

        // Fetch events for each claim (admin/system notes visible to user)
        $claim_ids       = array_column($claims, 'claim_id');
        $events_by_claim = array();
        if (!empty($claim_ids)) {
            $placeholders = implode(',', array_fill(0, count($claim_ids), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is array_fill of %d; sniff can't see %d inside $placeholders or count spread args.
            $events = $wpdb->get_results( $wpdb->prepare( "SELECT event_id, claim_id, status, note, actor_type, is_read, created_at FROM %i WHERE claim_id IN ({$placeholders}) AND note IS NOT NULL AND note != '' AND NOT (actor_type = 'system' AND note LIKE %s) ORDER BY created_at ASC", ...array_merge( array( $events_table ), $claim_ids, array( '%Антифрод:%' ) ) ), ARRAY_A );

            foreach ($events as $event) {
                $events_by_claim[ $event['claim_id'] ][] = $event;
            }
        }

        foreach ($claims as &$claim) {
            $claim['events'] = $events_by_claim[ $claim['claim_id'] ] ?? array();
        }
        unset($claim);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where from allowlist fragments with %s/%d placeholders.
            "SELECT COUNT(*) FROM %i c WHERE {$where}",
            ...array_merge( array( $claims_table ), $params )
        ));

        $pages = (int) ceil($total / $per_page);

        return array(
			'claims' => $claims,
			'total'  => $total,
			'pages'  => max(1, $pages),
		);
    }

    /**
     * Get all claims for admin with filters and pagination.
     *
     * @param array $args Filters: status, suspicious, merchant_id, search, date_from, date_to, orderby, order
     * @return array{claims: array[], total: int, pages: int}
     */
    public static function get_claims_admin( array $args = array() ): array {
        global $wpdb;

        $per_page = (int) ( $args['per_page'] ?? 20 );
        $page     = max(1, (int) ( $args['page'] ?? 1 ));
        $offset   = ( $page - 1 ) * $per_page;

        $where  = array();
        $params = array();

        if (!empty($args['status'])) {
            $where[]  = 'c.status = %s';
            $params[] = $args['status'];
        }

        if (isset($args['suspicious']) && $args['suspicious'] !== '') {
            $where[]  = 'c.is_suspicious = %d';
            $params[] = (int) $args['suspicious'];
        }

        if (!empty($args['merchant_id'])) {
            $where[]  = 'c.merchant_id = %d';
            $params[] = (int) $args['merchant_id'];
        }

        if (!empty($args['search'])) {
            $where[]  = '(c.order_id LIKE %s OR c.product_name LIKE %s OR c.merchant_name LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)';
            $like     = '%' . $wpdb->esc_like($args['search']) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if (!empty($args['date_from'])) {
            $where[]  = 'c.created_at >= %s';
            $params[] = $args['date_from'] . ' 00:00:00';
        }

        if (!empty($args['date_to'])) {
            $where[]  = 'c.created_at <= %s';
            $params[] = $args['date_to'] . ' 23:59:59';
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $allowed_orderby = array( 'claim_id', 'created_at', 'probability_score', 'order_value', 'status' );
        $orderby         = in_array($args['orderby'] ?? '', $allowed_orderby, true) ? $args['orderby'] : 'created_at';
        $order           = strtoupper($args['order'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

        $claims_table = $wpdb->prefix . 'cashback_claims';
        $events_table = $wpdb->prefix . 'cashback_claim_events';

        $list_params = array_merge( array( $events_table, $claims_table, $wpdb->users ), $params, array( $per_page, $offset ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql from allowlist fragments with %s/%d; $orderby/$order from hard allowlist (in_array + ASC/DESC); sniff can't count spread args.
        $claims = $wpdb->get_results( $wpdb->prepare( "SELECT c.*, u.display_name as user_display_name, u.user_email as user_email, (SELECT COUNT(*) FROM %i e WHERE e.claim_id = c.claim_id AND e.actor_type = 'user' AND e.is_read_admin = 0) AS unread_count FROM %i c LEFT JOIN %i u ON u.ID = c.user_id {$where_sql} ORDER BY (unread_count > 0) DESC, c.{$orderby} {$order} LIMIT %d OFFSET %d", ...$list_params ), ARRAY_A );

        if (!empty($params)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql from allowlist fragments; sniff can't count spread args.
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i c LEFT JOIN %i u ON u.ID = c.user_id {$where_sql}", ...array_merge( array( $claims_table, $wpdb->users ), $params ) ) );
        } else {
            $total = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM %i',
                $claims_table
            ) );
        }

        $pages = (int) ceil($total / $per_page);

        return array(
			'claims' => $claims,
			'total'  => $total,
			'pages'  => max(1, $pages),
		);
    }

    /**
     * Get admin dashboard stats.
     *
     * @return array
     */
    public static function get_admin_stats(): array {
        global $wpdb;

        $claims_table = $wpdb->prefix . 'cashback_claims';
        $row          = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
                SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
                SUM(CASE WHEN status = 'sent_to_network' THEN 1 ELSE 0 END) as sent_to_network,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) as declined,
                SUM(CASE WHEN is_suspicious = 1 THEN 1 ELSE 0 END) as suspicious,
                AVG(probability_score) as avg_probability
             FROM %i",
            $claims_table
        ), ARRAY_A );

        return $row ?: array(
            'total'           => 0,
			'draft'           => 0,
			'submitted'       => 0,
			'sent_to_network' => 0,
            'approved'        => 0,
			'declined'        => 0,
			'suspicious'      => 0,
			'avg_probability' => 0,
        );
    }

    /**
     * Add admin note to claim (without status change).
     *
     * @param int $claim_id
     * @param string $note
     * @param int $actor_id
     * @return array{success: bool, error?: string}
     */
    public static function add_note( int $claim_id, string $note, int $actor_id ): array {
        global $wpdb;

        $claims_table = $wpdb->prefix . 'cashback_claims';
        $claim        = $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM %i WHERE claim_id = %d',
            $claims_table,
            $claim_id
        ));

        if (!$claim) {
            return array(
				'success' => false,
				'error'   => __('Заявка не найдена.', 'cashback-plugin'),
			);
        }

        self::log_event($claim_id, $claim, $note, $actor_id, 'admin');

        return array( 'success' => true );
    }
}
