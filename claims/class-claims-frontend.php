<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Claims Frontend Module
 *
 * User-facing page in WooCommerce My Account:
 * - Single menu item "Потерянный кэшбэк"
 * - Two tabs: "Мои переходы" and "Мои заявки"
 * - Tab switching matches support module style
 */
class Cashback_Claims_Frontend {

    private const PER_PAGE = 20;

    public function __construct() {
        add_action('init', array( $this, 'register_endpoint' ), 5);
        add_filter('query_vars', array( $this, 'add_query_vars' ), 10);
        add_filter('woocommerce_account_menu_items', array( $this, 'add_menu_item' ), 10);
        add_action('woocommerce_account_cashback_lost_cashback_endpoint', array( $this, 'endpoint_content' ), 10);
        add_action('wp_ajax_claims_check_eligibility', array( $this, 'ajax_check_eligibility' ));
        add_action('wp_ajax_claims_calculate_score', array( $this, 'ajax_calculate_score' ));
        add_action('wp_ajax_claims_submit', array( $this, 'ajax_submit_claim' ));
        add_action('wp_ajax_claims_load_clicks', array( $this, 'ajax_load_clicks' ));
        add_action('wp_ajax_claims_load_claims', array( $this, 'ajax_load_claims' ));
        add_action('wp_ajax_claims_mark_read', array( $this, 'ajax_mark_read' ));
        add_action('wp_enqueue_scripts', array( $this, 'enqueue_scripts' ));
        add_action('wp_footer', array( $this, 'render_menu_badge' ));
    }

    public function register_endpoint(): void {
        add_rewrite_endpoint('cashback_lost_cashback', EP_ROOT | EP_PAGES);
    }

    /**
     * Render red badge on WooCommerce account menu item via CSS ::after.
     */
    public function render_menu_badge(): void {
        if (!is_user_logged_in() || is_admin()) {
            return;
        }

        if (!function_exists('is_account_page') || !is_account_page()) {
            return;
        }

        $count = Cashback_Claims_DB::get_unread_events_count(get_current_user_id());
        if ($count <= 0) {
            return;
        }

        ?>
        <style id="cashback-claims-menu-badge-style">
            .woocommerce-MyAccount-navigation-link--cashback_lost_cashback a::after {
                content: '<?php echo esc_js((string) absint($count)); ?>';
                display: inline-block;
                min-width: 18px;
                height: 18px;
                line-height: 18px;
                padding: 0 5px;
                border-radius: 50%;
                background: #f44336;
                color: #fff !important;
                font-size: 11px;
                font-weight: bold;
                text-align: center;
                margin-left: 6px;
                vertical-align: middle;
            }
        </style>
        <?php
    }

    public function add_query_vars( array $vars ): array {
        $vars[] = 'cashback_lost_cashback';
        return $vars;
    }

    public function add_menu_item( array $items ): array {
        if (isset($items['customer-logout'])) {
            $logout = $items['customer-logout'];
            unset($items['customer-logout']);
            $items['cashback_lost_cashback'] = __('Потерянный кэшбэк', 'cashback-plugin');
            $items['customer-logout']        = $logout;
        } else {
            $items['cashback_lost_cashback'] = __('Потерянный кэшбэк', 'cashback-plugin');
        }
        return $items;
    }

