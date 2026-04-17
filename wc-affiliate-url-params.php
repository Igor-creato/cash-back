<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC Affiliate URL Params
 *
 * Управление партнерскими параметрами URL для внешних товаров WooCommerce.
 * Параметры берутся из таблицы cashback_affiliate_network_params
 * на основе выбранной партнерской сети для товара.
 *
 * @since 2.0.0
 */
class WC_Affiliate_URL_Params {

    private const CACHE_GROUP      = 'wc_affiliate_url_params';
    private const CACHE_EXPIRATION = 3600;
    private const LOGGER_SOURCE    = 'wc-affiliate-url-params';

    /**
     * Rate limiting: двухуровневые ключи (без User-Agent).
     *
     * PER_PRODUCT — счётчик по IP + product_id.
     *   Ловит накрутку конкретного оффера.
     *
     * GLOBAL — счётчик по IP (без product_id).
     *   Ловит массовое кликание по разным товарам.
     *   Пороги выше из-за CGNAT (один IP = много пользователей).
     *
     * UA НЕ используется в ключах rate limit:
     *   - Боты меняют UA на каждый запрос → обход лимита.
     *   - CGNAT: разные UA с одного IP = разные ключи = фрагментация.
     * Bot detection через is_bot_user_agent() — отдельный слой.
     *
     * Три статуса: normal → spam (лог + флаг) → blocked (429, без лога).
     *
     * Для production рекомендуется Redis/Memcached object cache.
     */

    // --- Per-product лимиты (IP + product_id) ---
    private const RATE_PER_PRODUCT_SPAM  = 3;   // >3 кликов на один товар за 60с → spam
    private const RATE_PER_PRODUCT_BLOCK = 10;  // >=10 → blocked

