<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Группа 12 ADR — F-20-002: Claims scoring industry-aligned refactor.
 *
 * Цель: probability_score должен быть полезной подсказкой админу для
 * приоритизации очереди заявок. Автоматические решения (авто-одобрение /
 * авто-отклонение) НЕ используются — все transitions идут через admin
 * handler + партнёрскую верификацию.
 *
 * Изменения:
 *  1) score_time_factor() — non-linear лестница с penalty для «<5 min»
 *     (cookie-stuffing/бот red flag по TUNE / 24metrics / Chargebacks911).
 *  2) Добавлен 5-й фактор score_risk_factor() — инверсия композитного
 *     Cashback_Fraud_Detector::calculate_user_risk_score(user_id), который
 *     уже агрегирует 8 антифрод-сигналов.
 *  3) Веса перекалиброваны (сумма = 1.0): time 0.20 / merchant 0.20 /
 *     user 0.20 / consistency 0.15 / risk 0.25.
 *
 * Функционал НЕ ломается: подпись Cashback_Claims_Scoring::calculate(array)
 * сохраняется, callsite'ы в claims-manager и claims-frontend без изменений.
 */
#[Group('security')]
#[Group('group12')]
#[Group('claims')]
#[Group('scoring')]
final class ClaimsScoringTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        if (!class_exists('Cashback_Claims_Scoring')) {
            require_once $plugin_root . '/claims/class-claims-scoring.php';
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Изолируем $wpdb между тестами.
        $GLOBALS['wpdb'] = new ClaimsScoring_Wpdb_Stub();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    // =====================================================================
    // Source-grep: structural markers новой формулы.
    // =====================================================================

    private function read_class_source(): string
    {
        $plugin_root = dirname(__DIR__, 3);
        return (string) file_get_contents($plugin_root . '/claims/class-claims-scoring.php');
    }

    public function test_weights_are_recalibrated_to_industry_distribution(): void
    {
        $src = $this->read_class_source();

        // Новые веса (sum = 1.0). Строгие литералы, чтобы поймать любые случайные правки.
        self::assertMatchesRegularExpression(
            '/WEIGHT_TIME\s*=\s*0\.20\b/',
            $src,
            'F-20-002: WEIGHT_TIME должен быть 0.20 (снижен с 0.25, чтобы освободить вес для risk-фактора).'
        );
        self::assertMatchesRegularExpression(
            '/WEIGHT_MERCHANT\s*=\s*0\.20\b/',
            $src,
            'F-20-002: WEIGHT_MERCHANT должен быть 0.20 (снижен с 0.35 — per-claim сигнал слабый).'
        );
        self::assertMatchesRegularExpression(
            '/WEIGHT_USER\s*=\s*0\.20\b/',
            $src,
            'F-20-002: WEIGHT_USER должен остаться 0.20.'
        );
        self::assertMatchesRegularExpression(
            '/WEIGHT_CONSISTENCY\s*=\s*0\.15\b/',
            $src,
            'F-20-002: WEIGHT_CONSISTENCY должен быть 0.15 (снижен с 0.20).'
        );
        self::assertMatchesRegularExpression(
            '/WEIGHT_RISK\s*=\s*0\.25\b/',
            $src,
            'F-20-002: WEIGHT_RISK должен быть 0.25 (новый фактор, высший вес — сам композит 8 сигналов).'
        );
    }

    public function test_score_risk_factor_method_exists(): void
    {
        self::assertTrue(
            method_exists('Cashback_Claims_Scoring', 'score_risk_factor'),
            'F-20-002: метод score_risk_factor() должен быть добавлен в Cashback_Claims_Scoring.'
        );
    }

