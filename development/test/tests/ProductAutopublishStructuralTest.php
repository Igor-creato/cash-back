<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты на Cashback_Product_Autopublish — per-product
 * переключатель «Автопубликация» в Publish-метабоксе товара.
 *
 * Покрытие:
 *   - Класс существует, имеет нужные константы и методы.
 *   - register() навешивает три action'а: post_submitbox_misc_actions,
 *     save_post_product, transition_post_status (priority 5).
 *   - render_publish_toggle: nonce + product-only + capability check.
 *   - save_meta: skip autosave/revisions, capability, nonce verify.
 *   - on_transition_clear_markers: фильтр на publish + post_type=product,
 *     удаляет 4 маркера деактивации.
 *   - ensure_backfilled / handle_backfill_cron / backfill_all: гейтится
 *     опцией cashback_product_autopublish_backfill_v1, выборка
 *     publish OR (draft AND _cashback_auto_deactivated='1').
 *   - SQL реактивации в Cashback_API_Client::check_campaign_statuses()
 *     содержит INNER JOIN на _cashback_auto_publish_enabled='1'.
 *   - ajax_reactivate_product() ставит _cashback_auto_publish_enabled='1'.
 *   - cashback-plugin.php: require + register + ensure_backfilled.
 *
 * @group shop-import
 * @group product-autopublish
 */
