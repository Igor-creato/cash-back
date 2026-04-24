<?php
/**
 * Affiliate Module — Typed Audit Log.
 *
 * F-22-003 — Referral Attribution Hardening (Группа 12).
 *
 * Отдельный класс, а не `Cashback_Encryption::write_audit_log` или
 * `Cashback_Social_Auth_Audit::log`, потому что нужны affiliate-specific
 * типизированные поля (rejected_referrer_id, kept_referrer_id,
 * partner_token_hash, ip/subnet/ua/key hashes, confidence, signals),
 * которые используются админским review-queue и SQL-аудитом выплат.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Affiliate_Audit {

    /**
     * Запись аудит-события в cashback_affiliate_audit.
     *
     * Все типизированные поля опциональны (NULL по умолчанию). signals и
     * payload сериализуются в JSON (LONGTEXT на уровне БД — MariaDB
     * совместимость, JSON-валидность проверяется PHP-энкодером).
     *
     * @param string $event_type Имя события (snake_case, ≤64 символов).
     * @param array  $ctx        Контекст с ключами из списка колонок:
     *   actor_user_id, target_user_id, referrer_id,
     *   rejected_referrer_id, kept_referrer_id,
     *   click_id, partner_token_hash,
     *   ip_hash, ip_subnet_hash, ua_hash, key_hash,
     *   confidence ('high'|'medium'|'low'),
     *   reason,
     *   signals (array<string> | null),
     *   payload (array<mixed> | null).
     */
    public static function log( string $event_type, array $ctx ): void {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            return; // активация/deinstallation контекст — нет wpdb.
        }

        $table = $wpdb->prefix . 'cashback_affiliate_audit';

        $row = array(
            'event_type'           => mb_substr($event_type, 0, 64),
            'actor_user_id'        => self::to_int_or_null($ctx['actor_user_id']        ?? null),
            'target_user_id'       => self::to_int_or_null($ctx['target_user_id']       ?? null),
            'referrer_id'          => self::to_int_or_null($ctx['referrer_id']          ?? null),
            'rejected_referrer_id' => self::to_int_or_null($ctx['rejected_referrer_id'] ?? null),
            'kept_referrer_id'     => self::to_int_or_null($ctx['kept_referrer_id']     ?? null),
            'click_id'             => self::to_ascii_or_null($ctx['click_id']             ?? null, 32),
            'partner_token_hash'   => self::to_ascii_or_null($ctx['partner_token_hash']   ?? null, 32),
            'ip_hash'              => self::to_ascii_or_null($ctx['ip_hash']              ?? null, 32),
            'ip_subnet_hash'       => self::to_ascii_or_null($ctx['ip_subnet_hash']       ?? null, 32),
            'ua_hash'              => self::to_ascii_or_null($ctx['ua_hash']              ?? null, 32),
            'key_hash'             => self::to_ascii_or_null($ctx['key_hash']             ?? null, 32),
            'confidence'           => self::to_confidence_or_null($ctx['confidence']     ?? null),
            'reason'               => isset($ctx['reason']) && is_string($ctx['reason'])
                ? mb_substr($ctx['reason'], 0, 64)
                : null,
            'signals'              => isset($ctx['signals'])
                ? (string) wp_json_encode(array_values((array) $ctx['signals']))
                : null,
            'payload'              => isset($ctx['payload'])
                ? (string) wp_json_encode($ctx['payload'])
                : null,
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Audit-write must be synchronous и не кешируется.
        $wpdb->insert(
            $table,
            $row,
            array(
                '%s', // event_type
                '%d', '%d', '%d', '%d', '%d', // actor/target/referrer/rejected/kept
                '%s', // click_id
                '%s', '%s', '%s', '%s', '%s', // hashes
                '%s', // confidence
                '%s', // reason
                '%s', '%s', // signals, payload
            )
        );
    }

    private static function to_int_or_null( mixed $value ): ?int {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        $v = (int) $value;
        return $v > 0 ? $v : null;
    }

    /**
     * ASCII-only hex/uuid-строки: отфильтровываем всё что не hex/32-символы.
     */
    private static function to_ascii_or_null( mixed $value, int $max_len ): ?string {
        if (!is_string($value) || $value === '') {
            return null;
        }
        // Пропускаем 0-9a-f, плюс uuid-дефисы на случай форматов.
        $sanitized = (string) preg_replace('/[^0-9a-fA-F-]/', '', $value);
        if ($sanitized === '') {
            return null;
        }
        return mb_substr($sanitized, 0, $max_len);
    }

    private static function to_confidence_or_null( mixed $value ): ?string {
        if (!is_string($value)) {
            return null;
        }
        $v = strtolower($value);
        return in_array($v, array( 'high', 'medium', 'low' ), true) ? $v : null;
    }
}
