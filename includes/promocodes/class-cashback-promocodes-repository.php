<?php

/**
 * Repository слой для промокодов.
 *
 * Принимает только Cashback_Coupon_DTO[] — работает одинаково с любой
 * сетью благодаря normalised DTO. UNIQUE-ключ (network_id, external_id)
 * защищает от коллизий ID между сетями.
 *
 * @package CashbackPlugin
 * @since   7.2.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Не final: extending в тестах через анонимный класс и для будущих
// расширений (caching layer, audit-decorator). Контракт стабилен.
class Cashback_Promocodes_Repository {

    /** Hard-cap для get_active_for_campaign limit'а (защита от huge SELECT). */
    private const MAX_LIMIT = 100;

    /** Default limit если не передан. */
    private const DEFAULT_LIMIT = 30;

    /**
     * Upsert купонов для кампании + soft-delete отсутствующих.
     *
     * Каждый DTO становится INSERT ... ON DUPLICATE KEY UPDATE по
     * (network_id, external_id). После — все купоны кампании, чьи
     * external_id не в seen_ids, помечаются is_active=0.
     *
     * @param Cashback_Coupon_DTO[] $coupons
     * @return array{upserted:int,deactivated:int}
     */
    public function upsert_for_campaign( int $network_id, string $advcampaign_id, array $coupons ): array {
        global $wpdb;
        $table = $this->table_name();

        $upserted = 0;
        $seen_ids = array();

        foreach ( $coupons as $coupon ) {
            if ( ! $coupon instanceof Cashback_Coupon_DTO ) {
                continue;
            }
            $seen_ids[] = $coupon->external_id;

            $row = $this->dto_to_row( $coupon, $network_id, $advcampaign_id );

            $sql = $wpdb->prepare(
                'INSERT INTO %i (network_id, advcampaign_id, external_id, species, promocode, name, short_name, description, discount, date_start, date_end, regions, categories, image, goto_link, is_exclusive, rating, raw_payload, fetched_at, is_active) VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s, 1) ON DUPLICATE KEY UPDATE species = VALUES(species), promocode = VALUES(promocode), name = VALUES(name), short_name = VALUES(short_name), description = VALUES(description), discount = VALUES(discount), date_start = VALUES(date_start), date_end = VALUES(date_end), regions = VALUES(regions), categories = VALUES(categories), image = VALUES(image), goto_link = VALUES(goto_link), is_exclusive = VALUES(is_exclusive), rating = VALUES(rating), raw_payload = VALUES(raw_payload), fetched_at = VALUES(fetched_at), is_active = 1',
                $table,
                $row['network_id'],
                $row['advcampaign_id'],
                $row['external_id'],
                $row['species'],
                $row['promocode'] ?? '',
                $row['name'],
                $row['short_name'] ?? '',
                $row['description'] ?? '',
                $row['discount'] ?? '',
                $row['date_start'] ?? '',
                $row['date_end'] ?? '',
                $row['regions'],
                $row['categories'],
                $row['image'] ?? '',
                $row['goto_link'],
                $row['is_exclusive'],
                $row['rating'] ?? '',
                $row['raw_payload'],
                $row['fetched_at']
            );

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above.
            $result = $wpdb->query( $sql );
            if ( $result !== false ) {
                ++$upserted;
            }
        }

        $deactivated = $this->deactivate_missing( $network_id, $advcampaign_id, $seen_ids );

        return array(
            'upserted'    => $upserted,
            'deactivated' => $deactivated,
        );
    }

    /**
     * Soft-delete: помечает is_active=0 купоны кампании, отсутствующие в seen.
     *
     * @param string[] $seen_external_ids
     * @return int Affected rows.
     */
    public function deactivate_missing( int $network_id, string $advcampaign_id, array $seen_external_ids ): int {
        global $wpdb;
        $table = $this->table_name();

        if ( empty( $seen_external_ids ) ) {
            $sql = $wpdb->prepare(
                'UPDATE %i SET is_active = 0 WHERE network_id = %d AND advcampaign_id = %s AND is_active = 1',
                $table,
                $network_id,
                $advcampaign_id
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared.
            $affected = $wpdb->query( $sql );
            return is_int( $affected ) ? $affected : 0;
        }

        $placeholders = implode( ',', array_fill( 0, count( $seen_external_ids ), '%s' ) );
        $args         = array_merge(
            array( $table, $network_id, $advcampaign_id ),
            array_values( $seen_external_ids )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders сгенерирован из array_fill('%s'), не пользовательский ввод.
        $sql = $wpdb->prepare( "UPDATE %i SET is_active = 0 WHERE network_id = %d AND advcampaign_id = %s AND is_active = 1 AND external_id NOT IN ({$placeholders})", ...$args );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above with sanitized $placeholders.
        $affected = $wpdb->query( $sql );
        return is_int( $affected ) ? $affected : 0;
    }

    /**
     * Получить активные купоны для кампании.
     *
     * Фильтр: is_active=1, дата валидна, регион RU входит в regions.
     *
     * @param array{species?:array<int,string>|string,limit?:int} $filters
     * @return array<int,array<string,mixed>> Сырые row'ы (для рендера в шорткоде).
     */
    public function get_active_for_campaign( int $network_id, string $advcampaign_id, array $filters = array() ): array {
        global $wpdb;
        $table = $this->table_name();

        $limit_raw = isset( $filters['limit'] ) ? (int) $filters['limit'] : self::DEFAULT_LIMIT;
        $limit     = max( 1, min( self::MAX_LIMIT, $limit_raw ) );

        $species_filter = $filters['species'] ?? null;
        $species_clause = '';
        $species_args   = array();
        if ( $species_filter !== null ) {
            $species_list = is_array( $species_filter ) ? $species_filter : explode( ',', (string) $species_filter );
            $species_list = array_values( array_filter( array_map( 'trim', $species_list ), static fn( $s ) => $s !== '' ) );
            if ( ! empty( $species_list ) ) {
                $placeholders   = implode( ',', array_fill( 0, count( $species_list ), '%s' ) );
                $species_clause = " AND species IN ({$placeholders}) ";
                $species_args   = $species_list;
            }
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $species_clause built from validated array_fill('%s').
        $sql = $wpdb->prepare( "SELECT * FROM %i WHERE network_id = %d AND advcampaign_id = %s AND is_active = 1 AND ( date_start IS NULL OR date_start <= UTC_TIMESTAMP() ) AND ( date_end IS NULL OR date_end >= UTC_TIMESTAMP() ) AND ( regions IS NULL OR regions = '' OR FIND_IN_SET('RU', regions) > 0 ) {$species_clause} ORDER BY is_exclusive DESC, fetched_at DESC LIMIT %d", ...array_merge( array( $table, $network_id, $advcampaign_id ), $species_args, array( $limit ) ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above.
        $rows = $wpdb->get_results( $sql, ARRAY_A );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * @return array<string,mixed>
     */
    private function dto_to_row( Cashback_Coupon_DTO $coupon, int $network_id, string $advcampaign_id ): array {
        $now = gmdate( 'Y-m-d H:i:s' );

        return array(
            'network_id'     => $network_id,
            'advcampaign_id' => $advcampaign_id,
            'external_id'    => $coupon->external_id,
            'species'        => $coupon->species,
            'promocode'      => $coupon->promocode,
            'name'           => $coupon->name,
            'short_name'     => $coupon->short_name,
            'description'    => $coupon->description,
            'discount'       => $coupon->discount,
            'date_start'     => $coupon->date_start ? $coupon->date_start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : null,
            'date_end'       => $coupon->date_end ? $coupon->date_end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : null,
            'regions'        => implode( ',', $coupon->regions ),
            'categories'     => wp_json_encode( $coupon->categories ),
            'image'          => $coupon->image_url,
            'goto_link'      => $coupon->goto_link,
            'is_exclusive'   => $coupon->is_exclusive ? 1 : 0,
            'rating'         => $coupon->rating !== null ? (string) $coupon->rating : null,
            'raw_payload'    => wp_json_encode( $coupon->raw_payload ),
            'fetched_at'     => $now,
        );
    }

    private function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'cashback_promocodes';
    }
}