    /**
     * Main endpoint content — tabs + both tab contents.
     */
    public function endpoint_content(): void {
        $user_id = get_current_user_id();
        if (!$user_id) {
            echo '<p>' . esc_html__('Необходима авторизация.', 'cashback-plugin') . '</p>';
            return;
        }

        ?>
        <h2><?php esc_html_e('Потерянный кэшбэк', 'cashback-plugin'); ?></h2>

        <?php $unread_count = Cashback_Claims_DB::get_unread_events_count($user_id); ?>
        <!-- Вкладки -->
        <div class="cashback-support-tabs">
            <button type="button" class="cashback-support-tab active" data-tab="clicks"><?php esc_html_e('Мои переходы', 'cashback-plugin'); ?></button>
            <button type="button" class="cashback-support-tab" data-tab="claims"><?php esc_html_e('Мои заявки', 'cashback-plugin'); ?>
            <?php
            if ($unread_count > 0) :
?>
<span class="claims-tab-badge" id="claims-tab-unread-badge"><?php echo absint($unread_count); ?></span><?php endif; ?></button>
        </div>

        <!-- Вкладка: Мои переходы -->
        <div class="cashback-support-tab-content active" id="tab-clicks">
            <?php $this->render_clicks_tab($user_id); ?>
        </div>

        <!-- Вкладка: Мои заявки -->
        <div class="cashback-support-tab-content" id="tab-claims" style="display: none;">
            <?php $this->render_claims_tab($user_id); ?>
        </div>

        <!-- Модалка создания заявки -->
        <div id="claim-modal" class="claim-modal" style="display:none;">
            <div class="claim-modal-content">
                <span class="claim-modal-close">&times;</span>
                <h3><?php esc_html_e('Заявка на неначисленный кэшбэк', 'cashback-plugin'); ?></h3>

                <div class="claim-preload">
                    <p><strong><?php esc_html_e('Магазин:', 'cashback-plugin'); ?></strong> <span id="claim-product-name"></span></p>
                    <p><strong><?php esc_html_e('Дата перехода:', 'cashback-plugin'); ?></strong> <span id="claim-click-date"></span></p>
                </div>

                <div id="claim-eligibility-msg" class="woocommerce-info" style="display:none;"></div>

                <form id="claim-form" style="display:none;" data-cb-protected="1">
                    <input type="hidden" id="claim-click-id" name="click_id" value="">

                    <p class="form-row form-row-wide">
                        <label for="claim-order-id"><?php esc_html_e('Номер заказа', 'cashback-plugin'); ?> <span class="required">*</span></label>
                        <input type="text" id="claim-order-id" name="order_id" required>
                    </p>

                    <p class="form-row form-row-wide">
                        <label for="claim-order-value"><?php esc_html_e('Сумма заказа', 'cashback-plugin'); ?> <span class="required">*</span></label>
                        <input type="number" id="claim-order-value" name="order_value" step="0.01" min="0.01" required>
                    </p>

                    <p class="form-row form-row-wide">
                        <label for="claim-order-date"><?php esc_html_e('Дата заказа', 'cashback-plugin'); ?> <span class="required">*</span></label>
                        <input type="date" id="claim-order-date" name="order_date" required>
                    </p>

                    <p class="form-row form-row-wide">
                        <label for="claim-comment"><?php esc_html_e('Комментарий', 'cashback-plugin'); ?></label>
                        <textarea id="claim-comment" name="comment" rows="3"></textarea>
                    </p>

                    <div id="claim-score-display" class="claim-score" style="display:none;">
                        <p><strong><?php esc_html_e('Вероятность одобрения:', 'cashback-plugin'); ?></strong> <span id="claim-score-value"></span></p>
                        <div class="claim-score-bar">
                            <div class="claim-score-bar-fill" id="claim-score-bar-fill"></div>
                        </div>
                    </div>

                    <?php
                    if (class_exists('Cashback_Captcha')) {
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captcha renderer returns sanitized HTML markup.
                        echo Cashback_Captcha::render_container('cb-captcha-claims');
                    }
?>

                    <p class="cashback-legal-remark" style="font-size:12px;color:#666;margin:6px 0 12px;">
                        <?php esc_html_e('Отправляя заявку, вы подтверждаете согласие на обработку персональных данных, ранее данное при регистрации (152-ФЗ).', 'cashback-plugin'); ?>
                    </p>

                    <p class="form-row">
                        <button type="submit" class="button alt" id="claim-submit-btn" disabled>
                            <?php esc_html_e('Отправить заявку', 'cashback-plugin'); ?>
                        </button>
                    </p>
                </form>

                <div id="claim-result" class="claim-result" style="display:none;"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render clicks tab content.
     */
    private function render_clicks_tab( int $user_id ): void {
        $result = Cashback_Claims_Eligibility::get_user_clicks($user_id, 1, self::PER_PAGE);
        ?>
        <div class="clicks-filters">
            <div class="clicks-filters-row">
                <div class="clicks-filter-group">
                    <label for="clicks-date-from"><?php esc_html_e('С', 'cashback-plugin'); ?></label>
                    <input type="date" id="clicks-date-from" class="clicks-filter-input">
                </div>
                <div class="clicks-filter-group">
                    <label for="clicks-date-to"><?php esc_html_e('По', 'cashback-plugin'); ?></label>
                    <input type="date" id="clicks-date-to" class="clicks-filter-input">
                </div>
                <div class="clicks-filter-group">
                    <label for="clicks-search"><?php esc_html_e('Магазин', 'cashback-plugin'); ?></label>
                    <input type="text" id="clicks-search" class="clicks-filter-input" placeholder="<?php esc_attr_e('Поиск по названию...', 'cashback-plugin'); ?>">
                </div>
                <div class="clicks-filter-group">
                    <label for="clicks-can-claim"><?php esc_html_e('Заявка', 'cashback-plugin'); ?></label>
                    <select id="clicks-can-claim" class="clicks-filter-input">
                        <option value=""><?php esc_html_e('Все переходы', 'cashback-plugin'); ?></option>
                        <option value="yes"><?php esc_html_e('Можно подать заявку', 'cashback-plugin'); ?></option>
                    </select>
                </div>
                <div class="clicks-filter-group clicks-filter-buttons">
                    <button type="button" id="clicks-filter-apply" class="button"><?php esc_html_e('Применить', 'cashback-plugin'); ?></button>
                    <button type="button" id="clicks-filter-reset" class="button clicks-filter-reset"><?php esc_html_e('Сбросить', 'cashback-plugin'); ?></button>
                </div>
            </div>
        </div>
        <div id="clicks-table-container">
            <?php if (empty($result['clicks'])) : ?>
                <p><?php esc_html_e('У вас пока нет переходов по партнёрским ссылкам.', 'cashback-plugin'); ?></p>
            <?php else : ?>
                <?php $this->render_clicks_table($result['clicks']); ?>
            <?php endif; ?>
        </div>
        <div id="clicks-pagination">
            <?php if ($result['pages'] > 1) : ?>
                <?php
                Cashback_Pagination::render(array(
                    'mode'         => 'ajax',
                    'current_page' => 1,
                    'total_pages'  => (int) ceil((float) $result['pages']),
                ));
                ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render claims tab content.
     */
    private function render_claims_tab( int $user_id ): void {
        $result = Cashback_Claims_Manager::get_user_claims($user_id, 1, self::PER_PAGE);
        ?>
        <div class="clicks-filters">
            <div class="clicks-filters-row">
                <div class="clicks-filter-group">
                    <label for="claims-date-from"><?php esc_html_e('С', 'cashback-plugin'); ?></label>
                    <input type="date" id="claims-date-from" class="clicks-filter-input">
                </div>
                <div class="clicks-filter-group">
                    <label for="claims-date-to"><?php esc_html_e('По', 'cashback-plugin'); ?></label>
                    <input type="date" id="claims-date-to" class="clicks-filter-input">
                </div>
                <div class="clicks-filter-group">
                    <label for="claims-search"><?php esc_html_e('Магазин', 'cashback-plugin'); ?></label>
                    <input type="text" id="claims-search" class="clicks-filter-input" placeholder="<?php esc_attr_e('Поиск по названию...', 'cashback-plugin'); ?>">
                </div>
                <div class="clicks-filter-group">
                    <label for="claims-status-filter"><?php esc_html_e('Статус', 'cashback-plugin'); ?></label>
                    <select id="claims-status-filter" class="clicks-filter-input">
                        <option value=""><?php esc_html_e('Все статусы', 'cashback-plugin'); ?></option>
                        <?php
                        $statuses = array(
                            'submitted'       => __('Отправлена', 'cashback-plugin'),
                            'sent_to_network' => __('Отправлена партнёру', 'cashback-plugin'),
                            'approved'        => __('Одобрена', 'cashback-plugin'),
                            'declined'        => __('Отклонена', 'cashback-plugin'),
                        );
                        foreach ($statuses as $slug => $label) {
                            printf('<option value="%s">%s</option>', esc_attr($slug), esc_html($label));
                        }
                        ?>
                    </select>
                </div>
                <div class="clicks-filter-group clicks-filter-buttons">
                    <button type="button" id="claims-filter-apply" class="button"><?php esc_html_e('Применить', 'cashback-plugin'); ?></button>
                    <button type="button" id="claims-filter-reset" class="button clicks-filter-reset"><?php esc_html_e('Сбросить', 'cashback-plugin'); ?></button>
                </div>
            </div>
        </div>

        <div id="claims-table-container">
            <?php if (empty($result['claims'])) : ?>
                <p><?php esc_html_e('У вас пока нет заявок.', 'cashback-plugin'); ?></p>
            <?php else : ?>
                <?php $this->render_claims_table($result['claims']); ?>
            <?php endif; ?>
        </div>
        <div id="claims-pagination">
            <?php if (!empty($result['claims'])) : ?>
                <?php
                Cashback_Pagination::render(array(
                    'mode'         => 'ajax',
                    'current_page' => 1,
                    'total_pages'  => (int) ceil((float) $result['pages']),
                ));
                ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render clicks table HTML.
     */
    private function render_clicks_table( array $clicks ): void {
        ?>
        <table class="shop_table shop_table_responsive my_account_orders">
            <thead>
                <tr>
                    <th><?php esc_html_e('Магазин', 'cashback-plugin'); ?></th>
                    <th><?php esc_html_e('Дата перехода', 'cashback-plugin'); ?></th>
                    <th><?php esc_html_e('Кэшбэк', 'cashback-plugin'); ?></th>
                    <th><?php esc_html_e('Действие', 'cashback-plugin'); ?></th>
                </tr>
            </thead>
            <tbody id="clicks-table-body">
                <?php foreach ($clicks as $click) : ?>
                    <tr>
                        <td data-title="<?php esc_attr_e('Магазин', 'cashback-plugin'); ?>">
                            <?php echo esc_html($click['product_name']); ?>
                        </td>
                        <td data-title="<?php esc_attr_e('Дата перехода', 'cashback-plugin'); ?>">
                            <?php echo esc_html(gmdate('d.m.Y H:i', strtotime($click['created_at']))); ?>
                        </td>
                        <td data-title="<?php esc_attr_e('Кэшбэк', 'cashback-plugin'); ?>">
                            <?php if ((int) $click['has_cashback']) : ?>
                                <span class="cashback-status cashback-status--yes">
                                    <?php echo esc_html($this->get_status_label($click['cashback_status'] ?? '')); ?>
                                </span>
                            <?php else : ?>
                                <span class="cashback-status cashback-status--no">
                                    <?php esc_html_e('Нет', 'cashback-plugin'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td data-title="<?php esc_attr_e('Действие', 'cashback-plugin'); ?>">
                            <?php if ($click['can_claim']) : ?>
                                <button class="button claim-btn"
                                        data-click-id="<?php echo esc_attr($click['click_id']); ?>"
                                        data-product-id="<?php echo esc_attr($click['product_id']); ?>"
                                        data-product-name="<?php echo esc_attr($click['product_name']); ?>"
                                        data-click-date="<?php echo esc_attr($click['created_at']); ?>"
                                        data-merchant-id="<?php echo esc_attr($click['merchant_id'] ?? $click['offer_id'] ?? 0); ?>">
                                    <?php esc_html_e('Подать заявку', 'cashback-plugin'); ?>
                                </button>
                            <?php else : ?>
                                <span class="na" title="<?php echo esc_attr($click['claim_reason']); ?>"><?php echo esc_html($click['claim_reason']); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render claims table HTML.
     */
    private function render_claims_table( array $claims ): void {
        ?>
        <table class="shop_table shop_table_responsive my_account_orders claims-table-expandable">
            <thead>
                <tr>
                    <th><?php esc_html_e('ID', 'cashback-plugin'); ?></th>
                    <th><?php esc_html_e('Магазин', 'cashback-plugin'); ?></th>
                    <th><?php esc_html_e('Заказ', 'cashback-plugin'); ?></th>
                    <th><?php esc_html_e('Сумма', 'cashback-plugin'); ?></th>
                    <th><?php esc_html_e('Вероятность', 'cashback-plugin'); ?></th>
                    <th><?php esc_html_e('Статус', 'cashback-plugin'); ?></th>
                </tr>
            </thead>
            <tbody id="claims-table-body">
                <?php
                foreach ($claims as $claim) :
                    $events     = $claim['events'] ?? array();
                    $has_events = !empty($events);
                    $unread     = 0;
                    foreach ($events as $ev) {
                        if ((int) $ev['is_read'] === 0 && $ev['actor_type'] !== 'user') {
                            ++$unread;
                        }
                    }
                ?>
                    <tr class="claim-row <?php echo $has_events ? 'has-events' : ''; ?><?php echo $unread > 0 ? ' claim-row-unread' : ''; ?>" data-claim-id="<?php echo esc_attr($claim['claim_id']); ?>">
                        <td data-title="<?php esc_attr_e('ID', 'cashback-plugin'); ?>">
                            <?php echo esc_html($claim['claim_id']); ?>
                            <?php if ($unread > 0) : ?>
                                <span class="claims-tab-badge"><?php echo absint($unread); ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-title="<?php esc_attr_e('Магазин', 'cashback-plugin'); ?>">
                            <?php echo esc_html($claim['product_name'] ?? '—'); ?>
                        </td>
                        <td data-title="<?php esc_attr_e('Заказ', 'cashback-plugin'); ?>">
                            <?php echo esc_html($claim['order_id']); ?>
                        </td>
                        <td data-title="<?php esc_attr_e('Сумма', 'cashback-plugin'); ?>">
                            <?php echo esc_html(number_format_i18n((float) $claim['order_value'], 2)); ?> ₽
                        </td>
                        <td data-title="<?php esc_attr_e('Вероятность', 'cashback-plugin'); ?>">
                            <?php echo esc_html(number_format_i18n((float) $claim['probability_score'], 1)); ?>%
                        </td>
                        <td data-title="<?php esc_attr_e('Статус', 'cashback-plugin'); ?>">
                            <span class="claim-status claim-status--<?php echo esc_attr($claim['status']); ?>">
                                <?php echo esc_html($this->get_status_label($claim['status'])); ?>
                            </span>
                        </td>
                    </tr>
                    <?php if ($has_events) : ?>
                    <tr class="claim-events-row" id="claim-events-<?php echo esc_attr($claim['claim_id']); ?>" style="display:none;">
                        <td colspan="6">
                            <div class="claim-events-list">
                                <strong><?php esc_html_e('История:', 'cashback-plugin'); ?></strong>
                                <?php foreach ($events as $event) : ?>
                                    <div class="claim-event-item <?php echo $event['actor_type'] !== 'user' && (int) $event['is_read'] === 0 ? 'claim-event-unread' : ''; ?>">
                                        <span class="claim-event-date"><?php echo esc_html(gmdate('d.m.Y H:i', strtotime($event['created_at']))); ?></span>
                                        <span class="claim-event-actor">
                                        <?php
                                            if ($event['actor_type'] === 'admin') {
                                                esc_html_e('Администратор', 'cashback-plugin');
                                            } elseif ($event['actor_type'] === 'system') {
                                                esc_html_e('Система', 'cashback-plugin');
                                            } else {
                                                esc_html_e('Вы', 'cashback-plugin');
                                            }
                                        ?>
                                        </span>
                                        <?php if (!empty($event['note'])) : ?>
                                            <span class="claim-event-note"><?php echo esc_html($event['note']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /* ============ AJAX handlers ============ */

    public function ajax_check_eligibility(): void {
        check_ajax_referer('cashback_claim_nonce', 'nonce');

        $user_id  = get_current_user_id();
        $click_id = sanitize_text_field(wp_unslash($_POST['click_id'] ?? ''));

        $result = Cashback_Claims_Eligibility::check($user_id, $click_id);

        wp_send_json_success($result);
    }

    public function ajax_calculate_score(): void {
        check_ajax_referer('cashback_claim_nonce', 'nonce');

        $data = array(
            'user_id'     => get_current_user_id(),
            'click_id'    => sanitize_text_field(wp_unslash($_POST['click_id'] ?? '')),
            'order_date'  => sanitize_text_field(wp_unslash($_POST['order_date'] ?? '')),
            'order_value' => floatval(wp_unslash($_POST['order_value'] ?? 0)),
            'merchant_id' => absint(wp_unslash($_POST['merchant_id'] ?? 0)),
            'comment'     => sanitize_textarea_field(wp_unslash($_POST['comment'] ?? '')),
        );

        $score = Cashback_Claims_Scoring::calculate($data);

        wp_send_json_success($score);
    }

    public function ajax_submit_claim(): void {
        check_ajax_referer('cashback_claim_submit', 'claim_nonce');

        if (!get_current_user_id()) {
            wp_send_json_error(array( 'message' => __('Необходима авторизация.', 'cashback-plugin') ));
        }

        $data = array(
            'click_id'    => sanitize_text_field(wp_unslash($_POST['click_id'] ?? '')),
            'order_id'    => sanitize_text_field(wp_unslash($_POST['order_id'] ?? '')),
            'order_value' => floatval(wp_unslash($_POST['order_value'] ?? 0)),
            'order_date'  => sanitize_text_field(wp_unslash($_POST['order_date'] ?? '')),
            'comment'     => sanitize_textarea_field(wp_unslash($_POST['comment'] ?? '')),
        );

        // Группа 6 ADR шаг 3a: пробрасываем client-side request_id в idempotency_key — валидация
        // и нормализация делаются в Cashback_Claims_Manager::create(). Без key поведение не меняется.
        $request_id = isset($_POST['request_id']) ? sanitize_text_field(wp_unslash($_POST['request_id'])) : '';
        if ('' !== $request_id) {
            $data['idempotency_key'] = $request_id;
        }

        if (empty($data['click_id']) || empty($data['order_id']) || $data['order_value'] <= 0 || empty($data['order_date'])) {
            wp_send_json_error(array( 'message' => __('Заполните все обязательные поля.', 'cashback-plugin') ));
        }

        $result = Cashback_Claims_Manager::create($data);

        if ($result['success']) {
            wp_send_json_success(array(
                'message'  => __('Заявка успешно отправлена.', 'cashback-plugin'),
                'claim_id' => $result['claim_id'],
            ));
        } else {
            wp_send_json_error(array( 'message' => $result['error'] ));
        }
    }

    public function ajax_load_clicks(): void {
        check_ajax_referer('cashback_claims_nonce', 'nonce');

        $user_id = get_current_user_id();
        $page    = max(1, absint($_POST['page'] ?? 1));

        $filters = array(
            'date_from' => sanitize_text_field(wp_unslash($_POST['date_from'] ?? '')),
            'date_to'   => sanitize_text_field(wp_unslash($_POST['date_to'] ?? '')),
            'search'    => sanitize_text_field(wp_unslash($_POST['search'] ?? '')),
            'can_claim' => sanitize_text_field(wp_unslash($_POST['can_claim'] ?? '')),
        );

        $result = Cashback_Claims_Eligibility::get_user_clicks($user_id, $page, self::PER_PAGE, $filters);

        ob_start();
        if (empty($result['clicks'])) {
            echo '<p>' . esc_html__('Ничего не найдено.', 'cashback-plugin') . '</p>';
        } else {
            $this->render_clicks_table($result['clicks']);
        }
        $html = ob_get_clean();

        wp_send_json_success(array(
            'html'  => $html,
            'page'  => $page,
            'pages' => $result['pages'],
            'total' => $result['total'],
        ));
    }

    public function ajax_load_claims(): void {
        check_ajax_referer('cashback_claims_nonce', 'nonce');

        $user_id = get_current_user_id();
        $page    = max(1, absint($_POST['page'] ?? 1));
        $status  = sanitize_text_field(wp_unslash($_POST['status'] ?? ''));

        $filters = array(
            'date_from' => sanitize_text_field(wp_unslash($_POST['date_from'] ?? '')),
            'date_to'   => sanitize_text_field(wp_unslash($_POST['date_to'] ?? '')),
            'search'    => sanitize_text_field(wp_unslash($_POST['search'] ?? '')),
        );

        $result = Cashback_Claims_Manager::get_user_claims($user_id, $page, self::PER_PAGE, $status, $filters);

        ob_start();
        if (empty($result['claims'])) {
            echo '<p>' . esc_html__('У вас пока нет заявок.', 'cashback-plugin') . '</p>';
        } else {
            $this->render_claims_table($result['claims']);
        }
        $html = ob_get_clean();

        wp_send_json_success(array(
            'html'  => $html,
            'page'  => $page,
            'pages' => $result['pages'],
            'total' => $result['total'],
        ));
    }

    public function ajax_mark_read(): void {
        check_ajax_referer('cashback_claims_mark_read', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(array( 'message' => __('Необходима авторизация.', 'cashback-plugin') ));
        }

        $marked = Cashback_Claims_DB::mark_user_events_read($user_id);

        wp_send_json_success(array( 'marked' => $marked ));
    }

    public function enqueue_scripts(): void {
        if (!is_user_logged_in() || is_admin()) {
            return;
        }

        if (!function_exists('is_account_page') || !is_account_page()) {
            return;
        }

        // Check if we're on the claims endpoint page
        // get_query_var returns '' (empty string) when the endpoint is active without a value
        global $wp_query;
        if (!isset($wp_query->query_vars['cashback_lost_cashback'])) {
            return;
        }

        $plugin_dir_url = plugin_dir_url(__DIR__);

        wp_enqueue_style(
            'cashback-claims-css',
            $plugin_dir_url . 'assets/css/admin-claims.css',
            array(),
            '1.4.0'
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
            'cashback-claims-js',
            $plugin_dir_url . 'assets/js/admin-claims.js',
            array( 'jquery', 'cashback-pagination', 'cashback-safe-html' ),
            '1.5.0',
            true
        );

        wp_localize_script('cashback-claims-js', 'cashbackClaimsData', array(
            'eligibilityNonce' => wp_create_nonce('cashback_claim_nonce'),
            'submitNonce'      => wp_create_nonce('cashback_claim_submit'),
            'loadNonce'        => wp_create_nonce('cashback_claims_nonce'),
            'markReadNonce'    => wp_create_nonce('cashback_claims_mark_read'),
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'is_claims_page'   => 'true',
            'i18n'             => array(
                'highProb'   => __('Высокая вероятность', 'cashback-plugin'),
                'medProb'    => __('Средняя вероятность', 'cashback-plugin'),
                'lowProb'    => __('Низкая вероятность', 'cashback-plugin'),
                'submitting' => __('Отправка...', 'cashback-plugin'),
                'submit'     => __('Отправить заявку', 'cashback-plugin'),
            ),
        ));
    }


    private function get_status_label( string $status ): string {
        $labels = array(
            'draft'           => __('Черновик', 'cashback-plugin'),
            'submitted'       => __('Отправлена', 'cashback-plugin'),
            'sent_to_network' => __('Отправлена партнёру', 'cashback-plugin'),
            'approved'        => __('Одобрена', 'cashback-plugin'),
            'declined'        => __('Отклонена', 'cashback-plugin'),
            'waiting'         => __('В ожидании', 'cashback-plugin'),
            'completed'       => __('Подтверждён', 'cashback-plugin'),
            'balance'         => __('Зачислен', 'cashback-plugin'),
            'hold'            => __('На проверке', 'cashback-plugin'),
        );

        return $labels[ $status ] ?? $status;
    }

    private function get_probability_label( float $score ): string {
        $formatted = number_format_i18n($score, 1) . '%';

        if ($score >= 70) {
            return $formatted . ' — ' . __('Высокая', 'cashback-plugin');
        }

        if ($score >= 40) {
            return $formatted . ' — ' . __('Средняя', 'cashback-plugin');
        }

        return $formatted . ' — ' . __('Низкая', 'cashback-plugin');
    }
}
