<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Savello_Cashback_Internal_REST_Controller {

    private const NAMESPACE = 'savello-internal/v1';

    private Savello_Internal_HMAC_Auth_Service $auth;
    private Savello_Cashback_Internal_API_Service $service;

    public function __construct(
        ?Savello_Internal_HMAC_Auth_Service $auth = null,
        ?Savello_Cashback_Internal_API_Service $service = null
    ) {
        $this->auth    = $auth ?? new Savello_Internal_HMAC_Auth_Service();
        $this->service = $service ?? new Savello_Cashback_Internal_API_Service();
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

        register_rest_route(self::NAMESPACE, '/users/(?P<external_user_id>[^/]+)/price-monitor-limits', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'user_limits' ),
            'permission_callback' => array( $this, 'check_hmac' ),
        ));

        register_rest_route(self::NAMESPACE, '/manifest', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'manifest' ),
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

    public function user_limits( WP_REST_Request $request ) {
        return $this->response($this->service->get_user_price_monitor_limits(
            sanitize_text_field((string) $request->get_param('external_user_id'))
        ));
    }

    public function manifest( WP_REST_Request $request ) {
        unset($request);
        return $this->response($this->service->get_manifest());
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
}
