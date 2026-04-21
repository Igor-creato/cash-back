<?php
/**
 * Dedup strategy для cashback_fraud_device_ids (Группа 6 ADR, шаг 1).
 *
 * Ключ:      (user_id, DATE(first_seen), device_id).
 * Canonical: MIN(first_seen), tiebreak MIN(id).
 * Merge:     last_seen = MAX, confidence_score = MAX.
 * FK:        нет детей (soft FK только на wp_users.ID).
 * NULL rule: WHERE user_id IS NOT NULL — гостевые сессии не схлопываются.
 *
 * @package Cashback
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-cashback-dedup-strategy-interface.php';

if (!class_exists('Cashback_Dedup_Fraud_Device_Ids_Strategy')) {
    final class Cashback_Dedup_Fraud_Device_Ids_Strategy implements Cashback_Dedup_Strategy {
        public function scope_name(): string {
            return 'fraud_device_ids';
        }

        public function find_groups( object $wpdb, int $limit ): array {
            $table = $wpdb->prefix . 'cashback_fraud_device_ids';
            $sql   = 'SELECT user_id, DATE(first_seen) AS session_date, device_id, '
                . 'GROUP_CONCAT(id) AS ids, COUNT(*) AS cnt '
                . 'FROM %i '
                . 'WHERE user_id IS NOT NULL '
                . 'GROUP BY user_id, DATE(first_seen), device_id '
                . 'HAVING COUNT(*) > 1';
            if ($limit > 0) {
                $sql .= ' LIMIT %d';
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql собран из литералов с %i/%d placeholder'ами, передаётся в $wpdb->prepare().
                $raw = $wpdb->get_results($wpdb->prepare($sql, $table, $limit), ARRAY_A);
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql собран из литералов с %i placeholder'ом, передаётся в $wpdb->prepare().
                $raw = $wpdb->get_results($wpdb->prepare($sql, $table), ARRAY_A);
            }

            $groups = array();
            foreach ((array) $raw as $row) {
                $ids = array_map('intval', explode(',', (string) $row['ids']));
                if (count($ids) < 2) {
                    continue;
                }
                $groups[] = array(
                    'key'  => sprintf('%s|%s|%s', (string) $row['user_id'], (string) $row['session_date'], (string) $row['device_id']),
                    'ids'  => $ids,
                    'rows' => $this->load_rows($wpdb, $ids),
                );
            }
            return $groups;
        }

        public function choose_canonical( array $group ): int {
            $rows = $group['rows'];
            usort($rows, static function ( array $a, array $b ): int {
                $cmp = strcmp((string) $a['first_seen'], (string) $b['first_seen']);
                if ($cmp !== 0) {
                    return $cmp;
                }
                return ( (int) $a['id'] ) <=> ( (int) $b['id'] );
            });
            return (int) $rows[0]['id'];
        }

        public function merge_canonical( object $wpdb, int $canonical_id, array $group ): int {
            $max_last_seen  = '';
            $max_confidence = null;
            foreach ($group['rows'] as $row) {
                $ls = (string) ( $row['last_seen'] ?? '' );
                if ($ls !== '' && ( $max_last_seen === '' || strcmp($ls, $max_last_seen) > 0 )) {
                    $max_last_seen = $ls;
                }
                if (array_key_exists('confidence_score', $row) && $row['confidence_score'] !== null) {
                    $score = (float) $row['confidence_score'];
                    if ($max_confidence === null || $score > $max_confidence) {
                        $max_confidence = $score;
                    }
                }
            }

            $table = $wpdb->prefix . 'cashback_fraud_device_ids';
            if ($max_confidence !== null && $max_last_seen !== '') {
                $sql = $wpdb->prepare(
                    'UPDATE %i SET last_seen = %s, confidence_score = %f WHERE id = %d',
                    $table,
                    $max_last_seen,
                    $max_confidence,
                    $canonical_id
                );
            } elseif ($max_last_seen !== '') {
                $sql = $wpdb->prepare(
                    'UPDATE %i SET last_seen = %s WHERE id = %d',
                    $table,
                    $max_last_seen,
                    $canonical_id
                );
            } else {
                return 0;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql получен из $wpdb->prepare() с %i/%s/%f/%d placeholder'ами.
            return (int) $wpdb->query($sql);
        }

        public function relink_children( object $wpdb, int $canonical_id, array $duplicate_ids ): int {
            return 0;
        }

        public function delete_duplicates( object $wpdb, array $duplicate_ids ): int {
            if (empty($duplicate_ids)) {
                return 0;
            }
            $table        = $wpdb->prefix . 'cashback_fraud_device_ids';
            $placeholders = implode(',', array_fill(0, count($duplicate_ids), '%d'));
            $params       = array_merge(array( $table ), array_map('intval', $duplicate_ids));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders — строка из %d-литералов; sniff не считает spread-args.
            return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . $placeholders . ')', ...$params ) );
        }

        /**
         * @param array<int,int> $ids
         * @return array<int, array<string,mixed>>
         */
        private function load_rows( object $wpdb, array $ids ): array {
            $table        = $wpdb->prefix . 'cashback_fraud_device_ids';
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $params       = array_merge(array( $table ), array_map('intval', $ids));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders — строка из %d-литералов; sniff не считает spread-args.
            return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, first_seen, last_seen, confidence_score FROM %i WHERE id IN (' . $placeholders . ')', ...$params ), ARRAY_A );
        }
    }
}
