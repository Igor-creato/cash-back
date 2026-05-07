<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Legal_Documents::load_template после введения DB-first override.
 *
 * Покрывает:
 *   - Когда в wp_cashback_legal_template_versions есть published row →
 *     load_template возвращает body_html из БД (не из PHP-файла).
 *   - Когда нет published — fallback на PHP-файл.
 *   - Drafts НЕ должны влиять на load_template (публичная страница использует
 *     только published).
 */
#[Group('legal')]
#[Group('legal-documents-db-first')]
final class LegalDocumentsLoadTemplateDbFirstTest extends TestCase
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
        $GLOBALS['wpdb']             = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            /** @var array<int, array<string, mixed>> */
            public array $rows = array();

            public function suppress_errors( bool $s = true ): bool { return false; }

            public function prepare( string $q, mixed ...$args ): string {
                $i = 0;
                return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) {
                    $v = $args[ $i++ ] ?? '';
                    return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
                }, $q) ?? $q;
            }

            public function get_row( string $sql, mixed $output = ARRAY_A, int $y = 0 ): mixed {
                if (preg_match('/SELECT[\s\S]+FROM\s+wp_cashback_legal_template_versions\s+WHERE\s+consent_type\s*=\s*\'([^\']+)\'\s+AND\s+status\s*=\s*\'(draft|published)\'/i', $sql, $m)) {
                    foreach ($this->rows as $row) {
                        if ($row['consent_type'] === $m[1] && $row['status'] === $m[2]) {
                            return $row;
                        }
                    }
                }
                return null;
            }
        };
    }

    public function test_load_template_returns_db_body_when_published_exists(): void
    {
        $custom_body = '<p>CUSTOM ADMIN-EDITED VERSION 1.5</p>';
        $GLOBALS['wpdb']->rows[1] = array(
            'id'           => 1,
            'consent_type' => 'pd_consent',
            'version'      => '2.0.0',
            'body_html'    => $custom_body,
            'body_hash'    => hash('sha256', $custom_body),
            'status'       => 'published',
        );

        $loaded = Cashback_Legal_Documents::load_template('pd_consent');
        $this->assertSame($custom_body, $loaded);
    }

    public function test_load_template_falls_back_to_php_when_no_published(): void
    {
        // Никаких rows в wpdb — должен вернуть PHP-baseline.
        $loaded = Cashback_Legal_Documents::load_template('pd_consent');
        $this->assertNotEmpty($loaded);
        // PHP-шаблон содержит как минимум placeholder operator_full_name
        // или текстовые маркеры — проверяем что это «настоящий» документ.
        $this->assertGreaterThan(200, strlen($loaded), 'PHP fallback должен вернуть полноценный шаблон.');
    }

    public function test_load_template_ignores_draft_only(): void
    {
        // Только draft, нет published → должен fallback на PHP.
        $GLOBALS['wpdb']->rows[1] = array(
            'id'           => 1,
            'consent_type' => 'pd_consent',
            'version'      => '0.0.0-draft',
            'body_html'    => '<p>WIP DRAFT — not for production</p>',
            'status'       => 'draft',
        );

        $loaded = Cashback_Legal_Documents::load_template('pd_consent');
        $this->assertStringNotContainsString('WIP DRAFT', $loaded, 'Draft не должен подменять публичный текст.');
        $this->assertGreaterThan(200, strlen($loaded), 'Должен вернуться PHP-baseline.');
    }

    public function test_load_template_returns_empty_for_unknown_type(): void
    {
        $loaded = Cashback_Legal_Documents::load_template('made_up_type');
        $this->assertSame('', $loaded);
    }
}
