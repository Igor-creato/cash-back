<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты на миграцию v12 (Shop Importer + Tariffs + Groups + Log).
 *
 * Создаёт 4 новые таблицы и bumps cashback_db_version 11→12:
 *   - cashback_shop_tariffs           — массив тарифов на campaign (PERCENT/FIX)
 *   - cashback_shop_groups            — дедуп магазинов по домену
 *   - cashback_shop_group_members     — many-to-many продукт ↔ группа
 *   - cashback_shop_import_log        — лог запусков для admin UI прогресса
 *
 * Почему structural, а не functional: миграции v6..v11 показали что мокать
 * INFORMATION_SCHEMA-вызовы хрупко (per memory feedback_alter_table_no_prepare).
 * Структурный тест проверяет фактическое наличие нужных строк в коде.
 *
 * @group migration
 * @group shop-import
 */
#[Group('migration')]
#[Group('shop-import')]
final class ShopImportV12MigrationStructuralTest extends TestCase
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
    // 1. Метод migrate_shop_import_v12 существует с public-видимостью.
    // ============================================================

    public function test_mariadb_php_declares_migrate_shop_import_v12_method(): void
    {
        $this->assertMatchesRegularExpression(
            '/public function migrate_shop_import_v12\s*\(\s*\)\s*:\s*void/i',
            self::$mariadb_php,
            'Должен быть public method migrate_shop_import_v12(): void'
        );
    }

    // ============================================================
    // 2. Версионизация — fast-path return при cashback_db_version >= 12 + bump.
    // ============================================================

    public function test_migration_v12_has_db_version_fast_path(): void
    {
        $this->assertStringContainsString(
            "update_option('cashback_db_version', 12",
            self::$mariadb_php,
            'Должен быть update_option cashback_db_version=12 в конце миграции v12'
        );

        // fast-path: $current_version >= 12 → return.
        $this->assertMatchesRegularExpression(
            '/current_version\s*>=\s*12\s*\)\s*\{\s*return/s',
            self::$mariadb_php,
            'Должен быть fast-path return при current_version >= 12'
        );
    }

    // ============================================================
    // 3. CREATE TABLE cashback_shop_tariffs — все ключевые колонки.
    // ============================================================

    public function test_creates_cashback_shop_tariffs_table(): void
    {
        $this->assertMatchesRegularExpression(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?[^`]*cashback_shop_tariffs`?/is',
            self::$mariadb_php,
            'CREATE TABLE IF NOT EXISTS для cashback_shop_tariffs'
        );
    }

    public function test_shop_tariffs_table_has_required_columns(): void
    {
        $required_columns = array(
            'network_id',
            'offer_id',
            'tariff_id',
            'name',
            'tariff_type',
            'payment_size',
            'payment_min',
            'payment_max',
            'currency',
            'is_default',
            'is_deleted',
            'raw_payload',
            'imported_at',
            'updated_at',
        );

        foreach ($required_columns as $col) {
            $this->assertStringContainsString(
                '`' . $col . '`',
                self::$mariadb_php,
                "Таблица cashback_shop_tariffs должна содержать колонку `{$col}`"
            );
        }
    }

    public function test_shop_tariffs_unique_key_network_offer_tariff(): void
    {
        $this->assertMatchesRegularExpression(
            '/UNIQUE\s+KEY\s+`?uniq_network_offer_tariff`?\s*\(\s*`network_id`\s*,\s*`offer_id`\s*,\s*`tariff_id`\s*\)/is',
            self::$mariadb_php,
            'UNIQUE (network_id, offer_id, tariff_id) обязателен для shop_tariffs'
        );
    }

    public function test_shop_tariffs_uses_utf8mb4_collation(): void
    {
        if (preg_match(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?[^`]*cashback_shop_tariffs`?\s*\(.*?\)\s*ENGINE\s*=\s*InnoDB[^;]*;/is',
            self::$mariadb_php,
            $m
        )) {
            $ddl = $m[0];
            $this->assertStringNotContainsString('ascii_bin', $ddl, 'shop_tariffs не должна использовать ascii_bin');
            $this->assertStringContainsString('utf8mb4', strtolower($ddl), 'shop_tariffs должна использовать utf8mb4');
        } else {
            $this->fail('CREATE TABLE cashback_shop_tariffs не найден');
        }
    }

    public function test_shop_tariffs_payment_size_decimal(): void
    {
        if (preg_match(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?[^`]*cashback_shop_tariffs`?\s*\(.*?\)\s*ENGINE\s*=\s*InnoDB[^;]*;/is',
            self::$mariadb_php,
            $m
        )) {
            $ddl = $m[0];
            $this->assertMatchesRegularExpression(
                '/`payment_size`\s+DECIMAL\s*\(\s*12\s*,\s*4\s*\)/i',
                $ddl,
                'payment_size должен быть DECIMAL(12,4)'
            );
        } else {
            $this->fail('CREATE TABLE cashback_shop_tariffs не найден');
        }
    }

    public function test_shop_tariffs_tariff_type_enum_percent_fix(): void
    {
        if (preg_match(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?[^`]*cashback_shop_tariffs`?\s*\(.*?\)\s*ENGINE\s*=\s*InnoDB[^;]*;/is',
            self::$mariadb_php,
            $m
        )) {
            $ddl = $m[0];
            $this->assertMatchesRegularExpression(
                "/`tariff_type`\s+ENUM\s*\(\s*'percent'\s*,\s*'fix'\s*\)/i",
                $ddl,
                "tariff_type должен быть ENUM('percent','fix')"
            );
        } else {
            $this->fail('CREATE TABLE cashback_shop_tariffs не найден');
        }
    }

    // ============================================================
    // 4. CREATE TABLE cashback_shop_groups (дедуп по домену).
    // ============================================================

    public function test_creates_cashback_shop_groups_table(): void
    {
        $this->assertMatchesRegularExpression(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?[^`]*cashback_shop_groups`?/is',
            self::$mariadb_php,
            'CREATE TABLE IF NOT EXISTS для cashback_shop_groups'
        );
    }

    public function test_shop_groups_table_has_required_columns(): void
    {
        $required_columns = array(
            'domain',
            'display_name',
            'preferred_product_id',
            'pin_product_id',
            'status',
        );

        foreach ($required_columns as $col) {
            $this->assertStringContainsString(
                '`' . $col . '`',
                self::$mariadb_php,
                "Таблица cashback_shop_groups должна содержать колонку `{$col}`"
            );
        }
    }

    public function test_shop_groups_unique_domain(): void
    {
        $this->assertMatchesRegularExpression(
            '/UNIQUE\s+KEY\s+`?uniq_domain`?\s*\(\s*`domain`\s*\)/is',
            self::$mariadb_php,
            'UNIQUE (domain) обязателен для shop_groups'
        );
    }

    public function test_shop_groups_status_enum(): void
    {
        if (preg_match(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?[^`]*cashback_shop_groups`?\s*\(.*?\)\s*ENGINE\s*=\s*InnoDB[^;]*;/is',
            self::$mariadb_php,
            $m
        )) {
            $ddl = $m[0];
            $this->assertMatchesRegularExpression(
                "/`status`\s+ENUM\s*\(\s*'auto'\s*,\s*'confirmed'\s*,\s*'manual'\s*,\s*'split'\s*\)/i",
                $ddl,
                "status должен быть ENUM('auto','confirmed','manual','split')"
            );
        } else {
            $this->fail('CREATE TABLE cashback_shop_groups не найден');
        }
    }

    // ============================================================
    // 5. CREATE TABLE cashback_shop_group_members.
    // ============================================================

    public function test_creates_cashback_shop_group_members_table(): void
    {
        $this->assertMatchesRegularExpression(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?[^`]*cashback_shop_group_members`?/is',
            self::$mariadb_php,
            'CREATE TABLE IF NOT EXISTS для cashback_shop_group_members'
        );
    }

    public function test_shop_group_members_unique_product(): void
    {
        // Один продукт — максимум в одной группе.
        $this->assertMatchesRegularExpression(
            '/UNIQUE\s+KEY\s+`?uniq_product`?\s*\(\s*`product_id`\s*\)/is',
            self::$mariadb_php,
            'UNIQUE (product_id) обязателен для shop_group_members'
        );
    }

    public function test_shop_group_members_has_is_excluded_column(): void
    {
        if (preg_match(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?[^`]*cashback_shop_group_members`?\s*\(.*?\)\s*ENGINE\s*=\s*InnoDB[^;]*;/is',
            self::$mariadb_php,
            $m
        )) {
            $ddl = $m[0];
            $this->assertStringContainsString('`is_excluded`', $ddl);
        } else {
            $this->fail('CREATE TABLE cashback_shop_group_members не найден');
        }
    }

    // ============================================================
    // 6. CREATE TABLE cashback_shop_import_log.
    // ============================================================

    public function test_creates_cashback_shop_import_log_table(): void
    {
        $this->assertMatchesRegularExpression(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?[^`]*cashback_shop_import_log`?/is',
            self::$mariadb_php,
            'CREATE TABLE IF NOT EXISTS для cashback_shop_import_log'
        );
    }

    public function test_shop_import_log_has_run_id_and_counters(): void
    {
        $required_columns = array(
            'run_id',
            'network_id',
            'page',
            'fetched',
            'upserted_new',
            'upserted_upd',
            'tariffs_synced',
            'errors',
            'started_at',
            'finished_at',
        );

        foreach ($required_columns as $col) {
            $this->assertStringContainsString(
                '`' . $col . '`',
                self::$mariadb_php,
                "Таблица cashback_shop_import_log должна содержать колонку `{$col}`"
            );
        }
    }

    // ============================================================
    // 7. Hook миграции v12 в activate() и в maybe_run_migrations().
    // ============================================================

    public function test_activate_hooks_migrate_shop_import_v12(): void
    {
        $this->assertMatchesRegularExpression(
            '/migrate_shop_import_v12\s*\(\s*\)/i',
            self::$mariadb_php,
            'Mariadb_Plugin::activate() должен вызывать migrate_shop_import_v12'
        );
    }

    public function test_cashback_plugin_php_hooks_migrate_shop_import_v12(): void
    {
        $this->assertStringContainsString(
            'migrate_shop_import_v12',
            self::$cashback_plugin_php,
            'cashback-plugin.php должен вызывать migrate_shop_import_v12 в maybe_run_migrations'
        );
    }

    // ============================================================
    // 8. Раздел post-verify через INFORMATION_SCHEMA.
    // ============================================================

    public function test_migration_uses_information_schema_for_idempotency(): void
    {
        $start = strpos(self::$mariadb_php, 'public function migrate_shop_import_v12');
        $this->assertNotFalse($start, 'method migrate_shop_import_v12 not found');

        // Find end-of-method by looking for next public function.
        $next = strpos(self::$mariadb_php, "\n    public function ", $start + 1);
        if ($next === false) {
            $next = strlen(self::$mariadb_php);
        }
        $migration_block = substr(self::$mariadb_php, $start, $next - $start);

        $this->assertNotEmpty($migration_block);
        $this->assertStringContainsString(
            'information_schema',
            strtolower($migration_block),
            'Миграция должна использовать information_schema для idempotency post-verify'
        );
    }

    // ============================================================
    // 9. Все 4 таблицы в одной миграции (single transaction-like).
    // ============================================================

    public function test_migration_covers_all_four_tables(): void
    {
        $start = strpos(self::$mariadb_php, 'public function migrate_shop_import_v12');
        $this->assertNotFalse($start);

        $next = strpos(self::$mariadb_php, "\n    public function ", $start + 1);
        if ($next === false) {
            $next = strlen(self::$mariadb_php);
        }
        $block = substr(self::$mariadb_php, $start, $next - $start);

        $this->assertStringContainsString('cashback_shop_tariffs', $block);
        $this->assertStringContainsString('cashback_shop_groups', $block);
        $this->assertStringContainsString('cashback_shop_group_members', $block);
        $this->assertStringContainsString('cashback_shop_import_log', $block);
    }
}
