<?php
/**
 * Admin-UI для ежедневной сверки баланса (Группа 15, шаг S1).
 *
 * Подстраница `cashback-overview` → «Сверка баланса».
 * Показывает:
 *  - Summary последнего reconciliation-round'а (option cashback_balance_reconciliation_last_summary).
 *  - Таблицу расхождений (audit_log action='balance_consistency_mismatch') — пагинация Cashback_Pagination.
 *  - Таблицу зависших approved claims (action='claim_approved_no_transaction') — LIMIT 100 последних.
 *  - Кнопку «Запустить сейчас» — S1.C (admin_post + single-lock).
 *
 * Capability: manage_options.
 *
 * @since 1.3.0 (Group 15)
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cashback_Balance_Reconciliation_Admin {

	public const ADMIN_PAGE_SLUG  = 'cashback-balance-reconciliation';
	public const PARENT_MENU_SLUG = 'cashback-overview';

	public const MISMATCHES_PER_PAGE    = 20;
	public const STUCK_CLAIMS_LIMIT     = 100;
	public const MANUAL_RUN_ACTION      = 'cashback_reconcil_manual_run';
	public const MANUAL_RUN_NONCE       = 'cashback_reconcil_manual_nonce';
	public const MANUAL_RUN_LOCK        = 'cashback_reconcil_manual';
	public const MANUAL_RUN_MAX_BATCHES = 20;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_post_cashback_reconcil_manual_run', array( __CLASS__, 'handle_manual_run' ) );
		add_action( 'cashback_overview_widgets', array( __CLASS__, 'render_overview_widget' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Подключает JS/CSS модала корректировки + ref на MIN_ADJUST_REASON_LENGTH
	 * из users-management. Активируется только на подстранице сверки.
	 */
	public static function enqueue_assets( string $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only slug-detect для enqueue.
		$page_query = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( strpos( $hook, self::ADMIN_PAGE_SLUG ) === false && $page_query !== self::ADMIN_PAGE_SLUG ) {
			return;
		}

		$ver              = defined( 'CASHBACK_PLUGIN_VERSION' ) ? CASHBACK_PLUGIN_VERSION : '1.3.0';
		$min_reason_len   = class_exists( 'Cashback_Users_Management_Admin' )
			? Cashback_Users_Management_Admin::MIN_ADJUST_REASON_LENGTH
			: 20;

		wp_enqueue_style(
			'cashback-admin-balance-adjust',
			plugins_url( '../assets/css/admin-balance-adjust.css', __FILE__ ),
			array(),
			$ver
		);
		wp_enqueue_script(
			'cashback-admin-balance-adjust',
			plugins_url( '../assets/js/admin-balance-adjust.js', __FILE__ ),
			array(),
			$ver,
			true
		);
		wp_localize_script( 'cashback-admin-balance-adjust', 'cashbackBalanceAdjust', array(
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'cashback_adjust_balance_nonce' ),
			'minReasonLength'  => $min_reason_len,
			'amountPlaceholder'=> '+100.00 или -50.25',
			'i18n'             => array(
				'title'          => __( 'Ручная корректировка баланса', 'cashback-plugin' ),
				'forUser'        => __( 'Пользователь ID:', 'cashback-plugin' ),
				'amountLabel'    => __( 'Сумма корректировки', 'cashback-plugin' ),
				'amountHint'     => __( 'Знак +/- обязателен. Не более 2 знаков после точки.', 'cashback-plugin' ),
				'reasonLabel'    => sprintf(
					/* translators: %d: минимум символов. */
					__( 'Причина (минимум %d символов)', 'cashback-plugin' ),
					$min_reason_len
				),
				'confirm'        => __( 'Я понимаю, что это запись в ledger с немедленным эффектом.', 'cashback-plugin' ),
				'cancel'         => __( 'Отмена', 'cashback-plugin' ),
				'apply'          => __( 'Применить', 'cashback-plugin' ),
				'invalidAmount'  => __( 'Введите сумму в формате +100.00 или -50.25.', 'cashback-plugin' ),
				'reasonTooShort' => __( 'Причина должна быть минимум {n} символов.', 'cashback-plugin' ),
				'success'        => __( 'Корректировка применена. Новый баланс: {b}.', 'cashback-plugin' ),
				'genericError'   => __( 'Ошибка применения корректировки.', 'cashback-plugin' ),
				'networkError'   => __( 'Ошибка сети. Повторите.', 'cashback-plugin' ),
			),
		) );
	}

	/**
	 * Компактный виджет на главном dashboard'е плагина.
	 *
	 * Подписан на do_action('cashback_overview_widgets') из admin/statistics.php.
	 * Показывает: N расхождений + «часов назад» от последней проверки + ссылка на S1.
	 */
	public static function render_overview_widget(): void {
		$summary = get_option( Cashback_Balance_Reconciliation::LAST_SUMMARY_OPT, array() );
		if ( ! is_array( $summary ) ) {
			$summary = array();
		}

		$total_mis    = (int) ( $summary['total_mismatches'] ?? 0 );
		$stale_claims = (int) ( $summary['stale_approved_claims'] ?? 0 );
		$finished_at  = (string) ( $summary['finished_at'] ?? '' );

		$hours_ago = null;
		if ( $finished_at !== '' ) {
			$ts = strtotime( $finished_at );
			if ( $ts !== false ) {
				$hours_ago = (int) floor( max( 0, time() - $ts ) / HOUR_IN_SECONDS );
			}
		}

		$has_problems = ( $total_mis > 0 ) || ( $stale_claims > 0 );
		$page_url     = admin_url( 'admin.php?page=' . self::ADMIN_PAGE_SLUG );

		?>
		<div class="cashback-overview-reconcil-widget postbox <?php echo $has_problems ? 'has-problems' : 'all-clear'; ?>">
			<h2 class="hndle"><span><?php esc_html_e( 'Сверка баланса', 'cashback-plugin' ); ?></span></h2>
			<div class="inside">
				<?php if ( $finished_at === '' ) : ?>
					<p><?php esc_html_e( 'Сверка ещё не проводилась. Ежедневная AS-job запускается через час после деплоя.', 'cashback-plugin' ); ?></p>
				<?php else : ?>
					<p>
						<?php
						echo esc_html( sprintf(
							/* translators: 1: расхождений, 2: зависших claims. */
							__( 'Расхождений: %1$d · Зависших approved claims: %2$d', 'cashback-plugin' ),
							$total_mis,
							$stale_claims
						) );
						?>
					</p>
					<?php if ( $hours_ago !== null ) : ?>
						<p class="description">
							<?php
							echo esc_html( sprintf(
								/* translators: %d: часов назад. */
								_n( 'Последняя проверка: %d час назад', 'Последняя проверка: %d часов назад', $hours_ago, 'cashback-plugin' ),
								$hours_ago
							) );
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
				<p>
					<a href="<?php echo esc_url( $page_url ); ?>" class="button button-secondary">
						<?php esc_html_e( 'Подробнее →', 'cashback-plugin' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	public static function register_admin_page(): void {
		add_submenu_page(
			self::PARENT_MENU_SLUG,
			__( 'Сверка баланса', 'cashback-plugin' ),
			__( 'Сверка баланса', 'cashback-plugin' ),
			'manage_options',
			self::ADMIN_PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * SELECT страницы расхождений из cashback_audit_log.
	 *
	 * @return array{rows: array<int,array<string,mixed>>, total: int, total_pages: int}
	 */
	public static function get_mismatches( int $page ): array {
		global $wpdb;

		$page   = max( 1, $page );
		$offset = ( $page - 1 ) * self::MISMATCHES_PER_PAGE;
		$table  = $wpdb->prefix . 'cashback_audit_log';

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE action = %s',
			$table,
			'balance_consistency_mismatch'
		) );

		if ( $total === 0 ) {
			return array( 'rows' => array(), 'total' => 0, 'total_pages' => 0 );
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, entity_id, details, created_at
			 FROM %i
			 WHERE action = %s
			 ORDER BY created_at DESC, id DESC
			 LIMIT %d OFFSET %d',
			$table,
			'balance_consistency_mismatch',
			self::MISMATCHES_PER_PAGE,
			$offset
		), ARRAY_A );

		return array(
			'rows'        => is_array( $rows ) ? $rows : array(),
			'total'       => $total,
			'total_pages' => (int) max( 1, (int) ceil( $total / self::MISMATCHES_PER_PAGE ) ),
		);
	}

	/**
	 * SELECT последних зависших approved claims (LIMIT 100) из audit_log.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_stuck_claims(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'cashback_audit_log';

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, entity_id, details, created_at
			 FROM %i
			 WHERE action = %s
			 ORDER BY created_at DESC, id DESC
			 LIMIT %d',
			$table,
			'claim_approved_no_transaction',
			self::STUCK_CLAIMS_LIMIT
		), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * admin_post handler для кнопки «Запустить сейчас».
	 *
	 * Крутит reconciliation::run() до `completed_round=true` (макс MANUAL_RUN_MAX_BATCHES
	 * итераций — защита от таймаута на больших таблицах). Параллельные нажатия
	 * блокируются через GET_LOCK(, 0) — вторая кнопка сразу увидит flash-ошибку.
	 */
	public static function handle_manual_run(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'cashback-plugin' ) );
		}
		check_admin_referer( self::MANUAL_RUN_NONCE );

		global $wpdb;

		// Lock-имя — константа класса, user-input не участвует; прямой SQL безопасен и
		// совпадает с формулировкой плана: GET_LOCK('cashback_reconcil_manual', 0).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- static lock name.
		$lock = (int) $wpdb->get_var( "SELECT GET_LOCK('cashback_reconcil_manual', 0)" );
		if ( $lock !== 1 ) {
			self::redirect_with_flash(
				'error',
				__( 'Сверка уже запущена другим администратором. Дождитесь её завершения.', 'cashback-plugin' )
			);
			return; // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.no_argumentFound -- unreachable after redirect-exit.
		}

		try {
			$completed    = false;
			$iterations   = 0;
			$mismatches   = 0;
			$scanned      = 0;

			for ( $i = 0; $i < self::MANUAL_RUN_MAX_BATCHES; $i++ ) {
				$result = Cashback_Balance_Reconciliation::run();
				++$iterations;
				$mismatches += (int) ( $result['mismatches'] ?? 0 );
				$scanned    += (int) ( $result['scanned'] ?? 0 );

				if ( ! empty( $result['completed_round'] ) ) {
					$completed = true;
					break;
				}
			}

			if ( $completed ) {
				$message = sprintf(
					/* translators: 1: итераций, 2: расхождений, 3: проверено. */
					__( 'Сверка завершена: %1$d батч(ей), расхождений %2$d, проверено пользователей %3$d.', 'cashback-plugin' ),
					$iterations,
					$mismatches,
					$scanned
				);
				self::redirect_with_flash( 'success', $message );
			} else {
				$message = sprintf(
					/* translators: 1: батчей, 2: промежуточно расхождений. */
					__( 'Выполнено %1$d батчей, round ещё не завершён (промежуточно расхождений: %2$d). Ежедневная AS-job дойдёт до конца автоматически.', 'cashback-plugin' ),
					$iterations,
					$mismatches
				);
				self::redirect_with_flash( 'warning', $message );
			}
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- static lock name.
			$wpdb->query( "SELECT RELEASE_LOCK('cashback_reconcil_manual')" );
		}
	}

	private static function redirect_with_flash( string $level, string $message ): void {
		set_transient( 'cashback_reconcil_flash_' . (int) get_current_user_id(), array(
			'level'   => $level,
			'message' => $message,
		), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::ADMIN_PAGE_SLUG ) );
		exit;
	}

	private static function consume_flash(): ?array {
		$key   = 'cashback_reconcil_flash_' . (int) get_current_user_id();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) || ! isset( $flash['level'], $flash['message'] ) ) {
			return null;
		}
		delete_transient( $key );
		return array(
			'level'   => (string) $flash['level'],
			'message' => (string) $flash['message'],
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'cashback-plugin' ) );
		}

		$summary = get_option( Cashback_Balance_Reconciliation::LAST_SUMMARY_OPT, array() );
		if ( ! is_array( $summary ) ) {
			$summary = array();
		}
		$has_mismatches = (int) ( $summary['total_mismatches'] ?? 0 ) > 0;
		$finished_at    = (string) ( $summary['finished_at'] ?? '' );
		$total_scanned  = (int) ( $summary['total_scanned'] ?? 0 );
		$total_mis      = (int) ( $summary['total_mismatches'] ?? 0 );
		$stale_claims   = (int) ( $summary['stale_approved_claims'] ?? 0 );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing pagination (?paged=N).
		$current_page = isset( $_GET['paged'] ) ? max( 1, min( absint( $_GET['paged'] ), 1000 ) ) : 1;

		$mismatches_page = self::get_mismatches( $current_page );
		$stuck_claims    = self::get_stuck_claims();

		$flash = self::consume_flash();

		?>
		<div class="wrap cashback-balance-reconciliation">
			<h1><?php esc_html_e( 'Сверка баланса', 'cashback-plugin' ); ?></h1>

			<?php if ( $flash !== null ) : ?>
				<?php
				$notice_class = 'notice-info';
				if ( $flash['level'] === 'success' ) {
					$notice_class = 'notice-success';
				} elseif ( $flash['level'] === 'error' ) {
					$notice_class = 'notice-error';
				} elseif ( $flash['level'] === 'warning' ) {
					$notice_class = 'notice-warning';
				}
				?>
				<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
					<p><?php echo esc_html( $flash['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cashback-reconcil-manual-run-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::MANUAL_RUN_ACTION ); ?>" />
				<?php wp_nonce_field( self::MANUAL_RUN_NONCE ); ?>
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Запустить сверку сейчас', 'cashback-plugin' ); ?>
				</button>
				<span class="description">
					<?php
					echo esc_html( sprintf(
						/* translators: %d: max batches. */
						__( 'Максимум %d батчей по 500 пользователей за один клик. Параллельные запуски блокируются.', 'cashback-plugin' ),
						self::MANUAL_RUN_MAX_BATCHES
					) );
					?>
				</span>
			</form>

			<div class="cashback-reconcil-summary <?php echo $has_mismatches ? 'has-mismatches' : ''; ?>">
				<h2><?php esc_html_e( 'Последняя сверка', 'cashback-plugin' ); ?></h2>
				<?php if ( '' === $finished_at ) : ?>
					<p><?php esc_html_e( 'Сверка ещё не проводилась. Ежедневная AS-job запускается через час после деплоя.', 'cashback-plugin' ); ?></p>
				<?php else : ?>
					<ul class="cashback-reconcil-summary-list">
						<li>
							<strong><?php esc_html_e( 'Завершена:', 'cashback-plugin' ); ?></strong>
							<?php echo esc_html( $finished_at ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Проверено пользователей:', 'cashback-plugin' ); ?></strong>
							<?php echo esc_html( number_format_i18n( $total_scanned ) ); ?>
						</li>
						<li class="<?php echo $has_mismatches ? 'mismatches-warning' : ''; ?>">
							<strong><?php esc_html_e( 'Расхождений:', 'cashback-plugin' ); ?></strong>
							<?php echo esc_html( number_format_i18n( $total_mis ) ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Зависших approved claims:', 'cashback-plugin' ); ?></strong>
							<?php echo esc_html( number_format_i18n( $stale_claims ) ); ?>
						</li>
					</ul>
				<?php endif; ?>
			</div>

			<div class="cashback-reconcil-mismatches">
				<h2><?php esc_html_e( 'Расхождения баланса', 'cashback-plugin' ); ?></h2>
				<?php self::render_mismatches_table( $mismatches_page, $current_page ); ?>
			</div>

			<div class="cashback-reconcil-stuck-claims">
				<h2><?php esc_html_e( 'Зависшие approved claims', 'cashback-plugin' ); ?></h2>
				<p class="description">
					<?php
					echo esc_html( sprintf(
						/* translators: %d: максимальное количество записей. */
						__( 'Показаны до %d последних записей. Claims старше 14 дней без парной transaction в cashback_transactions.', 'cashback-plugin' ),
						self::STUCK_CLAIMS_LIMIT
					) );
					?>
				</p>
				<?php self::render_stuck_claims_table( $stuck_claims ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array{rows: array<int,array<string,mixed>>, total: int, total_pages: int} $mismatches
	 */
	private static function render_mismatches_table( array $mismatches, int $current_page ): void {
		$rows  = $mismatches['rows'];
		$total = $mismatches['total'];
		?>
		<table class="wp-list-table widefat fixed striped cashback-reconcil-mismatches-table">
			<thead>
				<tr>
					<th style="width:160px"><?php esc_html_e( 'Дата', 'cashback-plugin' ); ?></th>
					<th style="width:100px"><?php esc_html_e( 'User ID', 'cashback-plugin' ); ?></th>
					<th><?php esc_html_e( 'Issues', 'cashback-plugin' ); ?></th>
					<th style="width:260px"><?php esc_html_e( 'Действия', 'cashback-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'Расхождений не найдено.', 'cashback-plugin' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$uid         = (int) ( $row['entity_id'] ?? 0 );
					$created_at  = (string) ( $row['created_at'] ?? '' );
					$raw_details = (string) ( $row['details'] ?? '' );
					$decoded     = $raw_details !== '' ? json_decode( $raw_details, true ) : null;
					$issues      = array();
					if ( is_array( $decoded ) && isset( $decoded['issues'] ) && is_array( $decoded['issues'] ) ) {
						$issues = $decoded['issues'];
					}
					$user_link = add_query_arg(
						array( 'page' => 'cashback-users', 's' => $uid ),
						admin_url( 'admin.php' )
					);
					?>
					<tr>
						<td><?php echo esc_html( $created_at ); ?></td>
						<td>
							<a href="<?php echo esc_url( $user_link ); ?>">
								<?php echo esc_html( (string) $uid ); ?>
							</a>
						</td>
						<td>
							<?php if ( empty( $issues ) ) : ?>
								—
							<?php else : ?>
								<ul class="cashback-reconcil-issues">
									<?php foreach ( $issues as $issue ) : ?>
										<li><code><?php echo esc_html( (string) $issue ); ?></code></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
						<td>
							<details class="cashback-reconcil-details">
								<summary><?php esc_html_e( 'Показать JSON', 'cashback-plugin' ); ?></summary>
								<pre class="cashback-reconcil-json"><?php echo esc_html( $raw_details ); ?></pre>
							</details>
							<button
								type="button"
								class="button button-secondary cashback-reconcil-adjust-btn"
								data-user-id="<?php echo esc_attr( (string) $uid ); ?>"
							>
								<?php esc_html_e( 'Корректировка', 'cashback-plugin' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<?php
		if ( $total > 0 && class_exists( 'Cashback_Pagination' ) ) {
			Cashback_Pagination::render( array(
				'mode'         => 'link',
				'total_items'  => $total,
				'current_page' => $current_page,
				'total_pages'  => (int) $mismatches['total_pages'],
				'page_slug'    => self::ADMIN_PAGE_SLUG,
				'add_args'     => array(),
			) );
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private static function render_stuck_claims_table( array $rows ): void {
		?>
		<table class="wp-list-table widefat fixed striped cashback-reconcil-stuck-claims-table">
			<thead>
				<tr>
					<th style="width:160px"><?php esc_html_e( 'Дата фиксации', 'cashback-plugin' ); ?></th>
					<th style="width:100px"><?php esc_html_e( 'User ID', 'cashback-plugin' ); ?></th>
					<th style="width:100px"><?php esc_html_e( 'Claim ID', 'cashback-plugin' ); ?></th>
					<th><?php esc_html_e( 'Merchant', 'cashback-plugin' ); ?></th>
					<th style="width:110px"><?php esc_html_e( 'Cashback', 'cashback-plugin' ); ?></th>
					<th style="width:150px"><?php esc_html_e( 'Approved', 'cashback-plugin' ); ?></th>
					<th style="width:200px"><?php esc_html_e( 'Действие', 'cashback-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'Зависших claims не найдено.', 'cashback-plugin' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$created_at  = (string) ( $row['created_at'] ?? '' );
					$claim_id    = (int) ( $row['entity_id'] ?? 0 );
					$raw_details = (string) ( $row['details'] ?? '' );
					$decoded     = $raw_details !== '' ? json_decode( $raw_details, true ) : null;

					$uid        = is_array( $decoded ) ? (int) ( $decoded['user_id'] ?? 0 ) : 0;
					$merchant   = is_array( $decoded ) ? (string) ( $decoded['merchant_name'] ?? '' ) : '';
					$amount     = is_array( $decoded ) ? (string) ( $decoded['amount'] ?? '' ) : '';
					$approved   = is_array( $decoded ) ? (string) ( $decoded['approved_at'] ?? '' ) : '';

					$add_tx_url = add_query_arg(
						array(
							'page'                => 'cashback-transactions',
							'action'              => 'add',
							'prefill_user_id'     => $uid,
							'prefill_merchant'    => rawurlencode( $merchant ),
							'prefill_cashback'    => $amount,
							'prefill_from_claim'  => $claim_id,
						),
						admin_url( 'admin.php' )
					);
					?>
					<tr>
						<td><?php echo esc_html( $created_at ); ?></td>
						<td><?php echo esc_html( (string) $uid ); ?></td>
						<td><?php echo esc_html( (string) $claim_id ); ?></td>
						<td><?php echo esc_html( $merchant ); ?></td>
						<td><?php echo esc_html( $amount ); ?></td>
						<td><?php echo esc_html( $approved ); ?></td>
						<td>
							<a href="<?php echo esc_url( $add_tx_url ); ?>" class="button button-secondary">
								<?php esc_html_e( 'Создать транзакцию вручную', 'cashback-plugin' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}
}
