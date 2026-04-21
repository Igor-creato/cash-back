<?php
/**
 * Dedup Runner (Группа 6 ADR, шаг 1).
 *
 * Orchestrates: dry-run vs destructive, TX-envelope per group, logging, limit.
 *
 * @package Cashback
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-cashback-dedup-strategy-interface.php';

if (!class_exists('Cashback_Dedup_Runner')) {
    final class Cashback_Dedup_Runner {
        private object $wpdb;
        private object $strategy;
        private bool $dry_run;
        private int $limit;
        /** @var callable|null */
        private $logger;

        /**
         * @param object               $wpdb     wpdb или совместимый stub.
         * @param object               $strategy Инстанс стратегии (Cashback_Dedup_Strategy or compatible).
         * @param array<string, mixed> $options  dry_run (bool, default true), limit (int, default 0), logger (callable).
         */
        public function __construct( object $wpdb, object $strategy, array $options = array() ) {
            $this->wpdb     = $wpdb;
            $this->strategy = $strategy;
            $this->dry_run  = (bool) ( $options['dry_run'] ?? true );
            $this->limit    = (int) ( $options['limit'] ?? 0 );
            $logger_opt     = $options['logger'] ?? null;
            $this->logger   = is_callable($logger_opt) ? $logger_opt : null;
        }

        /**
         * @return array{scope:string, dry_run:bool, groups:int, deleted:int, relinked:int, errors:int}
         */
        public function run(): array {
            $scope = $this->strategy->scope_name();
            $stats = array(
                'scope'    => $scope,
                'dry_run'  => $this->dry_run,
                'groups'   => 0,
                'deleted'  => 0,
                'relinked' => 0,
                'errors'   => 0,
            );

            $this->emit('run.start', array(
                'scope'   => $scope,
                'dry_run' => $this->dry_run,
                'limit'   => $this->limit,
            ));

            $groups          = $this->strategy->find_groups($this->wpdb, $this->limit);
            $stats['groups'] = count($groups);

            if ($this->dry_run) {
                foreach ($groups as $group) {
                    $this->emit('group.found', array(
                        'scope' => $scope,
                        'key'   => $group['key'],
                        'count' => count($group['ids']),
                    ));
                }
                $this->emit('run.end', $stats);
                return $stats;
            }

            foreach ($groups as $group) {
                $this->wpdb->query('START TRANSACTION');
                try {
                    $canonical_id  = $this->strategy->choose_canonical($group);
                    $duplicate_ids = array_values(array_filter(
                        $group['ids'],
                        static fn( int $id ): bool => $id !== $canonical_id
                    ));

                    $this->strategy->merge_canonical($this->wpdb, $canonical_id, $group);
                    $relinked = $this->strategy->relink_children($this->wpdb, $canonical_id, $duplicate_ids);
                    $deleted  = $this->strategy->delete_duplicates($this->wpdb, $duplicate_ids);

                    $this->wpdb->query('COMMIT');

                    $stats['deleted']  += $deleted;
                    $stats['relinked'] += $relinked;

                    $this->emit('group.commit', array(
                        'scope'        => $scope,
                        'key'          => $group['key'],
                        'canonical_id' => $canonical_id,
                        'deleted'      => $deleted,
                        'relinked'     => $relinked,
                    ));
                } catch (\Throwable $e) {
                    $this->wpdb->query('ROLLBACK');
                    ++$stats['errors'];
                    $this->emit('group.rollback', array(
                        'scope' => $scope,
                        'key'   => $group['key'],
                        'error' => $e->getMessage(),
                    ));
                }
            }

            $this->emit('run.end', $stats);
            return $stats;
        }

        /** @param array<string, mixed> $ctx */
        private function emit( string $event, array $ctx ): void {
            if ($this->logger !== null) {
                ( $this->logger )($event, $ctx);
                return;
            }

            $scope = (string) ( $ctx['scope'] ?? $this->strategy->scope_name() );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fallback только если wp_json_encode() не определён (non-WP runner для unit-тестов).
            $payload = function_exists('wp_json_encode') ? wp_json_encode($ctx) : json_encode($ctx);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional dedup diagnostic logging (group 6, tools/).
            error_log('[cashback-dedup-' . $scope . '] ' . $event . ' ' . ( is_string($payload) ? $payload : '' ));
        }
    }
}
