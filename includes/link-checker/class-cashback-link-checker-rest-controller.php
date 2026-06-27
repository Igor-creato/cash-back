<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Link_Checker_REST_Controller {

    private const NAMESPACE = 'cashback/v1';
    private const BASE      = '/link-checker';

    private Cashback_Link_Checker_Service $service;

    public static function init(): void {
        $controller = new self(new Cashback_Link_Checker_Service());
        add_action('rest_api_init', array( $controller, 'register_routes' ));
    }

    public function __construct( Cashback_Link_Checker_Service $service ) {
        $this->service = $service;
    }

    public function register_routes(): void {
        $method = class_exists('WP_REST_Server') ? WP_REST_Server::CREATABLE : 'POST';

        register_rest_route(self::NAMESPACE, self::BASE . '/check', array(
            'methods'             => $method,
            'callback'            => array( $this, 'check' ),
            'permission_callback' => array( $this, 'allow_check_request' ),
            'args'                => array(
                'url' => array(
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'esc_url_raw',
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, self::BASE . '/activate', array(
            'methods'             => $method,
            'callback'            => array( $this, 'activate' ),
            'permission_callback' => array( $this, 'allow_activate_request' ),
            'args'                => array(
                'url' => array(
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'esc_url_raw',
                ),
                'client_request_id' => array(
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
    }

    public function allow_check_request( WP_REST_Request $request ): bool|WP_Error {
        $nonce = $this->verify_rest_nonce($request);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }

        return $this->rate_limit('cashback_link_checker_check');
    }

    public function allow_activate_request( WP_REST_Request $request ): bool|WP_Error {
        $nonce = $this->verify_rest_nonce($request);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }

        return $this->rate_limit('cashback_link_checker_activate');
    }

    public function check( WP_REST_Request $request ): WP_REST_Response {
        $result = $this->service->check(
            (string) $request->get_param('url'),
            function_exists('get_current_user_id') ? (int) get_current_user_id() : 0
        );

        return $this->response($result);
    }

    public function activate( WP_REST_Request $request ): WP_REST_Response {
        $result = $this->service->activate(
            (string) $request->get_param('url'),
            (string) $request->get_param('client_request_id'),
            function_exists('get_current_user_id') ? (int) get_current_user_id() : 0
        );

        return $this->response($result);
    }

    private function response( array|WP_Error $result ): WP_REST_Response {
        if ($result instanceof WP_Error) {
            $data   = $result->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;

            return new WP_REST_Response(array(
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ), $status);
        }

        return new WP_REST_Response($result, 200);
    }

    private function rate_limit( string $action ): bool|WP_Error {
        if (!class_exists('Cashback_Rate_Limiter')) {
            return true;
        }

        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $result  = Cashback_Rate_Limiter::check($action, $user_id, $this->client_ip());
        if (!empty($result['allowed'])) {
            return true;
        }

        return new WP_Error(
            'rate_limited',
            'Слишком много запросов. Попробуйте позже.',
            array(
                'status'      => 429,
                'retry_after' => (int) ($result['retry_after'] ?? 60),
            )
        );
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

    private function client_ip(): string {
        if (class_exists('Cashback_Encryption') && method_exists('Cashback_Encryption', 'get_client_ip')) {
            return Cashback_Encryption::get_client_ip();
        }

        // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- Fallback only when Cashback_Encryption::get_client_ip() is unavailable; sanitized before use.
        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        return $remote_addr;
    }
}
