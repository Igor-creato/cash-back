<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once dirname(__DIR__, 3) . '/antifraud/class-fraud-cluster-detector.php';

#[Group('fraud')]
class FraudClusterDetectorTest extends TestCase
{
    // ================================================================
    // normalize_email
    // ================================================================

    public static function email_provider(): array
    {
        return [
            'gmail dot trick'                  => ['i.v.a.n@gmail.com', 'ivan@gmail.com'],
            'gmail plus suffix'                => ['ivan+spam@gmail.com', 'ivan@gmail.com'],
            'googlemail dot+suffix'            => ['i.v.a.n+x@googlemail.com', 'ivan@gmail.com'],
            'gmail uppercase'                  => ['IvAn@GMAIL.com', 'ivan@gmail.com'],
            'yandex uppercase'                 => ['IVAN@YANDEX.RU', 'ivan@yandex.ru'],
            'yandex plus suffix'               => ['ivan+spam@yandex.ru', 'ivan@yandex.ru'],
            'yandex dots significant'          => ['i.v.a.n@yandex.ru', 'i.v.a.n@yandex.ru'],
            'mail.ru plus only'                => ['user+x@mail.ru', 'user@mail.ru'],
            'invalid stays as-is (lowercased)' => ['invalid-email', 'invalid-email'],
        ];
    }

    #[DataProvider('email_provider')]
    public function test_normalize_email(string $input, string $expected): void
    {
        $this->assertSame($expected, Cashback_Fraud_Cluster_Detector::normalize_email($input));
    }

    // ================================================================
    // normalize_phone
    // ================================================================

    public static function phone_provider(): array
    {
        return [
            'RU 8-prefix → +7'      => ['8 (900) 123-45-67', '+79001234567'],
            'RU 7-prefix bare'      => ['79001234567', '+79001234567'],
            'RU with +7'            => ['+7 (900) 123-45-67', '+79001234567'],
            'plus kept as-is'       => ['+44 20 7946 0958', '+442079460958'],
            'short stays digits'    => ['12345', '12345'],
            'empty'                 => ['', ''],
            'only formatting'       => ['---', ''],
        ];
    }

    #[DataProvider('phone_provider')]
    public function test_normalize_phone(string $input, string $expected): void
    {
        $this->assertSame($expected, Cashback_Fraud_Cluster_Detector::normalize_phone($input));
    }

    // ================================================================
    // compute_cluster_score
    // ================================================================

    public function test_score_single_device(): void
    {
        $score = Cashback_Fraud_Cluster_Detector::compute_cluster_score([
            ['reason' => 'device_id', 'value' => 'd1'],
        ]);
        $this->assertSame(30.0, $score);
    }

    public function test_score_device_plus_payment(): void
    {
        $score = Cashback_Fraud_Cluster_Detector::compute_cluster_score([
            ['reason' => 'device_id', 'value' => 'd1'],
            ['reason' => 'payment_hash', 'value' => 'h1'],
        ]);
        $this->assertSame(60.0, $score);
    }

    public function test_score_device_payment_phone(): void
    {
        $score = Cashback_Fraud_Cluster_Detector::compute_cluster_score([
            ['reason' => 'device_id', 'value' => 'd1'],
            ['reason' => 'payment_hash', 'value' => 'h1'],
            ['reason' => 'phone', 'value' => '+79001234567'],
        ]);
        $this->assertSame(85.0, $score);
    }

    public function test_score_caps_at_100(): void
    {
        $score = Cashback_Fraud_Cluster_Detector::compute_cluster_score([
            ['reason' => 'device_id', 'value' => 'd1'],
            ['reason' => 'visitor_id', 'value' => 'v1'],
            ['reason' => 'payment_hash', 'value' => 'h1'],
            ['reason' => 'phone', 'value' => 'p1'],
            ['reason' => 'email_normalized', 'value' => 'e1'],
            ['reason' => 'fingerprint_hash', 'value' => 'f1'],
        ]);
        $this->assertSame(100.0, $score);
    }

    public function test_score_repeated_value_half_strength(): void
    {
        // Два разных device_id в одном кластере: 30 + 15 = 45
        $score = Cashback_Fraud_Cluster_Detector::compute_cluster_score([
            ['reason' => 'device_id', 'value' => 'd1'],
            ['reason' => 'device_id', 'value' => 'd2'],
        ]);
        $this->assertSame(45.0, $score);
    }

