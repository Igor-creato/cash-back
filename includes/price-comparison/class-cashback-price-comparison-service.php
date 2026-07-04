<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Price_Comparison_Service {

    public const META_CITY = 'cashback_price_comparison_city';

    private object $client;
    private $cashback_resolver;
    private array $cashback_cache = array();

    public function __construct( ?object $client = null, ?callable $cashback_resolver = null ) {
        $this->client            = $client ?: new Cashback_Price_Comparison_Client();
        $this->cashback_resolver = $cashback_resolver ?: array( $this, 'resolve_cashback' );
    }

    public function search(
        string $city,
        string $query,
        int $user_id = 0,
        array $stores = array(),
        int $limit = 50,
        int $offset = 0
    ): array|WP_Error {
        $city  = trim($city);
        $query = trim($query);
        if ($city === '') {
            return new WP_Error(
                'INVALID_CITY',
                'Укажите город для поиска.',
                array( 'status' => 400 )
            );
        }
        if ($query === '') {
            return new WP_Error(
                'INVALID_QUERY',
                'Укажите название товара.',
                array( 'status' => 400 )
            );
        }

        $this->save_city_for_user($city, $user_id);

        $result = $this->client->search(
            array(
                'query'  => $query,
                'city'   => $city,
                'stores' => array_values(array_map('sanitize_text_field', $stores)),
                'limit'  => max(1, min(50, $limit)),
                'offset' => max(0, $offset),
            )
        );

        if ($result instanceof WP_Error) {
            return $result;
        }

        $items = array();
        foreach ((array) ( $result['items'] ?? array() ) as $item) {
            if (is_array($item)) {
                $items[] = $this->enrich_item($item, $user_id);
            }
        }
        $result['items'] = $items;

        return $result;
    }

    public function start_live_search(
        string $city,
        string $query,
        int $user_id = 0,
        array $stores = array(),
        int $limit = 20,
        int $timeout_seconds = 120
    ): array|WP_Error {
        $city  = trim($city);
        $query = trim($query);
        if ($city === '') {
            return new WP_Error(
                'INVALID_CITY',
                'Укажите город для поиска.',
                array( 'status' => 400 )
            );
        }
        if ($query === '') {
            return new WP_Error(
                'INVALID_QUERY',
                'Укажите название товара.',
                array( 'status' => 400 )
            );
        }

        $this->save_city_for_user($city, $user_id);

        return $this->client->start_live_search(
            array(
                'query'           => $query,
                'city'            => $city,
                'stores'          => array_values(array_map('sanitize_text_field', $stores)),
                'limit'           => max(1, min(50, $limit)),
                'timeout_seconds' => max(10, min(180, $timeout_seconds)),
                'mode'            => 'live',
            )
        );
    }

    public function get_live_search( string $run_id, int $user_id = 0 ): array|WP_Error {
        $run_id = trim($run_id);
        if (preg_match('/\A[A-Za-z0-9_-]{8,80}\z/', $run_id) !== 1) {
            return new WP_Error(
                'INVALID_LIVE_SEARCH_RUN',
                'Live поиск не найден.',
                array( 'status' => 404 )
            );
        }

        $result = $this->client->get_live_search($run_id);
        if ($result instanceof WP_Error) {
            return $result;
        }

        $items = array();
        foreach ((array) ( $result['items'] ?? array() ) as $item) {
            if (is_array($item)) {
                $items[] = $this->enrich_item($item, $user_id);
            }
        }
        $result['items'] = $items;

        return $result;
    }

    private function save_city_for_user( string $city, int $user_id ): void {
        if ($user_id <= 0 || !function_exists('update_user_meta')) {
            return;
        }

        update_user_meta($user_id, self::META_CITY, sanitize_text_field($city));
    }

    private function enrich_item( array $item, int $user_id ): array {
        $url = esc_url_raw((string) ( $item['action_url'] ?? '' ));
        if ($url === '') {
            $url = esc_url_raw((string) ( $item['url'] ?? '' ));
        }

        $item['action_label']    = 'Купить';
        $item['action_url']      = $url;
        $item['cashback_status'] = 'unknown';
        $item['cashback_note']   = 'Кэшбэк не определён';

        if ($url === '') {
            return $item;
        }

        $cashback = $this->cashback_for_url($url, $user_id);
        if ($cashback instanceof WP_Error || empty($cashback['cashback_available'])) {
            return $item;
        }

        $action_url              = (string) ( $cashback['activation_page_url'] ?? $cashback['cashback_url'] ?? $url );
        $item['action_label']    = (string) ( $cashback['button_text'] ?? 'Активировать кэшбэк' );
        $item['action_url']      = esc_url_raw($action_url);
        $item['cashback_status'] = 'available';
        $item['cashback_note']   = '';

        return $item;
    }

    private function cashback_for_url( string $url, int $user_id ): array|WP_Error {
        $cache_key = $user_id . '|' . $url;
        if (array_key_exists($cache_key, $this->cashback_cache)) {
            return $this->cashback_cache[ $cache_key ];
        }

        $resolver = $this->cashback_resolver;
        $result   = $resolver(
            array(
                'direct_url'        => $url,
                'user_id'           => $user_id,
                'client_request_id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : '',
                'ip_address'        => class_exists('Cashback_Encryption') ? Cashback_Encryption::get_client_ip() : '',
                'user_agent'        => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : null,
            )
        );

        $this->cashback_cache[ $cache_key ] = $result;
        return $result;
    }

    private function resolve_cashback( array $payload ): array|WP_Error {
        if (!class_exists('Savello_Cashback_Internal_API_Service')) {
            $path = dirname(__DIR__) . '/services/class-cashback-internal-api-service.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }

        if (!class_exists('Savello_Cashback_Internal_API_Service')) {
            return new WP_Error(
                'cashback_lookup_unavailable',
                'Кэшбэк не определён.',
                array( 'status' => 503 )
            );
        }

        return (new Savello_Cashback_Internal_API_Service())->resolve_direct_product_link($payload);
    }
}
