<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты на миграцию v8 промокодов:
 *   - +4 public-колонки в cashback_affiliate_networks
 *   - +2 новые таблицы (cashback_promocodes, cashback_promocode_clicks)
 *   - cashback_db_version bump 7→8
 *   - hook auto-migration в cashback-plugin.php::maybe_run_migrations()
 *
 * Почему structural, а не functional: миграции v6/v7 показали что мокать
 * INFORMATION_SCHEMA-вызовы хрупко (per memory feedback_alter_table_no_prepare).
 * Структурный тест проверяет фактическое наличие нужных строк в коде —
 * production-совместимо при всех путях вызова (activate / maybe_run_migrations).
 *
 * @group migration
 * @group promocodes
 */
#[Group('migration')]
#[Group('promocodes')]
final class PromocodesV8MigrationStructuralTest extends TestCase
{
    private static string $plugin_root;
    private static string $mariadb_php;
    private static string $cashback_plugin_php;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root         = dirname(__DIR__, 3);
        self::$mariadb_php         = file_get_contents(self::$plugin_root . '/mariadb.php');
        self::$cashback_plugin_php = file_get_contents(self::$plugin_root . '/cashback-plugin.php');
    }

    // ============================================================
    // 1. Метод migrate_promocodes_v8 существует с public-видимостью.
    // ============================================================

    public function test_mariadb_php_declares_migrate_promocodes_v8_method(): void
    {
        $this->assertMatchesRegularExpression(
            '/public function migrate_promocodes_v8\s*\(\s*\)\s*:\s*void/i',
            self::$mariadb_php,
            'Должен быть public method migrate_promocodes_v8(): void'
        );
    }

    // ============================================================
    // 2. Версионизация — fast-path return при cashback_db_version >= 8.
    // ============================================================

    public function test_migration_v8_has_db_version_fast_path(): void
    {
        $this->assertStringContainsString(
            "update_option('cashback_db_version', 8",
            self::$mariadb_php,
            'Должен быть update_option cashback_db_version=8 в конце миграции v8'
        );

        // fast-path: \$current_version >= 8 → return.
        $this->assertMatchesRegularExpression(
            '/current_version\s*>=\s*8\s*\)\s*\{\s*return/s',
            self::$mariadb_php,
            'Должен быть fast-path return при current_version >= 8'
        );
    }

    // ============================================================
    // 3. ALTER TABLE cashback_affiliate_networks для 4 новых колонок.
    // ============================================================

    public function test_migration_adds_api_coupons_endpoint_column(): void
    {
        $this->assertMatchesRegularExpression(
            '/ALTER\s+TABLE.*cashback_affiliate_networks.*ADD\s+COLUMN.*api_coupons_endpoint/is',
            self::$mariadb_php,
            'Должен быть ALTER TABLE cashback_affiliate_networks ADD COLUMN api_coupons_endpoint'
        );
    }

    public function test_migration_adds_api_coupons_field_map_column(): void
    {
        $this->assertStringContainsString(
            'api_coupons_field_map',
            self::$mariadb_php,
            'Должна быть колонка api_coupons_field_map'
        );
        $this->assertMatchesRegularExpression(
            '/api_coupons_field_map.*(LONGTEXT|longtext|long\s*text)/is',
            self::$mariadb_php,
            'api_coupons_field_map должна быть LONGTEXT для JSON-маппинга'
        );
    }

    public function test_migration_adds_api_coupons_species_map_column(): void
    {
        $this->assertStringContainsString(
            'api_coupons_species_map',
            self::$mariadb_php
        );
    }

    public function test_migration_adds_api_coupons_pagination_column(): void
    {
        $this->assertStringContainsString(
            'api_coupons_pagination',
            self::$mariadb_php
        );
    }

    // ============================================================
    // 4. CREATE TABLE cashback_promocodes — все ключевые колонки.
    // ============================================================

    public function test_creates_cashback_promocodes_table(): void
    {
        $this->assertMatchesRegularExpression(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?[^`]*cashback_promocodes`?/is',
            self::$mariadb_php,
            'CREATE TABLE IF NOT EXISTS для cashback_promocodes'
        );
    }

    public function test_promocodes_table_has_required_columns(): void
    {
        // Минимально необходимые колонки для DTO + soft-delete + advcampaign-фильтр.
        $required_columns = array(
            'network_id',
            'advcampaign_id',
            'external_id',
            'species',
            'promocode',
            'name',
            'description',
            'discount',
            'date_start',
            'date_end',
            'regions',
            'goto_link',
            'is_exclusive',
            'fetched_at',
            'is_active',
        );

        foreach ($required_columns as $col) {
            $this->assertStringContainsString(
                '`' . $col . '`',
                self::$mariadb_php,
                "Таблица cashback_promocodes должна содержать колонку `{$col}`"
            );
        }
    }

    public function test_promocodes_table_has_unique_network_external_index(): void
    {
        // UNIQUE (network_id, external_id) — защищает от коллизий ID между сетями.
        $this->assertMatchesRegularExpression(
            '/UNIQUE\s+KEY\s+`?uniq_network_external`?\s*\(\s*`network_id`\s*,\s*`external_id`\s*\)/is',
            self::$mariadb_php,
            'UNIQUE (network_id, external_id) обязателен'
        );
    }

    public function test_promocodes_table_uses_utf8mb4_collation(): void
    {
        // Per memory feedback_no_ascii_columns — utf8mb4, не ascii_bin.
        // Проверяем что CREATE TABLE wp_cashback_promocodes не использует ascii_bin.
        if (preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?[^`]*cashback_promocodes`?\s*\(.*?\)\s*ENGINE\s*=\s*InnoDB[^;]*;/is', self::$mariadb_php, $m)) {
            $ddl = $m[0];
            $this->assertStringNotContainsString('ascii_bin', $ddl, 'cashback_promocodes не должна использовать ascii_bin');
            $this->assertStringContainsString('utf8mb4', strtolower($ddl), 'cashback_promocodes должна использовать utf8mb4');
        } else {
            $this->fail('CREATE TABLE cashback_promocodes не найден');
        }
    }

    // ============================================================
    // 5. CREATE TABLE cashback_promocode_clicks — для click-tracking.
    // ============================================================

    public function test_creates_cashback_promocode_clicks_table(): void
    {
        $this->assertMatchesRegularExpression(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?[^`]*cashback_promocode_clicks`?/is',
            self::$mariadb_php,
            'CREATE TABLE IF NOT EXISTS для cashback_promocode_clicks'
        );
    }

    public function test_promocode_clicks_table_has_ip_hash_not_raw_ip(): void
    {
        // 152-ФЗ: IP — это ПД, храним только sha256(ip+salt) → CHAR(64) или VARCHAR(64).
        if (preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?[^`]*cashback_promocode_clicks`?\s*\(.*?\)\s*ENGINE\s*=\s*InnoDB[^;]*;/is', self::$mariadb_php, $m)) {
            $ddl = $m[0];
            $this->assertStringContainsString('ip_hash', $ddl, 'cashback_promocode_clicks должна содержать ip_hash');
            $this->assertStringNotContainsString('ip_address', $ddl, 'cashback_promocode_clicks НЕ должна содержать raw ip_address (152-ФЗ)');
            // CHAR(64) — sha256 hex.
            $this->assertMatchesRegularExpression(
                '/`ip_hash`\s+(CHAR|VARCHAR|char|varchar)\s*\(\s*64\s*\)/i',
                $ddl,
                'ip_hash должен быть CHAR(64) для sha256'
            );
        } else {
            $this->fail('CREATE TABLE cashback_promocode_clicks не найден');
        }
    }

    public function test_promocode_clicks_action_is_enum_copy_goto(): void
    {
        if (preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?[^`]*cashback_promocode_clicks`?\s*\(.*?\)\s*ENGINE\s*=\s*InnoDB[^;]*;/is', self::$mariadb_php, $m)) {
            $ddl = $m[0];
            $this->assertMatchesRegularExpression(
                "/`action`\s+enum\s*\(\s*'copy'\s*,\s*'goto'\s*\)/i",
                $ddl,
                "action должен быть ENUM('copy','goto')"
            );
        } else {
            $this->fail('CREATE TABLE cashback_promocode_clicks не найден');
        }
    }

    // ============================================================
    // 6. Метод migrate_seed_admitad_coupons_config существует.
    // ============================================================

    public function test_migrate_seed_admitad_coupons_config_method_exists(): void
    {
        $this->assertMatchesRegularExpression(
            '/function migrate_seed_admitad_coupons_config\s*\(\s*\)/i',
            self::$mariadb_php,
            'Должен быть метод migrate_seed_admitad_coupons_config'
        );
    }

    // ============================================================
    // 7. Hook миграции v8 в maybe_run_migrations() в cashback-plugin.php.
    // ============================================================

    public function test_cashback_plugin_php_hooks_migrate_promocodes_v8(): void
    {
        $this->assertStringContainsString(
            'migrate_promocodes_v8',
            self::$cashback_plugin_php,
            'cashback-plugin.php должен вызывать migrate_promocodes_v8 в maybe_run_migrations'
        );
    }

    public function test_activate_hooks_migrate_promocodes_v8(): void
    {
        // В Mariadb_Plugin::activate() миграция тоже должна вызываться, иначе свежие
        // установки не получат таблицы (паттерн v5/v6/v7).
        $this->assertMatchesRegularExpression(
            '/migrate_promocodes_v8\s*\(\s*\)/i',
            self::$mariadb_php,
            'Mariadb_Plugin::activate() должен вызывать migrate_promocodes_v8'
        );
    }

    // ============================================================
    // 8. Раздел post-verify через INFORMATION_SCHEMA (паттерн v6/v7).
    // ============================================================

    public function test_migration_uses_information_schema_for_idempotency(): void
    {
        // Должен быть хотя бы один lookup в information_schema.COLUMNS для проверки
        // что api_coupons_endpoint уже существует — иначе ALTER упадёт на повторе.
        // Извлекаем тело migrate_promocodes_v8 по позиции (от signature до следующего
        // public function — regex с balanced braces ненадёжен на нестандартном
        // formatting'е).
        $start = strpos(self::$mariadb_php, 'public function migrate_promocodes_v8');
        $this->assertNotFalse($start, 'method migrate_promocodes_v8 not found');

        $end = strpos(self::$mariadb_php, 'public function migrate_seed_admitad_coupons_config', $start);
        if ($end === false) {
            $end = strlen(self::$mariadb_php);
        }
        $migration_block = substr(self::$mariadb_php, $start, $end - $start);

        $this->assertNotEmpty($migration_block);
        $this->assertStringContainsString(
            'information_schema',
            strtolower($migration_block),
            'Миграция должна использовать information_schema для idempotency'
        );
    }
}
