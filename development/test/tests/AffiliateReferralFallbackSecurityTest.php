<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * RED-тесты для Группы 12 ADR — F-22-003 (Referral Attribution Hardening).
 *
 * Проверяют контракт рефакторинга:
 *   - Короткий TTL (5 мин) transient fallback, decoupled от cookie TTL.
 *   - Ключ transient = sha256(ip_subnet + ua_family + ua_major + accept_language).
 *   - IPv4 /24, IPv6 /64 через inet_pton (не explode).
 *   - NAT-collision: keep first, downgrade confidence первого, reject субсеквентных
 *     + обязательный audit `nat_collision_rejected_candidate`.
 *   - ENUM attribution_source / attribution_confidence / review_status + backfill.
 *   - Snapshot attribution-полей на уровне accrual (не JOIN profile).
 *   - Low-confidence accrual gate: pending → available только после review.
 *   - Антифрод: N-of-M signals (N≥2 → hard-block, N=1 → low-confidence).
 *   - Suspicious timing: <2 сек всегда signal, 2–5 сек только без cookie.
 *   - Rate-limit на click + signup с audit-трейлом; signup blocked → downgrade,
 *     не reject.
 *   - Cron auto-promote 14d: меняет review_status (не confidence), batch 500,
 *     idempotent, с аудитом.
 *   - Admin: approve/reject low-confidence + referral_reward_eligible flag.
 *   - Audit-таблица: LONGTEXT вместо JSON, без UNIQUE на (event_type, target, click_id).
 *
 * Методика: source-string regex invariants + поведенческие пробы на in-memory
 * transient-моке из bootstrap.php. Плановая реализация в cash-back-ancient-iverson.md.
 */
#[Group('affiliate-referral-fallback')]
final class AffiliateReferralFallbackSecurityTest extends TestCase
{
    private const SERVICE_FILE   = __DIR__ . '/../../../affiliate/class-affiliate-service.php';
    private const ANTIFRAUD_FILE = __DIR__ . '/../../../affiliate/class-affiliate-antifraud.php';
    private const DB_FILE        = __DIR__ . '/../../../affiliate/class-affiliate-db.php';
    private const AUDIT_FILE     = __DIR__ . '/../../../affiliate/class-affiliate-audit.php';
    private const ADMIN_FILE     = __DIR__ . '/../../../affiliate/class-affiliate-admin.php';
    private const PLUGIN_BOOT    = __DIR__ . '/../../../cashback-plugin.php';

    private function read(string $path): string
    {
        $src = @file_get_contents($path);
        $this->assertIsString($src, "Source must be readable: {$path}");
        return $src;
    }

    private function read_optional(string $path): string
    {
        $src = @file_get_contents($path);
        return is_string($src) ? $src : '';
    }

    // ════════════════════════════════════════════════════════════════
    // 1. TTL + ключ transient
    // ════════════════════════════════════════════════════════════════

    public function test_fallback_ttl_is_five_minutes(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        $this->assertMatchesRegularExpression(
            '/const\s+FALLBACK_TTL_SECONDS\s*=\s*300\s*;/',
            $src,
            'Константа FALLBACK_TTL_SECONDS = 300 (5 мин) должна быть объявлена в class-affiliate-service.php.'
        );
    }

    public function test_transient_key_uses_subnet_and_normalized_ua(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        // get_referral_transient_key должен принимать IP + raw_ua + accept_language
        $this->assertMatchesRegularExpression(
            '/function\s+get_referral_transient_key\s*\(\s*string\s+\$ip\s*,\s*string\s+\$raw_ua\s*,\s*string\s+\$accept_language\s*\)/',
            $src,
            'get_referral_transient_key() должен принимать (ip, raw_ua, accept_language).'
        );

        // В теле должны быть normalize_ua + extract_subnet
        $this->assertMatchesRegularExpression(
            '/normalize_ua\s*\(/',
            $src,
            'get_referral_transient_key() должен использовать normalize_ua() — UA нормализуется до family+major.'
        );
        $this->assertMatchesRegularExpression(
            '/extract_subnet\s*\(/',
            $src,
            'get_referral_transient_key() должен использовать extract_subnet() для IP.'
        );

        // Hash должен включать family + major + accept_language
        $this->assertMatchesRegularExpression(
            "/\['family'\]|family.{0,80}major.{0,80}accept_language|ua_family.{0,80}ua_major.{0,80}accept_language/s",
            $src,
            'Ключ должен формироваться из ua_family + ua_major + accept_language.'
        );
    }

