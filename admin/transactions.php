<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Transactions_Admin {

    use AdminPaginationTrait;

    private string $registered_table;
    private string $unregistered_table;
    private int $per_page = 20;

    public function __construct() {
        global $wpdb;
        $this->registered_table   = $wpdb->prefix . 'cashback_transactions';
        $this->unregistered_table = $wpdb->prefix . 'cashback_unregistered_transactions';

        add_action('admin_menu', array( $this, 'add_admin_menu' ));
        add_action('wp_ajax_update_transaction', array( $this, 'handle_update_transaction' ));
        add_action('wp_ajax_get_transaction', array( $this, 'handle_get_transaction' ));
        add_action('wp_ajax_transfer_unregistered_transaction', array( $this, 'handle_transfer_unregistered' ));
        add_action('admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ));
    }

    public function add_admin_menu(): void {
        add_submenu_page(
            'cashback-overview',
            'Транзакции',
            'Транзакции',
            'manage_options',
            'cashback-transactions',
            array( $this, 'render_page' )
        );
    }

    public function enqueue_admin_scripts( string $hook ): void {
        $allowed_hooks = array(
            'cashback-overview_page_cashback-transactions',
            'toplevel_page_cashback-transactions',
            'admin_page_cashback-transactions',
        );

        $is_target_page = in_array($hook, $allowed_hooks, true) ||
            ( isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-transactions' );

        if (!$is_target_page) {
            return;
        }

        wp_enqueue_style(
            'cashback-admin-transactions-css',
            plugins_url('../assets/css/admin.css', __FILE__),
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'cashback-admin-transactions',
            plugins_url('../assets/js/admin-transactions.js', __FILE__),
            array( 'jquery' ),
            '1.0.0',
            true
        );

        wp_localize_script('cashback-admin-transactions', 'cashbackTransactionsData', array(
            'updateNonce'   => wp_create_nonce('update_transaction_nonce'),
            'getNonce'      => wp_create_nonce('get_transaction_nonce'),
            'transferNonce' => wp_create_nonce('transfer_unregistered_nonce'),
        ));
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }

        global $wpdb;

        // Tab
        $current_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'registered';
        if (!in_array($current_tab, array( 'registered', 'unregistered' ), true)) {
            $current_tab = 'registered';
        }

        $table_name = ( $current_tab === 'registered' ) ? $this->registered_table : $this->unregistered_table;

        // Filters
        $filter_status  = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $filter_partner = isset($_GET['partner']) ? sanitize_text_field(wp_unslash($_GET['partner'])) : '';
        $search_query   = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';

        $allowed_statuses = array( 'waiting', 'completed', 'declined', 'hold', 'balance' );
        if (!empty($filter_status) && !in_array($filter_status, $allowed_statuses, true)) {
            $filter_status = '';
        }

        // Получаем список уникальных сетей для фильтра
        $available_partners = $wpdb->get_col(
            "SELECT DISTINCT partner FROM {$table_name} WHERE partner IS NOT NULL AND partner != '' ORDER BY partner ASC"
        );
        if (!empty($filter_partner) && !in_array($filter_partner, $available_partners, true)) {
            $filter_partner = '';
        }

        // Pagination (с ограничением верхней границы для защиты от DoS)
        $max_allowed_pages = 5000;
        $current_page      = max(1, min(absint($_GET['paged'] ?? 1), $max_allowed_pages));
        $offset            = ( $current_page - 1 ) * $this->per_page;

        // Build WHERE
        $where_conditions = array();
        $where_params     = array();

        if (!empty($filter_status)) {
            $where_conditions[] = 'order_status = %s';
            $where_params[]     = $filter_status;
        }

        if (!empty($filter_partner)) {
            $where_conditions[] = 'partner = %s';
            $where_params[]     = $filter_partner;
        }

        if (!empty($search_query)) {
            $like_pattern       = '%' . $wpdb->esc_like($search_query) . '%';
            $where_conditions[] = '(reference_id LIKE %s OR click_id LIKE %s OR order_number LIKE %s)';
            $where_params[]     = $like_pattern;
            $where_params[]     = $like_pattern;
            $where_params[]     = $like_pattern;
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);
        }

        // Count
        if (!empty($where_params)) {
            $total_items = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name}{$where_clause}",
                    ...$where_params
                )
            );
        } else {
            $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        }

        $total_pages = (int) ceil($total_items / $this->per_page);

        // Fetch rows
        $query_params = array_merge($where_params, array( $this->per_page, $offset ));
        if (!empty($query_params)) {
            $transactions = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, reference_id, user_id, order_number, partner, order_status, sum_order, comission, cashback, click_id, created_at
                     FROM {$table_name}{$where_clause}
                     ORDER BY created_at DESC
                     LIMIT %d OFFSET %d",
                    ...$query_params
                ),
                ARRAY_A
            );
        } else {
            $transactions = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, reference_id, user_id, order_number, partner, order_status, sum_order, comission, cashback, click_id, created_at
                     FROM {$table_name}
                     ORDER BY created_at DESC
                     LIMIT %d OFFSET %d",
                    $this->per_page,
                    $offset
                ),
                ARRAY_A
            );
        }

        $base_url = admin_url('admin.php?page=cashback-transactions');
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Транзакции</h1>
            <hr class="wp-header-end">

            <!-- Tabs -->
            <nav class="nav-tab-wrapper" style="margin-bottom: 15px;">
                <a href="<?php echo esc_url(add_query_arg('tab', 'registered', $base_url)); ?>"
                    class="nav-tab <?php echo $current_tab === 'registered' ? 'nav-tab-active' : ''; ?>">
                    Зарегистрированные
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'unregistered', $base_url)); ?>"
                    class="nav-tab <?php echo $current_tab === 'unregistered' ? 'nav-tab-active' : ''; ?>">
                    Незарегистрированные
                </a>
            </nav>

            <!-- Filters -->
            <div class="tablenav top">
                <div class="alignleft actions">
                    <select id="filter-status">
                        <option value="">Все статусы</option>
                        <?php foreach ($allowed_statuses as $status) : ?>
                            <option value="<?php echo esc_attr($status); ?>" <?php selected($filter_status, $status); ?>>
                                <?php echo esc_html($this->get_status_label($status)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select id="filter-partner">
                        <option value="">Все сети</option>
                        <?php foreach ($available_partners as $partner_name) : ?>
                            <option value="<?php echo esc_attr($partner_name); ?>" <?php selected($filter_partner, $partner_name); ?>>
                                <?php echo esc_html($partner_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="search" id="filter-search"
                            value="<?php echo esc_attr($search_query); ?>"
                            placeholder="Поиск по ID, click_id или order_number"
                            style="min-width: 280px;" />

                    <button type="button" id="filter-submit" class="button action">Фильтровать</button>
                    <?php if (!empty($filter_status) || !empty($filter_partner) || !empty($search_query)) : ?>
                        <button type="button" id="filter-reset" class="button action">Сбросить</button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($search_query)) : ?>
                <p>Результаты поиска: <strong>&laquo;<?php echo esc_html($search_query); ?>&raquo;</strong>
                    &mdash; найдено: <?php echo esc_html((string) $total_items); ?></p>
            <?php endif; ?>

            <!-- Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" style="width: 50px;">ID</th>
                        <th scope="col" style="width: 120px;">Номер</th>
                        <th scope="col" style="width: 80px;">User ID</th>
                        <th scope="col">Номер заказа</th>
                        <th scope="col">Сеть</th>
                        <th scope="col" style="width: 140px;">Статус</th>
                        <th scope="col" style="width: 120px;">Сумма заказа</th>
                        <th scope="col" style="width: 120px;">Комиссия</th>
                        <th scope="col" style="width: 100px;">Кэшбэк</th>
                        <th scope="col">Click ID</th>
                        <th scope="col" style="width: 140px;">Дата</th>
                        <th scope="col" style="width: 200px;">Действия</th>
                    </tr>
                </thead>
                <tbody id="transactions-tbody">
                    <?php if (!empty($transactions)) : ?>
                        <?php foreach ($transactions as $tx) : ?>
                            <?php $is_editable = ( $tx['order_status'] !== 'balance' ); ?>
                            <tr data-transaction-id="<?php echo esc_attr($tx['id']); ?>"
                                data-tab="<?php echo esc_attr($current_tab); ?>">
                                <td><?php echo esc_html($tx['id']); ?></td>
                                <td><code><?php echo esc_html($tx['reference_id'] ?? ''); ?></code></td>
                                <td><?php echo esc_html($tx['user_id']); ?></td>
                                <td><?php echo esc_html($tx['order_number'] ?? ''); ?></td>
                                <td><?php echo esc_html($tx['partner'] ?? ''); ?></td>
                                <td class="<?php echo $is_editable ? 'edit-field' : ''; ?>" data-field="order_status">
                                    <?php echo esc_html($this->get_status_label($tx['order_status'])); ?>
                                </td>
                                <td class="<?php echo $is_editable ? 'edit-field' : ''; ?>" data-field="sum_order">
                                    <?php echo esc_html($tx['sum_order'] ?? '0.00'); ?>
                                </td>
                                <td class="<?php echo $is_editable ? 'edit-field' : ''; ?>" data-field="comission">
                                    <?php echo esc_html($tx['comission'] ?? '0.00'); ?>
                                </td>
                                <td class="cashback-display"><?php echo esc_html($tx['cashback'] ?? '0.00'); ?></td>
                                <td><?php echo esc_html($tx['click_id'] ?? ''); ?></td>
                                <td><?php echo esc_html($tx['created_at'] ?? ''); ?></td>
                                <td>
                                    <?php if ($is_editable) : ?>
                                        <button class="button button-secondary edit-btn">Редактировать</button>
                                        <button class="button button-primary save-btn" style="display:none;">Сохранить</button>
                                        <button class="button button-default cancel-btn" style="display:none;">Отмена</button>
                                    <?php else : ?>
                                        <span class="description">Финальный статус</span>
                                    <?php endif; ?>
                                    <?php if ($current_tab === 'unregistered') : ?>
                                        <button class="button button-small transfer-btn"
                                                style="margin-top:4px;"
                                                data-transaction-id="<?php echo esc_attr($tx['id']); ?>">
                                            Перенести
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="12">
                                <?php if (!empty($search_query)) : ?>
                                    По запросу &laquo;<?php echo esc_html($search_query); ?>&raquo; транзакции не найдены.
                                <?php else : ?>
                                    Нет транзакций.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            $this->render_pagination(array(
                'total_items'  => $total_items,
                'per_page'     => $this->per_page,
                'current_page' => $current_page,
                'total_pages'  => $total_pages,
                'page_slug'    => 'cashback-transactions',
                'add_args'     => array_filter(array(
                    'tab'     => $current_tab,
                    'status'  => $filter_status,
                    'partner' => $filter_partner,
                    'search'  => $search_query,
                )),
            ));
            ?>
        </div>
        <?php
    }

    public function handle_update_transaction(): void {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['nonce'])),
            'update_transaction_nonce'
        )) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав.' ));
            return;
        }

        global $wpdb;

        $transaction_id = intval($_POST['transaction_id'] ?? 0);
        if ($transaction_id <= 0) {
            wp_send_json_error(array( 'message' => 'Некорректный ID транзакции.' ));
            return;
        }

        $tab        = sanitize_text_field(wp_unslash($_POST['tab'] ?? 'registered'));
        $table_name = ( $tab === 'unregistered' ) ? $this->unregistered_table : $this->registered_table;

        // === Валидация входных данных до начала транзакции ===
        $update_data    = array();
        $update_formats = array();

        if (isset($_POST['order_status'])) {
            $new_status = sanitize_text_field(wp_unslash($_POST['order_status']));
            // 'balance' исключён: перевод в balance происходит только через MySQL Event
            // cashback_ev_confirmed_cashback, который также начисляет available_balance.
            // Ручная установка balance без начисления баланса нарушает целостность данных.
            $allowed_statuses = array( 'waiting', 'completed', 'declined', 'hold' );
            if (!in_array($new_status, $allowed_statuses, true)) {
                wp_send_json_error(array( 'message' => 'Недопустимый статус.' ));
                return;
            }
            $update_data['order_status'] = $new_status;
            $update_formats[]            = '%s';
        }

        if (isset($_POST['sum_order'])) {
            $raw_sum = sanitize_text_field(wp_unslash($_POST['sum_order']));
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw_sum)) {
                wp_send_json_error(array( 'message' => 'Сумма заказа должна быть положительным числом.' ));
                return;
            }
            $update_data['sum_order'] = $raw_sum;
            $update_formats[]         = '%s';
        }

        if (isset($_POST['comission'])) {
            $raw_comission = sanitize_text_field(wp_unslash($_POST['comission']));
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw_comission)) {
                wp_send_json_error(array( 'message' => 'Комиссия должна быть положительным числом.' ));
                return;
            }
            $update_data['comission'] = $raw_comission;
            $update_formats[]         = '%s';
        }

        if (empty($update_data)) {
            wp_send_json_error(array( 'message' => 'Нет данных для обновления.' ));
            return;
        }

        // === Атомарное обновление с FOR UPDATE для защиты от race conditions ===
        $wpdb->query('START TRANSACTION');

        try {
            // Блокируем строку для предотвращения конкурентного обновления (MySQL Event, sync)
            $current = $wpdb->get_row($wpdb->prepare(
                "SELECT id, reference_id, user_id, order_status, sum_order, comission, cashback FROM {$table_name} WHERE id = %d FOR UPDATE",
                $transaction_id
            ), ARRAY_A);

            if (!$current) {
                $wpdb->query('ROLLBACK');
                wp_send_json_error(array( 'message' => 'Транзакция не найдена.' ));
                return;
            }

            if ($current['order_status'] === 'balance') {
                $wpdb->query('ROLLBACK');
                wp_send_json_error(array( 'message' => 'Транзакция с финальным статусом не может быть изменена.' ));
                return;
            }

            $result = $wpdb->update(
                $table_name,
                $update_data,
                array( 'id' => $transaction_id ),
                $update_formats,
                array( '%d' )
            );

            if ($result === false) {
                $db_error = $wpdb->last_error;
                $wpdb->query('ROLLBACK');
                error_log(sprintf('[Cashback Transactions] Update failed for ID %d: %s', $transaction_id, $db_error));
                wp_send_json_error(array( 'message' => 'Ошибка при обновлении транзакции.' ));
                return;
            }

            $wpdb->query('COMMIT');

            // Уведомление об изменении статуса обрабатывается через MySQL триггер → очередь → WP Cron

            // Аудит-лог: фиксируем ручное изменение транзакции
            if (class_exists('Cashback_Encryption')) {
                $changes = array();
                foreach ($update_data as $field => $new_value) {
                    $old_value = $current[ $field ] ?? '';
                    if ((string) $old_value !== (string) $new_value) {
                        $changes[ $field ] = array(
							'old' => $old_value,
							'new' => $new_value,
						);
                    }
                }
                if (!empty($changes)) {
                    Cashback_Encryption::write_audit_log(
                        'transaction_manual_edit',
                        get_current_user_id(),
                        ( $tab === 'unregistered' ) ? 'unregistered_transaction' : 'transaction',
                        $transaction_id,
                        array(
                            'changes' => $changes,
                            'user_id' => $current['user_id'],
                        )
                    );
                }
            }
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log(sprintf('[Cashback Transactions] Exception updating ID %d: %s', $transaction_id, $e->getMessage()));
            wp_send_json_error(array( 'message' => 'Ошибка при обновлении транзакции.' ));
            return;
        }

        // Return fresh data (cashback may have been recalculated by trigger)
        $updated = $wpdb->get_row($wpdb->prepare(
            "SELECT id, reference_id, user_id, order_number, partner, order_status, sum_order, comission, cashback, click_id, created_at
             FROM {$table_name} WHERE id = %d",
            $transaction_id
        ), ARRAY_A);

        wp_send_json_success(array( 'transaction_data' => $updated ));
    }

    public function handle_get_transaction(): void {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['nonce'])),
            'get_transaction_nonce'
        )) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав.' ));
            return;
        }

        global $wpdb;

        $transaction_id = intval($_POST['transaction_id'] ?? 0);
        if ($transaction_id <= 0) {
            wp_send_json_error(array( 'message' => 'Некорректный ID транзакции.' ));
            return;
        }

        $tab        = sanitize_text_field(wp_unslash($_POST['tab'] ?? 'registered'));
        $table_name = ( $tab === 'unregistered' ) ? $this->unregistered_table : $this->registered_table;

        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT id, reference_id, user_id, order_number, partner, order_status, sum_order, comission, cashback, click_id, created_at
             FROM {$table_name} WHERE id = %d",
            $transaction_id
        ), ARRAY_A);

        if (!$data) {
            wp_send_json_error(array( 'message' => 'Транзакция не найдена.' ));
            return;
        }

        wp_send_json_success($data);
    }

    public function handle_transfer_unregistered(): void {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['nonce'])),
            'transfer_unregistered_nonce'
        )) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав.' ));
            return;
        }

        $transaction_id = intval($_POST['transaction_id'] ?? 0);
        if ($transaction_id <= 0) {
            wp_send_json_error(array( 'message' => 'Некорректный ID транзакции.' ));
            return;
        }

        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!is_email($email)) {
            wp_send_json_error(array( 'message' => 'Некорректный email.' ));
            return;
        }

        $wp_user = get_user_by('email', $email);
        if (!$wp_user) {
            wp_send_json_error(array( 'message' => 'Пользователь с таким email не найден.' ));
            return;
        }

        global $wpdb;

        $wpdb->query('START TRANSACTION');

        try {
            // Блокируем исходную строку
            $tx = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->unregistered_table} WHERE id = %d FOR UPDATE",
                $transaction_id
            ), ARRAY_A);

            if (!$tx) {
                $wpdb->query('ROLLBACK');
                wp_send_json_error(array( 'message' => 'Транзакция не найдена.' ));
                return;
            }

            // Проверяем дубль в целевой таблице
            if (!empty($tx['uniq_id'])) {
                $duplicate = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->registered_table} WHERE uniq_id = %s AND partner = %s",
                    $tx['uniq_id'],
                    (string) ( $tx['partner'] ?? '' )
                ));

                if ($duplicate > 0) {
                    // Запись уже есть в зарегистрированных — удаляем «висящую» строку из unregistered
                    $wpdb->delete($this->unregistered_table, array( 'id' => $transaction_id ), array( '%d' ));
                    $wpdb->query('COMMIT');
                    if (class_exists('Cashback_Encryption')) {
                        Cashback_Encryption::write_audit_log(
                            'unregistered_transaction_cleanup_duplicate',
                            get_current_user_id(),
                            'unregistered_transaction',
                            $transaction_id,
                            array(
								'uniq_id' => $tx['uniq_id'],
								'partner' => $tx['partner'],
							)
                        );
                    }
                    wp_send_json_success(array(
                        'message'          => 'Транзакция уже существует в зарегистрированных; запись из незарегистрированных удалена.',
                        'transferred_user' => $email,
                    ));
                    return;
                }
            }

            // INSERT в зарегистрированные (cashback и applied_cashback_rate рассчитает триггер)
            $insert_result = $wpdb->insert(
                $this->registered_table,
                array(
                    'user_id'            => $wp_user->ID,
                    'order_number'       => $tx['order_number'],
                    'offer_id'           => $tx['offer_id'] !== null ? (int) $tx['offer_id'] : null,
                    'offer_name'         => $tx['offer_name'],
                    'order_status'       => $tx['order_status'],
                    'partner'            => $tx['partner'],
                    'sum_order'          => $tx['sum_order'],
                    'comission'          => $tx['comission'],
                    'currency'           => $tx['currency'],
                    'uniq_id'            => $tx['uniq_id'],
                    'api_verified'       => (int) $tx['api_verified'],
                    'action_date'        => $tx['action_date'],
                    'click_time'         => $tx['click_time'],
                    'click_id'           => $tx['click_id'],
                    'website_id'         => $tx['website_id'] !== null ? (int) $tx['website_id'] : null,
                    'action_type'        => $tx['action_type'],
                    'processed_at'       => $tx['processed_at'],
                    'processed_batch_id' => $tx['processed_batch_id'],
                    'idempotency_key'    => $tx['idempotency_key'],
                    'original_cpa_subid' => ( empty($tx['user_id']) || (int) $tx['user_id'] === 0 )
                        ? 'unregistered'
                        : (string) $tx['user_id'],
                    'spam_click'         => $tx['spam_click'],
                    'created_at'         => $tx['created_at'],
                ),
                array(
                    '%d',
					'%s',
					'%d',
					'%s',
					'%s',
					'%s',
                    '%f',
					'%f',
					'%s',
					'%s',
					'%d',
					'%s',
                    '%s',
					'%s',
					'%d',
					'%s',
					'%s',
					'%s',
                    '%s',
					'%s',
					'%d',
					'%s',
                )
            );

            if ($insert_result === false) {
                $db_error = $wpdb->last_error;
                $wpdb->query('ROLLBACK');
                error_log(sprintf('[Cashback Transfer] Insert failed for unreg ID %d: %s', $transaction_id, $db_error));
                wp_send_json_error(array( 'message' => 'Ошибка при переносе транзакции. Подробности в журнале ошибок.' ));
                return;
            }

            // Сохраняем до DELETE/COMMIT — они сбрасывают insert_id в 0
            $new_transaction_id = (int) $wpdb->insert_id;

            // Удаляем из незарегистрированных
            $delete_result = $wpdb->delete(
                $this->unregistered_table,
                array( 'id' => $transaction_id ),
                array( '%d' )
            );

            if ($delete_result === false) {
                $wpdb->query('ROLLBACK');
                wp_send_json_error(array( 'message' => 'Ошибка при удалении исходной транзакции.' ));
                return;
            }

            $wpdb->query('COMMIT');

            // Инвалидация кеша статистики
            $wpdb->query(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_cb_stats_tx_%'
                    OR option_name LIKE '_transient_timeout_cb_stats_tx_%'"
            );
            delete_transient('cb_stats_bal');

            // Аудит-лог
            if (class_exists('Cashback_Encryption')) {
                Cashback_Encryption::write_audit_log(
                    'unregistered_transaction_transferred',
                    get_current_user_id(),
                    'transaction',
                    $new_transaction_id,
                    array(
                        'source_id'    => $transaction_id,
                        'target_user'  => $wp_user->ID,
                        'target_email' => $email,
                        'uniq_id'      => $tx['uniq_id'],
                        'partner'      => $tx['partner'],
                    )
                );
            }

            wp_send_json_success(array(
                'message'          => sprintf('Транзакция перенесена пользователю %s.', $email),
                'transferred_user' => $email,
            ));
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log(sprintf('[Cashback Transfer] Exception for unreg ID %d: %s', $transaction_id, $e->getMessage()));
            wp_send_json_error(array( 'message' => 'Внутренняя ошибка при переносе транзакции.' ));
        }
    }

    private function get_status_label( string $status ): string {
        $labels = array(
            'waiting'   => 'В ожидании',
            'completed' => 'Подтверждена',
            'declined'  => 'Отклонена',
            'hold'      => 'На проверке',
            'balance'   => 'Зачислена на баланс',
        );
        return $labels[ $status ] ?? $status;
    }
}

new Cashback_Transactions_Admin();
