<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Universal dedup resolver — parity contract.
 *
 * Replays development/test/fixtures/dedup-vectors.json through the real
 * Cashback_API_Client::resolve_uniq_id(). The SAME fixture is replayed by the
 * Python mirror (F:/cash-back/webhook-resiver/tests/replay_dedup_vectors.py)
 * against app.identity.resolve_uniq_id(). expected_id/expected_reason are the
 * frozen contract — if PHP and Python ever diverge, one side fails here / there
 * (silent dup/loss is the failure mode this guards against).
 */
#[Group('split-order')]
final class DedupResolverParityTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/dedup-vectors.json';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../includes/class-cashback-api-client.php';
    }

    /**
     * @return iterable<string,array{0:array<string,mixed>}>
     */
    public static function vectorProvider(): iterable
    {
        $raw = file_get_contents(self::FIXTURE);
        if ($raw === false) {
            throw new RuntimeException('Cannot read fixture: ' . self::FIXTURE);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['vectors']) || !is_array($data['vectors'])) {
            throw new RuntimeException('Malformed fixture: ' . self::FIXTURE);
        }
        foreach ($data['vectors'] as $v) {
            yield (string) $v['name'] => array( $v );
        }
    }

    #[DataProvider('vectorProvider')]
    public function test_resolver_matches_frozen_contract(array $v): void
    {
        $dedup = $v['dedup_identity']; // null OR assoc array (json_decode assoc).

        [$id, $reason] = Cashback_API_Client::resolve_uniq_id(
            (string) $v['slug'],
            (string) $v['native_uniq_id'],
            (array) $v['fields'],
            $dedup === null ? null : (array) $dedup
        );

        $this->assertSame(
            $v['expected_id'],
            $id,
            "uniq_id mismatch for vector: {$v['name']}"
        );
        $this->assertSame(
            $v['expected_reason'],
            $reason,
            "reason mismatch for vector: {$v['name']}"
        );
    }

    public function test_fixture_covers_every_branch(): void
    {
        $raw  = file_get_contents(self::FIXTURE);
        $data = json_decode((string) $raw, true);
        $names = array_map(static fn($v): string => (string) $v['name'], $data['vectors']);
        $blob  = implode("\n", $names);

        // Guard the fixture itself stays representative across iterations.
        $this->assertStringContainsString('native passthrough', $blob);
        $this->assertStringContainsString('split sibling', $blob);
        $this->assertStringContainsString('re-postback resolves to SAME id', $blob);
        $this->assertStringContainsString('synthetic order-only', $blob);
        $this->assertStringContainsString('include_click_id true', $blob);
        $this->assertStringContainsString('all inputs empty => no_dedup_inputs', $blob);
        $this->assertStringContainsString('legacy null contract', $blob);
        $this->assertGreaterThanOrEqual(12, count($names), 'fixture must keep broad branch coverage');
    }
}
