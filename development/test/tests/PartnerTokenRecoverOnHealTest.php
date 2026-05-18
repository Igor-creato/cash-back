<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Финтех-инвариант: partner_token — vault-идентификатор, уже отправленный в
 * CPA в составе subid2. При пропавшей строке профиля (ручная чистка БД, гонка,
 * сбой user_register/wp_login хука) get_partner_token() ОБЯЗАН восстановить
 * прежний токен из durable-ссылки (cashback_click_log.affiliate_url), а НЕ
 * сгенерировать новый — иначе постбэки/сверки/транзакции перестают совпадать.
 *
 * Свежий токен допустим ТОЛЬКО когда durable-ссылок нет нигде (в CPA ничего не
 * ссылается на старый токен — сверка физически не может сломаться).
 *
 * @see plans/functional-petting-jellyfish.md Часть B (recover-on-heal)
 */
#[Group('partner-token')]
#[Group('fintech')]
final class PartnerTokenRecoverOnHealTest extends TestCase
{
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb = new class {
            public string $prefix = 'wp_';
            public string $users = 'wp_users';
            public string $last_error = '';
            public int $insert_id = 0;

            /** @var array<int,?string> user_id => partner_token (null = строка есть, токен NULL) */
            public array $profile = array();
            /** @var array<int,string> user_id => profile status */
            public array $profile_status = array();
            /** @var array<int,bool> user_id => есть строка баланса */
            public array $balance = array();
            /** @var list<array{id:int,user_id:int,affiliate_url:string}> */
            public array $clicks = array();
            /** @var array<int,bool> существующие WP user_id */
            public array $users_rows = array();

            public function suppress_errors(bool $s = true): bool { return false; }
            public function esc_like(string $t): string { return addcslashes($t, '_%\\'); }

            public function prepare(string $q, mixed ...$args): string
            {
                $flat = array();
                foreach ($args as $a) {
                    if (is_array($a)) { foreach ($a as $v) { $flat[] = $v; } } else { $flat[] = $a; }
                }
                $out = ''; $i = 0; $len = strlen($q); $idx = 0;
                while ($i < $len) {
                    if ($q[$i] === '%' && $i + 1 < $len) {
                        $spec = $q[$i + 1];
                        if (in_array($spec, array('d', 's', 'i', 'f'), true) && array_key_exists($idx, $flat)) {
                            $v = $flat[$idx];
                            if ($spec === 'd') { $out .= (string) (int) $v; }
                            elseif ($spec === 'i') { $out .= '`' . (string) $v . '`'; }
                            elseif ($spec === 'f') { $out .= (string) (float) $v; }
                            else { $out .= "'" . str_replace("'", "''", (string) $v) . "'"; }
                            $idx++; $i += 2; continue;
                        }
                    }
                    $out .= $q[$i]; $i++;
                }
                return $out;
            }

            public function get_var(string $q, int $col = 0, int $row = 0): mixed
            {
                // Reverse-resolve: SELECT user_id ... WHERE partner_token = 'TOK'
                if (preg_match('/SELECT\s+user_id\s+FROM.*partner_token\s*=\s*\'([0-9a-fA-F]+)\'/is', $q, $m)) {
                    foreach ($this->profile as $uid => $tok) {
                        if ($tok !== null && hash_equals((string) $tok, $m[1])) {
                            return (string) $uid;
                        }
                    }
                    return null;
                }
                // WP user existence: SELECT ID FROM `wp_users` WHERE ID = N
                if (preg_match('/SELECT\s+ID\s+FROM\s+`?wp_users`?\s+WHERE\s+ID\s*=\s*(\d+)/is', $q, $m)) {
                    $uid = (int) $m[1];
                    return isset($this->users_rows[$uid]) ? (string) $uid : null;
                }
                // COUNT(*) profile / balance
                if (preg_match('/COUNT\(\*\)\s+FROM\s+`?\w*cashback_user_(profile|balance)`?\s+WHERE\s+user_id\s*=\s*(\d+)/is', $q, $m)) {
                    $uid = (int) $m[2];
                    $bag = $m[1] === 'profile' ? $this->profile : $this->balance;
                    return array_key_exists($uid, $bag) ? '1' : '0';
                }
                // Fast path: SELECT partner_token FROM profile WHERE user_id = N
                if (preg_match('/SELECT\s+partner_token\s+FROM\s+`?\w*cashback_user_profile`?\s+WHERE\s+user_id\s*=\s*(\d+)/is', $q, $m)) {
                    $uid = (int) $m[1];
                    return $this->profile[$uid] ?? null;
                }
                // Profile status lookup
                if (preg_match('/SELECT\s+status\s+FROM\s+`?\w*cashback_user_profile`?\s+WHERE\s+user_id\s*=\s*(\d+)/is', $q, $m)) {
                    $uid = (int) $m[1];
                    if (!array_key_exists($uid, $this->profile)) {
                        return null;
                    }
                    return $this->profile_status[$uid] ?? 'active';
                }
                return null;
            }

            /** @return list<string> */
            public function get_col(string $q, int $x = 0): array
            {
                return $this->clickUrls($q);
            }

            /** @return list<object|array<string,mixed>> */
            public function get_results(string $q, string $output = 'OBJECT'): array
            {
                $urls = $this->clickUrls($q);
                if ($urls === array()) {
                    return array();
                }
                return array_map(
                    static fn(string $u) => (object) array('affiliate_url' => $u),
                    $urls
                );
            }

            /** Возвращает affiliate_url'ы юзера, новые → старые (ORDER BY id DESC). */
            private function clickUrls(string $q): array
            {
                if (!preg_match('/FROM\s+`?\w*cashback_click_log`?\s+WHERE\s+user_id\s*=\s*(\d+)/is', $q, $m)) {
                    return array();
                }
                $uid  = (int) $m[1];
                $rows = array_values(array_filter(
                    $this->clicks,
                    static fn(array $c): bool => $c['user_id'] === $uid
                ));
                usort($rows, static fn(array $a, array $b): int => $b['id'] <=> $a['id']);
                $limit  = null;
                $offset = 0;
                if (preg_match('/LIMIT\s+(\d+)/i', $q, $lm)) {
                    $limit = (int) $lm[1];
                }
                if (preg_match('/OFFSET\s+(\d+)/i', $q, $om)) {
                    $offset = (int) $om[1];
                }
                if ($offset > 0 || $limit !== null) {
                    $rows = array_slice($rows, $offset, $limit);
                }
                return array_map(static fn(array $c): string => $c['affiliate_url'], $rows);
            }

            public function query(string $q): int|bool
            {
                if (preg_match('/^\s*(START TRANSACTION|COMMIT|ROLLBACK)/i', $q)) {
                    return true;
                }
                // INSERT [IGNORE] INTO profile (user_id, partner_token, status, created_at)
                //   VALUES (N, 'TOK', 'active', ...)
                if (preg_match('/INSERT(?:\s+IGNORE)?\s+INTO\s+`?\w*cashback_user_profile`?.*VALUES\s*\(\s*(\d+)\s*,\s*\'([0-9a-fA-F]+)\'/is', $q, $m)) {
                    $uid = (int) $m[1];
                    if (array_key_exists($uid, $this->profile)) {
                        return 0; // IGNORE: строка уже есть
                    }
                    $this->profile[$uid] = $m[2];
                    $this->profile_status[$uid] = 'active';
                    $this->insert_id     = $uid;
                    return 1;
                }
                // INSERT [IGNORE] INTO balance ... VALUES (N, ...)
                if (preg_match('/INSERT(?:\s+IGNORE)?\s+INTO\s+`?\w*cashback_user_balance`?.*VALUES\s*\(\s*(\d+)/is', $q, $m)) {
                    $uid = (int) $m[1];
                    if (array_key_exists($uid, $this->balance)) {
                        return 0;
                    }
                    $this->balance[$uid] = true;
                    return 1;
                }
                // UPDATE profile SET partner_token='TOK' WHERE user_id=N AND partner_token IS NULL
                if (preg_match('/UPDATE\s+`?\w*cashback_user_profile`?\s+SET\s+partner_token\s*=\s*\'([0-9a-fA-F]+)\'\s+WHERE\s+user_id\s*=\s*(\d+)\s+AND\s+partner_token\s+IS\s+NULL/is', $q, $m)) {
                    $uid = (int) $m[2];
                    if (array_key_exists($uid, $this->profile) && $this->profile[$uid] === null) {
                        $this->profile[$uid] = $m[1];
                        return 1;
                    }
                    return 0;
                }
                return true;
            }
        };

