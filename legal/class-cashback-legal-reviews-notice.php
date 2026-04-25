<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Legal_Reviews_Notice
 *
 * Текстовая ремарка под формой отзыва WooCommerce о факте обработки
 * и публикации имени автора. Отображается на product-странице после
 * стандартной формы comment_form (для авторизованных юзеров — согласие
 * уже дано при регистрации, см. Cashback_Legal_Registration_Checkboxes).
 *
 * Если опция WP `comment_registration` = 0 (отзывы разрешены гостям) —
 * выводится admin-уведомление с предупреждением: гостевые отзывы
 * требуют отдельного чекбокса 152-ФЗ, реализация — задача Phase 6.4
 * в backlog. Для текущего сайта пользователь подтвердил, что отзывы
 * только для авторизованных, так что admin-уведомление информативное.
 *
 * @since 1.3.0
 */
class Cashback_Legal_Reviews_Notice {

    public static function init(): void {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('comment_form_logged_in_after', array( __CLASS__, 'render_logged_in_remark' ));
        add_action('comment_form_top', array( __CLASS__, 'render_guest_warning_for_admin' ));
    }

    /**
     * Text-remark для авторизованного юзера на форме отзыва.
     * Срабатывает только на product-странице WC.
     */
    public static function render_logged_in_remark(): void {
        if (!self::is_product_review_form()) {
            return;
        }
        echo '<p class="cashback-legal-remark" style="font-size:12px;color:#666;margin:6px 0 12px;">'
            . esc_html__('Оставляя отзыв, вы подтверждаете согласие на обработку и публикацию указанного имени, ранее данное при регистрации (152-ФЗ).', 'cashback-plugin')
            . '</p>';
    }

    /**
     * Если на сайте включены гостевые отзывы (comment_registration=0) и
     * текущий юзер — admin, выводим заметную плашку прямо в форме отзыва
     * как маркер незакрытого compliance-gap. Гостям не показываем, чтобы
     * не светить незавершённость UX-механизма.
     */
    public static function render_guest_warning_for_admin(): void {
        if (!self::is_product_review_form()) {
            return;
        }
        if (is_user_logged_in()) {
            return;
        }
        if ((int) get_option('comment_registration', 0) === 1) {
            return;
        }
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return;
        }
        echo '<div class="cashback-legal-admin-warning" style="background:#f8d7da;border:1px solid #f5c2c7;color:#842029;padding:8px 12px;margin:0 0 12px;font-size:12px;">'
            . esc_html__('⚠ Видимо только администраторам: гостевые отзывы разрешены (Settings → Discussion → "Users must be registered…" выключено), но отдельный чекбокс согласия 152-ФЗ для гостей не реализован. Включите опцию или активируйте Phase 6.4 в backlog.', 'cashback-plugin')
            . '</div>';
    }

    /**
     * Текущий запрос — рендер comment_form на странице товара?
     * Гарды для других CPT (post/page) — у тех тоже бывают комментарии,
     * но 152-ФЗ-ремарка в нашем плагине только для WC-отзывов.
     */
    private static function is_product_review_form(): bool {
        if (!function_exists('is_singular')) {
            return false;
        }
        if (function_exists('is_product') && is_product()) {
            return true;
        }
        return is_singular('product');
    }
}
