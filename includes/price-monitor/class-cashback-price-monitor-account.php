<?php
/**
 * Price monitor account endpoint UI.
 *
 * @package Cashback
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce account endpoint integration for price monitor.
 */
final class Cashback_Price_Monitor_Account {

	public const ENDPOINT      = 'price-monitor';
	public const SCRIPT_HANDLE = 'price-monitor-account';
	public const STYLE_HANDLE  = 'price-monitor-account';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_endpoint' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_endpoint' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the WooCommerce account endpoint.
	 */
	public function register_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Add endpoint query variable.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = self::ENDPOINT;

		return $vars;
	}

	/**
	 * Add the price monitor tab to the account menu.
	 *
	 * @param array $items Existing account menu items.
	 * @return array
	 */
	public function add_menu_item( array $items ): array {
		if ( isset( $items['customer-logout'] ) ) {
			$logout = $items['customer-logout'];
			unset( $items['customer-logout'] );
			$items[ self::ENDPOINT ]  = 'Мониторинг цен';
			$items['customer-logout'] = $logout;

			return $items;
		}

		$items[ self::ENDPOINT ] = 'Мониторинг цен';

		return $items;
	}

	/**
	 * Enqueue account assets on the price monitor endpoint.
	 */
	public function enqueue_assets(): void {
		if ( ! $this->is_endpoint_request() ) {
			return;
		}

		$version = defined( 'CASHBACK_PLUGIN_VERSION' ) ? CASHBACK_PLUGIN_VERSION : null;

		wp_enqueue_style(
			self::STYLE_HANDLE,
			cashback_asset_url( 'assets/css/price-monitor-account.css' ),
			array( 'cashback-account-base' ),
			$version
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			cashback_asset_url( 'assets/js/price-monitor-account.js' ),
			array(),
			$version,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'CashbackPriceMonitorAccount',
			$this->localized_config()
		);
	}

	/**
	 * Render the account endpoint shell.
	 */
	public function render_endpoint(): void {
		?>
		<section class="cashback-price-monitor" data-price-monitor-account>
			<header class="cashback-price-monitor__header">
				<div>
					<h2 class="cashback-price-monitor__title"><?php echo esc_html( 'Мониторинг цен' ); ?></h2>
					<p class="cashback-price-monitor__description"><?php echo esc_html( 'Добавляйте товары по ссылке и следите за изменением цены.' ); ?></p>
				</div>
			</header>

			<form class="cashback-price-monitor__form" data-price-monitor-add-form>
				<label class="cashback-price-monitor__field">
					<span class="cashback-price-monitor__label"><?php echo esc_html( 'Ссылка на товар' ); ?></span>
					<input
						type="url"
						name="url"
						class="cashback-price-monitor__input"
						placeholder="<?php echo esc_attr( 'https://example.com/product' ); ?>"
						required
					/>
				</label>

				<label class="cashback-price-monitor__field">
					<span class="cashback-price-monitor__label"><?php echo esc_html( 'Желаемая цена' ); ?></span>
					<input
						type="number"
						min="0"
						step="1"
						name="target_price_minor"
						class="cashback-price-monitor__input cashback-price-monitor__input--price"
						placeholder="<?php echo esc_attr( 'Например, 4990' ); ?>"
					/>
				</label>

				<button type="submit" class="cashback-btn-primary cashback-price-monitor__submit">
					<?php echo esc_html( 'Добавить товар' ); ?>
				</button>
			</form>

			<div class="cashback-price-monitor__feedback" data-price-monitor-feedback aria-live="polite"></div>
			<div class="cashback-price-monitor__items" data-price-monitor-items></div>
		</section>
		<?php
	}

	/**
	 * Build localized config for the account app.
	 *
	 * @return array
	 */
	private function localized_config(): array {
		return array(
			'restBase'            => $this->rest_base_url(),
			'linkCheckerRestBase' => $this->link_checker_rest_base_url(),
			'nonce'               => wp_create_nonce( 'wp_rest' ),
			'isLoggedIn'          => function_exists( 'is_user_logged_in' ) && is_user_logged_in(),
			'items'               => $this->initial_cards(),
			'i18n'                => array(
				'title'                  => 'Мониторинг цен',
				'unsupportedStore'       => 'Магазин не поддерживается',
				'monitoringUnavailable'  => 'Для данного магазина мониторинг временно недоступен.',
				'duplicateWatchlistItem' => 'Товар уже отслеживается',
				'limitExceeded'          => 'Достигнут лимит отслеживаемых товаров',
				'fetchPending'           => 'Данные товара загружаются',
				'fetchFailed'            => 'Не удалось обновить данные товара',
				'cashbackUnavailable'    => 'Кэшбэк не начислится',
				'invalidTargetPrice'     => 'Проверьте желаемую цену',
				'empty'                  => 'Пока нет отслеживаемых товаров',
				'addButton'              => 'Добавить товар',
				'cashbackButton'         => 'Активировать кэшбэк',
				'refreshButton'          => 'Обновить',
				'deleteButton'           => 'Удалить',
				'editButton'             => 'Изменить цену',
			),
		);
	}

	/**
	 * Load initial cards for server-side hydration.
	 *
	 * @return array
	 */
	private function initial_cards(): array {
		if ( ! class_exists( 'Cashback_Price_Monitor_Client' ) ) {
			return array();
		}

		$client = new Cashback_Price_Monitor_Client();
		$link_checker_service = class_exists( 'Cashback_Link_Checker_Service' )
			? new Cashback_Link_Checker_Service()
			: null;
		$result = $client->request(
			'GET',
			'/api/v1/watchlist/items',
			array(
				'user_id' => $this->external_user_id(),
			)
		);

		if ( $result instanceof WP_Error ) {
			return array();
		}

		$items = $result['items'] ?? array();
		if ( ! is_array( $items ) ) {
			return array();
		}

		$cards = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$cards[] = self::hydrate_card( $client, $link_checker_service, $item, $this->current_user_id() );
		}

		return $cards;
	}

	/**
	 * Compose a single account card payload from the backend item payload.
	 *
	 * @param object      $client               Backend client.
	 * @param object|null $link_checker_service Link checker service.
	 * @param array       $item                 Watchlist item payload.
	 * @param int         $user_id              Current user id.
	 * @return array
	 */
	public static function hydrate_card( object $client, ?object $link_checker_service, array $item, int $user_id ): array {
		$card = array(
			'item'       => $item,
			'product'    => array(),
			'source'     => array(),
			'actions'    => array(),
			'chart'      => array(
				'points'   => array(),
				'summary'  => array(
					'lowest_price_minor' => null,
					'latest_price_minor' => null,
				),
				'currency' => null,
			),
			'activation' => array(),
		);

		$product_id = isset( $item['product_id'] ) ? (string) $item['product_id'] : '';
		if ( '' === $product_id ) {
			return $card;
		}

		if ( ! method_exists( $client, 'request' ) ) {
			return $card;
		}

		$detail = $client->request( 'GET', '/api/v1/products/' . rawurlencode( $product_id ) );
		if ( is_array( $detail ) ) {
			$card['product'] = is_array( $detail['product'] ?? null ) ? $detail['product'] : array();
			$card['source']  = is_array( $detail['source'] ?? null ) ? $detail['source'] : array();
			$card['actions'] = is_array( $detail['actions'] ?? null ) ? $detail['actions'] : array();
		}

		$chart = $client->request(
			'GET',
			'/api/v1/products/' . rawurlencode( $product_id ) . '/price-chart',
			array( 'days' => 30 )
		);
		if ( is_array( $chart ) ) {
			$card['chart'] = $chart;
		}

		$direct_url = isset( $card['actions']['direct_url'] ) ? (string) $card['actions']['direct_url'] : '';
		if ( '' === $direct_url && isset( $item['canonical_url'] ) && is_string( $item['canonical_url'] ) ) {
			$direct_url = $item['canonical_url'];
			$card['actions']['direct_url'] = $direct_url;
		}

		if ( '' !== $direct_url && is_object( $link_checker_service ) && method_exists( $link_checker_service, 'check' ) ) {
			$activation = $link_checker_service->check( $direct_url, $user_id );
			if ( is_array( $activation ) ) {
				$card['activation'] = $activation;
			}
		}

		return $card;
	}

	/**
	 * Determine whether the current request is for the endpoint.
	 */
	private function is_endpoint_request(): bool {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return false;
		}

		global $wp;

		return isset( $wp->query_vars[ self::ENDPOINT ] );
	}

	/**
	 * Resolve the browser-facing REST base URL.
	 */
	private function rest_base_url(): string {
		if ( function_exists( 'rest_url' ) ) {
			return rtrim( (string) rest_url( 'cashback/v1/price-monitor' ), '/' );
		}

		return rtrim( home_url( '/wp-json/cashback/v1/price-monitor' ), '/' );
	}

	/**
	 * Resolve the link-checker activation REST base URL.
	 */
	private function link_checker_rest_base_url(): string {
		if ( function_exists( 'rest_url' ) ) {
			return rtrim( (string) rest_url( 'cashback/v1/link-checker' ), '/' );
		}

		return rtrim( home_url( '/wp-json/cashback/v1/link-checker' ), '/' );
	}

	/**
	 * Build the external backend user id.
	 */
	private function external_user_id(): string {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return 'wp:' . $host . ':' . $this->current_user_id();
	}

	/**
	 * Get the current WordPress user id.
	 */
	private function current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}
}
