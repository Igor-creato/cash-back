<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Price_Monitor_REST_Controller {

    private const NAMESPACE = 'cashback/v1/price-monitor';

    private object $client;
    private object $link_checker_service;

    public static function init(): void {
        $controller = new self(
            new Cashback_Price_Monitor_Client(),
            new Cashback_Link_Checker_Service()
        );

        add_action('rest_api_init', array( $controller, 'register_routes' ));
    }

    public function __construct( object $client, object $link_checker_service ) {
        $this->client               = $client;
        $this->link_checker_service = $link_checker_service;
    }

    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/items', array(
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'create_item' ),
                'permission_callback' => array( $this, 'allow_write_request' ),
                'args'                => array(
                    'url' => array(
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'esc_url_raw',
                    ),
                    'target_price_minor' => array(
                        'type'              => 'integer',
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                    ),
                    'currency' => array(
                        'type'              => 'string',
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'client_request_id' => array(
                        'type'              => 'string',
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            ),
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'list_items' ),
                'permission_callback' => array( $this, 'allow_read_request' ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/items/(?P<item_id>[A-Za-z0-9_-]+)', array(
            array(
                'methods'             => 'PATCH',
                'callback'            => array( $this, 'update_item' ),
                'permission_callback' => array( $this, 'allow_write_request' ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'delete_item' ),
                'permission_callback' => array( $this, 'allow_write_request' ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/items/(?P<item_id>[A-Za-z0-9_-]+)/refresh', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'refresh_item' ),
            'permission_callback' => array( $this, 'allow_write_request' ),
        ));
    }

    public function allow_read_request( WP_REST_Request $request ): bool|WP_Error {
        return $this->authorize($request, 'cashback_price_monitor_read');
    }

    public function allow_write_request( WP_REST_Request $request ): bool|WP_Error {
        return $this->authorize($request, 'cashback_price_monitor_write');
    }

    public function create_item( WP_REST_Request $request ): WP_REST_Response {
        $url = (string) $request->get_param('url');

        $supported = $this->client->request('GET', '/api/v1/sources/supported', array(
            'url' => $url,
        ));
        if ($supported instanceof WP_Error) {
            return $this->response($supported);
        }

        if (empty($supported['supported'])) {
            $error = is_array($supported['error'] ?? null) ? $supported['error'] : array();

            return new WP_REST_Response(array(
                'code'    => (string) ($error['code'] ?? 'unsupported_store'),
                'message' => (string) ($error['message'] ?? 'Магазин не поддерживается'),
            ), 422);
        }

        $payload = array(
            'user_id' => $this->external_user_id(),
            'url'     => $url,
        );

        $target_price_minor = $request->get_param('target_price_minor');
        if ($target_price_minor !== null && $target_price_minor !== '') {
            $payload['target_price_minor'] = (int) $target_price_minor;
        }

        $payload['currency'] = $this->currency($request);

        $created = $this->client->request(
            'POST',
            '/api/v1/watchlist/items',
            $payload,
            $this->idempotency_key($request)
        );
        if ($created instanceof WP_Error) {
            return $this->response($created);
        }

        $activation = $this->link_checker_service->check($url, $this->current_user_id());
        if ($activation instanceof WP_Error) {
            $activation = $this->error_payload($activation);
        }

        $created['activation'] = $activation;

        return new WP_REST_Response($created, 200);
    }

    public function list_items( WP_REST_Request $request ): WP_REST_Response {
        unset($request);

        return $this->response(
            $this->client->request('GET', '/api/v1/watchlist/items', array(
                'user_id' => $this->external_user_id(),
            ))
        );
    }

    public function update_item( WP_REST_Request $request ): WP_REST_Response {
        $payload = array(
            'user_id' => $this->external_user_id(),
        );

        foreach (array( 'target_price_minor', 'currency', 'status' ) as $key) {
            $value = $request->get_param($key);
            if ($value !== null && $value !== '') {
                $payload[ $key ] = $key === 'target_price_minor' ? (int) $value : (string) $value;
            }
        }

        return $this->response(
            $this->client->request(
                'PATCH',
                $this->item_path((string) $request->get_param('item_id')),
                $payload,
                $this->idempotency_key($request)
            )
        );
    }

    public function delete_item( WP_REST_Request $request ): WP_REST_Response {
        return $this->response(
            $this->client->request(
                'DELETE',
                $this->item_path((string) $request->get_param('item_id')),
                array(
                    'user_id' => $this->external_user_id(),
                ),
                $this->idempotency_key($request)
            ),
            204
        );
    }

    public function refresh_item( WP_REST_Request $request ): WP_REST_Response {
        return $this->response(
            $this->client->request(
                'POST',
                $this->item_path((string) $request->get_param('item_id'), '/refresh'),
                array(
                    'user_id' => $this->external_user_id(),
                ),
                $this->idempotency_key($request)
            )
        );
    }

    private function authorize( WP_REST_Request $request, string $rate_limit_action ): bool|WP_Error {
        $nonce = $this->verify_rest_nonce($request);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }

        $user_id = $this->current_user_id();
        if ($user_id <= 0 || !is_user_logged_in()) {
            return new WP_Error(
                'rest_forbidden',
                'Требуется авторизация.',
                array( 'status' => 401 )
            );
        }

        if (function_exists('current_user_can') && !current_user_can('read')) {
            return new WP_Error(
                'rest_forbidden',
                'Доступ запрещён.',
                array( 'status' => 403 )
            );
        }

        return $this->rate_limit($rate_limit_action);
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

    private function rate_limit( string $action ): bool|WP_Error {
        if (!class_exists('Cashback_Rate_Limiter')) {
            return true;
        }

        $result = Cashback_Rate_Limiter::check($action, $this->current_user_id(), $this->client_ip());
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

    private function response( array|WP_Error $result, int $success_status = 200 ): WP_REST_Response {
        if ($result instanceof WP_Error) {
            $data = $result->get_error_data();

            return new WP_REST_Response(
                $this->error_payload($result),
                is_array($data) && isset($data['status']) ? (int) $data['status'] : 400
            );
        }

        return new WP_REST_Response($result, $success_status);
    }

    private function error_payload( WP_Error $error ): array {
        return array(
            'code'    => $error->get_error_code(),
            'message' => $error->get_error_message(),
        );
    }

    private function item_path( string $item_id, string $suffix = '' ): string {
        return '/api/v1/watchlist/items/' . sanitize_text_field($item_id) . $suffix;
    }

    private function external_user_id(): string {
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);

        return 'wp:' . $host . ':' . $this->current_user_id();
    }

    private function idempotency_key( WP_REST_Request $request ): string {
        $request_id = $this->sanitize_request_id((string) $request->get_param('client_request_id'));
        if ($request_id !== '') {
            return $request_id;
        }

        if (function_exists('cashback_generate_uuid7')) {
            return cashback_generate_uuid7(false);
        }

        return str_replace('-', '', wp_generate_uuid4());
    }

    private function sanitize_request_id( string $value ): string {
        $value = sanitize_text_field($value);

        return preg_match('/^[A-Za-z0-9_-]{1,128}$/', $value) ? $value : '';
    }

    private function currency( WP_REST_Request $request ): string {
        $currency = strtoupper((string) $request->get_param('currency'));

        return $currency !== '' ? $currency : 'RUB';
    }

    private function current_user_id(): int {
        return function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    }

    private function client_ip(): string {
        if (class_exists('Cashback_Encryption') && method_exists('Cashback_Encryption', 'get_client_ip')) {
            return Cashback_Encryption::get_client_ip();
        }

        $remote_addr = filter_input(INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP);

        return is_string($remote_addr) ? $remote_addr : '';
    }
}
