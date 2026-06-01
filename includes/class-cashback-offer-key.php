<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Canonical internal key for CPA offers.
 *
 * Raw offer IDs are only unique inside a CPA network. This helper creates a
 * stable internal key that can be safely used for deduplication/scoring while
 * keeping raw offer_id untouched for CPA API calls.
 */
final class Cashback_Offer_Key {

    public static function from_parts( string $network_slug, string $offer_id ): ?string {
        $slug  = strtolower(trim($network_slug));
        $offer = trim($offer_id);

        if ($slug === '' || $offer === '') {
            return null;
        }

        return $slug . ':' . $offer;
    }

    public static function from_network_id( int $network_id, string $offer_id ): ?string {
        if ($network_id <= 0) {
            return null;
        }

        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) {
            return null;
        }

        $slug = $wpdb->get_var($wpdb->prepare(
            'SELECT slug FROM %i WHERE id = %d LIMIT 1',
            $wpdb->prefix . 'cashback_affiliate_networks',
            $network_id
        ));

        return is_string($slug) ? self::from_parts($slug, $offer_id) : null;
    }

    public static function looks_like_key( mixed $value ): bool {
        return is_string($value)
            && preg_match('/^[a-z0-9_-]+:.+$/', $value) === 1;
    }
}
