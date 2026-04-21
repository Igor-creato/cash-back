<?php
/**
 * Baseline allowlist для исходящих HTTP-запросов плагина.
 *
 * Используется Cashback_Outbound_HTTP_Guard для защиты от SSRF:
 * любой запрос к хосту, не указанному здесь (и не добавленному админом
 * через UI в wp_option 'cashback_outbound_allowlist_custom' либо site-owner'ом
 * через фильтр 'cashback_outbound_allowlist'), будет отклонён.
 *
 * Расширять этот файл только в рамках PR на добавление новой CPA-сети
 * или нового внешнего сервиса плагина. Для локального dev-окружения
 * использовать CASHBACK_OUTBOUND_ALLOWLIST_RELAX в wp-config.php
 * (релаксирует только хост-allowlist; private-IP guard остаётся активным).
 *
 * @package CashbackPlugin
 * @since   7.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

return array(
    // Точные хосты (case-insensitive). FQDN только, без IP-литералов.
    'hosts'    => array(
        // EPN (CPA-сеть)
        'oauth2.epn.bz',
        'app.epn.bz',

        // Admitad (CPA-сеть)
        'api.admitad.com',

        // Yandex SmartCaptcha (антифрод)
        'smartcaptcha.yandexcloud.net',
    ),

    // Точечные суффиксы вида '.example.com' — хост проходит, если ЗАКАНЧИВАЕТСЯ
    // на '.example.com' (т.е. это поддомен). Используется с осторожностью,
    // только когда API-домены партнёра шардированы по поддоменам.
    'suffixes' => array(),
);
