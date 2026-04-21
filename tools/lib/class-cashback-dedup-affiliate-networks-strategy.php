<?php
/**
 * Dedup strategy для cashback_affiliate_networks (Группа 6 ADR, шаг 1).
 *
 * UNIQUE(slug) на таблице УЖЕ существует (mariadb.php:186). Скрипт —
 * legacy safety net для установок, заведённых до введения UNIQUE.
 *
 * Ключ:      slug.
 * Canonical: MIN(id).
 * Merge:     no-op.
 * FK:        cashback_affiliate_network_params.network_id → canonical id.
 *
 * @package Cashback
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-cashback-dedup-strategy-interface.php';

if (!class_exists('Cashback_Dedup_Affiliate_Networks_Strategy')) {
    final class Cashback_Dedup_Affiliate_Networks_Strategy implements Cashback_Dedup_Strategy {
        public function scope_name(): string {
            return 'affiliate_networks';
        }

        public function find_groups( object $wpdb, int $limit ): array {
            $table = $wpdb->prefix . 'cashback_affiliate_networks';
            $sql   = 'SELECT slug, GROUP_CONCAT(id) AS ids, COUNT(*) AS cnt '
                . 'FROM %i '
                . 'GROUP BY slug '
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
                    'key'  => (string) $row['slug'],
                    'ids'  => $ids,
                    'rows' => $this->load_rows($wpdb, $ids),
                );
            }
            return $groups;
        }

        public function choose_canonical( array $group ): int {
            $ids = array_map('intval', $group['ids']);
            sort($ids);
            return $ids[0];
        }

        public function merge_canonical( object $wpdb, int $canonical_id, array $group ): int {
            return 0;
        }

        public function relink_children( object $wpdb, int $canonical_id, array $duplicate_ids ): int {
            if (empty($duplicate_ids)) {
                return 0;
            }
            $table        = $wpdb->prefix . 'cashback_affiliate_network_params';
            $placeholders = implode(',', array_fill(0, count($duplicate_ids), '%d'));
            $params       = array_merge(array( $table, $canonical_id ), array_map('intval', $duplicate_ids));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders — строка %d-литералов; sniff не считает spread-args.
            return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET network_id = %d WHERE network_id IN (' . $placeholders . ')', ...$params ) );
        }

        public function delete_duplicates( object $wpdb, array $duplicate_ids ): int {
            if (empty($duplicate_ids)) {
                return 0;
            }
            $table        = $wpdb->prefix . 'cashback_affiliate_networks';
            $placeholders = implode(',', array_fill(0, count($duplicate_ids), '%d'));
            $params       = array_merge(array( $table ), array_map('intval', $duplicate_ids));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders — строка %d-литералов; sniff не считает spread-args.
            return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . $placeholders . ')', ...$params ) );
        }

        /**
         * @param array<int,int> $ids
         * @return array<int, array<string,mixed>>
         */
        private function load_rows( object $wpdb, array $ids ): array {
            $table        = $wpdb->prefix . 'cashback_affiliate_networks';
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $params       = array_merge(array( $table ), array_map('intval', $ids));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders — строка %d-литералов; sniff не считает spread-args.
            return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, slug FROM %i WHERE id IN (' . $placeholders . ')', ...$params ), ARRAY_A );
        }
    }
}