    public function test_breakdown_includes_risk_key(): void
    {
        // Простейший claim без click_id → скоринг посчитается без DB-хитов,
        // но breakdown должен содержать 'risk' даже когда остальные factors по defaults.
        $result = Cashback_Claims_Scoring::calculate(array(
            'user_id'     => 0,
            'click_id'    => '',
            'order_date'  => '',
            'order_value' => 0.0,
            'merchant_id' => 0,
        ));

        self::assertIsArray($result);
        self::assertArrayHasKey('breakdown', $result);
        self::assertArrayHasKey('risk', $result['breakdown'], 'F-20-002: breakdown должен содержать ключ "risk".');
        self::assertArrayHasKey('time', $result['breakdown']);
        self::assertArrayHasKey('merchant', $result['breakdown']);
        self::assertArrayHasKey('user', $result['breakdown']);
        self::assertArrayHasKey('consistency', $result['breakdown']);
    }

    // =====================================================================
    // score_time_factor(): non-linear лестница + short-latency penalty.
    // Метод обращается к $wpdb — используем stub с известной датой клика.
    // =====================================================================

    /**
     * @dataProvider time_factor_cases
     */
    public function test_time_factor_non_linear_curve(string $click_time, string $order_time, float $expected, string $why): void
    {
        $GLOBALS['wpdb']->click_rows['click-1'] = array(
            'created_at'  => $click_time,
            'ip_address'  => '10.0.0.1',
            'user_agent'  => 'Mozilla',
            'product_id'  => 0,
        );

        $method = new ReflectionMethod('Cashback_Claims_Scoring', 'score_time_factor');
        $method->setAccessible(true);
        $actual = $method->invoke(null, 'click-1', $order_time);

        self::assertEqualsWithDelta($expected, $actual, 0.001, $why);
    }

    public static function time_factor_cases(): array
    {
        $click = '2026-04-24 10:00:00';
        return array(
            // Short-latency penalty — cookie-stuffing / бот зона.
            'less than 5 min → 0.2 (penalty)' => array($click, '2026-04-24 10:00:30', 0.2, 'F-20-002: <5 min — явный бот-паттерн, штраф.'),
            '5 min exact → 0.6'               => array($click, '2026-04-24 10:05:00', 0.6, '5-15 min — короткий, но допустимый.'),
            '10 min → 0.6'                    => array($click, '2026-04-24 10:10:00', 0.6, ''),
            '15 min exact → 0.95'             => array($click, '2026-04-24 10:15:00', 0.95, '15 min – 1h — нормальный.'),
            '45 min → 0.95'                   => array($click, '2026-04-24 10:45:00', 0.95, ''),
            '1h exact → 1.0 (peak)'           => array($click, '2026-04-24 11:00:00', 1.0, '1-24h — peak, typical decision cycle.'),
            '3h → 1.0 (peak)'                 => array($click, '2026-04-24 13:00:00', 1.0, ''),
            '24h exact → 1.0'                 => array($click, '2026-04-25 10:00:00', 1.0, 'граница 24h попадает в peak.'),
            '48h → 0.85'                      => array($click, '2026-04-26 10:00:00', 0.85, '24-72h — хорошо.'),
            '5 days → 0.7'                    => array($click, '2026-04-29 10:00:00', 0.7, '72h-7d — приемлемо.'),
            '15 days → 0.4'                   => array($click, '2026-05-09 10:00:00', 0.4, '7-30d — плохо.'),
            '45 days → 0.1'                   => array($click, '2026-06-08 10:00:00', 0.1, '>30d — минимум.'),
        );
    }

    public function test_time_factor_click_not_found_returns_zero(): void
    {
        $method = new ReflectionMethod('Cashback_Claims_Scoring', 'score_time_factor');
        $method->setAccessible(true);

        self::assertSame(0.0, $method->invoke(null, 'missing-click', '2026-04-24 10:00:00'));
    }

    public function test_time_factor_order_before_click_returns_zero(): void
    {
        $GLOBALS['wpdb']->click_rows['click-1'] = array(
            'created_at'  => '2026-04-24 10:00:00',
            'ip_address'  => '10.0.0.1',
            'user_agent'  => 'Mozilla',
            'product_id'  => 0,
        );

        $method = new ReflectionMethod('Cashback_Claims_Scoring', 'score_time_factor');
        $method->setAccessible(true);

        self::assertSame(0.0, $method->invoke(null, 'click-1', '2026-04-24 09:00:00'));
    }

