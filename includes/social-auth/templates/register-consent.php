<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template — выделенная страница согласий после OAuth-callback'а для НОВОГО юзера
 * (Branch D в Account_Manager → post-OAuth conditional consent, Auth0/GDPR pattern).
 *
 * Ожидаемые переменные (инициализируются в Router::render_register_consent_form):
 *  - $token           string  Токен pending-записи (KIND_REGISTER_VIA_SOCIAL).
 *  - $email           string  Email из OAuth-провайдера.
 *  - $provider_label  string  Человекочитаемое имя провайдера («Яндекс ID»).
 *  - $endpoint        string  URL POST-обработчика /wp-json/cashback/v1/social/register-consent.
 *  - $cb_error        string  Текст ошибки (если есть).
 *  - $site_name       string  Название сайта.
 *  - $pd_url          string  Ссылка на «Согласие на обработку ПД».
 *  - $policy_url      string  Ссылка на «Политику обработки ПД».
 *  - $offer_url       string  Ссылка на «Пользовательское соглашение (оферта)».
 *  - $fraud_url       string  Ссылка на 152-ФЗ ст. 9 (если задан documents).
 *
 * @var string $token
 * @var string $email
 * @var string $provider_label
 * @var string $endpoint
 * @var string $cb_error
 * @var string $site_name
 * @var string $pd_url
 * @var string $policy_url
 * @var string $offer_url
 * @var string $fraud_url
 */

if (!isset($token)) {
    $token = '';
}
if (!isset($email)) {
    $email = '';
}
if (!isset($provider_label) || $provider_label === '') {
    $provider_label = __('социальную сеть', 'cashback-plugin');
}
if (!isset($endpoint) || $endpoint === '') {
    $endpoint = rest_url('cashback/v1/social/register-consent');
}
if (!isset($cb_error)) {
    $cb_error = '';
}
if (!isset($site_name) || $site_name === '') {
    $site_name = (string) get_bloginfo('name');
}
foreach (array( 'pd_url', 'policy_url', 'offer_url', 'fraud_url' ) as $url_var) {
    if (!isset($$url_var)) {
        $$url_var = '';
    }
}

$nonce        = wp_create_nonce('wp_rest');
$cancel_url   = function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('myaccount') : home_url('/');
$cancel_label = __('Отменить и вернуться', 'cashback-plugin');

$link_or_text = static function ( string $url, string $text ): string {
    if ($url === '') {
        return esc_html($text);
    }
    return sprintf(
        '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
        esc_url($url),
        esc_html($text)
    );
};

?><!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
<meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>
<?php
/* translators: %s: site name */
echo esc_html(sprintf(__('Завершение регистрации — %s', 'cashback-plugin'), $site_name));
?>
</title>
<style>
    * { box-sizing: border-box; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        background: #f3f4f8;
        margin: 0;
        padding: 24px 16px;
        color: #1a1f3a;
        line-height: 1.5;
    }
    .wrap {
        max-width: 560px;
        margin: 0 auto;
        background: #fff;
        padding: 32px;
        border-radius: 12px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
    }
    h1 {
        font-size: 22px;
        margin: 0 0 12px;
        color: #1a1f3a;
    }
    .lead {
        font-size: 14px;
        color: #555;
        margin: 0 0 24px;
    }
    .info-banner {
        background: #eef3ff;
        border-left: 4px solid #4047d6;
        padding: 14px 16px;
        border-radius: 8px;
        margin: 0 0 24px;
        font-size: 14px;
        color: #1a1f3a;
    }
    .info-banner strong {
        display: block;
        font-size: 15px;
        margin-bottom: 4px;
    }
    .email-display {
        background: #f8f9fb;
        border: 1px solid #e1e4ed;
        border-radius: 8px;
        padding: 12px 16px;
        margin: 0 0 24px;
        font-size: 14px;
    }
    .email-display label {
        display: block;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        color: #777;
        margin-bottom: 4px;
    }
    .email-display .value {
        font-size: 15px;
        font-weight: 500;
        color: #1a1f3a;
        word-break: break-all;
    }
    .consent-row {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        margin: 14px 0;
        font-size: 14px;
        line-height: 1.45;
    }
    .consent-row input[type="checkbox"] {
        flex-shrink: 0;
        margin-top: 3px;
        width: 16px;
        height: 16px;
        cursor: pointer;
    }
    .consent-row label {
        cursor: pointer;
        color: #333;
    }
    .consent-row a {
        color: #3a44e0;
        text-decoration: underline;
    }
    .required {
        color: #d63638;
        margin-left: 2px;
    }
    button.submit {
        width: 100%;
        background: #4047d6;
        color: #fff;
        border: 0;
        border-radius: 8px;
        padding: 14px 18px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        margin-top: 24px;
        transition: background 0.15s ease;
    }
    button.submit:hover {
        background: #353cb8;
    }
    button.submit:disabled {
        background: #9ca3d4;
        cursor: not-allowed;
    }
    .cancel {
        display: block;
        text-align: center;
        margin-top: 16px;
        font-size: 13px;
        color: #777;
        text-decoration: underline;
    }
    .error {
        background: #fde8e8;
        color: #8a1f11;
        padding: 12px 14px;
        border-radius: 6px;
        margin: 0 0 18px;
        font-size: 14px;
    }
