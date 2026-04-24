<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/includes/rate-limit/interface-cashback-rate-limit-counter.php';
require_once dirname(__DIR__, 3) . '/includes/class-cashback-rate-limiter.php';

if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $func): bool
    {
        return true;
    }
}

if (!function_exists('is_email')) {
    function is_email(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}

/**
 * Unit-тесты для миграции contact-form rate-limit на атомарный backend
 * (Группа 7 ADR, шаг 8).
 *
 * Раньше handle_submit() вызывал get_transient/set_transient последовательно, причём
 * set_transient только ПОСЛЕ успешного submit — bot-rejected попытки квоту не
 * использовали. Теперь — один атомарный increment() в начале, независимо от
 * исхода валидации: более строгий и корректный rate-limit.
 */
#[Group('rate-limit')]
final class ContactFormRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cb_test_transients']   = array();
        $GLOBALS['_cb_test_cache']        = array();
        $GLOBALS['_cb_test_options']      = array();
        $GLOBALS['_cb_test_is_logged_in'] = false;
        $GLOBALS['_cb_test_user_id']      = 0;
        unset($_SERVER['HTTP_USER_AGENT']);
        unset($_POST['cb_captcha_token']);

        if (!class_exists('Cashback_Contact_Form')) {
            $file = dirname(__DIR__, 3) . '/includes/class-cashback-contact-form.php';
            if (!file_exists($file)) {
                $this->markTestSkipped('class-cashback-contact-form.php отсутствует');
            }
            require_once $file;
        }
        if (!class_exists('Cashback_Affiliate_Service')) {
            require_once dirname(__DIR__, 3) . '/affiliate/class-affiliate-service.php';
        }
        if (!class_exists('Cashback_Encryption')) {
            require_once dirname(__DIR__, 3) . '/includes/class-cashback-encryption.php';
        }
        if (!class_exists('Cashback_Fraud_Settings')) {
            require_once dirname(__DIR__, 3) . '/antifraud/class-fraud-settings.php';
        }
        if (!class_exists('Cashback_Captcha')) {
            require_once dirname(__DIR__, 3) . '/includes/class-cashback-captcha.php';
        }
    }

    protected function tearDown(): void
    {
        unset($_POST['cb_captcha_token']);
        \Cashback_Rate_Limiter::set_backend(null);
        parent::tearDown();
    }

    public function test_denied_response_triggers_rate_limited_error(): void
    {
        // Pre-CAPTCHA bucket (first increment) заблокирован → 429.
        $backend = new Contact_Form_Fake_Backend(array(
            array( 'hits' => 21, 'allowed' => false, 'reset_at' => time() + 3600 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $response = $this->invoke_handle_submit('1.2.3.4');

        $this->assertFalse($response['success']);
        $this->assertSame('rate_limited', $response['data']['code'] ?? null);
        $this->assertSame(429, $response['status_code'] ?? null);
    }

    public function test_allowed_response_proceeds_past_rate_limit_check(): void
    {
        // Iter-4: гостевой flow теперь два bucket'а (pre + post при отсутствии CAPTCHA).
        $backend = new Contact_Form_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $response = $this->invoke_handle_submit('1.2.3.4');

        if ($response['success'] === false) {
            $this->assertNotSame(
                'rate_limited',
                $response['data']['code'] ?? null,
                'При allowed=true код ошибки НЕ должен быть rate_limited.'
            );
        }
    }

    public function test_guest_pre_captcha_bucket_is_per_ip_narrow(): void
    {
        // Группа 13 iter-4: pre-CAPTCHA bucket per-(IP + UA-family), лимит 20/час.
        // Narrow bucket — attacker на одном IP не может DoS-ить соседей на том же subnet.
        $backend = new Contact_Form_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $GLOBALS['_cb_test_is_logged_in'] = false;
        $GLOBALS['_cb_test_user_id']      = 0;
        $this->invoke_handle_submit('1.2.3.4');

        $this->assertStringStartsWith('contact_ip_', $backend->scope_keys[0], 'First increment = pre-CAPTCHA per-IP');
        $this->assertSame(20, $backend->limits[0], 'RATE_LIMIT_GUEST_IP = 20/hour');
        $this->assertSame(3600, $backend->windows[0]);
    }

    public function test_guest_post_captcha_bucket_is_subnet_wide_when_captcha_skipped(): void
    {
        // Iter-4: post-CAPTCHA bucket (per subnet+UA, лимит 30) пишется вторым
        // при отсутствии CAPTCHA (configure=false в тест-окружении).
        $backend = new Contact_Form_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $GLOBALS['_cb_test_is_logged_in'] = false;
        $GLOBALS['_cb_test_user_id']      = 0;
        $this->invoke_handle_submit('1.2.3.4');

        $this->assertStringStartsWith('contact_g_', $backend->scope_keys[1], 'Second increment = post-CAPTCHA per-subject');
        $this->assertSame(30, $backend->limits[1], 'RATE_LIMIT_GUEST = 30/hour');
    }

    public function test_guest_spam_on_one_ip_does_not_dos_neighbor_ip(): void
    {
        // Iter-4 adversarial: attacker на IP_A забил pre-bucket, neighbor на IP_B
        // (тот же subnet+UA) всё равно может работать — их pre-bucket'ы разные.
        $backend = new Contact_Form_Fake_Backend(array(
            // IP_A: pre-bucket denied.
            array( 'hits' => 21, 'allowed' => false, 'reset_at' => time() + 3600 ),
            // IP_B: pre-bucket fresh.
            array( 'hits' => 1,  'allowed' => true,  'reset_at' => time() + 3600 ),
            array( 'hits' => 2,  'allowed' => true,  'reset_at' => time() + 3600 ), // post-bucket B
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $GLOBALS['_cb_test_is_logged_in'] = false;
        $GLOBALS['_cb_test_user_id']      = 0;
        $_SERVER['HTTP_USER_AGENT']       = 'Mozilla/5.0 Chrome/120.0.0 Safari/537.36';

        $response_a = $this->invoke_handle_submit('1.2.3.4');
        $this->assertSame('rate_limited', $response_a['data']['code'] ?? null);

        $response_b = $this->invoke_handle_submit('1.2.3.77');
        $this->assertNotSame('rate_limited', $response_b['data']['code'] ?? null);

        // Pre-bucket scope_keys[0] (A) != scope_keys[1] (B): per-IP isolation.
        $this->assertNotSame($backend->scope_keys[0], $backend->scope_keys[1]);
    }

    public function test_guest_post_bucket_shared_by_subnet_and_ua(): void
    {
        // Iter-4: post-bucket per-(subnet+UA) даёт NAT-relief на реальных доставках.
        // scope_keys[1] и scope_keys[3] (post-bucket обоих вызовов) должны совпадать.
        $backend = new Contact_Form_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
            array( 'hits' => 2, 'allowed' => true, 'reset_at' => time() + 3600 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $GLOBALS['_cb_test_is_logged_in'] = false;
        $GLOBALS['_cb_test_user_id']      = 0;
        $_SERVER['HTTP_USER_AGENT']       = 'Mozilla/5.0 Chrome/120.0.0 Safari/537.36';
        $this->invoke_handle_submit('1.2.3.4');
        $this->invoke_handle_submit('1.2.3.99');

        // scope_keys[1] post-bucket call#1, scope_keys[3] post-bucket call#2.
        $this->assertStringStartsWith('contact_g_', $backend->scope_keys[1]);
        $this->assertStringStartsWith('contact_g_', $backend->scope_keys[3]);
        $this->assertSame($backend->scope_keys[1], $backend->scope_keys[3], 'Subnet+UA shared на post-bucket.');
        // Pre-bucket по-разному (разные IP).
        $this->assertNotSame($backend->scope_keys[0], $backend->scope_keys[2]);
    }

    public function test_guest_different_ua_family_produces_different_scope_keys(): void
    {
        // UA-family входит в оба scope (pre и post) → все 4 scope_keys разные.
        $backend = new Contact_Form_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $GLOBALS['_cb_test_is_logged_in'] = false;
        $GLOBALS['_cb_test_user_id']      = 0;

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome/120.0.0 Safari/537.36';
        $this->invoke_handle_submit('1.2.3.4');
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Firefox/120.0';
        $this->invoke_handle_submit('1.2.3.4');

        // Pre-bucket разные (разные family).
        $this->assertNotSame($backend->scope_keys[0], $backend->scope_keys[2]);
        // Post-bucket тоже разные.
        $this->assertNotSame($backend->scope_keys[1], $backend->scope_keys[3]);
    }

    public function test_logged_in_scope_key_is_per_user_and_nat_safe(): void
    {
        // Для залогиненного ключ по user_id, IP не влияет → NAT-safe.
        $backend = new Contact_Form_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
            array( 'hits' => 2, 'allowed' => true, 'reset_at' => time() + 3600 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $GLOBALS['_cb_test_is_logged_in'] = true;
        $GLOBALS['_cb_test_user_id']      = 42;
        $this->invoke_handle_submit('1.2.3.4');
        $this->invoke_handle_submit('198.51.100.9');

        $this->assertStringStartsWith('contact_u_', $backend->scope_keys[0]);
        $this->assertSame($backend->scope_keys[0], $backend->scope_keys[1]);
        $this->assertSame(3, $backend->limits[0]); // per-user RATE_LIMIT = 3
    }

    public function test_different_ips_produce_different_pre_bucket_scope_keys(): void
    {
        $backend = new Contact_Form_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $GLOBALS['_cb_test_is_logged_in'] = false;
        $GLOBALS['_cb_test_user_id']      = 0;
        $_SERVER['HTTP_USER_AGENT']       = 'Mozilla/5.0 Chrome/120.0.0 Safari/537.36';
        $this->invoke_handle_submit('1.2.3.4');
        $this->invoke_handle_submit('5.6.7.8');

        // Pre-bucket разные (разные IP, разные subnet).
        $this->assertNotSame($backend->scope_keys[0], $backend->scope_keys[2]);
        // Post-bucket тоже разные (разные subnet).
        $this->assertNotSame($backend->scope_keys[1], $backend->scope_keys[3]);
    }

    public function test_failed_captcha_does_not_charge_shared_post_bucket(): void
    {
        // Группа 13 iter-4 adversarial: 30 junk POSTов с invalid captcha-токеном
        // должны заряжать ТОЛЬКО pre-bucket (per-IP narrow), НЕ shared post-bucket.
        // Иначе attacker на одном IP DoS-ит всю NAT-подсеть за одну UA-family.
        update_option('cashback_captcha_server_key', 'test-server-key');
        update_option('cashback_captcha_client_key', 'test-client-key');

        $backend = new Contact_Form_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $GLOBALS['_cb_test_is_logged_in'] = false;
        $GLOBALS['_cb_test_user_id']      = 0;
        $_POST['cb_captcha_token']        = ''; // invalid = empty

        $response = $this->invoke_handle_submit('1.2.3.4');

        // CAPTCHA провалилась → captcha_failed.
        $this->assertSame('captcha_failed', $response['data']['code'] ?? null);
        // Pre-bucket зарядился (per-IP).
        $this->assertCount(1, $backend->scope_keys, 'Должен быть только pre-bucket increment.');
        $this->assertStringStartsWith('contact_ip_', $backend->scope_keys[0]);
        // Post-bucket НЕ зарядился — shared NAT защищён.
        $this->assertArrayNotHasKey(1, $backend->scope_keys, 'Shared post-bucket не должен заряжаться при failed CAPTCHA.');
    }

    public function test_backend_exception_fails_open(): void
    {
        $backend = new Contact_Form_Fake_Backend(throwing: true);
        \Cashback_Rate_Limiter::set_backend($backend);

        $response = $this->invoke_handle_submit('1.2.3.4');

        // Fail-open: rate-limit не блокирует; последующие проверки (honeypot, nonce)
        // могут завалить запрос, но НЕ с кодом rate_limited.
        if ($response['success'] === false) {
            $this->assertNotSame('rate_limited', $response['data']['code'] ?? null);
        }
    }

    public function test_guest_post_bucket_is_skipped_when_backend_throws(): void
    {
        // Iter-4 adversarial F3: если pre-bucket try/catch съел throw из counter_backend(),
        // post-bucket НЕ должен обращаться к undefined $counter. Ранее это давало fatal
        // null-method call для гостевых submit'ов, превращая fail-open в fatal-крash.
        $backend = new Contact_Form_Fake_Backend(throwing: true);
        \Cashback_Rate_Limiter::set_backend($backend);

        $GLOBALS['_cb_test_is_logged_in'] = false;
        $GLOBALS['_cb_test_user_id']      = 0;

        // Не должно быть fatal'ов; response получится (может быть ошибкой др. проверки),
        // но НЕ с rate_limited и НЕ с TypeError.
        $response = $this->invoke_handle_submit('1.2.3.4');
        $this->assertIsArray($response);
        if ($response['success'] === false) {
            $this->assertNotSame('rate_limited', $response['data']['code'] ?? null);
        }
    }

    /**
     * Симулирует AJAX-вызов handle_submit() с заданным IP. Перехватывает
     * wp_send_json_success/error через Cashback_Test_Halt_Signal (bootstrap).
     *
     * @return array{success: bool, data: mixed, status_code: int|null}
     */
    private function invoke_handle_submit(string $ip): array
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
        unset($GLOBALS['_cb_test_last_json_response']);

        // Защитно гарантируем, что handle_submit дойдёт хотя бы до rate-limit inc
        // даже если UA явно не задан тестом — иначе is_bot_user_agent() может
        // прервать выполнение до increment() на новых backend-путях.
        if (!isset($_SERVER['HTTP_USER_AGENT']) || $_SERVER['HTTP_USER_AGENT'] === '') {
            $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome/120.0.0 Safari/537.36';
        }

        $reflection = new \ReflectionClass(\Cashback_Contact_Form::class);
        $form       = $reflection->newInstanceWithoutConstructor();

        try {
            $form->handle_submit();
        } catch (\Cashback_Test_Halt_Signal $e) {
            // expected — wp_send_json_* throws Halt в тестах.
        }

        /** @var array{success:bool, data:mixed, status_code:int|null} $resp */
        $resp = $GLOBALS['_cb_test_last_json_response'] ?? array(
            'success'     => false,
            'data'        => null,
            'status_code' => null,
        );

        return $resp;
    }
}

final class Contact_Form_Fake_Backend implements Cashback_Rate_Limit_Counter_Interface
{
    public int $call_count = 0;
    /** @var list<string> */
    public array $scope_keys = array();
    /** @var list<int> */
    public array $windows = array();
    /** @var list<int> */
    public array $limits = array();
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
        $this->windows[]    = $window_seconds;
        $this->limits[]     = $limit;

        if ($this->throwing) {
            throw new \RuntimeException('simulated backend failure');
        }

        return array_shift($this->queue)
            ?? array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + $window_seconds );
    }
}
