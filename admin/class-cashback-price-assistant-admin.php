<?php
/**
 * Admin page for Price Assistant store sources.
 *
 * @package Cashback
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Russian admin page for Price Assistant sources.
 */
final class Cashback_Price_Assistant_Admin {

	public const PAGE_SLUG = 'cashback-price-assistant-sources';

	/**
	 * Register WordPress admin hooks.
	 */
	public static function init(): void {
		$admin = new self();
		add_action( 'admin_menu', array( $admin, 'register_menu' ), 32 );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_assets' ) );
	}

	/**
	 * Register submenu page under Cashback overview.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'cashback-overview',
			'Источники Price Assistant',
			'Источники Price Assistant',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets for the Price Assistant admin page.
	 *
	 * @param string $hook Current admin hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		$allowed = array(
			'cashback-overview_page_' . self::PAGE_SLUG,
			'toplevel_page_' . self::PAGE_SLUG,
			'admin_page_' . self::PAGE_SLUG,
		);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page routing guard.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page routing guard.
		$current = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $hook, $allowed, true ) && self::PAGE_SLUG !== $current ) {
			return;
		}

		wp_enqueue_style(
			'cashback-price-assistant-admin',
			cashback_asset_url( 'admin/css/price-assistant-admin.css' ),
			array(),
			'1.0.0'
		);
		wp_enqueue_script(
			'cashback-price-assistant-admin',
			cashback_asset_url( 'admin/js/price-assistant-admin.js' ),
			array(),
			'1.0.0',
			true
		);
		wp_localize_script(
			'cashback-price-assistant-admin',
			'cashbackPriceAssistantAdmin',
			array(
				'restBase' => site_url( '/wp-json/cashback/v1/price-assistant/admin' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'labels'   => array(
					'loading'    => 'Загрузка…',
					'loadError'  => 'Не удалось загрузить данные.',
					'saveError'  => 'Не удалось сохранить.',
					'saved'      => 'Сохранено.',
					'empty'      => 'Данных пока нет.',
					'addStore'   => 'Добавить магазин',
					'saveSource' => 'Сохранить источник',
					'enabled'    => 'Включён',
					'disabled'   => 'Отключён',
				),
				'tabs'     => $this->tabs(),
			)
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'cashback' ) );
		}

		$tabs = $this->tabs();
		?>
		<div class="wrap cashback-price-assistant-admin" data-price-assistant-admin>
			<h1><?php echo esc_html__( 'Источники Price Assistant', 'cashback' ); ?></h1>

			<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Разделы источников Price Assistant', 'cashback' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<button
						type="button"
						class="nav-tab<?php echo 'stores' === $key ? ' nav-tab-active' : ''; ?>"
						data-price-assistant-tab="<?php echo esc_attr( $key ); ?>"
					>
						<?php echo esc_html( $label ); ?>
					</button>
				<?php endforeach; ?>
			</nav>

			<div id="cashback-pa-admin-notice" class="notice" hidden><p></p></div>

			<section class="cashback-pa-panel is-active" data-price-assistant-panel="stores">
				<p class="description cashback-pa-help">
					<?php echo esc_html__( 'поиск работает по включённым доменам/источникам; корзина и избранное только Ozon/Wildberries/Яндекс Маркет. Добавление credential proxy endpoints вынесено в отдельный security-этап.', 'cashback' ); ?>
				</p>

				<div class="cashback-pa-toolbar">
					<button type="button" class="button button-primary" data-pa-action="add-store">
						<?php echo esc_html__( 'Добавить магазин', 'cashback' ); ?>
					</button>
					<button type="button" class="button" data-pa-action="refresh">
						<?php echo esc_html__( 'Обновить', 'cashback' ); ?>
					</button>
				</div>

				<form class="cashback-pa-grid" data-pa-store-form>
					<label>
						<span><?php echo esc_html__( 'Код магазина', 'cashback' ); ?></span>
						<input type="text" name="store_code" autocomplete="off" />
					</label>
					<label>
						<span><?php echo esc_html__( 'Название', 'cashback' ); ?></span>
						<input type="text" name="display_name" autocomplete="off" />
					</label>
					<label>
						<span><?php echo esc_html__( 'Домашняя страница', 'cashback' ); ?></span>
						<input type="url" name="homepage_url" autocomplete="off" />
					</label>
					<label class="cashback-pa-check">
						<input type="checkbox" name="enabled" checked />
						<span><?php echo esc_html__( 'Включён', 'cashback' ); ?></span>
					</label>
					<button type="submit" class="button button-primary">
						<?php echo esc_html__( 'Сохранить магазин', 'cashback' ); ?>
					</button>
				</form>

				<form class="cashback-pa-grid" data-pa-source-form>
					<label>
						<span><?php echo esc_html__( 'Магазин для источника', 'cashback' ); ?></span>
						<select name="store_id" data-pa-store-select>
							<option value=""><?php echo esc_html__( 'Сначала загрузите или выберите магазин', 'cashback' ); ?></option>
						</select>
					</label>
					<label>
						<span><?php echo esc_html__( 'Код источника', 'cashback' ); ?></span>
						<input type="text" name="source_code" autocomplete="off" />
					</label>
					<label>
						<span><?php echo esc_html__( 'Название источника', 'cashback' ); ?></span>
						<input type="text" name="display_name" autocomplete="off" />
					</label>
					<label>
						<span><?php echo esc_html__( 'Домены', 'cashback' ); ?></span>
						<input type="text" name="domains" autocomplete="off" />
					</label>
					<label>
						<span><?php echo esc_html__( 'Шаблон поиска', 'cashback' ); ?></span>
						<input type="text" name="search_template" autocomplete="off" />
					</label>
					<label>
						<span><?php echo esc_html__( 'Регионы', 'cashback' ); ?></span>
						<input type="text" name="region_support" autocomplete="off" />
					</label>
					<label>
						<span><?php echo esc_html__( 'Приоритет', 'cashback' ); ?></span>
						<input type="number" name="priority" min="0" max="1000" value="100" />
					</label>
					<label>
						<span><?php echo esc_html__( 'Режим извлечения', 'cashback' ); ?></span>
						<select name="extraction_mode">
							<option value="json"><?php echo esc_html__( 'JSON', 'cashback' ); ?></option>
							<option value="css"><?php echo esc_html__( 'CSS', 'cashback' ); ?></option>
							<option value="hybrid"><?php echo esc_html__( 'Гибридный', 'cashback' ); ?></option>
						</select>
					</label>
					<label>
						<span><?php echo esc_html__( 'Политика прокси', 'cashback' ); ?></span>
						<select name="proxy_tier_policy">
							<option value="none"><?php echo esc_html__( 'Без прокси', 'cashback' ); ?></option>
							<option value="cheap_first"><?php echo esc_html__( 'Сначала дешёвые', 'cashback' ); ?></option>
							<option value="residential_first"><?php echo esc_html__( 'Сначала резидентские', 'cashback' ); ?></option>
							<option value="premium_only"><?php echo esc_html__( 'Только премиум', 'cashback' ); ?></option>
						</select>
					</label>
					<label>
						<span><?php echo esc_html__( 'Мин. интервал загрузки', 'cashback' ); ?></span>
						<input type="number" name="min_fetch_interval_minutes" min="1" max="1440" value="60" />
					</label>
					<label>
						<span><?php echo esc_html__( 'Порог сопоставления', 'cashback' ); ?></span>
						<input type="number" name="matching_threshold" min="0" max="100" value="65" />
					</label>
					<label>
						<span><?php echo esc_html__( 'Маппинг cashback merchant', 'cashback' ); ?></span>
						<textarea name="cashback_merchant_mapping" rows="3"></textarea>
					</label>
					<label class="cashback-pa-check">
						<input type="checkbox" name="enabled" checked />
						<span><?php echo esc_html__( 'Источник включён', 'cashback' ); ?></span>
					</label>
					<button type="submit" class="button button-primary">
						<?php echo esc_html__( 'Сохранить источник', 'cashback' ); ?>
					</button>
				</form>

				<div class="cashback-pa-table" data-pa-section="stores"></div>
			</section>

			<?php foreach ( $tabs as $key => $label ) : ?>
				<?php
				if ( 'stores' === $key ) {
					continue;
				}
				?>
				<section class="cashback-pa-panel" data-price-assistant-panel="<?php echo esc_attr( $key ); ?>">
					<h2><?php echo esc_html( $label ); ?></h2>
					<div class="cashback-pa-table" data-pa-section="<?php echo esc_attr( $key ); ?>"></div>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Return Russian tab labels.
	 *
	 * @return array<string,string>
	 */
	private function tabs(): array {
		return array(
			'stores'               => 'Магазины',
			'source-health'        => 'Здоровье источников',
			'fetch-attempts'       => 'Попытки загрузки',
			'sync-diagnostics'     => 'Диагностика синхронизации',
			'quarantine'           => 'Карантин',
			'proxy-economics'      => 'Экономика прокси',
			'matching-diagnostics' => 'Диагностика сопоставления',
		);
	}
}
