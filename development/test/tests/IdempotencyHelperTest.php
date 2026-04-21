<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional-тесты для Cashback_Idempotency helper (Группа 5 ADR).
 *
 * Покрывают публичный контракт класса:
 *   - normalize_request_id(): UUID v4/v7 с дефисами и без → canonical lowercase-no-dashes.
 *   - claim(): атомарный захват слота (wp_cache_add primary + set_transient fallback).
 *   - store_result() / get_stored_result(): JSON-serializable round-trip.
 *   - forget(): полный сброс для ручного cleanup / при ROLLBACK.
 *
 * Между тестами очищаем in-memory backends bootstrap'а (wp_cache + transient).
 */
#[Group('idempotency')]
final class IdempotencyHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_cache']      = array();
        $GLOBALS['_cb_test_transients'] = array();

        if (!class_exists('Cashback_Idempotency')) {
            require_once dirname(__DIR__, 3) . '/includes/class-cashback-idempotency.php';
        }
    }

    // ────────────────────────────────────────────────────────────
    // normalize_request_id()
    // ────────────────────────────────────────────────────────────

    public function test_normalize_accepts_uuid_v4_with_dashes(): void
    {
        $raw        = 'A1B2C3D4-E5F6-4A7B-8C9D-E0F1A2B3C4D5';
        $normalized = Cashback_Idempotency::normalize_request_id($raw);

        $this->assertSame('a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5', $normalized);
    }

    public function test_normalize_accepts_uuid_v7_without_dashes(): void
    {
        $raw        = '018f1e2a3b4c7d8e9fa0b1c2d3e4f506';
        $normalized = Cashback_Idempotency::normalize_request_id($raw);

        $this->assertSame($raw, $normalized);
    }

    public function test_normalize_lowercases_uppercase_input(): void
    {
        $normalized = Cashback_Idempotency::normalize_request_id('ABCDEF0123456789ABCDEF0123456789');

        $this->assertSame('abcdef0123456789abcdef0123456789', $normalized);
    }

    public function test_normalize_rejects_non_uuid_strings(): void
    {
        $this->assertSame('', Cashback_Idempotency::normalize_request_id('not-a-uuid'));
        $this->assertSame('', Cashback_Idempotency::normalize_request_id(''));
        $this->assertSame('', Cashback_Idempotency::normalize_request_id('12345'));
        $this->assertSame('', Cashback_Idempotency::normalize_request_id('zzzzzzzz-zzzz-zzzz-zzzz-zzzzzzzzzzzz'));
    }

    public function test_normalize_rejects_too_short_hex(): void
    {
        // 31 hex-char — короче 32.
        $this->assertSame('', Cashback_Idempotency::normalize_request_id('a1b2c3d4e5f64a7b8c9de0f1a2b3c4d'));
    }

    // ────────────────────────────────────────────────────────────
    // claim() / store_result() / get_stored_result()
    // ────────────────────────────────────────────────────────────

    public function test_claim_first_call_succeeds(): void
    {
        $ok = Cashback_Idempotency::claim('test_scope', 42, 'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5');

        $this->assertTrue($ok);
    }

    public function test_claim_second_call_fails_for_same_key(): void
    {
        $req = 'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5';

        $first  = Cashback_Idempotency::claim('test_scope', 42, $req);
        $second = Cashback_Idempotency::claim('test_scope', 42, $req);

        $this->assertTrue($first);
        $this->assertFalse($second, 'Второй claim с тем же request_id должен вернуть false');
    }

    public function test_claim_different_scope_is_independent(): void
    {
        $req = 'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5';

        Cashback_Idempotency::claim('scope_a', 42, $req);
        $other = Cashback_Idempotency::claim('scope_b', 42, $req);

        $this->assertTrue($other, 'Scope-изоляция: разные scope не конфликтуют');
    }

    public function test_claim_different_user_is_independent(): void
    {
        $req = 'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5';

        Cashback_Idempotency::claim('test_scope', 42, $req);
        $other = Cashback_Idempotency::claim('test_scope', 99, $req);

        $this->assertTrue($other, 'User-изоляция: разные user_id не конфликтуют');
    }

    public function test_store_and_get_result_roundtrip(): void
    {
        $scope   = 'test_scope';
        $user_id = 42;
        $req     = 'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5';
        $result  = array( 'message' => 'ok', 'id' => 123 );

        Cashback_Idempotency::claim($scope, $user_id, $req);
        Cashback_Idempotency::store_result($scope, $user_id, $req, $result);

        $stored = Cashback_Idempotency::get_stored_result($scope, $user_id, $req);
        $this->assertSame($result, $stored);
    }

    public function test_get_stored_result_without_store_returns_null(): void
    {
        Cashback_Idempotency::claim('test_scope', 42, 'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5');

        $stored = Cashback_Idempotency::get_stored_result('test_scope', 42, 'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5');
        $this->assertNull($stored, 'claim без store_result → get возвращает null');
    }

    public function test_get_stored_result_for_unknown_key_returns_null(): void
    {
        $this->assertNull(
            Cashback_Idempotency::get_stored_result('unknown', 1, 'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5')
        );
    }

    public function test_forget_clears_claim_and_result(): void
    {
        $scope = 'test_scope';
        $user  = 42;
        $req   = 'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5';

        Cashback_Idempotency::claim($scope, $user, $req);
        Cashback_Idempotency::store_result($scope, $user, $req, array( 'message' => 'ok' ));

        Cashback_Idempotency::forget($scope, $user, $req);

        $this->assertNull(Cashback_Idempotency::get_stored_result($scope, $user, $req));
        $this->assertTrue(
            Cashback_Idempotency::claim($scope, $user, $req),
            'После forget() новый claim должен проходить'
        );
    }

    public function test_store_result_is_returned_for_retry_without_reclaim(): void
    {
        $scope  = 'test_scope';
        $user   = 42;
        $req    = 'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5';
        $result = array( 'payout_id' => 555, 'status' => 'paid' );

        // 1-й проход: claim + store
        Cashback_Idempotency::claim($scope, $user, $req);
        Cashback_Idempotency::store_result($scope, $user, $req, $result);

        // 2-й проход (retry): хендлер должен сначала проверить get_stored_result
        $stored = Cashback_Idempotency::get_stored_result($scope, $user, $req);
        $this->assertNotNull($stored);
        $this->assertSame($result, $stored);

        // Повторный claim() всё равно вернёт false (слот занят).
        $this->assertFalse(Cashback_Idempotency::claim($scope, $user, $req));
    }

    public function test_empty_request_id_is_not_acceptable_for_claim(): void
    {
        $ok = Cashback_Idempotency::claim('test_scope', 42, '');

        $this->assertFalse($ok, 'Пустой request_id должен гарантированно отклоняться');
    }

    public function test_guest_user_id_zero_allows_claim_per_request_id(): void
    {
        $req = 'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5';

        $first  = Cashback_Idempotency::claim('guest_scope', 0, $req);
        $second = Cashback_Idempotency::claim('guest_scope', 0, $req);

        $this->assertTrue($first);
        $this->assertFalse($second);
    }
}
