<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Savello_Cashback_Internal_REST_Controller {

    private const NAMESPACE = 'savello-internal/v1';

    private Savello_Internal_HMAC_Auth_Service $auth;
    private Savello_Cashback_Internal_API_Service $service;
    private ?object $cpa_bridge;

    public function __construct(
        ?Savello_Internal_HMAC_Auth_Service $auth = null,
        ?Savello_Cashback_Internal_API_Service $service = null,
        ?object $cpa_bridge = null
    ) {
        $this->auth       = $auth ?? new Savello_Internal_HMAC_Auth_Service();
        $this->service    = $service ?? new Savello_Cashback_Internal_API_Service();
        $this->cpa_bridge = $cpa_bridge ?? (class_exists('Cashback_Price_Comparison_CPA_Bridge') ? new Cashback_Price_Comparison_CPA_Bridge() : null);
    }

    public static function init(): void {
        add_action('rest_api_init', static function (): void {
            (new self())->register_routes();
        });

        add_action('admin_init', array( self::class, 'register_settings' ));
    }

    public static function register_settings(): void {
        if (! function_exists('register_setting')) {
            return;
        }

        register_setting(
            'cashback_settings_group',
            Savello_Internal_HMAC_Auth_Service::OPTION_SECRET,
            array(
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => array( Savello_Internal_HMAC_Auth_Service::class, 'sanitize_secret' ),
                'show_in_rest'      => false,
            )
        );

        register_setting(
            'cashback_settings_group',
            Savello_Internal_HMAC_Auth_Service::OPTION_ENABLED,
            array(
                'type'              => 'integer',
                'default'           => 0,
                'sanitize_callback' => array( Savello_Internal_HMAC_Auth_Service::class, 'sanitize_enabled' ),
                'show_in_rest'      => false,
            )
        );
    }

    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/health', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'health' ),
            'permission_callback' => array( $this, 'check_hmac' ),
        ));

        register_rest_route(self::NAMESPACE, '/merchants', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'merchants' ),
            'permission_callback' => array( $this, 'check_hmac' ),
        ));

        register_rest_route(self::NAMESPACE, '/merchants/(?P<merchant_id>[A-Za-z0-9_-]+)/rates', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'merchant_rates' ),
            'permission_callback' => array( $this, 'check_hmac' ),
        ));

        register_rest_route(self::NAMESPACE, '/resolve-product', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'resolve_product' ),
            'permission_callback' => array( $this, 'check_hmac' ),
        ));

        register_rest_route(self::NAMESPACE, '/deeplink', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'deeplink' ),
            'permission_callback' => array( $this, 'check_hmac' ),
        ));

        register_rest_route(self::NAMESPACE, '/manifest', array(
			'methods'             => WP_REST_Server::READABLE, 'callback'            => array( $this, 'manifest' ), 'permission_callback' => array( $this, 'check_hmac' ),
        ));

        register_rest_route(self::NAMESPACE, '/price-comparison/cpa/networks', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'cpa_networks' ),
            'permission_callback' => array( $this, 'check_hmac' ),
        ));

        register_rest_route(self::NAMESPACE, '/price-comparison/cpa/feeds', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'cpa_feeds' ),
            'permission_callback' => array( $this, 'check_hmac' ),
        ));

        register_rest_route(self::NAMESPACE, '/price-comparison/cpa/feed-content', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'cpa_feed_content' ),
            'permission_callback' => array( $this, 'check_hmac' ),
        ));

        register_rest_route(self::NAMESPACE, '/price-comparison/cpa/deeplink', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'cpa_deeplink' ),
            'permission_callback' => array( $this, 'check_hmac' ),
        ));
    }

    public function check_hmac( WP_REST_Request $request ) {
        return $this->auth->verify_request($request);
    }

    public function health( WP_REST_Request $request ): WP_REST_Response {
        unset($request);
        return new WP_REST_Response(array(
            'status'  => 'ok',
            'service' => 'cashback-internal-api',
            'version' => '1.0.0',
        ), 200);
    }

    public function merchants( WP_REST_Request $request ) {
        return $this->response($this->service->get_merchants($request->get_params()));
    }

    public function merchant_rates( WP_REST_Request $request ) {
        return $this->response($this->service->get_merchant_rates(
            sanitize_text_field((string) $request->get_param('merchant_id'))
        ));
    }

    public function resolve_product( WP_REST_Request $request ) {
        return $this->response($this->service->resolve_product_cashback($this->request_payload($request)));
    }

    public function deeplink( WP_REST_Request $request ) {
        return $this->response($this->service->create_deeplink($this->request_payload($request)));
    }

    public function manifest( WP_REST_Request $request ) {
        unset($request);
return $this->response($this->service->get_manifest());
    }

    public function cpa_networks( WP_REST_Request $request ) {
        unset($request);
        $bridge = $this->cpa_bridge();
        if (is_wp_error($bridge)) {
            return $bridge;
        }

        return $this->response(array( 'items' => $bridge->list_network_statuses() ));
    }

    public function cpa_feeds( WP_REST_Request $request ) {
        unset($request);
        $bridge = $this->cpa_bridge();
        if (is_wp_error($bridge)) {
            return $bridge;
        }

        return $this->response(array( 'items' => $bridge->list_feed_descriptors() ));
    }

    public function cpa_feed_content( WP_REST_Request $request ) {
        $payload = $this->request_payload($request);
        $network = sanitize_key((string) ( $payload['network'] ?? '' ));
        $feed_id = sanitize_key((string) ( $payload['feed_id'] ?? '' ));

        if (! in_array($network, array( 'admitad', 'advcake' ), true)) {
            return $this->bad_request('Invalid network.');
        }
        if ($feed_id === '') {
            return $this->bad_request('Invalid feed_id.');
        }

        $bridge = $this->cpa_bridge();
        if (is_wp_error($bridge)) {
            return $bridge;
        }

        $payload['network'] = $network;
        $payload['feed_id'] = $feed_id;
        if (isset($payload['store_domain'])) {
            $payload['store_domain'] = sanitize_text_field((string) $payload['store_domain']);
        }
        if (isset($payload['offer_id'])) {
            $payload['offer_id'] = sanitize_text_field((string) $payload['offer_id']);
        }

        return $this->response($bridge->get_feed_content($payload));
    }

    public function cpa_deeplink( WP_REST_Request $request ) {
        $payload    = $this->request_payload($request);
        $network    = sanitize_key((string) ( $payload['network'] ?? '' ));
        $source_url = $this->sanitize_http_url((string) ( $payload['source_url'] ?? $payload['url'] ?? '' ));

        if (! in_array($network, array( 'admitad', 'advcake' ), true)) {
            return $this->bad_request('Invalid network.');
        }
        if ($source_url === '') {
            return $this->bad_request('Invalid source_url.');
        }

        $bridge = $this->cpa_bridge();
        if (is_wp_error($bridge)) {
            return $bridge;
        }

        $payload['network']    = $network;
        $payload['source_url'] = $source_url;
        if (isset($payload['click_id'])) {
            $payload['click_id'] = sanitize_text_field((string) $payload['click_id']);
        }

        $result = $bridge->create_deeplink($payload);
        if (is_wp_error($result)) {
            return $result;
        }

        $affiliate_url = $this->sanitize_http_url((string) ( $result['affiliate_url'] ?? $result['url'] ?? '' ));
        if ($affiliate_url === '') {
            return new WP_Error(
                'savello_internal_cpa_deeplink_unavailable',
                'CPA deeplink is unavailable.',
                array( 'status' => 502 )
            );
        }

        return $this->response(array(
            'status'        => (string) ( $result['status'] ?? 'ok' ),
            'network'       => $network,
            'affiliate_url' => $affiliate_url,
        ));
    }

    private function request_payload( WP_REST_Request $request ): array {
        $payload = $request->get_json_params();
        return is_array($payload) ? $payload : array();
    }

    private function response( $result ) {
        if (is_wp_error($result)) {
            return $result;
        }
        return new WP_REST_Response($result, 200);
    }

    private function cpa_bridge() {
        if ($this->cpa_bridge === null) {
            return new WP_Error(
                'savello_internal_cpa_bridge_unavailable',
                'CPA bridge is unavailable.',
                array( 'status' => 503 )
            );
        }

        return $this->cpa_bridge;
    }

    private function sanitize_http_url( string $url ): string {
        $url    = esc_url_raw($url);
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, array( 'http', 'https' ), true) ? $url : '';
    }

    private function bad_request( string $message ): WP_Error {
        return new WP_Error(
            'savello_internal_bad_request',
            $message,
            array( 'status' => 400 )
        );
    }
}
