<?php
/**
 * Интерфейс стратегии дедупликации (Группа 6 ADR, шаг 1).
 *
 * Стратегия инкапсулирует table-specific логику: поиск групп дубликатов,
 * выбор канонической строки, мерж в канонику, перепривязку FK-детей
 * и удаление дубликатов. Оркестрация TX и dry-run — задача Runner'а.
 *
 * @package Cashback
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!interface_exists('Cashback_Dedup_Strategy')) {
    interface Cashback_Dedup_Strategy {
        /**
         * Короткое имя scope'а (используется в логах и статистике).
         */
        public function scope_name(): string;

        /**
         * Найти группы дубликатов.
         *
         * @param object $wpdb  Экземпляр wpdb (или совместимый stub для тестов).
         * @param int    $limit Максимум групп; 0 = без лимита.
         *
         * @return array<int, array{key:string, ids:array<int,int>, rows:array<int,array<string,mixed>>}>
         */
        public function find_groups( object $wpdb, int $limit ): array;

        /**
         * Выбрать claim_id / id канонической строки в группе.
         *
         * @param array{key:string, ids:array<int,int>, rows:array<int,array<string,mixed>>} $group
         */
        public function choose_canonical( array $group ): int;

        /**
         * Смержить данные дубликатов в каноническую строку (если применимо).
         *
         * @param array{key:string, ids:array<int,int>, rows:array<int,array<string,mixed>>} $group
         * @return int Число affected rows (для логирования).
         */
        public function merge_canonical( object $wpdb, int $canonical_id, array $group ): int;

        /**
         * Перепривязать FK-детей: UPDATE children SET <fk> = canonical_id WHERE <fk> IN (duplicate_ids).
         *
         * @param array<int,int> $duplicate_ids
         */
        public function relink_children( object $wpdb, int $canonical_id, array $duplicate_ids ): int;

        /**
         * Удалить не-канонические строки.
         *
         * @param array<int,int> $duplicate_ids
         */
        public function delete_duplicates( object $wpdb, array $duplicate_ids ): int;
    }
}
