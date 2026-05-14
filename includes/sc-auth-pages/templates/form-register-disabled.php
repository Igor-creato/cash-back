<?php
/**
 * Шаблон: «Регистрация отключена администратором».
 *
 * Рендерится из Cashback_SC_Auth_Pages_Shortcodes::render_register когда
 * Cashback_Registration_Gate::is_allowed() === false (опция users_can_register=0).
 *
 * Доступные переменные:
 *  $login_url (string)       — permalink на /login/.
 *  $denial_message (string)  — текст уведомления.
 *
 * @package Cashback\SCAuthPages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @var string $login_url */
/** @var string $denial_message */
?>
<div class="sc-auth-pages-form sc-auth-pages-form--register-disabled">
    <?php
    $sc_register_title = (string) apply_filters('sc_auth_pages_register_form_title', __('Регистрация', 'cashback-plugin'));
    if ($sc_register_title !== '') :
        ?>
        <div class="sc-auth-pages-form__header">
            <h2 class="sc-auth-pages-form__title"><?php echo esc_html($sc_register_title); ?></h2>
        </div>
        <?php
    endif;
    ?>
    <div class="sc-auth-pages-form__notice woocommerce-info" role="status">
        <?php echo esc_html($denial_message); ?>
    </div>
    <p class="form-row">
        <a class="woocommerce-button button sc-auth-pages-form__submit" href="<?php echo esc_url($login_url); ?>">
            <?php esc_html_e('Войти', 'cashback-plugin'); ?>
        </a>
    </p>
</div>
