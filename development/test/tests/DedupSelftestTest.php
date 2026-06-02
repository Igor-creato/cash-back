<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Self-test дедупликации — поведенческая read-only проверка крон-маппинга.
 *
 * Ловит невнимательность админа: webhook-ресивер настроен верно, а в кроне
 * (api_field_map) на uniq_id повешено НЕ то API-поле. Config-валидация это
 * поймать не может (поля постбэка и API в разных пространствах имён, D-5b),
 * поэтому проверка ПОВЕДЕНЧЕСКАЯ: на свежей строке сверяем, что крон-резолвер
 * даёт тот же uniq_id, что уже сохранён.
 *
 * Контракт:
 *  - dedup_selftest() / ajax_dedup_selftest() СТРОГО read-only (структурный
 *    запрет write-конструкций и вызовов мутирующих методов);
 *  - вердикты MATCH / MISMATCH / INCONCLUSIVE на dataProvider;
 *  - неоднозначный split-order матч → INCONCLUSIVE, НИКОГДА не MISMATCH;
 *  - регистрация ajax + nonce + caps + __all__ reject + asset bump.
 */
#[Group('dedup')]
#[Group('readonly')]
final class DedupSelftestTest extends TestCase
{
    private static string $api_src;
    private static string $admin_src;
    private static string $js_src;

    public static function setUpBeforeClass(): void
    {
        $root            = dirname(__DIR__, 3);
        self::$api_src   = (string) file_get_contents($root . '/includes/class-cashback-api-client.php');
        self::$admin_src = (string) file_get_contents($root . '/admin/class-cashback-admin-api-validation.php');
        self::$js_src    = (string) file_get_contents($root . '/admin/js/api-validation.js');
        require_once $root . '/includes/class-cashback-api-client.php';
    }

