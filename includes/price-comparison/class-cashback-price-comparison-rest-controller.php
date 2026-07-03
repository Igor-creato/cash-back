<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Price_Comparison_REST_Controller {

    private const NAMESPACE = 'cashback/v1';
    private const BASE      = '/price-comparison';

    private Cashback_Price_Comparison_Service $service;

    public static function init(): void {
        $controller = new self();
        add_action('rest_api_init', array( $controller, 'register_routes' ));
    }

    public function __construct( ?Cashback_Price_Comparison_Service $service = null ) {
        $this->service = $service ?: new Cashback_Price_Comparison_Service();
    }

    public function register_routes(): void {
        $method = class_exists('WP_REST_Server') ? WP_REST_Server::CREATABLE : 'POST';
$readable = class_exists('WP_REST_Server') ? WP_REST_Server::READABLE : 'GET';

        register_rest_route(self::NAMESPACE, self::BASE . '/search', array(
	'methods'             => $method, 'callback'            => array( $this, 'search' ),
            'permission_callback' => array( $this, 'allow_search_request' ),
            'args'                => array(
                'city' => array(
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'query' => array(
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'stores' => array(
                    'type'              => 'array',
                    'required'          => false,
                    'default'           => array(),
                    'sanitize_callback' => array( $this, 'sanitize_stores' ),
                ),
),
));
register_rest_route(self::NAMESPACE, self::BASE . '/live-search', array( 'methods'             => $method, 'callback'            => array( $this, 'start_live_search' ), 'permission_callback' => array( $this, 'allow_search_request' ), 'args'                => array( 'city' => array( 'type'              => 'string', 'required'          => true, 'sanitize_callback' => 'sanitize_text_field' ), 'query' => array( 'type'              => 'string', 'required'          => true, 'sanitize_callback' => 'sanitize_text_field' ), 'stores' => array( 'type'              => 'array', 'required'          => false, 'default'           => array(), 'sanitize_callback' => array( $this, 'sanitize_stores' ) ), 'limit' => array( 'type'              => 'integer', 'required'          => false, 'default'           => 20, 'sanitize_callback' => 'absint' ), 'timeout_seconds' => array( 'type'              => 'integer', 'required'          => false, 'default'           => 120, 'sanitize_callback' => 'absint' ) ) ));
register_rest_route(self::NAMESPACE, self::BASE . '/live-search/(?P<run_id>[a-zA-Z0-9_-]+)', array( 'methods'             => $readable, 'callback'            => array( $this, 'get_live_search' ), 'permission_callback' => array( $this, 'allow_search_request' ), 'args'                => array( 'run_id' => array( 'type'              => 'string', 'required'          => true, 'sanitize_callback' => array( $this, 'sanitize_run_id' ) ) ) ));
    }

    public function allow_search_request( WP_REST_Request $request ): bool|WP_Error {
        $nonce = $this->verify_rest_nonce($request);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }

        return true;
    }

    public function search( WP_REST_Request $request ): WP_REST_Response {
        $result = $this->service->search(
            (string) $request->get_param('city'),
            (string) $request->get_param('query'),
            function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            (array) ( $request->get_param('stores') ?? array() ),
            (int) ( $request->get_param('limit') ?? 50 ),
            (int) ( $request->get_param('offset') ?? 0 )
        );

        return $this->response($result);
}

    public function start_live_search( WP_REST_Request $request ): WP_REST_Response {
        $result = $this->service->start_live_search(
            (string) $request->get_param('city'), (string) $request->get_param('query'), function_exists('get_current_user_id') ? (int) get_current_user_id() : 0, (array) ( $request->get_param('stores') ?? array() ), (int) ( $request->get_param('limit') ?? 20 ), (int) ( $request->get_param('timeout_seconds') ?? 120 )
        );
return $this->response($result);
}

    public function get_live_search( WP_REST_Request $request ): WP_REST_Response {
        $result = $this->service->get_live_search(
            (string) $request->get_param('run_id'), function_exists('get_current_user_id') ? (int) get_current_user_id() : 0
        );
return $this->response($result);
    }

    public function sanitize_stores( mixed $value ): array {
        if (!is_array($value)) {
return array();
        }
        return array_values(array_filter(array_map('sanitize_text_field', $value)));
}

    public function sanitize_run_id( mixed $value ): string {
        return sanitize_text_field((string) $value);
    }

    private function response( array|WP_Error $result ): WP_REST_Response {
        if ($result instanceof WP_Error) {
            $data   = $result->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;

            return new WP_REST_Response(array(
                'status'     => 'error',
                'error_code' => $result->get_error_code(),
                'message'    => $result->get_error_message(),
            ), $status);
        }

        $status = isset($result['status']) && $result['status'] === 'accepted' ? 202 : 200;
return new WP_REST_Response($result, $status);
    }

    private function verify_rest_nonce( WP_REST_Request $request ): bool|WP_Error {
        if (!function_exists('wp_verify_nonce')) {
            return true;
        }

        $nonce = (string) $request->get_header('X-WP-Nonce');
        if ($nonce !== '' && wp_verify_nonce($nonce, 'wp_rest') !== false) {
            return true;
        }

        return new WP_Error(
            'rest_cookie_invalid_nonce',
            'Недействительный nonce REST API.',
            array( 'status' => 403 )
        );
    }
}