    // --- Глобальные лимиты (IP, любые товары) ---
    private const RATE_GLOBAL_SPAM  = 10;  // >10 кликов суммарно за 60с → spam
    private const RATE_GLOBAL_BLOCK = 60;  // >=60 → blocked (CGNAT-safe)

    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    /**
     * Конструктор класса.
     *
     * @since 2.0.0
     */
    public function __construct() {
        // Хуки для добавления полей в админке
        add_action('woocommerce_product_options_general_product_data', array( $this, 'add_custom_fields' ));
        add_action('woocommerce_process_product_meta', array( $this, 'save_custom_fields' ));

        // Админ-уведомления (ошибки валидации при сохранении)
        add_action('admin_notices', array( $this, 'show_admin_notices' ));

        // Хуки для модификации URL на фронтенде
        add_filter('woocommerce_product_add_to_cart_url', array( $this, 'modify_external_url' ), 10, 2);

        // Добавляем data-product-id к ссылкам внешних товаров для JavaScript
        add_filter('woocommerce_loop_add_to_cart_link', array( $this, 'add_product_id_to_link' ), 10, 2);

        // Модифицируем кнопку внешнего товара на странице товара
        add_action('woocommerce_external_add_to_cart', array( $this, 'modify_single_product_button' ), 5);
        // Удаляем стандартный вывод кнопки, чтобы избежать дублирования
        remove_action('woocommerce_external_add_to_cart', 'woocommerce_external_add_to_cart', 30);

        // Подключение JS и CSS
        add_action('wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ));
        add_action('admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ));

        // Server-side redirect endpoint для логирования кликов.
        // Приоритет 1: перехватить ДО темы и WooCommerce, иначе single product template
        // перезапишет нашу промежуточную страницу.
        add_action('template_redirect', array( $this, 'handle_click_redirect' ), 1);

        // Отображение кэшбэка на карточках товаров и странице товара
        add_filter('woocommerce_get_price_html', array( $this, 'append_cashback_to_price' ), 10, 2);
        add_action('woocommerce_after_shop_loop_item_title', array( $this, 'display_cashback_standalone_loop' ), 15);
        add_action('woocommerce_single_product_summary', array( $this, 'display_cashback_single_product' ), 11);
    }

    /**
     * Добавление полей в Product Data метабокс.
     *
     * Отображает выпадающий список партнерских сетей и таблицу параметров
     * только для внешних товаров WooCommerce.
     *
     * @since 2.0.0
     *
     * @return void
     */
    public function add_custom_fields(): void {
        global $post, $wpdb;

        $product = wc_get_product($post->ID);

        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';
        $params_table   = $wpdb->prefix . 'cashback_affiliate_network_params';

        // Получаем все сети
        $networks = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, name, is_active FROM %i ORDER BY sort_order ASC, name ASC',
                $networks_table
            ),
            ARRAY_A
        );

        // Текущая выбранная сеть
        $selected_network_id = (int) get_post_meta($post->ID, '_affiliate_network_id', true);

        // Проверяем активность выбранной сети
        $selected_network_active = true;
        if ($selected_network_id > 0) {
            foreach ($networks as $network) {
                if ((int) $network['id'] === $selected_network_id) {
                    $selected_network_active = (bool) $network['is_active'];
                    break;
                }
            }
        }

        // Параметры выбранной сети (для начальной отрисовки)
        $network_params = array();
        if ($selected_network_id > 0) {
            $network_params = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id, param_name, param_type FROM %i WHERE network_id = %d ORDER BY id ASC',
                    $params_table,
                    $selected_network_id
                ),
                ARRAY_A
            ) ?: array();
        }

        echo '<div class="options_group show_if_external">';
        echo '<h4 style="padding: 10px 12px; margin: 0; border-bottom: 1px solid #ddd;">'
            . esc_html__('Партнерская сеть', 'wc-affiliate-url-params') . '</h4>';
        echo '<p class="description" style="padding: 5px 12px; color: #666; margin: 0;">'
            . wp_kses(
                __('В поле <b>Значение</b>: для подстановки ID пользователя введите <code>user</code>, для уникального идентификатора клика — <code>uuid</code>, иначе значение будет передано как есть.', 'wc-affiliate-url-params'),
                array(
					'b'    => array(),
					'code' => array(),
				)
            )
            . '</p>';

        // Выпадающий список сетей
        echo '<p class="form-field _affiliate_network_id_field" style="padding: 5px 12px;">';
        echo '<label for="_affiliate_network_id">' . esc_html__('Партнерская сеть', 'wc-affiliate-url-params') . '</label>';
        echo '<select id="_affiliate_network_id" name="_affiliate_network_id" class="select short">';
        echo '<option value="">' . esc_html__('Выберите сеть', 'wc-affiliate-url-params') . '</option>';

        foreach ($networks as $network) {
            $network_id  = (int) $network['id'];
            $is_active   = (bool) $network['is_active'];
            $is_selected = ( $network_id === $selected_network_id );

            // Неактивные сети: показываем только если они уже выбраны у товара
            if (!$is_active && !$is_selected) {
                continue;
            }

            $label = esc_html($network['name']);
            if (!$is_active) {
                $label .= ' (' . esc_html__('неактивна', 'wc-affiliate-url-params') . ')';
            }

            printf(
                '<option value="%d"%s>%s</option>',
                $network_id,
                selected($selected_network_id, $network_id, false),
                $label
            );
        }

        echo '</select>';
        echo '</p>';

        // Предупреждение если сеть отключена
        if ($selected_network_id > 0 && !$selected_network_active) {
            echo '<div class="affiliate-network-warning" style="padding: 8px 12px; margin: 5px 12px; background: #fff3cd; border-left: 4px solid #d63638; color: #856404;">';
            echo esc_html__('Сеть отключена. Товар будет переведен в статус "На утверждении".', 'wc-affiliate-url-params');
            echo '</div>';
        }

        // Offer ID (ID кампании в CPA-сети)
        $current_offer_id = get_post_meta($post->ID, '_offer_id', true);
        echo '<p class="form-field _offer_id_field" style="padding: 5px 12px;">';
        echo '<label for="_offer_id">' . esc_html__('Offer ID (ID кампании)', 'wc-affiliate-url-params') . '</label>';
        printf(
            '<input type="text" id="_offer_id" name="_offer_id" value="%s" class="short" placeholder="%s" />',
            esc_attr($current_offer_id),
            esc_attr__('advcampaign_id / offer_id в CPA-сети', 'wc-affiliate-url-params')
        );
        echo '<span class="description" style="display:block; margin-top:4px; color:#666;">'
            . esc_html__('ID кампании/оффера в CPA-сети. Используется для автоматической деактивации при отключении кампании.', 'wc-affiliate-url-params')
            . '</span>';
        echo '</p>';

        // Индикатор автоматической деактивации
        $auto_deactivated = get_post_meta($post->ID, '_cashback_auto_deactivated', true);
        if ($auto_deactivated === '1') {
            $deactivation_reason = get_post_meta($post->ID, '_cashback_deactivation_reason', true);
            $deactivated_at      = get_post_meta($post->ID, '_cashback_deactivated_at', true);
            echo '<div class="affiliate-network-warning" style="padding: 8px 12px; margin: 5px 12px; background: #fce4ec; border-left: 4px solid #d63638; color: #c62828;">';
            echo '<strong>' . esc_html__('Магазин автоматически деактивирован', 'wc-affiliate-url-params') . '</strong><br>';
            if ($deactivated_at) {
                echo esc_html(sprintf(__('Дата: %s', 'wc-affiliate-url-params'), $deactivated_at)) . '<br>';
            }
            if ($deactivation_reason) {
                echo esc_html(sprintf(__('Причина: %s', 'wc-affiliate-url-params'), $deactivation_reason));
            }
            echo '</div>';
        }

        // Контейнер для параметров сети
        echo '<div id="affiliate-network-params-container">';
        if (!empty($network_params)) {
            $this->render_network_params_table($network_params);
        }
        echo '</div>';

        echo '</div>';

        // Индивидуальные параметры товара
        $product_params = get_post_meta($post->ID, '_affiliate_product_params', true);
        if (!is_array($product_params)) {
            $product_params = array();
        }

        echo '<div class="options_group show_if_external">';
        echo '<h4 style="padding: 10px 12px; margin: 0; border-bottom: 1px solid #ddd;">'
            . esc_html__('Индивидуальные параметры товара', 'wc-affiliate-url-params') . '</h4>';
        echo '<p class="description" style="padding: 5px 12px; color: #666; margin: 0;">'
            . esc_html__('Добавляются только к URL этого товара. При совпадении ключа с параметром сети — используется значение товара.', 'wc-affiliate-url-params')
            . '</p>';

        echo '<div id="affiliate-product-params-container" style="padding: 10px 12px;">';

        // Заголовки колонок (без рамки)
        echo '<div class="product-params-labels" style="display: flex; gap: 10px; margin-bottom: 6px;">';
        echo '<span style="flex: 1; font-weight: 600; font-size: 13px;">' . esc_html__('Параметр', 'wc-affiliate-url-params') . '</span>';
        echo '<span style="flex: 1; font-weight: 600; font-size: 13px;">' . esc_html__('Значение', 'wc-affiliate-url-params') . '</span>';
        echo '<span style="width: 36px;"></span>';
        echo '</div>';

        // Контейнер для строк параметров
        echo '<div id="affiliate-product-params-rows">';

        foreach ($product_params as $pp) {
            $key   = isset($pp['key']) ? esc_attr($pp['key']) : '';
            $value = isset($pp['value']) ? esc_attr($pp['value']) : '';
            echo '<div class="product-param-row" style="display: flex; gap: 10px; margin-bottom: 6px; align-items: center;">';
            echo '<input type="text" name="affiliate_product_param_key[]" value="' . $key . '" class="regular-text" placeholder="param_key" pattern="[a-zA-Z0-9_\-]+" title="' . esc_attr__('Только латиница, цифры, _ и -', 'wc-affiliate-url-params') . '" style="flex:1;" />';
            echo '<input type="text" name="affiliate_product_param_value[]" value="' . $value . '" class="regular-text" placeholder="user / uuid / значение" style="flex:1;" />';
            echo '<button type="button" class="button button-small remove-product-param-btn" style="color:#a00; min-width:36px;" title="' . esc_attr__('Удалить', 'wc-affiliate-url-params') . '">&times;</button>';
            echo '</div>';
        }

        echo '</div>';

        echo '<p style="margin-top: 8px;">';
        echo '<button type="button" id="add-product-param-row" class="button button-small"'
            . ( count($product_params) >= 5 ? ' disabled' : '' ) . '>'
            . esc_html__('+ Добавить параметр', 'wc-affiliate-url-params') . '</button>';
        echo '<span class="description" style="margin-left: 8px;">'
            . esc_html__('Макс. 5 параметров.', 'wc-affiliate-url-params')
            . '</span>';
        echo '</p>';
        echo '</div>';

        echo '</div>';

        // Отображение кэшбэка на карточке товара
        echo '<div class="options_group show_if_external">';
        echo '<h4 style="padding: 10px 12px; margin: 0; border-bottom: 1px solid #ddd;">'
            . esc_html__('Отображение кэшбэка', 'wc-affiliate-url-params') . '</h4>';
        echo '<p class="description" style="padding: 5px 12px; color: #666; margin: 0;">'
            . esc_html__('Отображается на карточке товара под ценой или вместо цены, если цена не указана.', 'wc-affiliate-url-params')
            . '</p>';

        woocommerce_wp_text_input(array(
            'id'          => '_store_domain',
            'label'       => __('Домен магазина', 'wc-affiliate-url-params'),
            'description' => __('Домен для браузерного расширения (напр. aliexpress.com). Заполняется автоматически из URL товара.', 'wc-affiliate-url-params'),
            'desc_tip'    => true,
            'placeholder' => 'aliexpress.com',
            'type'        => 'text',
        ));

        woocommerce_wp_select(array(
            'id'          => '_store_popup_mode',
            'label'       => __('Всплывающее окно', 'wc-affiliate-url-params'),
            'description' => __('Показывать ли всплывающее уведомление в браузерном расширении.', 'wc-affiliate-url-params'),
            'desc_tip'    => true,
            'value'       => get_post_meta($post->ID, '_store_popup_mode', true),
            'options'     => array(
                ''     => __('— Выберите показывать ли всплывающее окно —', 'wc-affiliate-url-params'),
                'show' => __('Показывать', 'wc-affiliate-url-params'),
                'hide' => __('Не показывать', 'wc-affiliate-url-params'),
            ),
        ));

        woocommerce_wp_text_input(array(
            'id'          => '_cashback_display_label',
            'label'       => __('Текст метки', 'wc-affiliate-url-params'),
            'description' => __('По умолчанию: Кэшбэк', 'wc-affiliate-url-params'),
            'desc_tip'    => true,
            'placeholder' => 'Кэшбэк',
            'type'        => 'text',
        ));

        woocommerce_wp_text_input(array(
            'id'          => '_cashback_display_value',
            'label'       => __('Размер кэшбэка', 'wc-affiliate-url-params'),
            'description' => __('Например: до 81%, 388р., до 800р.', 'wc-affiliate-url-params'),
            'desc_tip'    => true,
            'placeholder' => 'до 81%',
            'type'        => 'text',
        ));

        echo '</div>';
    }

    /**
     * Отрисовка таблицы параметров сети.
     *
     * @since 2.0.0
     *
     * @param array $params Массив параметров из cashback_affiliate_network_params.
     *
     * @return void
     */
    private function render_network_params_table( array $params ): void {
        if (empty($params)) {
            echo '<p style="padding: 5px 12px; color: #666; font-style: italic;">'
                . esc_html__('У этой сети нет настроенных параметров.', 'wc-affiliate-url-params') . '</p>';
            return;
        }

        echo '<table class="affiliate-network-params-table widefat" style="margin: 10px 12px; width: calc(100% - 24px);">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Параметр', 'wc-affiliate-url-params') . '</th>';
        echo '<th>' . esc_html__('Значение', 'wc-affiliate-url-params') . '</th>';
        echo '<th style="width: 220px;">' . esc_html__('Действия', 'wc-affiliate-url-params') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($params as $param) {
            printf(
                '<tr data-param-id="%d">'
                    . '<td class="param-cell" data-field="param_name">%s</td>'
                    . '<td class="param-cell" data-field="param_type">%s</td>'
                    . '<td>'
                    . '<button type="button" class="button button-small affiliate-param-edit-btn">%s</button> '
                    . '<button type="button" class="button button-small affiliate-param-save-btn" style="display:none;">%s</button> '
                    . '<button type="button" class="button button-small affiliate-param-cancel-btn" style="display:none;">%s</button> '
                    . '<button type="button" class="button button-small affiliate-param-delete-btn" style="color:#a00;">%s</button>'
                    . '</td></tr>',
                (int) $param['id'],
                esc_html($param['param_name']),
                esc_html($param['param_type'] ?? ''),
                esc_html__('Редактировать', 'wc-affiliate-url-params'),
                esc_html__('Сохранить', 'wc-affiliate-url-params'),
                esc_html__('Отмена', 'wc-affiliate-url-params'),
                esc_html__('Удалить', 'wc-affiliate-url-params')
            );
        }

        echo '</tbody></table>';
    }

    /**
     * Сохранение выбранной партнерской сети для товара.
     *
     * @since 2.0.0
     *
     * @param int $post_id ID товара.
     *
     * @return void
     */
    public function save_custom_fields( int $post_id ): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_product', $post_id)) {
            return;
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'update-post_' . $post_id)) {
            return;
        }

        $product     = wc_get_product($post_id);
        $is_external = $product && $product->get_type() === 'external';

        // Fallback to POST data for new products where type may not be resolved yet
        if (!$is_external && !empty($_POST['product-type'])) {
            $is_external = sanitize_text_field(wp_unslash($_POST['product-type'])) === 'external';
        }

        if (!$is_external) {
            return;
        }

        $network_id = isset($_POST['_affiliate_network_id'])
            ? absint($_POST['_affiliate_network_id'])
            : 0;

        // Валидация: сеть обязательна для внешних товаров
        if ($network_id === 0) {
            set_transient(
                'cashback_affiliate_network_error_' . get_current_user_id(),
                __('Выберите партнерскую сеть', 'wc-affiliate-url-params'),
                30
            );
            return;
        }

        // Сохраняем ID сети
        update_post_meta($post_id, '_affiliate_network_id', $network_id);

        // Сохраняем Offer ID (ID кампании в CPA-сети)
        $offer_id = isset($_POST['_offer_id'])
            ? sanitize_text_field(wp_unslash($_POST['_offer_id']))
            : '';
        update_post_meta($post_id, '_offer_id', $offer_id);

        // Очищаем кэш
        wp_cache_delete('affiliate_params_' . $post_id, self::CACHE_GROUP);

        // Индивидуальные параметры товара
        $param_keys   = isset($_POST['affiliate_product_param_key']) && is_array($_POST['affiliate_product_param_key'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['affiliate_product_param_key']))
            : array();
        $param_values = isset($_POST['affiliate_product_param_value']) && is_array($_POST['affiliate_product_param_value'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['affiliate_product_param_value']))
            : array();

        $product_params     = array();
        $max_product_params = 5;

        for ($i = 0; $i < min(count($param_keys), $max_product_params); $i++) {
            $key   = trim($param_keys[ $i ]);
            $value = trim($param_values[ $i ]);

            if ($key === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $key)) {
                continue;
            }

            $product_params[] = array(
                'key'   => $key,
                'value' => $value,
            );
        }

        update_post_meta($post_id, '_affiliate_product_params', $product_params);

        // Домен магазина для браузерного расширения
        $store_domain = isset($_POST['_store_domain'])
            ? sanitize_text_field(wp_unslash($_POST['_store_domain']))
            : '';

        // Автозаполнение из product URL если поле пустое (только для внешних товаров)
        if (empty($store_domain) && $product && $product instanceof \WC_Product_External) {
            $product_url = $product->get_product_url();
            if ($product_url) {
                $parsed       = wp_parse_url($product_url);
                $store_domain = $parsed['host'] ?? '';
            }
        }

        // Нормализация: удаляем www., приводим к нижнему регистру
        $store_domain = strtolower(preg_replace('/^www\./i', '', $store_domain));
        update_post_meta($post_id, '_store_domain', $store_domain);

        // Всплывающее окно браузерного расширения
        $popup_mode = isset($_POST['_store_popup_mode'])
            ? sanitize_text_field(wp_unslash($_POST['_store_popup_mode']))
            : '';

        // Валидация: если домен задан, а режим не выбран — не даём опубликовать
        if (!empty($store_domain) && !in_array($popup_mode, array( 'show', 'hide' ), true)) {
            set_transient(
                'cashback_popup_mode_error_' . get_current_user_id(),
                __('Выберите показывать или не показывать всплывающее окно.', 'wc-affiliate-url-params'),
                30
            );
            // Откатываем статус если товар стал publish
            if (get_post_status($post_id) === 'publish') {
                wp_update_post(array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				));
            }
        } else {
            update_post_meta($post_id, '_store_popup_mode', $popup_mode);
        }

        // Сбрасываем кеш списка магазинов для браузерного расширения
        delete_transient('cashback_ext_stores_cache');

        // Кэшбэк для отображения на карточке товара
        $cashback_label = isset($_POST['_cashback_display_label'])
            ? sanitize_text_field(wp_unslash($_POST['_cashback_display_label']))
            : '';
        $cashback_value = isset($_POST['_cashback_display_value'])
            ? sanitize_text_field(wp_unslash($_POST['_cashback_display_value']))
            : '';

        update_post_meta($post_id, '_cashback_display_label', $cashback_label);
        update_post_meta($post_id, '_cashback_display_value', $cashback_value);

        // Проверяем активность выбранной сети
        global $wpdb;
        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';
        $is_active      = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT is_active FROM %i WHERE id = %d',
            $networks_table,
            $network_id
        ));

        if ($is_active === 0) {
            // Переводим товар в статус "На утверждении"
            wp_update_post(array(
                'ID'          => $post_id,
                'post_status' => 'pending',
            ));

            set_transient(
                'cashback_affiliate_network_warning_' . get_current_user_id(),
                __('Сеть отключена. Товар переведен в статус "На утверждении".', 'wc-affiliate-url-params'),
                30
            );
        }

        // Удаляем старые мета-ключи (миграция со старого формата)
        for ($i = 1; $i <= 3; $i++) {
            delete_post_meta($post_id, "_affiliate_param_{$i}_key");
            delete_post_meta($post_id, "_affiliate_param_{$i}_value");
        }
    }

    /**
     * Отображение админ-уведомлений (ошибки валидации, предупреждения).
     *
     * @since 2.0.0
     *
     * @return void
     */
    public function show_admin_notices(): void {
        $user_id = get_current_user_id();

        // Ошибка: режим всплывающего окна не выбран
        $popup_error = get_transient('cashback_popup_mode_error_' . $user_id);
        if ($popup_error) {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html($popup_error)
            );
            delete_transient('cashback_popup_mode_error_' . $user_id);
        }

        // Ошибка: сеть не выбрана
        $error = get_transient('cashback_affiliate_network_error_' . $user_id);
        if ($error) {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html($error)
            );
            delete_transient('cashback_affiliate_network_error_' . $user_id);
        }

        // Предупреждение: сеть отключена
        $warning = get_transient('cashback_affiliate_network_warning_' . $user_id);
        if ($warning) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                esc_html($warning)
            );
            delete_transient('cashback_affiliate_network_warning_' . $user_id);
        }
    }

    /**
     * Модификация URL внешнего товара с добавлением партнерских параметров.
     *
     * Параметры берутся из cashback_affiliate_network_params для выбранной сети.
     * param_name становится ключом URL-параметра, param_type — значением.
     * Специальное значение "user" подставляет ID текущего пользователя.
     *
     * @since 2.0.0
     *
     * @param string     $url     Исходный URL товара.
     * @param WC_Product $product Объект товара WooCommerce.
     *
     * @return string Модифицированный URL с партнерскими параметрами.
     */
    public function modify_external_url( string $url, WC_Product $product ): string {
        if ($product->get_type() !== 'external') {
            return $url;
        }

        $product_id = $product->get_id();

        // Проверяем кэш
        $cache_key     = 'affiliate_params_' . $product_id;
        $cached_params = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (false === $cached_params) {
            $cached_params = $this->get_affiliate_params($product_id);
            wp_cache_set($cache_key, $cached_params, self::CACHE_GROUP, self::CACHE_EXPIRATION);
        }

        // Всегда маршрутизируем через server-side redirect endpoint.
        // Это необходимо для:
        //   1. Логирования клика с уникальным click_id (даже если параметры сети не настроены).
        //   2. Работы перехватчика кликов в браузерном расширении (ищет cashback_click= в href).
        // build_final_affiliate_url() обработает отсутствие параметров — вернёт исходный URL товара.
        return home_url('/?cashback_click=' . $product_id);
    }

    /**
     * Получение партнерских параметров из БД для товара.
     *
     * @since 2.0.0
     *
     * @param int $product_id ID товара.
     *
     * @return array Массив партнерских параметров [ i => ['key' => param_name, 'value' => param_type] ].
     */
    private function get_affiliate_params( int $product_id ): array {
        global $wpdb;

        $network_id = (int) get_post_meta($product_id, '_affiliate_network_id', true);
        if ($network_id <= 0) {
            return array();
        }

        // Проверяем активность сети
        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';
        $is_active      = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT is_active FROM %i WHERE id = %d',
            $networks_table,
            $network_id
        ));

        if ($is_active === 0) {
            return array();
        }

        $params_table = $wpdb->prefix . 'cashback_affiliate_network_params';
        $rows         = $wpdb->get_results($wpdb->prepare(
            'SELECT param_name, param_type FROM %i WHERE network_id = %d ORDER BY id ASC',
            $params_table,
            $network_id
        ), ARRAY_A);

        $params = array();
        if (!empty($rows)) {
            foreach ($rows as $i => $row) {
                $params[ $i ] = array(
                    'key'   => $row['param_name'],
                    'value' => $row['param_type'],
                );
            }
        }

        // Мерж индивидуальных параметров товара
        $product_params = get_post_meta($product_id, '_affiliate_product_params', true);
        if (is_array($product_params) && !empty($product_params)) {
            $key_to_index = array();
            foreach ($params as $idx => $p) {
                $key_to_index[ $p['key'] ] = $idx;
            }

            foreach ($product_params as $pp) {
                if (empty($pp['key'])) {
                    continue;
                }

                if (isset($key_to_index[ $pp['key'] ])) {
                    // Переопределяем значение сетевого параметра
                    $params[ $key_to_index[ $pp['key'] ] ]['value'] = $pp['value'];
                } else {
                    // Добавляем новый параметр
                    $params[] = array(
                        'key'   => $pp['key'],
                        'value' => $pp['value'],
                    );
                }
            }
        }

        return $params;
    }

    /**
     * Server-side redirect: генерация click_id, логирование, 302 redirect.
     *
     * Работает через query parameter ?cashback_click={product_id}.
     * Не зависит от rewrite rules — работает на Apache, Nginx, любом сервере.
     *
     * При любой ошибке пользователь всё равно получает redirect.
     * Лучше потерять лог клика, чем потерять пользователя.
     *
     * @since 4.0.0
     *
     * @return void
     */
    public function handle_click_redirect(): void {
        // Шаг 2: финальный redirect с activation page → affiliate URL.
        // Браузерное расширение уже зафиксировало активацию по cookie на шаге 1.
        if (isset($_GET['cashback_go']) && isset($_GET['click_id'])) {
            $this->handle_activation_page();
            return;
        }

        if (!isset($_GET['cashback_click'])) {
            return;
        }

        $product_id = absint($_GET['cashback_click']);
        if ($product_id <= 0) {
            return;
        }

        // Запрещаем кеширование (click_id уникален каждый раз)
        nocache_headers();

        try {
            $product = wc_get_product($product_id);
            if (!$product || $product->get_type() !== 'external') {
                wp_redirect(home_url(), 302);
                exit;
            }

            // Блокировка кликов для автоматически деактивированных магазинов
            if (get_post_meta($product_id, '_cashback_auto_deactivated', true) === '1') {
                error_log(sprintf(
                    '[wc-affiliate-url-params] Click blocked for auto-deactivated product #%d',
                    $product_id
                ));
                wp_redirect(get_permalink($product_id) ?: home_url(), 302);
                exit;
            }

            $fallback_url = $product->get_product_url();
            if (empty($fallback_url)) {
                wp_redirect(home_url(), 302);
                exit;
            }

            // Генерация click_id через UUID v7 (time-ordered, лучшая индексация в БД)
            $click_id = cashback_generate_uuid7(false);

            // Валидация: 32 hex символа
            if (!ctype_xdigit($click_id) || strlen($click_id) !== 32) {
                error_log('[wc-affiliate-url-params] click_id validation failed: ' . $click_id);
                wp_redirect($fallback_url, 302);
                exit;
            }

            // Контекст пользователя
            $user_id    = get_current_user_id(); // 0 для гостей
            $session_id = $this->get_session_id();

            // Построение финального affiliate URL
            $affiliate_url = $this->build_final_affiliate_url($product_id, $user_id, $click_id);
            if (empty($affiliate_url)) {
                $affiliate_url = $fallback_url;
            }

            // CPA-сеть
            $cpa_network = $this->get_network_slug_for_product($product_id);

            // Метаданные запроса
            $ip_address = Cashback_Encryption::get_client_ip();
            $user_agent = isset($_SERVER['HTTP_USER_AGENT'])
                ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
                : null;
            // Всегда записываем permalink одиночного товара как referer,
            // чтобы в логе кликов отображалась ссылка на страницу товара
            // независимо от того, откуда был клик (каталог, поиск и т.д.).
            $referer = get_permalink($product_id) ?: null;

            // ─── BOT DETECTION (мягкий режим) ───
            // Redirect проходит, но клик помечается spam в БД.
            // Для жёсткого режима (403) — замени на блок ниже.
            $force_spam = $this->is_bot_user_agent($user_agent ?? '');

            // Жёсткий режим (403, бот не получает redirect):
            // if ($this->is_bot_user_agent($user_agent ?? '')) {
            //     if (defined('WP_DEBUG') && WP_DEBUG) {
            //         error_log(sprintf(
            //             '[wc-affiliate-url-params] Bot detected: IP=%s UA=%s product=%d',
            //             $ip_address,
            //             $user_agent ?? 'empty',
            //             $product_id
            //         ));
            //     }
            //     status_header(403);
            //     nocache_headers();
            //     exit;
            // }

            // ─── Rate Limiting: 2 уровня по IP (без UA) ───
            $rate_status = $this->get_click_rate_status($ip_address, $product_id);

            // Bot detection повышает статус до spam (но не до blocked)
            if ($force_spam && $rate_status === 'normal') {
                $rate_status = 'spam';
            }

            // blocked → 429, защита от DDoS и бана CPA (без записи в БД)
            if ($rate_status === 'blocked') {
                status_header(429);
                nocache_headers();
                exit;
            }

            // Логирование клика в БД (ошибка НЕ блокирует редирект)
            $this->log_click_to_db(array(
                'click_id'      => $click_id,
                'user_id'       => $user_id,
                'session_id'    => $session_id,
                'product_id'    => $product_id,
                'cpa_network'   => $cpa_network,
                'affiliate_url' => $affiliate_url,
                'ip_address'    => $ip_address,
                'user_agent'    => $user_agent,
                'referer'       => $referer,
                'spam_click'    => $rate_status === 'spam' ? 1 : 0,
            ));

            // Устанавливаем cookie для браузерного расширения.
            // chrome.cookies API расширения читает этот cookie на каждое событие onUpdated
            // и сохраняет активацию в chrome.storage.session — независимо от состояния SW,
            // кеша userId и SameSite-ограничений WP auth cookie в cross-origin fetch.
            //
            // Домен из _store_domain meta (НЕ из affiliate_url, который указывает на CPA-сеть).
            $raw_domain  = (string) get_post_meta($product_id, '_store_domain', true);
            $dest_domain = preg_replace('#^https?://#i', '', $raw_domain);
            $dest_domain = preg_replace('#^www\.#i', '', $dest_domain);
            $dest_domain = strtolower(explode('/', $dest_domain)[0]);

            if (!empty($dest_domain)) {
                setcookie(
                    'cb_activation',
                    (string) wp_json_encode(array(
                        'click_id' => $click_id,
                        'domain'   => $dest_domain,
                        'ts'       => time(),
                    )),
                    array(
                        'expires'  => time() + 1800,
                        'path'     => '/',
                        'secure'   => is_ssl(),
                        'httponly' => false,
                        'samesite' => 'Lax',
                    )
                );
            }

            // Гости (неавторизованные) — моментальный редирект без промежуточной страницы.
            // Кешбэк не начисляется, задержка не нужна.
            if ($user_id === 0) {
                wp_redirect($affiliate_url, 302);
                exit;
            }

            // Авторизованные: промежуточная страница с 5-секундным счётчиком,
            // чтобы браузерное расширение зафиксировало активацию кешбэка.
            // URL через home_url() — НЕ через permalink товара, иначе WooCommerce
            // перехватывает запрос как single product page и ломает standalone HTML.
            $activation_page_url = add_query_arg(
                array(
					'cashback_go' => '1',
					'click_id'    => $click_id,
				),
                home_url('/')
            );
            wp_redirect($activation_page_url, 302);
            exit;
        } catch (\Throwable $e) {
            // Лучше потерять лог клика, чем потерять пользователя
            error_log('[wc-affiliate-url-params] Redirect error: ' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine());

            try {
                $product = wc_get_product($product_id);
                $url     = ( $product && $product->get_type() === 'external' )
                    ? $product->get_product_url()
                    : home_url();
            } catch (\Throwable $e2) {
                $url = home_url();
            }

            wp_redirect($url ?: home_url(), 302);
            exit;
        }
    }

    /**
     * Промежуточная страница активации кэшбэка.
     *
     * Полностью standalone HTML — без wp_head()/wp_footer(), без шаблонов темы.
     * Это исключает конфликты с JS/CSS темы и WooCommerce single product template.
     *
     * Вызывается когда браузер приходит на ?cashback_go=1&click_id={id}.
     * К этому моменту клик уже записан в cashback_click_log.
     *
     * 1. Content script расширения прочитал data-cb-activation и уведомил service worker
     * 2. Service worker сохранил активацию до перехода на партнёрский сайт
     * 3. Иконка расширения стала зелёной к моменту загрузки магазина
     *
     * Без расширения: автоматический redirect через JavaScript за 5 секунд.
     *
     * @since 4.1.0
     *
     * @return void
     */
    private function handle_activation_page(): void {
        $click_id = sanitize_text_field(wp_unslash($_GET['click_id'] ?? ''));

        // Валидация: ровно 32 hex-символа (bin2hex(random_bytes(16)))
        if (!ctype_xdigit($click_id) || strlen($click_id) !== 32) {
            wp_redirect(home_url(), 302);
            exit;
        }

        // Гости — моментальный редирект (страница только для авторизованных)
        if (!is_user_logged_in()) {
            global $wpdb;
            $table = $wpdb->prefix . 'cashback_click_log';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table, no cache needed for single redirect lookup.
            $row = $wpdb->get_var($wpdb->prepare(
                'SELECT affiliate_url FROM %i WHERE click_id = %s LIMIT 1',
                $table,
                $click_id
            ));
            $url = ( $row && in_array(parse_url($row, PHP_URL_SCHEME), array( 'http', 'https' ), true) )
                ? $row
                : home_url();
            wp_redirect($url, 302);
            exit;
        }

        nocache_headers();

        global $wpdb;
        $table = $wpdb->prefix . 'cashback_click_log';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table, no cache needed for activation page lookup.
        $click = $wpdb->get_row($wpdb->prepare(
            'SELECT affiliate_url, product_id FROM %i WHERE click_id = %s LIMIT 1',
            $table,
            $click_id
        ), ARRAY_A);

        $affiliate_url = '';
        if (!empty($click['affiliate_url'])) {
            $scheme = parse_url($click['affiliate_url'], PHP_URL_SCHEME);
            if (in_array($scheme, array( 'http', 'https' ), true)) {
                $affiliate_url = $click['affiliate_url'];
            }
        }

        if (empty($affiliate_url)) {
            wp_redirect(home_url(), 302);
            exit;
        }

        // Название магазина из post_title
        $store_name = '';
        if (!empty($click['product_id'])) {
            $store_name = get_the_title((int) $click['product_id']);
        }

        // Домен магазина из _store_domain meta (не из affiliate URL)
        $store_domain = '';
        if (!empty($click['product_id'])) {
            $raw_domain   = (string) get_post_meta((int) $click['product_id'], '_store_domain', true);
            $store_domain = preg_replace('#^https?://#i', '', $raw_domain);
            $store_domain = preg_replace('#^www\.#i', '', $store_domain);
            $store_domain = strtolower(explode('/', $store_domain)[0]);
        }

        // JSON для браузерного расширения (content script читает data-cb-activation)
        $activation_data = esc_attr((string) wp_json_encode(array(
            'domain'   => $store_domain,
            'click_id' => $click_id,
        )));

        $safe_redirect_url = esc_url($affiliate_url);
        // wp_json_encode() для JS-контекста: экранирует кавычки и спецсимволы,
        // но НЕ кодирует & в &amp; (в отличие от esc_js(), который использует htmlspecialchars).
        $safe_js_url = wp_json_encode($affiliate_url);
        $charset     = esc_attr(get_bloginfo('charset'));
        $lang_attr   = get_language_attributes();
        $site_name   = esc_html(get_bloginfo('name'));

        // Логотип сайта: custom_logo → site_icon → fallback эмодзи
        $logo_html      = '';
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $logo_url = wp_get_attachment_image_url($custom_logo_id, 'medium');
            if ($logo_url) {
                $logo_alt  = get_post_meta($custom_logo_id, '_wp_attachment_image_alt', true);
                $logo_html = '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($logo_alt ?: $site_name) . '">';
            }
        }
        if (empty($logo_html)) {
            $site_icon_id = get_option('site_icon');
            if ($site_icon_id) {
                $icon_url = wp_get_attachment_image_url((int) $site_icon_id, 'full');
                if ($icon_url) {
                    $logo_html = '<img src="' . esc_url($icon_url) . '" alt="' . esc_attr($site_name) . '">';
                }
            }
        }
        if (empty($logo_html)) {
            $logo_html = '&#128176;';
        }
        $store_name_esc = esc_html($store_name);

        // Текстовые строки
        $text_heading   = esc_html__('Переход в магазин', 'cashback-plugin');
        $text_activated = esc_html__('Кэшбэк активируется через:', 'cashback-plugin');
        $text_redirect  = esc_html__('Вы будете перенаправлены через', 'cashback-plugin');
        $text_sec       = esc_html__('сек.', 'cashback-plugin');
        $text_go_now    = esc_html__('Перейти сейчас', 'cashback-plugin');

        // Favicon из Site Icon (Customizer → Site Identity → Иконка сайта)
        $favicon_html = '';
        $site_icon_id = get_option('site_icon');
        if ($site_icon_id) {
            $icon_32  = wp_get_attachment_image_url((int) $site_icon_id, array( 32, 32 ));
            $icon_180 = wp_get_attachment_image_url((int) $site_icon_id, array( 180, 180 ));
            if ($icon_32) {
                $favicon_html .= '<link rel="icon" href="' . esc_url($icon_32) . '" sizes="32x32">';
            }
            if ($icon_180) {
                $favicon_html .= '<link rel="apple-touch-icon" href="' . esc_url($icon_180) . '">';
            }
        }

        // Полностью standalone HTML — никаких wp_head()/wp_footer().
        // Тема и WooCommerce не могут ничего внедрить.
        echo <<<HTML
