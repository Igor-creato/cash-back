<?php

declare(strict_types=1);

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

        $is_partners_page = in_array($hook, $allowed_hooks, true) ||
            ( isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-partners' );

        if (!$is_partners_page) {
            return;
        }

        wp_enqueue_script(
            'cashback-admin-partners',
            plugins_url('../assets/js/admin-partner-management.js', __FILE__),
            array( 'jquery' ),
            '1.0.1',
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
            wp_die(__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }

        global $wpdb;

        // Поисковый запрос
        $search_query = isset($_GET['partner_search']) ? sanitize_text_field(wp_unslash($_GET['partner_search'])) : '';
        $is_search    = !empty($search_query);

        // Фильтр по статусу is_active
        $filter_status = isset($_GET['filter_status']) ? sanitize_text_field(wp_unslash($_GET['filter_status'])) : '';
        $is_filtered   = ( $filter_status !== '' && $filter_status !== 'all' );

        // Пагинация: настройки
        $per_page     = 10;
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
            $total_partners = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name}{$where_clause}",
                    ...$where_values
                )
            );

            // Получаем партнеров с учётом фильтров и пагинации
            $query_values = array_merge($where_values, array( $per_page, $offset ));
            $partners     = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table_name}{$where_clause} ORDER BY sort_order ASC, name ASC LIMIT %d OFFSET %d",
                    ...$query_values
                ),
                ARRAY_A
            );
        } else {
            $total_partners = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE %d = %d", 1, 1));

            $partners = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table_name} ORDER BY sort_order ASC, name ASC LIMIT %d OFFSET %d",
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
                "SELECT id, name FROM {$this->table_name} WHERE is_active = %d ORDER BY sort_order ASC, name ASC",
                1
            ),
            ARRAY_A
        );

        // Выводим сообщения об ошибках или успехе
        $message = '';
        if (isset($_GET['message'])) {
            $msg_type = sanitize_text_field(wp_unslash($_GET['message']));
            if ($msg_type === 'added') {
                $message = '<div class="notice notice-success is-dismissible"><p>Партнер успешно добавлен.</p></div>';
            } elseif ($msg_type === 'updated') {
                $message = '<div class="notice notice-success is-dismissible"><p>Партнер успешно обновлен.</p></div>';
            }
        }

        // Определяем активную вкладку
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'partners';
        if (!in_array($active_tab, array( 'partners', 'params' ), true)) {
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
                                <th scope="col">Действия</th>
                            </tr>
                        </thead>
                        <tbody id="partners-tbody">
                            <?php if (!empty($partners)) : ?>
                                <?php foreach ($partners as $partner) : ?>
                                    <tr data-id="<?php echo esc_attr($partner['id']); ?>">
                                        <td class="edit-field" data-field="name"><?php echo esc_html($partner['name']); ?></td>
                                        <td class="edit-field" data-field="slug"><?php echo esc_html($partner['slug']); ?></td>
                                        <td class="edit-field" data-field="notes"><?php echo esc_html($partner['notes'] ?: ''); ?></td>
                                        <td class="edit-field" data-field="sort_order"><?php echo esc_html($partner['sort_order']); ?></td>
                                        <td class="edit-field" data-field="is_active">
                                            <?php echo $partner['is_active'] ? 'Да' : 'Нет'; ?>
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
                                    <td colspan="6">
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

                <?php if ($total_partners > $per_page) : ?>
                    <!-- Пагинация -->
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php
                                printf(
                                    '%s из %s',
                                    esc_html(number_format_i18n(count($partners))),
                                    esc_html(number_format_i18n($total_partners))
                                );
                                ?>
                            </span>
                            <?php
                            $pagination_base_args = array(
								'page'  => 'cashback-partners',
								'tab'   => 'partners',
								'paged' => '%#%',
							);
                            if ($is_search) {
                                $pagination_base_args['partner_search'] = $search_query;
                            }
                            if ($is_filtered) {
                                $pagination_base_args['filter_status'] = $filter_status;
                            }
                            $pagination_links = paginate_links(array(
                                'base'      => add_query_arg($pagination_base_args, admin_url('admin.php')),
                                'format'    => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total'     => $total_pages,
                                'current'   => $current_page,
                                'type'      => 'plain',
                            ));

                            if ($pagination_links) {
                                echo wp_kses_post($pagination_links);
                            }
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

        </div>
<?php
    }

    /**
     * Обработка AJAX запроса на обновление партнера
     */
    public function handle_update_partner(): void {
        // Проверяем nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'update_partner_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        global $wpdb;

        $id         = intval($_POST['id']);
        $name       = sanitize_text_field(wp_unslash($_POST['name']));
        $slug       = sanitize_title(wp_unslash($_POST['slug']));
        $notes      = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        $sort_order = intval($_POST['sort_order']);
        $is_active  = intval($_POST['is_active']);

        // Валидация данных
        if (empty($name) || empty($slug)) {
            wp_send_json_error(array( 'message' => 'Заполните все обязательные поля.' ));
            return;
        }

        // Читаем текущий статус активности до обновления
        $old_is_active = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT is_active FROM {$this->table_name} WHERE id = %d", $id)
        );

        // Обновляем запись в базе данных
        $result = $wpdb->update(
            $this->table_name,
            array(
                'name'       => $name,
                'slug'       => $slug,
                'notes'      => $notes,
                'sort_order' => $sort_order,
                'is_active'  => $is_active,
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%d', '%d' ),
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

        // Возвращаем обновленные данные
        wp_send_json_success(array(
            'id'                => $id,
            'name'              => $name,
            'slug'              => $slug,
            'notes'             => $notes,
            'sort_order'        => $sort_order,
            'is_active'         => $is_active,
            'products_affected' => $products_affected,
        ));
    }

    /**
     * Обработка AJAX запроса на добавление партнера
     */
    public function handle_add_partner(): void {
        // Проверяем nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'add_partner_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        global $wpdb;

        $name       = sanitize_text_field(wp_unslash($_POST['name']));
        $slug       = sanitize_title(wp_unslash($_POST['slug']));
        $notes      = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        $sort_order = intval($_POST['sort_order']);
        $is_active  = intval($_POST['is_active']);

        // Валидация данных
        if (empty($name) || empty($slug)) {
            wp_send_json_error(array( 'message' => 'Заполните все обязательные поля.' ));
            return;
        }

        // Проверяем, существует ли уже партнер с таким slug или name
        $existing = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE slug = %s OR name = %s",
                $slug,
                $name
            )
        );

        if ($existing > 0) {
            wp_send_json_error(array( 'message' => 'Партнер с таким названием или slug уже существует.' ));
            return;
        }

        // Добавляем новую запись в базу данных
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'name'       => $name,
                'slug'       => $slug,
                'notes'      => $notes,
                'sort_order' => $sort_order,
                'is_active'  => $is_active,
            ),
            array( '%s', '%s', '%s', '%d', '%d' )
        );

        if ($result === false) {
            wp_send_json_error(array( 'message' => 'Ошибка при добавлении партнера в базе данных.' ));
            return;
        }

        wp_send_json_success();
    }

    /**
     * Получение параметров партнерской сети
     */
    public function handle_get_network_params(): void {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'get_network_params_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        global $wpdb;

        $network_id = intval($_POST['network_id']);

        $params = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, param_name, param_type FROM {$this->params_table} WHERE network_id = %d ORDER BY id ASC",
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
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'save_network_params_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        global $wpdb;

        $network_id = intval($_POST['network_id']);

        if ($network_id <= 0) {
            wp_send_json_error(array( 'message' => 'Выберите партнерскую сеть.' ));
            return;
        }

        // Проверяем что сеть существует
        $network_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE id = %d",
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

        // Проверяем лимит с учетом существующих параметров
        $existing_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->params_table} WHERE network_id = %d",
                $network_id
            )
        );

        $new_count = 0;
        foreach ($param_names as $pn) {
            if (!empty($pn)) {
                ++$new_count;
            }
        }

        if (( $existing_count + $new_count ) > 10) {
            wp_send_json_error(array( 'message' => 'Максимальное количество параметров — 10. Сейчас уже ' . $existing_count . '.' ));
            return;
        }

        // Добавляем новые параметры
        $wpdb->query('START TRANSACTION');

        $insert_error = false;
        for ($i = 0; $i < count($param_names); $i++) {
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
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'delete_network_param_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        global $wpdb;

        $param_id = intval($_POST['param_id']);

        $result = $wpdb->delete($this->params_table, array( 'id' => $param_id ), array( '%d' ));

        if ($result === false) {
            wp_send_json_error(array( 'message' => 'Ошибка при удалении параметра.' ));
            return;
        }

        wp_send_json_success(array( 'message' => 'Параметр удален.' ));
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
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'update_network_param_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        global $wpdb;

        $param_id   = intval($_POST['param_id']);
        $param_name = sanitize_text_field(wp_unslash($_POST['param_name']));
        $param_type = isset($_POST['param_type']) ? sanitize_text_field(wp_unslash($_POST['param_type'])) : '';

        if (empty($param_name)) {
            wp_send_json_error(array( 'message' => 'Название параметра обязательно.' ));
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
            wp_send_json_error(array( 'message' => 'Ошибка при обновлении параметра.' ));
            return;
        }

        wp_send_json_success(array(
            'param_name' => $param_name,
            'param_type' => $param_type,
        ));
    }
}

// Инициализируем класс
$partner_management_admin = new Cashback_Partner_Management_Admin();
