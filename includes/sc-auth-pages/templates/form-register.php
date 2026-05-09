<?php
/**
 * Шаблон формы регистрации.
 *
 * Доступные переменные:
 *  $login_url (string) — permalink на /login/.
 *
 * Внутри формы вызывается do_action('woocommerce_register_form'), благодаря чему
 * автоматически рендерятся существующие consent-чекбоксы (152-ФЗ + fraud) и
 * прочие WooCommerce-расширения, подключённые через стандартный хук.
 *
 * @package Cashback\SCAuthPages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @var string $login_url */
?>
<form class="woocommerce-form woocommerce-form-register sc-auth-pages-form sc-auth-pages-form--register" method="post" novalidate aria-label="<?php esc_attr_e('Форма регистрации', 'cashback-plugin'); ?>">
    <?php
    $sc_register_title = (string) apply_filters('sc_auth_pages_register_form_title', __('Регистрация', 'cashback-plugin'));
    if ($sc_register_title !== '') :
        ?>
        <div class="sc-auth-pages-form__header">
            <h2 class="sc-auth-pages-form__title"><?php echo esc_html($sc_register_title); ?></h2>
            <a class="sc-auth-pages-form__nav-link" href="<?php echo esc_url($login_url); ?>">
                <?php esc_html_e('Войти', 'cashback-plugin'); ?>
            </a>
        </div>
        <?php
    endif;
    ?>
    <?php
    do_action('sc_auth_pages_register_form_top'); // social-auth кнопки сверху
    if (has_action('sc_auth_pages_register_form_top')) :
        ?>
        <div class="sc-auth-pages-form-divider" aria-hidden="true">
            <?php esc_html_e('или', 'cashback-plugin'); ?>
        </div>
        <?php
    endif;
    ?>
    <?php wp_nonce_field('sc_auth_pages_register', '_sc_auth_nonce'); ?>
    <input type="hidden" name="sc_auth_action" value="register">

    <?php do_action('woocommerce_register_form_start'); ?>

    <p class="form-row form-row-wide">
        <label for="sc_auth_email">
            <?php esc_html_e('Email', 'cashback-plugin'); ?>&nbsp;<span class="required" aria-hidden="true">*</span>
        </label>
        <input
            type="email"
            class="woocommerce-Input woocommerce-Input--text input-text"
            name="email"
            id="sc_auth_email"
            autocomplete="email"
            required
            value="<?php echo esc_attr(isset($_POST['email']) ? sanitize_email(wp_unslash((string) $_POST['email'])) : ''); /* phpcs:ignore WordPress.Security.NonceVerification.Missing -- значение восстанавливаем после redirect; XSS защищён esc_attr. */ ?>"
        >
    </p>

    <?php
    $sc_auto_password = class_exists('Cashback_SC_Auth_Pages_Register')
        && Cashback_SC_Auth_Pages_Register::is_auto_password_mode();

    if (!$sc_auto_password) :
        ?>
        <p class="form-row form-row-wide">
            <label for="sc_auth_pwd">
                <?php esc_html_e('Пароль', 'cashback-plugin'); ?>&nbsp;<span class="required" aria-hidden="true">*</span>
            </label>
            <input
                type="password"
                class="woocommerce-Input woocommerce-Input--text input-text"
                name="password"
                id="sc_auth_pwd"
                autocomplete="new-password"
                minlength="8"
                required
            >
            <small class="sc-auth-pages-hint">
                <?php esc_html_e('Минимум 8 символов.', 'cashback-plugin'); ?>
            </small>
        </p>

        <p class="form-row form-row-wide">
            <label for="sc_auth_pwd2">
                <?php esc_html_e('Повторите пароль', 'cashback-plugin'); ?>&nbsp;<span class="required" aria-hidden="true">*</span>
            </label>
            <input
                type="password"
                class="woocommerce-Input woocommerce-Input--text input-text"
                name="password_confirm"
                id="sc_auth_pwd2"
                autocomplete="new-password"
                minlength="8"
                required
            >
        </p>
        <?php
    endif;
    ?>

    <p class="sc-auth-pages-hp" aria-hidden="true">
        <label>
            <?php esc_html_e('Email повторно (оставьте пустым)', 'cashback-plugin'); ?>
            <input type="text" name="email_2" tabindex="-1" autocomplete="off" value="">
        </label>
    </p>

    <?php do_action('woocommerce_register_form'); ?>

    <p class="form-row">
        <button type="submit"
                class="woocommerce-Button woocommerce-button button woocommerce-form-register__submit sc-auth-pages-form__submit"
                name="sc_auth_register_submit"
                value="1">
            <?php esc_html_e('Зарегистрироваться', 'cashback-plugin'); ?>
        </button>
    </p>

    <p class="sc-auth-pages-links">
        <a class="sc-auth-pages-links__login" href="<?php echo esc_url($login_url); ?>">
            <?php esc_html_e('Уже есть аккаунт? Войти', 'cashback-plugin'); ?>
        </a>
    </p>

    <?php if ($sc_auto_password) : ?>
        <p class="form-row form-row-wide sc-auth-pages-info">
            <?php esc_html_e('После регистрации мы отправим вам письмо со ссылкой на установку пароля.', 'cashback-plugin'); ?>
        </p>
    <?php endif; ?>

    <?php do_action('woocommerce_register_form_end'); ?>
</form>