</style>
</head>
<body>
<div class="wrap">
    <h1>
    <?php
    /* translators: %s: provider label, e.g. "Яндекс ID" */
    echo esc_html(sprintf(__('Завершение регистрации через %s', 'cashback-plugin'), $provider_label));
    ?>
    </h1>

    <div class="info-banner">
        <strong>
        <?php
        /* translators: %s: provider label */
        echo esc_html(sprintf(__('Авторизация через %s почти завершена.', 'cashback-plugin'), $provider_label));
        ?>
        </strong>
        <?php esc_html_e('Чтобы создать аккаунт, отметьте все обязательные согласия ниже и нажмите «Даю согласие и регистрируюсь».', 'cashback-plugin'); ?>
    </div>

    <?php if ($cb_error !== '') : ?>
        <div class="error"><?php echo esc_html($cb_error); ?></div>
    <?php endif; ?>

    <div class="email-display">
        <label><?php esc_html_e('Email из соцсети', 'cashback-plugin'); ?></label>
        <div class="value"><?php echo esc_html($email); ?></div>
    </div>

    <form method="post" action="<?php echo esc_url($endpoint); ?>" id="cashback-register-consent-form">
        <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">

        <div class="consent-row">
            <input type="checkbox" name="consent_fraud" id="consent_fraud" value="1" required>
            <label for="consent_fraud">
                <?php esc_html_e('Согласен на сбор технических данных устройства (IP, fingerprint браузера, device ID) для защиты от мошенничества согласно', 'cashback-plugin'); ?>
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link_or_text производит escaped HTML.
                echo $link_or_text($fraud_url, __('152-ФЗ ст. 9', 'cashback-plugin'));
                ?>
                .<span class="required">*</span>
            </label>
        </div>

        <div class="consent-row">
            <input type="checkbox" name="consent_pd" id="consent_pd" value="1" required>
            <label for="consent_pd">
                <?php esc_html_e('Я даю', 'cashback-plugin'); ?>
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link_or_text производит escaped HTML.
                echo $link_or_text($pd_url, __('согласие на обработку персональных данных', 'cashback-plugin'));
                ?>
                <?php esc_html_e('и подтверждаю ознакомление с', 'cashback-plugin'); ?>
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link_or_text производит escaped HTML.
                echo $link_or_text($policy_url, __('Политикой обработки персональных данных', 'cashback-plugin'));
                ?>
                .<span class="required">*</span>
            </label>
        </div>

        <div class="consent-row">
            <input type="checkbox" name="consent_offer" id="consent_offer" value="1" required>
            <label for="consent_offer">
                <?php esc_html_e('Я принимаю условия', 'cashback-plugin'); ?>
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link_or_text производит escaped HTML.
                echo $link_or_text($offer_url, __('Пользовательского соглашения (публичной оферты)', 'cashback-plugin'));
                ?>
                .<span class="required">*</span>
            </label>
        </div>

        <button class="submit" type="submit"><?php esc_html_e('Даю согласие и регистрируюсь', 'cashback-plugin'); ?></button>

        <a class="cancel" href="<?php echo esc_url($cancel_url); ?>"><?php echo esc_html($cancel_label); ?></a>
    </form>
</div>
</body>
</html>