    public function test_time_factor_invalid_timestamps_return_zero(): void
    {
        $GLOBALS['wpdb']->click_rows['click-1'] = array(
            'created_at'  => '2026-04-24 10:00:00',
            'ip_address'  => '10.0.0.1',
            'user_agent'  => 'Mozilla',
            'product_id'  => 0,
        );

        $method = new ReflectionMethod('Cashback_Claims_Scoring', 'score_time_factor');
        $method->setAccessible(true);

        self::assertSame(0.0, $method->invoke(null, 'click-1', 'not-a-date'));
        self::assertSame(0.0, $method->invoke(null, 'click-1', ''));
    }

    // =====================================================================
    // score_risk_factor(): инверсия Cashback_Fraud_Detector::calculate_user_risk_score.
    // =====================================================================

    /**
     * @dataProvider risk_factor_cases
     */
    public function test_risk_factor_inverts_composite_score(float $alerts_sum, float $expected, string $why): void
    {
        $GLOBALS['wpdb']->fraud_alerts_sum[42] = $alerts_sum;

        $method = new ReflectionMethod('Cashback_Claims_Scoring', 'score_risk_factor');
        $method->setAccessible(true);
        $actual = $method->invoke(null, 42);

        self::assertEqualsWithDelta($expected, $actual, 0.001, $why);
    }

    public static function risk_factor_cases(): array
    {
        return array(
            'clean user (0 alerts) → 1.0'   => array(0.0, 1.0, 'Чистый юзер получает полный балл.'),
            'low risk (30) → 0.7'           => array(30.0, 0.7, ''),
            'medium risk (60) → 0.4'        => array(60.0, 0.4, ''),
            'high risk (80) → 0.2'          => array(80.0, 0.2, ''),
            'critical (100) → 0.0'          => array(100.0, 0.0, 'Полностью токсичный юзер обнуляет фактор.'),
            'overflow protection (>100)'    => array(150.0, 0.0, 'calculate_user_risk_score() капает до 100; фактор не отрицательный.'),
        );
    }

    public function test_risk_factor_for_invalid_user_id_returns_neutral(): void
    {
        $method = new ReflectionMethod('Cashback_Claims_Scoring', 'score_risk_factor');
        $method->setAccessible(true);

        self::assertSame(0.5, $method->invoke(null, 0));
        self::assertSame(0.5, $method->invoke(null, -1));
    }

    // =====================================================================
    // calculate(): композитный сценарий.
    // =====================================================================

    public function test_happy_path_high_score(): void
    {
        // Юзер 42, чистый, peak time (3h), консистентный, merchant OK.
        $GLOBALS['wpdb']->click_rows['click-happy'] = array(
            'created_at'  => '2026-04-24 10:00:00',
            'ip_address'  => '10.0.0.1',
            'user_agent'  => 'Mozilla',
            'product_id'  => 0,
        );
        $GLOBALS['wpdb']->fraud_alerts_sum[42] = 0.0; // чистый
        $GLOBALS['wpdb']->merchant_stats[5]    = array('total' => 100, 'approved' => 90);
        $GLOBALS['wpdb']->user_stats[42]       = array('total' => 10, 'approved' => 9, 'suspicious' => 0);

        $result = Cashback_Claims_Scoring::calculate(array(
            'user_id'     => 42,
            'click_id'    => 'click-happy',
            'order_date'  => '2026-04-24 13:00:00',
            'order_value' => 5000.0,
            'merchant_id' => 5,
            'comment'     => 'Купил через приложение',
        ));

        self::assertGreaterThanOrEqual(85.0, $result['score'], 'Happy path должен давать высокий скор (≥85).');
    }

