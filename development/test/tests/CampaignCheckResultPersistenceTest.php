<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Покрытие helper'а `Cashback_Admin_API_Validation::persist_campaign_check_results()`
 * + structural тесты на интеграцию с `ajax_check_campaigns_now` и UI render.
 *
 * Контекст: до фикса кнопка «Проверить сейчас» обновляла только
 * `cashback_campaign_status_{slug}` (snapshot per-network для нижней таблицы),
 * но не общий blob `cashback_last_sync_result['campaign_check']`, который
 * рисует верхняя «Последняя проверка» плашка. Из-за этого после успешного
 * ручного запуска admin видел старое сообщение об ошибке.
 *
 * Helper merge'ит per-slug результат с сохранением чужих сетей и пишет
 * отдельный `campaign_check_timestamp` (чтобы не путать с общим timestamp
 * полной cron-синхронизации).
 *
 * @group admin
 * @group campaign-check
 */
#[Group('admin')]
#[Group('campaign-check')]
final class CampaignCheckResultPersistenceTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        if (!class_exists('Cashback_Time')) {
            require_once self::$plugin_root . '/includes/class-cashback-time.php';
        }
        if (!class_exists('Cashback_Admin_API_Validation')) {
            // Подгружаем минимальный набор зависимостей admin-класса.
            require_once self::$plugin_root . '/admin/class-cashback-admin-api-validation.php';
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_options'] = array();
    }

    private function persist( array $results, ?string $only_slug = null ): void
    {
        Cashback_Admin_API_Validation::persist_campaign_check_results($results, $only_slug);
    }

    private function get_blob(): array
    {
        $blob = $GLOBALS['_cb_test_options']['cashback_last_sync_result'] ?? array();
        $this->assertIsArray($blob);
        return $blob;
    }

    // ----------------------------------------------------------------
    // Single-slug merge
    // ----------------------------------------------------------------

    public function test_per_slug_writes_into_empty_blob(): void
    {
        $this->persist(array(
            'advcake' => array( 'success' => true, 'total_campaigns' => 4 ),
        ), 'advcake');

        $blob = $this->get_blob();
        $this->assertArrayHasKey('campaign_check', $blob);
        $this->assertSame(
            array( 'success' => true, 'total_campaigns' => 4 ),
            $blob['campaign_check']['advcake']
        );
    }

    public function test_per_slug_overwrites_previous_data_for_same_slug(): void
    {
        $GLOBALS['_cb_test_options']['cashback_last_sync_result'] = array(
            'campaign_check' => array(
                'advcake' => array( 'success' => false, 'error' => 'API вернул 0 кампаний' ),
            ),
        );

        $this->persist(array(
            'advcake' => array( 'success' => true, 'total_campaigns' => 4 ),
        ), 'advcake');

        $blob = $this->get_blob();
        $this->assertTrue($blob['campaign_check']['advcake']['success']);
        $this->assertSame(4, $blob['campaign_check']['advcake']['total_campaigns']);
        $this->assertArrayNotHasKey('error', $blob['campaign_check']['advcake']);
    }

    public function test_per_slug_preserves_other_networks(): void
    {
        $GLOBALS['_cb_test_options']['cashback_last_sync_result'] = array(
            'campaign_check' => array(
                'adm'     => array( 'success' => true, 'total_campaigns' => 50 ),
                'advcake' => array( 'success' => false, 'error' => 'stale' ),
            ),
        );

        $this->persist(array(
            'advcake' => array( 'success' => true, 'total_campaigns' => 4 ),
        ), 'advcake');

        $blob = $this->get_blob();
        $this->assertSame(50, $blob['campaign_check']['adm']['total_campaigns']);
        $this->assertSame(4, $blob['campaign_check']['advcake']['total_campaigns']);
    }

    public function test_per_slug_removes_stale_entry_when_result_missing(): void
    {
        // Сеть отключена в БД (is_active=0) → check_campaign_statuses
        // не вернёт её в $results. Helper должен убрать stale запись.
        $GLOBALS['_cb_test_options']['cashback_last_sync_result'] = array(
            'campaign_check' => array(
                'tes' => array( 'success' => false, 'error' => 'No adapter found for: tes' ),
            ),
        );

        $this->persist(array(), 'tes');

        $blob = $this->get_blob();
        $this->assertArrayNotHasKey('tes', $blob['campaign_check'] ?? array());
    }

    // ----------------------------------------------------------------
    // Full sync (only_slug = null)
    // ----------------------------------------------------------------

    public function test_full_replace_overwrites_entire_campaign_check(): void
    {
        $GLOBALS['_cb_test_options']['cashback_last_sync_result'] = array(
            'campaign_check' => array(
                'old' => array( 'success' => false, 'error' => 'stale' ),
            ),
        );

        $this->persist(array(
            'adm'     => array( 'success' => true, 'total_campaigns' => 50 ),
            'advcake' => array( 'success' => true, 'total_campaigns' => 4 ),
        ), null);

        $blob = $this->get_blob();
        $this->assertArrayNotHasKey('old', $blob['campaign_check']);
        $this->assertSame(50, $blob['campaign_check']['adm']['total_campaigns']);
        $this->assertSame(4, $blob['campaign_check']['advcake']['total_campaigns']);
    }

    // ----------------------------------------------------------------
    // Timestamp
    // ----------------------------------------------------------------

    public function test_writes_separate_campaign_check_timestamp(): void
    {
        $this->persist(array(
            'advcake' => array( 'success' => true, 'total_campaigns' => 4 ),
        ), 'advcake');

        $blob = $this->get_blob();
        $this->assertArrayHasKey('campaign_check_timestamp', $blob);
        $this->assertNotEmpty($blob['campaign_check_timestamp']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $blob['campaign_check_timestamp']
        );
    }

    public function test_does_not_touch_top_level_general_timestamp(): void
    {
        // Общий `timestamp` принадлежит cron'у полной API-синхронизации.
        // Ручная проверка кампаний не должна его перезаписывать, иначе админ
        // потеряет ориентир «когда последний раз отработал полный sync».
        $GLOBALS['_cb_test_options']['cashback_last_sync_result'] = array(
            'timestamp'      => '2026-05-14 16:52:01',
            'campaign_check' => array(),
        );

        $this->persist(array(
            'advcake' => array( 'success' => true, 'total_campaigns' => 4 ),
        ), 'advcake');

        $blob = $this->get_blob();
        $this->assertSame('2026-05-14 16:52:01', $blob['timestamp']);
    }

    public function test_initializes_empty_blob_when_option_missing(): void
    {
        // Никаких опций до вызова — helper должен сам создать структуру.
        $this->persist(array(
            'advcake' => array( 'success' => true, 'total_campaigns' => 4 ),
        ), 'advcake');

        $blob = $this->get_blob();
        $this->assertIsArray($blob['campaign_check']);
        $this->assertArrayHasKey('advcake', $blob['campaign_check']);
    }

    public function test_normalizes_malformed_existing_blob(): void
    {
        // Defensive: legacy data может оказаться скаляром или not-array.
        $GLOBALS['_cb_test_options']['cashback_last_sync_result'] = 'corrupted';

        $this->persist(array(
            'advcake' => array( 'success' => true, 'total_campaigns' => 4 ),
        ), 'advcake');

        $blob = $this->get_blob();
        $this->assertIsArray($blob);
        $this->assertSame(4, $blob['campaign_check']['advcake']['total_campaigns']);
    }

    // ----------------------------------------------------------------
    // Structural тесты на интеграцию (исходник admin-класса)
    // ----------------------------------------------------------------

    public function test_ajax_check_campaigns_now_calls_persist_helper(): void
    {
        $source = file_get_contents(self::$plugin_root . '/admin/class-cashback-admin-api-validation.php');
        $this->assertNotFalse($source);

        // Извлекаем тело метода ajax_check_campaigns_now через brace-balance.
        $body = $this->extract_method_body($source, 'ajax_check_campaigns_now');
        $this->assertNotSame('', $body, 'Не нашли тело ajax_check_campaigns_now');

        $this->assertMatchesRegularExpression(
            '/persist_campaign_check_results\s*\(/',
            $body,
            'ajax_check_campaigns_now должен вызывать persist_campaign_check_results после успешного check_campaign_statuses'
        );
    }

    public function test_top_panel_render_uses_campaign_check_timestamp_when_available(): void
    {
        $source = file_get_contents(self::$plugin_root . '/admin/class-cashback-admin-api-validation.php');
        $this->assertNotFalse($source);

        $this->assertMatchesRegularExpression(
            '/campaign_check_timestamp/',
            $source,
            'Render-блок «Последняя проверка» должен учитывать campaign_check_timestamp'
        );
    }

    public function test_top_panel_render_filters_unknown_slugs(): void
    {
        $source = file_get_contents(self::$plugin_root . '/admin/class-cashback-admin-api-validation.php');
        $this->assertNotFalse($source);

        // Render-блок должен сверять slug из blob с известными slug'ами активных
        // сетей (либо адаптерами), чтобы stale-записи (например «tes — No adapter
        // found») не отображались админу как ошибки.
        $this->assertMatchesRegularExpression(
            '/(known_slugs|active_slugs|filter_campaign_check_blob|in_array\s*\(\s*\$net\s*,\s*\$\w+_slugs)/',
            $source,
            'Render блока должен фильтровать blob по списку известных slug-сетей'
        );
    }

    /**
     * Извлекает тело named method'а через brace-balance.
     */
    private function extract_method_body( string $source, string $method ): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)\s*:\s*\w+\s*\{/';
        if (!preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $open       = (int) $m[0][1] + strlen($m[0][0]);
        $depth      = 1;
        $i          = $open;
        $length     = strlen($source);
        $in_string  = false;
        $string_ch  = '';
        $in_comment = false;

        while ($i < $length && $depth > 0) {
            $ch = $source[ $i ];

            // Очень простой stripper комментариев/строк, чтобы не считать `{` внутри.
            if ($in_comment) {
                if ($ch === '*' && $i + 1 < $length && $source[ $i + 1 ] === '/') {
                    $in_comment = false;
                    $i += 2;
                    continue;
                }
            } elseif ($in_string) {
                if ($ch === '\\') {
                    $i += 2;
                    continue;
                }
                if ($ch === $string_ch) {
                    $in_string = false;
                }
            } else {
                if ($ch === '/' && $i + 1 < $length) {
                    if ($source[ $i + 1 ] === '*') {
                        $in_comment = true;
                        $i         += 2;
                        continue;
                    }
                    if ($source[ $i + 1 ] === '/') {
                        $nl = strpos($source, "\n", $i);
                        $i  = $nl === false ? $length : $nl;
                        continue;
                    }
                } elseif ($ch === '"' || $ch === "'") {
                    $in_string = true;
                    $string_ch = $ch;
                } elseif ($ch === '{') {
                    ++$depth;
                } elseif ($ch === '}') {
                    --$depth;
                }
            }

            ++$i;
        }

        return substr($source, $open, $i - $open - 1);
    }
}
