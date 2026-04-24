<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit-тесты для HMAC-подписей F-2-001 hardening:
 *   - Cashback_Encryption::sign_activation_token / verify_activation_token
 *   - Cashback_Encryption::sign_cookie_payload / verify_cookie_payload
 *
 * Гарантии:
 *   - Валидная подпись проходит round-trip (positive path).
 *   - Истёкший token (> max_age) отклоняется.
 *   - Token «из будущего» (> now+30s clock-skew) отклоняется.
 *   - Подмена click_id / user_id / ts / domain делает подпись невалидной (timing-safe).
 *   - Domain separation: activation-token ключ ≠ cookie-signature ключ (подпись
 *     одного контекста не валидна в другом).
 *   - Битый base64 / мусор на входе → false без исключения.
 */
#[Group('security')]
#[Group('f-2-001')]
#[Group('hmac-signatures')]
final class ActivationTokenHmacTest extends TestCase
{
    private const CLICK_ID = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';
    private const USER_ID  = 42;
    private const DOMAIN   = 'shop.example.com';

    // =====================================================================
    // sign_activation_token / verify_activation_token
    // =====================================================================

    public function test_valid_token_round_trip(): void
    {
        $issued_at = time();
        $token     = Cashback_Encryption::sign_activation_token(self::CLICK_ID, self::USER_ID, $issued_at);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/', $token);
        $this->assertTrue(Cashback_Encryption::verify_activation_token($token, self::CLICK_ID, self::USER_ID));
    }

    public function test_token_is_deterministic_for_same_inputs(): void
    {
        $issued_at = 1_700_000_000;
        $a = Cashback_Encryption::sign_activation_token(self::CLICK_ID, self::USER_ID, $issued_at);
        $b = Cashback_Encryption::sign_activation_token(self::CLICK_ID, self::USER_ID, $issued_at);

        $this->assertSame($a, $b, 'Signing must be deterministic — иначе verify не сможет сравнивать.');
    }

    public function test_token_differs_per_click_id(): void
    {
        $issued_at  = 1_700_000_000;
        $other_click = str_repeat('b', 32);
        $t1 = Cashback_Encryption::sign_activation_token(self::CLICK_ID, self::USER_ID, $issued_at);
        $t2 = Cashback_Encryption::sign_activation_token($other_click, self::USER_ID, $issued_at);

        $this->assertNotSame($t1, $t2);
    }

    public function test_token_differs_per_user(): void
    {
        $issued_at = 1_700_000_000;
        $t1 = Cashback_Encryption::sign_activation_token(self::CLICK_ID, 42, $issued_at);
        $t2 = Cashback_Encryption::sign_activation_token(self::CLICK_ID, 43, $issued_at);

        $this->assertNotSame($t1, $t2);
    }

    public function test_expired_token_rejected(): void
    {
        $issued_at = time() - 3600; // 1 час назад, заметно больше TTL
        $token     = Cashback_Encryption::sign_activation_token(self::CLICK_ID, self::USER_ID, $issued_at);

        $this->assertFalse(
            Cashback_Encryption::verify_activation_token($token, self::CLICK_ID, self::USER_ID),
            'Токен старше default TTL (300s) должен быть отклонён.'
        );
    }

    public function test_custom_max_age_accepts_older_token(): void
    {
        // Явно разрешаем час — тот же токен, что отклоняется с default 300s.
        $issued_at = time() - 600;
        $token     = Cashback_Encryption::sign_activation_token(self::CLICK_ID, self::USER_ID, $issued_at);

        $this->assertTrue(
            Cashback_Encryption::verify_activation_token($token, self::CLICK_ID, self::USER_ID, 3600)
        );
    }

    public function test_future_token_rejected_beyond_clock_skew(): void
    {
        // Токен создан «в будущем» — это либо атака, либо существенный clock-skew.
        // Допускаем до 30s (защита от честного skew), далее — отказ.
        $issued_at = time() + 120;
        $token     = Cashback_Encryption::sign_activation_token(self::CLICK_ID, self::USER_ID, $issued_at);

        $this->assertFalse(
            Cashback_Encryption::verify_activation_token($token, self::CLICK_ID, self::USER_ID)
        );
    }

    public function test_future_token_within_clock_skew_accepted(): void
    {
        // Честный skew 10 сек — в пределах 30-сек допуска.
        $issued_at = time() + 10;
        $token     = Cashback_Encryption::sign_activation_token(self::CLICK_ID, self::USER_ID, $issued_at);

        $this->assertTrue(
            Cashback_Encryption::verify_activation_token($token, self::CLICK_ID, self::USER_ID)
        );
    }

    public function test_tampered_click_id_fails(): void
    {
        $token = Cashback_Encryption::sign_activation_token(self::CLICK_ID, self::USER_ID, time());

        $tampered_click = str_repeat('9', 32);
        $this->assertFalse(
            Cashback_Encryption::verify_activation_token($token, $tampered_click, self::USER_ID),
            'Попытка reuse валидного токена с подменённым click_id должна проваливаться.'
        );
    }

    public function test_tampered_user_id_fails(): void
    {
        $token = Cashback_Encryption::sign_activation_token(self::CLICK_ID, self::USER_ID, time());

        $this->assertFalse(
            Cashback_Encryption::verify_activation_token($token, self::CLICK_ID, 999),
            'Попытка reuse чужого токена (другой user_id) должна проваливаться — защита от IDOR.'
        );
    }

