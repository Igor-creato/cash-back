<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Claims Admin Module
 *
 * WordPress admin page for managing missing cashback claims.
 * Features:
 * - Claims list with filters (status, suspicious, search, date range)
 * - Sort by probability_score
 * - Claim detail card with event history
 * - Actions: approve, decline, send to network, add note
 */
class Cashback_Claims_Admin {

    private const PER_PAGE = 20;

    public function __construct() {
        add_action('admin_menu', array( $this, 'add_admin_menu' ));
        add_action('admin_enqueue_scripts', array( $this, 'enqueue_scripts' ));
        add_action('wp_ajax_claims_admin_transition', array( $this, 'ajax_transition' ));
        add_action('wp_ajax_claims_admin_add_note', array( $this, 'ajax_add_note' ));
        add_action('wp_ajax_claims_admin_get_detail', array( $this, 'ajax_get_detail' ));
        add_action('wp_ajax_claims_admin_stats', array( $this, 'ajax_stats' ));
        add_action('wp_ajax_cashback_claim_approve_with_tx', array( $this, 'ajax_approve_with_tx' ));

        // Invalidate pending count cache on claim creation or status change
        add_action('cashback_claim_created', array( __CLASS__, 'invalidate_pending_count_cache' ));
        add_action('cashback_claim_status_changed', array( __CLASS__, 'invalidate_pending_count_cache' ));
    }

    public function add_admin_menu(): void {
        add_submenu_page(
            'cashback-overview',
            __('Заявки кэшбэка', 'cashback-plugin'),
            $this->get_menu_title(),
            'manage_options',
            'cashback-claims-admin',
            array( $this, 'render_page' )
        );
    }

    /**
     * Menu title with pending claims badge.
     */
    private function get_menu_title(): string {
        $title = __('Заявки кэшбэка', 'cashback-plugin');

        $count = self::get_pending_claims_count();
        if ($count > 0) {
            $title .= sprintf(
                ' <span class="awaiting-mod count-%d"><span class="pending-count">%s</span></span>',
                $count,
                number_format_i18n($count)
            );
        }

        return $title;
    }

