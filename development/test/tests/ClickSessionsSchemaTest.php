<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Группа 12i-1 ADR — schema для click sessions + merchant policy.
 *
 * Closes F-10-001 schema slice: cashback_click_sessions table,
 * click_log extensions (session_id / client_request_id / is_session_primary),
 * affiliate_networks policy fields (click_session_policy / activation_window_seconds),
 * migration helper + bootstrap wire-up.
 *
 * Контракт (TDD RED, source-grep):
 *
 *  1. mariadb.php: CREATE TABLE cashback_click_sessions с нужными колонками +
 *     индексами (idx_user_product_active / idx_guest_product_active /
 *     idx_expires_status + UNIQUE uk_canonical_click_id).
 *  2. mariadb.php: CREATE TABLE cashback_click_log содержит click_session_id (FK) /
 *     client_request_id / is_session_primary (для fresh installs).
 *     Примечание: колонка click_session_id, а не просто session_id — чтобы не
 *     конфликтовать с существующей session_id (гостевая PHP-сессия).
 *  3. mariadb.php: CREATE TABLE cashback_affiliate_networks содержит
 *     click_session_policy / activation_window_seconds.
 *  4. Миграция Mariadb_Plugin::migrate_add_click_sessions_v1() присутствует:
 *     - проверяет cashback_db_version (bump на 3).
 *     - ALTER TABLE click_log ADD click_session_id / client_request_id / is_session_primary.
 *     - ALTER TABLE affiliate_networks ADD click_session_policy / activation_window_seconds.
 *     - INFORMATION_SCHEMA guards на существование колонок (идемпотентность).
 *  5. Миграция зарегистрирована в Mariadb_Plugin::activate().
 */
#[Group('security')]
#[Group('group12')]
#[Group('f-10-001')]
#[Group('click-sessions')]
class ClickSessionsSchemaTest extends TestCase
{
    private string $source = '';

    protected function setUp(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $path        = $plugin_root . '/mariadb.php';
        self::assertFileExists($path);
        $content = file_get_contents($path);
        self::assertNotFalse($content);
        $this->source = $content;
    }

    // =====================================================================
    // 1. CREATE TABLE cashback_click_sessions
    // =====================================================================

