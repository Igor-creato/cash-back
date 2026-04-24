<?php

declare(strict_types=1);

// phpcs:ignore PSR12.Files.FileHeader.IncorrectOrder -- Plugin file header comment ordering preserved for legacy consistency.
/**
 * Файл для управления партнерскими сетями в админке WordPress
 */

// Проверяем, что файл вызывается из WordPress
if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Partner_Management_Admin {


    private string $table_name;
    private string $params_table;

    public function __construct() {
        global $wpdb;
        $this->table_name   = $wpdb->prefix . 'cashback_affiliate_networks';
        $this->params_table = $wpdb->prefix . 'cashback_affiliate_network_params';

        // Регистрируем хук для добавления пункта меню
        add_action('admin_menu', array( $this, 'add_admin_menu' ));

        // Обработка AJAX запросов
        add_action('wp_ajax_update_partner', array( $this, 'handle_update_partner' ));
        add_action('wp_ajax_add_partner', array( $this, 'handle_add_partner' ));
        add_action('wp_ajax_save_network_params', array( $this, 'handle_save_network_params' ));
        add_action('wp_ajax_get_network_params', array( $this, 'handle_get_network_params' ));
        add_action('wp_ajax_delete_network_param', array( $this, 'handle_delete_network_param' ));
        add_action('wp_ajax_update_network_param', array( $this, 'handle_update_network_param' ));

        // Подключение скриптов
        add_action('admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ));
    }

    /**
     * Подключение скриптов и стилей для админ-панели
     */
    public function enqueue_admin_scripts( string $hook ): void {
        $allowed_hooks = array(
            'cashback-overview_page_cashback-partners',
            'toplevel_page_cashback-partners',
            'admin_page_cashback-partners',
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page-detect, literal compare.
        $is_partners_page = in_array($hook, $allowed_hooks, true) || ( isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-partners' );

        if (!$is_partners_page) {
            return;
        }

        wp_enqueue_script(
            'cashback-admin-partners',
            plugins_url('../assets/js/admin-partner-management.js', __FILE__),
            array( 'jquery' ),
            '1.2.0',
            true
        );

        wp_localize_script('cashback-admin-partners', 'cashbackPartnersData', array(
            'updateNonce'      => wp_create_nonce('update_partner_nonce'),
            'addNonce'         => wp_create_nonce('add_partner_nonce'),
            'saveParamsNonce'  => wp_create_nonce('save_network_params_nonce'),
            'getParamsNonce'   => wp_create_nonce('get_network_params_nonce'),
            'deleteParamNonce' => wp_create_nonce('delete_network_param_nonce'),
            'updateParamNonce' => wp_create_nonce('update_network_param_nonce'),
        ));

        // JS для вкладки «Разрешенные домены API Base URL» (tab=outbound-allowlist).
        // Подключаем всегда на странице партнёров — JS активируется только если
        // на странице есть соответствующая форма/кнопки.
        if (class_exists('Cashback_Admin_Outbound_Allowlist')) {
            wp_enqueue_script(
                'cashback-admin-outbound-allowlist',
                plugins_url('../assets/js/admin-outbound-allowlist.js', __FILE__),
                array( 'jquery' ),
                '1.0.0',
                true
            );

            wp_localize_script('cashback-admin-outbound-allowlist', 'cashbackOutboundAllowlist', array(
                'nonce'   => wp_create_nonce(Cashback_Admin_Outbound_Allowlist::NONCE_ACTION),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'i18n'    => array(
                    'confirmRemove' => __('Удалить этот домен из allowlist?', 'cashback-plugin'),
                    'errorGeneric'  => __('Произошла ошибка. Попробуйте снова.', 'cashback-plugin'),
                ),
            ));
        }
    }

    /**
     * Добавляем пункт меню в админке
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'cashback-overview',
            'Партнеры',
            'Партнеры',
            'manage_options',
            'cashback-partners',
            array( $this, 'render_partners_page' )
        );
    }

    /**
     * Отображаем страницу управления партнерами
     */
    public function render_partners_page(): void {
        // Проверяем права доступа
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }

        global $wpdb;

        // Поисковый запрос
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing search, sanitized + used via wpdb->esc_like.
        $search_query = isset($_GET['partner_search']) ? sanitize_text_field(wp_unslash($_GET['partner_search'])) : '';
        $is_search    = !empty($search_query);

        // Фильтр по статусу is_active
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing filter, allowlist-compared below.
        $filter_status = isset($_GET['filter_status']) ? sanitize_text_field(wp_unslash($_GET['filter_status'])) : '';
        $is_filtered   = ( $filter_status !== '' && $filter_status !== 'all' );

        // Пагинация: настройки
        $per_page = 10;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing pagination, intval-cast.
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset       = ( $current_page - 1 ) * $per_page;

        // Формируем WHERE-условия
        $where_conditions = array();
        $where_values     = array();

        if ($is_search) {
            $like_pattern       = '%' . $wpdb->esc_like($search_query) . '%';
            $where_conditions[] = '(name LIKE %s OR slug LIKE %s)';
            $where_values[]     = $like_pattern;
            $where_values[]     = $like_pattern;
        }

        if ($is_filtered) {
            $where_conditions[] = 'is_active = %d';
            $where_values[]     = intval($filter_status);
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);
        }

        // Общее количество партнеров
        if (!empty($where_values)) {
            $count_args     = array_merge(array( $this->table_name ), $where_values);
            $total_partners = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_clause из allowlist (`is_active = %d`, LIKE %s), значения через prepare().
                $wpdb->prepare( "SELECT COUNT(*) FROM %i{$where_clause}", ...$count_args )
            );

            // Получаем партнеров с учётом фильтров и пагинации
            $query_values = array_merge(array( $this->table_name ), $where_values, array( $per_page, $offset ));
            $partners     = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_clause из allowlist (`is_active = %d`, LIKE %s), значения через prepare(); sniff не считает spread-args.
                $wpdb->prepare( "SELECT * FROM %i{$where_clause} ORDER BY sort_order ASC, name ASC LIMIT %d OFFSET %d", ...$query_values ),
                ARRAY_A
            );
        } else {
            $total_partners = (int) $wpdb->get_var(
                $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %d = %d', $this->table_name, 1, 1 )
            );

            $partners = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i ORDER BY sort_order ASC, name ASC LIMIT %d OFFSET %d',
                    $this->table_name,
                    $per_page,
                    $offset
                ),
                ARRAY_A
            );
        }

        // Вычисляем общее количество страниц
        $total_pages = (int) ceil($total_partners / $per_page);

        // Получаем активные сети для выпадающего списка во вкладке параметров
        $active_networks = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, name FROM %i WHERE is_active = %d ORDER BY sort_order ASC, name ASC',
                $this->table_name,
                1
            ),
            ARRAY_A
        );

        // Выводим сообщения об ошибках или успехе
        $message = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice, allowlist-compared below.
        if (isset($_GET['message']) && is_string($_GET['message'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice, allowlist-compared below.
            $msg_type = sanitize_text_field(wp_unslash($_GET['message']));
            if ($msg_type === 'added') {
                $message = '<div class="notice notice-success is-dismissible"><p>Партнер успешно добавлен.</p></div>';
            } elseif ($msg_type === 'updated') {
                $message = '<div class="notice notice-success is-dismissible"><p>Партнер успешно обновлен.</p></div>';
            }
        }

        // Определяем активную вкладку
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin UI tab selector, allowlist-validated below.
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'partners';
        if (!in_array($active_tab, array( 'partners', 'params', 'outbound-allowlist' ), true)) {
            $active_tab = 'partners';
        }
?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Партнеры</h1>
            <hr class="wp-header-end">

            <?php echo wp_kses_post($message); ?>

            <!-- Вкладки -->
            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('admin.php?page=cashback-partners&tab=partners')); ?>"
                    class="nav-tab <?php echo $active_tab === 'partners' ? 'nav-tab-active' : ''; ?>">Партнеры</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=cashback-partners&tab=params')); ?>"
                    class="nav-tab <?php echo $active_tab === 'params' ? 'nav-tab-active' : ''; ?>">Добавить передаваемые в URL</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=cashback-partners&tab=outbound-allowlist')); ?>"
                    class="nav-tab <?php echo $active_tab === 'outbound-allowlist' ? 'nav-tab-active' : ''; ?>">Разрешенные домены API Base URL</a>
            </nav>

            <?php if ($active_tab === 'params') : ?>
                <!-- Вкладка параметров URL -->
                <div class="card" id="network-params-form" style="margin-top: 20px; margin-bottom: 20px;">
                    <h2 class="title">Параметры URL партнерской сети</h2>
                    <p class="description" style="margin: 5px 0 15px; color: #666;">
                        В поле <b>Значение параметра</b>: для подстановки ID пользователя введите <code>user</code>, для уникального идентификатора клика — <code>uuid</code>, иначе значение будет передано как есть.
                    </p>

                    <div style="margin-bottom: 15px;">
                        <label for="network-select" style="display:block; font-weight:600; margin-bottom:5px;">Выберите сеть:</label>
                        <select id="network-select" required>
                            <option value="">Выберите сеть</option>
                            <?php foreach ($active_networks as $network) : ?>
                                <option value="<?php echo esc_attr($network['id']); ?>">
                                    <?php echo esc_html($network['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="existing-params-table" style="display:none; margin-bottom: 20px;"></div>

                    <div id="params-container">
                        <div class="param-row" style="margin-bottom: 15px;">
                            <div style="margin-bottom: 10px;">
                                <label style="display:block; font-weight:600; margin-bottom:5px;">Название параметра:</label>
                                <input type="text" class="regular-text param-name" name="param_name[]" />
                            </div>
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:5px;">Значение параметра:</label>
                                <input type="text" class="regular-text param-type" name="param_type[]" />
                            </div>
                        </div>
                    </div>

                    <p class="submit">
                        <button type="button" id="add-param-row" class="button button-secondary">Добавить</button>
                        <button type="button" id="save-network-params" class="button button-primary">Сохранить</button>
                    </p>
                </div>

            <?php elseif ($active_tab === 'outbound-allowlist') : ?>
                <!-- Вкладка: разрешённые домены API Base URL (SSRF allowlist) -->
                <?php
                if (class_exists('Cashback_Admin_Outbound_Allowlist')) {
                    Cashback_Admin_Outbound_Allowlist::get_instance()->render_tab_content();
                }
                ?>

            <?php else : ?>
                <!-- Вкладка партнеров -->

                <!-- Форма добавления нового партнера -->
                <div class="card" id="add-partner-form" style="margin-top: 20px; margin-bottom: 20px;">
                    <h2 class="title">Добавить партнера</h2>
                    <form id="add-partner" method="post" action="">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="name">Название партнера:</label></th>
                                <td><input type="text" id="name" name="name" class="regular-text" required /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="slug">Slug:</label></th>
                                <td><input type="text" id="slug" name="slug" class="regular-text" required /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="notes">Примечание:</label></th>
                                <td><textarea id="notes" name="notes" class="large-text" rows="3"></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="sort_order">Порядок сортировки:</label></th>
                                <td><input type="number" id="sort_order" name="sort_order" value="0" min="0" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="is_active">Активен:</label></th>
                                <td>
                                    <select id="is_active" name="is_active">
                                        <option value="1">Да</option>
                                        <option value="0">Нет</option>
                                    </select>
                                </td>
                            </tr>
                            <!-- 12i-5 ADR (F-10-001): per-network click session policy -->
                            <tr>
                                <th scope="row"><label for="click_session_policy">Политика клика:</label></th>
                                <td>
                                    <select id="click_session_policy" name="click_session_policy">
                                        <option value="reuse_in_window" selected>Переиспользовать в окне</option>
                                        <option value="always_new">Всегда новый клик</option>
                                        <option value="reuse_per_product">Переиспользовать по товару</option>
                                    </select>
                                    <p class="description">Поведение при повторном клике пользователя. Рекомендуется "Переиспользовать в окне" (защита от CPA-штрафов за дубли).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="activation_window_seconds">Окно активации (сек):</label></th>
                                <td>
                                    <input type="number" id="activation_window_seconds" name="activation_window_seconds" value="1800" min="60" max="86400" />
                                    <p class="description">Диапазон 60..86400 (от 1 минуты до 24 часов). По умолчанию 1800 (30 минут).</p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <input type="submit" name="add_partner" id="add-partner-submit" class="button button-primary" value="Добавить партнера" />
                        </p>
                    </form>
                </div>

                <!-- Таблица существующих партнеров -->
                <h2 class="title">Существующие партнеры</h2>

                <!-- Фильтр по статусу и поиск -->
                <div class="search-box" style="margin-bottom: 15px;">
                    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                        <input type="hidden" name="page" value="cashback-partners" />
                        <input type="hidden" name="tab" value="partners" />
                        <label for="filter-status" class="screen-reader-text">Фильтр по статусу:</label>
                        <select id="filter-status" name="filter_status">
                            <option value="all" <?php selected($filter_status, ''); ?><?php selected($filter_status, 'all'); ?>>Все статусы</option>
                            <option value="1" <?php selected($filter_status, '1'); ?>>Активные</option>
                            <option value="0" <?php selected($filter_status, '0'); ?>>Не активные</option>
                        </select>
                        <label class="screen-reader-text" for="partner-search-input">Поиск партнеров:</label>
                        <input type="search" id="partner-search-input" name="partner_search"
                            value="<?php echo esc_attr($search_query); ?>"
                            placeholder="Введите название партнера или slug" style="min-width: 300px;" />
                        <input type="submit" id="search-submit" class="button" value="Фильтровать" />
                        <?php if ($is_filtered || $is_search) : ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=cashback-partners&tab=partners')); ?>" class="button">Сбросить</a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if ($is_search) : ?>
                    <p>Результаты поиска по запросу: <strong>&laquo;<?php echo esc_html($search_query); ?>&raquo;</strong>
                        — найдено: <?php echo esc_html((string) $total_partners); ?></p>
                <?php endif; ?>

                <div class="wp-list-table-wrapper">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col">Название</th>
                                <th scope="col">Slug</th>
                                <th scope="col">Примечание</th>
                                <th scope="col">Сортировка</th>
                                <th scope="col">Активен</th>
                                <!-- 12i-5 ADR (F-10-001): per-network click session policy -->
                                <th scope="col">Политика клика</th>
                                <th scope="col">Окно (сек)</th>
                                <th scope="col">Действия</th>
                            </tr>
                        </thead>
                        <tbody id="partners-tbody">
                            <?php if (!empty($partners)) : ?>
                                <?php foreach ($partners as $partner) : ?>
                                    <?php
                                    // 12i-5 ADR (F-10-001): human-readable label для policy.
                                    $policy_labels      = array(
                                        'reuse_in_window' => 'Переиспользовать в окне',
                                        'always_new'      => 'Всегда новый клик',
                                        'reuse_per_product' => 'Переиспользовать по товару',
                                    );
                                    $partner_policy     = isset($partner['click_session_policy']) ? (string) $partner['click_session_policy'] : 'reuse_in_window';
                                    $partner_policy_lbl = $policy_labels[ $partner_policy ] ?? 'Переиспользовать в окне';
                                    $partner_window     = isset($partner['activation_window_seconds']) ? (int) $partner['activation_window_seconds'] : 1800;
                                    ?>
                                    <tr data-id="<?php echo esc_attr($partner['id']); ?>">
                                        <td class="edit-field" data-field="name"><?php echo esc_html($partner['name']); ?></td>
                                        <td class="edit-field" data-field="slug"><?php echo esc_html($partner['slug']); ?></td>
                                        <td class="edit-field" data-field="notes"><?php echo esc_html($partner['notes'] ?: ''); ?></td>
                                        <td class="edit-field" data-field="sort_order"><?php echo esc_html($partner['sort_order']); ?></td>
                                        <td class="edit-field" data-field="is_active">
                                            <?php echo $partner['is_active'] ? 'Да' : 'Нет'; ?>
                                        </td>
                                        <td class="edit-field" data-field="click_session_policy" data-value="<?php echo esc_attr($partner_policy); ?>">
                                            <?php echo esc_html($partner_policy_lbl); ?>
                                        </td>
                                        <td class="edit-field" data-field="activation_window_seconds">
                                            <?php echo esc_html((string) $partner_window); ?>
                                        </td>
                                        <td>
                                            <button class="button button-secondary edit-btn">Редактировать</button>
                                            <button class="button button-primary save-btn" style="display:none;">Сохранить</button>
                                            <button class="button button-default cancel-btn" style="display:none;">Отмена</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr>
                                    <td colspan="8">
                                        <?php if ($is_search) : ?>
                                            По запросу &laquo;<?php echo esc_html($search_query); ?>&raquo; партнеры не найдены.
                                        <?php else : ?>
                                            Нет доступных партнеров.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php
                $partner_add_args = array( 'tab' => 'partners' );
                if ($is_search) {
                    $partner_add_args['partner_search'] = $search_query;
                }
                if ($is_filtered) {
                    $partner_add_args['filter_status'] = $filter_status;
                }
                Cashback_Pagination::render(array(
                    'total_items'  => $total_partners,
                    'current_page' => $current_page,
                    'total_pages'  => $total_pages,
                    'page_slug'    => 'cashback-partners',
                    'add_args'     => $partner_add_args,
                ));
                ?>

            <?php endif; ?>

        </div>
<?php
    }

    /**
     * Обработка AJAX запроса на обновление партнера
     */
    public function handle_update_partner(): void {
        // Проверяем nonce
        $nonce = isset($_POST['nonce']) && is_string($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'update_partner_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        // 12i-5 ADR (F-10-001): idempotency для client-side retries admin-AJAX.
        $idem_scope      = 'admin_partner_update';
        $idem_user_id    = get_current_user_id();
        $idem_request_id = '';
        if (isset($_POST['request_id']) && is_string($_POST['request_id'])) {
            $idem_request_id = Cashback_Idempotency::normalize_request_id(sanitize_text_field(wp_unslash($_POST['request_id'])));
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
					'message' => 'Запрос уже обрабатывается.',
				), 409);
                return;
            }
        }

        global $wpdb;

        $id         = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $name       = isset($_POST['name']) && is_string($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $slug       = isset($_POST['slug']) && is_string($_POST['slug']) ? sanitize_title(wp_unslash($_POST['slug'])) : '';
        $notes      = isset($_POST['notes']) && is_string($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        $sort_order = isset($_POST['sort_order']) ? intval($_POST['sort_order']) : 0;
        $is_active  = isset($_POST['is_active']) ? intval($_POST['is_active']) : 0;

        // 12i-5 ADR (F-10-001): click session policy + activation window с fail-safe clamp.
        $policy_allowlist = array( 'reuse_in_window', 'always_new', 'reuse_per_product' );
        $policy           = isset($_POST['click_session_policy']) && is_string($_POST['click_session_policy'])
            ? sanitize_text_field(wp_unslash($_POST['click_session_policy']))
            : 'reuse_in_window';
        if (!in_array($policy, $policy_allowlist, true)) {
            $policy = 'reuse_in_window';
        }
        $window = isset($_POST['activation_window_seconds']) ? intval($_POST['activation_window_seconds']) : 1800;
        if ($window < 60 || $window > 86400) {
            $window = 1800;
        }

        // Валидация данных
        if (empty($name) || empty($slug)) {
            wp_send_json_error(array( 'message' => 'Заполните все обязательные поля.' ));
            return;
        }

        // Читаем текущий статус активности до обновления
        $old_is_active = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT is_active FROM %i WHERE id = %d', $this->table_name, $id )
        );

        // Обновляем запись в базе данных
        $result = $wpdb->update(
            $this->table_name,
            array(
                'name'                      => $name,
                'slug'                      => $slug,
                'notes'                     => $notes,
                'sort_order'                => $sort_order,
                'is_active'                 => $is_active,
                'click_session_policy'      => $policy,
                'activation_window_seconds' => $window,
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%d', '%d', '%s', '%d' ),
            array( '%d' )
        );

        if ($result === false) {
            wp_send_json_error(array( 'message' => 'Ошибка при обновлении партнера в базе данных.' ));
            return;
        }

        // Синхронизируем статус публикации товаров при изменении активности сети
        $products_affected = 0;
        if ($old_is_active !== $is_active) {
            $products_affected = $this->sync_products_visibility($id, $is_active);
        }

        $response_data = array(
            'id'                        => $id,
            'name'                      => $name,
            'slug'                      => $slug,
            'notes'                     => $notes,
            'sort_order'                => $sort_order,
            'is_active'                 => $is_active,
            'click_session_policy'      => $policy,
            'activation_window_seconds' => $window,
            'products_affected'         => $products_affected,
        );

        // 12i-5 ADR (F-10-001): store_result для retry-replay в TTL.
        if ($idem_request_id !== '') {
            Cashback_Idempotency::store_result($idem_scope, $idem_user_id, $idem_request_id, $response_data);
        }

        wp_send_json_success($response_data);
    }

    /**
     * Обработка AJAX запроса на добавление партнера
     */
    public function handle_add_partner(): void {
        // Проверяем nonce
        $nonce = isset($_POST['nonce']) && is_string($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'add_partner_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        // 12i-5 ADR (F-10-001): idempotency для client-side retries admin-AJAX.
        $idem_scope      = 'admin_partner_add';
        $idem_user_id    = get_current_user_id();
        $idem_request_id = '';
        if (isset($_POST['request_id']) && is_string($_POST['request_id'])) {
            $idem_request_id = Cashback_Idempotency::normalize_request_id(sanitize_text_field(wp_unslash($_POST['request_id'])));
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
					'message' => 'Запрос уже обрабатывается.',
				), 409);
                return;
            }
        }

        global $wpdb;

        $name       = isset($_POST['name']) && is_string($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $slug       = isset($_POST['slug']) && is_string($_POST['slug']) ? sanitize_title(wp_unslash($_POST['slug'])) : '';
        $notes      = isset($_POST['notes']) && is_string($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        $sort_order = isset($_POST['sort_order']) ? intval($_POST['sort_order']) : 0;
        $is_active  = isset($_POST['is_active']) ? intval($_POST['is_active']) : 0;

        // 12i-5 ADR (F-10-001): click session policy + activation window с fail-safe clamp.
        $policy_allowlist = array( 'reuse_in_window', 'always_new', 'reuse_per_product' );
        $policy           = isset($_POST['click_session_policy']) && is_string($_POST['click_session_policy'])
            ? sanitize_text_field(wp_unslash($_POST['click_session_policy']))
            : 'reuse_in_window';
        if (!in_array($policy, $policy_allowlist, true)) {
            $policy = 'reuse_in_window';
        }
        $window = isset($_POST['activation_window_seconds']) ? intval($_POST['activation_window_seconds']) : 1800;
        if ($window < 60 || $window > 86400) {
            $window = 1800;
        }

        // Валидация данных
        if (empty($name) || empty($slug)) {
            wp_send_json_error(array( 'message' => 'Заполните все обязательные поля.' ));
            return;
        }

        // Pre-check по name — не покрыт UNIQUE-ограничением в БД (только slug имеет uniq_slug).
        // Это бизнес-правило против UI-дублей, не race-safe (TOCTOU окно допустимо на admin-path).
        $name_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE name = %s',
                $this->table_name,
                $name
            )
        );
        if ($name_exists > 0) {
            wp_send_json_error(array( 'message' => 'Партнер с таким названием уже существует.' ));
            return;
        }

        // Добавляем новую запись. Для slug полагаемся на UNIQUE uniq_slug (см. mariadb.php:186):
        // check-then-insert убран (Группа 6 ADR, шаг 3b, closes F-26-002) — дубль распознаётся через last_error.
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'name'                      => $name,
                'slug'                      => $slug,
                'notes'                     => $notes,
                'sort_order'                => $sort_order,
                'is_active'                 => $is_active,
                'click_session_policy'      => $policy,
                'activation_window_seconds' => $window,
            ),
            array( '%s', '%s', '%s', '%d', '%d', '%s', '%d' )
        );

        if ($result === false) {
            $classification = self::classify_partner_insert_error($wpdb->last_error);
            if ('duplicate_slug' === $classification) {
                wp_send_json_error(array( 'message' => 'Партнер с таким slug уже существует.' ));
                return;
            }
            wp_send_json_error(array( 'message' => 'Ошибка при добавлении партнера в базе данных.' ));
            return;
        }

        $response_data = array(
            'click_session_policy'      => $policy,
            'activation_window_seconds' => $window,
        );

        // 12i-5 ADR (F-10-001): store_result для retry-replay в TTL.
        if ($idem_request_id !== '') {
            Cashback_Idempotency::store_result($idem_scope, $idem_user_id, $idem_request_id, $response_data);
        }

        wp_send_json_success($response_data);
    }

    /**
     * Распознать какой UNIQUE ключ сработал по $wpdb->last_error.
     * Используется в handle_add_partner для различения Duplicate entry на slug.
     *
     * @return string 'duplicate_slug' | 'other'
     */
    public static function classify_partner_insert_error( string $last_error ): string {
        if ('' === $last_error || false === strpos($last_error, 'Duplicate entry')) {
            return 'other';
        }
        if (false !== strpos($last_error, "'uniq_slug'")) {
            return 'duplicate_slug';
        }
        return 'other';
    }

    /**
     * Получение параметров партнерской сети
     */
    public function handle_get_network_params(): void {
        $nonce = isset($_POST['nonce']) && is_string($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'get_network_params_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        global $wpdb;

        $network_id = isset($_POST['network_id']) ? intval($_POST['network_id']) : 0;

        $params = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, param_name, param_type FROM %i WHERE network_id = %d ORDER BY id ASC',
                $this->params_table,
                $network_id
            ),
            ARRAY_A
        );

        wp_send_json_success(array( 'params' => $params ?: array() ));
    }

    /**
     * Сохранение параметров партнерской сети
     */
    public function handle_save_network_params(): void {
        $nonce = isset($_POST['nonce']) && is_string($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'save_network_params_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        global $wpdb;

        $network_id = isset($_POST['network_id']) ? intval($_POST['network_id']) : 0;

        if ($network_id <= 0) {
            wp_send_json_error(array( 'message' => 'Выберите партнерскую сеть.' ));
            return;
        }

        // Проверяем что сеть существует
        $network_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE id = %d',
                $this->table_name,
                $network_id
            )
        );

        if ($network_exists === 0) {
            wp_send_json_error(array( 'message' => 'Партнерская сеть не найдена.' ));
            return;
        }

        $param_names = isset($_POST['param_names']) && is_array($_POST['param_names'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['param_names']))
            : array();
        $param_types = isset($_POST['param_types']) && is_array($_POST['param_types'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['param_types']))
            : array();

        // Проверяем лимит
        if (count($param_names) > 10) {
            wp_send_json_error(array( 'message' => 'Максимальное количество параметров — 10.' ));
            return;
        }

        // Проверяем что хотя бы один param_name заполнен
        $has_valid = false;
        foreach ($param_names as $pn) {
            if (!empty($pn)) {
                $has_valid = true;
                break;
            }
        }

        if (!$has_valid) {
            wp_send_json_error(array( 'message' => 'Заполните хотя бы одно название параметра.' ));
            return;
        }

        // F-2-001 п.4: whitelist для param_name (ASCII alpha-num + _/-, 1..32 симв.).
        // Отсекаем мусорные ключи до старта транзакции — не пускаем в БД.
        foreach ($param_names as $pn) {
            if ($pn === '') {
                continue;
            }
            if (!Cashback_Click_Session_Service::is_valid_affiliate_param_name($pn)) {
                wp_send_json_error(array(
                    'message' => sprintf(
                        /* translators: %s: rejected parameter name (truncated) */
                        __('Название параметра "%s" содержит недопустимые символы. Разрешены только буквы, цифры, _ и - (1..32 символов).', 'cashback-plugin'),
                        substr($pn, 0, 48)
                    ),
                ));
                return;
            }
        }

        $new_count = 0;
        foreach ($param_names as $pn) {
            if (!empty($pn)) {
                ++$new_count;
            }
        }

        // iter-26 F-26-003: проверка лимита внутри транзакции с блокировкой существующих
        // параметров сети. Без FOR UPDATE параллельные AJAX-запросы обходили порог 10.
        $wpdb->query('START TRANSACTION');

        // Блокируем существующие параметры сети (gap-free row-lock по network_id-индексу).
        $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE network_id = %d FOR UPDATE',
                $this->params_table,
                $network_id
            )
        );

        $existing_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE network_id = %d',
                $this->params_table,
                $network_id
            )
        );

        if (( $existing_count + $new_count ) > 10) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => 'Максимальное количество параметров — 10. Сейчас уже ' . $existing_count . '.' ));
            return;
        }

        $insert_error      = false;
        $param_names_count = count($param_names);
        for ($i = 0; $i < $param_names_count; $i++) {
            $pn = $param_names[ $i ];
            if (empty($pn)) {
                continue;
            }
            $pt = isset($param_types[ $i ]) ? $param_types[ $i ] : '';

            $result = $wpdb->insert(
                $this->params_table,
                array(
                    'network_id' => $network_id,
                    'param_name' => $pn,
                    'param_type' => $pt,
                ),
                array( '%d', '%s', '%s' )
            );

            if ($result === false) {
                $insert_error = true;
                break;
            }
        }

        if ($insert_error) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => 'Ошибка при сохранении параметров.' ));
            return;
        }

        $wpdb->query('COMMIT');
        wp_send_json_success(array( 'message' => 'Параметры успешно сохранены.' ));
    }

    /**
     * Удаление одного параметра партнерской сети
     */
    public function handle_delete_network_param(): void {
        $nonce = isset($_POST['nonce']) && is_string($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'delete_network_param_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        // Server-side дедуп request_id (Группа 5 ADR, F-35-006).
        $idem_scope      = 'admin_partner_param_delete';
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
                    'message' => 'Запрос уже обрабатывается. Повторите через несколько секунд.',
                ), 409);
                return;
            }
        }

        global $wpdb;

        $param_id = isset($_POST['param_id']) ? intval($_POST['param_id']) : 0;

        $result = $wpdb->delete($this->params_table, array( 'id' => $param_id ), array( '%d' ));

        if ($result === false) {
            if ($idem_request_id !== '') {
                Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
            }
            wp_send_json_error(array( 'message' => 'Ошибка при удалении параметра.' ));
            return;
        }

        $response_data = array( 'message' => 'Параметр удален.' );
        if ($idem_request_id !== '') {
            Cashback_Idempotency::store_result($idem_scope, $idem_user_id, $idem_request_id, $response_data);
        }

        wp_send_json_success($response_data);
    }

    /**
     * Синхронизирует статус публикации товаров при изменении активности сети.
     *
     * При деактивации: переводит опубликованные товары в черновик и помечает мета
     * _cashback_auto_unpublished = 1, чтобы при реактивации восстановить только их.
     * При активации: публикует товары, помеченные флагом _cashback_auto_unpublished.
     *
     * @param int $network_id  ID партнёрской сети.
     * @param int $is_active   Новое значение активности (1 — активна, 0 — неактивна).
     * @return int Количество затронутых товаров.
     */
    private function sync_products_visibility( int $network_id, int $is_active ): int {
        if ($is_active === 0) {
            // Деактивация: снимаем с публикации все опубликованные товары сети
            $products = get_posts(array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'     => '_affiliate_network_id',
                        'value'   => $network_id,
                        'compare' => '=',
                        'type'    => 'NUMERIC',
                    ),
                ),
            ));

            foreach ($products as $product_id) {
                update_post_meta($product_id, '_cashback_auto_unpublished', '1');
                wp_update_post(array(
                    'ID'          => $product_id,
                    'post_status' => 'draft',
                ));
            }

            return count($products);
        }

        // Активация: публикуем только товары, снятые автоматически
        $products = get_posts(array(
            'post_type'      => 'product',
            'post_status'    => array( 'draft', 'pending', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => '_affiliate_network_id',
                    'value'   => $network_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ),
                array(
                    'key'     => '_cashback_auto_unpublished',
                    'value'   => '1',
                    'compare' => '=',
                ),
            ),
        ));

        foreach ($products as $product_id) {
            delete_post_meta($product_id, '_cashback_auto_unpublished');
            wp_update_post(array(
                'ID'          => $product_id,
                'post_status' => 'publish',
            ));
        }

        return count($products);
    }

    /**
     * Обновление одного параметра партнерской сети
     */
    public function handle_update_network_param(): void {
        $nonce = isset($_POST['nonce']) && is_string($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'update_network_param_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        // Server-side дедуп request_id (Группа 5 ADR, F-35-006).
        $idem_scope      = 'admin_partner_param_update';
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
                    'message' => 'Запрос уже обрабатывается. Повторите через несколько секунд.',
                ), 409);
                return;
            }
        }

        global $wpdb;

        $param_id   = isset($_POST['param_id']) ? intval($_POST['param_id']) : 0;
        $param_name = isset($_POST['param_name']) && is_string($_POST['param_name']) ? sanitize_text_field(wp_unslash($_POST['param_name'])) : '';
        $param_type = isset($_POST['param_type']) && is_string($_POST['param_type']) ? sanitize_text_field(wp_unslash($_POST['param_type'])) : '';

        if (empty($param_name)) {
            if ($idem_request_id !== '') {
                Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
            }
            wp_send_json_error(array( 'message' => 'Название параметра обязательно.' ));
            return;
        }

        // F-2-001 п.4: whitelist (ASCII alpha-num + _/-, 1..32 симв.).
        if (!Cashback_Click_Session_Service::is_valid_affiliate_param_name($param_name)) {
            if ($idem_request_id !== '') {
                Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
            }
            wp_send_json_error(array(
                'message' => __('Название параметра содержит недопустимые символы. Разрешены только буквы, цифры, _ и - (1..32 символов).', 'cashback-plugin'),
            ));
            return;
        }

        $result = $wpdb->update(
            $this->params_table,
            array(
                'param_name' => $param_name,
                'param_type' => $param_type,
            ),
            array( 'id' => $param_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ($result === false) {
            if ($idem_request_id !== '') {
                Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
            }
            wp_send_json_error(array( 'message' => 'Ошибка при обновлении параметра.' ));
            return;
        }

        $response_data = array(
            'param_name' => $param_name,
            'param_type' => $param_type,
        );

        if ($idem_request_id !== '') {
            Cashback_Idempotency::store_result($idem_scope, $idem_user_id, $idem_request_id, $response_data);
        }

        wp_send_json_success($response_data);
    }
}

// Инициализируем класс
$partner_management_admin = new Cashback_Partner_Management_Admin();