    /**
     * Get count of new (submitted) claims that haven't been reviewed.
     */
    public static function get_pending_claims_count(): int {
        $cached = get_transient('cashback_claims_pending_count');
        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$wpdb->prefix}cashback_claims` WHERE status = 'submitted'"
        );

        set_transient('cashback_claims_pending_count', $count, HOUR_IN_SECONDS);

        return $count;
    }

    /**
     * Invalidate pending count cache (call after status transitions).
     */
    public static function invalidate_pending_count_cache(): void {
        delete_transient('cashback_claims_pending_count');
    }

    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by admin_enqueue_scripts hook signature.
    public function enqueue_scripts( string $hook ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page detection, no state change.
        $is_target = ( isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-claims-admin' );

        if (!$is_target) {
            return;
        }

        $plugin_dir_url = plugin_dir_url(__DIR__);

        wp_enqueue_style(
            'cashback-admin-claims-css',
            $plugin_dir_url . 'assets/css/admin-claims.css',
            array(),
            '1.0.0'
        );

        Cashback_Assets::enqueue_safe_html();

        wp_enqueue_script(
            'cashback-pagination',
            $plugin_dir_url . 'assets/js/cashback-pagination.js',
            array(),
            '1.0.0',
            true
        );

        wp_enqueue_script(
            'cashback-admin-claims-js',
            $plugin_dir_url . 'assets/js/admin-claims.js',
            array( 'jquery', 'cashback-pagination', 'cashback-safe-html' ),
            '1.1.0',
            true
        );

        wp_localize_script('cashback-admin-claims-js', 'cashbackAdminClaimsData', array(
            'transitionNonce' => wp_create_nonce('claims_admin_transition'),
            'noteNonce'       => wp_create_nonce('claims_admin_note'),
            'detailNonce'     => wp_create_nonce('claims_admin_detail'),
            'statsNonce'      => wp_create_nonce('claims_admin_stats'),
            'ajaxUrl'         => admin_url('admin-ajax.php'),
        ));

        // Combined approve+tx модал (Session 4-bis, P0.1 F-S7-NO-MANUAL-CREDIT).
        // CSS переиспользуем из stuck-claim-tx flow — визуальная согласованность.
        wp_enqueue_style(
            'cashback-admin-stuck-claim-tx-css',
            $plugin_dir_url . 'assets/css/admin-stuck-claim-tx.css',
            array(),
            '1.0.0'
        );
        wp_enqueue_script(
            'cashback-admin-claim-approve-tx-js',
            $plugin_dir_url . 'assets/js/admin-claim-approve-tx.js',
            array(),
            '1.0.0',
            true
        );
        wp_localize_script('cashback-admin-claim-approve-tx-js', 'cashbackClaimApproveTx', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('cashback_stuck_claim_nonce'),
            'i18n'    => array(
                'genericError'      => __('Внутренняя ошибка.', 'cashback-plugin'),
                'networkError'      => __('Ошибка сети. Повторите.', 'cashback-plugin'),
                'selectFundsReady'  => __('Выберите значение', 'cashback-plugin'),
                'invalidComission'  => __('Некорректная комиссия. Используйте число до 2 знаков после точки.', 'cashback-plugin'),
                'comissionPositive' => __('Комиссия должна быть больше нуля.', 'cashback-plugin'),
            ),
        ));
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('У вас недостаточно прав.', 'cashback-plugin'));
        }

        global $wpdb;

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin listing filters; validated in get_claims_admin() via allowlist/sanitization.
        $status_filter     = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $suspicious_filter = isset($_GET['suspicious']) ? sanitize_text_field(wp_unslash($_GET['suspicious'])) : '';
        $search            = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
        $date_from         = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
        $date_to           = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
        $orderby           = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : 'created_at';
        $order             = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : 'DESC';
        $page              = max(1, absint($_GET['paged'] ?? 1));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $result = Cashback_Claims_Manager::get_claims_admin(array(
            'status'     => $status_filter,
            'suspicious' => $suspicious_filter !== '' ? (int) $suspicious_filter : '',
            'search'     => $search,
            'date_from'  => $date_from,
            'date_to'    => $date_to,
            'orderby'    => $orderby,
            'order'      => $order,
            'page'       => $page,
            'per_page'   => self::PER_PAGE,
        ));

        $stats = Cashback_Claims_Manager::get_admin_stats();

        ?>
        <div class="wrap cashback-claims-admin">
            <h1 class="wp-heading-inline"><?php esc_html_e('Заявки кэшбэка', 'cashback-plugin'); ?></h1>

            <div class="claims-stats">
                <div class="stat-card">
                    <span class="stat-value"><?php echo esc_html($stats['total']); ?></span>
                    <span class="stat-label"><?php esc_html_e('Всего', 'cashback-plugin'); ?></span>
                </div>
                <div class="stat-card stat-submitted">
                    <span class="stat-value"><?php echo esc_html($stats['submitted']); ?></span>
                    <span class="stat-label"><?php esc_html_e('Новые', 'cashback-plugin'); ?></span>
                </div>
                <div class="stat-card stat-sent">
                    <span class="stat-value"><?php echo esc_html($stats['sent_to_network']); ?></span>
                    <span class="stat-label"><?php esc_html_e('У партнёра', 'cashback-plugin'); ?></span>
                </div>
                <div class="stat-card stat-approved">
                    <span class="stat-value"><?php echo esc_html($stats['approved']); ?></span>
                    <span class="stat-label"><?php esc_html_e('Одобрены', 'cashback-plugin'); ?></span>
                </div>
                <div class="stat-card stat-declined">
                    <span class="stat-value"><?php echo esc_html($stats['declined']); ?></span>
                    <span class="stat-label"><?php esc_html_e('Отклонены', 'cashback-plugin'); ?></span>
                </div>
                <div class="stat-card stat-suspicious">
                    <span class="stat-value"><?php echo esc_html($stats['suspicious']); ?></span>
                    <span class="stat-label"><?php esc_html_e('Подозрительные', 'cashback-plugin'); ?></span>
                </div>
            </div>

            <form method="get" class="claims-filters-form">
                <input type="hidden" name="page" value="cashback-claims-admin">

                <select name="status">
                    <option value=""><?php esc_html_e('Все статусы', 'cashback-plugin'); ?></option>
                    <?php
                    $statuses = array(
                        'draft'           => __('Черновик', 'cashback-plugin'),
                        'submitted'       => __('Отправлена', 'cashback-plugin'),
                        'sent_to_network' => __('Отправлена партнёру', 'cashback-plugin'),
                        'approved'        => __('Одобрена', 'cashback-plugin'),
                        'declined'        => __('Отклонена', 'cashback-plugin'),
                    );
                    foreach ($statuses as $slug => $label) {
                        printf('<option value="%s" %s>%s</option>', esc_attr($slug), selected($status_filter, $slug, false), esc_html($label));
                    }
                    ?>
                </select>

                <select name="suspicious">
                    <option value=""><?php esc_html_e('Все', 'cashback-plugin'); ?></option>
                    <option value="1" <?php selected($suspicious_filter, '1'); ?>><?php esc_html_e('Подозрительные', 'cashback-plugin'); ?></option>
                    <option value="0" <?php selected($suspicious_filter, '0'); ?>><?php esc_html_e('Обычные', 'cashback-plugin'); ?></option>
                </select>

                <input type="text" name="search" placeholder="<?php esc_attr_e('Поиск...', 'cashback-plugin'); ?>" value="<?php echo esc_attr($search); ?>">

                <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" placeholder="<?php esc_attr_e('Дата от', 'cashback-plugin'); ?>">
                <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" placeholder="<?php esc_attr_e('Дата до', 'cashback-plugin'); ?>">

                <select name="orderby">
                    <option value="created_at" <?php selected($orderby, 'created_at'); ?>><?php esc_html_e('По дате', 'cashback-plugin'); ?></option>
                    <option value="probability_score" <?php selected($orderby, 'probability_score'); ?>><?php esc_html_e('По вероятности', 'cashback-plugin'); ?></option>
                    <option value="order_value" <?php selected($orderby, 'order_value'); ?>><?php esc_html_e('По сумме', 'cashback-plugin'); ?></option>
                    <option value="status" <?php selected($orderby, 'status'); ?>><?php esc_html_e('По статусу', 'cashback-plugin'); ?></option>
                </select>

                <select name="order">
                    <option value="DESC" <?php selected($order, 'DESC'); ?>><?php esc_html_e('По убыванию', 'cashback-plugin'); ?></option>
                    <option value="ASC" <?php selected($order, 'ASC'); ?>><?php esc_html_e('По возрастанию', 'cashback-plugin'); ?></option>
                </select>

                <button type="submit" class="button button-primary"><?php esc_html_e('Применить', 'cashback-plugin'); ?></button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=cashback-claims-admin')); ?>" class="button"><?php esc_html_e('Сбросить', 'cashback-plugin'); ?></a>
            </form>

            <?php if (empty($result['claims'])) : ?>
                <p><?php esc_html_e('Заявок не найдено.', 'cashback-plugin'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ID', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Пользователь', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Магазин', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Заказ', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Сумма', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Вероятность', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Статус', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('⚠', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Дата', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Действия', 'cashback-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($result['claims'] as $claim) :
                            $unread = (int) ( $claim['unread_count'] ?? 0 );
                        ?>
                            <tr class="<?php echo $unread > 0 ? 'claim-row-unread' : ''; ?>">
                                <td>
                                    <?php echo esc_html($claim['claim_id']); ?>
                                    <?php if ($unread > 0) : ?>
                                        <span class="claims-tab-badge"><?php echo absint($unread); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo esc_html($claim['user_display_name'] ?? '—'); ?><br>
                                    <small><?php echo esc_html($claim['user_email'] ?? ''); ?></small>
                                </td>
                                <td><?php echo esc_html($claim['product_name'] ?? '—'); ?></td>
                                <td><?php echo esc_html($claim['order_id']); ?></td>
                                <td><?php echo esc_html(number_format_i18n((float) $claim['order_value'], 2)); ?> ₽</td>
                                <td>
                                    <div class="claim-score-mini">
                                        <div class="claim-score-mini-bar" style="width: <?php echo esc_attr($claim['probability_score']); ?>%;"></div>
                                    </div>
                                    <?php echo esc_html(number_format_i18n((float) $claim['probability_score'], 1)); ?>%
                                </td>
                                <td>
                                    <span class="claim-status claim-status--<?php echo esc_attr($claim['status']); ?>">
                                        <?php echo esc_html($this->get_status_label($claim['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ((int) $claim['is_suspicious']) : ?>
                                        <?php
                                        $reasons_text = '';
                                        if (!empty($claim['suspicious_reasons'])) {
                                            $decoded = json_decode($claim['suspicious_reasons'], true);
                                            if (is_array($decoded)) {
                                                $reasons_text = implode('; ', $decoded);
                                            } else {
                                                $reasons_text = $claim['suspicious_reasons'];
                                            }
                                        }
                                        ?>
                                        <span class="dashicons dashicons-warning" style="color: #d63638;" title="<?php echo esc_attr($reasons_text); ?>"></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(Cashback_Time::display((string) $claim['created_at'], 'd.m.Y H:i')); ?></td>
                                <td>
                                    <button class="button claims-view-btn" data-claim-id="<?php echo esc_attr($claim['claim_id']); ?>">
                                        <?php esc_html_e('Просмотр', 'cashback-plugin'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php
                Cashback_Pagination::render(array(
                    'total_items'  => isset($result['total']) ? (int) $result['total'] : 0,
                    'current_page' => $page,
                    'total_pages'  => (int) $result['pages'],
                    'page_slug'    => 'cashback-claims-admin',
                    'add_args'     => array_filter(array(
                        'status'     => $status_filter,
                        'suspicious' => $suspicious_filter,
                        'search'     => $search,
                        'date_from'  => $date_from,
                        'date_to'    => $date_to,
                        'orderby'    => $orderby,
                        'order'      => $order,
                    ), static function ( $v ) {
						return $v !== '' && $v !== null;
					} ),
                ));
                ?>
            <?php endif; ?>

            <div id="claim-detail-modal" class="claim-detail-modal" style="display:none;">
                <div class="claim-detail-content">
                    <span class="claim-detail-close">&times;</span>
                    <div id="claim-detail-body"></div>
                </div>
            </div>
        </div>
        <?php
        $this->render_claim_approve_modal();
    }

    /**
     * Hidden-разметка модала «Одобрить и создать транзакцию» (Session 4-bis,
     * P0.1 F-S7-NO-MANUAL-CREDIT). Один раз на страницу — JS показывает
     * по клику на .cashback-claim-approve-tx.
     *
     * Отличия от stuck-claim-tx модала:
     *  - Нет preview-load: claim уже отрендерен в #claim-detail-body.
     *  - Только два input'а — comission + funds_ready (read-only blocks
     *    не нужны, всё видно в карточке заявки).
     *
     * Visual-классы переиспользуют admin-stuck-claim-tx.css.
     */
    private function render_claim_approve_modal(): void {
        ?>
        <div id="cashback-claim-approve-tx-backdrop" class="cashback-stuck-tx-backdrop" hidden>
            <div
                class="cashback-stuck-tx-modal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="cashback-claim-approve-tx-title"
                tabindex="-1"
            >
                <h2 id="cashback-claim-approve-tx-title">
                    <?php esc_html_e('Одобрить и создать транзакцию', 'cashback-plugin'); ?>
                    <span class="cashback-stuck-tx-claim-badge">
                        claim #<span data-bind="claim_id">—</span>
                    </span>
                </h2>

                <div class="cashback-stuck-tx-body" data-role="body" hidden>
                    <p class="description">
                        <?php esc_html_e('Транзакция будет создана атомарно: статус заявки меняется на «approved» и начисляется кэшбэк в одной БД-транзакции.', 'cashback-plugin'); ?>
                    </p>

                    <div class="cashback-stuck-tx-field">
                        <label for="cashback-claim-approve-tx-comission">
                            <?php esc_html_e('Комиссия', 'cashback-plugin'); ?>
                        </label>
                        <input
                            type="text"
                            id="cashback-claim-approve-tx-comission"
                            name="comission"
                            inputmode="decimal"
                            pattern="^\d+(\.\d{1,2})?$"
                            placeholder="0.00"
                            autocomplete="off"
                            required
                        />
                        <p class="description">
                            <?php esc_html_e('Положительное число, до 2 знаков после точки. Кэшбэк рассчитается автоматически.', 'cashback-plugin'); ?>
                        </p>
                    </div>

                    <div class="cashback-stuck-tx-field">
                        <label for="cashback-claim-approve-tx-funds-ready">
                            <?php esc_html_e('Готова к выплате?', 'cashback-plugin'); ?>
                        </label>
                        <select id="cashback-claim-approve-tx-funds-ready" name="funds_ready" required>
                            <option value=""><?php esc_html_e('Выберите вариант', 'cashback-plugin'); ?></option>
                            <option value="1"><?php esc_html_e('Да', 'cashback-plugin'); ?></option>
                            <option value="0"><?php esc_html_e('Нет', 'cashback-plugin'); ?></option>
                        </select>
                    </div>
                </div>

                <div class="cashback-stuck-tx-message" data-role="message" hidden></div>

                <div class="cashback-stuck-tx-actions">
                    <button type="button" class="button button-secondary" data-role="cancel">
                        <?php esc_html_e('Отмена', 'cashback-plugin'); ?>
                    </button>
                    <button type="button" class="button button-primary" data-role="submit">
                        <?php esc_html_e('Одобрить и создать транзакцию', 'cashback-plugin'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Transition claim status.
     */
    public function ajax_transition(): void {
        check_ajax_referer('claims_admin_transition', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => __('Недостаточно прав.', 'cashback-plugin') ));
        }

        // Server-side дедуп request_id (Группа 5 ADR, F-33-004).
        // JS уже шлёт request_id через makeRequestId(); этот блок замыкает клиентскую
        // идемпотентность серверной половиной. Scope: admin_claim_transition.
        $idem_scope      = 'admin_claim_transition';
        $idem_user_id    = get_current_user_id();
        $idem_request_id = '';
        if (isset($_POST['request_id']) && is_string($_POST['request_id'])) {
            $idem_request_id = Cashback_Idempotency::normalize_request_id(
                sanitize_text_field(wp_unslash($_POST['request_id']))
            );
        }
        if ($idem_request_id !== '') {
            $idem_stored = Cashback_Idempotency::get_stored_result($idem_scope, $idem_user_id, $idem_request_id);
            if ($idem_stored !== null) {
                wp_send_json_success($idem_stored);
                return;
            }
            if (!Cashback_Idempotency::claim($idem_scope, $idem_user_id, $idem_request_id)) {
                wp_send_json_error(array(
                    'code'    => 'in_progress',
                    'message' => __('Запрос уже обрабатывается. Повторите через несколько секунд.', 'cashback-plugin'),
                ), 409);
                return;
            }
        }

        $claim_id   = absint($_POST['claim_id'] ?? 0);
        $new_status = sanitize_text_field(wp_unslash($_POST['new_status'] ?? ''));
        $note       = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));

        if (!$claim_id || !$new_status) {
            if ($idem_request_id !== '') {
                Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
            }
            wp_send_json_error(array( 'message' => __('Неверные параметры.', 'cashback-plugin') ));
        }

        $result = Cashback_Claims_Manager::transition_status(
            $claim_id,
            $new_status,
            $note,
            'admin',
            get_current_user_id()
        );

        if ($result['success']) {
            $response_data = array( 'message' => __('Статус обновлён.', 'cashback-plugin') );
            if ($idem_request_id !== '') {
                Cashback_Idempotency::store_result($idem_scope, $idem_user_id, $idem_request_id, $response_data);
            }
            wp_send_json_success($response_data);
        } else {
            // Освобождаем слот — retry должен иметь шанс переобработать.
            if ($idem_request_id !== '') {
                Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
            }
            wp_send_json_error(array( 'message' => $result['error'] ));
        }
    }

    /**
     * AJAX: Add admin note.
     */
    public function ajax_add_note(): void {
        check_ajax_referer('claims_admin_note', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => __('Недостаточно прав.', 'cashback-plugin') ));
        }

        // Server-side дедуп request_id (Группа 5 ADR, F-33-004 partial).
        // JS уже шлёт request_id через makeRequestId() — здесь замыкаем серверную
        // половину: retry с тем же id не создаёт дубль admin-комментария.
        $idem_scope      = 'admin_claim_add_note';
        $idem_user_id    = get_current_user_id();
        $idem_request_id = '';
        if (isset($_POST['request_id']) && is_string($_POST['request_id'])) {
            $idem_request_id = Cashback_Idempotency::normalize_request_id(
                sanitize_text_field(wp_unslash($_POST['request_id']))
            );
        }
        if ($idem_request_id !== '') {
            $idem_stored = Cashback_Idempotency::get_stored_result($idem_scope, $idem_user_id, $idem_request_id);
            if ($idem_stored !== null) {
                wp_send_json_success($idem_stored);
                return;
            }
            if (!Cashback_Idempotency::claim($idem_scope, $idem_user_id, $idem_request_id)) {
                wp_send_json_error(array(
                    'code'    => 'in_progress',
                    'message' => __('Запрос уже обрабатывается. Повторите через несколько секунд.', 'cashback-plugin'),
                ), 409);
                return;
            }
        }

        $claim_id = absint($_POST['claim_id'] ?? 0);
        $note     = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));

        if (!$claim_id || empty($note)) {
            if ($idem_request_id !== '') {
                Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
            }
            wp_send_json_error(array( 'message' => __('Неверные параметры.', 'cashback-plugin') ));
        }

        $result = Cashback_Claims_Manager::add_note($claim_id, $note, get_current_user_id());

        if ($result['success']) {
            $response_data = array( 'message' => __('Комментарий добавлен.', 'cashback-plugin') );
            if ($idem_request_id !== '') {
                Cashback_Idempotency::store_result($idem_scope, $idem_user_id, $idem_request_id, $response_data);
            }
            wp_send_json_success($response_data);
        } else {
            if ($idem_request_id !== '') {
                Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
            }
            wp_send_json_error(array( 'message' => $result['error'] ));
        }
    }

    /**
     * AJAX: Get claim detail.
     */
    public function ajax_get_detail(): void {
        check_ajax_referer('claims_admin_detail', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => __('Недостаточно прав.', 'cashback-plugin') ));
        }

        $claim_id = absint($_POST['claim_id'] ?? 0);

        if (!$claim_id) {
            wp_send_json_error(array( 'message' => __('Неверный параметр.', 'cashback-plugin') ));
        }

        $claim = Cashback_Claims_Manager::get_claim($claim_id);

        if (!$claim) {
            wp_send_json_error(array( 'message' => __('Заявка не найдена.', 'cashback-plugin') ));
        }

        Cashback_Claims_DB::mark_admin_events_read($claim_id);

        ob_start();
        $this->render_claim_detail($claim);
        $html = ob_get_clean();

        wp_send_json_success(array( 'html' => $html ));
    }

    /**
     * AJAX: Get stats.
     */
    public function ajax_stats(): void {
        check_ajax_referer('claims_admin_stats', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => __('Недостаточно прав.', 'cashback-plugin') ));
        }

        wp_send_json_success(Cashback_Claims_Manager::get_admin_stats());
    }

    /**
     * AJAX: атомарно перевести claim в `approved` И создать парную транзакцию
     * в одной TX. Закрывает баг F-S7-NO-MANUAL-CREDIT (run-h backlog) — до
     * этого `transition_status('approved')` менял только статус claim'а,
     * не зачисляя кэшбэк юзеру.
     *
     * Контракт идентичен `handle_create_stuck_claim_tx` (Группа 15) по полям
     * (comission + funds_ready), nonce'у (`cashback_stuck_claim_nonce`),
     * idempotency_key (`manual_claim_<id>` UNIQUE), маппингу INSERT
     * (api_verified=1, order_status='completed', currency='RUB',
     * created_by_admin=1) и audit catalog (`manual_tx_from_stuck_claim`,
     * `transaction_created`). Отличается:
     *  - source='claim_approve' в audit details (vs 'manual_stuck_claim'),
     *  - валидный source-status: `submitted | sent_to_network` (а не только
     *    pre-approved, как в stuck-flow),
     *  - дополнительный audit `claim_approved` post-transition,
     *  - idempotency scope `admin_claim_approve_with_tx` (отдельный от stuck).
     *
     * Все mutation внутри одной TX → если INSERT упадёт, status откатится
     * (consistency-safe, в отличие от наивного «два AJAX подряд»).
     */
    public function ajax_approve_with_tx(): void {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(
            sanitize_text_field(wp_unslash((string) $_POST['nonce'])),
            'cashback_stuck_claim_nonce'
        )) {
            wp_send_json_error(array( 'message' => __('Неверный токен безопасности.', 'cashback-plugin') ), 403);
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => __('Недостаточно прав.', 'cashback-plugin') ), 403);
            return;
        }

        $idem_scope   = 'admin_claim_approve_with_tx';
        $admin_id     = (int) get_current_user_id();
        $idem_request = '';
        if (isset($_POST['request_id']) && is_string($_POST['request_id'])) {
            $idem_request = Cashback_Idempotency::normalize_request_id(
                sanitize_text_field(wp_unslash($_POST['request_id']))
            );
        }
        if ($idem_request !== '') {
            $stored = Cashback_Idempotency::get_stored_result($idem_scope, $admin_id, $idem_request);
            if ($stored !== null) {
                wp_send_json_success($stored);
                return;
            }
            if (!Cashback_Idempotency::claim($idem_scope, $admin_id, $idem_request)) {
                wp_send_json_error(array(
                    'code'    => 'in_progress',
                    'message' => __('Запрос уже обрабатывается. Повторите через несколько секунд.', 'cashback-plugin'),
                ), 409);
                return;
            }
        }

        $claim_id = isset($_POST['claim_id']) ? absint($_POST['claim_id']) : 0;
        if ($claim_id <= 0) {
            if ($idem_request !== '') {
                Cashback_Idempotency::forget($idem_scope, $admin_id, $idem_request);
            }
            wp_send_json_error(array( 'message' => __('Некорректный claim_id.', 'cashback-plugin') ));
            return;
        }

        // funds_ready — строгая строковая проверка ДО любых cast'ов.
        $raw_funds_ready = isset($_POST['funds_ready'])
            ? sanitize_text_field(wp_unslash((string) $_POST['funds_ready']))
            : '';
        if ($raw_funds_ready !== '0' && $raw_funds_ready !== '1') {
            if ($idem_request !== '') {
                Cashback_Idempotency::forget($idem_scope, $admin_id, $idem_request);
            }
            wp_send_json_error(array( 'message' => __('Выберите значение', 'cashback-plugin') ));
            return;
        }
        $funds_ready = (int) $raw_funds_ready;

        // comission — строгая regex + положительное.
        $raw_comission = isset($_POST['comission'])
            ? trim(sanitize_text_field(wp_unslash((string) $_POST['comission'])))
            : '';
        if (!(bool) preg_match('/^\d+(\.\d{1,2})?$/', $raw_comission)) {
            if ($idem_request !== '') {
                Cashback_Idempotency::forget($idem_scope, $admin_id, $idem_request);
            }
            wp_send_json_error(array( 'message' => __('Некорректная комиссия. Используйте число до 2 знаков после точки.', 'cashback-plugin') ));
            return;
        }
        $comission_positive = function_exists('bccomp')
            ? ( bccomp($raw_comission, '0', 2) === 1 )
            : ( (float) $raw_comission > 0.0 );
        if (!$comission_positive) {
            if ($idem_request !== '') {
                Cashback_Idempotency::forget($idem_scope, $admin_id, $idem_request);
            }
            wp_send_json_error(array( 'message' => __('Комиссия должна быть больше нуля.', 'cashback-plugin') ));
            return;
        }

        $note = isset($_POST['note'])
            ? sanitize_textarea_field(wp_unslash((string) $_POST['note']))
            : '';

        global $wpdb;

        $claims_table = $wpdb->prefix . 'cashback_claims';
        $click_table  = $wpdb->prefix . 'cashback_click_log';
        $tx_table     = $wpdb->prefix . 'cashback_transactions';

        $wpdb->query('START TRANSACTION');

        try {
            $claim = $wpdb->get_row($wpdb->prepare(
                'SELECT claim_id, user_id, click_id, merchant_id, merchant_name,
                        product_id, product_name,
                        order_id, order_value, order_date, status
                 FROM %i
                 WHERE claim_id = %d
                 FOR UPDATE',
                $claims_table,
                $claim_id
            ), ARRAY_A);

            if (!is_array($claim)) {
                throw new \RuntimeException(__('Claim не найден.', 'cashback-plugin'));
            }

            $current_status = (string) ($claim['status'] ?? '');
            // Approved-переход допустим только из submitted или sent_to_network
            // (см. Cashback_Claims_Manager::VALID_TRANSITIONS).
            if ($current_status !== 'submitted' && $current_status !== 'sent_to_network') {
                throw new \RuntimeException(sprintf(
                    /* translators: %s: текущий статус claim'а. */
                    __('Недопустимый переход в "approved" из статуса "%s".', 'cashback-plugin'),
                    $current_status
                ));
            }

            // UPDATE статуса с защитой от race (CAS на старый статус).
            $updated = $wpdb->query($wpdb->prepare(
                'UPDATE %i SET status = %s WHERE claim_id = %d AND status = %s',
                $claims_table,
                'approved',
                $claim_id,
                $current_status
            ));

            if ((int) $updated !== 1) {
                throw new \RuntimeException(__('Статус заявки уже изменён другим действием. Обновите страницу.', 'cashback-plugin'));
            }

            // Event log в claim_events — через public helper.
            $event_logged = Cashback_Claims_Manager::log_event($claim_id, 'approved', $note, $admin_id, 'admin');
            if (!$event_logged) {
                throw new \RuntimeException(__('Не удалось записать событие claim_events.', 'cashback-plugin'));
            }

            $user_id  = (int) $claim['user_id'];
            $click_id = (string) $claim['click_id'];

            // Pre-flight FOR UPDATE на existing tx по (user_id, click_id) —
            // защита от race с api-sync cron / handle_create_stuck_claim_tx.
            // Split-order: один click_id → много транзакций. Блокируем только
            // при АКТИВНОМ кэшбэке по click_id (declined-sibling не мешает).
            $existing_tx_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM %i
                 WHERE user_id = %d AND click_id = %s
                   AND order_status IN ('waiting','hold','completed','balance')
                 LIMIT 1 FOR UPDATE",
                $tx_table,
                $user_id,
                $click_id
            ));
            if ($existing_tx_id > 0) {
                throw new \RuntimeException(sprintf(
                    /* translators: %d: id транзакции. */
                    __('Активный кэшбэк для этого click_id уже существует (ID %d).', 'cashback-plugin'),
                    $existing_tx_id
                ));
            }

            $click = $wpdb->get_row($wpdb->prepare(
                'SELECT cpa_network, created_at FROM %i WHERE click_id = %s LIMIT 1',
                $click_table,
                $click_id
            ), ARRAY_A);

            $cpa_slug   = is_array($click) ? (string) ($click['cpa_network'] ?? '') : '';
            $click_time = is_array($click) ? (string) ($click['created_at'] ?? '') : '';

            // Каноничные display-имена через shared helpers (DRY с stuck-claim-tx flow).
            $shop_name    = Cashback_Balance_Reconciliation_Admin::resolve_product_name($claim);
            $network_name = Cashback_Balance_Reconciliation_Admin::resolve_network_name($cpa_slug);

            $idempotency_key = 'manual_claim_' . $claim_id;

            // Триггер calculate_cashback_before_insert сам проставит cashback,
            // reference_id, applied_cashback_rate. NULL-поля убираем (wpdb не умеет NULL).
            $insert_data = array(
                'user_id'            => $user_id,
                'order_number'       => (string) ($claim['order_id'] ?? ''),
                'offer_name'         => $shop_name,
                'order_status'       => 'completed',
                'partner'            => $network_name,
                'sum_order'          => (string) ($claim['order_value'] ?? '0.00'),
                'comission'          => $raw_comission,
                'currency'           => 'RUB',
                'api_verified'       => 1,
                'action_date'        => (string) ($claim['order_date'] ?? ''),
                'click_id'           => $click_id,
                'idempotency_key'    => $idempotency_key,
                'original_cpa_subid' => (string) $user_id,
                'funds_ready'        => $funds_ready,
                'created_by_admin'   => 1,
            );
            if (isset($claim['merchant_id']) && $claim['merchant_id'] !== null) {
                $insert_data['offer_id'] = (int) $claim['merchant_id'];
            }
            if ($click_time !== '') {
                $insert_data['click_time'] = $click_time;
            }

            $insert_format = array();
            foreach ($insert_data as $col => $val) {
                if (in_array($col, array( 'user_id', 'offer_id', 'api_verified', 'funds_ready', 'created_by_admin' ), true)) {
                    $insert_format[] = '%d';
                } else {
                    $insert_format[] = '%s';
                }
            }

            $inserted = $wpdb->insert($tx_table, $insert_data, $insert_format);

            if ($inserted === false) {
                $db_error = (string) $wpdb->last_error;
                if ($db_error !== '' && stripos($db_error, 'Duplicate') !== false) {
                    throw new \RuntimeException(__('Транзакция уже создана (idempotency_key).', 'cashback-plugin'));
                }
                throw new \RuntimeException('INSERT failed: ' . $db_error);
            }

            $tx_id = (int) $wpdb->insert_id;

            $inserted_row = $wpdb->get_row($wpdb->prepare(
                'SELECT reference_id, cashback, applied_cashback_rate
                 FROM %i WHERE id = %d',
                $tx_table,
                $tx_id
            ), ARRAY_A);

            $reference_id          = is_array($inserted_row) ? (string) ($inserted_row['reference_id'] ?? '') : '';
            $cashback              = is_array($inserted_row) ? (string) ($inserted_row['cashback'] ?? '') : '';
            $applied_cashback_rate = is_array($inserted_row) ? (string) ($inserted_row['applied_cashback_rate'] ?? '') : '';

            if (class_exists('Cashback_Encryption')) {
                // 1) catalog: claim_approved (до COMMIT — внутри той же TX чтобы
                // консистентность audit'а соответствовала фактическому commit).
                try {
                    Cashback_Encryption::write_audit_log(
                        'claim_approved',
                        $admin_id,
                        'claim',
                        $claim_id,
                        array(
                            'user_id'    => $user_id,
                            'old_status' => $current_status,
                            'new_status' => 'approved',
                            'note'       => $note,
                            'actor_type' => 'admin',
                            'source'     => 'claim_approve',
                        )
                    );
                } catch (\Throwable $e) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Audit telemetry fail-soft (G2 ADR).
                    error_log('[cashback-audit] claim_approved: ' . $e->getMessage());
                }

                // 2) subject-specific: manual_tx_from_stuck_claim (переиспользуем
                // существующий action из catalog, source='claim_approve' для дифф).
                Cashback_Encryption::write_audit_log(
                    'manual_tx_from_stuck_claim',
                    $admin_id,
                    'transaction',
                    $tx_id,
                    array(
                        'claim_id'              => $claim_id,
                        'user_id'               => $user_id,
                        'click_id'              => $click_id,
                        'comission'             => $raw_comission,
                        'funds_ready'           => $funds_ready,
                        'cashback'              => $cashback,
                        'reference_id'          => $reference_id,
                        'applied_cashback_rate' => $applied_cashback_rate,
                        'idempotency_key'       => $idempotency_key,
                        'request_id'            => $idem_request,
                        'source'                => 'claim_approve',
                    )
                );

                // 3) catalog-universal: transaction_created (audit-log-completeness ADR).
                try {
                    Cashback_Encryption::write_audit_log(
                        'transaction_created',
                        $admin_id,
                        'transaction',
                        $tx_id,
                        array(
                            'user_id'         => $user_id,
                            'reference_id'    => $reference_id,
                            'cashback'        => $cashback,
                            'click_id'        => $click_id,
                            'source'          => 'claim_approve',
                            'idempotency_key' => $idempotency_key,
                        )
                    );
                } catch (\Throwable $e) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Audit telemetry fail-soft (G2 ADR).
                    error_log('[cashback-audit] transaction_created: ' . $e->getMessage());
                }
            }

            $wpdb->query('COMMIT');

            do_action('cashback_claim_status_changed', $claim_id, $current_status, 'approved', $note, 'admin', $admin_id);

            $payload = array(
                'tx_id'        => $tx_id,
                'claim_id'     => $claim_id,
                'reference_id' => $reference_id,
                'cashback'     => $cashback,
                'funds_ready'  => $funds_ready,
                'message'      => sprintf(
                    /* translators: 1: reference_id, 2: cashback. */
                    __('Транзакция %1$s создана. Кэшбэк: %2$s.', 'cashback-plugin'),
                    $reference_id !== '' ? $reference_id : ( '#' . $tx_id ),
                    $cashback
                ),
            );

            if ($idem_request !== '') {
                Cashback_Idempotency::store_result($idem_scope, $admin_id, $idem_request, $payload);
            }

            wp_send_json_success($payload);
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($idem_request !== '') {
                Cashback_Idempotency::forget($idem_scope, $admin_id, $idem_request);
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic logging.
            error_log('[cashback_claim_approve_with_tx] ' . $e->getMessage());
            wp_send_json_error(array( 'message' => $e->getMessage() ));
        }
    }

    /**
     * Render claim detail in modal.
     */
    private function render_claim_detail( array $claim ): void {
        ?>
        <h2><?php esc_html_e('Заявка #', 'cashback-plugin'); ?><?php echo esc_html($claim['claim_id']); ?></h2>

        <div class="claim-detail-grid">
            <div class="claim-detail-section">
                <h3><?php esc_html_e('Данные заявки', 'cashback-plugin'); ?></h3>
                <table class="widefat">
                    <tr><th><?php esc_html_e('Пользователь', 'cashback-plugin'); ?></th><td><?php echo esc_html($claim['user_display_name'] ?? ''); ?> (<?php echo esc_html($claim['user_email'] ?? ''); ?>)</td></tr>
                    <tr><th><?php esc_html_e('Магазин', 'cashback-plugin'); ?></th><td><?php echo esc_html($claim['product_name'] ?? '—'); ?></td></tr>
                    <tr><th><?php esc_html_e('Мерчант', 'cashback-plugin'); ?></th><td><?php echo esc_html($claim['merchant_name'] ?? '—'); ?> (ID: <?php echo esc_html($claim['merchant_id'] ?? '—'); ?>)</td></tr>
                    <tr><th><?php esc_html_e('Номер заказа', 'cashback-plugin'); ?></th><td><?php echo esc_html($claim['order_id']); ?></td></tr>
                    <tr><th><?php esc_html_e('Сумма заказа', 'cashback-plugin'); ?></th><td><?php echo esc_html(number_format_i18n((float) $claim['order_value'], 2)); ?> ₽</td></tr>
                    <tr><th><?php esc_html_e('Дата заказа', 'cashback-plugin'); ?></th><td><?php echo esc_html(Cashback_Time::display((string) $claim['order_date'], 'd.m.Y')); ?></td></tr>
                    <tr><th><?php esc_html_e('Вероятность', 'cashback-plugin'); ?></th><td><?php echo esc_html(number_format_i18n((float) $claim['probability_score'], 1)); ?>%</td></tr>
                    <tr><th><?php esc_html_e('Статус', 'cashback-plugin'); ?></th><td><span class="claim-status claim-status--<?php echo esc_attr($claim['status']); ?>"><?php echo esc_html($this->get_status_label($claim['status'])); ?></span></td></tr>
                    <?php
                    // F-20-002: разложение probability_score по 5 факторам.
                    // Legacy-строки (до миграции) имеют NULL — скрываем секцию.
                    $breakdown = isset($claim['scoring_breakdown']) && is_string($claim['scoring_breakdown']) && $claim['scoring_breakdown'] !== ''
                        ? json_decode((string) $claim['scoring_breakdown'], true)
                        : null;
                    if (is_array($breakdown)) :
                        $factor_labels = array(
                            'time'        => __('Время', 'cashback-plugin'),
                            'merchant'    => __('Мерчант', 'cashback-plugin'),
                            'user'        => __('Юзер', 'cashback-plugin'),
                            'consistency' => __('Консистентность', 'cashback-plugin'),
                            'risk'        => __('Риск', 'cashback-plugin'),
                        );
                        ?>
                        <tr><th colspan="2"><?php esc_html_e('Разложение скоринга', 'cashback-plugin'); ?></th></tr>
                        <?php
                        foreach ($factor_labels as $key => $label) :
                            $raw = isset($breakdown[ $key ]) ? (float) $breakdown[ $key ] : null;
                            if ($raw === null) {
                                continue;
                            }
                            // F-20-002 UX: строка «Риск» визуально инвертируется.
                            // Storage breakdown['risk'] = score_risk_factor * 100,
                            // т.е. «чистота юзера» (high = хорошо) — консистентно
                            // с остальными 4 факторами внутри формулы. Но label
                            // «Риск» читается интуитивно как «уровень риска», поэтому
                            // в UI показываем 100-raw и флипаем цвета ТОЛЬКО для
                            // этой строки. Формула probability_score не затрагивается.
                            if ($key === 'risk') {
                                $value = 100.0 - $raw;
                                $color = $value >= 70 ? '#d63638' : ( $value >= 40 ? '#b8860b' : '#2a8f2a' );
                            } else {
                                $value = $raw;
                                $color = $value >= 70 ? '#2a8f2a' : ( $value >= 40 ? '#b8860b' : '#d63638' );
                            }
                        ?>
                            <tr>
                                <th><?php echo esc_html($label); ?></th>
                                <td>
                                    <span style="display:inline-block;width:120px;height:8px;background:#eee;border-radius:4px;vertical-align:middle;overflow:hidden;">
                                        <span style="display:block;height:100%;width:<?php echo esc_attr((string) max(0, min(100, $value))); ?>%;background:<?php echo esc_attr($color); ?>;"></span>
                                    </span>
                                    <span style="margin-left:8px;color:<?php echo esc_attr($color); ?>;"><?php echo esc_html(number_format_i18n($value, 1)); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr><th><?php esc_html_e('Подозрительная', 'cashback-plugin'); ?></th><td><?php echo (int) $claim['is_suspicious'] ? '<span style="color:#d63638;">' . esc_html__('Да', 'cashback-plugin') . '</span>' : esc_html__('Нет', 'cashback-plugin'); ?></td></tr>
                    <tr><th><?php esc_html_e('Click ID', 'cashback-plugin'); ?></th><td><code><?php echo esc_html($claim['click_id'] ?? '—'); ?></code></td></tr>
                    <tr><th><?php esc_html_e('IP', 'cashback-plugin'); ?></th><td><?php echo esc_html($claim['ip_address']); ?></td></tr>
                    <tr><th><?php esc_html_e('User-Agent', 'cashback-plugin'); ?></th><td><small><?php echo esc_html($claim['user_agent'] ?? '—'); ?></small></td></tr>
                    <tr><th><?php esc_html_e('Создана', 'cashback-plugin'); ?></th><td><?php echo esc_html(Cashback_Time::display((string) $claim['created_at'], 'd.m.Y H:i:s')); ?></td></tr>
                </table>

                <?php if (!empty($claim['comment'])) : ?>
                    <h3><?php esc_html_e('Комментарий пользователя', 'cashback-plugin'); ?></h3>
                    <p><?php echo esc_html($claim['comment']); ?></p>
                <?php endif; ?>

                <?php if (!empty($claim['suspicious_reasons_decoded'])) : ?>
                    <h3><?php esc_html_e('Причины подозрения', 'cashback-plugin'); ?></h3>
                    <ul>
                        <?php foreach ($claim['suspicious_reasons_decoded'] as $reason) : ?>
                            <li><?php echo esc_html($reason); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="claim-detail-section">
                <h3><?php esc_html_e('История событий', 'cashback-plugin'); ?></h3>
                <?php if (!empty($claim['events'])) : ?>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Дата', 'cashback-plugin'); ?></th>
                                <th><?php esc_html_e('Статус', 'cashback-plugin'); ?></th>
                                <th><?php esc_html_e('Автор', 'cashback-plugin'); ?></th>
                                <th><?php esc_html_e('Комментарий', 'cashback-plugin'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($claim['events'] as $event) : ?>
                                <tr>
                                    <td><?php echo esc_html(Cashback_Time::display((string) $event['created_at'], 'd.m.Y H:i')); ?></td>
                                    <td><?php echo esc_html($event['status']); ?></td>
                                    <td><?php echo esc_html($event['actor_type'] . ( $event['actor_name'] ? ': ' . $event['actor_name'] : '' )); ?></td>
                                    <td><?php echo esc_html($event['note'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p><?php esc_html_e('Нет событий.', 'cashback-plugin'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="claim-actions" data-claim-id="<?php echo esc_attr($claim['claim_id']); ?>">
            <h3><?php esc_html_e('Действия', 'cashback-plugin'); ?></h3>

            <div class="claim-action-buttons">
                <?php if ($claim['status'] === 'submitted') : ?>
                    <button class="button button-primary claims-action-btn" data-action="sent_to_network" data-claim-id="<?php echo esc_attr($claim['claim_id']); ?>">
                        <?php esc_html_e('Отправить партнёру', 'cashback-plugin'); ?>
                    </button>
                    <button class="button button-success cashback-claim-approve-tx" data-claim-id="<?php echo esc_attr($claim['claim_id']); ?>">
                        <?php esc_html_e('Одобрить и создать транзакцию', 'cashback-plugin'); ?>
                    </button>
                    <button class="button button-danger claims-action-btn" data-action="declined" data-claim-id="<?php echo esc_attr($claim['claim_id']); ?>">
                        <?php esc_html_e('Отклонить', 'cashback-plugin'); ?>
                    </button>
                <?php elseif ($claim['status'] === 'sent_to_network') : ?>
                    <button class="button button-success cashback-claim-approve-tx" data-claim-id="<?php echo esc_attr($claim['claim_id']); ?>">
                        <?php esc_html_e('Одобрить и создать транзакцию', 'cashback-plugin'); ?>
                    </button>
                    <button class="button button-danger claims-action-btn" data-action="declined" data-claim-id="<?php echo esc_attr($claim['claim_id']); ?>">
                        <?php esc_html_e('Отклонить', 'cashback-plugin'); ?>
                    </button>
                <?php endif; ?>
            </div>

            <div class="claim-note-form">
                <textarea id="claim-note-text" rows="3" placeholder="<?php esc_attr_e('Добавить комментарий...', 'cashback-plugin'); ?>"></textarea>
                <button class="button claims-note-btn" data-claim-id="<?php echo esc_attr($claim['claim_id']); ?>">
                    <?php esc_html_e('Добавить комментарий', 'cashback-plugin'); ?>
                </button>
            </div>
        </div>
        <?php
    }

    private function get_status_label( string $status ): string {
        $labels = array(
            'draft'           => __('Черновик', 'cashback-plugin'),
            'submitted'       => __('Отправлена', 'cashback-plugin'),
            'sent_to_network' => __('Отправлена партнёру', 'cashback-plugin'),
            'approved'        => __('Одобрена', 'cashback-plugin'),
            'declined'        => __('Отклонена', 'cashback-plugin'),
        );
        return $labels[ $status ] ?? $status;
    }
}
