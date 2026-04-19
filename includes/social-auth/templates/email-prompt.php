<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template — форма ввода email после социального входа без email (ветка C).
 *
 * Ожидаемые переменные (инициализируются в Router::handle_email_prompt_form):
 *  - $token       string  Токен pending-записи.
 *  - $cb_error       string  Текст ошибки (если есть).
 *  - $endpoint    string  URL POST-обработчика /wp-json/cashback/v1/social/email-prompt.
 *  - $provider    string  Идентификатор провайдера (для отображения).
 *  - $site_name   string  Название сайта.
 *
 * @var string $token
 * @var string $cb_error
 * @var string $endpoint
 * @var string $provider
 * @var string $site_name
 */

if (!isset($token)) {
    $token = '';
}
if (!isset($cb_error)) {
    $cb_error = '';
}
if (!isset($endpoint)) {
    $endpoint = rest_url('cashback/v1/social/email-prompt');
}
if (!isset($provider)) {
    $provider = '';
}
if (!isset($site_name)) {
    $site_name = get_bloginfo('name');
}

$nonce = wp_create_nonce('wp_rest');

?><!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
<meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html__('Подтверждение email — ', 'cashback-plugin') . esc_html($site_name); ?></title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f5; margin: 0; padding: 40px 20px; color: #222; }
    .wrap { max-width: 480px; margin: 0 auto; background: #fff; padding: 32px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    h1 { font-size: 20px; margin: 0 0 12px; }
    p.lead { font-size: 14px; color: #555; margin: 0 0 24px; }
    label { display: block; font-size: 14px; margin-bottom: 6px; }
    input[type=email] { width: 100%; padding: 10px 12px; font-size: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
    .consent { margin: 14px 0; font-size: 13px; color: #555; }
    .consent input { margin-right: 6px; }
    button { background: #2271b1; color: #fff; border: 0; border-radius: 4px; padding: 10px 18px; font-size: 15px; cursor: pointer; }
    button:hover { background: #135e96; }
    .error { background: #fde8e8; color: #8a1f11; padding: 10px 12px; border-radius: 4px; margin-bottom: 16px; font-size: 14px; }
</style>
</head>
<body>
<div class="wrap">
    <h1><?php esc_html_e('Укажите ваш email', 'cashback-plugin'); ?></h1>
    <p class="lead"><?php esc_html_e('Социальная сеть не передала email. Чтобы завершить регистрацию, введите email — мы отправим ссылку для подтверждения.', 'cashback-plugin'); ?></p>

    <?php if ($cb_error !== '') : ?>
        <div class="error"><?php echo esc_html($cb_error); ?></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url($endpoint); ?>">
        <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">

        <label for="cashback-social-email"><?php esc_html_e('Email', 'cashback-plugin'); ?></label>
        <input id="cashback-social-email" type="email" name="email" required autocomplete="email" placeholder="you@example.com">

        <div class="consent">
            <label>
                <input type="checkbox" name="consent" value="1" required>
                <?php
                printf(
                    /* translators: %s: site name */
                    esc_html__('Согласен с условиями регистрации на сайте %s', 'cashback-plugin'),
                    esc_html($site_name)
                );
                ?>
            </label>
        </div>

        <button type="submit"><?php esc_html_e('Отправить письмо', 'cashback-plugin'); ?></button>
    </form>
</div>
</body>
</html>
