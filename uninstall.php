<?php

declare(strict_types=1);

/**
 * Uninstall script for Cashback Plugin
 *
 * This file is executed when the plugin is uninstalled via WordPress admin.
 * It cleans up all plugin data including tables, options, and scheduled events.
 *
 * @package Cashback_Plugin
 */

// Exit if accessed directly or not uninstalling
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Security check: only admins can uninstall
if (!current_user_can('activate_plugins')) {
    exit;
}

/**
 * Clean up plugin data
 */
function cashback_plugin_uninstall(): void {
    global $wpdb;

    // Remove scheduled cron events
    $cron_hooks = array(
        'cashback_support_auto_delete_cron',
        'cashback_health_check_cron',
        'cashback_fraud_detection_cron',
        'cashback_fraud_cleanup_cron',
        'cashback_notification_process_queue',
    );
    foreach ($cron_hooks as $hook) {
        $timestamp = wp_next_scheduled($hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
        }
    }

    // Validate table prefix for security
    $prefix = $wpdb->prefix;
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
        error_log('Cashback Plugin Uninstall: Invalid table prefix detected');
        return;
    }

    // List of tables to drop
    $tables = array(
        // Affiliate module (drop first — FK dependencies)
        // cashback_affiliate_ledger удалён — данные мигрированы в cashback_balance_ledger
        "{$prefix}cashback_affiliate_accruals",
        "{$prefix}cashback_affiliate_clicks",
        "{$prefix}cashback_affiliate_profiles",
        // Antifraud
        "{$prefix}cashback_fraud_signals",
        "{$prefix}cashback_fraud_alerts",
        "{$prefix}cashback_user_fingerprints",
        "{$prefix}cashback_balance_ledger",
        "{$prefix}cashback_payout_requests",
        "{$prefix}cashback_transactions",
        "{$prefix}cashback_unregistered_transactions",
        "{$prefix}cashback_user_balance",
        "{$prefix}cashback_webhooks",
        "{$prefix}cashback_user_profile",
        "{$prefix}cashback_payout_methods",
        "{$prefix}cashback_affiliate_network_params",
        "{$prefix}cashback_affiliate_networks",
        "{$prefix}cashback_banks",
        "{$prefix}cashback_support_attachments",
        "{$prefix}cashback_support_tickets",
        "{$prefix}cashback_support_messages",
        "{$prefix}cashback_audit_log",
        "{$prefix}cashback_click_log",
        "{$prefix}cashback_sync_log",
        "{$prefix}cashback_validation_checkpoints",
        "{$prefix}cashback_rate_history",
        // Claims module (drop before claims — FK dependency)
        "{$prefix}cashback_claim_events",
        "{$prefix}cashback_claims",
        // Notifications module
        "{$prefix}cashback_notification_queue",
        "{$prefix}cashback_notification_preferences",
    );

    // Drop triggers
    $triggers = array(
        "{$prefix}calculate_cashback_before_insert",
        "{$prefix}calculate_cashback_before_insert_unregistered",
        "{$prefix}calculate_cashback_before_update",
        "{$prefix}calculate_cashback_before_update_unregistered",
        "{$prefix}cashback_tr_prevent_delete_final_status",
        "{$prefix}cashback_tr_prevent_update_final_status",
        "{$prefix}cashback_tr_validate_status_transition",
        "{$prefix}cashback_tr_validate_status_transition_unregistered",
        "{$prefix}tr_prevent_delete_paid_payout",
        "{$prefix}tr_prevent_update_paid_payout",
        "{$prefix}tr_prevent_delete_failed_payout",
        "{$prefix}tr_prevent_update_failed_payout",
        "{$prefix}tr_banned_user_update_banned_at",
        "{$prefix}tr_freeze_balance_on_ban",
        "{$prefix}tr_clear_ban_on_unban",
        "{$prefix}tr_unfreeze_balance_on_unban",
        // tr_notify_transaction_insert/update удалены (запись в очередь на уровне приложения),
        // но DROP оставлен для старых установок
        "{$prefix}tr_notify_transaction_insert",
        "{$prefix}tr_notify_transaction_update",
        "{$prefix}tr_webhook_payload_hash",
    );

    // Drop events
    $events = array(
        // cashback_ev_confirmed_cashback удалён в новой версии (заменён PHP cron)
        // DROP на случай если остался от старых установок
        "{$prefix}cashback_ev_confirmed_cashback",
        "{$prefix}cashback_ev_cleanup_cashback_webhooks_old",
        "{$prefix}cashback_ev_cleanup_click_log",
        "{$prefix}cashback_ev_mark_inactive_profiles",
    );

    // Drop triggers
    foreach ($triggers as $trigger) {
        $wpdb->query($wpdb->prepare('DROP TRIGGER IF EXISTS `%i`', $trigger));
    }

    // Drop events
    foreach ($events as $event) {
        $wpdb->query($wpdb->prepare('DROP EVENT IF EXISTS `%i`', $event));
    }

    // Удаление файлов вложений поддержки
    $upload_dir  = wp_upload_dir();
    $support_dir = $upload_dir['basedir'] . '/cashback-support';
    if (is_dir($support_dir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($support_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($support_dir);
    }

    // Drop tables (in reverse order to respect foreign keys)
    foreach (array_reverse($tables) as $table) {
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS `%i`', $table));
    }

    // Delete plugin options
    $options = array(
        'cashback_max_withdrawal_amount',
        'cashback_email_sender_name',
        'cashback_support_module_enabled',
        'cashback_support_attachments_enabled',
        'cashback_support_max_file_size',
        'cashback_support_max_files_per_message',
        'cashback_support_allowed_extensions',
        'cashback_plugin_version',
        // Antifraud settings
        'cashback_fraud_enabled',
        'cashback_fraud_max_users_per_ip',
        'cashback_fraud_max_users_per_fingerprint',
        'cashback_fraud_max_withdrawals_per_day',
        'cashback_fraud_max_withdrawals_per_week',
        'cashback_fraud_cancellation_rate_threshold',
        'cashback_fraud_cancellation_min_transactions',
        'cashback_fraud_amount_anomaly_multiplier',
        'cashback_fraud_new_account_cooling_days',
        'cashback_fraud_auto_hold_amount',
        'cashback_fraud_max_accounts_per_details_hash',
        'cashback_fraud_fingerprint_retention_days',
        'cashback_fraud_auto_flag_threshold',
        'cashback_fraud_email_notification_enabled',
        'cashback_fraud_last_run',
        // Trigger/event status flags
        'cashback_triggers_active',
        'cashback_events_active',
        // API sync results
        'cashback_last_sync_result',
        // Global lock
        'cashback_global_lock_active',
        // Affiliate module
        'cashback_affiliate_module_enabled',
        'cashback_affiliate_global_rate',
        'cashback_affiliate_cookie_ttl',
        'cashback_affiliate_rules_url',
        'cashback_affiliate_antifraud_enabled',
        // Claims module
        'cashback_claims_blocked_merchants',
        'cashback_claims_max_per_day',
        'cashback_claims_max_per_week',
        // Notifications module (global toggles)
        'cashback_notify_transaction_new',
        'cashback_notify_transaction_status',
        'cashback_notify_cashback_credited',
        'cashback_notify_ticket_reply',
        'cashback_notify_claim_created',
        'cashback_notify_claim_status',
        'cashback_notify_user_registered',
        'cashback_notify_ticket_admin_alert',
        'cashback_notify_claim_admin_alert',
        'cashback_email_sender_email',
        // Бот-защита
        'cashback_bot_protection_enabled',
        'cashback_captcha_client_key',
        'cashback_captcha_server_key',
        'cashback_bot_grey_threshold',
        'cashback_bot_block_threshold',
    );

    foreach ($options as $option) {
        delete_option($option);
    }

    // Delete affiliate network and product params post_meta from all products
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_affiliate_network_id'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_affiliate_product_params'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_offer_id'));

    // Delete campaign auto-deactivation post_meta
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_cashback_auto_deactivated'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_cashback_deactivation_reason'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_cashback_deactivated_at'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_cashback_deactivated_network'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_cashback_admin_override'));
    // Clean up old affiliate param meta (if any remain from pre-2.0)
    $like_key = $wpdb->esc_like('_affiliate_param_') . '%' . $wpdb->esc_like('_key');
    $like_val = $wpdb->esc_like('_affiliate_param_') . '%' . $wpdb->esc_like('_value');
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
        $like_key,
        $like_val
    ));

    // Delete transients
    delete_transient('cashback_support_flush_rules');
    delete_transient('cashback_ext_stores_cache');

    // Delete rate limiting and plugin transients
    $transient_prefixes = array(
        'cb_pp_',
		'cb_gl_',
		'cb_ip_',
		'cb_decrypt_rate_',
		'cb_support_rate_',
		'cb_fp_rate_',
        'cb_bank_search_rate_',
		'cb_balance_rate_',
		'cb_load_ticket_rate_',
        'cb_close_ticket_rate_',
		'cb_hist_page_rate_',
		'cb_payout_page_rate_',
        'cb_api_sync_rate_',
		'cb_api_validate_rate_',
		'cb_fraud_scan_rate_',
        'cb_aff_ref_',
        // Бот-защита: rate limiter, grey scoring, CAPTCHA verification cache
        'cb_rl_',
		'cb_grey_',
		'cb_cap_',
        // Контактная форма: rate limit
        'cb_contact_rate_',
    );
    $where_parts        = array();
    $values             = array();
    foreach ($transient_prefixes as $p) {
        $where_parts[] = 'option_name LIKE %s';
        $where_parts[] = 'option_name LIKE %s';
        $values[]      = $wpdb->esc_like('_transient_' . $p) . '%';
        $values[]      = $wpdb->esc_like('_transient_timeout_' . $p) . '%';
    }
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE " . implode(' OR ', $where_parts),
        ...$values
    ));

    // Delete campaign status options (cashback_campaign_status_*)
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('cashback_campaign_status_') . '%'
    ));

    // Delete encryption key file
    $key_file = WP_CONTENT_DIR . '/.cashback-encryption-key.php';
    if (file_exists($key_file)) {
        unlink($key_file);
    }

    // Clear any cached data
    wp_cache_flush();

    // Log uninstall completion
    error_log('Cashback Plugin: Successfully uninstalled and cleaned up all data');
}

// Execute uninstall
cashback_plugin_uninstall();
