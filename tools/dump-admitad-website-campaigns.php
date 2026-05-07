<?php
/**
 * Dev-tool: дампит сырой ответ Admitad /advcampaigns/website/{id}/ для одной
 * страницы — позволяет диагностировать какие поля реально приходят (gotolink,
 * actions_detail, image, и т.д.) после первого реального импорта.
 *
 * НЕ запускать на боевом без надобности — токен дёрнется один раз.
 *
 * Usage:
 *   wp eval-file wp-content/plugins/cash-back/tools/dump-admitad-website-campaigns.php <network_id>
 *
 * Опционально (через env-переменные):
 *   CB_DUMP_LIMIT=5  — сколько кампаний вывести (default 3).
 *   CB_DUMP_FULL=1   — вывести полный raw payload (по умолчанию короткая выборка ключей).
 *
 * @package CashbackPlugin
 */

declare(strict_types=1);

// CLI dev-tool: всё output идёт в stdout WP-CLI; HTML-escape не применим.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
// phpcs:disable Squiz.Strings.DoubleQuoteUsage.NotRequired

if (! defined('ABSPATH')) {
    fwrite(STDERR, "[cashback-dump-admitad] ABSPATH undefined. Run via `wp eval-file`.\n");
    exit(1);
}

if (! defined('WP_CLI') && ! ( function_exists('current_user_can') && current_user_can('manage_options') )) {
    fwrite(STDERR, "[cashback-dump-admitad] Requires WP-CLI context or manage_options capability.\n");
    exit(1);
}

$cli_args   = isset($args) && is_array($args) ? $args : array();
$network_id = isset($cli_args[0]) ? (int) $cli_args[0] : 0;
if ($network_id <= 0) {
    echo 'Usage: wp eval-file ... dump-admitad-website-campaigns.php <network_id>', "\n";
    return;
}

$limit = (int) ( getenv('CB_DUMP_LIMIT') ?: '3' );
$full  = (bool) getenv('CB_DUMP_FULL');

if (! class_exists('Cashback_API_Client')) {
    echo "Cashback_API_Client недоступен.\n";
    return;
}

$api_client = Cashback_API_Client::get_instance();
$network    = $api_client->get_network_config('admitad');
if (! is_array($network) || (int) ( $network['id'] ?? 0 ) !== $network_id) {
    printf("Сеть admitad с id=%d не найдена или неактивна.\n", $network_id);
    return;
}

$creds = $api_client->get_credentials($network_id);
if (! is_array($creds)) {
    printf("Credentials не настроены для network_id=%d.\n", $network_id);
    return;
}

$adapter = $api_client->get_adapter('admitad');
if ($adapter === null || ! method_exists($adapter, 'fetch_campaigns_detailed')) {
    echo "Adapter admitad не зарегистрирован или метод недоступен.\n";
    return;
}

printf(
    "=== Admitad /advcampaigns/website/%s/?limit=%d&offset=0 ===\n\n",
    (string) ( $network['api_website_id'] ?? '' ),
    $limit
);

$result = $adapter->fetch_campaigns_detailed($creds, $network, 0, $limit);

if (empty($result['success'])) {
    printf("ERROR: %s\n", (string) ( $result['error'] ?? 'unknown' ));
    return;
}

$campaigns = $result['campaigns'] ?? array();
printf(
    "Получено: %d кампаний; has_next=%s\n\n",
    count($campaigns),
    ! empty($result['has_next']) ? 'true' : 'false'
);

foreach ($campaigns as $i => $campaign) {
    printf("--- Campaign #%d ---\n", (int) $i);
    printf("id=%s, name=%s\n", (string) $campaign['id'], (string) $campaign['name']);
    printf("site_url=%s\n", (string) $campaign['site_url']);
    printf("image_url=%s\n", (string) $campaign['image_url']);
    printf(
        "goto_link=%s\n",
        $campaign['goto_link'] !== '' ? (string) $campaign['goto_link'] : '(пусто!)'
    );
    printf(
        "connection_status=%s status_raw=%s is_active=%s\n",
        (string) $campaign['connection_status'],
        (string) $campaign['status_raw'],
        $campaign['is_active'] ? 'true' : 'false'
    );
    printf("currency=%s\n", (string) $campaign['currency']);

    $raw = $campaign['raw'] ?? array();

    // Какие поля реально пришли с API (только ключи, без значений).
    printf("raw keys: %s\n", implode(', ', array_keys($raw)));

    // Inline actions / actions_detail — если Admitad их кладёт в campaign payload,
    // это решит проблему с тарифами без отдельного fetch_shop_tariffs.
    if (isset($raw['actions'])) {
        $arr = (array) $raw['actions'];
        printf(
            "raw.actions (count=%d): %s\n",
            count($arr),
            (string) wp_json_encode(array_slice($arr, 0, 2))
        );
    }
    if (isset($raw['actions_detail'])) {
        $arr = (array) $raw['actions_detail'];
        printf(
            "raw.actions_detail (count=%d): %s\n",
            count($arr),
            (string) wp_json_encode(array_slice($arr, 0, 2))
        );
    }
    // Иногда поле называется rates / tariffs.
    foreach (array( 'rates', 'tariffs', 'gotolink', 'goto_link', 'image' ) as $alt) {
        if (isset($raw[ $alt ])) {
            $val     = $raw[ $alt ];
            $val_str = is_scalar($val) ? (string) $val : (string) wp_json_encode($val);
            printf("raw.%s=%s\n", (string) $alt, mb_substr($val_str, 0, 200));
        }
    }

    if ($full) {
        echo "FULL RAW:\n";
        echo (string) wp_json_encode(
            $raw,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        echo "\n";
    }

    echo "\n";
}

// Дополнительно: дёрнем /advcampaigns/{id}/actions/ для первой кампании
// чтобы видеть какой scope/error даёт actions endpoint.
if (! empty($campaigns) && method_exists($adapter, 'fetch_shop_tariffs')) {
    $first_id = (string) $campaigns[0]['id'];
    printf("=== /advcampaigns/%s/actions/ (для первой кампании) ===\n", $first_id);
    $tariffs_result = $adapter->fetch_shop_tariffs($creds, $network, $first_id);
    if (empty($tariffs_result['success'])) {
        printf("ERROR: %s\n", (string) ( $tariffs_result['error'] ?? 'unknown' ));
    } else {
        $tariffs = $tariffs_result['tariffs'] ?? array();
        printf("Получено тарифов: %d\n", count($tariffs));
        foreach ($tariffs as $t) {
            printf(
                "  - tariff_id=%s type=%s payment_size=%s %s\n",
                (string) $t['tariff_id'],
                (string) $t['tariff_type'],
                (string) $t['payment_size'],
                (string) $t['currency']
            );
        }
    }
}

// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
// phpcs:enable Squiz.Strings.DoubleQuoteUsage.NotRequired