    /** Brace-balanced extraction of a method body (not a magic-size window). */
    private function method_body(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        $this->assertNotFalse($start, "signature not found: {$signature}");
        $brace = strpos($src, '{', $start);
        $this->assertNotFalse($brace);
        $depth = 0;
        $len   = strlen($src);
        for ($i = $brace; $i < $len; $i++) {
            $ch = $src[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }
        $this->fail("unbalanced braces for {$signature}");
    }

    // ---- structural: read-only guarantee (the critical assurance) ----

    public function test_dedup_selftest_body_is_strictly_read_only(): void
    {
        $body = $this->method_body(self::$api_src, 'public function dedup_selftest(');

        foreach (array(
            '/\bINSERT\s+INTO\b/i',
            '/\bUPDATE\s+%i\b/i',
            '/\bUPDATE\s+`/i',
            '/\bDELETE\s+FROM\b/i',
            '/\bREPLACE\s+INTO\b/i',
            '/->insert\(/',
            '/->update\(/',
            '/->delete\(/',
            '/->replace\(/',
            '/sync_update_local\(/',
            '/update_checkpoint\(/',
            '/insert_missing_transaction\(/',
            '/->validate_user\(/',
            '/log_audit\(/',
        ) as $forbidden) {
            $this->assertDoesNotMatchRegularExpression(
                $forbidden,
                $body,
                "dedup_selftest must be read-only — forbidden construct {$forbidden}"
            );
        }

        // Должен читать только через SELECT-обёртки.
        $this->assertStringContainsString('$wpdb->get_results(', $body);
        $this->assertStringContainsString('SELECT', $body);
        $this->assertStringContainsString('self::resolve_uniq_id(', $body);
    }

    public function test_ajax_handler_is_read_only_and_guarded(): void
    {
        $body = $this->method_body(self::$admin_src, 'public function ajax_dedup_selftest(): void');

        $this->assertStringContainsString("check_ajax_referer('cashback_api_validation', 'nonce')", $body);
        $this->assertStringContainsString("current_user_can('manage_options')", $body);
        $this->assertStringContainsString("'__all__'", $body, 'must reject bulk __all__ in v1');
        $this->assertStringContainsString('->dedup_selftest(', $body);

        // B-1: STRICTLY read-only — even the ajax wrapper must not write
        // (no rate-limit transient / option write).
        foreach (array(
            '/->insert\(/', '/->update\(/', '/->delete\(/',
            '/\bUPDATE\s+/i', '/\bINSERT\s+/i',
            '/set_transient\(/', '/update_option\(/', '/add_option\(/', '/delete_option\(/',
        ) as $forbidden) {
            $this->assertDoesNotMatchRegularExpression($forbidden, $body, "ajax handler must not write: {$forbidden}");
        }
    }

    public function test_ajax_registered_and_assets_bumped(): void
    {
        $this->assertStringContainsString(
            "add_action('wp_ajax_cashback_dedup_selftest', array( \$this, 'ajax_dedup_selftest' ));",
            self::$admin_src
        );
        // Версия ассета поднята (новый JS-обработчик подцепится).
        $this->assertStringNotContainsString("'5.2.0'", self::$admin_src, 'asset ver must be bumped from 5.2.0');
        $this->assertSame(2, substr_count(self::$admin_src, "'5.4.1'"), 'js + css ver = 5.4.1');
        $this->assertStringContainsString('cashback-dedup-selftest-btn', self::$admin_src);
        // JS: обработчик + новый action.
        $this->assertStringContainsString("action: 'cashback_dedup_selftest'", self::$js_src);
        $this->assertStringContainsString('renderDedupSelftestResult', self::$js_src);
    }

    // ---- behavioural: verdict logic ----

    /**
     * Тест-двойник: подменяем сетевой конфиг и API-ответ (публичные методы),
     * приватные таблицы выставляем рефлексией. Резолвер — настоящий.
     */
    private function make_client(?array $cfg, array $api): Cashback_API_Client
    {
        if (!class_exists('Mariadb_Plugin')) {
            eval('class Mariadb_Plugin { public static function get_partner_token($id){ return null; } }');
        }
        $client = new class($cfg, $api) extends Cashback_API_Client {
            /** @param array<string,mixed>|null $c */
            public function __construct(public ?array $c, public array $a) {}
            public function get_network_config(string $slug): ?array
            {
                return $this->c;
            }
            public function fetch_all_actions_for_network(string $slug, array $cr, array $p, int $m = 20, array $n = array()): array
            {
                return $this->a;
            }
        };
        foreach (array( 'transactions_table' => 'wp_cashback_transactions', 'unregistered_table' => 'wp_cashback_unregistered_transactions', 'networks_table' => 'wp_cashback_affiliate_networks' ) as $prop => $val) {
            $rp = new ReflectionProperty('Cashback_API_Client', $prop);
            $rp->setAccessible(true);
            $rp->setValue($client, $val);
        }
        return $client;
    }

    /** Mock $wpdb. $dups → строки primary-сканера дублей (HAVING d>1). */
    private function set_wpdb(array $tx_rows, array $unreg_rows = array(), array $dups = array()): void
    {
        $GLOBALS['wpdb'] = new class($tx_rows, $unreg_rows, $dups) {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public function __construct(public array $tx, public array $un, public array $dups) {}
            public function prepare(string $q, mixed ...$a): string
            {
                return $q . '|' . implode(',', array_map('strval', $a));
            }
            public function get_results(string $q, mixed $o = null): array
            {
                // Primary duplicate-identity сканер (отдельный запрос).
                if (strpos($q, 'COUNT(DISTINCT uniq_id)') !== false) {
                    return $this->dups;
                }
                // %i подставляется первым аргументом таблицы.
                return strpos($q, 'wp_cashback_unregistered_transactions') !== false ? $this->un : $this->tx;
            }
            // Mariadb_Plugin::get_partner_token() читает get_var — отдаём null,
            // dedup_selftest деградирует к (string)user_id (registered-путь).
            public function get_var(string $q, int $x = 0, int $y = 0): mixed
            {
                return null;
            }
        };
    }

    private function base_cfg(string $uniq_api_field): array
    {
        // field_map: API-поле → локальная колонка. Крон uniq_id берёт из
        // api_field_for('uniq_id') = ключ, чьё значение 'uniq_id'. Базовый
        // маппинг не должен коллизировать ключом с $uniq_api_field.
        $fm = array(
            'advcampaign_id' => 'offer_id',
            'payment'        => 'comission',
        );
        // order_number мапим из 'order_id' (если только это не сам uniq-источник).
        $fm[ $uniq_api_field === 'order_id' ? 'order_no_alt' : 'order_id' ] = 'order_number';
        $fm[ $uniq_api_field ] = 'uniq_id';

        return array(
            'slug'            => 'testnet',
            'name'            => 'TestNet',
            'credentials'     => array( 'api_key' => 'x' ),
            'api_click_field' => 'subid1',
            'api_user_field'  => 'subid2',
            'field_map'       => $fm,
            'dedup_identity'  => null,
        );
    }

    private function sample_row(array $over = array()): array
    {
        return array_merge(array(
            'id'                 => 1,
            'user_id'            => '5',
            'partner'            => 'testnet',
            'uniq_id'            => 'ACT-100',
            'idempotency_key'    => hash('sha256', 'testnet|ACT-100'),
            'click_id'           => 'c1',
            'order_number'       => 'ORD-9',
            'offer_id'           => 10,
            'action_type'        => 'sale',
            'action_date'        => gmdate('Y-m-d H:i:s'),
            'created_at'         => gmdate('Y-m-d H:i:s'),
            'original_cpa_subid' => '',
            'created_by_admin'   => 0,
            'comission'          => 12.34,
            'sum_order'          => 100.0,
        ), $over);
    }

    public function test_network_unavailable_when_no_config(): void
    {
        $c = $this->make_client(null, array());
        $r = $c->dedup_selftest('testnet');
        $this->assertSame('inconclusive', $r['verdict']);
        $this->assertSame('network_unavailable', $r['reason']);
    }

    public function test_no_webhook_sample(): void
    {
        $this->set_wpdb(array(), array());
        $c = $this->make_client($this->base_cfg('action_id'), array( 'success' => true, 'actions' => array() ));
        $r = $c->dedup_selftest('testnet');
        $this->assertSame('inconclusive', $r['verdict']);
        $this->assertSame('no_webhook_sample', $r['reason']);
    }

    public function test_api_unavailable_is_inconclusive_not_mismatch(): void
    {
        $this->set_wpdb(array( $this->sample_row() ));
        $c = $this->make_client($this->base_cfg('action_id'), array( 'success' => false, 'error' => 'timeout' ));
        $r = $c->dedup_selftest('testnet');
        $this->assertSame('inconclusive', $r['verdict']);
        $this->assertSame('api_unavailable', $r['reason']);
    }

    public function test_broad_sample_catches_mismatch_among_match_rows(): void
    {
        // B-2 (sound resolution): даже если первая свежая строка — cron-
        // origin (recompute == stored == «совпало»), webhook-origin строка
        // в той же ШИРОКОЙ выборке даст MISMATCH, и агрегатор «любой
        // MISMATCH доминирует» вернёт mismatch. Здесь cron-маппинг
        // перепутан (uniq←order поле) — обе строки одного юзера, разные
        // конверсии; матчинг по составному ключу.
        $row_ok = $this->sample_row(array( 'id' => 1, 'uniq_id' => 'ORD-A', 'idempotency_key' => hash('sha256', 'testnet|ORD-A'), 'order_number' => 'ORD-A', 'user_id' => '5', 'click_id' => 'c1' ));
        $row_wh = $this->sample_row(array( 'id' => 2, 'uniq_id' => 'ACT-200', 'idempotency_key' => hash('sha256', 'testnet|ACT-200'), 'order_number' => 'ORD-B', 'user_id' => '5', 'click_id' => 'c2' ));
        $this->set_wpdb(array( $row_ok, $row_wh ));
        $api = array( 'success' => true, 'actions' => array(
            array( 'action_id' => 'ACT-100', 'order_id' => 'ORD-A', 'order_no_alt' => 'ORD-A', 'advcampaign_id' => 10, 'action_type' => 'sale', 'subid1' => 'c1', 'payment' => 12.34 ),
            array( 'action_id' => 'ACT-200', 'order_id' => 'ORD-B', 'order_no_alt' => 'ORD-B', 'advcampaign_id' => 10, 'action_type' => 'sale', 'subid1' => 'c2', 'payment' => 12.34 ),
        ) );
        // base_cfg('order_id'): крон uniq_id ← поле order_id (перепутано).
        // row_ok хранит 'ORD-A' (== order) → recompute 'ORD-A' == stored → match.
        // row_wh хранит 'ACT-200' (правильный native) → recompute 'ORD-B' ≠ → MISMATCH.
        $c = $this->make_client($this->base_cfg('order_id'), $api);
        $r = $c->dedup_selftest('testnet');
        $this->assertSame('mismatch', $r['verdict'], json_encode($r));
        $this->assertSame('uniq_mismatch', $r['detail']['sub']);
    }

    public function test_probe_budget_exhausted_suppresses_match(): void
    {
        // Codex iter-3 B-2: >8 групп пользователей → часть не опрошена →
        // MATCH недостоверен → inconclusive/probe_budget_exhausted (а не
        // ложный «всё ок», пока buggy строка в непроверенной группе).
        $rows = array();
        for ($i = 1; $i <= 9; $i++) {
            $rows[] = $this->sample_row(array( 'id' => $i, 'user_id' => (string) $i ));
        }
        $this->set_wpdb($rows);
        $api = array( 'success' => true, 'actions' => array(
            array( 'action_id' => 'ACT-100', 'order_id' => 'ORD-9', 'advcampaign_id' => 10, 'action_type' => 'sale', 'subid1' => 'c1', 'payment' => 12.34 ),
        ) );
        $c = $this->make_client($this->base_cfg('action_id'), $api);
        $r = $c->dedup_selftest('testnet');
        $this->assertSame('inconclusive', $r['verdict'], json_encode($r));
        $this->assertSame('probe_budget_exhausted', $r['reason']);
    }

    public function test_match_when_cron_map_correct(): void
    {
        $this->set_wpdb(array( $this->sample_row(array( 'uniq_id' => 'ACT-100' )) ));
        // Крон правильно: uniq_id ← API-поле action_id; API отдаёт action_id=ACT-100.
        $api = array( 'success' => true, 'actions' => array(
            array( 'action_id' => 'ACT-100', 'order_id' => 'ORD-9', 'advcampaign_id' => 10, 'action_type' => 'sale', 'subid1' => 'c1', 'payment' => 12.34 ),
        ) );
        $c = $this->make_client($this->base_cfg('action_id'), $api);
        $r = $c->dedup_selftest('testnet');
        $this->assertSame('match', $r['verdict'], json_encode($r));
        $this->assertSame(1, $r['checked']);
    }

    public function test_mismatch_when_cron_uniq_points_at_order_field(): void
    {
        $this->set_wpdb(array( $this->sample_row(array( 'uniq_id' => 'ACT-100', 'order_number' => 'ORD-9' )) ));
        // Перепутано: крон uniq_id ← поле "order_id" (номер заказа). API той
        // же конверсии: настоящий action_id=ACT-100, но order_id=ORD-9.
        // order_number матчится из 'order_no_alt'. Резолвер возьмёт ORD-9
        // (перепутанное поле) → ≠ сохранённого ACT-100.
        $api = array( 'success' => true, 'actions' => array(
            array( 'action_id' => 'ACT-100', 'order_id' => 'ORD-9', 'order_no_alt' => 'ORD-9', 'advcampaign_id' => 10, 'action_type' => 'sale', 'subid1' => 'c1', 'payment' => 12.34 ),
        ) );
        $c = $this->make_client($this->base_cfg('order_id'), $api);
        $r = $c->dedup_selftest('testnet');
        $this->assertSame('mismatch', $r['verdict'], json_encode($r));
        $this->assertSame('uniq_mismatch', $r['detail']['sub']);
        $this->assertSame('order_id', $r['detail']['api_field_cron_reads']);
        $this->assertSame('ACT-100', $r['detail']['stored_uniq']);
        $this->assertSame('ORD-9', $r['detail']['computed_uniq']);
    }

    public function test_api_action_not_found_is_inconclusive(): void
    {
        $this->set_wpdb(array( $this->sample_row(array( 'order_number' => 'ORD-9' )) ));
        // API вернул другую конверсию (другой order/offer) — нашей нет.
        $api = array( 'success' => true, 'actions' => array(
            array( 'action_id' => 'X', 'order_id' => 'OTHER', 'advcampaign_id' => 77, 'action_type' => 'lead', 'subid1' => 'zz', 'payment' => 1 ),
        ) );
        $c = $this->make_client($this->base_cfg('action_id'), $api);
        $r = $c->dedup_selftest('testnet');
        $this->assertSame('inconclusive', $r['verdict']);
        $this->assertSame('api_action_not_found', $r['reason']);
    }

    public function test_ambiguous_split_order_is_inconclusive_never_mismatch(): void
    {
        $this->set_wpdb(array( $this->sample_row(array(
            'uniq_id' => 'ACT-100', 'order_number' => 'ORD-9', 'offer_id' => 10,
            'action_type' => 'sale', 'click_id' => 'c1', 'comission' => 5.00,
        )) ));
        // Два split-order действия НЕРАЗЛИЧИМЫ по order+offer+type+click+amount+date,
        // но с разными action_id → грубый фильтр даёт 2, доуточнение не разводит.
        $day = gmdate('Y-m-d H:i:s');
        $api = array( 'success' => true, 'actions' => array(
            array( 'action_id' => 'A1', 'order_id' => 'ORD-9', 'advcampaign_id' => 10, 'action_type' => 'sale', 'subid1' => 'c1', 'payment' => 5.00, 'action_date' => $day ),
            array( 'action_id' => 'A2', 'order_id' => 'ORD-9', 'advcampaign_id' => 10, 'action_type' => 'sale', 'subid1' => 'c1', 'payment' => 5.00, 'action_date' => $day ),
        ) );
        $c = $this->make_client($this->base_cfg('action_id'), $api);
        $r = $c->dedup_selftest('testnet');
        $this->assertSame('inconclusive', $r['verdict'], json_encode($r));
        $this->assertSame('ambiguous_match', $r['reason']);
    }

    public function test_split_order_refined_by_amount_then_matches(): void
    {
        $this->set_wpdb(array( $this->sample_row(array(
            'uniq_id' => 'ACT-100', 'order_number' => 'ORD-9', 'offer_id' => 10,
            'action_type' => 'sale', 'click_id' => 'c1', 'comission' => 7.77,
        )) ));
        $day = gmdate('Y-m-d H:i:s');
        // 2 кандидата по грубому фильтру, но разные суммы → доуточнение по
        // amount оставляет ровно 1 (ACT-100, 7.77).
        $api = array( 'success' => true, 'actions' => array(
            array( 'action_id' => 'ACT-100', 'order_id' => 'ORD-9', 'advcampaign_id' => 10, 'action_type' => 'sale', 'subid1' => 'c1', 'payment' => 7.77, 'action_date' => $day ),
            array( 'action_id' => 'ACT-200', 'order_id' => 'ORD-9', 'advcampaign_id' => 10, 'action_type' => 'sale', 'subid1' => 'c1', 'payment' => 3.33, 'action_date' => $day ),
        ) );
        $c = $this->make_client($this->base_cfg('action_id'), $api);
        $r = $c->dedup_selftest('testnet');
        $this->assertSame('match', $r['verdict'], json_encode($r));
    }
}
