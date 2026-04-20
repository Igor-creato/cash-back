<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Мобильный фасад над модулем Claims.
 *
 * Тонкий обёртка: вся бизнес-логика остаётся в existing классах:
 *   - Cashback_Claims_Eligibility (check, get_user_clicks)
 *   - Cashback_Claims_Scoring    (calculate)
 *   - Cashback_Claims_Manager    (create, get_user_claims, get_claim)
 *
 * Не дублируем антифрод и скоринг — вызываем service-level API.
 *
 * @since 1.1.0
 */
class Cashback_Mobile_Claims_Service {

    public const MAX_PER_PAGE = 50;

    /**
     * Клики пользователя (пагинация) с флагом can_claim.
     *
     * @param array $filters из query-string: store, date_from, date_to.
     * @return array {items, total, page, per_page, pages}
     */
    public static function paginate_clicks( int $user_id, int $page, int $per_page, array $filters = array() ): array {
        if (!class_exists('Cashback_Claims_Eligibility')) {
            return self::empty_page($page, $per_page);
        }

        $page     = max(1, $page);
        $per_page = max(1, min(self::MAX_PER_PAGE, $per_page));

        try {
            $result = Cashback_Claims_Eligibility::get_user_clicks($user_id, $page, $per_page, $filters);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback][Mobile][Claims] get_user_clicks failed: ' . $e->getMessage());
            return self::empty_page($page, $per_page);
        }

        $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : array();
        $total = (int) ( $result['total'] ?? count($items) );

