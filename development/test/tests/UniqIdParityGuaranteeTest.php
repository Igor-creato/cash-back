<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * uniq_id-ПАРИТЕТ — регресс-страж «тихого незачисления».
 *
 * Контекст: коммит ef32586 (2026-05-15) убрал click_id-fallback в
 * reconciliation — матчинг API-экшена к локальной строке теперь ТОЛЬКО
 * по uniq_id. Match-ключ = `$action[ api_field_for('uniq_id', map) ]`,
 * где api_field_for = array_flip($map)['uniq_id'] (class-cashback-
 * api-client.php:466-469). Если seed-карта сети начнёт мапить uniq_id
 * из поля, которого API/адаптер не отдаёт (как было у Advcake до
 * 8266c33: map "id"→uniq_id, а XML без <id>), reconciliation МОЛЧА
 * перестаёт матчить → order_status не доходит до 'completed',
 * funds_ready не ставится → cashback не зачисляется, без единого
 * алерта. Этот тест ловит такой дрейф в CI, а не в проде.
 *
 * Покрытие:
 *  A. Контракт Cashback_API_Client::resolve_uniq_id() (native
 *     passthrough / no_dedup_inputs / legacy-null / trim).
 *  B. Инвариант seed api_field_map для встроенных сетей: какое API-поле
 *     резолвится в uniq_id (admitad/epn → action_id; advcake → id).
 *  C. Документированный контракт receiver_uniq_source (v16 seed):
 *     webhook сохраняет uniq_id из этого поля постбэка — оно обязано
 *     быть согласовано с reconciliation-полем.
 *
 * Методика: behavioral (require класса + вызов public static) +
 * source-parse плоских map-литералов mariadb.php (без запуска
 * тяжёлых миграций) — house-style зеркало DedupSelftestTest /
 * DedupSourceConsistencyV18Test.
 *
 * @group dedup
 * @group readonly
 */
