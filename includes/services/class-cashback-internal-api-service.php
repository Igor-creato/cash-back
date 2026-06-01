<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Savello_Cashback_Internal_API_Service {

    private const VERSION = '1.0.0';

    public function get_merchants( array $filters ): array {
        $status = $this->sanitize_status((string) ( $filters['status'] ?? 'active' ));
        $limit  = max(1, min(500, (int) ( $filters['limit'] ?? 100 )));
        $offset = max(0, (int) ( $filters['offset'] ?? 0 ));

        $all = array();
        foreach ($this->load_merchant_rows() as $row) {
            $item = $this->format_merchant_row($row);
            if ($item === null) {
                continue;
            }
            if ($status !== 'all' && $item['status'] !== $status) {
                continue;
            }
            $all[] = $item;
        }

        return array(
            'items'      => array_slice($all, $offset, $limit),
            'pagination' => array(
                'limit'  => $limit,
                'offset' => $offset,
                'total'  => count($all),
            ),
            'version'    => self::VERSION,
        );
    }

    public function get_merchant_rates( string $merchant_id ) {
        $merchant = $this->get_merchant($merchant_id);
        if ($merchant === null) {
            return $this->merchant_not_found();
        }

        $rates = array();
        foreach ($this->load_rates_for_merchant($merchant) as $row) {
            $rate = $this->format_rate_row($merchant['merchant_id'], $row);
            if ($rate !== null && $rate['status'] === 'active') {
                $rates[] = $rate;
            }
        }

        return array(
            'merchant_id' => $merchant['merchant_id'],
            'rates'       => $rates,
        );
    }

    public function resolve_product_cashback( array $payload ) {
        $url      = $this->sanitize_url((string) ( $payload['url'] ?? '' ));
        $price    = $payload['price'] ?? null;
        $currency = strtoupper(sanitize_text_field((string) ( $payload['currency'] ?? 'RUB' )));

        if ($url === '' || ! is_numeric($price) || (float) $price < 0 || ! preg_match('/^[A-Z]{3,8}$/', $currency)) {
            return $this->bad_request('Invalid product payload.');
        }

        $price_float = (float) $price;
        $merchant    = $this->find_merchant_by_url($url);
        if ($merchant === null) {
            return array(
                'cashback_status'    => 'no_partner',
                'merchant_id'        => null,
                'cashback_available' => false,
                'confidence'         => 'none',
                'display_policy'     => 'cashback_unavailable',
                'message'            => 'Кэшбэк для этого магазина недоступен',
            );
        }

        $rates = $this->load_rates_for_merchant($merchant);
        $rate  = $this->select_rate($rates, (string) ( $payload['category_id'] ?? '' ));
        if ($rate === null) {
            return array(
                'cashback_status'    => 'partner_no_commission',
                'merchant_id'        => $merchant['merchant_id'],
                'merchant_name'      => $merchant['merchant_name'],
                'network'            => $merchant['network'],
                'offer_id'           => $merchant['offer_id'],
                'cashback_available' => false,
                'confidence'         => 'none',
                'display_policy'     => 'cashback_unavailable',
                'message'            => 'Ставка кэшбэка недоступна',
            );
        }

        $formatted  = $this->format_rate_row($merchant['merchant_id'], $rate);
        $user_share = $this->guest_user_share();
        $base       = array(
            'merchant_id'           => $merchant['merchant_id'],
            'merchant_name'         => $merchant['merchant_name'],
            'network'               => $merchant['network'],
            'offer_id'              => $merchant['offer_id'],
            'rate_id'               => $formatted['rate_id'],
            'cashback_available'    => true,
            'commission_rate_type'  => $formatted['rate_type'],
            'commission_exact'      => $formatted['commission_exact'],
            'commission_min'        => $formatted['commission_min'],
            'commission_max'        => $formatted['commission_max'],
            'user_share'            => $user_share,
            'display_policy'        => $formatted['display_policy'],
        );

        if ($formatted['commission_exact'] !== null) {
            $calc = $this->calculate_user_cashback($price_float, (float) $formatted['commission_exact'], $user_share);
            return array_merge($base, array(
                'cashback_status'          => 'partner_exact',
                'user_cashback_exact_rate' => $this->round_rate($formatted['commission_exact'] * $user_share),
                'expected_cashback_exact'  => $calc['user_cashback'],
                'effective_price'          => $calc['effective_price'],
                'confidence'               => 'exact',
                'message'                  => 'Расчётный кэшбэк зависит от подтверждения магазином',
            ));
        }

        $min = (float) ( $formatted['commission_min'] ?? 0.0 );
        $max = (float) ( $formatted['commission_max'] ?? 0.0 );

        return array_merge($base, array(
            'cashback_status'              => 'partner_estimated',
            'user_cashback_min_rate'       => $this->round_rate($min * $user_share),
            'user_cashback_max_rate'       => $this->round_rate($max * $user_share),
            'expected_cashback_min'        => $this->round_money($price_float * $min / 100 * $user_share),
            'expected_cashback_max'        => $this->round_money($price_float * $max / 100 * $user_share),
            'effective_price_conservative' => $min <= 0.0 ? $this->round_money($price_float) : $this->round_money($price_float - ( $price_float * $min / 100 * $user_share )),
            'confidence'                   => $min <= 0.0 ? 'low' : 'medium',
            'message'                      => $min <= 0.0 ? 'Кэшбэк может быть недоступен для этого типа заказа' : 'Точная ставка зависит от категории товара',
        ));
    }

    public function create_deeplink( array $payload ) {
        $merchant_id = sanitize_text_field((string) ( $payload['merchant_id'] ?? '' ));
        $target_url  = $this->sanitize_url((string) ( $payload['target_url'] ?? '' ));
        if ($target_url === '') {
            return $this->bad_request('Invalid target_url.');
        }

        $merchant = $this->get_merchant($merchant_id);
        if ($merchant === null) {
            return $this->merchant_not_found();
        }

        $click_id = sanitize_text_field((string) ( $payload['click_id'] ?? $payload['subid'] ?? '' ));
        if ($click_id === '') {
            $click_id = $this->new_click_id();
        }

        $cashback_url = add_query_arg(
            array(
                'cashback_internal_click' => $click_id,
                'cashback_source'         => 'price_monitor',
            ),
            $target_url
        );

        return array(
            'cashback_url' => $cashback_url,
            'link_type'    => $merchant['supports_deeplink'] ? 'deeplink' : 'standard_affiliate_url',
            'merchant_id'  => $merchant['merchant_id'],
            'click_id'     => $click_id,
            'expires_at'   => null,
        );
    }

    public function get_user_price_monitor_limits( string $external_user_id ) {
        $external_user_id = sanitize_text_field($external_user_id);
        if (! preg_match('/^wp:[A-Za-z0-9_.:-]+:(\d+)$/', $external_user_id, $m)) {
            return $this->bad_request('Invalid external_user_id.');
        }

        $user_id = (int) $m[1];
        $profile = $this->load_user_profile($user_id);
        if ($profile === null) {
            return new WP_Error('savello_internal_not_found', 'User not found.', array( 'status' => 404 ));
        }

        $rate = max(0.0, min(100.0, (float) ( $profile['cashback_rate'] ?? 60.0 )));

        return array(
            'external_user_id' => $external_user_id,
            'tariff'           => 'basic',
            'limits'           => array(
                'max_tracked_products'        => 20,
                'history_days'                => 30,
                'min_fetch_interval_minutes'  => 360,
                'alerts_per_day'              => 10,
                'manual_refresh_per_day'      => 3,
                'browser_fallback_allowed'    => false,
            ),
            'cashback'         => array(
                'user_share'        => $this->round_rate($rate / 100),
                'cashback_currency' => 'RUB',
            ),
        );
    }

    public function get_manifest(): array {
        $merchants_updated = $this->max_updated_at('merchants');
        $rates_updated     = $this->max_updated_at('rates');
        $version_seed      = wp_json_encode(array( $merchants_updated, $rates_updated, self::VERSION ));

        return array(
            'version'                    => hash('sha256', is_string($version_seed) ? $version_seed : self::VERSION),
            'generated_at'               => gmdate('Y-m-d\TH:i:s\Z'),
            'merchants_updated_at'       => $this->mysql_to_iso($merchants_updated),
            'rates_updated_at'           => $this->mysql_to_iso($rates_updated),
            'deeplink_rules_updated_at'  => $this->mysql_to_iso($merchants_updated),
            'display_policy_updated_at'  => $this->mysql_to_iso($rates_updated),
        );
    }

    public function calculate_user_cashback( float $price, float $commission_rate, float $user_share ): array {
        $user_cashback = $this->round_money($price * $commission_rate / 100 * $user_share);
        return array(
            'user_cashback'   => $user_cashback,
            'effective_price' => $this->round_money($price - $user_cashback),
        );
    }

    private function get_merchant( string $merchant_id ): ?array {
        $merchant_id = sanitize_text_field($merchant_id);
        foreach ($this->load_merchant_rows() as $row) {
            $item = $this->format_merchant_row($row);
            if ($item !== null && $item['merchant_id'] === $merchant_id) {
                return $item;
            }
        }
        return null;
    }

    private function find_merchant_by_url( string $url ): ?array {
        $host = $this->normalize_domain((string) wp_parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }

        foreach ($this->get_merchants(array( 'status' => 'active', 'limit' => 500 ))['items'] as $merchant) {
            foreach ($merchant['domains'] as $domain) {
                if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                    return $merchant;
                }
            }
        }
        return null;
    }

    private function load_merchant_rows(): array {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) {
            return array();
        }

        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';
        $sql = $wpdb->prepare(
            'SELECT p.ID, p.post_title, p.post_status, p.post_modified_gmt AS updated_at,
                    pm_net.meta_value AS network_id,
                    pm_offer.meta_value AS offer_id,
                    pm_domain.meta_value AS store_domain,
                    pm_currency.meta_value AS currency,
                    pm_status.meta_value AS status_raw,
                    pm_url.meta_value AS product_url,
                    n.name AS network_name,
                    n.slug AS network_slug,
                    n.is_active AS network_active
               FROM %i p
          LEFT JOIN %i pm_net ON p.ID = pm_net.post_id AND pm_net.meta_key = %s
          LEFT JOIN %i pm_offer ON p.ID = pm_offer.post_id AND pm_offer.meta_key = %s
          LEFT JOIN %i pm_domain ON p.ID = pm_domain.post_id AND pm_domain.meta_key = %s
          LEFT JOIN %i pm_currency ON p.ID = pm_currency.post_id AND pm_currency.meta_key = %s
          LEFT JOIN %i pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = %s
          LEFT JOIN %i pm_url ON p.ID = pm_url.post_id AND pm_url.meta_key = %s
          LEFT JOIN %i n ON n.id = pm_net.meta_value
              WHERE p.post_type = %s
                AND pm_net.meta_value IS NOT NULL
                AND pm_offer.meta_value IS NOT NULL
           ORDER BY p.ID ASC',
            $wpdb->posts,
            $wpdb->postmeta,
            '_affiliate_network_id',
            $wpdb->postmeta,
            '_offer_id',
            $wpdb->postmeta,
            '_store_domain',
            $wpdb->postmeta,
            '_cashback_campaign_currency',
            $wpdb->postmeta,
            '_cashback_campaign_status_raw',
            $wpdb->postmeta,
            '_product_url',
            $networks_table,
            'product'
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built exclusively by $wpdb->prepare() above.
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    private function format_merchant_row( array $row ): ?array {
        $product_id = (int) ( $row['ID'] ?? 0 );
        $offer_id   = trim((string) ( $row['offer_id'] ?? '' ));
        $network_id = (int) ( $row['network_id'] ?? 0 );
        $domain     = $this->normalize_domain((string) ( $row['store_domain'] ?? '' ));
        if ($product_id <= 0 || $offer_id === '' || $network_id <= 0 || $domain === '') {
            return null;
        }

        $network_active = (int) ( $row['network_active'] ?? 1 ) === 1;
        $status_raw     = strtolower((string) ( $row['status_raw'] ?? '' ));
        $post_status    = (string) ( $row['post_status'] ?? '' );
        $status         = 'active';
        if (! $network_active || ! in_array($post_status, array( 'publish', 'draft' ), true)) {
            $status = 'disabled';
        } elseif (in_array($status_raw, array( 'paused', 'suspend', 'suspended', 'disabled', 'declined' ), true) || $post_status !== 'publish') {
            $status = $status_raw === 'paused' ? 'paused' : 'disabled';
        }

        $currency = strtoupper((string) ( $row['currency'] ?? 'RUB' ));
        if ($currency === '') {
            $currency = 'RUB';
        }

        return array(
            'merchant_id'           => (string) $product_id,
            'merchant_name'         => (string) ( $row['post_title'] ?? ( $row['network_name'] ?? $domain ) ),
            'network'               => (string) ( $row['network_slug'] ?? '' ),
            'offer_id'              => $offer_id,
            'program_id'            => $offer_id,
            'status'                => $status,
            'domains'               => array( $domain ),
            'domain_aliases'        => array_values(array_unique(array( 'www.' . $domain ))),
            'allowed_regions'       => array(),
            'default_currency'      => $currency,
            'supports_deeplink'     => (string) ( $row['product_url'] ?? '' ) !== '',
            'supports_product_feed' => false,
            'cashback_available'    => $status === 'active',
            'updated_at'            => $this->mysql_to_iso((string) ( $row['updated_at'] ?? '' )),
            '_network_id'           => $network_id,
            '_product_url'          => (string) ( $row['product_url'] ?? '' ),
        );
    }

    private function load_rates_for_merchant( array $merchant ): array {
        if (! class_exists('Cashback_Shop_Tariff_Sync')) {
            return array();
        }
        return Cashback_Shop_Tariff_Sync::get_active((int) $merchant['_network_id'], (string) $merchant['offer_id']);
    }

    private function format_rate_row( string $merchant_id, array $row ): ?array {
        $type = (string) ( $row['tariff_type'] ?? '' );
        if (! in_array($type, array( 'percent', 'fix' ), true)) {
            return null;
        }

        $min = $this->nullable_float($row['payment_min'] ?? null);
        $max = $this->nullable_float($row['payment_max'] ?? null);
        $exact = $min === null && $max === null ? (float) ( $row['payment_size'] ?? 0.0 ) : null;
        $display_policy = 'show_exact_rate';
        $confidence_policy = 'exact';
        if ($exact === null) {
            $display_policy = (float) $min <= 0.0 ? 'show_possible_do_not_reduce_effective_price' : 'show_range_use_min_for_effective_price';
            $confidence_policy = 'estimated_range';
        }

        return array(
            'merchant_id'       => $merchant_id,
            'rate_id'           => (string) ( $row['tariff_id'] ?? '' ),
            'external_rate_id'  => (string) ( $row['tariff_id'] ?? '' ),
            'name'              => (string) ( $row['name'] ?? '' ),
            'status'            => empty($row['is_deleted']) ? 'active' : 'disabled',
            'rate_type'         => $type,
            'commission_exact'  => $exact,
            'commission_min'    => $min,
            'commission_max'    => $max,
            'currency'          => $type === 'fix' ? strtoupper((string) ( $row['currency'] ?? 'RUB' )) : null,
            'action_type'       => 'sale',
            'order_type'        => 'affiliate',
            'geo'               => array(),
            'category_id'       => null,
            'category_name'     => null,
            'product_group'     => (string) ( $row['tariff_id'] ?? '' ),
            'is_hot_product'    => false,
            'is_affiliate_order' => true,
            'is_non_affiliate_order' => false,
            'valid_from'        => $this->mysql_to_iso((string) ( $row['imported_at'] ?? '' )),
            'valid_to'          => null,
            'updated_at'        => $this->mysql_to_iso((string) ( $row['updated_at'] ?? '' )),
            'confidence_policy' => $confidence_policy,
            'display_policy'    => $display_policy,
        );
    }

    private function select_rate( array $rates, string $preferred_id ): ?array {
        foreach ($rates as $row) {
            if ((string) ( $row['tariff_id'] ?? '' ) === $preferred_id) {
                return $row;
            }
        }
        return $rates[0] ?? null;
    }

    private function load_user_profile( int $user_id ): ?array {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb) || $user_id <= 0) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT user_id, cashback_rate, status FROM %i WHERE user_id = %d LIMIT 1',
                $wpdb->prefix . 'cashback_user_profile',
                $user_id
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    private function max_updated_at( string $kind ): ?string {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) {
            return null;
        }

        if ($kind === 'rates') {
            $value = $wpdb->get_var('SELECT MAX(updated_at) FROM ' . $wpdb->prefix . 'cashback_shop_tariffs');
            return is_string($value) && $value !== '' ? $value : null;
        }

        $value = $wpdb->get_var('SELECT MAX(post_modified_gmt) FROM ' . $wpdb->posts . " WHERE post_type = 'product'");
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function normalize_domain( string $value ): string {
        $value = trim(strtolower($value));
        $value = preg_replace('#^https?://#i', '', $value) ?? '';
        $value = preg_replace('#^www\.#i', '', $value) ?? '';
        $value = explode('/', $value)[0];
        return trim($value);
    }

    private function sanitize_url( string $url ): string {
        $url = esc_url_raw($url);
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, array( 'http', 'https' ), true) ? $url : '';
    }

    private function sanitize_status( string $status ): string {
        $status = sanitize_key($status);
        return in_array($status, array( 'active', 'paused', 'disabled', 'all' ), true) ? $status : 'active';
    }

    private function guest_user_share(): float {
        if (class_exists('Cashback_Shop_Options')) {
            return $this->round_rate(Cashback_Shop_Options::get_guest_display_rate() / 100);
        }
        return 0.6;
    }

    private function nullable_float( mixed $value ): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }

    private function round_money( float $value ): float {
        return round($value, 2);
    }

    private function round_rate( float $value ): float {
        return round($value, 3);
    }

    private function mysql_to_iso( ?string $value ): ?string {
        if ($value === null || trim($value) === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        $timestamp = strtotime($value . ' UTC');
        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function new_click_id(): string {
        if (function_exists('cashback_generate_uuid7')) {
            return cashback_generate_uuid7(false);
        }
        return str_replace('-', '', wp_generate_uuid4());
    }

    private function bad_request( string $message ): WP_Error {
        return new WP_Error('savello_internal_bad_request', $message, array( 'status' => 400 ));
    }

    private function merchant_not_found(): WP_Error {
        return new WP_Error('savello_internal_merchant_not_found', 'Merchant not found.', array( 'status' => 404 ));
    }
}