#[Group('shop-import')]
#[Group('product-autopublish')]
final class ProductAutopublishStructuralTest extends TestCase
{
    private static string $plugin_root;
    private static string $autopub_php;
    private static string $api_client_php;
    private static string $admin_validation_php;
    private static string $cashback_plugin_php;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root          = dirname(__DIR__, 3);
        self::$autopub_php          = (string) file_get_contents(self::$plugin_root . '/includes/shops/class-cashback-product-autopublish.php');
        self::$api_client_php       = (string) file_get_contents(self::$plugin_root . '/includes/class-cashback-api-client.php');
        self::$admin_validation_php = (string) file_get_contents(self::$plugin_root . '/admin/class-cashback-admin-api-validation.php');
        self::$cashback_plugin_php  = (string) file_get_contents(self::$plugin_root . '/cashback-plugin.php');
    }

    // ============================================================
    // 1. Файл и базовые требования.
    // ============================================================

    public function test_class_file_exists(): void
    {
        $this->assertFileExists(
            self::$plugin_root . '/includes/shops/class-cashback-product-autopublish.php'
        );
    }

    public function test_declares_class_cashback_product_autopublish(): void
    {
        $this->assertMatchesRegularExpression(
            '/class\s+Cashback_Product_Autopublish/i',
            self::$autopub_php
        );
    }

    public function test_uses_strict_types_and_abspath_guard(): void
    {
        $this->assertStringContainsString('declare(strict_types=1);', self::$autopub_php);
        $this->assertStringContainsString("defined('ABSPATH')", self::$autopub_php);
    }

    // ============================================================
    // 2. Константы.
    // ============================================================

    public function test_defines_required_constants(): void
    {
        $this->assertMatchesRegularExpression(
            "/const\s+META_KEY\s*=\s*'_cashback_auto_publish_enabled'/",
            self::$autopub_php
        );
        $this->assertMatchesRegularExpression(
            "/const\s+NONCE_ACTION\s*=\s*'cashback_autopublish_save'/",
            self::$autopub_php
        );
        $this->assertMatchesRegularExpression(
            "/const\s+NONCE_FIELD\s*=\s*'cashback_autopublish_nonce'/",
            self::$autopub_php
        );
        $this->assertMatchesRegularExpression(
            "/const\s+POST_FIELD\s*=\s*'cashback_autopublish'/",
            self::$autopub_php
        );
        $this->assertMatchesRegularExpression(
            "/const\s+BACKFILL_OPTION\s*=\s*'cashback_product_autopublish_backfill_v1'/",
            self::$autopub_php
        );
        $this->assertMatchesRegularExpression(
            "/const\s+CRON_BACKFILL_HOOK\s*=\s*'cashback_product_autopublish_backfill'/",
            self::$autopub_php
        );
    }

    // ============================================================
    // 3. register() — навешаны три action'а.
    // ============================================================

    public function test_register_adds_post_submitbox_misc_actions(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'post_submitbox_misc_actions'\s*,\s*array\s*\(\s*__CLASS__\s*,\s*'render_publish_toggle'\s*\)/",
            self::$autopub_php,
            'render_publish_toggle должен быть навешан на post_submitbox_misc_actions'
        );
    }

    public function test_register_adds_save_post_product_action(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'save_post_product'\s*,\s*array\s*\(\s*__CLASS__\s*,\s*'save_meta'\s*\)\s*,\s*10\s*,\s*2\s*\)/",
            self::$autopub_php,
            'save_meta должен быть навешан на save_post_product, priority=10, args=2'
        );
    }

    public function test_register_adds_transition_post_status_priority_5(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'transition_post_status'\s*,\s*array\s*\(\s*__CLASS__\s*,\s*'on_transition_clear_markers'\s*\)\s*,\s*5\s*,\s*3\s*\)/",
            self::$autopub_php,
            'on_transition_clear_markers должен быть навешан на transition_post_status с priority=5'
        );
    }

    public function test_register_adds_cron_backfill_handler(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*self::CRON_BACKFILL_HOOK\s*,\s*array\s*\(\s*__CLASS__\s*,\s*'handle_backfill_cron'\s*\)/",
            self::$autopub_php
        );
    }

    public function test_register_is_idempotent(): void
    {
        // static $registered гарантирует одноразовый вызов add_action.
        $this->assertMatchesRegularExpression(
            '/static\s+\$registered\s*=\s*false\s*;.*?if\s*\(\s*\$registered\s*\)\s*\{[^}]*return\s*;/s',
            self::$autopub_php
        );
    }

    // ============================================================
    // 4. render_publish_toggle — nonce + product-only + capability.
    // ============================================================

    public function test_render_publish_toggle_emits_nonce_field(): void
    {
        $this->assertMatchesRegularExpression(
            '/wp_nonce_field\s*\(\s*self::NONCE_ACTION\s*,\s*self::NONCE_FIELD\s*\)/',
            self::$autopub_php
        );
    }

    public function test_render_publish_toggle_filters_product_post_type(): void
    {
        $body = $this->extract_method_body('render_publish_toggle');
        $this->assertStringContainsString(
            "\$post_type !== 'product'",
            $body,
            'render_publish_toggle обязан early-return на не-product'
        );
    }

    public function test_render_publish_toggle_capability_check(): void
    {
        $body = $this->extract_method_body('render_publish_toggle');
        $this->assertMatchesRegularExpression(
            "/current_user_can\s*\(\s*'edit_post'\s*,\s*\\\$post_id\s*\)/",
            $body
        );
    }

    // ============================================================
    // 5. save_meta — skip autosave/revisions, capability, nonce.
    // ============================================================

    public function test_save_meta_skips_autosave_and_revisions(): void
    {
        $body = $this->extract_method_body('save_meta');
        $this->assertStringContainsString('wp_is_post_autosave', $body);
        $this->assertStringContainsString('wp_is_post_revision', $body);
        $this->assertStringContainsString('DOING_AUTOSAVE', $body);
    }

    public function test_save_meta_capability_check(): void
    {
        $body = $this->extract_method_body('save_meta');
        $this->assertMatchesRegularExpression(
            "/current_user_can\s*\(\s*'edit_post'\s*,\s*\\\$post_id\s*\)/",
            $body
        );
    }

    public function test_save_meta_verifies_nonce(): void
    {
        $body = $this->extract_method_body('save_meta');
        $this->assertMatchesRegularExpression(
            '/wp_verify_nonce\s*\(\s*\$nonce\s*,\s*self::NONCE_ACTION\s*\)/',
            $body
        );
    }

    public function test_save_meta_writes_or_deletes_meta(): void
    {
        $body = $this->extract_method_body('save_meta');
        $this->assertMatchesRegularExpression(
            "/update_post_meta\s*\(\s*\\\$post_id\s*,\s*self::META_KEY\s*,\s*'1'\s*\)/",
            $body
        );
        $this->assertMatchesRegularExpression(
            '/delete_post_meta\s*\(\s*\$post_id\s*,\s*self::META_KEY\s*\)/',
            $body
        );
    }

    // ============================================================
    // 6. on_transition_clear_markers — фильтры + 4 маркера.
    // ============================================================

    public function test_on_transition_filters_publish_status(): void
    {
        $body = $this->extract_method_body('on_transition_clear_markers');
        $this->assertStringContainsString(
            "\$new_status !== 'publish'",
            $body,
            'on_transition_clear_markers должен срабатывать только на → publish'
        );
        $this->assertStringContainsString(
            '$new_status === $old_status',
            $body,
            'идемпотентность на publish→publish'
        );
    }

    public function test_on_transition_filters_product_post_type(): void
    {
        $body = $this->extract_method_body('on_transition_clear_markers');
        $this->assertStringContainsString(
            "\$post_type !== 'product'",
            $body
        );
    }

    public function test_on_transition_skips_revisions_and_autosaves(): void
    {
        $body = $this->extract_method_body('on_transition_clear_markers');
        $this->assertStringContainsString('wp_is_post_revision', $body);
        $this->assertStringContainsString('wp_is_post_autosave', $body);
    }

    public function test_on_transition_uses_shared_cpa_status_marker_clear(): void
    {
        $body = $this->extract_method_body('on_transition_clear_markers');
        $this->assertStringContainsString('Cashback_Product_Cpa_Status_Service::clear_deactivation_markers', $body);
        $this->assertStringContainsString('_cashback_auto_deactivated', $body);
        $this->assertStringContainsString('_cashback_deactivation_reason', $body);
        $this->assertStringContainsString('_cashback_deactivated_at', $body);
        $this->assertStringContainsString('_cashback_deactivated_network', $body);
        $this->assertStringContainsString('_cashback_auto_deactivated_source', $body);
    }

    // ============================================================
    // 7. ensure_backfilled / backfill_all — гейт опцией + правильная выборка.
    // ============================================================

    public function test_ensure_backfilled_terminal_state_one(): void
    {
        $body = $this->extract_method_body('ensure_backfilled');
        $this->assertMatchesRegularExpression(
            "/get_option\s*\(\s*self::BACKFILL_OPTION\s*,\s*''\s*\).*?===\s*'1'/s",
            $body
        );
    }

    public function test_ensure_backfilled_uses_wp_schedule_single_event(): void
    {
        $body = $this->extract_method_body('ensure_backfilled');
        $this->assertStringContainsString('wp_schedule_single_event', $body);
        $this->assertStringContainsString('wp_next_scheduled', $body);
    }

    public function test_handle_backfill_cron_writes_terminal_state(): void
    {
        $body = $this->extract_method_body('handle_backfill_cron');
        $this->assertMatchesRegularExpression(
            "/update_option\s*\(\s*self::BACKFILL_OPTION\s*,\s*'1'/",
            $body
        );
    }

    public function test_backfill_all_includes_publish_and_auto_deactivated_drafts(): void
    {
        $body = $this->extract_method_body('backfill_all');
        // publish товары
        $this->assertStringContainsString("p.post_status = 'publish'", $body);
        // draft с auto-deactivated
        $this->assertStringContainsString("p.post_status = 'draft'", $body);
        $this->assertStringContainsString("pm_deact.meta_value = '1'", $body);
        // Только товары с _offer_id
        $this->assertStringContainsString("pm_offer.meta_value <> ''", $body);
        // НЕ перезаписываем уже выставленный флаг
        $this->assertStringContainsString('pm_autopub.meta_id IS NULL', $body);
    }

    // ============================================================
    // 8. Cashback_API_Client SQL реактивации — INNER JOIN на autopub.
    // ============================================================

    public function test_check_campaign_statuses_sql_inner_joins_autopub(): void
    {
        $this->assertMatchesRegularExpression(
            '/INNER JOIN .*pm_autopub.*pm_autopub\.meta_key\s*=\s*\'_cashback_auto_publish_enabled\'.*pm_autopub\.meta_value\s*=\s*\'1\'/s',
            self::$api_client_php,
            'SQL реактивации должен содержать INNER JOIN на _cashback_auto_publish_enabled=1'
        );
    }

    // ============================================================
    // 9. ajax_reactivate_product ставит _cashback_auto_publish_enabled=1.
    // ============================================================

    public function test_ajax_reactivate_product_sets_autopublish_meta(): void
    {
        // Извлекаем тело метода ajax_reactivate_product.
        $body = $this->extract_method_body_from(self::$admin_validation_php, 'ajax_reactivate_product');
        $this->assertMatchesRegularExpression(
            "/update_post_meta\s*\(\s*\\\$product_id\s*,\s*'_cashback_auto_publish_enabled'\s*,\s*'1'\s*\)/",
            $body,
            'ajax_reactivate_product должен включать переключатель Автопубликация'
        );
    }

    // ============================================================
    // 10. Bootstrap (cashback-plugin.php): require + register + ensure_backfilled.
    // ============================================================

    public function test_cashback_plugin_requires_autopublish_class(): void
    {
        $this->assertStringContainsString(
            "require_file('includes/shops/class-cashback-product-autopublish.php')",
            self::$cashback_plugin_php
        );
    }

    public function test_cashback_plugin_registers_autopublish(): void
    {
        $this->assertMatchesRegularExpression(
            '/Cashback_Product_Autopublish::register\s*\(\s*\)/',
            self::$cashback_plugin_php
        );
        $this->assertMatchesRegularExpression(
            '/Cashback_Product_Autopublish::ensure_backfilled\s*\(\s*\)/',
            self::$cashback_plugin_php
        );
    }

    // ============================================================
    // Helpers — извлечение тела метода через brace-balance.
    // ============================================================

    private function extract_method_body( string $method ): string
    {
        return $this->extract_method_body_from(self::$autopub_php, $method);
    }

    private function extract_method_body_from( string $source, string $method ): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\([^\)]*\)[^\{]*\{/';
        if (! preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail("Method {$method} not found");
        }
        $start = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $len   = strlen($source);
        for ($i = $start; $i < $len; ++$i) {
            $ch = $source[ $i ];
            if ($ch === '{') {
                ++$depth;
            } elseif ($ch === '}') {
                --$depth;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }
            }
        }
        $this->fail("Method {$method} body brace-unbalanced");
    }
}
