<?php
/**
 * Price monitor admin settings UI.
 *
 * @package Cashback
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page and form handlers for price monitor.
 */
final class Cashback_Price_Monitor_Admin {

	public const PAGE_SLUG         = 'cashback-price-monitor';
	public const OPTION_USER_LIMIT = 'cashback_price_monitor_user_limit';

	/**
	 * Backend client instance.
	 *
	 * @var object
	 */
	private object $client;

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Register admin hooks.
	 *
	 * @param object|null $client Optional injected backend client.
	 */
	public function __construct( ?object $client = null ) {
		$this->client = $client ?? new Cashback_Price_Monitor_Client();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_cashback_price_monitor_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_cashback_price_monitor_save_source', array( $this, 'handle_save_source' ) );
		add_action( 'admin_post_cashback_price_monitor_save_proxy_pool', array( $this, 'handle_save_proxy_pool' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the submenu page.
	 */
	public function register_menu(): void {
		$this->hook_suffix = add_submenu_page(
			'cashback-overview',
			'Мониторинг цен',
			'Мониторинг цен',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets for the price monitor page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $this->hook_suffix !== $hook ) {
			return;
		}

		$version = defined( 'CASHBACK_PLUGIN_VERSION' ) ? CASHBACK_PLUGIN_VERSION : null;

		wp_enqueue_style(
			'price-monitor-admin',
			cashback_asset_url( 'assets/css/price-monitor-admin.css' ),
			array(),
			$version
		);

		wp_enqueue_script(
			'price-monitor-admin',
			cashback_asset_url( 'assets/js/price-monitor-admin.js' ),
			array(),
			$version,
			true
		);

		wp_localize_script(
			'price-monitor-admin',
			'CashbackPriceMonitorAdmin',
			array(
				'pageSlug'  => self::PAGE_SLUG,
				'userLimit' => $this->user_limit(),
				'i18n'      => array(
					'backendSection' => 'Настройки backend',
					'sourceSection'  => 'Поддерживаемые источники',
					'proxySection'   => 'Прокси-пулы',
				),
			)
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_page(): void {
		$this->assert_manage_options();

		$redacted_settings = $this->redacted_settings();
		$remote_settings   = $this->remote_settings();
		$sources           = $this->remote_sources();
		$admin_post_url    = admin_url( 'admin-post.php' );
		$user_limit        = isset( $remote_settings['max_tracked_products_per_user'] )
			? (int) $remote_settings['max_tracked_products_per_user']
			: $this->user_limit();
		?>
		<div class="wrap cashback-price-monitor-admin">
			<h1><?php echo esc_html( 'Мониторинг цен' ); ?></h1>

			<div class="cashback-price-monitor-admin__grid">
				<section class="cashback-price-monitor-admin__section">
					<h2><?php echo esc_html( 'Настройки backend' ); ?></h2>
					<form method="post" action="<?php echo esc_attr( $admin_post_url ); ?>">
						<input type="hidden" name="action" value="cashback_price_monitor_save_settings" />
						<?php wp_nonce_field( 'cashback_price_monitor_save_settings' ); ?>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="cashback-price-monitor-backend-url"><?php echo esc_html( 'Backend URL' ); ?></label></th>
								<td>
									<input
										id="cashback-price-monitor-backend-url"
										type="url"
										name="backend_url"
										class="regular-text"
										value="<?php echo esc_attr( (string) $redacted_settings['backend_url'] ); ?>"
									/>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html( 'Включено' ); ?></th>
								<td>
									<label>
										<input type="hidden" name="enabled" value="0" />
										<input type="checkbox" name="enabled" value="1" <?php echo esc_attr( $this->checked_attr( (bool) $redacted_settings['enabled'] ) ); ?> />
										<?php echo esc_html( 'Разрешить обращения к backend.' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="cashback-price-monitor-backend-secret"><?php echo esc_html( 'Backend secret' ); ?></label></th>
								<td>
									<input
										id="cashback-price-monitor-backend-secret"
										type="password"
										name="backend_secret"
										class="regular-text"
										value=""
										autocomplete="off"
										placeholder="<?php echo esc_attr( (string) $redacted_settings['backend_secret'] ); ?>"
									/>
									<p class="description"><?php echo esc_html( '[redacted]' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="cashback-price-monitor-user-limit"><?php echo esc_html( 'Лимит товаров на пользователя' ); ?></label></th>
								<td>
									<input
										id="cashback-price-monitor-user-limit"
										type="number"
										min="1"
										name="max_tracked_products_per_user"
										class="small-text"
										value="<?php echo esc_attr( (string) $user_limit ); ?>"
									/>
								</td>
							</tr>
						</table>

						<p><button type="submit" class="button button-primary"><?php echo esc_html( 'Сохранить настройки' ); ?></button></p>
					</form>
				</section>

				<section class="cashback-price-monitor-admin__section">
					<h2><?php echo esc_html( 'Поддерживаемые источники' ); ?></h2>
					<form method="post" action="<?php echo esc_attr( $admin_post_url ); ?>">
						<input type="hidden" name="action" value="cashback_price_monitor_save_source" />
						<?php wp_nonce_field( 'cashback_price_monitor_save_source' ); ?>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="cashback-price-monitor-source-domain"><?php echo esc_html( 'Домен' ); ?></label></th>
								<td><input id="cashback-price-monitor-source-domain" type="text" name="source_domain" class="regular-text" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="cashback-price-monitor-source-name"><?php echo esc_html( 'Название' ); ?></label></th>
								<td><input id="cashback-price-monitor-source-name" type="text" name="display_name" class="regular-text" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="cashback-price-monitor-source-logo"><?php echo esc_html( 'Logo URL' ); ?></label></th>
								<td><input id="cashback-price-monitor-source-logo" type="url" name="logo_url" class="regular-text" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="cashback-price-monitor-source-status"><?php echo esc_html( 'Статус' ); ?></label></th>
								<td>
									<select id="cashback-price-monitor-source-status" name="status">
										<option value="active"><?php echo esc_html( 'active' ); ?></option>
										<option value="paused"><?php echo esc_html( 'paused' ); ?></option>
										<option value="disabled"><?php echo esc_html( 'disabled' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="cashback-price-monitor-source-interval"><?php echo esc_html( 'Интервал, часы' ); ?></label></th>
								<td><input id="cashback-price-monitor-source-interval" type="number" min="1" name="fetch_interval_hours" class="small-text" value="6" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="cashback-price-monitor-source-retention"><?php echo esc_html( 'Хранение истории, дней' ); ?></label></th>
								<td><input id="cashback-price-monitor-source-retention" type="number" min="1" max="365" name="history_retention_days" class="small-text" value="90" /></td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html( 'Browser fallback' ); ?></th>
								<td>
									<label><input type="checkbox" name="browser_fallback_allowed" value="1" /> <?php echo esc_html( 'Разрешить browser fallback' ); ?></label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="cashback-price-monitor-source-pool"><?php echo esc_html( 'Proxy pool' ); ?></label></th>
								<td><input id="cashback-price-monitor-source-pool" type="text" name="proxy_pool_id" class="regular-text" /></td>
							</tr>
						</table>

						<p><button type="submit" class="button button-primary"><?php echo esc_html( 'Сохранить источник' ); ?></button></p>
					</form>
				</section>

				<section class="cashback-price-monitor-admin__section">
					<h2><?php echo esc_html( 'Прокси-пулы' ); ?></h2>
					<form method="post" action="<?php echo esc_attr( $admin_post_url ); ?>">
						<input type="hidden" name="action" value="cashback_price_monitor_save_proxy_pool" />
						<?php wp_nonce_field( 'cashback_price_monitor_save_proxy_pool' ); ?>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="cashback-price-monitor-proxy-pool-id"><?php echo esc_html( 'Pool ID' ); ?></label></th>
								<td><input id="cashback-price-monitor-proxy-pool-id" type="text" name="pool_id" class="regular-text" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="cashback-price-monitor-proxy-tier"><?php echo esc_html( 'Tier' ); ?></label></th>
								<td><input id="cashback-price-monitor-proxy-tier" type="number" min="1" name="tier" class="small-text" value="1" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="cashback-price-monitor-proxy-secret"><?php echo esc_html( 'Secret reference' ); ?></label></th>
								<td><input id="cashback-price-monitor-proxy-secret" type="text" name="proxy_url_secret_ref" class="regular-text" /></td>
							</tr>
						</table>

						<p><button type="submit" class="button"><?php echo esc_html( 'Сохранить proxy pool' ); ?></button></p>
					</form>
				</section>
			</div>

			<section class="cashback-price-monitor-admin__section">
				<h2><?php echo esc_html( 'Диагностика' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<tr>
							<th><?php echo esc_html( 'Backend URL' ); ?></th>
							<td><?php echo esc_html( (string) $redacted_settings['backend_url'] ); ?></td>
						</tr>
						<tr>
							<th><?php echo esc_html( 'Backend secret' ); ?></th>
							<td><?php echo esc_html( (string) $redacted_settings['backend_secret'] ); ?></td>
						</tr>
						<tr>
							<th><?php echo esc_html( 'Лимит товаров' ); ?></th>
							<td><?php echo esc_html( (string) $user_limit ); ?></td>
						</tr>
						<tr>
							<th><?php echo esc_html( 'Источников' ); ?></th>
							<td><?php echo esc_html( (string) count( $sources ) ); ?></td>
						</tr>
					</tbody>
				</table>

				<table class="widefat striped cashback-price-monitor-admin__sources-table">
					<thead>
						<tr>
							<th><?php echo esc_html( 'Домен' ); ?></th>
							<th><?php echo esc_html( 'Название' ); ?></th>
							<th><?php echo esc_html( 'Logo URL' ); ?></th>
							<th><?php echo esc_html( 'Интервал' ); ?></th>
							<th><?php echo esc_html( 'Хранение' ); ?></th>
							<th><?php echo esc_html( 'Browser fallback' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( array() === $sources ) : ?>
							<tr>
								<td colspan="6"><?php echo esc_html( 'Источники пока не настроены.' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $sources as $source ) : ?>
								<tr>
									<td><?php echo esc_html( (string) ( $source['source_domain'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $source['display_name'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $source['logo_url'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $source['fetch_interval_hours'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $source['history_retention_days'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( ! empty( $source['browser_fallback_allowed'] ) ? 'yes' : 'no' ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</section>
		</div>
		<?php
	}

	/**
	 * Save backend settings and mirror safe values to the backend.
	 *
	 * @return array
	 */
	public function handle_save_settings(): array {
		$this->assert_manage_options();
		$this->assert_nonce( 'cashback_price_monitor_save_settings' );

		$backend_secret = trim( $this->post_string( 'backend_secret' ) );
		if ( '' === $backend_secret ) {
			$backend_secret = trim( (string) get_option( Cashback_Price_Monitor_Client::OPTION_SECRET, '' ) );
		}

		$payload = array(
			'backend_url'                   => esc_url_raw( $this->post_string( 'backend_url' ) ),
			'backend_secret'                => $backend_secret,
			'enabled'                       => $this->post_bool( 'enabled' ) ? 1 : 0,
			'max_tracked_products_per_user' => $this->sanitize_positive_int( $this->post_value( 'max_tracked_products_per_user', 10 ), 10 ),
		);

		update_option( Cashback_Price_Monitor_Client::OPTION_BACKEND_URL, $payload['backend_url'], false );
		update_option( Cashback_Price_Monitor_Client::OPTION_SECRET, $payload['backend_secret'], false );
		update_option( Cashback_Price_Monitor_Client::OPTION_ENABLED, $payload['enabled'], false );
		update_option( self::OPTION_USER_LIMIT, $payload['max_tracked_products_per_user'], false );

		$this->request_backend(
			'PATCH',
			'/api/v1/admin/settings',
			array(
				'max_tracked_products_per_user' => $payload['max_tracked_products_per_user'],
			)
		);

		return $payload;
	}

	/**
	 * Save a source definition.
	 *
	 * @return array
	 */
	public function handle_save_source(): array {
		$this->assert_manage_options();
		$this->assert_nonce( 'cashback_price_monitor_save_source' );

		$payload = array(
			'source_domain'            => $this->sanitize_source_domain( $this->post_string( 'source_domain' ) ),
			'display_name'             => sanitize_text_field( $this->post_string( 'display_name' ) ),
			'logo_url'                 => esc_url_raw( $this->post_string( 'logo_url' ) ),
			'status'                   => $this->sanitize_source_status( $this->post_string( 'status', 'active' ) ),
			'fetch_interval_hours'     => $this->sanitize_bounded_int( $this->post_value( 'fetch_interval_hours', 6 ), 1, 720 ),
			'history_retention_days'   => $this->sanitize_bounded_int( $this->post_value( 'history_retention_days', 90 ), 1, 365 ),
			'browser_fallback_allowed' => $this->post_bool( 'browser_fallback_allowed' ),
			'proxy_pool_id'            => $this->sanitize_optional_text( $this->post_string( 'proxy_pool_id' ) ),
		);

		$this->request_backend( 'POST', '/api/v1/admin/sources', $payload );

		return $payload;
	}

	/**
	 * Save a proxy pool definition.
	 *
	 * @return array
	 */
	public function handle_save_proxy_pool(): array {
		$this->assert_manage_options();
		$this->assert_nonce( 'cashback_price_monitor_save_proxy_pool' );

		$payload = array(
			'pool_id'              => $this->sanitize_optional_text( $this->post_string( 'pool_id' ) ),
			'tier'                 => $this->sanitize_bounded_int( $this->post_value( 'tier', 1 ), 1, 100 ),
			'proxy_url_secret_ref' => $this->sanitize_optional_text( $this->post_string( 'proxy_url_secret_ref' ) ),
		);

		$this->request_backend( 'POST', '/api/v1/admin/proxy-pools', $payload );

		return $payload;
	}

	/**
	 * Read saved settings with secrets redacted for display.
	 *
	 * @return array
	 */
	private function redacted_settings(): array {
		$secret = trim( (string) get_option( Cashback_Price_Monitor_Client::OPTION_SECRET, '' ) );

		return array(
			'backend_url'    => (string) get_option( Cashback_Price_Monitor_Client::OPTION_BACKEND_URL, '' ),
			'backend_secret' => '' === $secret ? '' : '[redacted]',
			'enabled'        => (int) get_option( Cashback_Price_Monitor_Client::OPTION_ENABLED, 0 ) === 1,
		);
	}

	/**
	 * Fetch backend settings for diagnostics.
	 *
	 * @return array
	 */
	private function remote_settings(): array {
		$response = $this->request_backend( 'GET', '/api/v1/admin/settings' );

		return is_array( $response['settings'] ?? null ) ? $response['settings'] : array();
	}

	/**
	 * Fetch source list for diagnostics.
	 *
	 * @return array
	 */
	private function remote_sources(): array {
		$response = $this->request_backend( 'GET', '/api/v1/admin/sources' );

		return is_array( $response['sources'] ?? null ) ? $response['sources'] : array();
	}

	/**
	 * Proxy a request to the backend client when available.
	 *
	 * @param string $method  HTTP method.
	 * @param string $path    Backend path.
	 * @param array  $payload Request payload.
	 * @return array
	 */
	private function request_backend( string $method, string $path, array $payload = array() ): array {
		if ( ! method_exists( $this->client, 'request' ) ) {
			return array();
		}

		$result = $this->client->request( $method, $path, $payload );

		return $result instanceof WP_Error || ! is_array( $result ) ? array() : $result;
	}

	/**
	 * Require admin capability.
	 */
	private function assert_manage_options(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостаточно прав.' );
		}
	}

	/**
	 * Require a valid admin nonce.
	 *
	 * @param string $action Nonce action.
	 */
	private function assert_nonce( string $action ): void {
		if ( ! check_admin_referer( $action ) ) {
			wp_die( 'Неверный nonce.' );
		}
	}

	/**
	 * Read configured per-user limit.
	 */
	private function user_limit(): int {
		return $this->sanitize_positive_int( get_option( self::OPTION_USER_LIMIT, 10 ), 10 );
	}

	/**
	 * Sanitize a positive integer with fallback.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $fallback Fallback value.
	 */
	private function sanitize_positive_int( mixed $value, int $fallback ): int {
		$number = is_numeric( $value ) ? (int) $value : $fallback;

		return max( 1, $number );
	}

	/**
	 * Sanitize an integer inside a bounded range.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Minimum value.
	 * @param int   $max   Maximum value.
	 */
	private function sanitize_bounded_int( mixed $value, int $min, int $max ): int {
		$number = is_numeric( $value ) ? (int) $value : $min;
		$number = max( $min, $number );

		return min( $max, $number );
	}

	/**
	 * Sanitize optional text into a nullable string.
	 *
	 * @param string $value Raw value.
	 */
	private function sanitize_optional_text( string $value ): ?string {
		$clean = sanitize_text_field( $value );

		return '' === $clean ? null : $clean;
	}

	/**
	 * Normalize a source domain from freeform input.
	 *
	 * @param string $value Raw value.
	 */
	private function sanitize_source_domain( string $value ): string {
		$value = trim( strtolower( $value ) );
		if ( '' === $value ) {
			return '';
		}

		if ( str_contains( $value, '://' ) ) {
			$host = wp_parse_url( $value, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$value = $host;
			}
		}

		if ( str_contains( $value, '/' ) ) {
			$value = (string) preg_replace( '#/.*$#', '', $value );
		}

		return trim( $value, '.' );
	}

	/**
	 * Sanitize source status to an allowlist value.
	 *
	 * @param string $value Raw value.
	 */
	private function sanitize_source_status( string $value ): string {
		$status = sanitize_key( $value );

		return in_array( $status, array( 'active', 'paused', 'disabled' ), true ) ? $status : 'active';
	}

	/**
	 * Render a checked attribute for checkbox inputs.
	 *
	 * @param bool $checked Whether the control is checked.
	 */
	private function checked_attr( bool $checked ): string {
		return $checked ? 'checked' : '';
	}

	/**
	 * Read a posted string value after nonce validation.
	 *
	 * @param string $key      POST key.
	 * @param string $fallback Fallback value.
	 */
	private function post_string( string $key, string $fallback = '' ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : $fallback;
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Read a posted boolean flag after nonce validation.
	 *
	 * @param string $key POST key.
	 */
	private function post_bool( string $key ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		return ! empty( $_POST[ $key ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Read a posted scalar value after nonce validation.
	 *
	 * @param string $key      POST key.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	private function post_value( string $key, mixed $fallback = '' ): mixed {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $fallback;
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}
}
