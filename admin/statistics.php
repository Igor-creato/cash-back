<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Statistics_Admin
{
    private static ?self $instance = null;

    private string $transactions_table;
    private string $unregistered_transactions_table;
    private string $payout_requests_table;
    private string $user_balance_table;

    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        global $wpdb;
        $this->transactions_table             = $wpdb->prefix . 'cashback_transactions';
        $this->unregistered_transactions_table = $wpdb->prefix . 'cashback_unregistered_transactions';
        $this->payout_requests_table          = $wpdb->prefix . 'cashback_payout_requests';
        $this->user_balance_table             = $wpdb->prefix . 'cashback_user_balance';

        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Подключение скриптов и стилей для страницы статистики
     */
    public function enqueue_admin_scripts(string $hook): void
    {
        $allowed_hooks = [
            'toplevel_page_cashback-overview',
            'admin_page_cashback-overview'
        ];

        $is_overview_page = in_array($hook, $allowed_hooks, true) ||
            (isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-overview');

        if (!$is_overview_page) {
            return;
        }

        wp_enqueue_style(
            'cashback-admin-css',
            plugins_url('../assets/css/admin.css', __FILE__),
            [],
            '1.1.0'
        );

        wp_enqueue_script(
            'cashback-admin-statistics',
            plugins_url('../assets/js/admin-statistics.js', __FILE__),
            ['jquery'],
            '1.0.0',
            true
        );
    }

    /**
     * Рендер страницы статистики
     */
    public function render_overview_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }

        // Фильтры по дате
        $filter_date_from = sanitize_text_field(wp_unslash($_GET['date_from'] ?? ''));
        $filter_date_to   = sanitize_text_field(wp_unslash($_GET['date_to'] ?? ''));

        if (!empty($filter_date_from) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_from)) {
            $filter_date_from = '';
        }
        if (!empty($filter_date_from)) {
            [$y, $m, $d] = array_map('intval', explode('-', $filter_date_from));
            if (!checkdate($m, $d, $y)) {
                $filter_date_from = '';
            }
        }
        if (!empty($filter_date_to) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_to)) {
            $filter_date_to = '';
        }
        if (!empty($filter_date_to)) {
            [$y, $m, $d] = array_map('intval', explode('-', $filter_date_to));
            if (!checkdate($m, $d, $y)) {
                $filter_date_to = '';
            }
        }

        // По умолчанию — текущий месяц (с 1-го числа до сегодня)
        $is_default_period = empty($filter_date_from) && empty($filter_date_to);
        if ($is_default_period) {
            $filter_date_from = gmdate('Y-m-01');
            $filter_date_to   = gmdate('Y-m-d');
        }

        $has_date_filter = true;

        // Получаем данные
        $tx_stats     = $this->get_transaction_stats($filter_date_from, $filter_date_to);
        $payout_stats = $this->get_payout_stats($filter_date_from, $filter_date_to);
        $balance_stats = $this->get_balance_totals();

        $total_commission = (float) $tx_stats['total_commission'];
        $total_cashback   = (float) $tx_stats['total_cashback'];
        $service_profit   = $total_commission - $total_cashback;

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html__('Статистика кэшбэка', 'cashback-plugin'); ?></h1>
            <hr class="wp-header-end">

            <!-- Фильтры по дате -->
            <div class="tablenav top">
                <div class="alignleft actions">
                    <label for="filter-date-from"><?php echo esc_html__('Дата от:', 'cashback-plugin'); ?></label>
                    <input type="date" id="filter-date-from"
                           value="<?php echo esc_attr($filter_date_from); ?>" />
                    <label for="filter-date-to"><?php echo esc_html__('Дата до:', 'cashback-plugin'); ?></label>
                    <input type="date" id="filter-date-to"
                           value="<?php echo esc_attr($filter_date_to); ?>" />
                    <button type="button" id="stats-filter-submit" class="button action">
                        <?php echo esc_html__('Фильтровать', 'cashback-plugin'); ?>
                    </button>
                    <button type="button" id="stats-filter-reset" class="button action">
                        <?php echo esc_html__('Текущий месяц', 'cashback-plugin'); ?>
                    </button>
                </div>
                <br class="clear">
            </div>

            <p class="description">
                <?php
                if (!empty($filter_date_from) && !empty($filter_date_to)) {
                    printf(
                        esc_html__('Данные за период: %s — %s', 'cashback-plugin'),
                        esc_html($filter_date_from),
                        esc_html($filter_date_to)
                    );
                } elseif (!empty($filter_date_from)) {
                    printf(
                        esc_html__('Данные с %s', 'cashback-plugin'),
                        esc_html($filter_date_from)
                    );
                } else {
                    printf(
                        esc_html__('Данные до %s', 'cashback-plugin'),
                        esc_html($filter_date_to)
                    );
                }
                ?>
            </p>

            <!-- Карточки KPI -->
            <div class="cashback-stats-cards">
                <div class="cashback-stat-card card-commission">
                    <div class="stat-label"><?php echo esc_html__('Общая комиссия от партнеров', 'cashback-plugin'); ?></div>
                    <div class="stat-value"><?php echo esc_html($this->format_currency($total_commission)); ?></div>
                </div>
                <div class="cashback-stat-card card-cashback">
                    <div class="stat-label"><?php echo esc_html__('Общий кэшбэк пользователям', 'cashback-plugin'); ?></div>
                    <div class="stat-value"><?php echo esc_html($this->format_currency($total_cashback)); ?></div>
                </div>
                <div class="cashback-stat-card card-profit">
                    <div class="stat-label"><?php echo esc_html__('Прибыль сервиса', 'cashback-plugin'); ?></div>
                    <div class="stat-value <?php echo $service_profit >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo esc_html($this->format_currency($service_profit)); ?>
                    </div>
                </div>
            </div>

            <!-- Транзакции по статусам -->
            <div class="cashback-stats-section">
                <div class="postbox">
                    <h2 class="hndle"><span><?php echo esc_html__('Транзакции по статусам', 'cashback-plugin'); ?></span></h2>
                    <div class="inside">
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Статус', 'cashback-plugin'); ?></th>
                                    <th><?php echo esc_html__('Количество', 'cashback-plugin'); ?></th>
                                    <th><?php echo esc_html__('Комиссия', 'cashback-plugin'); ?></th>
                                    <th><?php echo esc_html__('Кэшбэк', 'cashback-plugin'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $status_labels = [
                                    'waiting'   => __('В ожидании', 'cashback-plugin'),
                                    'completed' => __('Подтверждён', 'cashback-plugin'),
                                    'hold'      => __('На проверке', 'cashback-plugin'),
                                    'declined'  => __('Отклонён', 'cashback-plugin'),
                                    'balance'   => __('На балансе', 'cashback-plugin'),
                                ];
                                foreach ($status_labels as $status_key => $status_label):
                                    $count_key      = 'count_' . $status_key;
                                    $commission_key  = 'commission_' . $status_key;
                                    $cashback_key    = 'cashback_' . $status_key;
                                ?>
                                <tr>
                                    <td><?php echo esc_html($status_label); ?></td>
                                    <td><?php echo esc_html(number_format_i18n((int) ($tx_stats[$count_key] ?? 0))); ?></td>
                                    <td><?php echo esc_html($this->format_currency((float) ($tx_stats[$commission_key] ?? 0))); ?></td>
                                    <td><?php echo esc_html($this->format_currency((float) ($tx_stats[$cashback_key] ?? 0))); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th><?php echo esc_html__('Итого', 'cashback-plugin'); ?></th>
                                    <th><?php echo esc_html(number_format_i18n((int) $tx_stats['total_count'])); ?></th>
                                    <th><?php echo esc_html($this->format_currency($total_commission)); ?></th>
                                    <th><?php echo esc_html($this->format_currency($total_cashback)); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Статистика выплат -->
            <div class="cashback-stats-section">
                <div class="postbox">
                    <h2 class="hndle"><span><?php echo esc_html__('Статистика выплат', 'cashback-plugin'); ?></span></h2>
                    <div class="inside">
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Статус', 'cashback-plugin'); ?></th>
                                    <th><?php echo esc_html__('Количество', 'cashback-plugin'); ?></th>
                                    <th><?php echo esc_html__('Сумма', 'cashback-plugin'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $payout_labels = [
                                    'waiting'     => __('Ожидает обработки', 'cashback-plugin'),
                                    'processing'  => __('В обработке', 'cashback-plugin'),
                                    'paid'        => __('Выплачен', 'cashback-plugin'),
                                    'failed'      => __('Возврат в баланс', 'cashback-plugin'),
                                    'declined'    => __('Заморожена', 'cashback-plugin'),
                                    'needs_retry' => __('Требует повтора', 'cashback-plugin'),
                                ];
                                foreach ($payout_labels as $p_key => $p_label):
                                    $amount_key = $p_key . '_total';
                                    $count_key  = $p_key . '_count';
                                ?>
                                <tr>
                                    <td><?php echo esc_html($p_label); ?></td>
                                    <td><?php echo esc_html(number_format_i18n((int) ($payout_stats[$count_key] ?? 0))); ?></td>
                                    <td><?php echo esc_html($this->format_currency((float) ($payout_stats[$amount_key] ?? 0))); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th><?php echo esc_html__('Итого', 'cashback-plugin'); ?></th>
                                    <th><?php echo esc_html(number_format_i18n((int) $payout_stats['total_requests'])); ?></th>
                                    <th><?php echo esc_html($this->format_currency((float) $payout_stats['grand_total'])); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Текущие балансы пользователей -->
            <div class="cashback-stats-section">
                <div class="postbox">
                    <h2 class="hndle"><span><?php echo esc_html__('Текущие балансы пользователей', 'cashback-plugin'); ?></span></h2>
                    <div class="inside">
                        <p class="description"><?php echo esc_html__('Агрегированные балансы всех пользователей (без фильтра по дате).', 'cashback-plugin'); ?></p>
                        <table class="widefat striped">
                            <tbody>
                                <tr>
                                    <th><?php echo esc_html__('Доступные средства', 'cashback-plugin'); ?></th>
                                    <td><?php echo esc_html($this->format_currency((float) $balance_stats['total_available'])); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('В ожидании выплаты', 'cashback-plugin'); ?></th>
                                    <td><?php echo esc_html($this->format_currency((float) $balance_stats['total_pending'])); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Выплачено', 'cashback-plugin'); ?></th>
                                    <td><?php echo esc_html($this->format_currency((float) $balance_stats['total_paid'])); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Заморожено', 'cashback-plugin'); ?></th>
                                    <td><?php echo esc_html($this->format_currency((float) $balance_stats['total_frozen'])); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Всего пользователей', 'cashback-plugin'); ?></th>
                                    <td><?php echo esc_html(number_format_i18n((int) $balance_stats['total_users'])); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    /**
     * Статистика по транзакциям
     *
     * @param string $date_from Дата начала (YYYY-MM-DD)
     * @param string $date_to   Дата окончания (YYYY-MM-DD)
     * @return array<string, string>
     */
    private function get_transaction_stats(string $date_from, string $date_to): array
    {
        global $wpdb;

        $cache_key = 'cb_stats_tx_' . md5($date_from . '|' . $date_to);
        $cached    = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $where_conditions = [];
        $where_params     = [];

        if (!empty($date_from)) {
            $where_conditions[] = 'created_at >= %s';
            $where_params[]     = $date_from . ' 00:00:00';
        }
        if (!empty($date_to)) {
            $where_conditions[] = 'created_at <= %s';
            $where_params[]     = $date_to . ' 23:59:59';
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }

        $sql = "SELECT
            COALESCE(SUM(CASE WHEN order_status NOT IN ('declined', 'hold') THEN comission ELSE 0 END), 0) AS total_commission,
            COALESCE(SUM(CASE WHEN order_status NOT IN ('declined', 'hold') THEN cashback ELSE 0 END), 0) AS total_cashback,
            COALESCE(SUM(CASE WHEN order_status NOT IN ('declined', 'hold') THEN 1 ELSE 0 END), 0) AS total_count,
            COALESCE(SUM(CASE WHEN order_status = 'waiting' THEN 1 ELSE 0 END), 0) AS count_waiting,
            COALESCE(SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END), 0) AS count_completed,
            COALESCE(SUM(CASE WHEN order_status = 'hold' THEN 1 ELSE 0 END), 0) AS count_hold,
            COALESCE(SUM(CASE WHEN order_status = 'declined' THEN 1 ELSE 0 END), 0) AS count_declined,
            COALESCE(SUM(CASE WHEN order_status = 'balance' THEN 1 ELSE 0 END), 0) AS count_balance,
            COALESCE(SUM(CASE WHEN order_status = 'waiting' THEN comission ELSE 0 END), 0) AS commission_waiting,
            COALESCE(SUM(CASE WHEN order_status = 'completed' THEN comission ELSE 0 END), 0) AS commission_completed,
            COALESCE(SUM(CASE WHEN order_status = 'hold' THEN comission ELSE 0 END), 0) AS commission_hold,
            COALESCE(SUM(CASE WHEN order_status = 'declined' THEN comission ELSE 0 END), 0) AS commission_declined,
            COALESCE(SUM(CASE WHEN order_status = 'balance' THEN comission ELSE 0 END), 0) AS commission_balance,
            COALESCE(SUM(CASE WHEN order_status = 'waiting' THEN cashback ELSE 0 END), 0) AS cashback_waiting,
            COALESCE(SUM(CASE WHEN order_status = 'completed' THEN cashback ELSE 0 END), 0) AS cashback_completed,
            COALESCE(SUM(CASE WHEN order_status = 'hold' THEN cashback ELSE 0 END), 0) AS cashback_hold,
            COALESCE(SUM(CASE WHEN order_status = 'declined' THEN cashback ELSE 0 END), 0) AS cashback_declined,
            COALESCE(SUM(CASE WHEN order_status = 'balance' THEN cashback ELSE 0 END), 0) AS cashback_balance
        FROM (
            SELECT order_status, comission, cashback FROM {$this->transactions_table} {$where_clause}
            UNION ALL
            SELECT order_status, comission, cashback FROM {$this->unregistered_transactions_table} {$where_clause}
        ) AS combined";

        // WHERE params передаются дважды — для каждой части UNION ALL
        $merged_params = !empty($where_params) ? array_merge($where_params, $where_params) : [];

        if (!empty($merged_params)) {
            $result = $wpdb->get_row($wpdb->prepare($sql, $merged_params), ARRAY_A);
        } else {
            $result = $wpdb->get_row($sql, ARRAY_A);
        }

        if (!$result) {
            $result = [
                'total_commission' => '0', 'total_cashback' => '0', 'total_count' => '0',
                'count_waiting' => '0', 'count_completed' => '0', 'count_hold' => '0',
                'count_declined' => '0', 'count_balance' => '0',
                'commission_waiting' => '0', 'commission_completed' => '0', 'commission_hold' => '0',
                'commission_declined' => '0', 'commission_balance' => '0',
                'cashback_waiting' => '0', 'cashback_completed' => '0', 'cashback_hold' => '0',
                'cashback_declined' => '0', 'cashback_balance' => '0',
            ];
        }

        set_transient($cache_key, $result, HOUR_IN_SECONDS);

        return $result;
    }

    /**
     * Статистика по выплатам
     *
     * @param string $date_from Дата начала (YYYY-MM-DD)
     * @param string $date_to   Дата окончания (YYYY-MM-DD)
     * @return array<string, string>
     */
    private function get_payout_stats(string $date_from, string $date_to): array
    {
        global $wpdb;

        $cache_key = 'cb_stats_py_' . md5($date_from . '|' . $date_to);
        $cached    = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $where_conditions = [];
        $where_params     = [];

        if (!empty($date_from)) {
            $where_conditions[] = 'created_at >= %s';
            $where_params[]     = $date_from . ' 00:00:00';
        }
        if (!empty($date_to)) {
            $where_conditions[] = 'created_at <= %s';
            $where_params[]     = $date_to . ' 23:59:59';
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }

        $sql = "SELECT
            COUNT(*) AS total_requests,
            COALESCE(SUM(total_amount), 0) AS grand_total,
            COALESCE(SUM(CASE WHEN status = 'waiting' THEN total_amount ELSE 0 END), 0) AS waiting_total,
            COALESCE(SUM(CASE WHEN status = 'processing' THEN total_amount ELSE 0 END), 0) AS processing_total,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) AS paid_total,
            COALESCE(SUM(CASE WHEN status = 'failed' THEN total_amount ELSE 0 END), 0) AS failed_total,
            COALESCE(SUM(CASE WHEN status = 'declined' THEN total_amount ELSE 0 END), 0) AS declined_total,
            COALESCE(SUM(CASE WHEN status = 'needs_retry' THEN total_amount ELSE 0 END), 0) AS needs_retry_total,
            COALESCE(SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END), 0) AS waiting_count,
            COALESCE(SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END), 0) AS processing_count,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_count,
            COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_count,
            COALESCE(SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END), 0) AS declined_count,
            COALESCE(SUM(CASE WHEN status = 'needs_retry' THEN 1 ELSE 0 END), 0) AS needs_retry_count
        FROM {$this->payout_requests_table}
        {$where_clause}";

        if (!empty($where_params)) {
            $result = $wpdb->get_row($wpdb->prepare($sql, $where_params), ARRAY_A);
        } else {
            $result = $wpdb->get_row($sql, ARRAY_A);
        }

        if (!$result) {
            $result = [
                'total_requests' => '0', 'grand_total' => '0',
                'waiting_total' => '0', 'processing_total' => '0', 'paid_total' => '0',
                'failed_total' => '0', 'declined_total' => '0', 'needs_retry_total' => '0',
                'waiting_count' => '0', 'processing_count' => '0', 'paid_count' => '0',
                'failed_count' => '0', 'declined_count' => '0', 'needs_retry_count' => '0',
            ];
        }

        set_transient($cache_key, $result, HOUR_IN_SECONDS);

        return $result;
    }

    /**
     * Агрегированные балансы пользователей (текущее состояние, без фильтра по дате)
     *
     * @return array<string, string>
     */
    private function get_balance_totals(): array
    {
        global $wpdb;

        $cached = get_transient('cb_stats_bal');
        if ($cached !== false) {
            return $cached;
        }

        $result = $wpdb->get_row(
            "SELECT
                COALESCE(SUM(available_balance), 0) AS total_available,
                COALESCE(SUM(pending_balance), 0) AS total_pending,
                COALESCE(SUM(paid_balance), 0) AS total_paid,
                COALESCE(SUM(frozen_balance), 0) AS total_frozen,
                COUNT(*) AS total_users
            FROM {$this->user_balance_table}",
            ARRAY_A
        );

        if (!$result) {
            $result = [
                'total_available' => '0',
                'total_pending'   => '0',
                'total_paid'      => '0',
                'total_frozen'    => '0',
                'total_users'     => '0',
            ];
        }

        set_transient('cb_stats_bal', $result, 15 * MINUTE_IN_SECONDS);

        return $result;
    }

    /**
     * Форматирование суммы как валюта
     *
     * @param float $amount Сумма
     * @return string Отформатированная строка
     */
    private function format_currency(float $amount): string
    {
        return number_format($amount, 2, '.', ' ') . ' ₽';
    }
}

Cashback_Statistics_Admin::get_instance();
