<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/includes/rate-limit/interface-cashback-rate-limit-counter.php';
require_once dirname(__DIR__, 3) . '/includes/class-cashback-rate-limiter.php';

/**
 * Unit-тесты для click-rate маппинга в Cashback_Click_Session_Service
 * (Группа 7 ADR, шаг 7; rate-limit перенесён в сервис в group 12 refactor).
 *
 * Ключевые требования:
 *  - логика маппинга hits→normal/spam/blocked сохранена;
 *  - scope_keys shared с WC_Affiliate_URL_Params (оба callsite'а делят общий
 *    счётчик — намеренно, чтобы один IP не мог удвоить лимит через две точки входа).
 */
#[Group('rate-limit')]
final class RestApiActivateClickRateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cb_test_transients'] = array();
        $GLOBALS['_cb_test_cache']      = array();

        if (!class_exists('Cashback_Click_Session_Service')) {
            // После group 12 refactor get_click_rate_status живёт в click-session сервисе.
            // Подключаем файл сервиса напрямую — если будут ошибки, skip.
            $service_file = dirname(__DIR__, 3) . '/includes/class-cashback-click-session-service.php';
            if (!file_exists($service_file)) {
                $this->markTestSkipped('class-cashback-click-session-service.php отсутствует');
            }
            require_once $service_file;
        }
    }

    protected function tearDown(): void
    {
        \Cashback_Rate_Limiter::set_backend(null);
        parent::tearDown();
    }

    public function test_normal_when_hits_below_spam_threshold(): void
    {
        $backend = new Rest_Activate_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $status = $this->invoke_click_rate('1.2.3.4', 100);

        $this->assertSame('normal', $status);
    }

    public function test_blocked_when_per_product_hits_reach_block(): void
    {
        $backend = new Rest_Activate_Fake_Backend(array(
            array( 'hits' => 10, 'allowed' => false, 'reset_at' => time() + 60 ),
            array( 'hits' => 5,  'allowed' => true,  'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $this->assertSame('blocked', $this->invoke_click_rate('1.2.3.4', 100));
    }

    public function test_spam_on_pp_threshold_breach(): void
    {
        $backend = new Rest_Activate_Fake_Backend(array(
            array( 'hits' => 4, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 5, 'allowed' => true, 'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $this->assertSame('spam', $this->invoke_click_rate('1.2.3.4', 100));
    }

    public function test_backend_error_falls_open_to_normal(): void
    {
        $backend = new Rest_Activate_Fake_Backend(throwing: true);
        \Cashback_Rate_Limiter::set_backend($backend);

        $this->assertSame('normal', $this->invoke_click_rate('1.2.3.4', 100));
    }

    public function test_scope_keys_match_wc_affiliate_for_shared_counter(): void
    {
        // Критично: REST /activate и wc-affiliate клики от одного subject+product_id
        // должны использовать ОДИН scope_key — иначе злоумышленник удвоит лимит,
        // чередуя точки входа.
        // Группа 13 NAT-safety: subject для гостя = "n:<subnet>|<ua_family>".
        // Iter-3: добавлен hard subnet-only bucket 'gh_' (keyed h:<subnet>).
        $backend = new Rest_Activate_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $this->invoke_click_rate('1.2.3.4', 100);

        $subject     = 'n:1.2.3.0/24|Chrome';
        $expected_pp = 'pp_' . substr(sha1($subject . '|100'), 0, 40);
        $expected_gl = 'gl_' . substr(sha1($subject), 0, 40);
        $expected_gh = 'gh_' . substr(sha1('h:1.2.3.0/24'), 0, 40);

        $this->assertSame($expected_pp, $backend->scope_keys[0]);
        $this->assertSame($expected_gl, $backend->scope_keys[1]);
        $this->assertSame($expected_gh, $backend->scope_keys[2]);
    }

    public function test_logged_in_user_scope_key_is_user_primary_nat_safe(): void
    {
        // Группа 13: для залогиненного ключ по user_id, IP игнорируется — NAT-safe.
        $backend = new Rest_Activate_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 2, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 2, 'allowed' => true, 'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $this->invoke_click_rate('1.2.3.4', 100, 42);
        $this->invoke_click_rate('198.51.100.9', 100, 42); // другой IP, тот же user

        $this->assertSame($backend->scope_keys[0], $backend->scope_keys[2]);
        $this->assertSame($backend->scope_keys[1], $backend->scope_keys[3]);
    }

    private function invoke_click_rate(string $ip, int $product_id, int $user_id = 0, string $ua = 'Mozilla/5.0 Chrome/120'): string
    {
        // Группа 13: signature (user_id, ip, ua, product_id).
        $method = new \ReflectionMethod(\Cashback_Click_Session_Service::class, 'get_click_rate_status');

        return (string) $method->invoke(null, $user_id, $ip, $ua, $product_id);
    }
}

final class Rest_Activate_Fake_Backend implements Cashback_Rate_Limit_Counter_Interface
{
    public int $call_count = 0;
    /** @var list<string> */
    public array $scope_keys = array();
    /** @var list<array{hits:int, allowed:bool, reset_at:int}> */
    private array $queue;
    private bool $throwing;

    /** @param list<array{hits:int, allowed:bool, reset_at:int}> $queue */
    public function __construct(array $queue = array(), bool $throwing = false)
    {
        $this->queue    = $queue;
        $this->throwing = $throwing;
    }

    public function increment(string $scope_key, int $window_seconds, int $limit): array
    {
        $this->call_count++;
        $this->scope_keys[] = $scope_key;

        if ($this->throwing) {
            throw new \RuntimeException('simulated backend failure');
        }

        return array_shift($this->queue)
            ?? array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + $window_seconds );
    }
}
