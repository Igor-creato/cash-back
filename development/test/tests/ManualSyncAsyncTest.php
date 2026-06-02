<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression for manual API sync UX:
 * a long-running sync must not be held inside one 120s admin-ajax request.
 */
#[Group('api-validation')]
#[Group('manual-sync')]
final class ManualSyncAsyncTest extends TestCase {

    private static string $admin_src;
    private static string $cron_src;
    private static string $js_src;

    public static function setUpBeforeClass(): void {
        $root            = dirname(__DIR__, 3);
        self::$admin_src = (string) file_get_contents($root . '/admin/class-cashback-admin-api-validation.php');
        self::$cron_src  = (string) file_get_contents($root . '/includes/class-cashback-api-cron.php');
        self::$js_src    = (string) file_get_contents($root . '/admin/js/api-validation.js');
    }

    private function method_body( string $src, string $signature ): string {
        $start = strpos($src, $signature);
        $this->assertNotFalse($start, "signature not found: {$signature}");
        $brace = strpos($src, '{', $start);
        $this->assertNotFalse($brace);

        $depth = 0;
        $len   = strlen($src);
        for ($i = $brace; $i < $len; $i++) {
            if ($src[ $i ] === '{') {
                ++$depth;
            } elseif ($src[ $i ] === '}') {
                --$depth;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }

        $this->fail("unbalanced braces for {$signature}");
    }

    public function test_admin_registers_manual_sync_status_ajax_endpoint(): void {
        $this->assertStringContainsString(
            "add_action('wp_ajax_cashback_manual_sync_status'",
            self::$admin_src,
            'admin class must register a polling endpoint for manual sync status.'
        );
        $this->assertStringContainsString(
            'public function ajax_manual_sync_status()',
            self::$admin_src,
            'admin class must implement ajax_manual_sync_status().'
        );
    }

    public function test_manual_sync_ajax_starts_async_job_when_action_scheduler_exists(): void {
        $body = $this->method_body(self::$admin_src, 'public function ajax_manual_sync()');

        $this->assertStringContainsString('start_manual_sync_async', $body);
        $this->assertStringContainsString('run_id', $body);
        $this->assertStringContainsString("'async'", $body);
        $this->assertStringNotContainsString('Cashback_API_Cron::manual_sync()', $body);
    }

    public function test_cron_supports_deterministic_async_manual_run_id(): void {
        $this->assertStringContainsString('MANUAL_HOOK_NAME', self::$cron_src);
        $this->assertStringContainsString('run_manual_sync_async', self::$cron_src);
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+run_sync\s*\(\s*\?string\s+\$run_id\s*=\s*null\s*\)/',
            self::$cron_src,
            'run_sync must accept an optional run_id so the polling endpoint can follow the enqueued job.'
        );
        $this->assertStringContainsString('get_manual_sync_status', self::$cron_src);
    }

    public function test_js_polls_status_instead_of_waiting_for_whole_sync_request(): void {
        $manual_body = $this->method_body(self::$js_src, "$(document).on('click', '#cashback-manual-sync-btn'");

        $this->assertStringNotContainsString('timeout: 120000', $manual_body);
        $this->assertStringContainsString('startManualSyncPolling', self::$js_src);
        $this->assertStringContainsString("action: 'cashback_manual_sync_status'", self::$js_src);
        $this->assertStringContainsString('pollManualSyncStatus', self::$js_src);
    }
}
