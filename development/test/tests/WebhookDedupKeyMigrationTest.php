<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('migration')]
#[Group('webhooks')]
final class WebhookDedupKeyMigrationTest extends TestCase {
	private string $mariadb_source;
	private string $plugin_source;

	protected function setUp(): void {
		$root                 = dirname(__DIR__, 3);
		$this->mariadb_source = (string) file_get_contents($root . '/mariadb.php');
		$this->plugin_source  = (string) file_get_contents($root . '/cashback-plugin.php');
	}

	public function test_webhooks_schema_uses_nullable_dedup_key_for_uniqueness(): void {
		$block = $this->extract_create_table_block($this->mariadb_source, 'cashback_webhooks');

		self::assertMatchesRegularExpression('/`dedup_key`\s+char\(64\)\s+DEFAULT\s+NULL/i', $block);
		self::assertMatchesRegularExpression('/UNIQUE\s+KEY\s+`uk_webhook_dedup_key`\s*\(\s*`dedup_key`\s*\)/i', $block);
		self::assertMatchesRegularExpression('/KEY\s+`idx_payload_hash`\s*\(\s*`payload_hash`\s*\)/i', $block);
		self::assertDoesNotMatchRegularExpression('/UNIQUE\s+KEY\s+`uk_payload_hash`\s*\(\s*`payload_hash`\s*\)/i', $block);
	}

	public function test_payload_hash_trigger_sets_dedup_key_only_for_transactions(): void {
		self::assertStringContainsString('NEW.dedup_key IS NULL', $this->mariadb_source);
		self::assertStringContainsString("NEW.event_type = 'transaction'", $this->mariadb_source);
		self::assertStringContainsString('SET NEW.dedup_key = SHA2(NEW.payload, 256)', $this->mariadb_source);
	}

	public function test_v22_migration_is_registered_in_activation_and_runtime_paths(): void {
		self::assertStringContainsString('public function migrate_webhook_dedup_key_v22()', $this->mariadb_source);
		self::assertStringContainsString('$instance->migrate_webhook_dedup_key_v22();', $this->mariadb_source);
		self::assertStringContainsString('Mariadb_Plugin::get_instance()->migrate_webhook_dedup_key_v22();', $this->plugin_source);
	}

	private function extract_create_table_block(string $source, string $table): string {
		$pattern = '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`\{\$wpdb->prefix\}' . preg_quote($table, '/') . '`\s*\((.*?)\)\s+ENGINE=/is';
		self::assertMatchesRegularExpression($pattern, $source, 'CREATE TABLE block not found for ' . $table);
		preg_match($pattern, $source, $m);
		return (string) ($m[1] ?? '');
	}
}
