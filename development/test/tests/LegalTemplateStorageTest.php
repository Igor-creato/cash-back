<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Legal_Template_Storage — DAO для wp_cashback_legal_template_versions.
 *
 * Покрывает:
 *   - seed_if_missing: первый вызов копирует PHP-template как published v1.0.0,
 *     повторный — no-op.
 *   - get_active_body / get_draft / discard_draft (round-trip).
 *   - save_draft: insert первого, update при изменённом hash, no-op при том же hash.
 */
#[Group('legal')]
#[Group('legal-template-storage')]
final class LegalTemplateStorageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/legal/class-cashback-legal-documents.php';
        require_once $plugin_root . '/legal/class-cashback-legal-operator.php';
        require_once $plugin_root . '/legal/class-cashback-legal-template-storage.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options'] = array();
        $GLOBALS['wpdb']             = $this->make_wpdb_mock();
        $GLOBALS['_cb_test_user_id'] = 7;
    }

    /**
     * In-memory wpdb с честной таблицей wp_cashback_legal_template_versions.
     * Поддерживает узкий набор SQL'ов, которые использует DAO.
     */
    private function make_wpdb_mock(): object
    {
        return new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = array();
            private int $next_id = 100;
            /** @var array<int, array<string, mixed>> */
            public array $insert_calls = array();
            /** @var array<int, array<string, mixed>> */
            public array $update_calls = array();
            /** @var array<int, array<string, mixed>> */
            public array $delete_calls = array();
            /** @var array<int, string> */
            public array $query_calls = array();

            public function suppress_errors( bool $suppress = true ): bool {
                return false;
            }

            public function prepare( string $q, mixed ...$args ): string {
                $i = 0;
                return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) {
                    $v = $args[ $i++ ] ?? '';
                    return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
                }, $q) ?? $q;
            }

            public function query( string $sql ): int|false {
                $this->query_calls[] = $sql;
                if (preg_match('/UPDATE\s+wp_cashback_legal_template_versions\s+SET\s+status\s*=\s*\'superseded\'\s+WHERE\s+id\s*=\s*(\d+)/i', $sql, $m)) {
                    $id = (int) $m[1];
                    if (isset($this->rows[ $id ])) {
                        $this->rows[ $id ]['status'] = 'superseded';
                    }
                    return 1;
                }
                return 1;
            }

            public function insert( string $table, array $data, mixed $format = null ): int|false {
                $this->insert_calls[] = array( 'table' => $table, 'data' => $data );
                $id = $this->next_id++;
                $this->rows[ $id ] = array_merge(array( 'id' => $id ), $data);
                $this->insert_id = $id;
                return 1;
            }

            public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int|false {
                $this->update_calls[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
                $matched = 0;
                foreach ($this->rows as $id => $row) {
                    $ok = true;
                    foreach ($where as $k => $v) {
                        if (($row[ $k ] ?? null) != $v) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
                            $ok = false;
                            break;
                        }
                    }
                    if ($ok) {
                        $this->rows[ $id ] = array_merge($row, $data);
                        $matched++;
                    }
                }
                return $matched;
            }

            public function delete( string $table, array $where, mixed $where_format = null ): int|false {
                $this->delete_calls[] = array( 'table' => $table, 'where' => $where );
                $deleted = 0;
                foreach ($this->rows as $id => $row) {
                    $ok = true;
                    foreach ($where as $k => $v) {
                        if (($row[ $k ] ?? null) != $v) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
                            $ok = false;
                            break;
                        }
                    }
                    if ($ok) {
                        unset($this->rows[ $id ]);
                        $deleted++;
                    }
                }
                return $deleted;
            }

            public function get_row( string $sql, mixed $output = ARRAY_A, int $y = 0 ): mixed {
                if (preg_match('/SELECT[\s\S]+FROM\s+wp_cashback_legal_template_versions\s+WHERE\s+consent_type\s*=\s*\'([^\']+)\'\s+AND\s+status\s*=\s*\'(draft|published)\'/i', $sql, $m)) {
                    foreach ($this->rows as $row) {
                        if ($row['consent_type'] === $m[1] && $row['status'] === $m[2]) {
                            return $row;
                        }
                    }
                    return null;
                }
                return null;
            }

            public function get_var( string $sql ): mixed {
                if (preg_match('/SHOW TABLES LIKE\s+\'([^\']+)\'/i', $sql, $m)) {
                    return $m[1];
                }
                return null;
            }
        };
    }

    // ────────────────────────────────────────────────────────────
    // table_name
    // ────────────────────────────────────────────────────────────

    public function test_table_name_uses_wpdb_prefix(): void
    {
        $this->assertSame('wp_cashback_legal_template_versions', Cashback_Legal_Template_Storage::table_name());
    }

    // ────────────────────────────────────────────────────────────
    // seed_if_missing
    // ────────────────────────────────────────────────────────────

    public function test_seed_if_missing_creates_published_v100_when_no_rows(): void
    {
        Cashback_Legal_Template_Storage::seed_if_missing('pd_consent');

        $this->assertCount(1, $GLOBALS['wpdb']->insert_calls, 'Должен быть один INSERT при seed.');
        $insert = $GLOBALS['wpdb']->insert_calls[0]['data'];
        $this->assertSame('pd_consent', $insert['consent_type']);
        $this->assertSame('1.0.0', $insert['version']);
        $this->assertSame('published', $insert['status']);
        $this->assertNotEmpty($insert['body_html'], 'body_html не пустое (скопировано из PHP-template).');
        $this->assertSame(64, strlen((string) $insert['body_hash']), 'body_hash — SHA-256 hex (64 chars).');
        $this->assertSame(hash('sha256', (string) $insert['body_html']), $insert['body_hash']);
    }

    public function test_seed_if_missing_idempotent_when_published_exists(): void
    {
        $GLOBALS['wpdb']->rows[1] = array(
            'id'           => 1,
            'consent_type' => 'pd_consent',
            'version'      => '1.0.0',
            'body_html'    => '<p>existing</p>',
            'body_hash'    => hash('sha256', '<p>existing</p>'),
            'status'       => 'published',
            'created_at'   => '2026-04-25 00:00:00',
            'created_by'   => 1,
            'published_at' => '2026-04-25 00:00:00',
            'published_by' => 1,
        );

        Cashback_Legal_Template_Storage::seed_if_missing('pd_consent');

        $this->assertSame(array(), $GLOBALS['wpdb']->insert_calls, 'Повторный seed не должен делать INSERT.');
    }

    public function test_seed_if_missing_skips_when_only_draft_exists(): void
    {
        // Если есть только draft (не published) — seed всё равно создаёт published v1.0.0,
        // т.к. draft без published — невалидное состояние, baseline нужен.
        $GLOBALS['wpdb']->rows[5] = array(
            'id'           => 5,
            'consent_type' => 'pd_consent',
            'version'      => '0.0.0-draft',
            'body_html'    => '<p>draft</p>',
            'body_hash'    => hash('sha256', '<p>draft</p>'),
            'status'       => 'draft',
            'created_at'   => '2026-05-07 00:00:00',
        );

        Cashback_Legal_Template_Storage::seed_if_missing('pd_consent');

        $this->assertCount(1, $GLOBALS['wpdb']->insert_calls);
        $this->assertSame('published', $GLOBALS['wpdb']->insert_calls[0]['data']['status']);
    }

    public function test_seed_if_missing_rejects_unknown_type(): void
    {
        Cashback_Legal_Template_Storage::seed_if_missing('made_up_type');

        $this->assertSame(array(), $GLOBALS['wpdb']->insert_calls);
    }

    // ────────────────────────────────────────────────────────────
    // get_active_body
    // ────────────────────────────────────────────────────────────

    public function test_get_active_body_returns_null_when_no_published_row(): void
    {
        $this->assertNull(Cashback_Legal_Template_Storage::get_active_body('pd_consent'));
    }

    public function test_get_active_body_returns_published_body(): void
    {
        $GLOBALS['wpdb']->rows[1] = array(
            'id'           => 1,
            'consent_type' => 'pd_consent',
            'version'      => '1.0.0',
            'body_html'    => '<p>active text</p>',
            'body_hash'    => hash('sha256', '<p>active text</p>'),
            'status'       => 'published',
        );

        $this->assertSame('<p>active text</p>', Cashback_Legal_Template_Storage::get_active_body('pd_consent'));
    }

    public function test_get_active_body_ignores_drafts_and_superseded(): void
    {
        $GLOBALS['wpdb']->rows[1] = array(
            'id' => 1, 'consent_type' => 'pd_consent', 'status' => 'superseded', 'body_html' => '<p>old</p>',
        );
        $GLOBALS['wpdb']->rows[2] = array(
            'id' => 2, 'consent_type' => 'pd_consent', 'status' => 'draft', 'body_html' => '<p>draft</p>',
        );

        $this->assertNull(Cashback_Legal_Template_Storage::get_active_body('pd_consent'));
    }

    // ────────────────────────────────────────────────────────────
    // get_draft
    // ────────────────────────────────────────────────────────────

    public function test_get_draft_returns_null_when_no_draft(): void
    {
        $this->assertNull(Cashback_Legal_Template_Storage::get_draft('pd_consent'));
    }

    public function test_get_draft_returns_draft_row(): void
    {
        $GLOBALS['wpdb']->rows[1] = array(
            'id'           => 1,
            'consent_type' => 'pd_consent',
            'status'       => 'draft',
            'body_html'    => '<p>draft text</p>',
            'body_hash'    => 'h1',
            'created_at'   => '2026-05-07 12:00:00',
            'created_by'   => 7,
        );

        $draft = Cashback_Legal_Template_Storage::get_draft('pd_consent');
        $this->assertIsArray($draft);
        $this->assertSame('<p>draft text</p>', $draft['body_html']);
        $this->assertSame('h1', $draft['body_hash']);
        $this->assertSame(7, (int) $draft['created_by']);
    }

    // ────────────────────────────────────────────────────────────
    // save_draft
    // ────────────────────────────────────────────────────────────

    public function test_save_draft_inserts_when_no_existing_draft(): void
    {
        $body = '<p>fresh draft</p>';

        $result = Cashback_Legal_Template_Storage::save_draft('pd_consent', $body, 7);

        $this->assertCount(1, $GLOBALS['wpdb']->insert_calls);
        $this->assertSame(0, count($GLOBALS['wpdb']->update_calls));
        $insert = $GLOBALS['wpdb']->insert_calls[0]['data'];
        $this->assertSame('draft', $insert['status']);
        $this->assertSame($body, $insert['body_html']);
        $this->assertSame(hash('sha256', $body), $insert['body_hash']);
        $this->assertSame(7, (int) $insert['created_by']);
        $this->assertSame(hash('sha256', $body), $result['hash']);
    }

    public function test_save_draft_updates_when_body_changed(): void
    {
        $old = '<p>old draft</p>';
        $GLOBALS['wpdb']->rows[10] = array(
            'id'           => 10,
            'consent_type' => 'pd_consent',
            'status'       => 'draft',
            'body_html'    => $old,
            'body_hash'    => hash('sha256', $old),
            'created_at'   => '2026-05-07 11:00:00',
            'created_by'   => 7,
        );

        $new = '<p>new draft body</p>';
        Cashback_Legal_Template_Storage::save_draft('pd_consent', $new, 7);

        $this->assertSame(0, count($GLOBALS['wpdb']->insert_calls), 'Если draft уже есть — не должно быть INSERT.');
        $this->assertCount(1, $GLOBALS['wpdb']->update_calls);
        $update = $GLOBALS['wpdb']->update_calls[0];
        $this->assertSame(array( 'id' => 10 ), $update['where']);
        $this->assertSame($new, $update['data']['body_html']);
        $this->assertSame(hash('sha256', $new), $update['data']['body_hash']);
    }

    public function test_save_draft_noop_when_body_unchanged(): void
    {
        $body = '<p>same body</p>';
        $GLOBALS['wpdb']->rows[10] = array(
            'id'           => 10,
            'consent_type' => 'pd_consent',
            'status'       => 'draft',
            'body_html'    => $body,
            'body_hash'    => hash('sha256', $body),
            'created_at'   => '2026-05-07 11:00:00',
            'created_by'   => 7,
        );

        Cashback_Legal_Template_Storage::save_draft('pd_consent', $body, 7);

        $this->assertSame(0, count($GLOBALS['wpdb']->insert_calls));
        $this->assertSame(0, count($GLOBALS['wpdb']->update_calls));
    }

    public function test_save_draft_rejects_unknown_type(): void
    {
        $result = Cashback_Legal_Template_Storage::save_draft('made_up', '<p>x</p>', 7);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(0, count($GLOBALS['wpdb']->insert_calls));
    }

    // ────────────────────────────────────────────────────────────
    // discard_draft
    // ────────────────────────────────────────────────────────────

    public function test_discard_draft_deletes_existing_draft(): void
    {
        $GLOBALS['wpdb']->rows[10] = array(
            'id' => 10, 'consent_type' => 'pd_consent', 'status' => 'draft',
        );

        $ok = Cashback_Legal_Template_Storage::discard_draft('pd_consent');
        $this->assertTrue($ok);
        $this->assertCount(1, $GLOBALS['wpdb']->delete_calls);
        $this->assertSame(
            array( 'consent_type' => 'pd_consent', 'status' => 'draft' ),
            $GLOBALS['wpdb']->delete_calls[0]['where']
        );
    }

    public function test_discard_draft_noop_when_no_draft(): void
    {
        $ok = Cashback_Legal_Template_Storage::discard_draft('pd_consent');
        $this->assertFalse($ok);
    }
}