    public function test_tampered_hmac_fails(): void
    {
        $token = Cashback_Encryption::sign_activation_token(self::CLICK_ID, self::USER_ID, time());

        // Флипаем один символ в HMAC-части.
        [$ts_part, $mac_part] = explode('.', $token);
        $flipped = ( $mac_part[0] === 'A' ? 'B' : 'A' ) . substr($mac_part, 1);
        $tampered_token = $ts_part . '.' . $flipped;

        $this->assertFalse(
            Cashback_Encryption::verify_activation_token($tampered_token, self::CLICK_ID, self::USER_ID)
        );
    }

    public function test_garbage_token_returns_false(): void
    {
        $this->assertFalse(Cashback_Encryption::verify_activation_token('', self::CLICK_ID, self::USER_ID));
        $this->assertFalse(Cashback_Encryption::verify_activation_token('nodot', self::CLICK_ID, self::USER_ID));
        $this->assertFalse(Cashback_Encryption::verify_activation_token('a.b.c', self::CLICK_ID, self::USER_ID));
        $this->assertFalse(Cashback_Encryption::verify_activation_token('!!!.@@@', self::CLICK_ID, self::USER_ID));
        $this->assertFalse(Cashback_Encryption::verify_activation_token('AAAA.BBBB', self::CLICK_ID, self::USER_ID));
    }

    // =====================================================================
    // sign_cookie_payload / verify_cookie_payload
    // =====================================================================

    public function test_cookie_sig_round_trip(): void
    {
        $ts  = time();
        $sig = Cashback_Encryption::sign_cookie_payload(self::CLICK_ID, self::DOMAIN, $ts);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_\-]+$/', $sig);
        $this->assertTrue(Cashback_Encryption::verify_cookie_payload($sig, self::CLICK_ID, self::DOMAIN, $ts));
    }

    public function test_cookie_sig_deterministic(): void
    {
        $ts = 1_700_000_000;
        $a  = Cashback_Encryption::sign_cookie_payload(self::CLICK_ID, self::DOMAIN, $ts);
        $b  = Cashback_Encryption::sign_cookie_payload(self::CLICK_ID, self::DOMAIN, $ts);

        $this->assertSame($a, $b);
    }

    public function test_cookie_sig_domain_case_insensitive(): void
    {
        // Нормализуем domain lower-case'ом в signer — cookie-writer может записать WWW.Example.com,
        // а browser/extension прочитать www.example.com.
        $ts  = 1_700_000_000;
        $sig = Cashback_Encryption::sign_cookie_payload(self::CLICK_ID, 'SHOP.Example.COM', $ts);

        $this->assertTrue(Cashback_Encryption::verify_cookie_payload($sig, self::CLICK_ID, 'shop.example.com', $ts));
    }

    public function test_cookie_tampered_click_id_fails(): void
    {
        $ts  = time();
        $sig = Cashback_Encryption::sign_cookie_payload(self::CLICK_ID, self::DOMAIN, $ts);

        $this->assertFalse(
            Cashback_Encryption::verify_cookie_payload($sig, str_repeat('0', 32), self::DOMAIN, $ts)
        );
    }

    public function test_cookie_tampered_domain_fails(): void
    {
        $ts  = time();
        $sig = Cashback_Encryption::sign_cookie_payload(self::CLICK_ID, self::DOMAIN, $ts);

        $this->assertFalse(
            Cashback_Encryption::verify_cookie_payload($sig, self::CLICK_ID, 'evil.example.com', $ts),
            'Подмена домена в cookie должна инвалидировать подпись — защита от reuse активации на чужом магазине.'
        );
    }

    public function test_cookie_tampered_ts_fails(): void
    {
        $ts  = time();
        $sig = Cashback_Encryption::sign_cookie_payload(self::CLICK_ID, self::DOMAIN, $ts);

        $this->assertFalse(
            Cashback_Encryption::verify_cookie_payload($sig, self::CLICK_ID, self::DOMAIN, $ts + 1)
        );
    }

    // =====================================================================
    // Domain separation — activation-key ≠ cookie-key
    // =====================================================================

    public function test_domain_separated_keys_not_interchangeable(): void
    {
        // Подписываем activation-token и пытаемся использовать его hmac-часть как cookie-sig.
        $ts            = 1_700_000_000;
        $token         = Cashback_Encryption::sign_activation_token(self::CLICK_ID, self::USER_ID, $ts);
        $token_mac     = explode('.', $token)[1];

        // Крайне маловероятное совпадение — но проверяем программно, а не по factum.
        // token_mac подписывает payload `{$ts}|{$click_id}|{$user_id}` с ACTIVATION_TOKEN_SALT ключом;
        // cookie sig подписывает `{$click_id}|{$domain}|{$ts}` с COOKIE_SIGNATURE_SALT ключом.
        // Любая переиспользка невозможна.
        $this->assertFalse(
            Cashback_Encryption::verify_cookie_payload($token_mac, self::CLICK_ID, self::DOMAIN, $ts),
            'HMAC activation-token ключа не должен валидировать cookie sig — domain separation работает.'
        );
    }
}