        $GLOBALS['wpdb'] = $this->wpdb;
    }

    private function affiliateUrl(string $token): string
    {
        return 'https://ad.example.com/g/abc/?erid=zzz&subid1='
            . str_repeat('a', 32) . '&subid2=' . $token . '&extra=1';
    }

    /** 1. CORE: профиль пропал, но в click_log есть subid2 → вернуть РОВНО его. */
    public function test_recovers_exact_prior_token_from_click_log(): void
    {
        $known = '7f2c1763a0017fd3e98c822ba1296704';
        $this->wpdb->users_rows[1] = true;
        $this->wpdb->clicks = array(
            array('id' => 2072, 'user_id' => 1, 'affiliate_url' => $this->affiliateUrl($known)),
            array('id' => 2073, 'user_id' => 1, 'affiliate_url' => $this->affiliateUrl($known)),
        );

        $token = Mariadb_Plugin::get_partner_token(1);

        $this->assertSame($known, $token, 'must recover the exact prior token, never regenerate');
        $this->assertSame($known, $this->wpdb->profile[1] ?? null, 'profile row recreated with recovered token');
    }

    /** 2. SAFE-NEW: ссылок нет нигде → стабильный свежий токен (не unregistered). */
    public function test_creates_fresh_token_only_when_no_references_anywhere(): void
    {
        $this->wpdb->users_rows[42] = true; // реальный юзер, кликов нет

        $token = Mariadb_Plugin::get_partner_token(42);

        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $token);
        $this->assertSame($token, $this->wpdb->profile[42] ?? null, 'profile row created with the fresh token');
    }

    /** 3. GUEST/INVALID: гость и несуществующий WP-юзер → null, профиль НЕ создан. */
    public function test_guest_and_unknown_user_return_null_without_creating_profile(): void
    {
        $this->assertNull(Mariadb_Plugin::get_partner_token(0));

        // 999 нет в wp_users
        $this->assertNull(Mariadb_Plugin::get_partner_token(999));
        $this->assertArrayNotHasKey(999, $this->wpdb->profile);
    }

    /** 4. REGRESSION: существующий токен возвращается как есть, не трогается. */
    public function test_existing_token_returned_unchanged(): void
    {
        $existing = 'deadbeefdeadbeefdeadbeefdeadbeef';
        $this->wpdb->users_rows[7]  = true;
        $this->wpdb->profile[7]     = $existing;
        // Ссылка с ДРУГИМ токеном не должна повлиять на существующий профиль.
        $this->wpdb->clicks = array(
            array('id' => 10, 'user_id' => 7, 'affiliate_url' => $this->affiliateUrl(str_repeat('b', 32))),
        );

        $token = Mariadb_Plugin::get_partner_token(7);

        $this->assertSame($existing, $token, 'existing token must never be overwritten/regenerated');
        $this->assertSame($existing, $this->wpdb->profile[7]);
    }

    /** 5. COLLISION-SAFETY: кандидат принадлежит другому юзеру → не воровать. */
    public function test_does_not_steal_token_owned_by_another_user(): void
    {
        $shared = 'cafebabecafebabecafebabecafebabe';
        $this->wpdb->users_rows[1]  = true;
        $this->wpdb->users_rows[2]  = true;
        $this->wpdb->profile[2]     = $shared;          // токен уже принадлежит юзеру 2
        $this->wpdb->clicks = array(
            array('id' => 1, 'user_id' => 1, 'affiliate_url' => $this->affiliateUrl($shared)),
        );

        $token = Mariadb_Plugin::get_partner_token(1);

        $this->assertIsString($token);
        $this->assertNotSame($shared, $token, 'must not reassign a token owned by another user');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $token);
        $this->assertSame($shared, $this->wpdb->profile[2], 'other user token untouched');
    }

    /** 6. REGEX/PARSER: duplicate+uppercase subid2 → должен брать последний валидный параметр. */
    public function test_recovers_last_case_insensitive_subid2_when_duplicate_exists(): void
    {
        $victim = '0123456789abcdef0123456789abcdef';
        $known  = '7f2c1763a0017fd3e98c822ba1296704';
        $this->wpdb->users_rows[11] = true;
        $this->wpdb->users_rows[12] = true;
        $this->wpdb->profile[12]    = $victim;
        $this->wpdb->clicks = array(
            array(
                'id' => 501,
                'user_id' => 11,
                'affiliate_url' => 'https://ad.example.com/click?subid2=' . $victim . '&SUBID2=' . $known,
            ),
        );

        $token = Mariadb_Plugin::get_partner_token(11);

        $this->assertSame($known, $token, 'must prefer the last valid subid2 and accept case-insensitive key');
        $this->assertSame($known, $this->wpdb->profile[11] ?? null);
        $this->assertSame($victim, $this->wpdb->profile[12] ?? null);
    }

    /** 7. BOUNDARY: если валидный токен старее первых 50 кликов, восстановление всё равно должно его найти. */
    public function test_recovers_token_beyond_first_fifty_clicks(): void
    {
        $known = 'aaaaaaaa11111111bbbbbbbb22222222';
        $this->wpdb->users_rows[21] = true;

        $clicks = array();
        for ($i = 1; $i <= 75; $i++) {
            $clicks[] = array(
                'id' => $i,
                'user_id' => 21,
                'affiliate_url' => 'https://ad.example.com/click?foo=' . $i,
            );
        }
        $clicks[0]['affiliate_url'] = $this->affiliateUrl($known); // oldest row, outside initial LIMIT 50
        $this->wpdb->clicks = $clicks;

        $token = Mariadb_Plugin::get_partner_token(21);

        $this->assertSame($known, $token, 'must continue scanning older clicks until references are exhausted');
        $this->assertSame($known, $this->wpdb->profile[21] ?? null);
    }

    /** 8. STATUS-GATE: banned/deleted profile без токена не должен получать новый или recovered token. */
    public function test_banned_and_deleted_profiles_do_not_get_token_restored_or_generated(): void
    {
        $known = 'bbbbbbbb11111111cccccccc22222222';

        $this->wpdb->users_rows[31]     = true;
        $this->wpdb->profile[31]        = null;
        $this->wpdb->profile_status[31] = 'banned';
        $this->wpdb->clicks[] = array(
            'id' => 601,
            'user_id' => 31,
            'affiliate_url' => $this->affiliateUrl($known),
        );

        $this->wpdb->users_rows[32]     = true;
        $this->wpdb->profile[32]        = null;
        $this->wpdb->profile_status[32] = 'deleted';
        $this->wpdb->clicks[] = array(
            'id' => 602,
            'user_id' => 32,
            'affiliate_url' => $this->affiliateUrl($known),
        );

        $this->assertNull(Mariadb_Plugin::get_partner_token(31));
        $this->assertNull(Mariadb_Plugin::get_partner_token(32));
        $this->assertNull($this->wpdb->profile[31]);
        $this->assertNull($this->wpdb->profile[32]);
    }
}
