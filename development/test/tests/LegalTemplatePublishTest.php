<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Legal_Template_Storage::publish_draft — атомарная публикация.
 *
 * Покрывает:
 *   - happy path: bump version, draft → published, old published → superseded,
 *     option cashback_legal_consent_versions обновляется, audit log пишется,
 *     wp_schedule_single_event для batch superseded ставится.
 *   - error: нет draft → 'no_draft'.
 *   - error: optimistic concurrency mismatch → 'concurrent_modification'.
 *   - error: validation fail (missing placeholders) → WP_Error из validator.
 *   - idempotency: повторный publish с тем же idempotency_key → возврат
 *     stored result, без новых INSERT/UPDATE.
 */
#[Group('legal')]
#[Group('legal-template-publish')]
final class LegalTemplatePublishTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/includes/class-cashback-idempotency.php';
        require_once $plugin_root . '/legal/class-cashback-legal-documents.php';
        require_once $plugin_root . '/legal/class-cashback-legal-operator.php';
        require_once $plugin_root . '/legal/class-cashback-legal-template-validator.php';
        require_once $plugin_root . '/legal/class-cashback-legal-template-storage.php';

        if (!function_exists('wp_kses')) {
            function wp_kses(string $html, array $allowed_html): string
            {
                $tags = '';
                foreach (array_keys($allowed_html) as $tag) {
                    $tags .= '<' . $tag . '>';
                }
                return strip_tags($html, $tags);
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options']      = array();
        $GLOBALS['_cb_test_transients']   = array();
        $GLOBALS['_cb_test_cache']        = array();
        $GLOBALS['_cb_test_user_id']      = 7;
        $GLOBALS['_cb_test_scheduled']    = array();
        $GLOBALS['wpdb']                  = $this->make_wpdb_mock();
    }

    private function make_wpdb_mock(): object
    {
        return new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = array();
            /** @var array<int, array<string, mixed>> */
            public array $audit_rows = array();
            private int $next_id = 100;
            /** @var array<int, array<string, mixed>> */
            public array $insert_calls = array();
            /** @var array<int, array<string, mixed>> */
            public array $update_calls = array();
            /** @var array<int, string> */
            public array $query_calls = array();

            public function suppress_errors( bool $s = true ): bool { return false; }

            public function prepare( string $q, mixed ...$args ): string {
                $i = 0;
                return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) {
                    $v = $args[ $i++ ] ?? '';
                    return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
                }, $q) ?? $q;
            }

            public function query( string $sql ): int|false {
                $this->query_calls[] = $sql;
                return 1;
            }

            public function insert( string $table, array $data, mixed $format = null ): int|false {
                $this->insert_calls[] = array( 'table' => $table, 'data' => $data );
                if ($table === 'wp_cashback_audit_log') {
                    $this->audit_rows[] = $data;
                    return 1;
                }
                $id = $this->next_id++;
                $this->rows[ $id ] = array_merge(array( 'id' => $id ), $data);
                $this->insert_id = $id;
                return 1;
            }

            public function update( string $table, array $data, array $where, mixed $f = null, mixed $wf = null ): int|false {
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

            public function delete( string $table, array $where, mixed $wf = null ): int|false {
                return 0;
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

    private function seed_published_and_draft( string $type, string $published_body, string $draft_body, string $version = '1.0.0' ): void
    {
        $GLOBALS['wpdb']->rows[1] = array(
            'id'           => 1,
            'consent_type' => $type,
            'version'      => $version,
            'body_html'    => $published_body,
            'body_hash'    => hash('sha256', $published_body),
            'status'       => 'published',
            'created_at'   => '2026-04-25 00:00:00',
            'created_by'   => 1,
            'published_at' => '2026-04-25 00:00:00',
            'published_by' => 1,
        );
        $GLOBALS['wpdb']->rows[2] = array(
            'id'           => 2,
            'consent_type' => $type,
            'version'      => '0.0.0-draft',
            'body_html'    => $draft_body,
            'body_hash'    => hash('sha256', $draft_body),
            'status'       => 'draft',
            'created_at'   => '2026-05-07 12:00:00',
            'created_by'   => 7,
        );
        // Option версионирования синхронизируется с published row.
        $GLOBALS['_cb_test_options']['cashback_legal_consent_versions'] = array( $type => $version );
    }

    private function php_template_body_for( string $type ): string
    {
        $reflect = new ReflectionClass(Cashback_Legal_Documents::class);
        $method  = $reflect->getMethod('load_template');
        return (string) $method->invoke(null, $type);
    }

    // ────────────────────────────────────────────────────────────
    // happy path
    // ────────────────────────────────────────────────────────────

    public function test_publish_bumps_version_and_swaps_statuses(): void
    {
        $php_body = $this->php_template_body_for('pd_consent');
        // draft = существующий PHP-шаблон с микро-правкой (placeholder'ы сохранены).
        $draft_body = $php_body . "\n<!-- minor edit -->";
        $this->seed_published_and_draft('pd_consent', $php_body, $draft_body, '1.0.0');

        $expected_pub_hash = hash('sha256', $php_body);

        $result = Cashback_Legal_Template_Storage::publish_draft(
            'pd_consent',
            7,
            'idem-key-001',
            $expected_pub_hash
        );

        $this->assertIsArray($result);
        $this->assertSame('1.0.0', $result['old_version']);
        $this->assertSame('2.0.0', $result['new_version']);
        $this->assertSame(hash('sha256', $draft_body), $result['body_hash']);

        // Состояние строк после publish.
        $this->assertSame('superseded', $GLOBALS['wpdb']->rows[1]['status'], 'Старая published → superseded.');
        $this->assertSame('published', $GLOBALS['wpdb']->rows[2]['status'], 'Draft → published.');
        $this->assertSame('2.0.0', $GLOBALS['wpdb']->rows[2]['version']);
        $this->assertSame(7, (int) $GLOBALS['wpdb']->rows[2]['published_by']);

        // Option-версия обновлена.
        $stored_versions = $GLOBALS['_cb_test_options']['cashback_legal_consent_versions'] ?? array();
        $this->assertSame('2.0.0', $stored_versions['pd_consent']);
    }

    public function test_publish_writes_audit_log(): void
    {
        $php_body   = $this->php_template_body_for('pd_consent');
        $draft_body = $php_body . "\n<!-- edit -->";
        $this->seed_published_and_draft('pd_consent', $php_body, $draft_body, '1.0.0');

        Cashback_Legal_Template_Storage::publish_draft(
            'pd_consent',
            7,
            'idem-audit-001',
            hash('sha256', $php_body)
        );

        $audit = $GLOBALS['wpdb']->audit_rows;
        $this->assertNotEmpty($audit, 'Audit log должен быть записан.');
        $found = false;
        foreach ($audit as $entry) {
            if (($entry['action'] ?? '') === 'legal_template_published') {
                $found = true;
                $details = json_decode((string) $entry['details'], true);
                $this->assertSame('pd_consent', $details['consent_type'] ?? null);
                $this->assertSame('1.0.0', $details['from_version'] ?? null);
                $this->assertSame('2.0.0', $details['to_version'] ?? null);
                break;
            }
        }
        $this->assertTrue($found, 'audit_log с action=legal_template_published не найден.');
    }

    public function test_publish_schedules_supersede_batch(): void
    {
        $php_body   = $this->php_template_body_for('pd_consent');
        $draft_body = $php_body . "\n<!-- edit -->";
        $this->seed_published_and_draft('pd_consent', $php_body, $draft_body, '1.0.0');

        Cashback_Legal_Template_Storage::publish_draft(
            'pd_consent',
            7,
            'idem-schedule-001',
            hash('sha256', $php_body)
        );

        $this->assertNotEmpty($GLOBALS['_cb_test_scheduled'], 'wp_schedule_single_event должен быть вызван.');
        $first = $GLOBALS['_cb_test_scheduled'][0];
        $this->assertSame('cashback_legal_bump_batch', $first['hook']);
        $this->assertSame(array( 'pd_consent', '2.0.0' ), $first['args']);
    }

    // ────────────────────────────────────────────────────────────
    // error paths
    // ────────────────────────────────────────────────────────────

    public function test_publish_returns_error_when_no_draft(): void
    {
        $php_body = $this->php_template_body_for('pd_consent');
        $GLOBALS['wpdb']->rows[1] = array(
            'id' => 1, 'consent_type' => 'pd_consent', 'version' => '1.0.0',
            'body_html' => $php_body, 'body_hash' => hash('sha256', $php_body),
            'status' => 'published',
        );
        $GLOBALS['_cb_test_options']['cashback_legal_consent_versions'] = array( 'pd_consent' => '1.0.0' );

        $err = Cashback_Legal_Template_Storage::publish_draft('pd_consent', 7, 'idem-no-draft', hash('sha256', $php_body));
        $this->assertInstanceOf(WP_Error::class, $err);
        $this->assertSame('no_draft', $err->get_error_code());
    }

    public function test_publish_rejects_when_expected_hash_mismatch(): void
    {
        $php_body   = $this->php_template_body_for('pd_consent');
        $draft_body = $php_body . "\n<!-- edit -->";
        $this->seed_published_and_draft('pd_consent', $php_body, $draft_body, '1.0.0');

        $err = Cashback_Legal_Template_Storage::publish_draft(
            'pd_consent',
            7,
            'idem-stale-hash',
            'STALEHASH_DOES_NOT_MATCH_ANYTHING'
        );

        $this->assertInstanceOf(WP_Error::class, $err);
        $this->assertSame('concurrent_modification', $err->get_error_code());
        // Состояние БД не должно меняться.
        $this->assertSame('published', $GLOBALS['wpdb']->rows[1]['status']);
        $this->assertSame('draft', $GLOBALS['wpdb']->rows[2]['status']);
    }

    public function test_publish_rejects_when_validator_fails(): void
    {
        $php_body = $this->php_template_body_for('pd_consent');
        // Draft без обязательных placeholder'ов — validator вернёт placeholders_missing.
        $bad_draft = '<p>Совсем без плейсхолдеров.</p>';
        $this->seed_published_and_draft('pd_consent', $php_body, $bad_draft, '1.0.0');

        $err = Cashback_Legal_Template_Storage::publish_draft(
            'pd_consent',
            7,
            'idem-bad-draft',
            hash('sha256', $php_body)
        );

        $this->assertInstanceOf(WP_Error::class, $err);
        $this->assertSame('placeholders_missing', $err->get_error_code());
        // Состояние не меняется.
        $this->assertSame('published', $GLOBALS['wpdb']->rows[1]['status']);
        $this->assertSame('draft', $GLOBALS['wpdb']->rows[2]['status']);
    }

    public function test_publish_rejects_when_idempotency_key_empty(): void
    {
        $php_body   = $this->php_template_body_for('pd_consent');
        $draft_body = $php_body . "\n<!-- edit -->";
        $this->seed_published_and_draft('pd_consent', $php_body, $draft_body, '1.0.0');

        $err = Cashback_Legal_Template_Storage::publish_draft(
            'pd_consent',
            7,
            '',
            hash('sha256', $php_body)
        );
        $this->assertInstanceOf(WP_Error::class, $err);
        $this->assertSame('idempotency_key_required', $err->get_error_code());
    }

    public function test_publish_replay_with_same_idempotency_key_returns_cached_result(): void
    {
        $php_body   = $this->php_template_body_for('pd_consent');
        $draft_body = $php_body . "\n<!-- edit -->";
        $this->seed_published_and_draft('pd_consent', $php_body, $draft_body, '1.0.0');

        $key   = 'idem-replay-001';
        $first = Cashback_Legal_Template_Storage::publish_draft('pd_consent', 7, $key, hash('sha256', $php_body));
        $this->assertIsArray($first);

        // Сбрасываем счётчики, повторный вызов с тем же key.
        $GLOBALS['wpdb']->insert_calls = array();
        $GLOBALS['wpdb']->update_calls = array();

        $second = Cashback_Legal_Template_Storage::publish_draft('pd_consent', 7, $key, 'IGNORED_HASH');
        $this->assertIsArray($second);
        $this->assertSame($first['old_version'], $second['old_version']);
        $this->assertSame($first['new_version'], $second['new_version']);

        $this->assertSame(0, count($GLOBALS['wpdb']->insert_calls), 'Replay не должен делать новые INSERT.');
        $this->assertSame(0, count($GLOBALS['wpdb']->update_calls), 'Replay не должен делать новые UPDATE.');
    }
}

// Стаб wp_schedule_single_event — пушит в $GLOBALS['_cb_test_scheduled'].
if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(int $timestamp, string $hook, array $args = array()): bool
    {
        $GLOBALS['_cb_test_scheduled'][] = array(
            'timestamp' => $timestamp,
            'hook'      => $hook,
            'args'      => $args,
        );
        return true;
    }
}
