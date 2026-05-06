<?php
/**
 * Шаблон формы входа.
 *
 * Доступные переменные:
 *  $redirect_to (string) — URL для редиректа после успешного логина (берётся из ?redirect_to=).
 *  $register_url (string) — permalink на /register/.
 *
 * @package Cashback\SCAuthPages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @var string $redirect_to */
/** @var string $register_url */
?>
<form class="woocommerce-form woocommerce-form-login sc-auth-pages-form sc-auth-pages-form--login" method="post" novalidate aria-label="<?php esc_attr_e('Форма входа', 'cashback-plugin'); ?>">
    <?php wp_nonce_field('sc_auth_pages_login', '_sc_auth_nonce'); ?>
    <input type="hidden" name="sc_auth_action" value="login">
    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>">

    <p class="form-row form-row-wide">
        <label for="sc_auth_log">
            <?php esc_html_e('Email или логин', 'cashback-plugin'); ?>&nbsp;<span class="required" aria-hidden="true">*</span>
        </label>
        <input
            type="text"
            class="woocommerce-Input woocommerce-Input--text input-text"
            name="log"
            id="sc_auth_log"
            autocomplete="username"
            required
            value="<?php echo esc_attr(isset($_POST['log']) ? sanitize_text_field(wp_unslash((string) $_POST['log'])) : ''); /* phpcs:ignore WordPress.Security.NonceVerification.Missing -- значение восстанавливаем после redirect; XSS защищён esc_attr. */ ?>"
        >
    </p>

    <p class="form-row form-row-wide">
        <label for="sc_auth_pwd">
            <?php esc_html_e('Пароль', 'cashback-plugin'); ?>&nbsp;<span class="required" aria-hidden="true">*</span>
        </label>
        <input
            type="password"
            class="woocommerce-Input woocommerce-Input--text input-text"
            name="pwd"
            id="sc_auth_pwd"
            autocomplete="current-password"
            required
        >
    </p>

    <p class="form-row sc-auth-pages-rememberme">
        <label class="woocommerce-form__label woocommerce-form__label-for-checkbox woocommerce-form-login__rememberme">
            <input
                class="woocommerce-form__input woocommerce-form__input-checkbox"
                name="rememberme"
                type="checkbox"
                id="sc_auth_rememberme"
                value="forever"
            >
            <span><?php esc_html_e('Запомнить меня', 'cashback-plugin'); ?></span>
        </label>
    </p>

    <p class="form-row">
        <button type="submit"
                class="woocommerce-button button woocommerce-form-login__submit sc-auth-pages-form__submit"
                name="sc_auth_login_submit"
                value="1">
            <?php esc_html_e('Войти', 'cashback-plugin'); ?>
        </button>
    </p>

    <p class="sc-auth-pages-links">
        <a class="sc-auth-pages-links__lost" href="<?php echo esc_url(function_exists('wc_lostpassword_url') ? wc_lostpassword_url() : wp_lostpassword_url()); ?>">
            <?php esc_html_e('Забыли пароль?', 'cashback-plugin'); ?>
        </a>
        <span class="sc-auth-pages-links__sep" aria-hidden="true">·</span>
        <a class="sc-auth-pages-links__register" href="<?php echo esc_url($register_url); ?>">
            <?php esc_html_e('Создать аккаунт', 'cashback-plugin'); ?>
        </a>
    </p>

    <?php do_action('woocommerce_login_form_end'); ?>
</form>