<!DOCTYPE html>
<html {$lang_attr} data-cb-activation="{$activation_data}">
<head>
<meta charset="{$charset}">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
{$favicon_html}
<title>{$text_heading} — {$site_name}</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{
    height:100%;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;
    font-size:15px;
    line-height:1.6;
    color:#333;
    background:#f7f7f7;
    -webkit-font-smoothing:antialiased;
    -moz-osx-font-smoothing:grayscale;
}
body{
    display:flex;
    align-items:center;
    justify-content:center;
}
.cb-activation{
    text-align:center;
    padding:48px 32px;
    max-width:440px;
    width:90%;
    background:#fff;
    border-radius:16px;
    box-shadow:0 2px 24px rgba(0,0,0,.06);
}
.cb-activation__icon{
    margin:0 auto 20px;
    display:flex;
    align-items:center;
    justify-content:center;
    line-height:1;
}
.cb-activation__icon img{
    max-height:48px;
    width:auto;
    object-fit:contain;
}
.cb-activation__heading{
    font-size:22px;
    font-weight:600;
    color:#1a1a1a;
    margin:0 0 6px;
}
.cb-activation__store{
    font-size:14px;
    color:#888;
    margin:0 0 24px;
}
.cb-activation__status{
    display:none;
    align-items:center;
    justify-content:center;
    gap:6px;
    font-size:14px;
    font-weight:500;
    color:#2e7d32;
    margin-bottom:12px;
}
.cb-activation__status.visible{
    display:flex;
}
.cb-activation__status svg{
    flex-shrink:0;
}
.cb-activation__timer{
    position:relative;
    width:72px;height:72px;
    margin:0 auto 12px;
}
.cb-activation__timer svg{
    width:72px;height:72px;
    transform:rotate(-90deg);
}
.cb-activation__timer-bg{
    fill:none;
    stroke:#e0e0e0;
    stroke-width:3;
}
.cb-activation__timer-progress{
    fill:none;
    stroke:#4caf50;
    stroke-width:3;
    stroke-linecap:round;
    stroke-dasharray:201.06;
    stroke-dashoffset:0;
    transition:stroke-dashoffset 1s linear;
}
.cb-activation__timer-text{
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:24px;
    font-weight:700;
    color:#4caf50;
    font-variant-numeric:tabular-nums;
}
.cb-activation__label{
    font-size:14px;
    color:#888;
    margin:0 0 24px;
}
.cb-activation__link{
    display:inline-block;
    padding:10px 28px;
    font-size:14px;
    font-weight:500;
    color:#fff;
    background:#4caf50;
    border:none;
    border-radius:8px;
    text-decoration:none;
    cursor:pointer;
    transition:background .2s;
}
.cb-activation__link:hover{
    background:#43a047;
}
</style>
</head>
<body>
<div class="cb-activation">
    <div class="cb-activation__icon">{$logo_html}</div>
    <h1 class="cb-activation__heading">{$text_heading}</h1>
    <p class="cb-activation__store">{$store_name_esc}</p>
    <div class="cb-activation__status" id="cb-ext-status">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="8" fill="#4caf50"/><path d="M4.5 8.5L7 11L11.5 5.5" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </div>
    <p class="cb-activation__label">{$text_activated}</p>
    <div class="cb-activation__timer">
        <svg viewBox="0 0 72 72">
            <circle class="cb-activation__timer-bg" cx="36" cy="36" r="32"/>
            <circle class="cb-activation__timer-progress" id="cb-progress" cx="36" cy="36" r="32"/>
        </svg>
        <div class="cb-activation__timer-text" id="cb-countdown">5</div>
    </div>
    <p class="cb-activation__label">{$text_redirect} <strong id="cb-seconds">5</strong> {$text_sec}</p>
    <a class="cb-activation__link" href="{$safe_redirect_url}">{$text_go_now} &rarr;</a>
