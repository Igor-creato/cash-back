<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('transactions')]
#[Group('api-client')]
final class TransactionDeclineReasonTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
    }

    private function source(string $relative): string
    {
        $src = file_get_contents(self::$plugin_root . '/' . ltrim($relative, '/'));
        $this->assertIsString($src, 'Source must be readable: ' . $relative);
        return $src;
    }

    private function extract_method_body(string $src, string $method_name): string
    {
        $pos = strpos($src, 'function ' . $method_name . '(');
        $this->assertIsInt($pos, $method_name . '() method must exist.');

        $brace = strpos($src, '{', $pos);
        $this->assertIsInt($brace, 'Opening brace must follow ' . $method_name . ' signature.');

        $depth = 0;
        $len   = strlen($src);
        for ($i = $brace; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }

        $this->fail('Could not find closing brace for ' . $method_name);
    }

    private function table_ddl(string $src, string $var_name): string
    {
        $pos = strpos($src, '$' . $var_name . ' = "CREATE TABLE');
        $this->assertIsInt($pos, 'CREATE TABLE variable must exist: ' . $var_name);
        $end = strpos($src, '";', $pos);
        $this->assertIsInt($end, 'CREATE TABLE variable must close: ' . $var_name);
        return substr($src, $pos, $end - $pos);
    }

    public function test_schema_contains_decline_reason_in_both_transaction_tables(): void
    {
        $src = $this->source('mariadb.php');

        foreach (['table_transactions', 'table_unregistered'] as $var) {
            $ddl = $this->table_ddl($src, $var);
            $this->assertMatchesRegularExpression(
                "/`decline_reason`\s+text\s+DEFAULT\s+NULL\s+COMMENT\s+'CPA decline reason \/ advertiser comment for declined transaction'/i",
                $ddl,
                $var . ' must include decline_reason TEXT DEFAULT NULL with the required comment.'
            );
        }
    }

    public function test_v20_migration_is_registered_and_idempotent(): void
    {
        $src  = $this->source('mariadb.php');
        $body = $this->extract_method_body($src, 'migrate_transaction_decline_reason_v20');

        $this->assertStringContainsString('$instance->migrate_transaction_decline_reason_v20();', $src);
        $this->assertStringContainsString('cashback_transactions', $body);
        $this->assertStringContainsString('cashback_unregistered_transactions', $body);
        $this->assertStringContainsString('INFORMATION_SCHEMA.COLUMNS', $body);
        $this->assertStringContainsString('decline_reason', $body);
        $this->assertStringContainsString('ADD COLUMN `decline_reason`', $body);
        $this->assertStringContainsString("update_option('cashback_db_version', 20", $body);
    }

    public function test_api_client_resolves_decline_reason_only_for_declined_status(): void
    {
        $src  = $this->source('includes/class-cashback-api-client.php');
        $body = $this->extract_method_body($src, 'resolve_decline_reason');

        $this->assertMatchesRegularExpression("/\\\$mapped_status\s*!==\s*'declined'[\s\S]+return\s+null/", $body);
        $this->assertStringContainsString("api_field_for('decline_reason'", $body);
        $this->assertStringContainsString("'comment'", $body);
        $this->assertStringContainsString("'reason'", $body);
        $this->assertMatchesRegularExpression('/trim\s*\(\s*\(string\)\s*\$action\[\s*\$field\s*\]\s*\)/', $body);
        $this->assertStringContainsString('mb_substr($reason, 0, 2000)', $body);
    }

    public function test_insert_missing_transaction_writes_decline_reason(): void
    {
        $src  = $this->source('includes/class-cashback-api-client.php');
        $body = $this->extract_method_body($src, 'insert_missing_transaction');

        $this->assertStringContainsString('resolve_decline_reason($action, $field_map, $mapped_status)', $body);
        $this->assertMatchesRegularExpression("/'decline_reason'\s*=>\s*\\\$decline_reason/", $body);
        $this->assertMatchesRegularExpression("/'%s',\s*\/\/ decline_reason/", $body);
    }

    public function test_sync_update_local_updates_and_clears_decline_reason(): void
    {
        $src  = $this->source('includes/class-cashback-api-client.php');
        $body = $this->extract_method_body($src, 'sync_update_local');

        $this->assertStringContainsString('resolve_decline_reason($action, $field_map, $mapped_status)', $body);
        $this->assertStringContainsString('$reason_changed', $body);
        $this->assertMatchesRegularExpression('/!\s*\$reason_changed/', $body);
        $this->assertMatchesRegularExpression("/\\\$update_data\['decline_reason'\]\s*=\s*\\\$api_decline_reason/", $body);
    }

    public function test_background_sync_uses_advcake_update_window_and_admitad_status_window(): void
    {
        $src  = $this->source('includes/class-cashback-api-client.php');
        $body = $this->extract_method_body($src, 'build_background_sync_params');

        $this->assertStringContainsString('Cashback_Advcake_Adapter', $body);
        $this->assertStringContainsString("'update_from'", $body);
        $this->assertStringContainsString("'update_to'", $body);
        $this->assertStringContainsString("'status_updated_start'", $body);
        $this->assertStringContainsString("'status_updated_end'", $body);
    }

    public function test_admin_transactions_table_selects_and_escapes_decline_reason(): void
    {
        $src = $this->source('admin/transactions.php');

        $this->assertStringContainsString('Причина отказа', $src);
        $this->assertMatchesRegularExpression('/SELECT\s+id,\s+reference_id,\s+user_id,\s+order_number,\s+partner,\s+order_status,\s+decline_reason/is', $src);
        $this->assertStringContainsString('esc_attr($decline_reason)', $src);
        $this->assertStringContainsString('esc_html($decline_reason', $src);
        $this->assertStringContainsString('colspan="13"', $src);
        $this->assertDoesNotMatchRegularExpression('/decline_reason[^\n]+edit-field/', $src);
    }
}