    public function test_click_sessions_table_defined(): void
    {
        self::assertMatchesRegularExpression(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`\{\$wpdb->prefix\}cashback_click_sessions`/i',
            $this->source,
            '12i-1: CREATE TABLE cashback_click_sessions должен быть определён в mariadb.php.'
        );
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function click_sessions_columns_provider(): array
    {
        return array(
            array( 'canonical_click_id' ),
            array( 'user_id' ),
            array( 'guest_session_id' ),
            array( 'product_id' ),
            array( 'merchant_id' ),
            array( 'affiliate_url' ),
            array( 'status' ),
            array( 'tap_count' ),
            array( 'created_at' ),
            array( 'expires_at' ),
            array( 'last_tap_at' ),
            array( 'converted_at' ),
        );
    }

    #[PHPUnit\Framework\Attributes\DataProvider('click_sessions_columns_provider')]
    public function test_click_sessions_has_required_columns( string $column ): void
    {
        $sessions_block = $this->extract_create_table_block('cashback_click_sessions');
        self::assertMatchesRegularExpression(
            '/`' . preg_quote($column, '/') . '`/',
            $sessions_block,
            '12i-1: колонка ' . $column . ' должна быть в CREATE TABLE cashback_click_sessions.'
        );
    }

    public function test_click_sessions_has_canonical_unique_key(): void
    {
        $block = $this->extract_create_table_block('cashback_click_sessions');
        self::assertMatchesRegularExpression(
            '/UNIQUE\s+KEY\s+`uk_canonical_click_id`\s*\(\s*`canonical_click_id`\s*\)/i',
            $block,
            '12i-1: UNIQUE KEY на canonical_click_id обязателен — идентификатор сессии должен быть уникальным.'
        );
    }

    public function test_click_sessions_has_user_product_active_index(): void
    {
        $block = $this->extract_create_table_block('cashback_click_sessions');
        self::assertMatchesRegularExpression(
            '/KEY\s+`idx_user_product_active`\s*\(\s*`user_id`\s*,\s*`product_id`\s*,\s*`status`\s*,\s*`expires_at`\s*\)/i',
            $block,
            '12i-1: композитный индекс idx_user_product_active нужен для lookup активной сессии FOR UPDATE.'
        );
    }

    public function test_click_sessions_has_guest_product_active_index(): void
    {
        $block = $this->extract_create_table_block('cashback_click_sessions');
        self::assertMatchesRegularExpression(
            '/KEY\s+`idx_guest_product_active`\s*\(\s*`guest_session_id`\s*,\s*`product_id`\s*,\s*`status`\s*,\s*`expires_at`\s*\)/i',
            $block,
            '12i-1: композитный индекс idx_guest_product_active для lookup сессии гостя.'
        );
    }

    public function test_click_sessions_has_expires_status_index(): void
    {
        $block = $this->extract_create_table_block('cashback_click_sessions');
        self::assertMatchesRegularExpression(
            '/KEY\s+`idx_expires_status`\s*\(\s*`expires_at`\s*,\s*`status`\s*\)/i',
            $block,
            '12i-1: индекс idx_expires_status для GC expired sessions.'
        );
    }

    public function test_click_sessions_status_is_enum(): void
    {
        $block = $this->extract_create_table_block('cashback_click_sessions');
        self::assertMatchesRegularExpression(
            "/`status`\s+ENUM\s*\(\s*'active'\s*,\s*'expired'\s*,\s*'converted'\s*,\s*'invalidated'\s*\)/i",
            $block,
            '12i-1: status — ENUM из 4 значений (active/expired/converted/invalidated).'
        );
    }

    // =====================================================================
    // 2. click_log extensions (fresh-install CREATE TABLE)
    // =====================================================================

    public function test_click_log_create_has_click_session_id(): void
    {
        $block = $this->extract_create_table_block('cashback_click_log');
        self::assertMatchesRegularExpression(
            '/`click_session_id`\s+BIGINT/i',
            $block,
            '12i-1: click_log.click_session_id (FK на click_sessions.id) должен быть в CREATE TABLE '
            . '(fresh install). Имя click_session_id, а не session_id — чтобы не конфликтовать '
            . 'с существующей session_id (гостевая PHP-сессия, varchar(128)).'
        );
    }

    public function test_click_log_create_has_client_request_id(): void
    {
        $block = $this->extract_create_table_block('cashback_click_log');
        self::assertMatchesRegularExpression(
            '/`client_request_id`\s+CHAR\s*\(\s*32\s*\)/i',
            $block,
            '12i-1: click_log.client_request_id CHAR(32) для идемпотентности тапов.'
        );
    }

    public function test_click_log_create_has_is_session_primary(): void
    {
        $block = $this->extract_create_table_block('cashback_click_log');
        self::assertMatchesRegularExpression(
            '/`is_session_primary`\s+TINYINT\s*\(\s*1\s*\)/i',
            $block,
            '12i-1: click_log.is_session_primary (1 = тап создал сессию).'
        );
    }

    public function test_click_log_create_has_click_session_index(): void
    {
        $block = $this->extract_create_table_block('cashback_click_log');
        self::assertMatchesRegularExpression(
            '/KEY\s+`idx_click_session_id`\s*\(\s*`click_session_id`\s*\)/i',
            $block
        );
    }

    public function test_click_log_create_has_client_request_index(): void
    {
        $block = $this->extract_create_table_block('cashback_click_log');
        self::assertMatchesRegularExpression(
            '/KEY\s+`idx_client_request`\s*\(\s*`user_id`\s*,\s*`client_request_id`\s*\)/i',
            $block
        );
    }

    // =====================================================================
    // 3. affiliate_networks merchant policy columns (fresh-install)
    // =====================================================================

    public function test_affiliate_networks_has_policy_column(): void
    {
        $block = $this->extract_create_table_block('cashback_affiliate_networks');
        self::assertMatchesRegularExpression(
            '/`click_session_policy`\s+VARCHAR\s*\(\s*32\s*\).*DEFAULT\s+\'reuse_in_window\'/is',
            $block,
            '12i-1: affiliate_networks.click_session_policy VARCHAR(32) DEFAULT "reuse_in_window".'
        );
    }

    public function test_affiliate_networks_has_window_column(): void
    {
        $block = $this->extract_create_table_block('cashback_affiliate_networks');
        self::assertMatchesRegularExpression(
            '/`activation_window_seconds`\s+INT\s+UNSIGNED.*DEFAULT\s+1800/is',
            $block,
            '12i-1: affiliate_networks.activation_window_seconds INT UNSIGNED DEFAULT 1800.'
        );
    }

    // =====================================================================
    // 4. Migration helper
    // =====================================================================

    public function test_migration_helper_method_exists(): void
    {
        self::assertMatchesRegularExpression(
            '/public\s+function\s+migrate_add_click_sessions_v1\s*\(\s*\)\s*:\s*void/',
            $this->source,
            '12i-1: метод migrate_add_click_sessions_v1() должен присутствовать в Mariadb_Plugin.'
        );
    }

    public function test_migration_alters_click_log_click_session_id(): void
    {
        $body = $this->extract_method('migrate_add_click_sessions_v1');
        self::assertStringContainsString(
            'click_session_id',
            $body,
            '12i-1: migration должна ALTER TABLE click_log ADD COLUMN click_session_id (FK на click_sessions.id).'
        );
    }

    public function test_migration_alters_click_log_client_request_id(): void
    {
        $body = $this->extract_method('migrate_add_click_sessions_v1');
        self::assertStringContainsString(
            'client_request_id',
            $body,
            '12i-1: migration должна добавить client_request_id в click_log.'
        );
    }

    public function test_migration_alters_click_log_is_session_primary(): void
    {
        $body = $this->extract_method('migrate_add_click_sessions_v1');
        self::assertStringContainsString(
            'is_session_primary',
            $body,
            '12i-1: migration должна добавить is_session_primary в click_log.'
        );
    }

    public function test_migration_alters_affiliate_networks_policy(): void
    {
        $body = $this->extract_method('migrate_add_click_sessions_v1');
        self::assertStringContainsString(
            'click_session_policy',
            $body,
            '12i-1: migration должна ALTER TABLE affiliate_networks ADD COLUMN click_session_policy.'
        );
    }

    public function test_migration_alters_affiliate_networks_window(): void
    {
        $body = $this->extract_method('migrate_add_click_sessions_v1');
        self::assertStringContainsString(
            'activation_window_seconds',
            $body,
            '12i-1: migration должна ALTER TABLE affiliate_networks ADD COLUMN activation_window_seconds.'
        );
    }

    public function test_migration_uses_information_schema_guard(): void
    {
        $body = $this->extract_method('migrate_add_click_sessions_v1');
        self::assertMatchesRegularExpression(
            '/INFORMATION_SCHEMA/i',
            $body,
            '12i-1: migration должна использовать INFORMATION_SCHEMA guards для идемпотентности (паттерн migrate_add_frozen_balance_buckets).'
        );
    }

    public function test_migration_bumps_db_version(): void
    {
        $body = $this->extract_method('migrate_add_click_sessions_v1');
        self::assertMatchesRegularExpression(
            "/update_option\s*\(\s*['\"]cashback_db_version['\"]\s*,\s*3\b/",
            $body,
            '12i-1: migration должна повысить cashback_db_version до 3 (предыдущий максимум — 2).'
        );
    }

    public function test_migration_checks_existing_db_version(): void
    {
        $body = $this->extract_method('migrate_add_click_sessions_v1');
        self::assertMatchesRegularExpression(
            "/get_option\s*\(\s*['\"]cashback_db_version['\"]/",
            $body,
            '12i-1: migration должна читать cashback_db_version перед применением (идемпотентность).'
        );
    }

    // =====================================================================
    // 5. Migration wired in activate()
    // =====================================================================

    public function test_migration_registered_in_activate(): void
    {
        $body = $this->extract_method('activate');
        self::assertMatchesRegularExpression(
            '/\$instance->migrate_add_click_sessions_v1\s*\(\s*\)/',
            $body,
            '12i-1: migrate_add_click_sessions_v1 должна вызываться из Mariadb_Plugin::activate().'
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function extract_create_table_block( string $table_suffix ): string
    {
        $pattern = '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`\{\$wpdb->prefix\}' . preg_quote($table_suffix, '/') . '`\s*\(([\s\S]*?)\)\s*ENGINE=InnoDB/i';
        if (preg_match($pattern, $this->source, $m) !== 1) {
            self::fail('CREATE TABLE для ' . $table_suffix . ' не найден в mariadb.php.');
        }
        return (string) $m[1];
    }

    private function extract_method( string $name ): string
    {
        $pattern = '/(?:public|private|protected)\s+(?:static\s+)?function\s+' . preg_quote($name, '/')
            . '\s*\([^)]*\)(?:\s*:\s*\??[\w\\\\]+)?\s*\{/';

        if (preg_match($pattern, $this->source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            self::fail('Метод ' . $name . '() не найден в mariadb.php.');
        }

        $start = (int) $m[0][1];
        $brace = strpos($this->source, '{', $start);
        if ($brace === false) {
            self::fail('Нет открывающей скобки у ' . $name);
        }

        $depth = 0;
        $len   = strlen($this->source);
        for ($i = $brace; $i < $len; $i++) {
            if ($this->source[$i] === '{') {
                $depth++;
            } elseif ($this->source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($this->source, $brace, $i - $brace + 1);
                }
            }
        }
        self::fail('Нет закрывающей скобки у ' . $name);
    }
}