        return array(
            'items'    => array_values($items),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => max(1, (int) ceil($total / $per_page)),
        );
    }

    /**
     * Проверка права на claim по конкретному клику.
     *
     * @return array|WP_Error
     */
    public static function check_eligibility( int $user_id, string $click_id ) {
        if (!class_exists('Cashback_Claims_Eligibility')) {
            return new WP_Error('claims_unavailable', __('Claims module is not available.', 'cashback'), array( 'status' => 503 ));
        }
        if ('' === $click_id) {
            return new WP_Error('invalid_click_id', __('click_id is required.', 'cashback'), array( 'status' => 400 ));
        }

        try {
            return (array) Cashback_Claims_Eligibility::check($user_id, $click_id);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback][Mobile][Claims] eligibility check failed: ' . $e->getMessage());
            return new WP_Error('eligibility_failed', __('Failed to check eligibility.', 'cashback'), array( 'status' => 500 ));
        }
    }

    /**
     * Предрасчёт скоринга для заявки.
     *
     * @param array $payload {order_value, order_date, comment, merchant_id?}
     * @return array|WP_Error
     */
    public static function calculate_score( int $user_id, string $click_id, array $payload ) {
        if (!class_exists('Cashback_Claims_Scoring')) {
            return new WP_Error('claims_unavailable', __('Claims module is not available.', 'cashback'), array( 'status' => 503 ));
        }

        // Подтягиваем product_id/merchant_id из eligibility (click-data).
        $eligibility = class_exists('Cashback_Claims_Eligibility')
            ? (array) Cashback_Claims_Eligibility::check($user_id, $click_id)
            : array();

        $click_data = isset($eligibility['data']) && is_array($eligibility['data']) ? $eligibility['data'] : array();

        $claim_data = array(
            'user_id'     => $user_id,
            'click_id'    => $click_id,
            'order_date'  => (string) ( $payload['order_date'] ?? '' ),
            'order_value' => (float) ( $payload['order_value'] ?? 0 ),
            'merchant_id' => (int) ( $click_data['merchant_id'] ?? 0 ),
            'comment'     => (string) ( $payload['comment'] ?? '' ),
        );

        try {
            return (array) Cashback_Claims_Scoring::calculate($claim_data);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback][Mobile][Claims] scoring failed: ' . $e->getMessage());
            return new WP_Error('scoring_failed', __('Failed to calculate score.', 'cashback'), array( 'status' => 500 ));
        }
    }

    /**
     * Создать заявку.
     *
     * @param array $data {click_id, order_id, order_value, order_date, comment?}
     * @return array|WP_Error
     */
    public static function create_claim( int $user_id, array $data ) {
        if (!class_exists('Cashback_Claims_Manager')) {
            return new WP_Error('claims_unavailable', __('Claims module is not available.', 'cashback'), array( 'status' => 503 ));
        }

        $payload = array(
            'user_id'     => $user_id,
            'click_id'    => (string) ( $data['click_id'] ?? '' ),
            'order_id'    => (string) ( $data['order_id'] ?? '' ),
            'order_value' => (float) ( $data['order_value'] ?? 0 ),
            'order_date'  => (string) ( $data['order_date'] ?? '' ),
            'comment'     => (string) ( $data['comment'] ?? '' ),
        );

        if ('' === $payload['click_id'] || '' === $payload['order_id'] || '' === $payload['order_date'] || $payload['order_value'] <= 0) {
            return new WP_Error('invalid_params', __('click_id, order_id, order_value and order_date are required.', 'cashback'), array( 'status' => 400 ));
        }

        try {
            $result = (array) Cashback_Claims_Manager::create($payload);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback][Mobile][Claims] create failed: ' . $e->getMessage());
            return new WP_Error('claim_create_failed', __('Failed to create claim.', 'cashback'), array( 'status' => 500 ));
        }

        if (empty($result['success'])) {
            $message = (string) ( $result['error'] ?? __('Could not create claim.', 'cashback') );
            $status  = isset($result['http_status']) ? (int) $result['http_status'] : 409;
            return new WP_Error('claim_rejected', $message, array( 'status' => $status ));
        }

        return array(
            'ok'       => true,
            'claim_id' => (int) ( $result['claim_id'] ?? 0 ),
        );
    }

    /**
     * Список claim'ов пользователя (пагинация + фильтр по статусу).
     */
    public static function paginate_claims( int $user_id, int $page, int $per_page, string $status_filter = '' ): array {
        if (!class_exists('Cashback_Claims_Manager')) {
            return self::empty_page($page, $per_page);
        }

        $page     = max(1, $page);
        $per_page = max(1, min(self::MAX_PER_PAGE, $per_page));

        try {
            $result = (array) Cashback_Claims_Manager::get_user_claims($user_id, $page, $per_page, $status_filter);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback][Mobile][Claims] list failed: ' . $e->getMessage());
            return self::empty_page($page, $per_page);
        }

        $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : array();
        $total = (int) ( $result['total'] ?? count($items) );

        return array(
            'items'    => array_values($items),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => max(1, (int) ceil($total / $per_page)),
        );
    }

    /**
     * Детали claim + события.
     *
     * @return array|WP_Error
     */
    public static function get_claim( int $user_id, int $claim_id ) {
        if (!class_exists('Cashback_Claims_Manager')) {
            return new WP_Error('claims_unavailable', __('Claims module is not available.', 'cashback'), array( 'status' => 503 ));
        }

        $claim = Cashback_Claims_Manager::get_claim($claim_id, $user_id);
        if (!is_array($claim)) {
            return new WP_Error('claim_not_found', __('Claim not found.', 'cashback'), array( 'status' => 404 ));
        }

        $events = self::fetch_events($claim_id);

        // Автоматически отмечаем события прочитанными при просмотре.
        if (class_exists('Cashback_Claims_DB')) {
            try {
                Cashback_Claims_DB::mark_user_events_read($user_id);
            } catch (\Throwable $e) {
                unset($e);
            }
        }

        return array(
            'claim'  => $claim,
            'events' => $events,
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function fetch_events( int $claim_id ): array {
        global $wpdb;
        $events_table = $wpdb->prefix . 'cashback_claim_events';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-scoped claim events.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT event_id, status, note, actor_type, is_read, created_at
                 FROM %i WHERE claim_id = %d ORDER BY created_at ASC, event_id ASC',
                $events_table,
                $claim_id
            ),
            ARRAY_A
        );

        $out = array();
        foreach ((array) $rows as $row) {
            $out[] = array(
                'event_id'   => (int) $row['event_id'],
                'status'     => (string) $row['status'],
                'note'       => (string) ( $row['note'] ?? '' ),
                'actor_type' => (string) $row['actor_type'],
                'is_read'    => (bool) $row['is_read'],
                'created_at' => (string) $row['created_at'],
            );
        }
        return $out;
    }

    private static function empty_page( int $page, int $per_page ): array {
        return array(
            'items'    => array(),
            'total'    => 0,
            'page'     => max(1, $page),
            'per_page' => max(1, $per_page),
            'pages'    => 1,
        );
    }
}
