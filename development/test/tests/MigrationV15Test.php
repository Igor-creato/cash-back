<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional тесты на Mariadb_Plugin::migrate_v15_uniqueness (v4.3.4).
 * Закрывает audit-finding C-2: race на seed sub1/sub2 без UNIQUE на (network_id, param_name).
 *
 * @group migration
 * @group v15
 */
#[Group('migration')]
#[Group('v15')]
final class MigrationV15Test extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once dirname(__DIR__) . '/Shop_Test_Wpdb_Stub.php';

        if (!class_exists('Mariadb_Plugin')) {
            require_once $plugin_root . '/mariadb.php';
        }
    }

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new Shop_Test_Wpdb_Stub();
        $GLOBALS['_cb_test_options'] = array();
    }

    public function test_fast_path_when_db_version_already_15(): void
    {
        update_option('cashback_db_version', 15);
        global $wpdb;

        Mariadb_Plugin::get_instance()->migrate_v15_uniqueness();

        $this->assertSame(array(), $wpdb->queries, 'fast-path: ни одного SQL-вызова');
    }

    public function test_skips_alter_when_unique_index_already_present(): void
    {
        update_option('cashback_db_version', 14);
        global $wpdb;
        // information_schema → UNIQUE найден (имитируем): get_results вернёт non-empty.
        $wpdb->next_get_results = array( (object) array( 'INDEX_NAME' => 'uk_network_param' ) );

        Mariadb_Plugin::get_instance()->migrate_v15_uniqueness();

        $sqls = array_map(static fn($q) => $q['sql'], $wpdb->queries);
        $this->assertNotContains(true, array_map(
            static fn(string $s): bool => stripos($s, 'ADD UNIQUE KEY') !== false,
            $sqls
        ), 'Если UNIQUE уже есть — ALTER не вызывается');
        $this->assertSame(15, (int) get_option('cashback_db_version', 0), 'db_version всё равно bump до 15');
    }

    public function test_dedupes_and_adds_unique_when_missing(): void
    {
        update_option('cashback_db_version', 14);
        global $wpdb;
        // information_schema → пустой (нет UNIQUE) → migration пройдёт всю branch.
        $wpdb->next_get_results = array();

        Mariadb_Plugin::get_instance()->migrate_v15_uniqueness();

        $sqls = array_map(static fn($q) => $q['sql'], $wpdb->queries);
        // 1) DELETE p1 ... — дедупликация
        $has_dedup = false;
        $has_alter = false;
        foreach ($sqls as $s) {
            if (stripos($s, 'DELETE p1 FROM') !== false) { $has_dedup = true; }
            if (stripos($s, 'ADD UNIQUE KEY `uk_network_param`') !== false) { $has_alter = true; }
        }
        $this->assertTrue($has_dedup, 'DELETE p1 ... dedupe запрос должен быть выполнен');
        $this->assertTrue($has_alter, 'ALTER ADD UNIQUE KEY uk_network_param должен быть выполнен');
        $this->assertSame(15, (int) get_option('cashback_db_version', 0));
    }

    public function test_does_not_bump_version_if_alter_fails(): void
    {
        update_option('cashback_db_version', 14);
        global $wpdb;
        $wpdb->next_get_results        = array();
        $wpdb->fail_on_query_substring = 'ADD UNIQUE KEY';  // ALTER падает

        Mariadb_Plugin::get_instance()->migrate_v15_uniqueness();

        $this->assertSame(14, (int) get_option('cashback_db_version', 0), 'failure → db_version не bumped');
    }
}
