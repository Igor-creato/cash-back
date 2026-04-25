<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Legal_Consent_Manager::mark_superseded_for_type (Phase 5).
 *
 * Покрывает:
 *  - invalid type → 0
 *  - empty user list → 0
 *  - users с last granted version < bumped → инсёрт superseded
 *  - users с last granted version >= bumped → пропуск
 */
#[Group('legal')]
#[Group('legal-supersede')]
final class LegalSupersedeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/legal/class-cashback-legal-db.php';
        require_once $plugin_root . '/legal/class-cashback-legal-documents.php';
        require_once $plugin_root . '/legal/class-cashback-legal-operator.php';
        require_once $plugin_root . '/legal/class-cashback-legal-consent-manager.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options']             = array();
        $GLOBALS['_cb_test_legal_inserted_rows'] = array();

        // Stub wpdb с управлением get_col + get_row для разных юзеров.
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public int $insert_id = 0;
            private int $next_id = 400;

            /** @var array<int, string> */
            public array $candidate_user_ids = array();
            /** @var array<int, array<string, mixed>|null> */
            public array $last_granted_per_user = array();

            public function suppress_errors( bool $suppress = true ) {
                return false;
            }

            public function insert( string $table, array $data, $format = null ) {
                $GLOBALS['_cb_test_legal_inserted_rows'][] = $data;
                $this->insert_id = $this->next_id++;
                return 1;
            }

            public function prepare( string $q, ...$args ): string {
                // Помечаем тип запроса по содержимому.
                if (strpos($q, 'SELECT DISTINCT user_id') !== false) {
                    return 'CANDIDATES_QUERY';
                }
                if (strpos($q, "action = 'granted'") !== false) {
                    // Это get_last_active_granted — но нам нужен user_id из args.
                    return 'GRANTED_QUERY:' . (string) ( $args[1] ?? 0 );
                }
                if (strpos($q, "action IN ('revoked', 'superseded')") !== false) {
                    return 'SUPERSEDED_CHECK';
                }
                return 'GENERIC';
            }

            public function get_col( string $q ) {
                if ($q === 'CANDIDATES_QUERY') {
                    return $this->candidate_user_ids;
                }
                return array();
            }

            public function get_row( string $q, $output = ARRAY_A, int $y = 0 ) {
                if (strpos($q, 'GRANTED_QUERY') !== false) {
                    // user_id извлекается caller'ом get_last_active_granted из args.
                    // Мы возвращаем next-in-line из last_granted_per_user через
                    // сtatic counter — так как порядок stable в mark_superseded_for_type.
                    static $idx = 0;
                    $uids = array_keys($this->last_granted_per_user);
                    if ($idx >= count($uids)) {
                        $idx = 0;
                    }
                    $uid = $uids[ $idx ] ?? 0;
                    $idx++;
                    return $this->last_granted_per_user[ $uid ] ?? null;
                }
                return null;
            }

            public function get_var( string $q ) {
                // get_last_active_granted делает дополнительный get_var на supersession check.
                return null;
            }
        };
    }

    public function test_invalid_type_returns_zero(): void
    {
        $count = Cashback_Legal_Consent_Manager::mark_superseded_for_type('arbitrary', '2.0.0');
        $this->assertSame(0, $count);
    }

    public function test_empty_candidates_returns_zero(): void
    {
        $GLOBALS['wpdb']->candidate_user_ids = array();
        $count = Cashback_Legal_Consent_Manager::mark_superseded_for_type('pd_consent', '2.0.0');
        $this->assertSame(0, $count);
    }

    public function test_users_with_old_version_get_superseded(): void
    {
        $GLOBALS['wpdb']->candidate_user_ids = array( '10', '11' );
        $GLOBALS['wpdb']->last_granted_per_user = array(
            10 => array(
                'id'               => 1,
                'document_version' => '1.0.0',
                'document_hash'    => 'h1',
                'document_url'     => 'http://localhost/pd-consent',
                'granted_at'       => '2026-01-01 00:00:00',
            ),
            11 => array(
                'id'               => 2,
                'document_version' => '1.0.0',
                'document_hash'    => 'h1',
                'document_url'     => 'http://localhost/pd-consent',
                'granted_at'       => '2026-02-01 00:00:00',
            ),
        );

        $count = Cashback_Legal_Consent_Manager::mark_superseded_for_type('pd_consent', '2.0.0');
        $this->assertSame(2, $count);
        $this->assertCount(2, $GLOBALS['_cb_test_legal_inserted_rows']);
        foreach ($GLOBALS['_cb_test_legal_inserted_rows'] as $row) {
            $this->assertSame('superseded', $row['action']);
            $this->assertSame('admin_bump', $row['source']);
            $this->assertSame('pd_consent', $row['consent_type']);
        }
    }

    public function test_user_with_current_version_is_skipped(): void
    {
        $GLOBALS['wpdb']->candidate_user_ids = array( '20' );
        $GLOBALS['wpdb']->last_granted_per_user = array(
            20 => array(
                'id'               => 5,
                'document_version' => '2.0.0',
                'document_hash'    => 'h2',
                'granted_at'       => '2026-04-01 00:00:00',
            ),
        );

        $count = Cashback_Legal_Consent_Manager::mark_superseded_for_type('pd_consent', '2.0.0');
        $this->assertSame(0, $count);
        $this->assertSame(array(), $GLOBALS['_cb_test_legal_inserted_rows']);
    }
}
