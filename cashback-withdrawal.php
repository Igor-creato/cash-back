<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Class CashbackWithdrawal
 *
 * Handles the cashback withdrawal functionality in WooCommerce My Account.
 */
class CashbackWithdrawal {


    /**
     * Instance of the class (singleton pattern)
     */
    private static $instance = null;

    /**
     * Get instance of the class
     *
     * @return CashbackWithdrawal
     */
    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('init', array( $this, 'register_endpoint' ));
        add_filter('woocommerce_account_menu_items', array( $this, 'add_menu_item' ));
        add_action('woocommerce_account_cashback-withdrawal_endpoint', array( $this, 'endpoint_content' ));
        add_action('wp_enqueue_scripts', array( $this, 'enqueue_styles' ));
        // AJAX обработчики для вывода кэшбэка (только для авторизованных пользователей)
        add_action('wp_ajax_process_cashback_withdrawal', array( $this, 'process_cashback_withdrawal' ));
        // AJAX обработчик для обновления баланса (только для авторизованных пользователей)
        add_action('wp_ajax_get_user_balance', array( $this, 'get_user_balance_ajax' ));
        // AJAX обработчик для сохранения настроек вывода (только для авторизованных пользователей)
        add_action('wp_ajax_save_payout_settings', array( $this, 'save_payout_settings' ));
        // AJAX обработчик для поиска банков (только для авторизованных пользователей)
        add_action('wp_ajax_search_banks', array( $this, 'search_banks_ajax' ));
    }

    /**
     * Register the custom endpoint
     */
    public function register_endpoint() {
        add_rewrite_endpoint('cashback-withdrawal', EP_ROOT | EP_PAGES);
        add_filter('query_vars', array( $this, 'add_query_vars' ));
    }

    /**
     * Add query vars
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'cashback-withdrawal';
        return $vars;
    }

    /**
     * Add menu item to My Account navigation
     *
     * @param array $items
     * @return array
     */
    public function add_menu_item( $items ) {
        // Insert after 'orders'
        $new_items                        = array();
        $new_items['cashback-withdrawal'] = __('Вывод кэшбэка', 'cashback-plugin');

        return $this->insert_after_helper($items, $new_items, 'orders');
    }

    /**
     * Helper function to insert items after a specific key
     *
     * @param array $items
     * @param array $new_items
     * @param string $after
     * @return array
     */
    private function insert_after_helper( $items, $new_items, $after ) {
        $position = array_search($after, array_keys($items), true);
        if ($position === false) {
            return $items + $new_items;
        }
        ++$position;
        $array  = array_slice($items, 0, $position, true);
        $array += $new_items;
        $array += array_slice($items, $position, count($items) - $position, true);
        return $array;
    }

    /**
     * Get all user balances in a single query.
     *
     * @param int $user_id
     * @return array{available: float, pending: float, paid: float}
     */
    private function get_all_balances( int $user_id ): array {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_user_balance';
        $row        = $wpdb->get_row($wpdb->prepare(
            'SELECT available_balance, pending_balance, paid_balance FROM %i WHERE user_id = %d',
            $table_name,
            $user_id
        ));

        return array(
            'available' => (float) ( $row->available_balance ?? 0.0 ),
            'pending'   => (float) ( $row->pending_balance ?? 0.0 ),
            'paid'      => (float) ( $row->paid_balance ?? 0.0 ),
        );
    }

    /**
     * Get user's available balance
     *
     * @param int $user_id
     * @return float
     */
    private function get_available_balance( int $user_id ): float {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_user_balance';
        $balance    = $wpdb->get_var($wpdb->prepare(
            'SELECT available_balance FROM %i WHERE user_id = %d',
            $table_name,
            $user_id
        ));

        return (float) ( $balance ?: 0.0 );
    }

    /**
     * Get all active payout methods
     *
     * @return array
     */
    private function get_payout_methods(): array {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_payout_methods';

        // Пробуем с bank_required, если колонка ещё не создана — fallback без неё
        $wpdb->suppress_errors(true);
        $methods = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, name, slug, bank_required FROM %i WHERE is_active = %d ORDER BY sort_order ASC, name ASC',
                $table_name,
                1
            ),
            ARRAY_A
        );
        $wpdb->suppress_errors(false);

        if ($methods === null) {
            $methods = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id, name, slug FROM %i WHERE is_active = %d ORDER BY sort_order ASC, name ASC',
                    $table_name,
                    1
                ),
                ARRAY_A
            );
        }

        return $methods ?: array();
    }

    /**
     * Get first 10 active banks for initial dropdown display
     *
     * @return array
     */
    private function get_banks(): array {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_banks';
        $banks      = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, name FROM %i WHERE is_active = %d ORDER BY sort_order ASC, name ASC LIMIT 10',
                $table_name,
                1
            ),
            ARRAY_A
        );

        return $banks ?: array();
    }

    /**
     * Check if user has saved payout settings
     *
     * @param int $user_id
     * @return bool
     */
    private function has_payout_settings( int $user_id ): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_user_profile';
        $result     = $wpdb->get_row($wpdb->prepare(
            'SELECT payout_method_id, payout_account, encrypted_details, bank_id FROM %i WHERE user_id = %d',
            $table_name,
            $user_id
        ), ARRAY_A);

        if (!$result) {
            return false;
        }

        // Реквизиты считаются заполненными если есть зашифрованные данные или plaintext
        $has_account = !empty($result['encrypted_details']) || !empty($result['payout_account']);

        $method_id   = (int) $result['payout_method_id'];
        $bank_needed = $method_id > 0 ? $this->is_bank_required_for_method($method_id) : true;

        return !empty($result['payout_method_id']) &&
            $has_account &&
            ( !$bank_needed || !empty($result['bank_id']) );
    }

    /**
     * Get user's payout method ID
     *
     * @param int $user_id
     * @return int
     */
    private function get_user_payout_method_id( int $user_id ): int {
        global $wpdb;

        $table_name       = $wpdb->prefix . 'cashback_user_profile';
        $payout_method_id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT payout_method_id FROM %i WHERE user_id = %d',
                $table_name,
                $user_id
            )
        );

        return $payout_method_id ? intval($payout_method_id) : 0;
    }

    /**
     * Get payout method name by ID
     *
     * @param int $method_id
     * @return string
     */
    private function get_payout_method_name( int $method_id ): string {
        global $wpdb;

        $table_name  = $wpdb->prefix . 'cashback_payout_methods';
        $method_name = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT name FROM %i WHERE id = %d',
                $table_name,
                $method_id
            )
        );

        return $method_name ?: '';
    }

    /**
     * Get bank name by ID
     *
     * @param int $bank_id
     * @return string
     */
    private function get_bank_name( int $bank_id ): string {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_banks';
        $bank_name  = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT name FROM %i WHERE id = %d',
                $table_name,
                $bank_id
            )
        );

        return $bank_name ?: '';
    }

    /**
     * Get user's bank ID
     *
     * @param int $user_id
     * @return int
     */
    private function get_user_bank_id( int $user_id ): int {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_user_profile';
        $bank_id    = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT bank_id FROM %i WHERE user_id = %d',
                $table_name,
                $user_id
            )
        );

        return $bank_id ? intval($bank_id) : 0;
    }

    /**
     * Get minimum payout amount for user
     *
     * @param int $user_id
     * @return float
     */
    private function get_min_payout_amount( int $user_id ): float {
        global $wpdb;

        $table_name        = $wpdb->prefix . 'cashback_user_profile';
        $min_payout_amount = $wpdb->get_var($wpdb->prepare(
            'SELECT min_payout_amount FROM %i WHERE user_id = %d',
            $table_name,
            $user_id
        ));

        return (float) ( $min_payout_amount ?: 100.00 ); // Default to 100.00 if not set
    }

    /**
     * Get payout method for user
     *
     * @param int $user_id
     * @return string|null
     */
    private function get_payout_method( int $user_id ): ?string {
        global $wpdb;

        $table_profile = $wpdb->prefix . 'cashback_user_profile';
        $table_methods = $wpdb->prefix . 'cashback_payout_methods';

        $payout_method = $wpdb->get_var($wpdb->prepare(
            'SELECT pm.slug
             FROM %i up
             LEFT JOIN %i pm ON up.payout_method_id = pm.id
             WHERE up.user_id = %d',
            $table_profile,
            $table_methods,
            $user_id
        ));

        return $payout_method;
    }

    /**
     * Get bank info for user
     *
     * @param int $user_id
     * @return array|null Array containing bank id, code and name
     */
    private function get_user_bank_info( int $user_id ): ?array {
        global $wpdb;

        $table_profile = $wpdb->prefix . 'cashback_user_profile';
        $table_banks   = $wpdb->prefix . 'cashback_banks';

        $bank_info = $wpdb->get_row($wpdb->prepare(
            'SELECT b.id, b.bank_code, b.name
             FROM %i up
             LEFT JOIN %i b ON up.bank_id = b.id AND b.is_active = 1
             WHERE up.user_id = %d',
            $table_profile,
            $table_banks,
            $user_id
        ), ARRAY_A);

        return $bank_info ?: null;
    }

    /**
     * Get payout account for user
     *
     * @param int $user_id
     * @return string|null
     */
    private function get_payout_account( int $user_id ): ?string {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_user_profile';
        $row        = $wpdb->get_row($wpdb->prepare(
            'SELECT payout_account, encrypted_details FROM %i WHERE user_id = %d',
            $table_name,
            $user_id
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        // Fail-closed: если есть ciphertext — он ДОЛЖЕН быть расшифрован.
        // При отсутствии ключа или при сбое decrypt возвращаем null и НЕ
        // подставляем plaintext-fallback — это могло бы вернуть старые данные,
        // оставшиеся в колонке payout_account до миграции. См. ADR Группа 4 (F-1-001).
        if (!empty($row['encrypted_details'])) {
            if (!class_exists('Cashback_Encryption') || !Cashback_Encryption::is_configured()) {
                $this->log_encryption_unavailable('get_payout_account', $user_id);
                return null;
            }

            try {
                $decrypted = Cashback_Encryption::decrypt_details($row['encrypted_details']);
                return $decrypted['account'] ?? null;
            } catch (\Exception $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback: Failed to decrypt payout account for user ' . $user_id . ': ' . $e->getMessage());
                return null;
            }
        }

        // Legacy: для записей до миграции шифрования, где encrypted_details пусто,
        // возвращаем plaintext payout_account как есть.
        return $row['payout_account'] ?: null;
    }

    /**
     * Логирует факт недоступности шифрования при чтении/записи реквизитов.
     * Пишет в error_log и, если доступен, в аудит-лог шифрования.
     */
    private function log_encryption_unavailable( string $scope, int $user_id ): void {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log(sprintf(
            'Cashback: encryption unavailable at %s for user %d (CB_ENCRYPTION_KEY missing or invalid)',
            $scope,
            $user_id
        ));

        if (class_exists('Cashback_Encryption') && method_exists('Cashback_Encryption', 'write_audit_log')) {
            try {
                Cashback_Encryption::write_audit_log('encryption_unavailable', array(
                    'scope'   => $scope,
                    'user_id' => $user_id,
                ));
            } catch (\Throwable $e) {
                // Аудит-лог не должен ломать основной поток; проглатываем.
            }
        }
    }

    /**
     * Получает маскированный номер счёта из профиля пользователя.
     * Fallback на маскирование plaintext payout_account.
     */
    private function get_user_masked_account( int $user_id, ?string $fallback_payout_account = null ): string {
        global $wpdb;

        $table_name     = $wpdb->prefix . 'cashback_user_profile';
        $masked_details = $wpdb->get_var($wpdb->prepare(
            'SELECT masked_details FROM %i WHERE user_id = %d',
            $table_name,
            $user_id
        ));

        if (class_exists('Cashback_Encryption')) {
            return Cashback_Encryption::get_masked_account($masked_details, $fallback_payout_account);
        }

        return $fallback_payout_account ?: '';
    }

    /**
     * Получает зашифрованные данные профиля для копирования в заявку
     *
     * @return array{encrypted_details: string|null, masked_details: string|null}
     */
    private function get_user_encryption_data( int $user_id ): array {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_user_profile';
        $row        = $wpdb->get_row($wpdb->prepare(
            'SELECT encrypted_details, masked_details FROM %i WHERE user_id = %d',
            $table_name,
            $user_id
        ), ARRAY_A);

        return array(
            'encrypted_details' => $row['encrypted_details'] ?? null,
            'masked_details'    => $row['masked_details'] ?? null,
        );
    }

    /**
     * Get payout method label for display
     *
     * @param string $method
     * @return string
     */
    private function get_payout_method_label( string $method ): string {
        global $wpdb;

        // Получаем название способа вывода из базы данных
        $table_name  = $wpdb->prefix . 'cashback_payout_methods';
        $method_name = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT name FROM %i WHERE slug = %s',
                $table_name,
                $method
            )
        );

        if ($method_name) {
            return $method_name;
        }

        // Если не найдено в базе, используем старую логику
        $labels = array(
            'sbp'      => __('Система быстрых платежей (СБП)', 'cashback-plugin'),
            'mir'      => __('Карта МИР', 'cashback-plugin'),
            'yoomoney' => __('ЮMoney', 'cashback-plugin'),
        );

        return isset($labels[ $method ]) ? $labels[ $method ] : ucfirst($method);
    }

    /**
     * Display content for the endpoint
     */
    public function endpoint_content() {
        $user_id = get_current_user_id();

        // Проверка статуса "banned"
        if ($user_id && Cashback_User_Status::is_user_banned($user_id)) {
            $ban_info = Cashback_User_Status::get_ban_info($user_id);
            echo '<div class="cashback-withdrawal-container">';
            echo '<h2>' . esc_html__('Вывод кэшбэка', 'cashback-plugin') . '</h2>';
            echo '<div class="woocommerce-message woocommerce-error" role="alert">';
            echo esc_html(Cashback_User_Status::get_banned_message($ban_info));
            echo '</div>';
            echo '</div>';
            return;
        }

        if (!$user_id) {
            // Вместо вывода сообщения об ошибке, просто выходим
            // Ошибка авторизации будет обрабатываться через AJAX
            echo '<div class="cashback-withdrawal-container">';
            echo '<h2>' . esc_html__('Вывод кэшбэка', 'cashback-plugin') . '</h2>';
            echo '<div id="withdrawal-messages"></div>';
            echo '<div id="cashback-content">';
            echo '<div class="balance-display">';
            echo '<p>' . esc_html__('Доступный баланс:', 'cashback-plugin') . ' <span id="cashback-balance-amount" class="balance-amount">0</span></p>';
            echo '</div>';
            echo '<p>' . esc_html__('Минимальная сумма выплаты:', 'cashback-plugin') . ' <span class="min-payout-amount">0</span></p>';
            echo '<div class="error-message">' . esc_html__('Вы должны быть авторизованы для просмотра этой страницы.', 'cashback-plugin') . '</div>';
            echo '</div>';
            echo '</div>';
            echo '<div class="cashback-withdrawal-form">';
            echo '<form id="withdrawal-form">';
            echo '<p class="form-row">';
            echo '<label for="withdrawal-amount">' . esc_html__('Сумма вывода', 'cashback-plugin') . ' <span class="required">*</span></label>';
            echo '<input type="number" class="input-text" name="withdrawal_amount" id="withdrawal-amount" placeholder="' . esc_attr__('Введите сумму', 'cashback-plugin') . '" value="" min="0" max="0" step="0.01" disabled/>';
            echo '</p>';
            echo '<p class="form-row">';
            echo '<button type="submit" class="woocommerce-Button button" id="withdrawal-submit" name="withdrawal_submit" value="' . esc_attr__('Вывести', 'cashback-plugin') . '" disabled>' . esc_html__('Вывести', 'cashback-plugin') . '</button>';
            echo '</p>';
            echo '<div id="withdrawal-messages"></div>';
            echo '</form>';
            echo '</div>';
            return;
        }

        $balances          = $this->get_all_balances($user_id);
        $balance           = $balances['available'];
        $pending_balance   = $balances['pending'];
        $paid_balance      = $balances['paid'];
        $min_payout_amount = $this->get_min_payout_amount($user_id);

        // Получаем информацию о способе вывода и номере счета
        $payout_method_id = $this->get_user_payout_method_id($user_id);
        $payout_account   = $this->get_payout_account($user_id);
        $masked_account   = $this->get_user_masked_account($user_id, $payout_account);
        $bank_id          = $this->get_user_bank_id($user_id);

        // Проверяем, есть ли у пользователя сохраненные настройки
        $has_settings = $this->has_payout_settings($user_id);

        // Проверяем активность сохраненных платежных данных
        $settings_inactive       = false;
        $payout_method_is_active = true;
        $bank_is_active          = true;

        if ($has_settings) {
            $payout_method_is_active = $this->is_payout_method_active($payout_method_id);
            $bank_required_flag      = $this->is_bank_required_for_method($payout_method_id);
            $bank_is_active          = ( $bank_required_flag && $bank_id > 0 ) ? $this->is_bank_active($bank_id) : true;

            if (!$payout_method_is_active || !$bank_is_active) {
                $settings_inactive = true;
            }
        }

        // Получаем доступные способы вывода и банки
        $payout_methods = $this->get_payout_methods();
        $banks          = $this->get_banks();

        echo '<div class="cashback-withdrawal-container">';
        echo '<h2>' . esc_html__('Вывод кэшбэка', 'cashback-plugin') . '</h2>';

        // Навигация вкладок
        echo '<div class="cashback-tabs">';
        echo '<button type="button" class="cashback-tab active" data-tab="tab-withdrawal">' . esc_html__('Вывод кэшбэка', 'cashback-plugin') . '</button>';
        echo '<button type="button" class="cashback-tab" data-tab="tab-settings">' . esc_html__('Настройки вывода', 'cashback-plugin') . '</button>';
        echo '</div>';

        // === ВКЛАДКА 1: Вывод кэшбэка ===
        echo '<div class="cashback-tab-content active" id="tab-withdrawal">';

        // Три карточки баланса
        echo '<div class="balance-info-grid">';

        echo '<div class="balance-info-card">';
        echo '<span class="balance-info-label">' . esc_html__('Доступный баланс', 'cashback-plugin') . '</span>';
        echo '<span id="cashback-balance-amount" class="balance-info-value ' . esc_attr( $balance > 0 ? 'balance-green' : 'balance-gray' ) . '">' . wp_kses_post( wc_price($balance) ) . '</span>';
        echo '</div>';

        echo '<div class="balance-info-card">';
        echo '<span class="balance-info-label">' . esc_html__('В обработке', 'cashback-plugin') . '</span>';
        echo '<span id="cashback-pending-amount" class="balance-info-value ' . esc_attr( $pending_balance > 0 ? 'balance-pending' : 'balance-gray' ) . '">' . wp_kses_post( wc_price($pending_balance) ) . '</span>';
        echo '</div>';

        echo '<div class="balance-info-card">';
        echo '<span class="balance-info-label">' . esc_html__('Заработано', 'cashback-plugin') . '</span>';
        echo '<span id="cashback-paid-amount" class="balance-info-value ' . esc_attr( $paid_balance > 0 ? 'balance-paid' : 'balance-gray' ) . '">' . wp_kses_post( wc_price($paid_balance) ) . '</span>';
        echo '</div>';

        echo '</div>'; // .balance-info-grid

        echo '<p>' . esc_html__('Минимальная сумма выплаты:', 'cashback-plugin') . ' <span class="min-payout-amount">' . wp_kses_post( wc_price($min_payout_amount) ) . '</span></p>';

        // Форма вывода кэшбэка
        echo '<div class="cashback-withdrawal-form">';
        echo '<form id="withdrawal-form" data-cb-protected="1">';
        echo '<p class="form-row">';
        echo '<label for="withdrawal-amount">' . esc_html__('Сумма вывода', 'cashback-plugin') . ' <span class="required">*</span></label>';
        echo '<input type="number" class="input-text" name="withdrawal_amount" id="withdrawal-amount" placeholder="' . esc_attr__('Введите сумму', 'cashback-plugin') . '" value="" step="0.01" min="' . esc_attr((string) $min_payout_amount) . '" />';
        echo '</p>';
        // CAPTCHA контейнер для серых IP
        if (class_exists('Cashback_Captcha')) {
            echo wp_kses_post( Cashback_Captcha::render_container('cb-captcha-withdrawal') ); // Pre-built captcha HTML container.
        }
        echo '<p class="form-row">';
        echo '<button type="submit" class="woocommerce-Button button" id="withdrawal-submit" name="withdrawal_submit" value="' . esc_attr__('Вывести', 'cashback-plugin') . '">' . esc_html__('Вывести', 'cashback-plugin') . '</button>';
        echo '</p>';
        echo '<div id="withdrawal-messages"></div>';
        wp_nonce_field('cashback_withdrawal_submit_nonce', 'withdrawal_nonce');
        echo '</form>';
        echo '</div>';

        echo '</div>'; // #tab-withdrawal

        // === ВКЛАДКА 2: Настройки вывода ===
        echo '<div class="cashback-tab-content" id="tab-settings">';

        echo '<div class="payout-settings-section woocommerce-EditAccountForm edit-account">';
        echo '<h3>' . esc_html__('Настройки вывода кэшбэка', 'cashback-plugin') . '</h3>';
        echo '<div id="payout_settings_message"></div>';

        // Предупреждение о неактивных платежных данных
        if ($settings_inactive) {
            echo '<div class="woocommerce-message woocommerce-error cashback-inactive-warning" role="alert">';
            echo esc_html__('Измените Ваши платежные данные, выплата по Вашим старым данным сейчас не производится', 'cashback-plugin');
            echo '</div>';
        }

        // Получаем название банка один раз для использования в обоих блоках
        $bank_name = ( $bank_id > 0 ) ? $this->get_bank_name($bank_id) : '';

        if ($has_settings && !$settings_inactive) {
            // Если настройки есть и активны - показываем их в виде текста
            $method_name               = $this->get_payout_method_name($payout_method_id);
            $bank_required_for_display = $this->is_bank_required_for_method($payout_method_id);

            echo '<div id="payout_settings_display" class="payout-settings-display">';
            echo '<p class="woocommerce-form-row">';
            echo '<strong>' . esc_html__('Способ вывода:', 'cashback-plugin') . '</strong> ';
            echo esc_html($method_name);
            echo '</p>';
            echo '<p class="woocommerce-form-row">';
            echo '<strong>' . esc_html__('Номер счета/телефона:', 'cashback-plugin') . '</strong> ';
            echo esc_html($masked_account);
            echo '</p>';
            if ($bank_required_for_display && !empty($bank_name)) {
                echo '<p class="woocommerce-form-row">';
                echo '<strong>' . esc_html__('Банк:', 'cashback-plugin') . '</strong> ';
                echo esc_html($bank_name);
                echo '</p>';
            }
            echo '<p class="woocommerce-form-row">';
            echo '<button type="button" class="woocommerce-Button button" id="edit_payout_settings_btn">' . esc_html__('Изменить данные', 'cashback-plugin') . '</button>';
            echo '</p>';
            echo '</div>';
        }

        // Форма редактирования (скрыта, если настройки активны; показана, если неактивны или нет настроек)
        $form_class = ( $has_settings && !$settings_inactive ) ? 'payout-settings-form-hidden' : '';
        echo '<div id="payout_settings_form" class="payout-settings-form ' . esc_attr( $form_class ) . '">';
        echo '<form id="payout-settings-form">';

        echo '<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">';
        echo '<label for="payout_method_id">' . esc_html__('Способ вывода', 'cashback-plugin') . ' <span class="required">*</span></label>';
        echo '<select name="payout_method_id" id="payout_method_id" class="woocommerce-Input woocommerce-Input--text input-text">';
        echo '<option value="">' . esc_html__('Выберите платежную систему', 'cashback-plugin') . '</option>';
        foreach ($payout_methods as $method) {
            $selected      = ( $payout_method_id === intval($method['id']) ) ? 'selected' : '';
            $bank_req_attr = isset($method['bank_required']) ? intval($method['bank_required']) : 1;
            echo '<option value="' . esc_attr((string) $method['id']) . '" data-slug="' . esc_attr($method['slug']) . '" data-bank-required="' . esc_attr((string) $bank_req_attr) . '" ' . esc_attr( $selected ) . '>' . esc_html($method['name']) . '</option>';
        }
        echo '</select>';
        echo '</p>';

        echo '<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">';
        echo '<label for="payout_account">' . esc_html__('Номер счета или телефона', 'cashback-plugin') . ' <span class="required">*</span></label>';
        echo '<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="payout_account" id="payout_account" value="' . esc_attr($payout_account) . '" />';
        echo '</p>';

        // Кастомный компонент поиска банков с autocomplete
        $bank_required_for_current = $this->is_bank_required_for_method($payout_method_id);
        $bank_row_style            = $bank_required_for_current ? '' : ' style="display:none"';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $bank_row_style is a hardcoded attribute fragment (' style="display:none"' or '').
        echo '<div class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide"' . $bank_row_style . '>';
        echo '<label for="bank_search_input" id="bank_search_label">' . esc_html__('Банк', 'cashback-plugin') . ' <span class="required">*</span></label>';
        echo '<input type="hidden" name="bank_id" id="bank_id" value="' . esc_attr((string) $bank_id) . '" />';
        echo '<div class="bank-search-wrapper" role="combobox" aria-expanded="false" aria-owns="bank_search_results" aria-haspopup="listbox">';
        // Используем ранее полученное название банка
        $current_bank_name = $bank_name;
        echo '<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" id="bank_search_input" autocomplete="off" placeholder="' . esc_attr__('Начните вводить название банка...', 'cashback-plugin') . '" value="' . esc_attr($current_bank_name) . '" role="searchbox" aria-autocomplete="list" aria-controls="bank_search_results" aria-labelledby="bank_search_label" />';
        echo '<ul id="bank_search_results" class="bank-search-results" role="listbox" aria-label="' . esc_attr__('Список банков', 'cashback-plugin') . '">';
        // Первый элемент — показ начальных 10 банков при фокусе
        foreach ($banks as $idx => $bank) {
            echo '<li class="bank-search-item" role="option" aria-selected="' . ( $bank_id === intval($bank['id']) ? 'true' : 'false' ) . '" data-bank-id="' . esc_attr($bank['id']) . '" data-bank-name="' . esc_attr($bank['name']) . '" tabindex="-1">' . esc_html($bank['name']) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
        echo '<div id="bank_search_error" class="bank-search-error" role="alert" aria-live="polite"></div>';
        echo '</div>';

        echo '<div class="clear"></div>';

        echo '<p class="woocommerce-form-row form-row">';
        echo '<button type="button" class="woocommerce-Button button" id="save_payout_settings_btn">' . esc_html__('Сохранить настройки', 'cashback-plugin') . '</button>';
        if ($has_settings && !$settings_inactive) {
            echo ' <button type="button" class="woocommerce-Button button button-secondary" id="cancel_edit_payout_settings_btn">' . esc_html__('Отменить', 'cashback-plugin') . '</button>';
        }
        echo '</p>';

        echo '</form>';
        echo '</div>'; // .payout-settings-form
        echo '</div>'; // .payout-settings-section

        echo '</div>'; // #tab-settings
        echo '</div>'; // .cashback-withdrawal-container
    }

    /**
     * Process cashback withdrawal request with concurrency-safe balance handling.
     * Allows multiple withdrawal requests (as long as balance permits),
     * but prevents race conditions during balance deduction.
     */
    /**
     * Process cashback withdrawal request.
     *
     * Архитектура (v2 — production-grade):
     *
     * 1. ИДЕМПОТЕНТНОСТЬ: клиент предоставляет idempotency_key (UUIDv4).
     *    При retry тот же ключ → SELECT возвращает существующую заявку.
     *    UNIQUE KEY в БД — последний рубеж защиты от дублей.
     *
     * 2. БЛОКИРОВКА: только InnoDB row-level lock (SELECT FOR UPDATE).
     *    GET_LOCK удалён — он advisory, не участвует в транзакциях,
     *    привязан к соединению, ненадёжен при connection pooling.
     *
     * 3. INSERT-FIRST: никаких SELECT-before-INSERT проверок.
     *    reference_id генерируется, INSERT пробуется, при коллизии — retry.
     *    UNIQUE KEY — единственный арбитр уникальности.
     *
     * 4. RATE LIMITING: по данным БД (COUNT за 24 часа), не transients.
     *    Атомарно проверяется внутри транзакции с FOR UPDATE.
     *
     * 5. БАЛАНС: обновляется атомарным UPDATE с CHECK в WHERE.
     *    Оптимистичная блокировка через version удалена — FOR UPDATE
     *    уже гарантирует эксклюзивный доступ к строке.
     *
     * 6. ЛЕДЖЕР: пишется в той же транзакции. ON DUPLICATE KEY UPDATE id = id
     *    обеспечивает идемпотентность записи.
     */
    public function process_cashback_withdrawal() {
        // === 1. Безопасность: nonce и аутентификация ===
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'cashback_withdrawal_submit_nonce')) {
            wp_send_json_error(__('Ошибка безопасности.', 'cashback-plugin'));
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(__('Вы должны быть авторизованы для выполнения этого действия.', 'cashback-plugin'));
            return;
        }

        $user_id = get_current_user_id();

        if (!$user_id || $user_id <= 0) {
            wp_send_json_error(__('Некорректный идентификатор пользователя.', 'cashback-plugin'));
            return;
        }

        // Проверка бана (идемпотентная, без side effects)
        if (Cashback_User_Status::is_user_banned($user_id)) {
            $ban_info = Cashback_User_Status::get_ban_info($user_id);
            wp_send_json_error(Cashback_User_Status::get_banned_message($ban_info));
            return;
        }

        // === 1.5. Антифрод: cooling period (детерминистичная проверка, до транзакции) ===
        if (class_exists('Cashback_Fraud_Settings') && Cashback_Fraud_Settings::is_enabled()) {
            $cooling_days = Cashback_Fraud_Settings::get_new_account_cooling_days();
            if ($cooling_days > 0) {
                $user_data = get_userdata($user_id);
                if ($user_data) {
                    $seconds_since = time() - strtotime($user_data->user_registered);
                    if ($seconds_since < ( $cooling_days * DAY_IN_SECONDS )) {
                        $remaining = (int) ceil(( $cooling_days * DAY_IN_SECONDS - $seconds_since ) / DAY_IN_SECONDS);
                        wp_send_json_error(array(
                            'message' => sprintf(
                                /* translators: %d: number of cooling-down days remaining before withdrawal is allowed. */
                                __('Вывод средств будет доступен через %d дн. после регистрации.', 'cashback-plugin'),
                                $remaining
                            ),
                        ));
                        return;
                    }
                }
            }
        }

        // === 2. Валидация входных данных (до любых операций с БД) ===
        global $wpdb;

        // 2.1. Idempotency key от клиента (ОБЯЗАТЕЛЕН)
        // Клиент генерирует UUID при загрузке формы. При retry отправляет тот же UUID.
        // Это единственный способ отличить retry от нового запроса.
        $raw_idempotency_key = sanitize_text_field(wp_unslash($_POST['idempotency_key'] ?? ''));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $raw_idempotency_key)) {
            wp_send_json_error(__('Ошибка запроса. Обновите страницу и попробуйте снова.', 'cashback-plugin'));
            return;
        }
        // Колонка idempotency_key — char(32) hex без дефисов
        $idempotency_key = str_replace('-', '', strtolower($raw_idempotency_key));

        // 2.2. Сумма вывода — строгая валидация десятичного числа
        $withdrawal_amount = sanitize_text_field(wp_unslash($_POST['withdrawal_amount'] ?? '0'));
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $withdrawal_amount)) {
            wp_send_json_error(__('Некорректная сумма вывода.', 'cashback-plugin'));
            return;
        }

        $withdrawal_str = $withdrawal_amount;

        if (bccomp($withdrawal_str, '0', 2) <= 0) {
            wp_send_json_error(__('Сумма вывода должна быть положительной.', 'cashback-plugin'));
            return;
        }

        $max_withdrawal_str = number_format((float) get_option('cashback_max_withdrawal_amount', 50000.00), 2, '.', '');
        if (bccomp($withdrawal_str, $max_withdrawal_str, 2) > 0) {
            /* translators: %s: maximum withdrawal amount formatted as price. */
            wp_send_json_error(sprintf(__('Максимальная сумма вывода %s', 'cashback-plugin'), wc_price((float) $max_withdrawal_str)));
            return;
        }

        // 2.3. Проверка реквизитов (без блокировок — только чтение)
        $payout_method  = $this->get_payout_method($user_id);
        $payout_account = $this->get_payout_account($user_id);

        if (empty($payout_method) || empty($payout_account)) {
            wp_send_json_error(array(
                'message'   => __('Для вывода средств пожалуйста, заполните способ вывода и номер счета в вашем профиле.', 'cashback-plugin'),
                'show_form' => true,
            ));
            return;
        }

        $user_payout_method_id = $this->get_user_payout_method_id($user_id);
        $user_bank_id          = $this->get_user_bank_id($user_id);

        if ($user_payout_method_id > 0 && !$this->is_payout_method_active($user_payout_method_id)) {
            $method_name = $this->get_payout_method_name($user_payout_method_id);
            wp_send_json_error(array(
                /* translators: %s: payout method name that is currently disabled. */
                'message'   => sprintf(__('Через %s сейчас выплаты не производятся, выберите другую', 'cashback-plugin'), $method_name),
                'show_form' => true,
            ));
            return;
        }

        if ($user_bank_id > 0 && !$this->is_bank_active($user_bank_id)) {
            $bank_name = $this->get_bank_name($user_bank_id);
            wp_send_json_error(array(
                /* translators: %s: bank name that is currently disabled for payouts. */
                'message'   => sprintf(__('Через %s сейчас выплаты не производятся, выберите другой', 'cashback-plugin'), $bank_name),
                'show_form' => true,
            ));
            return;
        }

        // === 3. Идемпотентная проверка ДО транзакции (fast path) ===
        // Если заявка с этим ключом уже существует — возвращаем её данные.
        // Это дешёвый SELECT по UNIQUE INDEX, избегаем открытия транзакции при retry.
        $table_requests = $wpdb->prefix . 'cashback_payout_requests';
        $existing       = $wpdb->get_row($wpdb->prepare(
            'SELECT id, reference_id, total_amount, status FROM %i WHERE idempotency_key = %s',
            $table_requests,
            $idempotency_key
        ));

        if ($existing) {
            // Retry от клиента — заявка уже создана. Возвращаем успех с теми же данными.
            wp_send_json_success(sprintf(
                /* translators: 1: withdrawal amount in rubles, 2: payout request reference ID. */
                __('Заявка на вывод кэшбэка на сумму %1$s руб. успешно добавлена. Номер заявки: %2$s', 'cashback-plugin'),
                number_format((float) $existing->total_amount, 2, '.', ' '),
                $existing->reference_id
            ));
            return;
        }

        // === 4. Транзакция: атомарное создание заявки + списание баланса + леджер ===
        $table_balance = $wpdb->prefix . 'cashback_user_balance';
        $ledger_table  = $wpdb->prefix . 'cashback_balance_ledger';

        $wpdb->query('START TRANSACTION');

        try {
            // 4.1. Блокируем строку баланса (FOR UPDATE — единственный механизм блокировки)
            // Это сериализует все withdrawal-запросы одного пользователя.
            // Другие запросы того же user_id будут ждать завершения транзакции.
            $user_balance = $wpdb->get_row($wpdb->prepare(
                'SELECT available_balance, pending_balance
                FROM %i
                WHERE user_id = %d FOR UPDATE',
                $table_balance,
                $user_id
            ));

            if (!$user_balance) {
                throw new \Exception('User balance record not found');
            }

            $balance_str = (string) $user_balance->available_balance;

            // 4.1.1. Повторная проверка бана ВНУТРИ транзакции (после блокировки баланса).
            // Race condition: админ банит пользователя между первой проверкой бана (до транзакции)
            // и SELECT FOR UPDATE на баланс. Бан-триггер обновляет balance, но если withdrawal
            // захватил FOR UPDATE первым, триггер заблокирован и бан ещё не применён.
            // Читаем статус профиля здесь — под защитой транзакции.
            $profile_table = $wpdb->prefix . 'cashback_user_profile';
            $user_status   = $wpdb->get_var($wpdb->prepare(
                'SELECT status FROM %i WHERE user_id = %d FOR UPDATE',
                $profile_table,
                $user_id
            ));
            if ($user_status === 'banned') {
                $wpdb->query('ROLLBACK');
                $ban_info = Cashback_User_Status::get_ban_info($user_id);
                wp_send_json_error(Cashback_User_Status::get_banned_message($ban_info));
                return;
            }

            // 4.2. Rate limiting по данным БД (атомарно внутри транзакции)
            // FOR UPDATE на balance row уже сериализует запросы этого пользователя,
            // поэтому COUNT здесь видит консистентное состояние.
            $recent_requests_count = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i
                 WHERE user_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)',
                $table_requests,
                $user_id
            ));

            if ($recent_requests_count >= 3) {
                $wpdb->query('ROLLBACK');
                wp_send_json_error(__('Слишком много заявок на вывод. Попробуйте через 24 часа.', 'cashback-plugin'));
                return;
            }

            // 4.3. Валидация баланса под блокировкой (исключает TOCTOU)
            $min_payout_amount = $this->get_min_payout_amount($user_id);
            $min_payout_str    = (string) $min_payout_amount;

            if (bccomp($balance_str, $min_payout_str, 2) < 0) {
                throw new \Exception('balance_below_min');
            }

            if (bccomp($withdrawal_str, $min_payout_str, 2) < 0) {
                throw new \Exception('amount_below_min');
            }

            if (bccomp($withdrawal_str, $balance_str, 2) > 0) {
                throw new \Exception('Insufficient available balance after lock');
            }

            // 4.4. Собираем данные для заявки (внутри транзакции — снапшот консистентен)
            // Перечитываем payout_method и payout_account внутри транзакции,
            // чтобы исключить рассогласование с encrypted_details
            // при параллельном save_payout_settings (race condition:
            // pre-tx reads видят старый метод, а in-tx reads — новые encrypted_details).
            $payout_method  = $this->get_payout_method($user_id);
            $payout_account = $this->get_payout_account($user_id);
            if (empty($payout_method) || empty($payout_account)) {
                throw new \Exception('payout_details_missing');
            }
            $bank_info       = $this->get_user_bank_info($user_id);
            $bank_code       = $bank_info['bank_code'] ?? '';
            $encryption_data = $this->get_user_encryption_data($user_id);
            $has_encrypted   = !empty($encryption_data['encrypted_details']);

            // 4.5. INSERT-FIRST с retry по reference_id коллизии
            // reference_id генерируется здесь, не заранее — минимизирует окно коллизии.
            // UNIQUE KEY на idempotency_key — финальная защита от дублей.
            $reference_id = Mariadb_Plugin::generate_reference_id();

            $insert_data    = array(
                'user_id'         => $user_id,
                'reference_id'    => $reference_id,
                'total_amount'    => $withdrawal_amount,
                'payout_method'   => $payout_method,
                'payout_account'  => $has_encrypted ? '' : ( $payout_account ?: '' ),
                'provider'        => $bank_code,
                'idempotency_key' => $idempotency_key,
                'status'          => 'waiting',
            );
            $insert_formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

            if ($has_encrypted) {
                $insert_data['encrypted_details'] = $encryption_data['encrypted_details'];
                $insert_data['masked_details']    = $encryption_data['masked_details'];
                $insert_formats[]                 = '%s';
                $insert_formats[]                 = '%s';
            }

            $inserted           = false;
            $max_insert_retries = 3;

            for ($attempt = 0; $attempt < $max_insert_retries; $attempt++) {
                $result = $wpdb->insert($table_requests, $insert_data, $insert_formats);

                if ($result !== false) {
                    $inserted = true;
                    break;
                }

                $db_error = $wpdb->last_error;

                // Коллизия reference_id — перегенерировать и повторить
                if (strpos($db_error, 'uk_reference_id') !== false || strpos($db_error, 'reference_id') !== false) {
                    $reference_id                = Mariadb_Plugin::generate_reference_id();
                    $insert_data['reference_id'] = $reference_id;
                    continue;
                }

                // Дубликат idempotency_key — параллельный запрос уже создал заявку
                // (прошёл между нашим early check и INSERT)
                if (strpos($db_error, 'uk_idempotency') !== false || strpos($db_error, 'idempotency_key') !== false) {
                    throw new \Exception('Duplicate payout request detected');
                }

                // Неизвестная ошибка — логируем SQL error отдельно (не включаем в Exception)
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log(sprintf('[CashbackWithdrawal] Insert failed for user %d: %s', $user_id, $db_error));
                throw new \Exception('Failed to insert payout request');
            }

            if (!$inserted) {
                throw new \Exception('Failed to insert payout request after reference_id retries');
            }

            $payout_id = $wpdb->insert_id;

            // 4.6. Атомарное списание баланса
            // FOR UPDATE уже гарантирует эксклюзивный доступ — version не нужен.
            // CHECK `available_balance >= amount` — defense in depth на уровне SQL.
            $balance_result = $wpdb->query($wpdb->prepare(
                'UPDATE %i
                SET available_balance = available_balance - CAST(%s AS DECIMAL(18,2)),
                    pending_balance = pending_balance + CAST(%s AS DECIMAL(18,2)),
                    version = version + 1
                WHERE user_id = %d
                  AND available_balance >= CAST(%s AS DECIMAL(18,2))',
                $table_balance,
                $withdrawal_amount,
                $withdrawal_amount,
                $user_id,
                $withdrawal_amount
            ));

            if ($balance_result === false || $balance_result === 0) {
                throw new \Exception('Failed to update user balance');
            }

            // 4.7. Запись в леджер (в той же транзакции)
            // idempotency_key леджера детерминистичен: 'payout_hold_{payout_id}'
            // ON DUPLICATE KEY UPDATE id = id — skip при повторе (невозможен в нормальном flow,
            // но защищает при ручном replay)
            $ledger_amount = '-' . $withdrawal_str;
            $ledger_result = $wpdb->query($wpdb->prepare(
                'INSERT INTO %i
                     (user_id, type, amount, payout_request_id, idempotency_key)
                 VALUES (%d, \'payout_hold\', %s, %d, %s)
                 ON DUPLICATE KEY UPDATE id = id',
                $ledger_table,
                $user_id,
                $ledger_amount,
                $payout_id,
                'payout_hold_' . $payout_id
            ));

            if ($ledger_result === false) {
                throw new \Exception('Failed to write payout_hold to ledger');
            }

            // 4.8. COMMIT — всё или ничего
            $commit_result = $wpdb->query('COMMIT');
            if ($commit_result === false) {
                throw new \Exception('COMMIT failed');
            }

            // === 5. Post-commit side effects (не влияют на консистентность) ===

            // Антифрод: запись события вывода (после коммита, чтобы не inflate при rollback)
            if (class_exists('Cashback_Fraud_Collector')) {
                Cashback_Fraud_Collector::record_withdrawal_event($user_id);
            }

            // Логирование
            $new_balance = bcsub($balance_str, $withdrawal_str, 2);
            wc_get_logger()->info(sprintf(
                'User %d withdrew %s. New balance: %s. Payout ID: %d. Reference: %s. Idempotency: %s',
                $user_id,
                $withdrawal_amount,
                $new_balance,
                $payout_id,
                $reference_id,
                $idempotency_key
            ));

            wp_send_json_success(sprintf(
                /* translators: 1: withdrawal amount in rubles, 2: payout request reference ID. */
                __('Заявка на вывод кэшбэка на сумму %1$s руб. успешно добавлена. Номер заявки: %2$s', 'cashback-plugin'),
                number_format((float) $withdrawal_amount, 2, '.', ' '),
                $reference_id
            ));
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            $error_message = $e->getMessage();

            // Логируем неожиданные ошибки (пропускаем ожидаемые валидационные)
            $expected_errors = array(
                'Insufficient available balance after lock',
                'balance_below_min',
                'amount_below_min',
                'payout_details_missing',
                'Duplicate payout request detected',
            );
            if (!in_array($error_message, $expected_errors, true)) {
                wc_get_logger()->error(sprintf(
                    'CashbackWithdrawal error for user %d: %s. Amount: %s. Idempotency: %s',
                    $user_id,
                    $error_message,
                    $withdrawal_amount,
                    $idempotency_key
                ));
            }

            if ($error_message === 'Insufficient available balance after lock') {
                wp_send_json_error(__('Недостаточно средств для вывода.', 'cashback-plugin'));
            } elseif ($error_message === 'balance_below_min') {
                $min_amt = $this->get_min_payout_amount($user_id);
                /* translators: %s: minimum payout amount formatted as price. */
                wp_send_json_error(sprintf(__('Ваш баланс меньше минимально допустимой суммы для вывода %s', 'cashback-plugin'), wc_price($min_amt)));
            } elseif ($error_message === 'amount_below_min') {
                $min_amt = $this->get_min_payout_amount($user_id);
                /* translators: %s: minimum withdrawal amount formatted as price. */
                wp_send_json_error(sprintf(__('Введите сумму больше или равно %s', 'cashback-plugin'), wc_price($min_amt)));
            } elseif ($error_message === 'payout_details_missing') {
                wp_send_json_error(array(
                    'message'   => __('Для вывода средств пожалуйста, заполните способ вывода и номер счета в вашем профиле.', 'cashback-plugin'),
                    'show_form' => true,
                ));
            } elseif ($error_message === 'Duplicate payout request detected') {
                // Параллельный запрос с тем же ключом — найдём созданную заявку
                $dup = $wpdb->get_row($wpdb->prepare(
                    'SELECT reference_id, total_amount FROM %i WHERE idempotency_key = %s',
                    $table_requests,
                    $idempotency_key
                ));
                if ($dup) {
                    wp_send_json_success(sprintf(
                        /* translators: 1: withdrawal amount in rubles, 2: payout request reference ID. */
                        __('Заявка на вывод кэшбэка на сумму %1$s руб. успешно добавлена. Номер заявки: %2$s', 'cashback-plugin'),
                        number_format((float) $dup->total_amount, 2, '.', ' '),
                        $dup->reference_id
                    ));
                } else {
                    wp_send_json_success(__('Заявка уже создана.', 'cashback-plugin'));
                }
            } else {
                wp_send_json_error(__('Ошибка при обработке запроса на вывод. Пожалуйста, попробуйте еще раз.', 'cashback-plugin'));
            }
        }
    }

    // generate_unique_reference_id() удалён — TOCTOU-паттерн (SELECT before INSERT).
    // reference_id генерируется внутри транзакции, коллизии обрабатываются retry на INSERT
    // через UNIQUE KEY constraint. БД — единственный арбитр уникальности.

    /**
     * Enqueue custom styles and scripts
     */
    public function enqueue_styles() {
        if (is_user_logged_in() && !is_admin() && function_exists('is_account_page') && is_account_page()) {
            wp_enqueue_style(
                'cashback-withdrawal-styles',
                plugins_url('assets/css/frontend.css', __FILE__),
                array(),
                '1.5.0'
            );

            // Подключаем скрипты для обработки формы вывода
            wp_enqueue_script(
                'cashback-withdrawal-js',
                plugins_url('assets/js/cashback-withdrawal.js', __FILE__),
                array( 'jquery' ),
                '1.5.0',
                true
            );

            // Передаем AJAX URL в JavaScript
            wp_localize_script('cashback-withdrawal-js', 'cashback_ajax', array(
                'ajax_url'                => admin_url('admin-ajax.php'),
                'nonce'                   => wp_create_nonce('cashback_withdrawal_nonce'),
                'withdrawal_submit_nonce' => wp_create_nonce('cashback_withdrawal_submit_nonce'),
            ));
        }
    }

    /**
     * AJAX обработчик для сохранения настроек вывода
     */
    public function save_payout_settings() {
        // Проверяем nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['security'] ?? '')), 'cashback_withdrawal_nonce')) {
            wp_send_json_error(array( 'message' => __('Неверный nonce.', 'cashback-plugin') ));
            return;
        }

        // Проверяем авторизацию пользователя
        if (!is_user_logged_in()) {
            wp_send_json_error(array( 'message' => __('Пользователь не авторизован.', 'cashback-plugin') ));
            return;
        }

        $user_id = get_current_user_id();

        if (!$user_id) {
            wp_send_json_error(array( 'message' => __('Пользователь не найден.', 'cashback-plugin') ));
            return;
        }

        // Проверка статуса "banned"
        if (Cashback_User_Status::is_user_banned($user_id)) {
            $ban_info = Cashback_User_Status::get_ban_info($user_id);
            wp_send_json_error(array(
                'message' => Cashback_User_Status::get_banned_message($ban_info),
            ));
            return;
        }

        // Fail-closed encryption guard: без настроенного ключа сохранение реквизитов
        // отключено полностью — plaintext-PII в БД недопустим. Проверяем ДО валидации
        // формы, чтобы не тратить ресурсы и не вводить пользователя в заблуждение
        // сообщениями валидации при фундаментальной недоступности сервиса.
        // См. ADR Группа 4 (F-1-001).
        if (!class_exists('Cashback_Encryption') || !Cashback_Encryption::is_configured()) {
            $this->log_encryption_unavailable('save_payout_settings', $user_id);
            wp_send_json_error(
                array(
                    'message' => __('Шифрование реквизитов не настроено. Пожалуйста, обратитесь к администратору сайта.', 'cashback-plugin'),
                    'code'    => 'encryption_unavailable',
                ),
                503
            );
            return;
        }

        // Rate limiting: max 3 settings saves per 24 hours
        $settings_rate_key   = 'cb_settings_rate_' . $user_id;
        $settings_rate_count = (int) get_transient($settings_rate_key);
        if ($settings_rate_count >= 3) {
            wp_send_json_error(array( 'message' => __('Слишком частое сохранение настроек. Попробуйте через 24 часа.', 'cashback-plugin') ));
            return;
        }

        $payout_method_id = intval($_POST['payout_method_id'] ?? 0);
        $payout_account   = sanitize_text_field(wp_unslash($_POST['payout_account'] ?? ''));
        $bank_id          = intval($_POST['bank_id'] ?? 0);

        // Валидация данных
        if (empty($payout_method_id)) {
            wp_send_json_error(array( 'message' => __('Пожалуйста, выберите способ вывода.', 'cashback-plugin') ));
            return;
        }

        if (empty($payout_account)) {
            wp_send_json_error(array( 'message' => __('Пожалуйста, введите номер счета или телефона.', 'cashback-plugin') ));
            return;
        }

        if (mb_strlen($payout_account) > 50) {
            wp_send_json_error(array( 'message' => __('Номер счета слишком длинный (максимум 50 символов).', 'cashback-plugin') ));
            return;
        }

        // Проверяем, что способ вывода существует и активен
        $valid_method = $this->validate_payout_method($payout_method_id);
        if (!$valid_method) {
            wp_send_json_error(array( 'message' => __('Недопустимый способ вывода.', 'cashback-plugin') ));
            return;
        }

        // Проверяем обязательность банка для выбранного способа
        $bank_required = $this->is_bank_required_for_method($payout_method_id);

        if ($bank_required) {
            if (!$bank_id || $bank_id <= 0) {
                wp_send_json_error(array( 'message' => __('Пожалуйста, выберите банк.', 'cashback-plugin') ));
                return;
            }

            // Проверяем, что банк существует и активен
            $valid_bank = $this->validate_bank($bank_id);
            if (!$valid_bank) {
                wp_send_json_error(array( 'message' => __('Недопустимый банк.', 'cashback-plugin') ));
                return;
            }
        } else {
            $bank_id = 0;
        }

        // Валидация формата номера счёта в зависимости от типа способа выплаты
        $method_slug = $this->get_payout_method_slug_by_id($payout_method_id);
        if ($method_slug) {
            $format_error = $this->validate_payout_account_format($method_slug, $payout_account);
            if (!empty($format_error)) {
                wp_send_json_error(array( 'message' => $format_error ));
                return;
            }
        }

        // Шифрование реквизитов (ключ гарантированно настроен в guard выше —
        // см. проверку is_configured() до валидации формы).
        $encrypted_details = null;
        $masked_details    = null;
        $details_hash      = null;

        try {
            $bank_name  = $bank_required ? $this->get_bank_name($bank_id) : '';
            $enc_result = Cashback_Encryption::encrypt_details(array(
                'account'   => $payout_account,
                'full_name' => '',
                'bank'      => $bank_name ?: '',
            ));
            $encrypted_details = $enc_result['encrypted_details'];
            $masked_details    = $enc_result['masked_details'];
            $details_hash      = $enc_result['details_hash'];
        } catch (\Exception $e) {
            wp_send_json_error(array( 'message' => __('Ошибка шифрования данных.', 'cashback-plugin') ));
            return;
        }

        // Обновляем данные пользователя
        $result = $this->update_user_payout_details(
            $user_id,
            $payout_method_id,
            $payout_account,
            $bank_id,
            $encrypted_details,
            $masked_details,
            $details_hash
        );

        if ($result) {
            // Инкремент счетчика rate limiting
            $settings_rate_count = (int) get_transient($settings_rate_key);
            set_transient($settings_rate_key, $settings_rate_count + 1, DAY_IN_SECONDS);

            wp_send_json_success(array(
                'message' => __('Настройки успешно сохранены.', 'cashback-plugin'),
            ));
        } else {
            wp_send_json_error(array( 'message' => __('Ошибка при сохранении данных.', 'cashback-plugin') ));
        }
    }

    /**
     * Validate payout method
     *
     * @param int $method_id
     * @return bool
     */
    private function validate_payout_method( int $method_id ): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_payout_methods';
        $method     = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE id = %d AND is_active = %d',
                $table_name,
                $method_id,
                1
            ),
            ARRAY_A
        );

        return !empty($method);
    }

    /**
     * Validate bank
     *
     * @param int $bank_id
     * @return bool
     */
    private function validate_bank( int $bank_id ): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_banks';
        $bank       = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE id = %d AND is_active = %d',
                $table_name,
                $bank_id,
                1
            ),
            ARRAY_A
        );

        return !empty($bank);
    }

    /**
     * Get the slug of a payout method by its ID
     *
     * @param int $method_id
     * @return string|null
     */
    private function get_payout_method_slug_by_id( int $method_id ): ?string {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_payout_methods';
        $slug       = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT slug FROM %i WHERE id = %d AND is_active = %d',
                $table_name,
                $method_id,
                1
            )
        );

        return $slug ?: null;
    }

    /**
     * Validate the payout account format based on the payout method slug.
     *
     * @param string $slug
     * @param string $payout_account
     * @return string Error message if invalid, empty string if valid
     */
    private function validate_payout_account_format( string $slug, string $payout_account ): string {
        switch ($slug) {
            case 'sbp':
                return $this->validate_phone_number($payout_account);

            case 'mir':
                return $this->validate_mir_card($payout_account);

            default:
                return '';
        }
    }

    /**
     * Validate phone number for SBP payout method.
     * Must start with +, followed by 10-15 digits (E.164 standard).
     *
     * @param string $phone
     * @return string Error message if invalid, empty string if valid
     */
    private function validate_phone_number( string $phone ): string {
        $cleaned = preg_replace('/[\s\-\(\)]/', '', $phone);

        if (empty($cleaned) || $cleaned[0] !== '+') {
            return __('Номер телефона должен начинаться с кода страны (например, +79001234567).', 'cashback-plugin');
        }

        $digits = substr($cleaned, 1);

        if (!ctype_digit($digits)) {
            return __('Номер телефона содержит недопустимые символы. Допускаются только цифры после знака +.', 'cashback-plugin');
        }

        $digit_count = strlen($digits);
        if ($digit_count < 10 || $digit_count > 15) {
            return __('Введите полный номер телефона с кодом страны (10-15 цифр после +).', 'cashback-plugin');
        }

        return '';
    }

    /**
     * Validate MIR card number.
     * Must be 16 digits, BIN starts with 2200-2204, passes Luhn check.
     *
     * @param string $card
     * @return string Error message if invalid, empty string if valid
     */
    private function validate_mir_card( string $card ): string {
        $cleaned = preg_replace('/[\s\-]/', '', $card);

        if (!ctype_digit($cleaned)) {
            return __('Номер карты должен содержать только цифры.', 'cashback-plugin');
        }

        if (strlen($cleaned) !== 16) {
            return __('Номер карты МИР должен содержать 16 цифр.', 'cashback-plugin');
        }

        $bin_prefix = (int) substr($cleaned, 0, 4);
        if ($bin_prefix < 2200 || $bin_prefix > 2204) {
            return __('Номер карты МИР должен начинаться с 2200-2204.', 'cashback-plugin');
        }

        if (!$this->luhn_check($cleaned)) {
            return __('Некорректный номер карты (не прошёл проверку контрольной суммы).', 'cashback-plugin');
        }

        return '';
    }

    /**
     * Luhn algorithm for card number validation.
     *
     * @param string $number Digits-only string
     * @return bool
     */
    private function luhn_check( string $number ): bool {
        $sum    = 0;
        $length = strlen($number);
        $parity = $length % 2;

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $number[ $i ];

            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return ( $sum % 10 ) === 0;
    }

    /**
     * Check if a payout method is active by ID
     *
     * @param int $method_id
     * @return bool
     */
    private function is_payout_method_active( int $method_id ): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_payout_methods';
        $is_active  = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT is_active FROM %i WHERE id = %d',
                $table_name,
                $method_id
            )
        );

        return (int) $is_active === 1;
    }

    /**
     * Check if a bank is active by ID
     *
     * @param int $bank_id
     * @return bool
     */
    private function is_bank_active( int $bank_id ): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_banks';
        $is_active  = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT is_active FROM %i WHERE id = %d',
                $table_name,
                $bank_id
            )
        );

        return (int) $is_active === 1;
    }

    /**
     * Check if a payout method requires bank selection.
     *
     * @param int $method_id
     * @return bool True if bank is required (default), false if not
     */
    private function is_bank_required_for_method( int $method_id ): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_payout_methods';

        // Подавляем ошибку если колонка bank_required ещё не создана (до миграции)
        $wpdb->suppress_errors(true);
        $bank_required = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT bank_required FROM %i WHERE id = %d',
                $table_name,
                $method_id
            )
        );
        $wpdb->suppress_errors(false);

        // null = колонка не существует или метод не найден → по умолчанию банк обязателен
        return $bank_required === null ? true : (int) $bank_required === 1;
    }

    /**
     * Update user payout details
     *
     * @param int $user_id
     * @param int $payout_method_id
     * @param string $payout_account
     * @param int $bank_id
     * @param string|null $encrypted_details
     * @param string|null $masked_details
     * @param string|null $details_hash
     * @return bool
     */
    private function update_user_payout_details(
        int $user_id,
        int $payout_method_id,
        string $payout_account,
        int $bank_id,
        ?string $encrypted_details = null,
        ?string $masked_details = null,
        ?string $details_hash = null
    ): bool {
        global $wpdb;

        // Defense-in-depth: если шифрование настроено, но ciphertext не передан —
        // это программная ошибка вызывающего кода. Отказываем во избежание
        // случайной записи plaintext PII в БД. См. ADR Группа 4 (F-1-001).
        if ($encrypted_details === null
            && class_exists('Cashback_Encryption')
            && Cashback_Encryption::is_configured()
        ) {
            $this->log_encryption_unavailable('update_user_payout_details_missing_ciphertext', $user_id);
            return false;
        }

        $table_name = $wpdb->prefix . 'cashback_user_profile';

        $wpdb->query('START TRANSACTION');

        try {
            // Проверяем, существует ли запись профиля для пользователя (с блокировкой строки)
            $existing_record = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT user_id FROM %i WHERE user_id = %d FOR UPDATE',
                    $table_name,
                    $user_id
                )
            );

            $data    = array(
                'payout_method_id'          => $payout_method_id,
                'payout_details_updated_at' => current_time('mysql'),
            );
            $formats = array( '%d', '%s' );

            if ($bank_id > 0) {
                $data['bank_id'] = $bank_id;
                $formats[]       = '%d';
            }

            // Если шифрование настроено — сохраняем только зашифрованные данные, plaintext очищаем
            if ($encrypted_details !== null) {
                $data['encrypted_details'] = $encrypted_details;
                $data['masked_details']    = $masked_details;
                $data['details_hash']      = $details_hash;
                $data['payout_account']    = '';
                $data['payout_full_name']  = '';
                $formats                   = array_merge($formats, array( '%s', '%s', '%s', '%s', '%s' ));
            } else {
                // Fallback: если шифрование не настроено, сохраняем plaintext
                $data['payout_account'] = $payout_account;
                $formats[]              = '%s';
            }

            if ($existing_record) {
                // Обновляем существующую запись
                $result = $wpdb->update(
                    $table_name,
                    $data,
                    array( 'user_id' => $user_id ),
                    $formats,
                    array( '%d' )
                );
            } else {
                // Создаем новую запись
                $data['user_id'] = $user_id;
                array_unshift($formats, '%d');
                $result = $wpdb->insert(
                    $table_name,
                    $data,
                    $formats
                );
            }

            if ($result === false) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            // Если банк не обязателен (bank_id = 0), устанавливаем NULL
            // wpdb не поддерживает NULL через format-массив, поэтому используем отдельный запрос
            if ($bank_id <= 0) {
                $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET bank_id = NULL WHERE user_id = %d',
                    $table_name,
                    $user_id
                ));
            }

            $wpdb->query('COMMIT');
            return true;
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    /**
     * AJAX обработчик для поиска банков
     * Защита: nonce, авторизация, подготовленные запросы (SQL-инъекции), XSS (esc_html)
     */
    public function search_banks_ajax() {
        // Проверяем nonce
        if (!check_ajax_referer('cashback_withdrawal_nonce', 'security', false)) {
            wp_send_json_error(array( 'message' => __('Ошибка безопасности.', 'cashback-plugin') ));
            return;
        }

        // Запрещаем анонимный доступ
        if (!is_user_logged_in()) {
            wp_send_json_error(array( 'message' => __('Пользователь не авторизован.', 'cashback-plugin') ));
            return;
        }

        // Rate limiting: максимум 30 запросов в минуту
        $user_id    = get_current_user_id();
        $rate_key   = 'cb_bank_search_rate_' . $user_id;
        $rate_count = (int) get_transient($rate_key);
        if ($rate_count >= 30) {
            wp_send_json_error(array( 'message' => __('Слишком много запросов. Попробуйте через минуту.', 'cashback-plugin') ));
            return;
        }
        set_transient($rate_key, $rate_count + 1, MINUTE_IN_SECONDS);

        global $wpdb;

        // Санитизация поискового запроса — защита от XSS
        $search_term = sanitize_text_field(wp_unslash($_POST['search'] ?? ''));

        if (mb_strlen($search_term) < 1) {
            // Если менее 1 символа, возвращаем первые 10 активных банков
            $table_name = $wpdb->prefix . 'cashback_banks';
            $banks      = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id, name FROM %i WHERE is_active = %d ORDER BY sort_order ASC, name ASC LIMIT 10',
                    $table_name,
                    1
                ),
                ARRAY_A
            );

            wp_send_json_success(array( 'banks' => $banks ?: array() ));
            return;
        }

        $table_name = $wpdb->prefix . 'cashback_banks';

        // Подготовленный запрос — защита от SQL-инъекций
        // Используем LIKE с подстановкой через $wpdb->prepare
        $like_term = '%' . $wpdb->esc_like($search_term) . '%';

        $banks = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, name FROM %i WHERE is_active = %d AND name LIKE %s ORDER BY sort_order ASC, name ASC LIMIT 20',
                $table_name,
                1,
                $like_term
            ),
            ARRAY_A
        );

        wp_send_json_success(array( 'banks' => $banks ?: array() ));
    }

    /**
     * AJAX обработчик для получения баланса пользователя
     */
    public function get_user_balance_ajax() {
        // Проверяем nonce
        if (!check_ajax_referer('cashback_withdrawal_nonce', 'nonce', false)) {
            wp_send_json_error(__('Ошибка безопасности.', 'cashback-plugin'));
            return;
        }

        // Проверяем, авторизован ли пользователь
        if (!is_user_logged_in()) {
            wp_send_json_error(__('Вы должны быть авторизованы для выполнения этого действия.', 'cashback-plugin'));
            return;
        }

        // Rate limiting: максимум 30 запросов в минуту
        $user_id    = get_current_user_id();
        $rate_key   = 'cb_balance_rate_' . $user_id;
        $rate_count = (int) get_transient($rate_key);
        if ($rate_count >= 30) {
            wp_send_json_error(__('Слишком много запросов. Попробуйте через минуту.', 'cashback-plugin'));
            return;
        }
        set_transient($rate_key, $rate_count + 1, MINUTE_IN_SECONDS);

        $balances = $this->get_all_balances($user_id);

        wp_send_json_success(array(
            'balance'           => $balances['available'],
            'formatted_balance' => wc_price($balances['available']),
            'pending_balance'   => $balances['pending'],
            'formatted_pending' => wc_price($balances['pending']),
            'paid_balance'      => $balances['paid'],
            'formatted_paid'    => wc_price($balances['paid']),
        ));
    }
}

// Инициализация будет происходить в основном файле плагина