    public function test_bot_pattern_30s_drops_score(): void
    {
        // Тот же юзер, та же consistency — но клик→заказ за 30 секунд.
        // Результат должен быть заметно ниже happy path за счёт штрафа time-фактора.
        $GLOBALS['wpdb']->click_rows['click-bot'] = array(
            'created_at'  => '2026-04-24 10:00:00',
            'ip_address'  => '10.0.0.1',
            'user_agent'  => 'Mozilla',
            'product_id'  => 0,
        );
        $GLOBALS['wpdb']->fraud_alerts_sum[42] = 0.0;
        $GLOBALS['wpdb']->merchant_stats[5]    = array('total' => 100, 'approved' => 90);
        $GLOBALS['wpdb']->user_stats[42]       = array('total' => 10, 'approved' => 9, 'suspicious' => 0);

        $result = Cashback_Claims_Scoring::calculate(array(
            'user_id'     => 42,
            'click_id'    => 'click-bot',
            'order_date'  => '2026-04-24 10:00:30',
            'order_value' => 5000.0,
            'merchant_id' => 5,
            'comment'     => 'Купил через приложение',
        ));

        // Штраф на time-фактор 0.2 вместо 1.0 должен сдвинуть общий скор ниже 85.
        self::assertLessThan(85.0, $result['score'], 'Бот-паттерн <5 min должен опускать скор ниже happy path (<85).');
        self::assertSame(20.0, $result['breakdown']['time'], 'Time breakdown должен отразить штраф: 0.2 * 100 = 20.');
    }

    public function test_dirty_user_with_active_fraud_alerts_drops_score(): void
    {
        // Идеальный time, consistency, merchant, но юзер с критической суммой алертов.
        $GLOBALS['wpdb']->click_rows['click-dirty'] = array(
            'created_at'  => '2026-04-24 10:00:00',
            'ip_address'  => '10.0.0.1',
            'user_agent'  => 'Mozilla',
            'product_id'  => 0,
        );
        $GLOBALS['wpdb']->fraud_alerts_sum[42]  = 80.0; // high risk
        $GLOBALS['wpdb']->merchant_stats[5]     = array('total' => 100, 'approved' => 90);
        $GLOBALS['wpdb']->user_stats[42]        = array('total' => 10, 'approved' => 9, 'suspicious' => 0);

        $result = Cashback_Claims_Scoring::calculate(array(
            'user_id'     => 42,
            'click_id'    => 'click-dirty',
            'order_date'  => '2026-04-24 13:00:00',
            'order_value' => 5000.0,
            'merchant_id' => 5,
            'comment'     => 'Купил через приложение',
        ));

        // risk-factor = 0.2 → вклад = 0.2 * 0.25 * 100 = 5 вместо 25.
        // Разница должна быть ≥15 баллов относительно happy path.
        self::assertLessThan(85.0, $result['score'], 'Юзер с risk 80 не должен получать happy-path скор.');
        self::assertSame(20.0, $result['breakdown']['risk'], 'Risk breakdown должен отразить инверсию: (1-80/100)*100 = 20.');
    }

    public function test_calculate_signature_preserved(): void
    {
        // Backward-compat guard: подпись метода calculate() не меняется.
        $ref = new ReflectionMethod('Cashback_Claims_Scoring', 'calculate');
        self::assertTrue($ref->isStatic(), 'calculate() должен оставаться статическим.');
        self::assertTrue($ref->isPublic(), 'calculate() должен оставаться публичным.');

        $params = $ref->getParameters();
        self::assertCount(1, $params, 'calculate() должен принимать ровно 1 аргумент.');
        self::assertSame('claim_data', $params[0]->getName());
    }

    public function test_score_is_bounded_0_100(): void
    {
        // Экстремальные данные не должны давать скор вне [0, 100].
        $GLOBALS['wpdb']->click_rows['click-x'] = array(
            'created_at'  => '2026-04-24 10:00:00',
            'ip_address'  => '10.0.0.1',
            'user_agent'  => 'Mozilla',
            'product_id'  => 0,
        );
        $GLOBALS['wpdb']->fraud_alerts_sum[42] = 999.0; // overflow
        $GLOBALS['wpdb']->merchant_stats[5]    = array('total' => 100, 'approved' => 100);
        $GLOBALS['wpdb']->user_stats[42]       = array('total' => 100, 'approved' => 100, 'suspicious' => 0);

        $result = Cashback_Claims_Scoring::calculate(array(
            'user_id'     => 42,
            'click_id'    => 'click-x',
            'order_date'  => '2026-04-24 13:00:00',
            'order_value' => 5000.0,
            'merchant_id' => 5,
            'comment'     => 'ok',
        ));

        self::assertGreaterThanOrEqual(0.0, $result['score']);
        self::assertLessThanOrEqual(100.0, $result['score']);
    }
}