</div>
<script>
(function(){
    'use strict';
    var URL={$safe_js_url};
    var TOTAL=5;
    var remaining=TOTAL;
    var CIRCUMFERENCE=2*Math.PI*32; // 201.06
    var elCountdown=document.getElementById('cb-countdown');
    var elSeconds=document.getElementById('cb-seconds');
    var elProgress=document.getElementById('cb-progress');

    function update(){
        if(elCountdown)elCountdown.textContent=remaining;
        if(elSeconds)elSeconds.textContent=remaining;
        if(elProgress){
            var offset=CIRCUMFERENCE*(1-remaining/TOTAL);
            elProgress.style.strokeDashoffset=offset;
        }
    }
    update();

    function tick(){
        remaining--;
        if(remaining<0)remaining=0;
        update();
        if(remaining<=0){
            clearInterval(timer);
            window.location.href=URL;
        }
    }
    var timer=setInterval(tick,1000);

    var confirmed=false;
    document.addEventListener('cashback:site:confirmed',function(){
        if(confirmed)return;
        confirmed=true;
        var el=document.getElementById('cb-ext-status');
        if(el)el.classList.add('visible');
    });
})();
</script>
</body>
</html>
HTML;
        exit;
    }

    /**
     * Построение финального affiliate URL с подстановкой реальных значений параметров.
     *
     * @since 3.0.0
     *
     * @param int    $product_id ID товара WooCommerce.
     * @param int    $user_id    ID текущего пользователя (0 для гостей).
     * @param string $click_id   UUID v7 (time-ordered), сгенерированный на сервере.
     *
     * @return string|null Полный affiliate URL или null если товар не найден.
     */
    private function build_final_affiliate_url( int $product_id, int $user_id, string $click_id ): ?string {
        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'external') {
            return null;
        }

        $base_url = $product->get_product_url();
        if (empty($base_url)) {
            return null;
        }

        // Защита от open redirect: разрешаем только http/https схемы
        $scheme = parse_url($base_url, PHP_URL_SCHEME);
        if (!in_array($scheme, array( 'http', 'https' ), true)) {
            return null;
        }

        $affiliate_params = $this->get_affiliate_params($product_id);
        if (empty($affiliate_params)) {
            return $base_url;
        }

        // Получаем partner_token (криптографически стойкий, вместо user_id)
        $partner_token = null;
        if ($user_id > 0) {
            $partner_token = Mariadb_Plugin::get_partner_token($user_id);
        }

        $params = array();
        foreach ($affiliate_params as $param) {
            if (empty($param['key']) || empty($param['value'])) {
                continue;
            }

            $param_type = strtolower(trim($param['value']));

            if ($param_type === 'user') {
                // partner_token вместо user_id — защита от IDOR и перебора
                $params[ $param['key'] ] = $partner_token !== null ? $partner_token : 'unregistered';
            } elseif ($param_type === 'uuid') {
                $params[ $param['key'] ] = $click_id;
            } else {
                $params[ $param['key'] ] = $param['value'];
            }
        }

        return add_query_arg($params, $base_url);
    }

    /**
     * Получение slug CPA-сети для товара.
     *
     * @since 3.0.0
     *
     * @param int $product_id ID товара.
     *
     * @return string|null Slug сети или null.
     */
    private function get_network_slug_for_product( int $product_id ): ?string {
        global $wpdb;

        $network_id = (int) get_post_meta($product_id, '_affiliate_network_id', true);
        if ($network_id <= 0) {
            return null;
        }

        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';
        $slug           = $wpdb->get_var($wpdb->prepare(
            'SELECT slug FROM %i WHERE id = %d AND is_active = 1',
            $networks_table,
            $network_id
        ));

        return $slug ?: null;
    }

    /**
     * Получение идентификатора сессии для текущего посетителя.
     *
     * Для авторизованных пользователей возвращает null (достаточно user_id).
     * Для гостей используется только WooCommerce session.
     * PHP session_start() не используется — конфликтует с page cache и object cache.
     *
     * @since 3.0.0
     *
     * @return string|null Идентификатор WC-сессии или null.
     */
    private function get_session_id(): ?string {
        if (is_user_logged_in()) {
            return null;
        }

        if (function_exists('WC') && WC()->session) {
            $wc_session_id = WC()->session->get_customer_id();
            if (!empty($wc_session_id)) {
                return (string) $wc_session_id;
            }
        }

        return null;
    }

    /**
     * Проверяет User-Agent на известные бот-сигнатуры.
     *
     * Не блокирует поисковых ботов (они не кликают по affiliate ссылкам,
     * но если дойдут до ?cashback_click — это уже подозрительно).
     *
     * @since 4.2.0
     *
     * @param string $user_agent Raw User-Agent строка.
     *
     * @return bool true если UA похож на бота/скрипт.
     */
    private function is_bot_user_agent( string $user_agent ): bool {
        // Пустой UA — однозначно не браузер
        if (trim($user_agent) === '') {
            return true;
        }

        // Слишком короткий UA (нормальный браузер > 40 символов)
        if (strlen($user_agent) < 20) {
            return true;
        }

        // Известные бот/скрипт сигнатуры (lowercase для сравнения)
        $bot_signatures = array(
            'curl/',
            'wget/',
            'python-requests',
            'python-urllib',
            'python/',
            'httpie/',
            'java/',
            'apache-httpclient',
            'go-http-client',
            'node-fetch',
            'axios/',
            'undici/',
            'scrapy',
            'mechanize',
            'libwww-perl',
            'lwp-trivial',
            'php/',
            'guzzlehttp',
            'okhttp',
            'headlesschrome',
            'phantomjs',
            'selenium',
            'puppeteer',
            'playwright',
        );

        $ua_lower = strtolower($user_agent);

        foreach ($bot_signatures as $sig) {
            if (str_contains($ua_lower, $sig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Двухуровневый rate limit без User-Agent.
     *
     * Ключи:
     *   1. per_product: md5(IP + product_id) — ловит накрутку оффера
     *   2. global:      md5(IP)              — ловит массовый фрод / DDoS
     *
     * UA не используется в ключах:
     *   - Боты меняют UA на каждый запрос → обход.
     *   - CGNAT: разные UA = разные ключи = один пользователь исчерпает лимит соты.
     *
     * Логика:
     *   - Если ЛЮБОЙ ключ >= BLOCK → 'blocked'
     *   - Если ЛЮБОЙ ключ > SPAM → 'spam'
     *   - Иначе → 'normal'
     *
     * Оптимизация: если статус уже 'blocked', счётчик НЕ перезаписывается
     * (экономим запись в wp_options / object cache).
     *
     * @since 4.3.0
     *
     * @param string $ip_address IP адрес клиента.
     * @param int    $product_id ID товара.
     *
     * @return string 'normal' | 'spam' | 'blocked'
     */
    private function get_click_rate_status( string $ip_address, int $product_id ): string {
        $window = self::RATE_LIMIT_WINDOW_SECONDS;

        // --- Ключ 1: per-product (IP + product_id, без UA) ---
        $pp_hash = substr(md5($ip_address . '|' . $product_id), 0, 12);
        $pp_key  = 'cb_pp_' . $pp_hash;

        // --- Ключ 2: global (IP only, без UA и product_id) ---
        $gl_hash = substr(md5($ip_address), 0, 12);
        $gl_key  = 'cb_gl_' . $gl_hash;

        // Читаем текущие счётчики
        $pp_count = (int) get_transient($pp_key);
        $gl_count = (int) get_transient($gl_key);

        // Проверяем блокировку ДО инкремента (не тратим write на заблокированных)
        if (
            $pp_count >= self::RATE_PER_PRODUCT_BLOCK ||
            $gl_count >= self::RATE_GLOBAL_BLOCK
        ) {
            return 'blocked';
        }

        // Инкрементируем
        $pp_new = $pp_count + 1;
        $gl_new = $gl_count + 1;

        set_transient($pp_key, $pp_new, $window);
        set_transient($gl_key, $gl_new, $window);

        // Проверяем после инкремента
        if (
            $pp_new >= self::RATE_PER_PRODUCT_BLOCK ||
            $gl_new >= self::RATE_GLOBAL_BLOCK
        ) {
            return 'blocked';
        }

        if (
            $pp_new > self::RATE_PER_PRODUCT_SPAM ||
            $gl_new > self::RATE_GLOBAL_SPAM
        ) {
            return 'spam';
        }

        return 'normal';
    }

    /**
     * Запись клика в cashback_click_log.
     *
     * Без транзакции — одиночный INSERT не требует START TRANSACTION.
     * Ошибка записи логируется, но не блокирует редирект пользователя.
     * user_id = 0 для гостей (не NULL), чтобы использовать единый %d плейсхолдер.
     *
     * @since 4.2.0
     *
     * @param array $data Данные клика (user_id: int, 0 для гостей).
     *
     * @return bool true при успехе, false при ошибке.
     */
    private function log_click_to_db( array $data ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_click_log';

        // Время в UTC с микросекундами
        $created_at = ( new \DateTimeImmutable('now', new \DateTimeZone('UTC')) )->format('Y-m-d H:i:s.u');

        $result = $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (click_id, user_id, session_id, product_id, cpa_network, affiliate_url, ip_address, user_agent, referer, spam_click, created_at)
             VALUES (%s, %d, %s, %d, %s, %s, %s, %s, %s, %d, %s)',
            $table,
            $data['click_id'],
            absint($data['user_id']),
            $data['session_id'],
            $data['product_id'],
            $data['cpa_network'],
            $data['affiliate_url'],
            $data['ip_address'],
            $data['user_agent'],
            $data['referer'],
            absint($data['spam_click'] ?? 0),
            $created_at
        ));

        if ($result === false) {
            $logger = wc_get_logger();
            $logger->error(
                sprintf('Ошибка записи клика для товара %d: %s', $data['product_id'], $wpdb->last_error),
                array( 'source' => self::LOGGER_SOURCE )
            );
            return false;
        }

        return true;
    }

    /**
     * Получение статистики подозрительных кликов за последние N часов.
     *
     * Для ручного анализа: вызывай из WP-CLI или admin-страницы.
     * Пример: WC_Affiliate_URL_Params::get_spam_stats(24)
     *
     * @since 4.2.0
     *
     * @param int $hours За сколько часов смотреть (по умолчанию 24).
     *
     * @return array Массив с агрегированной статистикой.
     */
    public static function get_spam_stats( int $hours = 24 ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_click_log';

        // Топ IP по спам-кликам
        $top_ips = $wpdb->get_results($wpdb->prepare(
            'SELECT ip_address, COUNT(*) as total, SUM(spam_click) as spam_count
             FROM %i
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
             GROUP BY ip_address
             HAVING spam_count > 0
             ORDER BY spam_count DESC
             LIMIT 20',
            $table,
            $hours
        ), ARRAY_A);

        // Топ товаров по спам-кликам
        $top_products = $wpdb->get_results($wpdb->prepare(
            'SELECT product_id, COUNT(*) as total, SUM(spam_click) as spam_count
             FROM %i
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
             GROUP BY product_id
             HAVING spam_count > 0
             ORDER BY spam_count DESC
             LIMIT 20',
            $table,
            $hours
        ), ARRAY_A);

        // Общие цифры
        $totals = $wpdb->get_row($wpdb->prepare(
            'SELECT COUNT(*) as total_clicks, SUM(spam_click) as total_spam
             FROM %i
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)',
            $table,
            $hours
        ), ARRAY_A);

        return array(
            'period_hours' => $hours,
            'total_clicks' => (int) ( $totals['total_clicks'] ?? 0 ),
            'total_spam'   => (int) ( $totals['total_spam'] ?? 0 ),
            'spam_rate'    => $totals['total_clicks'] > 0
                ? round((int) $totals['total_spam'] / (int) $totals['total_clicks'] * 100, 1)
                : 0,
            'top_ips'      => $top_ips ?: array(),
            'top_products' => $top_products ?: array(),
        );
    }

    /**
     * Добавление data-product-id к ссылкам внешних товаров.
     *
     * @since 1.0.0
     *
     * @param string     $link    HTML код ссылки.
     * @param WC_Product $product Объект товара WooCommerce.
     *
     * @return string Модифицированная ссылка с data-атрибутом.
     */
    public function add_product_id_to_link( string $link, WC_Product $product ): string {
        if ($product->get_type() === 'external') {
            $product_id   = $product->get_id();
            $base_url     = $product->get_product_url();
            $cashback_url = home_url('/?cashback_click=' . $product_id);

            // Принудительно заменяем href на наш click-tracking endpoint.
            // WoodMart может рендерить кнопки через get_product_url() напрямую,
            // минуя фильтр woocommerce_product_add_to_cart_url — подправляем здесь.
            $link = preg_replace('/\bhref="[^"]*"/', 'href="' . esc_url($cashback_url) . '"', $link, 1);

            // Добавляем data-атрибуты. target/_blank и rel=nofollow добавляем только если
            // их нет в оригинальном HTML — WooCommerce external template уже включает их,
            // а дублирование провоцирует WoodMart добавлять иконку внешней ссылки (↗),
            // что меняет внешний вид кнопки.
            $extra_attrs = 'data-product-id="' . esc_attr((string) $product_id) . '"'
                . ' data-product-url="' . esc_url($base_url) . '"';

            if (strpos($link, 'target=') === false) {
                $extra_attrs .= ' target="_blank"';
            }
            if (strpos($link, 'rel=') === false) {
                $extra_attrs .= ' rel="nofollow"';
            }

            $link = str_replace('<a ', '<a ' . $extra_attrs . ' ', $link);
        }
        return $link;
    }

    /**
     * Модификация кнопки внешнего товара на странице товара.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function modify_single_product_button(): void {
        global $product;

        if (!$product || $product->get_type() !== 'external') {
            return;
        }

        $base_url    = $product->get_product_url();
        $product_url = $this->modify_external_url($base_url, $product);
        $button_text = $product->single_add_to_cart_text();

        echo '<p class="cart">';
        echo '<a href="' . esc_url($product_url) . '"'
            . ' class="single_add_to_cart_button button alt"'
            . ' data-product-id="' . esc_attr((string) $product->get_id()) . '"'
            . ' data-product-url="' . esc_url($base_url) . '"'
            . ' target="_blank"'
            . ' rel="nofollow">';
        echo esc_html($button_text);
        echo '</a>';
        echo '</p>';
    }

    /**
     * Генерация HTML для отображения кэшбэка.
     *
     * @since 3.0.0
     *
     * @param int    $product_id ID товара.
     * @param string $context    Контекст отображения: 'loop' или 'single'.
     * @param bool   $standalone Отображение вместо цены (без цены).
     *
     * @return string HTML-строка или пустая строка если кэшбэк не задан.
     */
    private function get_cashback_html( int $product_id, string $context = 'loop', bool $standalone = false ): string {
        $value = get_post_meta($product_id, '_cashback_display_value', true);
        if (empty($value)) {
            return '';
        }

        $label = get_post_meta($product_id, '_cashback_display_label', true);
        if (empty($label)) {
            $label = 'Кэшбэк';
        }

        $classes = 'cashback-display cashback-display--' . esc_attr($context);
        if ($standalone) {
            $classes .= ' cashback-display--standalone';
        }

        return sprintf(
            '<span class="%s"><span class="cashback-display__label">%s</span> <span class="cashback-display__value">%s</span></span>',
            esc_attr($classes),
            esc_html($label),
            esc_html($value)
        );
    }

    /**
     * Добавляет кэшбэк к HTML цены товара.
     *
     * Для товаров с ценой — дописывает кэшбэк после цены.
     * Для товаров без цены — не изменяет HTML (кэшбэк выводится отдельными хуками).
     *
     * @since 3.0.0
     *
     * @param string      $price_html HTML цены.
     * @param \WC_Product $product    Объект товара.
     *
     * @return string Модифицированный HTML цены.
     */
    public function append_cashback_to_price( string $price_html, \WC_Product $product ): string {
        if ($product->get_type() !== 'external') {
            return $price_html;
        }

        if (is_admin() && !wp_doing_ajax()) {
            return $price_html;
        }

        if (function_exists('is_cart') && is_cart()) {
            return $price_html;
        }

        if (function_exists('is_checkout') && is_checkout()) {
            return $price_html;
        }

        $product_id = $product->get_id();
        $is_single  = function_exists('is_product') && is_product();
        $context    = $is_single ? 'single' : 'loop';
        $standalone = empty(trim($price_html));

        $cashback_html = $this->get_cashback_html($product_id, $context, $standalone);
        if (empty($cashback_html)) {
            return $price_html;
        }

        if ($standalone) {
            return $price_html;
        }

        return $price_html . $cashback_html;
    }

    /**
     * Отображает кэшбэк в каталоге товаров когда цена не указана.
     *
     * Резервный вывод через хук woocommerce_after_shop_loop_item_title
     * на случай если woocommerce_template_loop_price() не вызывается для товаров без цены.
     *
     * @since 3.0.0
     *
     * @return void
     */
    public function display_cashback_standalone_loop(): void {
        global $product;

        if (!$product || $product->get_type() !== 'external') {
            return;
        }

        if ($product->get_price() !== '' && $product->get_price() !== null) {
            return;
        }

        $cashback_html = $this->get_cashback_html($product->get_id(), 'loop', true);
        if (!empty($cashback_html)) {
            echo '<span class="price">' . wp_kses_post($cashback_html) . '</span>';
        }
    }

    /**
     * Отображает кэшбэк на странице товара когда цена не указана.
     *
     * Хук woocommerce_single_product_summary с приоритетом 11
     * (после woocommerce_template_single_price с приоритетом 10).
     *
     * @since 3.0.0
     *
     * @return void
     */
    public function display_cashback_single_product(): void {
        global $product;

        if (!$product || $product->get_type() !== 'external') {
            return;
        }

        if ($product->get_price() !== '' && $product->get_price() !== null) {
            return;
        }

        $cashback_html = $this->get_cashback_html($product->get_id(), 'single', true);
        if (!empty($cashback_html)) {
            echo '<p class="price">' . wp_kses_post($cashback_html) . '</p>';
        }
    }

    /**
     * Подключение скриптов и стилей для фронтенда.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function enqueue_frontend_scripts(): void {
        if (!is_admin()) {
            wp_enqueue_script(
                'wc-affiliate-url-params',
                plugins_url('assets/js/affiliate-guest-warning.js', __FILE__),
                array( 'jquery' ),
                '4.1.1',
                true
            );

            wp_localize_script('wc-affiliate-url-params', 'wcAffiliateParams', array(
                'isLoggedIn'     => is_user_logged_in(),
                'warningMessage' => __(
                    'Вы не авторизованы, при переходе покупка не будет учтена сервисом. Продолжить?',
                    'wc-affiliate-url-params'
                ),
                'loginUrl'       => add_query_arg('action', 'register', get_permalink(wc_get_page_id('myaccount'))),
            ));

            wp_enqueue_style(
                'wc-affiliate-url-params',
                plugins_url('assets/css/frontend.css', __FILE__),
                array(),
                '1.0.0'
            );
        }
    }

    /**
     * Подключение стилей и скриптов для админки.
     *
     * @since 2.0.0
     *
     * @param string $hook Текущая страница админки.
     *
     * @return void
     */
    public function enqueue_admin_scripts( string $hook ): void {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        global $post;

        if (!$post || get_post_type($post->ID) !== 'product') {
            return;
        }

        wp_enqueue_style(
            'wc-affiliate-url-params-admin',
            plugins_url('assets/css/admin.css', __FILE__),
            array(),
            '1.2.0'
        );

        wp_enqueue_script(
            'wc-affiliate-network-admin',
            plugins_url('assets/js/admin-affiliate-network.js', __FILE__),
            array( 'jquery' ),
            '1.1.0',
            true
        );

        wp_localize_script('wc-affiliate-network-admin', 'wcAffiliateNetworkData', array(
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'getParamsNonce'   => wp_create_nonce('get_network_params_nonce'),
            'updateParamNonce' => wp_create_nonce('update_network_param_nonce'),
            'deleteParamNonce' => wp_create_nonce('delete_network_param_nonce'),
        ));
    }
}

// Объявление совместимости с HPOS
add_action('before_woocommerce_init', function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});
