<?php
/**
 * WP-CLI entry: дедупликация wp_cashback_fraud_device_ids перед UNIQUE.
 *
 * Запуск: wp eval-file tools/dedup-rows-fraud-device-ids.php [--confirm=yes] [--limit=N]
 * По умолчанию — dry-run. См. tools/README.md.
 *
 * @package Cashback
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    fwrite(STDERR, "[cashback-dedup] ABSPATH undefined. Run via `wp eval-file`.\n");
    exit(1);
}

if (!defined('WP_CLI') && !( function_exists('current_user_can') && current_user_can('manage_options') )) {
    fwrite(STDERR, "[cashback-dedup] Requires WP-CLI context or manage_options capability.\n");
    exit(1);
}

require_once __DIR__ . '/lib/class-cashback-dedup-strategy-interface.php';
require_once __DIR__ . '/lib/class-cashback-dedup-runner.php';
require_once __DIR__ . '/lib/class-cashback-dedup-fraud-device-ids-strategy.php';

global $wpdb;

$dry_run  = true;
$limit    = 0;
$cli_args = isset($args) && is_array($args) ? $args : array();
foreach ($cli_args as $cli_arg) {
    if (!is_string($cli_arg)) {
        continue;
    }
    if (preg_match('/^--confirm=yes$/i', $cli_arg)) {
        $dry_run = false;
    } elseif (preg_match('/^--limit=(\d+)$/', $cli_arg, $m)) {
        $limit = (int) $m[1];
    }
}

$runner = new Cashback_Dedup_Runner(
    $wpdb,
    new Cashback_Dedup_Fraud_Device_Ids_Strategy(),
    array(
		'dry_run' => $dry_run,
		'limit'   => $limit,
	)
);
$stats  = $runner->run();

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-скрипт: печатает числа и константу scope из стратегии в stdout WP-CLI.
printf( "[cashback-dedup-%s] mode=%s groups=%d deleted=%d relinked=%d errors=%d\n", $stats['scope'], $dry_run ? 'dry-run' : 'destructive', $stats['groups'], $stats['deleted'], $stats['relinked'], $stats['errors'] );
