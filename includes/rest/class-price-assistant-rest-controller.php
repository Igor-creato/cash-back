<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Cashback_Price_Assistant_REST_Controller {

    private const NAMESPACE       = 'cashback/v1';
    private const CONSENT_VERSION = 'price-assistant-session-v1';
    private const MARKETPLACES    = array(
        'ozon'          => 'Ozon',
        'wildberries'   => 'Wildberries',
        'yandex_market' => 'Yandex Market',
    );
    private const MARKETPLACE_PAGE_URLS = array(
        'ozon'          => array(
            'login'     => 'https://www.ozon.ru/my/main',
            'cart'      => 'https://www.ozon.ru/cart',
            'favorites' => 'https://www.ozon.ru/my/favorites',
        ),
        'wildberries'   => array(
            'login'     => 'https://www.wildberries.ru/lk',
            'cart'      => 'https://www.wildberries.ru/lk/basket',
            'favorites' => 'https://www.wildberries.ru/lk/favorites',
        ),
        'yandex_market' => array(
            'login'     => 'https://market.yandex.ru/my/orders',
            'cart'      => 'https://market.yandex.ru/my/cart',
            'favorites' => 'https://market.yandex.ru/my/wishlist',
        ),
    );
    private const MARKETPLACE_HOST_PERMISSIONS = array( 'ozon'          => array( 'https://*.ozon.ru/*' ), 'wildberries'   => array( 'https://*.wildberries.ru/*' ), 'yandex_market' => array( 'https://market.yandex.ru/*', 'https://*.market.yandex.ru/*' ) );

    private Cashback_Price_Assistant_Proxy_Client $client;

    public function __construct( ?Cashback_Price_Assistant_Proxy_Client $client = null ) {
        $this->client = $client ?? new Cashback_Price_Assistant_Proxy_Client();
    }

    public static function init(): void {
        add_action('rest_api_init', static function (): void {
            (new self())->register_routes();
        });
    }

    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/price-assistant/consent', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_consent' ),
            'permission_callback' => array( $this, 'check_read_permission' ),
        ));

        register_rest_route(self::NAMESPACE, '/price-assistant/connections', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'list_connections' ),
                'permission_callback' => array( $this, 'check_read_permission' ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'create_connection' ),
                'permission_callback' => array( $this, 'check_write_permission' ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/price-assistant/connections/(?P<connection_id>\d+)/session-bundle', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'upload_session_bundle' ),
            'permission_callback' => array( $this, 'check_write_permission' ),
        ));

        register_rest_route(self::NAMESPACE, '/price-assistant/connections/(?P<connection_id>\d+)/immediate-import', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'immediate_import' ),
            'permission_callback' => array( $this, 'check_write_permission' ),
        ));

        register_rest_route(self::NAMESPACE, '/price-assistant/connections/(?P<connection_id>\d+)', array(
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => array( $this, 'disconnect' ),
            'permission_callback' => array( $this, 'check_write_permission' ),
        ));

        register_rest_route(self::NAMESPACE, '/price-assistant/sync-status', array( 'methods'             => WP_REST_Server::READABLE, 'callback'            => array( $this, 'list_connections' ), 'permission_callback' => array( $this, 'check_read_permission' ) ));
