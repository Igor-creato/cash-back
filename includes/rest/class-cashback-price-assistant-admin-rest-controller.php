<?php
/**
 * REST proxy controller for Price Assistant store admin.
 *
 * @package Cashback
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers HMAC-backed admin proxy routes for Price Assistant sources.
 */
final class Cashback_Price_Assistant_Admin_REST_Controller {

	private const NAMESPACE = 'cashback/v1';
	private const BASE_PATH = '/v1/price-assistant/admin';

	/**
	 * Backend proxy client.
	 *
	 * @var Cashback_Price_Assistant_Proxy_Client
	 */
	private Cashback_Price_Assistant_Proxy_Client $proxy_client;

	/**
	 * Create the controller.
	 *
	 * @param Cashback_Price_Assistant_Proxy_Client|null $proxy_client Optional proxy client for tests.
	 */
	public function __construct( ?Cashback_Price_Assistant_Proxy_Client $proxy_client = null ) {
		$this->proxy_client = $proxy_client ?? new Cashback_Price_Assistant_Proxy_Client();
	}

	/**
	 * Hook route registration into WordPress REST bootstrap.
	 */
	public static function init(): void {
		$controller = new self();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
	}

	/**
	 * Register Price Assistant admin routes.
	 */
	public function register_routes(): void {
		$permission = array( $this, 'check_admin_permission' );

		register_rest_route(
			self::NAMESPACE,
			'/price-assistant/admin/stores',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'proxy_get_stores' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'proxy_create_store' ),
					'permission_callback' => $permission,
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/price-assistant/admin/stores/(?P<store_id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'proxy_patch_store' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/price-assistant/admin/stores/(?P<store_id>\d+)/sources',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'proxy_create_source' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/price-assistant/admin/stores/(?P<store_id>\d+)/sources/(?P<source_id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'proxy_patch_source' ),
				'permission_callback' => $permission,
			)
		);

		foreach ( $this->diagnostic_routes() as $route => $method ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, $method ),
					'permission_callback' => $permission,
				)
			);
		}
	}

	/**
	 * Check admin capability and REST nonce.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function check_admin_permission( WP_REST_Request $request ): bool|WP_Error {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'price_assistant_admin_nonce_required',
				'Требуется nonce REST API.',
				array( 'status' => 403 )
			);
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'price_assistant_admin_forbidden',
				'Недостаточно прав.',
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Proxy store list requests.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function proxy_get_stores( WP_REST_Request $request ): WP_REST_Response {
		return $this->proxy( 'GET', '/stores', null, $this->safe_query( $request ) );
	}

	/**
	 * Proxy store creation requests.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function proxy_create_store( WP_REST_Request $request ): WP_REST_Response {
		return $this->proxy( 'POST', '/stores', $this->payload( $request ) );
	}

	/**
	 * Proxy store update requests.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function proxy_patch_store( WP_REST_Request $request ): WP_REST_Response {
		$store_id = absint( $request->get_param( 'store_id' ) );
		return $this->proxy( 'PATCH', '/stores/' . $store_id, $this->payload( $request ) );
	}

	/**
	 * Proxy source creation requests.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function proxy_create_source( WP_REST_Request $request ): WP_REST_Response {
		$store_id = absint( $request->get_param( 'store_id' ) );
		return $this->proxy( 'POST', '/stores/' . $store_id . '/sources', $this->payload( $request ) );
	}

	/**
	 * Proxy source update requests.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function proxy_patch_source( WP_REST_Request $request ): WP_REST_Response {
		$store_id  = absint( $request->get_param( 'store_id' ) );
		$source_id = absint( $request->get_param( 'source_id' ) );
		return $this->proxy(
			'PATCH',
			'/stores/' . $store_id . '/sources/' . $source_id,
			$this->payload( $request )
		);
	}

	/**
	 * Proxy source health diagnostics.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function proxy_source_health( WP_REST_Request $request ): WP_REST_Response {
		return $this->proxy( 'GET', '/source-health', null, $this->safe_query( $request ) );
	}

	/**
	 * Proxy fetch attempt diagnostics.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function proxy_fetch_attempts( WP_REST_Request $request ): WP_REST_Response {
		return $this->proxy( 'GET', '/fetch-attempts', null, $this->safe_query( $request ) );
	}

	/**
	 * Proxy sync diagnostics.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function proxy_sync_diagnostics( WP_REST_Request $request ): WP_REST_Response {
		return $this->proxy( 'GET', '/sync-diagnostics', null, $this->safe_query( $request ) );
	}

	/**
	 * Proxy quarantine diagnostics.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function proxy_quarantine( WP_REST_Request $request ): WP_REST_Response {
		return $this->proxy( 'GET', '/quarantine', null, $this->safe_query( $request ) );
	}

	/**
	 * Proxy economics diagnostics.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function proxy_proxy_economics( WP_REST_Request $request ): WP_REST_Response {
		return $this->proxy( 'GET', '/proxy-economics', null, $this->safe_query( $request ) );
	}

	/**
	 * Proxy matching diagnostics.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function proxy_matching_diagnostics( WP_REST_Request $request ): WP_REST_Response {
		return $this->proxy( 'GET', '/matching-diagnostics', null, $this->safe_query( $request ) );
	}

	/**
	 * Send a request through the existing backend proxy client.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path Admin API path suffix.
	 * @param array|null $payload Optional JSON payload.
	 * @param array      $query Optional query params.
	 * @return WP_REST_Response
	 */
	private function proxy(
		string $method,
		string $path,
		?array $payload = null,
		array $query = array()
	): WP_REST_Response {
		$result = $this->proxy_client->request(
			$method,
			self::BASE_PATH . $path,
			$payload,
			$query
		);
		return new WP_REST_Response( $result['data'], (int) $result['status'] );
	}

	/**
	 * Read JSON payload from the request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array
	 */
	private function payload( WP_REST_Request $request ): array {
		$json = $request->get_json_params();
		return is_array( $json ) ? $json : array();
	}

	/**
	 * Build a sanitized query for backend diagnostics.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array
	 */
	private function safe_query( WP_REST_Request $request ): array {
		$query = array();
		foreach ( $request->get_params() as $key => $value ) {
			$key = (string) $key;
			if ( '_wpnonce' === $key || 'rest_route' === $key ) {
				continue;
			}
			if ( is_scalar( $value ) ) {
				$query[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return $query;
	}

	/**
	 * Return read-only diagnostics route map.
	 *
	 * @return array<string,string>
	 */
	private function diagnostic_routes(): array {
		return array(
			'/price-assistant/admin/source-health'        => 'proxy_source_health',
			'/price-assistant/admin/fetch-attempts'       => 'proxy_fetch_attempts',
			'/price-assistant/admin/sync-diagnostics'     => 'proxy_sync_diagnostics',
			'/price-assistant/admin/quarantine'           => 'proxy_quarantine',
			'/price-assistant/admin/proxy-economics'      => 'proxy_proxy_economics',
			'/price-assistant/admin/matching-diagnostics' => 'proxy_matching_diagnostics',
		);
	}
}