    public function test_score_unknown_reason_ignored(): void
    {
        $score = Cashback_Fraud_Cluster_Detector::compute_cluster_score([
            ['reason' => 'made_up_signal', 'value' => 'x'],
        ]);
        $this->assertSame(0.0, $score);
    }

    // ================================================================
    // generate_cluster_uid — детерминистичность
    // ================================================================

    public function test_uid_deterministic_regardless_of_order(): void
    {
        $a = Cashback_Fraud_Cluster_Detector::generate_cluster_uid([10, 20, 30]);
        $b = Cashback_Fraud_Cluster_Detector::generate_cluster_uid([30, 10, 20]);
        $c = Cashback_Fraud_Cluster_Detector::generate_cluster_uid([20, 30, 10]);
        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
    }

    public function test_uid_different_for_different_users(): void
    {
        $a = Cashback_Fraud_Cluster_Detector::generate_cluster_uid([1, 2]);
        $b = Cashback_Fraud_Cluster_Detector::generate_cluster_uid([1, 3]);
        $this->assertNotSame($a, $b);
    }

    public function test_uid_format_is_uuid_like(): void
    {
        $uid = Cashback_Fraud_Cluster_Detector::generate_cluster_uid([1, 2, 3]);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-8[0-9a-f]{3}-[0-9a-f]{12}$/',
            $uid
        );
    }

    public function test_uid_dedupes_user_ids(): void
    {
        $a = Cashback_Fraud_Cluster_Detector::generate_cluster_uid([1, 2, 2, 3]);
        $b = Cashback_Fraud_Cluster_Detector::generate_cluster_uid([1, 2, 3]);
        $this->assertSame($a, $b);
    }

    // ================================================================
    // union_find_clusters
    // ================================================================

    public function test_union_find_two_disconnected_components(): void
    {
        $edges = [
            ['user_a' => 1, 'user_b' => 2, 'reason' => 'device_id', 'value' => 'd1'],
            ['user_a' => 3, 'user_b' => 4, 'reason' => 'device_id', 'value' => 'd2'],
        ];
        $groups = Cashback_Fraud_Cluster_Detector::union_find_clusters($edges);
        $this->assertCount(2, $groups);

        $sorted = array_map(static fn(array $g) => $g, $groups);
        usort($sorted, static fn(array $a, array $b) => $a[0] <=> $b[0]);
        $this->assertSame([1, 2], $sorted[0]);
        $this->assertSame([3, 4], $sorted[1]);
    }

    public function test_union_find_transitive_closure(): void
    {
        // 1—2 (device), 2—3 (email), 3—4 (phone) → один кластер {1,2,3,4}
        $edges = [
            ['user_a' => 1, 'user_b' => 2, 'reason' => 'device_id', 'value' => 'd1'],
            ['user_a' => 2, 'user_b' => 3, 'reason' => 'email_normalized', 'value' => 'e1'],
            ['user_a' => 3, 'user_b' => 4, 'reason' => 'phone', 'value' => 'p1'],
        ];
        $groups = Cashback_Fraud_Cluster_Detector::union_find_clusters($edges);
        $this->assertCount(1, $groups);
        $this->assertSame([1, 2, 3, 4], $groups[0]);
    }

    public function test_union_find_skips_singletons(): void
    {
        // Самосоединение и valid edges одновременно
        $edges = [
            ['user_a' => 1, 'user_b' => 1, 'reason' => 'device_id', 'value' => 'd1'],
            ['user_a' => 2, 'user_b' => 3, 'reason' => 'device_id', 'value' => 'd2'],
        ];
        $groups = Cashback_Fraud_Cluster_Detector::union_find_clusters($edges);
        $this->assertCount(1, $groups);
        $this->assertSame([2, 3], $groups[0]);
    }

    public function test_union_find_empty_edges(): void
    {
        $this->assertSame([], Cashback_Fraud_Cluster_Detector::union_find_clusters([]));
    }

    public function test_union_find_dedupe_edges_in_same_group(): void
    {
        // Дубликаты рёбер не должны раздувать группу
        $edges = [
            ['user_a' => 1, 'user_b' => 2, 'reason' => 'device_id', 'value' => 'd1'],
            ['user_a' => 1, 'user_b' => 2, 'reason' => 'device_id', 'value' => 'd1'],
            ['user_a' => 2, 'user_b' => 3, 'reason' => 'phone', 'value' => 'p1'],
        ];
        $groups = Cashback_Fraud_Cluster_Detector::union_find_clusters($edges);
        $this->assertCount(1, $groups);
        $this->assertSame([1, 2, 3], $groups[0]);
    }
}
