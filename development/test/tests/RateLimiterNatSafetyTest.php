<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/includes/rate-limit/interface-cashback-rate-limit-counter.php';
require_once dirname(__DIR__, 3) . '/includes/class-cashback-rate-limiter.php';
require_once dirname(__DIR__, 3) . '/affiliate/class-affiliate-service.php';

/**
 * NAT-safety тесты для Cashback_Rate_Limiter (post-plan — Группа 13).
 *
 * Проверяют:
 *   1. Grey scoring для авторизованных пользователей привязан к user_id, не к IP
 *      (один "плохой" пользователь за NAT не должен зашумить всем остальным).
 *   2. Grey scoring для гостей (user_id=0) остаётся привязан к IP — backward compat.
 *   3. В make_scope_key: для логина scope keyed по user_id (разные IP → тот же bucket).
 *   4. В make_scope_key: для гостя pre-composed composite ($ip не валидный IP) не ломается.
 *   5. В make_scope_key: для гостя raw IP сводится к subnet /24 (IPv4) или /64 (IPv6)
 *      — разные IP в одной подсети разделяют bucket (ожидаемо для NAT).
 */
#[Group('rate-limit')]
final class RateLimiterNatSafetyTest extends TestCase
{
    /** @var array<string, Fake_RL_Backend_Nat> */
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cb_test_transients']  = array();
        $GLOBALS['_cb_test_options']     = array();
        $GLOBALS['_cb_test_cache']       = array();
        $GLOBALS['_cb_test_filters']     = array();
        $GLOBALS['_cb_test_is_logged_in'] = false;
        $GLOBALS['_cb_test_user_id']      = 0;
    }

    protected function tearDown(): void
    {
        \Cashback_Rate_Limiter::set_backend(null);
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Grey scoring
    // ---------------------------------------------------------------

    public function test_grey_score_is_per_user_for_logged_in(): void
    {
        // Два разных пользователя за одним IP. Первый нарушает — второй НЕ должен стать серым.
        \Cashback_Rate_Limiter::record_violation(42, '203.0.113.5', 'bot_ua');
        \Cashback_Rate_Limiter::record_violation(42, '203.0.113.5', 'honeypot');

        $this->assertGreaterThan(0, \Cashback_Rate_Limiter::get_grey_score(42, '203.0.113.5'));
        $this->assertSame(0, \Cashback_Rate_Limiter::get_grey_score(43, '203.0.113.5'));
    }

    public function test_grey_score_for_guest_is_per_ip(): void
    {
        // Для user_id=0 — ключ по IP (backward compat; гости всё ещё делят ведро за NAT).
        \Cashback_Rate_Limiter::record_violation(0, '203.0.113.5', 'honeypot');

        $this->assertGreaterThan(0, \Cashback_Rate_Limiter::get_grey_score(0, '203.0.113.5'));
        $this->assertSame(0, \Cashback_Rate_Limiter::get_grey_score(0, '198.51.100.9'));
    }

    public function test_is_blocked_ip_is_per_user_for_logged_in(): void
    {
        // Выставляем score 80+ только для пользователя 42.
        for ($i = 0; $i < 3; $i++) {
            \Cashback_Rate_Limiter::record_violation(42, '203.0.113.5', 'honeypot'); // 40 баллов
        }
        // У 42 score = 100 (cap), у 43 — 0.
        $this->assertTrue(\Cashback_Rate_Limiter::is_blocked_ip(42, '203.0.113.5'));
        $this->assertFalse(\Cashback_Rate_Limiter::is_blocked_ip(43, '203.0.113.5'));
    }

    // ---------------------------------------------------------------
    // Scope key
    // ---------------------------------------------------------------

    public function test_scope_key_for_logged_in_is_user_primary_nat_safe(): void
    {
        // Два разных IP одного user_id → тот же scope (NAT-safe: user работает с разных сетей).
        $backend = new Fake_RL_Backend_Nat(array(
            array('hits' => 1, 'allowed' => true, 'reset_at' => time() + 60),
            array('hits' => 2, 'allowed' => true, 'reset_at' => time() + 60),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        \Cashback_Rate_Limiter::check('get_user_balance', 42, '203.0.113.5');
        \Cashback_Rate_Limiter::check('get_user_balance', 42, '198.51.100.9');

        $this->assertSame($backend->scope_keys[0], $backend->scope_keys[1]);
    }

    public function test_scope_key_for_guest_different_ips_are_different_buckets(): void
    {
        // Iter-4 (adversarial): default guest scope = per-IP (не subnet).
        // Раньше iter-1 collapsed scope в subnet без UA-separation — один abuser
        // DoS-ил всю /24 через любой public guest action. NAT-relief теперь opt-in
        // у callsite'ов (affiliate_click/signup), а не в make_scope_key по умолчанию.
        $backend = new Fake_RL_Backend_Nat(array(
            array('hits' => 1, 'allowed' => true, 'reset_at' => time() + 60),
            array('hits' => 1, 'allowed' => true, 'reset_at' => time() + 60),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        \Cashback_Rate_Limiter::check('affiliate_click', 0, '203.0.113.5');
        \Cashback_Rate_Limiter::check('affiliate_click', 0, '198.51.100.9');

        $this->assertNotSame($backend->scope_keys[0], $backend->scope_keys[1]);
    }

    public function test_scope_key_for_guest_same_subnet_different_ips_are_different_buckets(): void
    {
        // Iter-4 adversarial: разные IP в одной /24 → ОТДЕЛЬНЫЕ bucket'ы.
        // Убираем default subnet-collapse — это был vector для cross-guest DoS.
        $backend = new Fake_RL_Backend_Nat(array(
            array('hits' => 1, 'allowed' => true, 'reset_at' => time() + 60),
            array('hits' => 1, 'allowed' => true, 'reset_at' => time() + 60),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        \Cashback_Rate_Limiter::check('affiliate_click', 0, '203.0.113.5');
        \Cashback_Rate_Limiter::check('affiliate_click', 0, '203.0.113.77');

        $this->assertNotSame(
            $backend->scope_keys[0],
            $backend->scope_keys[1],
            'Разные guest IP в одной /24 НЕ должны делить bucket — иначе shared-NAT DoS.'
        );
    }

    public function test_check_with_skip_blocked_check_bypasses_block_gate(): void
    {
        // Iter-4 adversarial F1: после captcha-pass guard должен уметь пропускать
        // check() через is_blocked_ip gate, иначе recovery для гостя не работает —
        // guard пускает через CAPTCHA, а check() снова бросает 429 на ту же blocked-gate.
        $backend = new Fake_RL_Backend_Nat(array(
            array('hits' => 1, 'allowed' => true, 'reset_at' => time() + 60),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        // Поднимаем score >= 80 для guest IP (honeypot = 40 × 3 = cap 100).
        for ($i = 0; $i < 3; $i++) {
            \Cashback_Rate_Limiter::record_violation(0, '203.0.113.10', 'honeypot');
        }

        // Без bypass → check() блокирует на is_blocked_ip (не захода в counter).
        $r1 = \Cashback_Rate_Limiter::check('get_user_balance', 0, '203.0.113.10');
        $this->assertFalse($r1['allowed']);
        $this->assertCount(0, $backend->scope_keys, 'При blocked gate counter не должен вызываться.');

        // С bypass=true → check() пропускает is_blocked_ip и идёт к counter'у.
        $r2 = \Cashback_Rate_Limiter::check('get_user_balance', 0, '203.0.113.10', true);
        $this->assertTrue($r2['allowed'], 'С bypass=true check() должен пропустить block gate.');
        $this->assertCount(1, $backend->scope_keys, 'С bypass counter должен вызваться.');
    }

    public function test_scope_key_for_guest_with_pre_composed_subject_is_preserved(): void
    {
        // Для affiliate_signup callsite передаёт уже composite-ключ (subnet+UA).
        // Он не должен ломаться — make_scope_key оставляет non-IP строку как-есть.
        $backend = new Fake_RL_Backend_Nat(array(
            array('hits' => 1, 'allowed' => true, 'reset_at' => time() + 60),
            array('hits' => 1, 'allowed' => true, 'reset_at' => time() + 60),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        \Cashback_Rate_Limiter::check('affiliate_signup', 0, 'abcd1234567890ab:Chrome');
        \Cashback_Rate_Limiter::check('affiliate_signup', 0, 'deadbeefdeadbeef:Firefox');

        $this->assertNotSame($backend->scope_keys[0], $backend->scope_keys[1]);
    }

    public function test_is_blocked_ip_for_logged_in_does_not_leak_to_guest_on_same_ip(): void
    {
        // Один пользователь 42 заблокирован (score=100) на IP.
        for ($i = 0; $i < 3; $i++) {
            \Cashback_Rate_Limiter::record_violation(42, '203.0.113.5', 'honeypot');
        }

        $this->assertTrue(\Cashback_Rate_Limiter::is_blocked_ip(42, '203.0.113.5'));
        $this->assertFalse(\Cashback_Rate_Limiter::is_blocked_ip(0, '203.0.113.5'));
    }
}

/**
 * Local fake backend (избегаем конфликта с Fake_RL_Backend из RateLimiterBackendDITest).
 */
final class Fake_RL_Backend_Nat implements Cashback_Rate_Limit_Counter_Interface
{
    /** @var list<string> */
    public array $scope_keys = array();
    /** @var list<array{hits:int, allowed:bool, reset_at:int}> */
    private array $queue;

    /**
     * @param list<array{hits:int, allowed:bool, reset_at:int}> $queue
     */
    public function __construct(array $queue = array())
    {
        $this->queue = $queue;
    }

    public function increment(string $scope_key, int $window_seconds, int $limit): array
    {
        $this->scope_keys[] = $scope_key;

        return array_shift($this->queue)
            ?? array('hits' => 1, 'allowed' => true, 'reset_at' => time() + $window_seconds);
    }
}
