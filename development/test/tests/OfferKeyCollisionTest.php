<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed
    {
        return $GLOBALS['_cb_test_options'][$key] ?? $default;
    }
}

if (!function_exists('__')) {
    function __(string $text, ?string $domain = null): string
    {
        return $text;
    }
}

#[Group('offer-key')]
#[Group('claims')]
final class OfferKeyCollisionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);

        require_once $root . '/includes/class-cashback-offer-key.php';
        require_once $root . '/claims/class-claims-db.php';
        require_once $root . '/claims/class-claims-eligibility.php';
        require_once $root . '/claims/class-claims-manager.php';
        require_once $root . '/claims/class-claims-scoring.php';
    }

    public function test_offer_key_helper_canonicalizes_network_slug_and_raw_offer_id(): void
    {
        self::assertTrue(class_exists('Cashback_Offer_Key'));

        self::assertSame('admitad:100', Cashback_Offer_Key::from_parts(' Admitad ', ' 100 '));
        self::assertSame('advcake:gb-ru', Cashback_Offer_Key::from_parts('ADVCAKE', ' gb-ru '));
        self::assertNull(Cashback_Offer_Key::from_parts('', '100'));
        self::assertNull(Cashback_Offer_Key::from_parts('admitad', ''));
    }

    public function test_claims_schema_uses_merchant_key_order_uniqueness(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/claims/class-claims-db.php');
        $block  = $this->extract_create_table_block($source, 'cashback_claims');

        self::assertMatchesRegularExpression('/`merchant_key`\s+varchar\(384\)\s+DEFAULT\s+NULL/i', $block);
        self::assertMatchesRegularExpression('/UNIQUE\s+KEY\s+`uk_merchant_key_order`\s*\(\s*`merchant_key`\s*,\s*`order_id`\s*\)/i', $block);
        self::assertDoesNotMatchRegularExpression('/UNIQUE\s+KEY\s+`uk_merchant_order`\s*\(\s*`merchant_id`\s*,\s*`order_id`\s*\)/i', $block);
    }

    public function test_click_log_schema_stores_raw_offer_id_and_canonical_offer_key(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/mariadb.php');
        $block  = $this->extract_create_table_block($source, 'cashback_click_log');

        self::assertMatchesRegularExpression('/`offer_id`\s+varchar\(255\)\s+DEFAULT\s+NULL/i', $block);
        self::assertMatchesRegularExpression('/`offer_key`\s+varchar\(384\)\s+DEFAULT\s+NULL/i', $block);
        self::assertMatchesRegularExpression('/KEY\s+`idx_offer_key`\s*\(\s*`offer_key`\s*\)/i', $block);
    }

    public function test_click_activation_writes_raw_offer_id_and_offer_key(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/class-cashback-click-session-service.php');

        self::assertStringContainsString("get_post_meta(\$product_id, '_offer_id'", $source);
        self::assertStringContainsString("'offer_id'           =>", $source);
        self::assertStringContainsString("'offer_key'          =>", $source);
        self::assertStringContainsString('Cashback_Offer_Key::from_parts', $source);
    }

    public function test_claim_duplicate_classification_accepts_new_merchant_key_index(): void
    {
        $err = "Duplicate entry 'admitad:100-ORDER-A' for key 'uk_merchant_key_order'";

        self::assertSame('duplicate_order', Cashback_Claims_Manager::classify_insert_error($err));
    }

    public function test_claims_migration_tolerates_missing_click_log_offer_key_column(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/claims/class-claims-db.php');

        self::assertStringContainsString("SHOW COLUMNS FROM `{\$click_table}` LIKE 'offer_key'", $source);
        self::assertStringContainsString('if (empty($click_offer_key_col))', $source);

        $fallback_start = strpos($source, 'if (empty($click_offer_key_col))');
        $fallback_end   = strpos($source, '} else {', (int) $fallback_start);
        self::assertIsInt($fallback_start);
        self::assertIsInt($fallback_end);

        $fallback_sql = substr($source, (int) $fallback_start, (int) $fallback_end - (int) $fallback_start);
        self::assertStringContainsString('CONCAT(LOWER(TRIM(cl.cpa_network))', $fallback_sql);
        self::assertStringNotContainsString('cl.offer_key', $fallback_sql);
    }

    public function test_runtime_migration_runs_click_offer_key_before_claims_identity(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/cashback-plugin.php');
        $start  = strpos($source, 'private function maybe_run_migrations');
        self::assertIsInt($start);

        $body_start = (int) $start;
        $body_end   = strpos($source, '// Группа 14:', $body_start);
        self::assertIsInt($body_end);
        $body = substr($source, $body_start, (int) $body_end - $body_start);

        $click_pos  = strpos($body, 'migrate_offer_key_v21();');
        $claims_pos = strpos($body, 'migrate_offer_key_identity();');
        self::assertIsInt($click_pos);
        self::assertIsInt($claims_pos);
        self::assertLessThan($claims_pos, $click_pos);
    }

    public function test_blocked_merchant_key_is_network_scoped(): void
    {
        $method = new ReflectionMethod('Cashback_Claims_Eligibility', 'check_merchant_allows_claims');
        $method->setAccessible(true);

        $GLOBALS['_cb_test_options']['cashback_claims_blocked_merchants'] = array('admitad:100');

        self::assertNotTrue($method->invoke(null, 100, 'admitad:100'));
        self::assertTrue($method->invoke(null, 100, 'epn:100'));
    }

    public function test_scoring_aggregates_merchant_history_by_merchant_key(): void
    {
        $GLOBALS['wpdb'] = new Offer_Key_Scoring_Wpdb_Stub();
        $GLOBALS['wpdb']->merchant_key_stats['admitad:100'] = array('total' => 10, 'approved' => 9);
        $GLOBALS['wpdb']->merchant_key_stats['epn:100']     = array('total' => 10, 'approved' => 1);

        $method = new ReflectionMethod('Cashback_Claims_Scoring', 'score_merchant_factor');
        $method->setAccessible(true);

        self::assertEqualsWithDelta(0.9, (float) $method->invoke(null, 100, 'admitad:100'), 0.001);
        self::assertEqualsWithDelta(0.1, (float) $method->invoke(null, 100, 'epn:100'), 0.001);

        unset($GLOBALS['wpdb']);
    }

    private function extract_create_table_block(string $source, string $table): string
    {
        $pattern = '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`\{\$wpdb->prefix\}' . preg_quote($table, '/') . '`\s*\((.*?)\)\s+ENGINE=/is';
        self::assertMatchesRegularExpression($pattern, $source, 'CREATE TABLE block not found for ' . $table);
        preg_match($pattern, $source, $m);
        return (string) ($m[1] ?? '');
    }
}

final class Offer_Key_Scoring_Wpdb_Stub
{
    public string $prefix = 'wp_';

    /** @var array<string,array{total:int,approved:int}> */
    public array $merchant_key_stats = array();

    public function prepare(string $query, mixed ...$args): string
    {
        $i = 0;
        return (string) preg_replace_callback('/%[isdf]/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? '';
            if ($m[0] === '%i') {
                return '`' . str_replace('`', '``', (string) $v) . '`';
            }
            if ($m[0] === '%s') {
                return "'" . str_replace("'", "\\'", (string) $v) . "'";
            }
            return (string) $v;
        }, $query);
    }

    public function get_row(string $sql, string $output = ARRAY_A): ?array
    {
        if (preg_match("/WHERE\\s+merchant_key\\s*=\\s*'([^']+)'/is", $sql, $m)) {
            return $this->merchant_key_stats[$m[1]] ?? array('total' => 0, 'approved' => 0);
        }

        return null;
    }
}
