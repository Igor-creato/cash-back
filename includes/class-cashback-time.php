<?php
/**
 * Cashback_Time — единая точка работы со временем в плагине.
 *
 * Закрывает класс багов «mixed-zone storage»: устраняет смешивание `current_time('mysql')`
 * (зона сайта) и `gmdate(...)` / `UTC_TIMESTAMP()` (UTC) в одних и тех же таблицах
 * и сравнениях. Все timestamp'ы плагина пишутся, читаются и сравниваются в **UTC**;
 * пользователю показываются через `wp_date()` в зоне сайта.
 *
 * Границы:
 *   - storage:    `now_mysql()` / `now_micro()` → UTC `Y-m-d H:i:s[.uuuuuu]`.
 *   - cutoff:     `offset_mysql('-30 days')` → UTC относительно сейчас.
 *   - parse:      `parse($mysql_utc)` → UNIX timestamp; явно указывает UTC,
 *                 не полагаясь на `date.timezone`.
 *   - display:    `display($mysql_utc, $format)` → строка в зоне сайта через `wp_date()`.
 *
 * SQL-эквиваленты:
 *   - вместо `NOW()`              — `UTC_TIMESTAMP()`
 *   - вместо `CURRENT_TIMESTAMP`  — `UTC_TIMESTAMP()` (при `default-time-zone='+00:00'` идентичны)
 *   - DEFAULT CURRENT_TIMESTAMP в DDL — оставить (после смены MariaDB tz возвращает UTC).
 *
 * См. ADR: obsidian/knowledge/decisions/utc-everywhere.md
 *
 * @package Cashback
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PHPUNIT_RUNNING' ) ) {
	exit;
}

if ( class_exists( 'Cashback_Time', false ) ) {
	return;
}

final class Cashback_Time {

	/**
	 * Текущее время в UTC, формат `Y-m-d H:i:s` — для записи в БД.
	 *
	 * Замена для `current_time('mysql')` (которое возвращает зону сайта) и
	 * `current_time('mysql', true)` (UTC, но без явности).
	 */
	public static function now_mysql(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Текущее время в UTC c микросекундами, формат `Y-m-d H:i:s.uuuuuu`.
	 *
	 * Используется в click-sessions/idempotency, где гранулярности секунды
	 * недостаточно для дедупликации параллельных запросов. SQL-эквивалент —
	 * `UTC_TIMESTAMP(6)`; PHP-вариант нужен, когда timestamp генерируется
	 * приложением до записи (например, для подсчёта `expires_at = now + window`).
	 */
	public static function now_micro(): string {
		$micro = microtime( true );
		$sec   = (int) $micro;
		$usec  = (int) round( ( $micro - $sec ) * 1000000 );
		if ( $usec >= 1000000 ) {
			++$sec;
			$usec = 0;
		}
		return gmdate( 'Y-m-d H:i:s', $sec ) . '.' . sprintf( '%06d', $usec );
	}

	/**
	 * UTC-cutoff относительно текущего момента: `offset_mysql('-30 days')`.
	 *
	 * Замена для паттерна `gmdate('Y-m-d H:i:s', strtotime('-N units'))`,
	 * который встречается в claims-eligibility, claims-antifraud, statistics.
	 *
	 * @param string $relative Любая строка, понятная strtotime: `-30 days`,
	 *                         `+1 hour`, `monday this week` и т.п.
	 * @throws InvalidArgumentException если строку не удалось распарсить.
	 */
	public static function offset_mysql( string $relative ): string {
		$ts = strtotime( $relative );
		if ( false === $ts ) {
			throw new InvalidArgumentException( "Cashback_Time::offset_mysql: cannot parse '{$relative}'" );
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Парсинг БД-строки (UTC) в UNIX timestamp.
	 *
	 * Явно добавляет ` UTC` к строке, чтобы strtotime не зависел от
	 * `date.timezone` PHP. Это снимает silent-расхождения, когда
	 * окружение тестов/CI отличается от прода.
	 *
	 * @param string $mysql_utc Строка вида `Y-m-d H:i:s[.uuuuuu]`, хранимая в UTC.
	 * @return int UNIX timestamp (секунды). 0 при пустой/невалидной строке.
	 */
	public static function parse( string $mysql_utc ): int {
		$mysql_utc = trim( $mysql_utc );
		if ( '' === $mysql_utc || '0000-00-00 00:00:00' === $mysql_utc ) {
			return 0;
		}
		$ts = strtotime( $mysql_utc . ' UTC' );
		return false === $ts ? 0 : (int) $ts;
	}

	/**
	 * Локализованное отображение через `wp_date()` — в зоне сайта (`timezone_string`).
	 *
	 * Замена для паттерна `gmdate('d.m.Y H:i', strtotime($db_value))`, который
	 * показывает UTC и не локализуется. `wp_date()` сам учитывает
	 * `timezone_string` опцию и текущую локаль.
	 *
	 * @param string $mysql_utc UTC-строка из БД.
	 * @param string $format    PHP date-format. По умолчанию `d.m.Y H:i` (русский стиль).
	 * @return string Локализованная строка; пустая, если `$mysql_utc` пустой/невалидный.
	 */
	public static function display( string $mysql_utc, string $format = 'd.m.Y H:i' ): string {
		$ts = self::parse( $mysql_utc );
		if ( 0 === $ts ) {
			return '';
		}
		return (string) wp_date( $format, $ts );
	}
}
