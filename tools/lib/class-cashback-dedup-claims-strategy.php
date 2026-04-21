<?php
/**
 * Dedup strategy для cashback_claims (Группа 6 ADR, шаг 1).
 *
 * Ключ:      (merchant_id, order_id) — per-network UNIQUE.
 * Canonical: приоритет статусов approved > sent_to_network > submitted > declined > draft;
 *            tiebreak — MIN(created_at), затем MIN(claim_id).
 * Merge:     user-поля не трогаем (no-op).
 * FK:        cashback_claim_events.claim_id → canonical claim_id.
 * NULL rule: WHERE merchant_id IS NOT NULL — legacy-записи без мерчанта не группируются.
 *
 * @package Cashback
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-cashback-dedup-strategy-interface.php';

if (!class_exists('Cashback_Dedup_Claims_Strategy')) {
    final class Cashback_Dedup_Claims_Strategy implements Cashback_Dedup_Strategy {
        private const STATUS_PRIORITY = array(
            'approved'        => 1,
            'sent_to_network' => 2,
            'submitted'       => 3,
            'declined'        => 4,
            'draft'           => 5,
        );

        public function scope_name(): string {
            return 'claims';
        }

        public function find_groups( object $wpdb, int $limit ): array {
            $table = $wpdb->prefix . 'cashback_claims';
            $sql   = 'SELECT merchant_id, order_id, GROUP_CONCAT(claim_id) AS ids, COUNT(*) AS cnt '
                . 'FROM %i '
                . 'WHERE merchant_id IS NOT NULL '
                . 'GROUP BY merchant_id, order_id '
                . 'HAVING COUNT(*) > 1';

            if ($limit > 0) {
                $sql .= ' LIMIT %d';
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql собран из литералов с %i/%d placeholder'ами.
                $raw = $wpdb->get_results($wpdb->prepare($sql, $table, $limit), ARRAY_A);
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql собран из литералов с %i placeholder'ом.
                $raw = $wpdb->get_results($wpdb->prepare($sql, $table), ARRAY_A);
            }

            $groups = array();
            foreach ((array) $raw as $row) {
                $ids = array_map('intval', explode(',', (string) $row['ids']));
                if (count($ids) < 2) {
                    continue;
                }
                $groups[] = array(
                    'key'  => sprintf('%s|%s', (string) $row['merchant_id'], (string) $row['order_id']),
                    'ids'  => $ids,
                    'rows' => $this->load_rows($wpdb, $ids),
                );
            }
            return $groups;
        }

        public function choose_canonical( array $group ): int {
            $rows = $group['rows'];
            usort($rows, static function ( array $a, array $b ): int {
                $pa = self::STATUS_PRIORITY[ (string) ( $a['status'] ?? '' ) ] ?? 99;
                $pb = self::STATUS_PRIORITY[ (string) ( $b['status'] ?? '' ) ] ?? 99;
                if ($pa !== $pb) {
                    return $pa <=> $pb;
                }
                $cmp = strcmp((string) $a['created_at'], (string) $b['created_at']);
                if ($cmp !== 0) {
                    return $cmp;
                }
                return ( (int) $a['claim_id'] ) <=> ( (int) $b['claim_id'] );
            });
            return (int) $rows[0]['claim_id'];
        }

        public function merge_canonical( object $wpdb, int $canonical_id, array $group ): int {
            return 0;
        }

        public function relink_children( object $wpdb, int $canonical_id, array $duplicate_ids ): int {
            if (empty($duplicate_ids)) {
                return 0;
            }
            $table        = $wpdb->prefix . 'cashback_claim_events';
            $placeholders = implode(',', array_fill(0, count($duplicate_ids), '%d'));
            $params       = array_merge(array( $table, $canonical_id ), array_map('intval', $duplicate_ids));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders — строка %d-литералов; sniff не считает spread-args.
            return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET claim_id = %d WHERE claim_id IN (' . $placeholders . ')', ...$params ) );
        }

        public function delete_duplicates( object $wpdb, array $duplicate_ids ): int {
            if (empty($duplicate_ids)) {
                return 0;
            }
            $table        = $wpdb->prefix . 'cashback_claims';
            $placeholders = implode(',', array_fill(0, count($duplicate_ids), '%d'));
            $params       = array_merge(array( $table ), array_map('intval', $duplicate_ids));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders — строка %d-литералов; sniff не считает spread-args.
            return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE claim_id IN (' . $placeholders . ')', ...$params ) );
        }

        /**
         * @param array<int,int> $ids
         * @return array<int, array<string,mixed>>
         */
        private function load_rows( object $wpdb, array $ids ): array {
            $table        = $wpdb->prefix . 'cashback_claims';
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $params       = array_merge(array( $table ), array_map('intval', $ids));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders — строка %d-литералов; sniff не считает spread-args.
            return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT claim_id, status, created_at FROM %i WHERE claim_id IN (' . $placeholders . ')', ...$params ), ARRAY_A );
        }
    }
}