register_rest_route(self::NAMESPACE, '/price-assistant/search', array( 'methods'             => WP_REST_Server::READABLE, 'callback'            => array( $this, 'search_products' ), 'permission_callback' => array( $this, 'check_read_permission' ) ));
register_rest_route(self::NAMESPACE, '/price-assistant/collections', array( 'methods'             => WP_REST_Server::READABLE, 'callback'            => array( $this, 'get_collections' ), 'permission_callback' => array( $this, 'check_read_permission' ) ));
register_rest_route(self::NAMESPACE, '/price-assistant/collections/(?P<collection_id>\d+)', array( 'methods'             => WP_REST_Server::DELETABLE, 'callback'            => array( $this, 'delete_collection' ), 'permission_callback' => array( $this, 'check_write_permission' ) ));
register_rest_route(self::NAMESPACE, '/price-assistant/watchlist/items', array( array( 'methods'             => WP_REST_Server::READABLE, 'callback'            => array( $this, 'get_watchlist_items' ), 'permission_callback' => array( $this, 'check_read_permission' ) ), array( 'methods'             => WP_REST_Server::CREATABLE, 'callback'            => array( $this, 'create_watchlist_item' ), 'permission_callback' => array( $this, 'check_write_permission' ) ) ));
register_rest_route(self::NAMESPACE, '/price-assistant/watchlist/items/(?P<subscription_id>\d+)', array( array( 'methods'             => WP_REST_Server::EDITABLE, 'callback'            => array( $this, 'update_watchlist_item' ), 'permission_callback' => array( $this, 'check_write_permission' ) ), array( 'methods'             => WP_REST_Server::DELETABLE, 'callback'            => array( $this, 'delete_watchlist_item' ), 'permission_callback' => array( $this, 'check_write_permission' ) ) ));
register_rest_route(self::NAMESPACE, '/price-assistant/watchlist/items/(?P<subscription_id>\d+)/cashback-link', array( 'methods'             => WP_REST_Server::CREATABLE, 'callback'            => array( $this, 'create_cashback_link' ), 'permission_callback' => array( $this, 'check_write_permission' ) ));
register_rest_route(self::NAMESPACE, '/price-assistant/user-region', array( 'methods'             => WP_REST_Server::EDITABLE, 'callback'            => array( $this, 'update_user_region' ), 'permission_callback' => array( $this, 'check_write_permission' ) ));
register_rest_route(self::NAMESPACE, '/price-assistant/products/(?P<tracked_product_id>\d+)/chart', array( 'methods'             => WP_REST_Server::READABLE, 'callback'            => array( $this, 'get_chart' ), 'permission_callback' => array( $this, 'check_read_permission' ) ));

        register_rest_route(self::NAMESPACE, '/price-assistant/products/(?P<tracked_product_id>\d+)/compare', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_compare' ),
            'permission_callback' => array( $this, 'check_read_permission' ),
        ));
    }

    public function check_read_permission( WP_REST_Request $request ) {
        return $this->check_permission($request, 'cashback_price_assistant_read');
    }

    public function check_write_permission( WP_REST_Request $request ) {
        return $this->check_permission($request, 'cashback_price_assistant_write');
    }

    public function get_consent( WP_REST_Request $request ): WP_REST_Response {
        unset($request);

        return new WP_REST_Response(array(
            'consent_version' => self::CONSENT_VERSION,
            'text'            => 'Мы сохраним технический токен доступа к корзине/избранному. Логин и пароль не сохраняются',
            'scope'           => array( 'cart_read', 'favorites_read' ),
            'connector'       => array(
                'mode'               => 'browser_cookies_api_after_explicit_consent',
                'session_bundle_url' => '/connections/{connection_id}/session-bundle',
                'immediate_import_url' => '/connections/{connection_id}/immediate-import',
            ),
            'marketplaces'    => $this->enabled_marketplaces(),
        ), 200);
    }

    public function list_connections( WP_REST_Request $request ): WP_REST_Response {
        unset($request);
        return $this->proxy_get('/v1/marketplace-connections');
    }

    public function create_connection( WP_REST_Request $request ): WP_REST_Response {
        $marketplace = $this->marketplace_from_request($request);
        if ($marketplace === '') {
            return $this->error_response('invalid_marketplace', 400);
        }
        if (! $this->marketplace_enabled($marketplace)) {
            return $this->error_response('marketplace_disabled', 423);
        }

        $payload = $this->owner_payload(array(
            'marketplace'        => $marketplace,
            'consent_version'    => $this->string_param($request, 'consent_version', self::CONSENT_VERSION),
            'scope'              => $this->scope_param($request),
            'captured_at'        => $this->string_param($request, 'captured_at', gmdate('Y-m-d\TH:i:s\Z')),
            'connector_version'  => $this->string_param($request, 'connector_version', 'wordpress-proxy-0.1.0'),
        ));

        $expires_at = $this->string_param($request, 'expires_at', '');
        if ($expires_at !== '') {
            $payload['expires_at'] = $expires_at;
        }

        return $this->proxy_result($this->client->request('POST', '/v1/marketplace-connections', $payload));
    }

    public function upload_session_bundle( WP_REST_Request $request ): WP_REST_Response {
        $connection_id = absint($request->get_param('connection_id'));
        if ($connection_id <= 0) {
            return $this->error_response('invalid_connection_id', 400);
        }

        if (! (bool) $request->get_param('consent')) {
            return $this->error_response('consent_required', 400);
        }

        $marketplace = $this->marketplace_from_request($request);
        if ($marketplace !== '' && ! $this->marketplace_enabled($marketplace)) {
            return $this->error_response('marketplace_disabled', 423);
        }

        $session_bundle = $request->get_param('session_bundle');
        if (! is_array($session_bundle)) {
            return $this->error_response('invalid_session_bundle', 400);
        }

        $payload = $this->owner_payload(array(
            'consent_version'   => $this->string_param($request, 'consent_version', self::CONSENT_VERSION),
            'scope'             => $this->scope_param($request),
            'captured_at'       => $this->string_param($request, 'captured_at', gmdate('Y-m-d\TH:i:s\Z')),
            'connector_version' => $this->string_param($request, 'connector_version', 'wordpress-proxy-0.1.0'),
            'session_bundle'    => $session_bundle,
        ));

        $expires_at = $this->string_param($request, 'expires_at', '');
        if ($expires_at !== '') {
            $payload['expires_at'] = $expires_at;
        }

        return $this->proxy_result($this->client->request(
            'POST',
            '/v1/marketplace-connections/' . $connection_id . '/session-bundle',
            $payload,
            array(),
            'session-bundle-' . $connection_id . '-' . $this->external_user_id()
        ));
    }

    public function immediate_import( WP_REST_Request $request ): WP_REST_Response {
        $connection_id = absint($request->get_param('connection_id'));
        if ($connection_id <= 0) {
            return $this->error_response('invalid_connection_id', 400);
        }

        if (! (bool) $request->get_param('consent')) {
            return $this->error_response('consent_required', 400);
        }

        $marketplace = $this->marketplace_from_request($request);
        if ($marketplace === '') {
            return $this->error_response('invalid_marketplace', 400);
        }
        if (! $this->marketplace_enabled($marketplace)) {
            return $this->error_response('marketplace_disabled', 423);
        }

        $collection_type = sanitize_key((string) $request->get_param('collection_type'));
        if (! in_array($collection_type, array( 'cart', 'favorites' ), true)) {
            return $this->error_response('invalid_collection_type', 400);
        }

        $items = $request->get_param('items');
        if (! is_array($items)) {
            return $this->error_response('invalid_items', 400);
        }
        $sanitized_items = array_values(array_filter(array_map(array( $this, 'sanitize_import_item' ), $items)));

        $captured_at = $this->string_param($request, 'captured_at', gmdate('Y-m-d\TH:i:s\Z'));
        $session = $this->client->request(
            'POST', '/v1/sync-sessions', $this->owner_payload(array( 'connection_id'   => $connection_id, 'collection_type' => $collection_type, 'started_at'      => $captured_at )), array(),
            'sync-session-' . $connection_id . '-' . $collection_type . '-' . $this->external_user_id()
        );
        if ((int) ( $session['status'] ?? 502 ) < 200 || (int) ( $session['status'] ?? 502 ) >= 300) {
            return $this->proxy_result($session);
        }

        $sync_session_id = $this->sync_session_id($session);
        if ($sync_session_id <= 0) {
            return $this->error_response('invalid_sync_session', 502);
        }

        $items_result = $this->client->request(
            'POST', '/v1/sync-sessions/' . $sync_session_id . '/items', $this->owner_payload(array( 'items' => $sanitized_items )), array(),
            'sync-items-' . $sync_session_id . '-' . $this->external_user_id()
        );
        if ((int) ( $items_result['status'] ?? 502 ) < 200 || (int) ( $items_result['status'] ?? 502 ) >= 300) {
            return $this->proxy_result($items_result);
        }

        return $this->proxy_result($this->client->request(
            'POST', '/v1/sync-sessions/' . $sync_session_id . '/finish', $this->owner_payload(array( 'status'      => 'succeeded', 'finished_at' => gmdate('Y-m-d\TH:i:s\Z') )), array(), 'sync-finish-' . $sync_session_id . '-' . $this->external_user_id()
        ));
    }

    public function disconnect( WP_REST_Request $request ): WP_REST_Response {
        $connection_id = absint($request->get_param('connection_id'));
        if ($connection_id <= 0) {
            return $this->error_response('invalid_connection_id', 400);
        }

        return $this->proxy_result($this->client->request(
            'POST',
            '/v1/marketplace-connections/' . $connection_id . '/disconnect',
            $this->owner_payload(),
            array(),
            'disconnect-' . $connection_id . '-' . $this->external_user_id()
        ));
    }

    public function get_collections( WP_REST_Request $request ): WP_REST_Response {
        unset($request);
return $this->proxy_get('/v1/collections');
}

    public function search_products( WP_REST_Request $request ): WP_REST_Response {
        $query_text = $this->string_param($request, 'q', '');
if ($query_text === '') {
return $this->error_response('invalid_search_query', 400);
}

        $query = $this->owner_query();
$query['q'] = $query_text;
foreach (array( 'region_code', 'limit' ) as $key) {
$value = $request->get_param($key);
if ($value !== null && $value !== '') {
$query[ $key ] = sanitize_text_field((string) $value);
}
        }

        return $this->proxy_result($this->client->request(
            'GET', '/v1/price-assistant/search', null, $query
        ));
    }

    public function delete_collection( WP_REST_Request $request ): WP_REST_Response {
        $collection_id = absint($request->get_param('collection_id'));
if ($collection_id <= 0) {
return $this->error_response('invalid_collection_id', 400);
}

        return $this->proxy_result($this->client->request(
            'DELETE', '/v1/collections/' . $collection_id, null, $this->owner_query(), 'collection-delete-' . $collection_id . '-' . $this->external_user_id()
        ));
}

    public function get_watchlist_items( WP_REST_Request $request ): WP_REST_Response {
        $query = $this->owner_query();
foreach (array( 'active_only', 'limit' ) as $key) {
$value = $request->get_param($key);
if ($value !== null && $value !== '') {
$query[ $key ] = sanitize_text_field((string) $value);
}
        }
        return $this->proxy_result($this->client->request('GET', '/v1/watchlist/items', null, $query));
}

    public function create_watchlist_item( WP_REST_Request $request ): WP_REST_Response {
        $product_url = esc_url_raw((string) $request->get_param('product_url'));
if ($product_url === '') {
return $this->error_response('invalid_product_url', 400);
}

        $payload = $this->owner_payload(array( 'product_url' => $product_url, 'region_code' => $this->string_param($request, 'region_code', 'default') ));
foreach (array( 'target_price', 'target_effective_price' ) as $key) {
$value = $this->decimal_string_param($request, $key);
if ($value !== null) {
$payload[ $key ] = $value;
}
        }

        return $this->proxy_result($this->client->request(
            'POST', '/v1/watchlist/items', $payload, array(), 'watchlist-create-' . $this->external_user_id()
        ));
}

    public function update_watchlist_item( WP_REST_Request $request ): WP_REST_Response {
        $subscription_id = absint($request->get_param('subscription_id'));
if ($subscription_id <= 0) {
return $this->error_response('invalid_subscription_id', 400);
}

        $payload = array();
foreach (array( 'target_price', 'target_effective_price' ) as $key) {
$value = $this->decimal_string_param($request, $key);
if ($value !== null) {
$payload[ $key ] = $value;
}
        }
        if ($request->get_param('is_active') !== null) {
$payload['is_active'] = (bool) $request->get_param('is_active');
}

        return $this->proxy_result($this->client->request(
            'PATCH', '/v1/watchlist/items/' . $subscription_id, $payload, $this->owner_query(), 'watchlist-update-' . $subscription_id . '-' . $this->external_user_id()
        ));
}

    public function delete_watchlist_item( WP_REST_Request $request ): WP_REST_Response {
        $subscription_id = absint($request->get_param('subscription_id'));
if ($subscription_id <= 0) {
return $this->error_response('invalid_subscription_id', 400);
}

        return $this->proxy_result($this->client->request(
            'DELETE', '/v1/watchlist/items/' . $subscription_id, null, $this->owner_query(), 'watchlist-delete-' . $subscription_id . '-' . $this->external_user_id()
        ));
}

    public function create_cashback_link( WP_REST_Request $request ): WP_REST_Response {
        $subscription_id = absint($request->get_param('subscription_id'));
if ($subscription_id <= 0) {
return $this->error_response('invalid_subscription_id', 400);
}

        return $this->proxy_result($this->client->request(
            'POST', '/v1/watchlist/items/' . $subscription_id . '/cashback-link', $this->owner_payload(), array(), 'cashback-link-' . $subscription_id . '-' . $this->external_user_id()
        ));
}

    public function update_user_region( WP_REST_Request $request ): WP_REST_Response {
        $region_code = $this->string_param($request, 'region_code', '');
if ($region_code === '') {
return $this->error_response('invalid_region_code', 400);
}

        $payload = $this->owner_payload(array( 'region_code' => $region_code ));
$country_code = $this->string_param($request, 'country_code', '');
if ($country_code !== '') {
$payload['country_code'] = strtoupper(substr($country_code, 0, 8));
}

        return $this->proxy_result($this->client->request(
            'PATCH', '/v1/user-region', $payload, array(), 'user-region-' . $this->external_user_id()
        ));
}

    public function get_chart( WP_REST_Request $request ): WP_REST_Response {
        $product_id = absint($request->get_param('tracked_product_id'));
        if ($product_id <= 0) {
            return $this->error_response('invalid_product_id', 400);
        }

        $query = $this->owner_query();
        foreach (array( 'days', 'granularity', 'currency' ) as $key) {
            $value = $request->get_param($key);
            if ($value !== null && $value !== '') {
                $query[$key] = sanitize_text_field((string) $value);
            }
        }

        return $this->proxy_result($this->client->request(
            'GET',
            '/v1/products/' . $product_id . '/price-chart',
            null,
            $query
        ));
    }

    public function get_compare( WP_REST_Request $request ): WP_REST_Response {
        $product_id = absint($request->get_param('tracked_product_id'));
        if ($product_id <= 0) {
            return $this->error_response('invalid_product_id', 400);
        }

        return $this->proxy_result($this->client->request(
            'GET',
            '/v1/products/' . $product_id . '/compare',
            null,
            $this->owner_query()
        ));
    }

    public static function marketplace_option_name( string $marketplace ): string {
        return 'price_monitor_marketplace_' . sanitize_key($marketplace) . '_enabled';
    }

    public static function marketplaces(): array {
        return self::MARKETPLACES;
    }

    public static function connector_marketplaces(): array {
        $marketplaces = array();
foreach (self::MARKETPLACES as $code => $label) {
$marketplace = array( 'code'             => $code, 'label'            => $label, 'enabled'          => (int) get_option(self::marketplace_option_name($code), 0) === 1, 'access_status'    => 'available', 'disabled_reason'  => '', 'page_urls'        => self::MARKETPLACE_PAGE_URLS[ $code ], 'host_permissions' => self::MARKETPLACE_HOST_PERMISSIONS[ $code ], 'allowlist'        => array( 'cookies' => array(), 'tokens'  => array() ) );
if ( 'ozon' === $code ) {
$marketplace['enabled']         = false;
$marketplace['access_status']   = 'requires_official_access';
$marketplace['disabled_reason'] = 'Требуется официальный доступ Ozon к consumer OAuth/API для корзины и избранного.';
}
            $marketplaces[] = $marketplace;
}
        return $marketplaces;
    }

    private function proxy_get( string $path ): WP_REST_Response {
        return $this->proxy_result($this->client->request('GET', $path, null, $this->owner_query()));
    }

    private function proxy_result( array $result ): WP_REST_Response {
        $status = (int) ( $result['status'] ?? 502 );
        $data   = is_array($result['data'] ?? null) ? $result['data'] : array();
        return new WP_REST_Response($data, $status);
    }

    private function owner_payload( array $extra = array() ): array {
        return array_merge(array(
            'site_id'          => $this->site_id(),
            'external_user_id' => $this->external_user_id(),
        ), $extra);
    }

    private function owner_query(): array {
        return array(
            'site_id'          => $this->site_id(),
            'external_user_id' => $this->external_user_id(),
        );
    }

    private function check_permission( WP_REST_Request $request, string $rate_action ) {
        if (! is_user_logged_in()) {
            return new WP_Error('price_assistant_login_required', 'Login is required.', array( 'status' => 401 ));
        }

        $nonce = trim($request->get_header('X-WP-Nonce'));
        if ($nonce === '') {
            $nonce = trim((string) $request->get_param('_wpnonce'));
        }
        if ($nonce === '' || ! wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('price_assistant_nonce_required', 'REST nonce is required.', array( 'status' => 403 ));
        }

        if ((int) get_option(Cashback_Price_Assistant_Proxy_Client::OPTION_ENABLED, 0) !== 1) {
            return new WP_Error('price_assistant_disabled', 'Price assistant is disabled.', array( 'status' => 503 ));
        }

        if (class_exists('Cashback_Rate_Limiter')) {
            $limit = Cashback_Rate_Limiter::check($rate_action, get_current_user_id(), $this->client_ip());
            if (empty($limit['allowed'])) {
                return new WP_Error(
                    'price_assistant_rate_limited',
                    'Too many requests.',
                    array(
                        'status'      => 429,
                        'retry_after' => (int) ( $limit['retry_after'] ?? 60 ),
                    )
                );
            }
        }

        return true;
    }

    private function marketplace_from_request( WP_REST_Request $request ): string {
        $marketplace = sanitize_key((string) ( $request->get_param('marketplace') ?? $request->get_param('source') ?? '' ));
        return array_key_exists($marketplace, self::MARKETPLACES) ? $marketplace : '';
    }

    private function marketplace_enabled( string $marketplace ): bool {
        return (int) get_option(self::marketplace_option_name($marketplace), 0) === 1;
    }

    private function enabled_marketplaces(): array {
        return self::connector_marketplaces();
    }

    private function scope_param( WP_REST_Request $request ): array {
        $scope = $request->get_param('scope');
        if (! is_array($scope) || $scope === array()) {
            return array( 'cart_read', 'favorites_read' );
        }
        return array_values(array_filter(array_map(
            static fn( $value ): string => sanitize_key((string) $value),
            $scope
        )));
    }

    private function string_param( WP_REST_Request $request, string $key, string $fallback ): string {
        $value = $request->get_param($key);
if (! is_scalar($value)) {
return $fallback;
}
        $value = sanitize_text_field((string) $value);
        return $value === '' ? $fallback : $value;
}

    private function decimal_string_param( WP_REST_Request $request, string $key ): ?string {
        $value = $request->get_param($key);
if ($value === null || $value === '') {
return null;
}
        if (! is_scalar($value) || ! is_numeric($value)) {
return null;
}
        if ((float) $value < 0) {
return null;
}
        return number_format((float) $value, 2, '.', '');
}

    private function sanitize_import_item( mixed $item ): array {
        if (! is_array($item)) {
return array();
}

        $external_item_id = $this->first_text_value($item, array( 'external_item_id', 'source_item_id', 'product_id', 'sku' ), 191);
$product_url      = $this->first_url_value($item, array( 'product_url', 'url', 'source_url' ));
if ($external_item_id === '' || $product_url === '') {
return array();
}

        $sanitized = array( 'external_item_id' => $external_item_id, 'product_url'      => $product_url );
$source_product_id = $this->first_text_value($item, array( 'source_product_id', 'product_id', 'sku' ), 191);
if ($source_product_id !== '') {
$sanitized['source_product_id'] = $source_product_id;
}
        $title = $this->first_text_value($item, array( 'title' ), 512);
if ($title !== '') {
$sanitized['title'] = $title;
}
        if (isset($item['quantity']) && is_numeric($item['quantity'])) {
$sanitized['quantity'] = max(1, absint($item['quantity']));
}
        $raw_json = array();
foreach (array( 'price', 'currency', 'availability', 'image_url', 'collected_at' ) as $key) {
if (isset($item[ $key ]) && is_scalar($item[ $key ])) {
$raw_json[ $key ] = sanitize_text_field((string) $item[ $key ]);
}
        }
        if ($raw_json !== array()) {
$sanitized['raw_json'] = $raw_json;
}

        return $sanitized;
}

    private function first_text_value( array $item, array $keys, int $max_length ): string {
        foreach ($keys as $key) {
if (isset($item[ $key ]) && is_scalar($item[ $key ])) {
$value = sanitize_text_field((string) $item[ $key ]);
if ($value !== '') {
return substr($value, 0, $max_length);
}
            }
        }
        return '';
}

    private function first_url_value( array $item, array $keys ): string {
        foreach ($keys as $key) {
if (isset($item[ $key ]) && is_scalar($item[ $key ])) {
$value = esc_url_raw((string) $item[ $key ]);
if ($value !== '') {
return $value;
}
            }
        }
        return '';
    }

    private function sync_session_id( array $result ): int {
        $data = is_array($result['data'] ?? null) ? $result['data'] : array();
        return absint($data['sync_session_id'] ?? $data['id'] ?? 0);
    }

    private function site_id(): string {
        return Cashback_Price_Assistant_Proxy_Client::sanitize_site_id(get_option(Cashback_Price_Assistant_Proxy_Client::OPTION_SITE_ID, ''));
    }

    private function external_user_id(): string {
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $host = preg_replace('/[^a-z0-9_.:-]/', '', $host) ?: 'site';
        return 'wp:' . $host . ':' . get_current_user_id();
    }

    private function client_ip(): string {
        if (class_exists('Cashback_Encryption')) {
            return Cashback_Encryption::get_client_ip();
        }
        return '0.0.0.0';
    }

    private function error_response( string $code, int $status ): WP_REST_Response {
        return new WP_REST_Response(array(
            'code'    => $code,
            'message' => $code,
        ), $status);
    }
}