#[Group('dedup')]
#[Group('readonly')]
final class UniqIdParityGuaranteeTest extends TestCase
{
    private static string $mariadb_src;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);
        self::$mariadb_src = (string) file_get_contents($root . '/mariadb.php');
        require_once $root . '/includes/class-cashback-api-client.php';
    }

    // ------------------------------------------------------------------
    // A. resolve_uniq_id() — контракт резолвера идентичности.
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $dedup_identity
     * @dataProvider provide_resolve_uniq_id_cases
     */
    #[DataProvider('provide_resolve_uniq_id_cases')]
    public function test_resolve_uniq_id_contract(
        string $slug,
        string $native,
        ?array $dedup_identity,
        string $expected_id,
        ?string $expected_reason
    ): void {
        [$id, $reason] = Cashback_API_Client::resolve_uniq_id(
            $slug,
            $native,
            array(
                'order_number' => 'ORD-1',
                'offer_id'     => 'OF-1',
                'action_type'  => 'sale',
                'click_id'     => 'CL-1',
            ),
            $dedup_identity
        );

        if ($expected_id === '__SYNTHETIC__') {
            $this->assertStringStartsWith('syn_', $id, 'synthetic id expected');
            $this->assertNull($reason);
            return;
        }

        $this->assertSame($expected_id, $id, "uniq_id для slug={$slug} native='{$native}'");
        $this->assertSame($expected_reason, $reason, 'reason mismatch');
    }

    /** @return array<string,array{0:string,1:string,2:?array<string,mixed>,3:string,4:?string}> */
    public static function provide_resolve_uniq_id_cases(): array
    {
        $native = array( 'has_native_action_id' => true );
        $synth  = array(
            'has_native_action_id'       => false,
            'synthetic_fields'           => array( 'order_number', 'offer_id', 'action_type' ),
            'synthetic_include_click_id' => false,
        );

        return array(
            // Native есть → verbatim passthrough (встроенные сети).
            'admitad action_id passthrough'      => array( 'admitad', '1271805605', $native, '1271805605', null ),
            'advcake id(=order_id) passthrough'  => array( 'advcake', 'ORD-9', $native, 'ORD-9', null ),
            'native trims surrounding ws'        => array( 'admitad', '  42  ', $native, '42', null ),
            // legacy null-контракт == has_native:true (поведение до v16).
            'legacy null contract = native'      => array( 'admitad', 'A1', null, 'A1', null ),
            // Native ожидался, но пуст → DLQ, НЕ вставляем (caller skip).
            'empty native + has_native → DLQ'    => array( 'admitad', '', $native, '', 'no_dedup_inputs' ),
            'whitespace native + has_native DLQ' => array( 'advcake', '   ', $native, '', 'no_dedup_inputs' ),
            'empty native + legacy null → DLQ'   => array( 'epn', '', null, '', 'no_dedup_inputs' ),
            // Direct-партнёр без native id → синтетический syn_…
            'synthetic when no native id'        => array( 'directpartner', '', $synth, '__SYNTHETIC__', null ),
        );
    }

    // ------------------------------------------------------------------
    // B. seed api_field_map → какое API-поле резолвится в uniq_id.
    //    Воспроизводим runtime api_field_for() = array_flip()['uniq_id']
    //    (class-cashback-api-client.php:466-469) на seed-литерале.
    // ------------------------------------------------------------------

    /**
     * @dataProvider provide_seed_uniq_field_expectations
     */
    #[DataProvider('provide_seed_uniq_field_expectations')]
    public function test_seed_api_field_map_resolves_uniq_id_to_expected_api_field(
        string $network_label,
        string $anchor,
        string $expected_api_field
    ): void {
        $map = $this->parse_flat_map_after($anchor);

        $this->assertNotEmpty($map, "seed api_field_map не найдена/не распарсилась: {$network_label}");

        $flipped = array_flip($map);
        $this->assertArrayHasKey(
            'uniq_id',
            $flipped,
            "{$network_label}: seed api_field_map НЕ мапит ни одно API-поле в uniq_id "
                . '→ reconciliation никогда не сматчит → тихое незачисление (ef32586-класс).'
        );
        $this->assertSame(
            $expected_api_field,
            $flipped['uniq_id'],
            "{$network_label}: api_field_for('uniq_id') должно быть '{$expected_api_field}' "
                . "(поле, которое адаптер/API реально отдаёт), а seed даёт '{$flipped['uniq_id']}'."
        );
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function provide_seed_uniq_field_expectations(): array
    {
        // Admitad/EPN: insert_default_api_config() — Admitad API
        // /statistics/actions/ отдаёт `action_id` (developers.admitad.com).
        // Advcake: migrate_advcake_seed_v14() — XML без <id>, адаптер
        // всегда эмитит `id` (8266c33, см. AdvcakeAdapterTest).
        return array(
            'Admitad seed' => array(
                'Admitad',
                "array( 'slug' => 'admitad' )",
                'action_id',
            ),
            'EPN seed' => array(
                'EPN',
                "array( 'slug' => 'epn' )",
                'action_id',
            ),
            'Advcake seed (v14)' => array(
                'Advcake',
                '$field_map_json = (string) wp_json_encode(array(',
                'id',
            ),
        );
    }

    /**
     * Найти БЛИЖАЙШИЙ перед `$anchor` блок `wp_json_encode(array( ... ))`,
     * относящийся к api_field_map, и распарсить плоские 'k' => 'v' пары.
     *
     * Карты гарантированно плоские string→string (DEFAULT_FIELD_MAP-
     * ориентация), поэтому regex по парам надёжен (нет вложенности).
     *
     * @return array<string,string>
     */
    private function parse_flat_map_after(string $anchor): array
    {
        $src        = self::$mariadb_src;
        $anchor_pos = strpos($src, $anchor);
        $this->assertNotFalse($anchor_pos, "anchor не найден в mariadb.php: {$anchor}");

        if (strpos($anchor, 'wp_json_encode') !== false) {
            // Якорь сам содержит начало encode (Advcake: $field_map_json =
            // (string) wp_json_encode(array( … )) — переменная, не литерал
            // 'api_field_map' =>). Парсим ВПЕРЁД от якоря.
            $enc = strpos($src, 'wp_json_encode(array(', $anchor_pos);
        } else {
            // Литерал 'api_field_map' => wp_json_encode(array( … )) —
            // блок этой сети идёт прямо перед update() с её slug-якорем;
            // берём последний 'api_field_map' ДО якоря.
            $scan   = substr($src, 0, $anchor_pos);
            $fm_pos = strrpos($scan, "'api_field_map'");
            $this->assertNotFalse($fm_pos, "'api_field_map' не найден перед anchor {$anchor}");
            $enc = strpos($src, 'wp_json_encode(array(', $fm_pos);
        }
        $this->assertNotFalse($enc, 'wp_json_encode(array( не найден для seed map');

        // Балансируем скобки от 'array(' .
        $open = strpos($src, '(', $enc + strlen('wp_json_encode'));
        $this->assertNotFalse($open);
        $depth = 0;
        $len   = strlen($src);
        $close = -1;
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '(') {
                $depth++;
            } elseif ($src[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    $close = $i;
                    break;
                }
            }
        }
        $this->assertGreaterThan(0, $close, 'несбалансированные скобки в seed map');
        $body = substr($src, $open + 1, $close - $open - 1);

        $pairs = array();
        if (preg_match_all("/'([^']+)'\\s*=>\\s*'([^']*)'/", $body, $m, PREG_SET_ORDER)) {
            foreach ($m as $pair) {
                $pairs[ $pair[1] ] = $pair[2];
            }
        }
        return $pairs;
    }

    // ------------------------------------------------------------------
    // C. receiver_uniq_source (v16 seed) — webhook↔reconciliation паритет.
    // ------------------------------------------------------------------

    public function test_v16_seed_receiver_uniq_source_matches_contract(): void
    {
        $src = self::$mariadb_src;

        // v16 seed: 'admitad' => array( 'receiver_uniq_source' => 'admitad_id' ), …
        $expected = array(
            'admitad' => 'admitad_id',
            'epn'     => 'transactionId',
            'advcake' => 'id',
        );

        foreach ($expected as $slug => $field) {
            $pattern = "/'" . preg_quote($slug, '/')
                . "'\\s*=>\\s*array\\(\\s*'receiver_uniq_source'\\s*=>\\s*'"
                . preg_quote($field, '/') . "'/";
            $this->assertMatchesRegularExpression(
                $pattern,
                $src,
                "v16 seed receiver_uniq_source для '{$slug}' должно быть '{$field}' "
                    . '(контракт: webhook сохраняет uniq_id из этого поля постбэка; '
                    . 'рассинхрон с reconciliation-полем = тихое незачисление).'
            );
        }
    }
}
