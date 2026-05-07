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

if (! defined('ABSPATH')) {
    fwrite(STDERR, "Запускать только через wp eval-file (нужна загрузка WP).\n");
    exit(1);
}

$network_id = isset($args[0]) ? (int) $args[0] : 0;
if ($network_id <= 0) {
    echo "Usage: wp eval-file ... dump-admitad-website-campaigns.php <network_id>\n";
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
    echo "Сеть admitad с id={$network_id} не найдена или неактивна.\n";
    return;
}

$creds = $api_client->get_credentials($network_id);
if (! is_array($creds)) {
    echo "Credentials не настроены для network_id={$network_id}.\n";
    return;
}

$adapter = $api_client->get_adapter('admitad');
if ($adapter === null || ! method_exists($adapter, 'fetch_campaigns_detailed')) {
    echo "Adapter admitad не зарегистрирован или метод недоступен.\n";
    return;
}

echo "=== Admitad /advcampaigns/website/{$network['api_website_id']}/?limit={$limit}&offset=0 ===\n\n";

$result = $adapter->fetch_campaigns_detailed($creds, $network, 0, $limit);

if (empty($result['success'])) {
    echo "ERROR: " . ( $result['error'] ?? 'unknown' ) . "\n";
    return;
}

$campaigns = $result['campaigns'] ?? array();
echo "Получено: " . count($campaigns) . " кампаний; has_next=" . ( ! empty($result['has_next']) ? 'true' : 'false' ) . "\n\n";

foreach ($campaigns as $i => $campaign) {
    echo "--- Campaign #{$i} ---\n";
    echo "id={$campaign['id']}, name={$campaign['name']}\n";
    echo "site_url={$campaign['site_url']}\n";
    echo "image_url={$campaign['image_url']}\n";
    echo "goto_link=" . ( $campaign['goto_link'] !== '' ? $campaign['goto_link'] : '(пусто!)' ) . "\n";
    echo "connection_status={$campaign['connection_status']} status_raw={$campaign['status_raw']} is_active=" . ( $campaign['is_active'] ? 'true' : 'false' ) . "\n";
    echo "currency={$campaign['currency']}\n";

    $raw = $campaign['raw'] ?? array();

    // Какие поля реально пришли с API (только ключи, без значений).
    echo "raw keys: " . implode(', ', array_keys($raw)) . "\n";

    // Inline actions / actions_detail — если Admitad их кладёт в campaign payload,
    // это решит проблему с тарифами без отдельного fetch_shop_tariffs.
    if (isset($raw['actions'])) {
        echo "raw.actions (count=" . count((array) $raw['actions']) . "): "
            . ( is_array($raw['actions']) ? wp_json_encode(array_slice((array) $raw['actions'], 0, 2)) : '?' ) . "\n";
    }
    if (isset($raw['actions_detail'])) {
        echo "raw.actions_detail (count=" . count((array) $raw['actions_detail']) . "): "
            . ( is_array($raw['actions_detail']) ? wp_json_encode(array_slice((array) $raw['actions_detail'], 0, 2)) : '?' ) . "\n";
    }
    // Иногда поле называется rates / tariffs.
    foreach (array( 'rates', 'tariffs', 'gotolink', 'goto_link', 'image' ) as $alt) {
        if (isset($raw[ $alt ])) {
            $val = $raw[ $alt ];
            $s   = is_scalar($val) ? (string) $val : wp_json_encode($val);
            echo "raw.{$alt}=" . mb_substr((string) $s, 0, 200) . "\n";
        }
    }

    if ($full) {
        echo "FULL RAW:\n";
        echo wp_json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }

    echo "\n";
}

// Дополнительно: дёрнем /advcampaigns/{id}/actions/ для первой кампании
// чтобы видеть какой scope/error даёт actions endpoint.
if (! empty($campaigns) && method_exists($adapter, 'fetch_shop_tariffs')) {
    $first_id = (string) $campaigns[0]['id'];
    echo "=== /advcampaigns/{$first_id}/actions/ (для первой кампании) ===\n";
    $tariffs_result = $adapter->fetch_shop_tariffs($creds, $network, $first_id);
    if (empty($tariffs_result['success'])) {
        echo "ERROR: " . ( $tariffs_result['error'] ?? 'unknown' ) . "\n";
    } else {
        $tariffs = $tariffs_result['tariffs'] ?? array();
        echo "Получено тарифов: " . count($tariffs) . "\n";
        foreach ($tariffs as $t) {
            echo "  - tariff_id={$t['tariff_id']} type={$t['tariff_type']} payment_size={$t['payment_size']} {$t['currency']}\n";
        }
    }
}