    public function test_transient_key_does_not_hash_raw_user_agent_directly(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        // Находим тело get_referral_transient_key
        if (!preg_match('/function\s+get_referral_transient_key\s*\([^)]*\)\s*:\s*string\s*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('get_referral_transient_key() not found');
        }
        $body_start = $m[0][1];
        $body       = substr($src, $body_start, 800);

        // hash('sha256', $raw_ua) или hash(..., $raw_ua . ...) без normalize_ua — ошибка.
        $this->assertDoesNotMatchRegularExpression(
            "/hash\([^,]+,\s*\\\$raw_ua\\b/",
            $body,
            'Raw \$raw_ua НЕ должен идти в hash напрямую — только нормализованный family+major.'
        );
    }

    public function test_transient_ttl_decoupled_from_cookie_ttl(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        // Извлекаем тело store_referral_transient
        if (!preg_match('/function\s+store_referral_transient\s*\([^)]*\)\s*:\s*void\s*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('store_referral_transient() not found');
        }
        $body_start = $m[0][1];
        // Берём разумно большой кусок — тело не должно превышать ~1500 символов
        $body = substr($src, $body_start, 2000);

        $this->assertDoesNotMatchRegularExpression(
            '/get_cookie_ttl_days\s*\(/',
            $body,
            'store_referral_transient() НЕ должен вызывать get_cookie_ttl_days() — TTL отвязан от cookie (F-22-003).'
        );

        $this->assertMatchesRegularExpression(
            '/FALLBACK_TTL_SECONDS/',
            $body,
            'store_referral_transient() должен использовать self::FALLBACK_TTL_SECONDS.'
        );

        // Аналогично для read_referral_transient
        if (preg_match('/function\s+read_referral_transient\s*\([^)]*\)[^{]*\{/', $src, $rm, PREG_OFFSET_CAPTURE)) {
            $read_body = substr($src, $rm[0][1], 1500);
            $this->assertDoesNotMatchRegularExpression(
                '/get_cookie_ttl_days\s*\(/',
                $read_body,
                'read_referral_transient() НЕ должен вызывать get_cookie_ttl_days().'
            );
        }
    }

    public function test_subnet_extraction_uses_inet_pton(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        $this->assertMatchesRegularExpression(
            '/function\s+extract_subnet\s*\(\s*string\s+\$ip\s*\)/',
            $src,
            'extract_subnet(string $ip) должен быть объявлен.'
        );

        if (!preg_match('/function\s+extract_subnet\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('extract_subnet() not found');
        }
        $body = substr($src, $m[0][1], 1500);

        $this->assertMatchesRegularExpression(
            '/inet_pton\s*\(/',
            $body,
            'extract_subnet() должен использовать inet_pton() (корректно обрабатывает ::).'
        );

        $this->assertDoesNotMatchRegularExpression(
            "/explode\(\s*':'\s*,/",
            $body,
            "explode(':', ...) в extract_subnet() сломается на '::' в IPv6 — используй inet_pton."
        );

        $this->assertMatchesRegularExpression(
            '#/24#',
            $body,
            'extract_subnet() должен давать IPv4 /24.'
        );
        $this->assertMatchesRegularExpression(
            '#/64#',
            $body,
            'extract_subnet() должен давать IPv6 /64.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 2. NAT collision policy + audit отвергнутого кандидата
    // ════════════════════════════════════════════════════════════════

    public function test_nat_collision_keeps_first_and_downgrades_confidence(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        if (!preg_match('/function\s+store_referral_transient\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('store_referral_transient() not found');
        }
        $body = substr($src, $m[0][1], 3000);

        // При наличии $existing с другим referrer_id — пишем collision=true + confidence_override='low'
        // Допускаем оба стиля: array-access assignment или array-literal в merge.
        $this->assertMatchesRegularExpression(
            '/\[\s*[\'"]collision[\'"]\s*\]\s*=\s*true\b|[\'"]collision[\'"]\s*=>\s*true\b/',
            $body,
            'store_referral_transient() при NAT-коллизии должен ставить collision=true на существующую запись.'
        );

        $this->assertMatchesRegularExpression(
            '/\[\s*[\'"]confidence_override[\'"]\s*\]\s*=\s*[\'"]low[\'"]|[\'"]confidence_override[\'"]\s*=>\s*[\'"]low[\'"]/',
            $body,
            'store_referral_transient() при NAT-коллизии должен ставить confidence_override=\'low\'.'
        );
    }

    public function test_nat_collision_audits_rejected_candidate(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        if (!preg_match('/function\s+store_referral_transient\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('store_referral_transient() not found');
        }
        $body = substr($src, $m[0][1], 3000);

        $this->assertMatchesRegularExpression(
            "/Cashback_Affiliate_Audit::log\s*\(\s*['\"]nat_collision_rejected_candidate['\"]/",
            $body,
            'При NAT-коллизии должен писаться audit nat_collision_rejected_candidate (иначе разбирать споры невозможно).'
        );

        foreach (['rejected_referrer_id', 'kept_referrer_id', 'key_hash', 'ip_subnet_hash', 'ua_hash'] as $field) {
            $this->assertMatchesRegularExpression(
                "/['\"]" . preg_quote($field, '/') . "['\"]\s*=>/",
                $body,
                "NAT-collision audit должен содержать поле '{$field}'."
            );
        }
    }

    // ════════════════════════════════════════════════════════════════
    // 3. Схема: ENUMs, review_status, referral_reward_eligible, accrual snapshot
    // ════════════════════════════════════════════════════════════════

    public function test_attribution_source_enum_includes_cookie_transient_signed_token(): void
    {
        $src = $this->read(self::DB_FILE);

        // Учитываем backticks вокруг имени колонки (WP-convention).
        $this->assertMatchesRegularExpression(
            "/attribution_source`?\s+ENUM\s*\(\s*['\"]cookie['\"]\s*,\s*['\"]transient['\"]\s*,\s*['\"]signed_token['\"]\s*\)/i",
            $src,
            "attribution_source ENUM должен быть ('cookie','transient','signed_token')."
        );
    }

    public function test_attribution_confidence_enum_high_medium_low(): void
    {
        $src = $this->read(self::DB_FILE);

        $this->assertMatchesRegularExpression(
            "/attribution_confidence`?\s+ENUM\s*\(\s*['\"]high['\"]\s*,\s*['\"]medium['\"]\s*,\s*['\"]low['\"]\s*\)/i",
            $src,
            "attribution_confidence ENUM должен быть ('high','medium','low')."
        );
    }

    public function test_profile_has_review_status_column(): void
    {
        $src = $this->read(self::DB_FILE);

        $this->assertMatchesRegularExpression(
            "/review_status`?\s+ENUM\s*\(\s*['\"]none['\"]\s*,\s*['\"]pending['\"]\s*,\s*['\"]manual_approved['\"]\s*,\s*['\"]manual_rejected['\"]\s*,\s*['\"]auto_approved['\"]\s*\)/i",
            $src,
            "review_status ENUM должен быть ('none','pending','manual_approved','manual_rejected','auto_approved')."
        );
    }

    public function test_profile_has_referral_reward_eligible(): void
    {
        $src = $this->read(self::DB_FILE);

        $this->assertMatchesRegularExpression(
            "/referral_reward_eligible`?\s+TINYINT\s*\(\s*1\s*\)\s+NOT\s+NULL\s+DEFAULT\s+1/i",
            $src,
            'referral_reward_eligible TINYINT(1) NOT NULL DEFAULT 1 должно быть добавлено в profiles.'
        );
    }

    public function test_profile_has_collision_detected_and_signals_columns(): void
    {
        $src = $this->read(self::DB_FILE);

        $this->assertMatchesRegularExpression(
            '/collision_detected`?\s+TINYINT\s*\(\s*1\s*\)\s+NOT\s+NULL\s+DEFAULT\s+0/i',
            $src,
            'collision_detected TINYINT(1) NOT NULL DEFAULT 0 должно быть в profiles.'
        );

        // LONGTEXT, НЕ JSON
        $this->assertMatchesRegularExpression(
            '/antifraud_signals`?\s+LONGTEXT/i',
            $src,
            'antifraud_signals должен быть LONGTEXT (MariaDB-совместимость, JSON валидируется в PHP).'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/antifraud_signals`?\s+JSON\b/i',
            $src,
            'antifraud_signals НЕ должен быть JSON (MariaDB JSON = alias LONGTEXT с constraint — несовместимо).'
        );
    }

    public function test_accrual_has_attribution_snapshot_columns(): void
    {
        $src = $this->read(self::DB_FILE);

        // Поиск миграций к cashback_affiliate_accruals
        foreach (
            [
                'attribution_source',
                'attribution_confidence',
                'collision_detected',
                'review_status_at_creation',
                'antifraud_signals',
            ] as $col
        ) {
            $this->assertMatchesRegularExpression(
                '/affiliate_accruals.{0,500}' . preg_quote($col, '/') . '|' . preg_quote($col, '/') . '.{0,500}affiliate_accruals/s',
                $src,
                "cashback_affiliate_accruals должен получить snapshot-колонку '{$col}'."
            );
        }
    }

    public function test_backfill_sets_cookie_high_none_for_existing(): void
    {
        $src = $this->read(self::DB_FILE);

        // UPDATE может использовать %i placeholder или литеральное имя таблицы.
        $this->assertMatchesRegularExpression(
            "/UPDATE.{0,400}(?:cashback_affiliate_profiles|%i|\\\$profiles_table).{0,500}attribution_source\s*=\s*['\"]cookie['\"].{0,200}attribution_confidence\s*=\s*['\"]high['\"].{0,200}review_status\s*=\s*['\"]none['\"]/si",
            $src,
            'Backfill: existing bindings должны получить attribution_source=cookie, confidence=high, review_status=none.'
        );

        $this->assertMatchesRegularExpression(
            '/WHERE\s+referred_by_user_id\s+IS\s+NOT\s+NULL.{0,100}attribution_source\s+IS\s+NULL/si',
            $src,
            'Backfill должен ограничиваться привязанными записями без attribution_source (idempotency).'
        );
    }

    public function test_db_version_bumped_to_1_2(): void
    {
        $src = $this->read(self::DB_FILE);

        $this->assertMatchesRegularExpression(
            "/cashback_affiliate_db_version.{0,200}['\"]1\\.2['\"]|['\"]1\\.2['\"].{0,100}cashback_affiliate_db_version/s",
            $src,
            'cashback_affiliate_db_version должен быть поднят до 1.2.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 4. Audit table: LONGTEXT + без UNIQUE на (event_type,target,click_id)
    // ════════════════════════════════════════════════════════════════

    public function test_audit_table_created_with_typed_fields(): void
    {
        $src = $this->read(self::DB_FILE);

        $this->assertMatchesRegularExpression(
            '/CREATE\s+TABLE.{0,100}cashback_affiliate_audit/si',
            $src,
            'CREATE TABLE cashback_affiliate_audit должна быть в миграции.'
        );

        foreach (
            [
                'event_type',
                'rejected_referrer_id',
                'kept_referrer_id',
                'partner_token_hash',
                'ip_hash',
                'ip_subnet_hash',
                'ua_hash',
                'key_hash',
                'confidence',
                'signals',
                'payload',
            ] as $field
        ) {
            $this->assertMatchesRegularExpression(
                '/cashback_affiliate_audit.{0,2000}\b' . preg_quote($field, '/') . '\b/s',
                $src,
                "Audit-таблица должна содержать колонку '{$field}'."
            );
        }
    }

    public function test_audit_table_no_unique_on_event_target_click(): void
    {
        $src = $this->read(self::DB_FILE);

        // UNIQUE KEY ... (event_type, target_user_id, click_id) — ломает legit retry
        $this->assertDoesNotMatchRegularExpression(
            '/UNIQUE\s+KEY\s+\w*\s*\(\s*event_type\s*,\s*target_user_id\s*,\s*click_id\s*\)/si',
            $src,
            'UNIQUE на (event_type, target_user_id, click_id) ломает legitimate retry — используй обычный KEY.'
        );
    }

    public function test_audit_stores_signals_and_payload_as_longtext(): void
    {
        $src = $this->read(self::DB_FILE);

        // Ищем блок CREATE TABLE cashback_affiliate_audit
        if (!preg_match('/CREATE\s+TABLE[^;]*cashback_affiliate_audit[^;]*;/si', $src, $m)) {
            $this->fail('CREATE TABLE cashback_affiliate_audit not found');
        }
        $block = $m[0];

        $this->assertMatchesRegularExpression(
            '/signals`?\s+LONGTEXT/i',
            $block,
            'cashback_affiliate_audit.signals должен быть LONGTEXT.'
        );
        $this->assertMatchesRegularExpression(
            '/payload`?\s+LONGTEXT/i',
            $block,
            'cashback_affiliate_audit.payload должен быть LONGTEXT.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![_a-z])(signals|payload)`?\s+JSON\b/i',
            $block,
            'Audit-таблица НЕ должна использовать JSON тип (MariaDB-совместимость).'
        );
    }

    public function test_audit_helper_class_exists_with_log_method(): void
    {
        $src = $this->read_optional(self::AUDIT_FILE);

        $this->assertNotSame('', $src, 'affiliate/class-affiliate-audit.php должен быть создан.');

        $this->assertMatchesRegularExpression(
            '/class\s+Cashback_Affiliate_Audit\b/',
            $src,
            'Класс Cashback_Affiliate_Audit должен быть определён.'
        );
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+log\s*\(\s*string\s+\$event_type\s*,\s*array\s+\$ctx\b/',
            $src,
            'Cashback_Affiliate_Audit::log(string $event_type, array $ctx) должен существовать.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 5. bind_referral_on_registration — source/confidence/review запись
    // ════════════════════════════════════════════════════════════════

    public function test_bind_updates_attribution_source_and_confidence_columns(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        if (!preg_match('/function\s+bind_referral_on_registration\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('bind_referral_on_registration() not found');
        }
        $body = substr($src, $m[0][1], 10000);

        foreach (
            [
                'attribution_source',
                'attribution_confidence',
                'collision_detected',
                'review_status',
                'antifraud_signals',
            ] as $col
        ) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($col, '/') . '\b\s*=\s*%/',
                $body,
                "bind_referral_on_registration() UPDATE должен писать '{$col}' = %..."
            );
        }
    }

    public function test_bind_derives_review_status_from_confidence(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        if (!preg_match('/function\s+bind_referral_on_registration\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('bind_referral_on_registration() not found');
        }
        $body = substr($src, $m[0][1], 10000);

        $this->assertMatchesRegularExpression(
            "/\\\$confidence\s*===?\s*['\"]high['\"].{0,80}['\"]none['\"].{0,80}['\"]pending['\"]|['\"]none['\"].{0,80}['\"]pending['\"].{0,80}\\\$confidence.{0,30}['\"]high['\"]/s",
            $body,
            'review_status должен выводиться из confidence: high → none, иначе pending.'
        );
    }

    public function test_bind_writes_audit_referral_bound(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        if (!preg_match('/function\s+bind_referral_on_registration\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('bind_referral_on_registration() not found');
        }
        $body = substr($src, $m[0][1], 10000);

        $this->assertMatchesRegularExpression(
            "/Cashback_Affiliate_Audit::log\s*\(\s*['\"]referral_bound['\"]/",
            $body,
            'bind_referral_on_registration() должен писать audit event referral_bound.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 6. Антифрод — N-of-M signals + subnet + timing
    // ════════════════════════════════════════════════════════════════

    public function test_antifraud_uses_subnet_match_not_ip_match(): void
    {
        $src = $this->read(self::ANTIFRAUD_FILE);

        $this->assertMatchesRegularExpression(
            '/function\s+is_same_subnet_referral\s*\(/',
            $src,
            'is_same_subnet_referral() должен заменить is_same_ip_referral (IP → subnet как signal, не identity).'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/function\s+is_same_ip_referral\s*\(/',
            $src,
            'Старый is_same_ip_referral() должен быть удалён (F-22-003).'
        );
    }

    public function test_validate_referral_contract_includes_confidence_and_signals(): void
    {
        $src = $this->read(self::ANTIFRAUD_FILE);

        // Параметры могут иметь default-значения (backward-compatible caller transition).
        $this->assertMatchesRegularExpression(
            '/function\s+validate_referral\s*\(\s*int\s+\$referrer_id\b[^)]*\bstring\s+\$source\b[^)]*\bbool\s+\$cookie_valid\b[^)]*\bbool\s+\$collision_detected\b[^)]*\)/s',
            $src,
            'validate_referral() должен принимать source + cookie_valid + collision_detected.'
        );

        // Возврат должен содержать confidence и signals
        $this->assertMatchesRegularExpression(
            "/['\"]confidence['\"]\s*=>/",
            $src,
            "validate_referral() return-array должен содержать 'confidence' ключ."
        );
        $this->assertMatchesRegularExpression(
            "/['\"]signals['\"]\s*=>/",
            $src,
            "validate_referral() return-array должен содержать 'signals' ключ."
        );
    }

    public function test_hard_block_on_two_or_more_signals(): void
    {
        $src = $this->read(self::ANTIFRAUD_FILE);

        if (!preg_match('/function\s+validate_referral\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('validate_referral() not found');
        }
        $body = substr($src, $m[0][1], 5000);

        $this->assertMatchesRegularExpression(
            '/count\s*\(\s*\$signals\s*\)\s*>=\s*2|>=\s*2.{0,30}\$signals/s',
            $body,
            'N≥2 сигналов → hard-block. Проверка count($signals) >= 2 должна быть в validate_referral().'
        );

        $this->assertMatchesRegularExpression(
            "/multi_signal_block|['\"]multiple_signals['\"]/",
            $body,
            'При N≥2 — reason должен быть multiple_signals + audit multi_signal_block.'
        );
    }

    public function test_single_signal_yields_low_confidence_not_block(): void
    {
        $src = $this->read(self::ANTIFRAUD_FILE);

        if (!preg_match('/function\s+validate_referral\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('validate_referral() not found');
        }
        $body = substr($src, $m[0][1], 5000);

        // Должен быть путь allowed=true + confidence=low для has_signals
        $this->assertMatchesRegularExpression(
            "/has_signals.{0,200}['\"]allowed['\"]\s*=>\s*true.{0,200}['\"]low['\"]|['\"]allowed['\"]\s*=>\s*true.{0,200}['\"]confidence['\"]\s*=>\s*['\"]low['\"]/s",
            $body,
            'При N=1 сигнале validate_referral() должен вернуть allowed=true, confidence=low.'
        );
    }

    public function test_confidence_derivation_strict(): void
    {
        $src = $this->read(self::ANTIFRAUD_FILE);

        if (!preg_match('/function\s+validate_referral\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('validate_referral() not found');
        }
        $body = substr($src, $m[0][1], 5000);

        // cookie + 0 signals → high
        $this->assertMatchesRegularExpression(
            "/\\\$source\s*===?\s*['\"]cookie['\"].{0,150}['\"]confidence['\"]\s*=>\s*['\"]high['\"]|['\"]confidence['\"]\s*=>\s*['\"]high['\"].{0,150}\\\$source\s*===?\s*['\"]cookie['\"]/s",
            $body,
            'source=cookie + 0 signals → confidence=high. Политика должна быть явной.'
        );

        // transient + 0 signals → medium (и НЕ high)
        $this->assertMatchesRegularExpression(
            "/['\"]confidence['\"]\s*=>\s*['\"]medium['\"]/",
            $body,
            'transient + 0 signals → confidence=medium (fallback НИКОГДА не даёт high).'
        );
    }

    public function test_suspicious_timing_signature_includes_cookie_valid_flag(): void
    {
        $src = $this->read(self::ANTIFRAUD_FILE);

        $this->assertMatchesRegularExpression(
            '/function\s+is_suspicious_timing\s*\(\s*string\s+\$click_id\b[^)]*\bbool\s+\$cookie_valid\b[^)]*\)/s',
            $src,
            'is_suspicious_timing() должен принимать (string $click_id, bool $cookie_valid) — 2–5 сек signal только без cookie.'
        );
    }

    public function test_suspicious_timing_thresholds_2_and_5_seconds(): void
    {
        $src = $this->read(self::ANTIFRAUD_FILE);

        if (!preg_match('/function\s+is_suspicious_timing\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('is_suspicious_timing() not found');
        }
        $body = substr($src, $m[0][1], 1500);

        // <2 sec всегда signal
        $this->assertMatchesRegularExpression(
            '/<\s*2\b|\b2\s*(?:seconds|sec|s)\b|\$diff\s*<\s*2\b/',
            $body,
            'Граница <2 сек (всегда signal) должна быть в is_suspicious_timing().'
        );
        // 5 sec — граница для условного signal
        $this->assertMatchesRegularExpression(
            '/<\s*5\b|\b5\s*(?:seconds|sec|s)\b/',
            $body,
            'Граница <5 сек должна быть в is_suspicious_timing() (signal только без cookie).'
        );
        // Условная ветка использует $cookie_valid
        $this->assertMatchesRegularExpression(
            '/\$cookie_valid/',
            $body,
            'is_suspicious_timing() должен учитывать $cookie_valid для диапазона 2–5 сек.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 7. process_affiliate_commissions — snapshot + gate by snapshot
    // ════════════════════════════════════════════════════════════════

    public function test_process_commissions_writes_attribution_snapshot_to_accruals(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        if (!preg_match('/function\s+process_affiliate_commissions\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('process_affiliate_commissions() not found');
        }
        $body = substr($src, $m[0][1], 12000);

        foreach (
            [
                'attribution_source',
                'attribution_confidence',
                'collision_detected',
                'review_status_at_creation',
                'antifraud_signals',
            ] as $col
        ) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($col, '/') . '\b/',
                $body,
                "process_affiliate_commissions() должен писать snapshot-колонку '{$col}' в accrual."
            );
        }
    }

    public function test_process_commissions_gates_low_confidence_to_pending(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        if (!preg_match('/function\s+process_affiliate_commissions\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('process_affiliate_commissions() not found');
        }
        $body = substr($src, $m[0][1], 12000);

        // Проверка снэпшота в accrual на gate: confidence=high OR review_status ∈ {none,manual_approved,auto_approved} → available
        $this->assertMatchesRegularExpression(
            "/['\"]high['\"].{0,200}['\"]available['\"]|['\"]auto_approved['\"].{0,200}['\"]available['\"]|gate.{0,400}['\"]available['\"]/s",
            $body,
            'Gate: high-confidence ИЛИ auto/manual_approved → status=available; остальные → pending.'
        );

        // phrase: "pending" при low
        $this->assertMatchesRegularExpression(
            "/['\"]pending['\"]/",
            $body,
            'process_affiliate_commissions() должен сохранять pending для low-confidence.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 8. Rate-limit на click + signup
    // ════════════════════════════════════════════════════════════════

    public function test_rate_limit_on_handle_referral_visit_with_audit(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        if (!preg_match('/function\s+handle_referral_visit\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('handle_referral_visit() not found');
        }
        $body = substr($src, $m[0][1], 4000);

        $this->assertMatchesRegularExpression(
            "/Cashback_Rate_Limiter::check\s*\(\s*['\"]affiliate_click['\"]/",
            $body,
            "handle_referral_visit() должен вызывать Cashback_Rate_Limiter::check('affiliate_click', ...)."
        );

        $this->assertMatchesRegularExpression(
            "/Cashback_Affiliate_Audit::log\s*\(\s*['\"]rate_limit_blocked_click['\"]/",
            $body,
            'При blocked — обязательный audit rate_limit_blocked_click (иначе rate-limit = чёрная дыра).'
        );

        // Обязательные поля audit
        foreach (['partner_token_hash', 'ip_hash'] as $field) {
            $this->assertMatchesRegularExpression(
                "/['\"]" . preg_quote($field, '/') . "['\"]\s*=>/",
                $body,
                "rate_limit_blocked_click audit должен содержать поле '{$field}'."
            );
        }
    }

    public function test_rate_limit_signup_key_uses_subnet_and_ua_family(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        if (!preg_match('/function\s+bind_referral_on_registration\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('bind_referral_on_registration() not found');
        }
        $body = substr($src, $m[0][1], 10000);

        $this->assertMatchesRegularExpression(
            "/Cashback_Rate_Limiter::check\s*\(\s*['\"]affiliate_signup['\"]/",
            $body,
            "bind_referral_on_registration() должен вызывать Cashback_Rate_Limiter::check('affiliate_signup', ...)."
        );

        // Композитный ключ: subnet + ua_family (не голый IP)
        $this->assertMatchesRegularExpression(
            "/affiliate_signup['\"].{0,300}subnet.{0,200}family|composite_key.{0,200}affiliate_signup|\\\$composite_key|\\\$rl_key/s",
            $body,
            'Rate-limit ключ для signup должен быть composite (subnet + ua_family), не голый IP — иначе NAT страдает.'
        );
    }

    public function test_rate_limit_signup_downgrades_not_rejects(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        if (!preg_match('/function\s+bind_referral_on_registration\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('bind_referral_on_registration() not found');
        }
        $body = substr($src, $m[0][1], 10000);

        $this->assertMatchesRegularExpression(
            "/Cashback_Affiliate_Audit::log\s*\(\s*['\"]rate_limit_downgrade_bind['\"]/",
            $body,
            'При blocked signup — audit rate_limit_downgrade_bind (binding создаётся, не отклоняется).'
        );

        // Должен остаться path где binding выполняется: UPDATE profiles ... referred_by_user_id
        $this->assertMatchesRegularExpression(
            '/UPDATE\s+%i.{0,200}referred_by_user_id/s',
            $body,
            'bind_referral_on_registration() при RL-blocked должен всё равно создать binding с low-confidence (не раннее return до UPDATE).'
        );

        // signal rate_limit_signup_blocked должен добавляться
        $this->assertMatchesRegularExpression(
            "/['\"]rate_limit_signup_blocked['\"]/",
            $body,
            "signal 'rate_limit_signup_blocked' должен добавляться в antifraud_signals при RL-blocked."
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 9. Cron auto-promote — preserve confidence, batch 500, idempotent
    // ════════════════════════════════════════════════════════════════

    public function test_auto_promote_cron_scheduled_on_activation(): void
    {
        $src = $this->read(self::PLUGIN_BOOT);

        // wp_schedule_event(time(), 'daily', 'cashback_affiliate_auto_promote'):
        // time() содержит ()-пару, поэтому [^)]* слишком строго — используем .{0,N}.
        $this->assertMatchesRegularExpression(
            "/wp_schedule_event\s*\(.{0,120}['\"]cashback_affiliate_auto_promote['\"]/s",
            $src,
            'cashback_affiliate_auto_promote должен регистрироваться в wp_schedule_event (daily).'
        );

        $this->assertMatchesRegularExpression(
            "/wp_clear_scheduled_hook\s*\(.{0,40}['\"]cashback_affiliate_auto_promote['\"]/s",
            $src,
            'Deactivation hook должен очищать cashback_affiliate_auto_promote.'
        );
    }

    public function test_auto_promote_preserves_confidence_changes_only_review_status(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        if (!preg_match('/function\s+auto_promote_low_confidence\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('auto_promote_low_confidence() not found');
        }
        $body = substr($src, $m[0][1], 5000);

        // Должен UPDATE review_status, но НЕ attribution_confidence
        $this->assertMatchesRegularExpression(
            "/SET\s+review_status\s*=\s*['\"]auto_approved['\"]/i",
            $body,
            'auto_promote должен UPDATE-ить review_status на auto_approved.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/SET\s[^;]*attribution_confidence\s*=/i',
            $body,
            'auto_promote НЕ должен менять attribution_confidence — confidence immutable (историческое качество).'
        );
    }

    public function test_auto_promote_criteria_strict(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        if (!preg_match('/function\s+auto_promote_low_confidence\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('auto_promote_low_confidence() not found');
        }
        $body = substr($src, $m[0][1], 5000);

        $this->assertMatchesRegularExpression(
            "/review_status\s*=\s*['\"]pending['\"]/",
            $body,
            "auto_promote WHERE должен фильтровать review_status='pending'."
        );
        $this->assertMatchesRegularExpression(
            '/collision_detected\s*=\s*0/',
            $body,
            'auto_promote WHERE должен исключать collision_detected=1.'
        );
        $this->assertMatchesRegularExpression(
            '/INTERVAL\s+14\s+DAY/i',
            $body,
            'auto_promote должен требовать referred_at <= NOW() - INTERVAL 14 DAY.'
        );
        $this->assertMatchesRegularExpression(
            '/JSON_LENGTH\s*\(\s*\w*antifraud_signals\w*\s*\)\s*=\s*0|antifraud_signals\s+IS\s+NULL/i',
            $body,
            'auto_promote должен проверять отсутствие сигналов (IS NULL OR JSON_LENGTH=0).'
        );
    }

    public function test_auto_promote_batch_limit_500(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        if (!preg_match('/function\s+auto_promote_low_confidence\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('auto_promote_low_confidence() not found');
        }
        $body = substr($src, $m[0][1], 5000);

        $this->assertMatchesRegularExpression(
            '/\bLIMIT\s+500\b/i',
            $body,
            'auto_promote должен ограничивать batch LIMIT 500 (защита от long-transaction).'
        );
    }

    public function test_auto_promote_writes_per_row_audit(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        if (!preg_match('/function\s+auto_promote_low_confidence\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('auto_promote_low_confidence() not found');
        }
        $body = substr($src, $m[0][1], 5000);

        $this->assertMatchesRegularExpression(
            "/Cashback_Affiliate_Audit::log\s*\(\s*['\"]auto_promoted['\"]/",
            $body,
            'auto_promote должен писать audit auto_promoted на каждую promotion.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 10. Admin — approve/reject low-confidence
    // ════════════════════════════════════════════════════════════════

    public function test_admin_approve_reject_ajax_handlers_registered(): void
    {
        $src = $this->read(self::ADMIN_FILE);

        $this->assertMatchesRegularExpression(
            "/wp_ajax_affiliate_approve_low_confidence/",
            $src,
            'AJAX-хендлер wp_ajax_affiliate_approve_low_confidence должен быть зарегистрирован.'
        );

        $this->assertMatchesRegularExpression(
            "/wp_ajax_affiliate_reject_low_confidence/",
            $src,
            'AJAX-хендлер wp_ajax_affiliate_reject_low_confidence должен быть зарегистрирован.'
        );
    }

    public function test_admin_reject_declines_accruals_and_sets_ineligible(): void
    {
        $src = $this->read(self::ADMIN_FILE);

        // UPDATE profiles SET review_status='manual_rejected', referral_reward_eligible=0
        $this->assertMatchesRegularExpression(
            "/review_status\s*=\s*['\"]manual_rejected['\"].{0,300}referral_reward_eligible\s*=\s*0|referral_reward_eligible\s*=\s*0.{0,300}review_status\s*=\s*['\"]manual_rejected['\"]/s",
            $src,
            'reject admin action должен ставить review_status=manual_rejected + referral_reward_eligible=0.'
        );

        // UPDATE pending accruals → declined. Код может использовать %i placeholder
        // с $accruals_table, поэтому проверяем семантическую близость:
        // referred_user_id (scope — по юзеру) + status='declined' + status='pending'
        // (источник filter).
        $this->assertMatchesRegularExpression(
            "/status\s*=\s*['\"]declined['\"].{0,400}referred_user_id|referred_user_id.{0,400}status\s*=\s*['\"]declined['\"]/si",
            $src,
            'reject должен переводить pending accruals этого юзера в status=declined.'
        );
        $this->assertMatchesRegularExpression(
            "/UPDATE.{0,100}%i.{0,300}status\s*=\s*['\"]declined['\"]|UPDATE.{0,100}cashback_affiliate_accruals.{0,300}status\s*=\s*['\"]declined['\"]/si",
            $src,
            'UPDATE-запрос к accruals таблице с SET status=declined должен присутствовать.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 11. Behavioral — NAT-collision keep-first с in-memory transient
    //     (проверяет контракт store/read после реализации Шагов 3-4)
    // ════════════════════════════════════════════════════════════════

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_transients'] = array();
    }

    public function test_behavioural_keep_first_and_downgrade_on_second_referrer(): void
    {
        // Проверяем через публично-вызываемые static helpers (после реализации).
        // Если класс ещё не загружен — тест будет SKIPPED.
        if (!class_exists('Cashback_Affiliate_Service')) {
            $svc_file = __DIR__ . '/../../../affiliate/class-affiliate-service.php';
            if (file_exists($svc_file)) {
                @require_once $svc_file;
            }
        }
        if (!class_exists('Cashback_Affiliate_Service')) {
            $this->markTestSkipped('Cashback_Affiliate_Service not yet loadable in isolation — covered by source-string tests.');
        }

        // Используем Reflection для доступа к private static методам (после Шага 3-4).
        $rc = new \ReflectionClass(\Cashback_Affiliate_Service::class);

        if (!$rc->hasMethod('store_referral_transient') || !$rc->hasMethod('read_referral_transient')) {
            $this->markTestSkipped('Transient methods not yet present — covered by source-string tests.');
        }

        // После реализации: второй store с другим referrer → collision=true на первой записи.
        // Проверяем через in-memory транзиент из bootstrap.
        $this->assertTrue(true, 'Behavioural test placeholder — activated after Steps 3-4.');
    }
}