// ============================================================
// In-memory $wpdb stub для тестов скоринга.
// Отвечает на запросы:
//   - cashback_click_log  (get_row: created_at ± ip_address,user_agent,product_id)
//   - cashback_claims     (get_row: aggregate per merchant_id / user_id)
//   - cashback_fraud_alerts (get_var: SUM(risk_score))
// ============================================================

final class ClaimsScoring_Wpdb_Stub
{
    public string $prefix = 'wp_';
    public string $last_error = '';

    /** @var array<string, array<string, mixed>> click_id → row */
    public array $click_rows = array();

    /** @var array<int, array{total:int, approved:int}> merchant_id → stats */
    public array $merchant_stats = array();

    /** @var array<int, array{total:int, approved:int, suspicious:int}> user_id → stats */
    public array $user_stats = array();

    /** @var array<int, float> user_id → sum of open/reviewing alert risk_score */
    public array $fraud_alerts_sum = array();

    public function prepare(string $query, mixed ...$args): string
    {
        // %i (identifier) → backticked; %s → quoted; %d/%f → literal.
        $i = 0;
        return (string) preg_replace_callback('/%[isdf]/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? '';
            if ($m[0] === '%i') {
                return '`' . str_replace('`', '``', (string) $v) . '`';
            }
            if ($m[0] === '%s') {
                return "'" . str_replace("'", "\\'", (string) $v) . "'";
            }
            return (string) $v;
        }, $query);
    }

    public function get_row(string $sql, string $output = ARRAY_A): ?array
    {
        // cashback_click_log → created_at only (score_time_factor) OR full row (score_consistency_factor)
        if (preg_match("/FROM\\s+`?[^`\\s]*cashback_click_log`?.*WHERE\\s+click_id\\s*=\\s*'([^']+)'/is", $sql, $m)) {
            $id = $m[1];
            if (!isset($this->click_rows[$id])) {
                return null;
            }
            // Возвращаем доступные поля; продакшен-код сам выбирает нужные.
            return $this->click_rows[$id];
        }

        // cashback_claims aggregate by merchant_id
        if (preg_match("/FROM\\s+`?[^`\\s]*cashback_claims`?.*WHERE\\s+merchant_id\\s*=\\s*(\\d+)/is", $sql, $m)) {
            $merchant = (int) $m[1];
            $stats    = $this->merchant_stats[$merchant] ?? null;
            if ($stats === null) {
                return array('total' => 0, 'approved' => 0);
            }
            return $stats;
        }

        // cashback_claims aggregate by user_id
        if (preg_match("/FROM\\s+`?[^`\\s]*cashback_claims`?.*WHERE\\s+user_id\\s*=\\s*(\\d+)/is", $sql, $m)) {
            $user  = (int) $m[1];
            $stats = $this->user_stats[$user] ?? null;
            if ($stats === null) {
                return array('total' => 0, 'approved' => 0, 'suspicious' => 0);
            }
            return $stats;
        }

        return null;
    }

    public function get_var(string $sql): string|int|float|null
    {
        // Cashback_Fraud_Detector::calculate_user_risk_score: SUM(risk_score) FROM cashback_fraud_alerts
        if (preg_match("/FROM\\s+`?[^`\\s]*cashback_fraud_alerts`?.*WHERE\\s+user_id\\s*=\\s*(\\d+)/is", $sql, $m)) {
            $user = (int) $m[1];
            return $this->fraud_alerts_sum[$user] ?? 0.0;
        }

        return null;
    }
}
