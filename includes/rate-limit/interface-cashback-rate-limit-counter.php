<?php

/**
 * Атомарный counter для rate-limit (Группа 7 ADR, шаг 2).
 *
 * Контракт зафиксирован в development/test/tests/RateLimiterConcurrencyTest.php.
 * Реализации: Cashback_Rate_Limit_SQL_Counter (primary), будущий
 * Cashback_Rate_Limit_Cache_Counter (opt-in fast-path для Redis/memcached).
 *
 * @package Cashback
 * @since   1.0.0 (Group 7)
 */

defined('ABSPATH') || exit;

interface Cashback_Rate_Limit_Counter_Interface {

    /**
     * Атомарно увеличить счётчик на +1 в текущем окне и вернуть итоговое состояние.
     *
     * Если окно (window_started_at + window_seconds) истекло к моменту вызова —
     * counter сбрасывается к 1 и окно перезапускается.
     *
     * @param string $scope_key       Детерминированный ключ (e.g. sha1("{action}|{uid}|{ip}")).
     *                                Ограничен длиной в 64 символа (VARCHAR(64) PK в production-схеме).
     * @param int    $window_seconds  Длина окна в секундах. Должна быть > 0.
     * @param int    $limit           Порог allowed=true. hits ≤ limit → allowed=true.
     *
     * @return array{hits: int, allowed: bool, reset_at: int}
     *     hits     — количество обращений в текущем окне (монотонно растёт даже после превышения);
     *     allowed  — true если hits ≤ limit;
     *     reset_at — unix timestamp окончания текущего окна.
     *
     * @throws \InvalidArgumentException при пустом scope_key / non-positive window / negative limit.
     * @throws \RuntimeException         при ошибке backend'а (БД недоступна и т.п.).
     */
    public function increment( string $scope_key, int $window_seconds, int $limit ): array;
}
