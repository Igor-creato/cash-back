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
		add_action( 'wp_ajax_cashback_stuck_claim_load', array( __CLASS__, 'handle_load_stuck_claim' ) );
		add_action( 'wp_ajax_cashback_stuck_claim_create_tx', array( __CLASS__, 'handle_create_stuck_claim_tx' ) );
	}

	/**
	 * Подключает JS/CSS модала корректировки + ref на MIN_ADJUST_REASON_LENGTH
	 * из users-management. Активируется только на подстранице сверки.
	 */
	public static function enqueue_assets( string $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only slug-detect для enqueue.
		$page_query     = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$on_recon_page  = strpos( $hook, self::ADMIN_PAGE_SLUG ) !== false || $page_query === self::ADMIN_PAGE_SLUG;
		$on_overview    = $page_query === 'cashback-overview';
		if ( ! $on_recon_page && ! $on_overview ) {
			return;
		}

		$ver              = defined( 'CASHBACK_PLUGIN_VERSION' ) ? CASHBACK_PLUGIN_VERSION : '1.3.0';
		$min_reason_len   = class_exists( 'Cashback_Users_Management_Admin' )
			? Cashback_Users_Management_Admin::MIN_ADJUST_REASON_LENGTH
			: 20;

		// CSS для раскрывающейся ячейки «Расхождение» и виджета на overview —
		// нужен на обеих страницах (recon-page + overview).
		wp_enqueue_style(
			'cashback-admin-balance-reconciliation',
			plugins_url( '../assets/css/admin-balance-reconciliation.css', __FILE__ ),
			array(),
			$ver
		);

		// Модал корректировки кнопки «Корректировка» нужен только на recon-page
		// (на overview виджет кнопок не имеет).
		if ( ! $on_recon_page ) {
			return;
		}

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

		// Модал ручного создания транзакции из зависших approved claims —
		// независимый от balance-adjust, нужен только на recon-page.
		wp_enqueue_style(
			'cashback-admin-stuck-claim-tx',
			plugins_url( '../assets/css/admin-stuck-claim-tx.css', __FILE__ ),
			array(),
			$ver
		);
		wp_enqueue_script(
			'cashback-admin-stuck-claim-tx',
			plugins_url( '../assets/js/admin-stuck-claim-tx.js', __FILE__ ),
			array(),
			$ver,
			true
		);
		wp_localize_script( 'cashback-admin-stuck-claim-tx', 'cashbackStuckClaimTx', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cashback_stuck_claim_nonce' ),
			'i18n'    => array(
				'loading'           => __( 'Загрузка данных claim…', 'cashback-plugin' ),
				'invalidComission'  => __( 'Некорректная комиссия. Используйте число до 2 знаков после точки.', 'cashback-plugin' ),
				'comissionPositive' => __( 'Комиссия должна быть больше нуля.', 'cashback-plugin' ),
				'selectFundsReady'  => __( 'Выберите значение', 'cashback-plugin' ),
				'genericError'      => __( 'Ошибка создания транзакции.', 'cashback-plugin' ),
				'networkError'      => __( 'Ошибка сети. Повторите.', 'cashback-plugin' ),
			),
		) );

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
		self::render_stuck_claim_modal();
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
					<th style="width:100px"><?php esc_html_e( 'User', 'cashback-plugin' ); ?></th>
					<th><?php esc_html_e( 'Расхождение', 'cashback-plugin' ); ?></th>
					<th style="width:180px"><?php esc_html_e( 'Действия', 'cashback-plugin' ); ?></th>
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
					$details_arr = is_array( $decoded ) ? $decoded : array();
					$issues      = is_array( $details_arr['issues'] ?? null ) ? $details_arr['issues'] : array();

					// Короткая сводка для <summary>: первый переведённый issue + «+N ещё».
					$summary_parts = array();
					if ( ! empty( $issues ) && class_exists( 'Cashback_Balance_Issue_Renderer' ) ) {
						$summary_parts[] = Cashback_Balance_Issue_Renderer::translate_issue( (string) $issues[0] );
					} elseif ( ! empty( $issues ) ) {
						$summary_parts[] = (string) $issues[0];
					}
					$extra = count( $issues ) - 1;
					if ( $extra > 0 ) {
						$summary_parts[] = '+ ещё ' . $extra;
					}
					$summary_text = implode( ' · ', $summary_parts );

					$user_link = add_query_arg(
						array( 'page' => 'cashback-users', 's' => $uid ),
						admin_url( 'admin.php' )
					);
					?>
					<tr>
						<td><?php echo esc_html( $created_at ); ?></td>
						<td>
							<a href="<?php echo esc_url( $user_link ); ?>">
								#<?php echo esc_html( (string) $uid ); ?>
							</a>
						</td>
						<td>
							<?php if ( empty( $issues ) ) : ?>
								—
							<?php else : ?>
								<details class="cashback-reconcil-details">
									<summary><?php echo esc_html( $summary_text ); ?></summary>
									<div class="cashback-reconcil-details-body">
										<?php
										if ( class_exists( 'Cashback_Balance_Issue_Renderer' ) ) {
											// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_mismatch_details_html сам выполняет esc_html/esc_attr для всех динамических значений.
											echo Cashback_Balance_Issue_Renderer::render_mismatch_details_html( $details_arr );
										}
										?>
										<details class="cashback-reconcil-raw-details">
											<summary><?php esc_html_e( 'Показать raw JSON (для разработчика)', 'cashback-plugin' ); ?></summary>
											<pre class="cashback-reconcil-json"><?php echo esc_html( $raw_details ); ?></pre>
										</details>
									</div>
								</details>
							<?php endif; ?>
						</td>
						<td>
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
					<th style="width:220px"><?php esc_html_e( 'Действие', 'cashback-plugin' ); ?></th>
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
					?>
					<tr>
						<td><?php echo esc_html( $created_at ); ?></td>
						<td><?php echo esc_html( (string) $uid ); ?></td>
						<td><?php echo esc_html( (string) $claim_id ); ?></td>
						<td><?php echo esc_html( $merchant ); ?></td>
						<td><?php echo esc_html( $amount ); ?></td>
						<td><?php echo esc_html( $approved ); ?></td>
						<td>
							<button
								type="button"
								class="button button-secondary cashback-stuck-create-tx"
								data-claim-id="<?php echo esc_attr( (string) $claim_id ); ?>"
							>
								<?php esc_html_e( 'Создать транзакцию вручную', 'cashback-plugin' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Hidden-разметка модала «Создать транзакцию вручную». Один раз на страницу.
	 *
	 * Все поля кроме «Комиссия» и «Готова к выплате?» заполняются через AJAX
	 * `cashback_stuck_claim_load` и помечены readonly. Сабмит идёт в
	 * `cashback_stuck_claim_create_tx` — INSERT в cashback_transactions
	 * выполняется в той же транзакции БД, что и проверки claim/click.
	 */
	private static function render_stuck_claim_modal(): void {
		?>
		<div id="cashback-stuck-tx-backdrop" class="cashback-stuck-tx-backdrop" hidden>
			<div
				class="cashback-stuck-tx-modal"
				role="dialog"
				aria-modal="true"
				aria-labelledby="cashback-stuck-tx-title"
				tabindex="-1"
			>
				<h2 id="cashback-stuck-tx-title">
					<?php esc_html_e( 'Создать транзакцию вручную', 'cashback-plugin' ); ?>
					<span class="cashback-stuck-tx-claim-badge">
						claim #<span data-bind="claim_id">—</span>
					</span>
				</h2>

				<div class="cashback-stuck-tx-loading" data-role="loading">
					<?php esc_html_e( 'Загрузка данных claim…', 'cashback-plugin' ); ?>
				</div>

				<div class="cashback-stuck-tx-body" data-role="body" hidden>
					<dl class="cashback-stuck-tx-readonly">
						<dt><?php esc_html_e( 'User ID', 'cashback-plugin' ); ?></dt>
						<dd data-bind="user_id">—</dd>

						<dt><?php esc_html_e( 'Click ID', 'cashback-plugin' ); ?></dt>
						<dd data-bind="click_id" class="cashback-stuck-tx-mono">—</dd>

						<dt><?php esc_html_e( 'Merchant', 'cashback-plugin' ); ?></dt>
						<dd data-bind="merchant_name">—</dd>

						<dt><?php esc_html_e( 'Order ID', 'cashback-plugin' ); ?></dt>
						<dd data-bind="order_id">—</dd>

						<dt><?php esc_html_e( 'Order value', 'cashback-plugin' ); ?></dt>
						<dd data-bind="order_value">—</dd>

						<dt><?php esc_html_e( 'Order date', 'cashback-plugin' ); ?></dt>
						<dd data-bind="order_date">—</dd>

						<dt><?php esc_html_e( 'CPA-сеть', 'cashback-plugin' ); ?></dt>
						<dd data-bind="partner">—</dd>

						<dt><?php esc_html_e( 'Click time', 'cashback-plugin' ); ?></dt>
						<dd data-bind="click_time">—</dd>

						<dt><?php esc_html_e( 'Approved', 'cashback-plugin' ); ?></dt>
						<dd data-bind="approved_at">—</dd>
					</dl>

					<div class="cashback-stuck-tx-field">
						<label for="cashback-stuck-tx-comission">
							<?php esc_html_e( 'Комиссия', 'cashback-plugin' ); ?>
						</label>
						<input
							type="text"
							id="cashback-stuck-tx-comission"
							name="comission"
							inputmode="decimal"
							pattern="^\d+(\.\d{1,2})?$"
							placeholder="0.00"
							autocomplete="off"
							required
						/>
						<p class="description">
							<?php esc_html_e( 'Положительное число, до 2 знаков после точки. Кэшбэк рассчитается автоматически.', 'cashback-plugin' ); ?>
						</p>
					</div>

					<div class="cashback-stuck-tx-field">
						<label for="cashback-stuck-tx-funds-ready">
							<?php esc_html_e( 'Готова к выплате?', 'cashback-plugin' ); ?>
						</label>
						<select id="cashback-stuck-tx-funds-ready" name="funds_ready" required>
							<option value=""><?php esc_html_e( 'Выберите вариант', 'cashback-plugin' ); ?></option>
							<option value="1"><?php esc_html_e( 'Да', 'cashback-plugin' ); ?></option>
							<option value="0"><?php esc_html_e( 'Нет', 'cashback-plugin' ); ?></option>
						</select>
					</div>
				</div>

				<div class="cashback-stuck-tx-message" data-role="message" hidden></div>

				<div class="cashback-stuck-tx-actions">
					<button type="button" class="button button-secondary" data-role="cancel">
						<?php esc_html_e( 'Отмена', 'cashback-plugin' ); ?>
					</button>
					<button type="button" class="button button-primary" data-role="submit" disabled>
						<?php esc_html_e( 'Создать транзакцию', 'cashback-plugin' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: вернуть pre-fill для модала по `claim_id`.
	 *
	 * Контракт:
	 *  - nonce = `cashback_stuck_claim_nonce` (общий для load + create).
	 *  - capability = manage_options.
	 *  - claim должен существовать и быть в статусе `approved`.
	 *  - tx по паре (user_id, click_id) ещё не создана.
	 *  - cashback_click_log опционален (LEFT JOIN) — partner/click_time могут быть NULL.
	 */
	public static function handle_load_stuck_claim(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( (string) $_POST['nonce'] ) ),
			'cashback_stuck_claim_nonce'
		) ) {
			wp_send_json_error( array( 'message' => __( 'Неверный токен безопасности.', 'cashback-plugin' ) ), 403 );
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'cashback-plugin' ) ), 403 );
			return;
		}

		$claim_id = isset( $_POST['claim_id'] ) ? absint( $_POST['claim_id'] ) : 0;
		if ( $claim_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Некорректный claim_id.', 'cashback-plugin' ) ) );
			return;
		}

		global $wpdb;

		$claims_table = $wpdb->prefix . 'cashback_claims';
		$click_table  = $wpdb->prefix . 'cashback_click_log';
		$tx_table     = $wpdb->prefix . 'cashback_transactions';

		$claim = $wpdb->get_row( $wpdb->prepare(
			'SELECT claim_id, user_id, click_id, merchant_id, merchant_name,
			        product_id, product_name,
			        order_id, order_value, order_date, status, updated_at
			 FROM %i
			 WHERE claim_id = %d
			 LIMIT 1',
			$claims_table,
			$claim_id
		), ARRAY_A );

		if ( ! is_array( $claim ) ) {
			wp_send_json_error( array( 'message' => __( 'Claim не найден.', 'cashback-plugin' ) ) );
			return;
		}
		if ( (string) ( $claim['status'] ?? '' ) !== 'approved' ) {
			wp_send_json_error( array( 'message' => __( 'Claim не в статусе approved.', 'cashback-plugin' ) ) );
			return;
		}

		$user_id  = (int) ( $claim['user_id'] ?? 0 );
		$click_id = (string) ( $claim['click_id'] ?? '' );

		$existing_tx_id = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM %i WHERE user_id = %d AND click_id = %s LIMIT 1',
			$tx_table,
			$user_id,
			$click_id
		) );
		if ( $existing_tx_id > 0 ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %d: id транзакции. */
					__( 'Транзакция для этого click_id уже существует (ID %d).', 'cashback-plugin' ),
					$existing_tx_id
				),
			) );
			return;
		}

		$click = $wpdb->get_row( $wpdb->prepare(
			'SELECT cpa_network, created_at FROM %i WHERE click_id = %s LIMIT 1',
			$click_table,
			$click_id
		), ARRAY_A );

		$cpa_slug   = is_array( $click ) ? (string) ( $click['cpa_network'] ?? '' ) : '';
		$click_time = is_array( $click ) ? (string) ( $click['created_at'] ?? '' ) : '';

		$shop_name    = self::resolve_product_name( $claim );
		$network_name = self::resolve_network_name( $cpa_slug );

		wp_send_json_success( array(
			'claim_id'      => (int) $claim['claim_id'],
			'user_id'       => $user_id,
			'click_id'      => $click_id,
			'merchant_id'   => isset( $claim['merchant_id'] ) ? (int) $claim['merchant_id'] : 0,
			'merchant_name' => $shop_name,
			'order_id'      => (string) ( $claim['order_id'] ?? '' ),
			'order_value'   => (string) ( $claim['order_value'] ?? '' ),
			'order_date'    => (string) ( $claim['order_date'] ?? '' ),
			'approved_at'   => (string) ( $claim['updated_at'] ?? '' ),
			'partner'       => $network_name,
			'click_time'    => $click_time,
		) );
	}

	/**
	 * Возвращает каноничное название «магазина» (WooCommerce-товара).
	 *
	 * Приоритет:
	 *   1. wc_get_product($product_id)->get_name() — текущее название из WP_Posts.
	 *   2. claim.product_name — снапшот при создании заявки (если товар удалён).
	 *   3. claim.merchant_name — что юзер ввёл при создании claim'а (фолбэк).
	 *
	 * @param array<string,mixed> $claim Строка из cashback_claims.
	 */
	private static function resolve_product_name( array $claim ): string {
		$product_id = isset( $claim['product_id'] ) ? (int) $claim['product_id'] : 0;
		if ( $product_id > 0 && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$name = (string) $product->get_name();
				if ( $name !== '' ) {
					return $name;
				}
			}
		}
		$snapshot = (string) ( $claim['product_name'] ?? '' );
		if ( $snapshot !== '' ) {
			return $snapshot;
		}
		return (string) ( $claim['merchant_name'] ?? '' );
	}

	/**
	 * Возвращает display-имя CPA-сети по slug'у из cashback_click_log.cpa_network.
	 *
	 * cashback_click_log.cpa_network хранит slug ('admitad', 'epn', ...);
	 * каноничное полное имя — в cashback_affiliate_networks.name. Если запись
	 * не найдена / удалена / неактивна — возвращаем slug как-есть (фолбэк).
	 */
	private static function resolve_network_name( string $cpa_slug ): string {
		$cpa_slug = trim( $cpa_slug );
		if ( $cpa_slug === '' ) {
			return '';
		}
		global $wpdb;
		$networks_table = $wpdb->prefix . 'cashback_affiliate_networks';
		$name = $wpdb->get_var( $wpdb->prepare(
			'SELECT name FROM %i WHERE slug = %s LIMIT 1',
			$networks_table,
			$cpa_slug
		) );
		if ( is_string( $name ) && $name !== '' ) {
			return $name;
		}
		return $cpa_slug;
	}

	/**
	 * AJAX: атомарно создать транзакцию из approved-claim.
	 *
	 * Контракт:
	 *  - nonce = `cashback_stuck_claim_nonce`, capability = manage_options.
	 *  - server-side дедуп `request_id` через Cashback_Idempotency
	 *    (scope = `admin_stuck_claim_tx`).
	 *  - Валидация: comission strict regex `^\d+(\.\d{1,2})?$` + > 0;
	 *    funds_ready ∈ {'0','1'} (строгая строковая проверка).
	 *  - INSERT внутри START TRANSACTION + FOR UPDATE на claim/tx;
	 *    UNIQUE idempotency_key = `manual_claim_<claim_id>` — последний рубеж от дублей.
	 *  - api_verified = 1, order_status = 'completed', currency = 'RUB',
	 *    reference_id/cashback/applied_cashback_rate проставит триггер
	 *    calculate_cashback_before_insert.
	 *  - Audit-log: `manual_tx_from_stuck_claim` (admin_id, claim_id, tx_id, request_id).
	 */
	public static function handle_create_stuck_claim_tx(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( (string) $_POST['nonce'] ) ),
			'cashback_stuck_claim_nonce'
		) ) {
			wp_send_json_error( array( 'message' => __( 'Неверный токен безопасности.', 'cashback-plugin' ) ), 403 );
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'cashback-plugin' ) ), 403 );
			return;
		}

		$idem_scope   = 'admin_stuck_claim_tx';
		$admin_id     = (int) get_current_user_id();
		$idem_request = '';
		if ( isset( $_POST['request_id'] ) && is_string( $_POST['request_id'] ) ) {
			$idem_request = Cashback_Idempotency::normalize_request_id(
				sanitize_text_field( wp_unslash( $_POST['request_id'] ) )
			);
		}
		if ( $idem_request !== '' ) {
			$stored = Cashback_Idempotency::get_stored_result( $idem_scope, $admin_id, $idem_request );
			if ( $stored !== null ) {
				wp_send_json_success( $stored );
				return;
			}
			if ( ! Cashback_Idempotency::claim( $idem_scope, $admin_id, $idem_request ) ) {
				wp_send_json_error( array(
					'code'    => 'in_progress',
					'message' => __( 'Запрос уже обрабатывается. Повторите через несколько секунд.', 'cashback-plugin' ),
				), 409 );
				return;
			}
		}

		$claim_id = isset( $_POST['claim_id'] ) ? absint( $_POST['claim_id'] ) : 0;
		if ( $claim_id <= 0 ) {
			if ( $idem_request !== '' ) {
				Cashback_Idempotency::forget( $idem_scope, $admin_id, $idem_request );
			}
			wp_send_json_error( array( 'message' => __( 'Некорректный claim_id.', 'cashback-plugin' ) ) );
			return;
		}

		// funds_ready — строгая строковая проверка ДО любых cast'ов.
		$raw_funds_ready = isset( $_POST['funds_ready'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['funds_ready'] ) )
			: '';
		if ( $raw_funds_ready !== '0' && $raw_funds_ready !== '1' ) {
			if ( $idem_request !== '' ) {
				Cashback_Idempotency::forget( $idem_scope, $admin_id, $idem_request );
			}
			wp_send_json_error( array( 'message' => __( 'Выберите значение', 'cashback-plugin' ) ) );
			return;
		}
		$funds_ready = (int) $raw_funds_ready;

		// comission — строгая regex + положительное.
		$raw_comission = isset( $_POST['comission'] )
			? trim( sanitize_text_field( wp_unslash( (string) $_POST['comission'] ) ) )
			: '';
		if ( ! (bool) preg_match( '/^\d+(\.\d{1,2})?$/', $raw_comission ) ) {
			if ( $idem_request !== '' ) {
				Cashback_Idempotency::forget( $idem_scope, $admin_id, $idem_request );
			}
			wp_send_json_error( array( 'message' => __( 'Некорректная комиссия. Используйте число до 2 знаков после точки.', 'cashback-plugin' ) ) );
			return;
		}
		// Сравнение строк через bccomp избегает float-погрешностей в граничных случаях
		// типа '0.00' / '0.001'. Если bcmath нет — fallback на (float).
		$comission_positive = function_exists( 'bccomp' )
			? ( bccomp( $raw_comission, '0', 2 ) === 1 )
			: ( (float) $raw_comission > 0.0 );
		if ( ! $comission_positive ) {
			if ( $idem_request !== '' ) {
				Cashback_Idempotency::forget( $idem_scope, $admin_id, $idem_request );
			}
			wp_send_json_error( array( 'message' => __( 'Комиссия должна быть больше нуля.', 'cashback-plugin' ) ) );
			return;
		}

		global $wpdb;

		$claims_table = $wpdb->prefix . 'cashback_claims';
		$click_table  = $wpdb->prefix . 'cashback_click_log';
		$tx_table     = $wpdb->prefix . 'cashback_transactions';

		$wpdb->query( 'START TRANSACTION' );

		try {
			$claim = $wpdb->get_row( $wpdb->prepare(
				'SELECT claim_id, user_id, click_id, merchant_id, merchant_name,
				        product_id, product_name,
				        order_id, order_value, order_date, status
				 FROM %i
				 WHERE claim_id = %d
				 FOR UPDATE',
				$claims_table,
				$claim_id
			), ARRAY_A );

			if ( ! is_array( $claim ) ) {
				throw new \RuntimeException( __( 'Claim не найден.', 'cashback-plugin' ) );
			}
			if ( (string) ( $claim['status'] ?? '' ) !== 'approved' ) {
				throw new \RuntimeException( __( 'Claim не в статусе approved.', 'cashback-plugin' ) );
			}

			$user_id  = (int) $claim['user_id'];
			$click_id = (string) $claim['click_id'];

			$existing_tx_id = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM %i WHERE user_id = %d AND click_id = %s LIMIT 1 FOR UPDATE',
				$tx_table,
				$user_id,
				$click_id
			) );
			if ( $existing_tx_id > 0 ) {
				throw new \RuntimeException( sprintf(
					/* translators: %d: id транзакции. */
					__( 'Транзакция для этого click_id уже существует (ID %d).', 'cashback-plugin' ),
					$existing_tx_id
				) );
			}

			$click = $wpdb->get_row( $wpdb->prepare(
				'SELECT cpa_network, created_at FROM %i WHERE click_id = %s LIMIT 1',
				$click_table,
				$click_id
			), ARRAY_A );

			$cpa_slug   = is_array( $click ) ? (string) ( $click['cpa_network'] ?? '' ) : '';
			$click_time = is_array( $click ) ? (string) ( $click['created_at'] ?? '' ) : '';

			// Каноничные display-имена (как в основной таблице транзакций / API-валидации):
			// offer_name — название WooCommerce-товара ([class-claims-eligibility.php:84]),
			// partner — полное имя CPA-сети из cashback_affiliate_networks.name, не slug.
			$shop_name    = self::resolve_product_name( $claim );
			$network_name = self::resolve_network_name( $cpa_slug );

			$idempotency_key = 'manual_claim_' . $claim_id;

			// Опциональные NULL-поля убираем из массива целиком — MariaDB подставит
			// DEFAULT NULL по схеме (mariadb.php:241-282), $wpdb->insert не умеет NULL.
			$insert_data = array(
				'user_id'            => $user_id,
				'order_number'       => (string) ( $claim['order_id'] ?? '' ),
				'offer_name'         => $shop_name,
				'order_status'       => 'completed',
				'partner'            => $network_name,
				'sum_order'          => (string) ( $claim['order_value'] ?? '0.00' ),
				'comission'          => $raw_comission,
				'currency'           => 'RUB',
				'api_verified'       => 1,
				'action_date'        => (string) ( $claim['order_date'] ?? '' ),
				'click_id'           => $click_id,
				'idempotency_key'    => $idempotency_key,
				'original_cpa_subid' => (string) $user_id,
				'funds_ready'        => $funds_ready,
				'created_by_admin'   => 1,
			);
			if ( isset( $claim['merchant_id'] ) && $claim['merchant_id'] !== null ) {
				$insert_data['offer_id'] = (int) $claim['merchant_id'];
			}
			if ( $click_time !== '' ) {
				$insert_data['click_time'] = $click_time;
			}

			$insert_format = array();
			foreach ( $insert_data as $col => $val ) {
				if ( in_array( $col, array( 'user_id', 'offer_id', 'api_verified', 'funds_ready', 'created_by_admin' ), true ) ) {
					$insert_format[] = '%d';
				} else {
					$insert_format[] = '%s';
				}
			}

			$inserted = $wpdb->insert( $tx_table, $insert_data, $insert_format );

			if ( $inserted === false ) {
				$db_error = (string) $wpdb->last_error;
				if ( $db_error !== '' && stripos( $db_error, 'Duplicate' ) !== false ) {
					throw new \RuntimeException( __( 'Транзакция уже создана (idempotency_key).', 'cashback-plugin' ) );
				}
				throw new \RuntimeException( 'INSERT failed: ' . $db_error );
			}

			$tx_id = (int) $wpdb->insert_id;

			$inserted_row = $wpdb->get_row( $wpdb->prepare(
				'SELECT reference_id, cashback, applied_cashback_rate
				 FROM %i WHERE id = %d',
				$tx_table,
				$tx_id
			), ARRAY_A );

			$reference_id          = is_array( $inserted_row ) ? (string) ( $inserted_row['reference_id'] ?? '' ) : '';
			$cashback              = is_array( $inserted_row ) ? (string) ( $inserted_row['cashback'] ?? '' ) : '';
			$applied_cashback_rate = is_array( $inserted_row ) ? (string) ( $inserted_row['applied_cashback_rate'] ?? '' ) : '';

			if ( class_exists( 'Cashback_Encryption' ) ) {
				Cashback_Encryption::write_audit_log(
					'manual_tx_from_stuck_claim',
					$admin_id,
					'transaction',
					$tx_id,
					array(
						'claim_id'              => $claim_id,
						'user_id'               => $user_id,
						'click_id'              => $click_id,
						'comission'             => $raw_comission,
						'funds_ready'           => $funds_ready,
						'cashback'              => $cashback,
						'reference_id'          => $reference_id,
						'applied_cashback_rate' => $applied_cashback_rate,
						'idempotency_key'       => $idempotency_key,
						'request_id'            => $idem_request,
					)
				);
			}

			$wpdb->query( 'COMMIT' );

			$payload = array(
				'tx_id'        => $tx_id,
				'reference_id' => $reference_id,
				'cashback'     => $cashback,
				'funds_ready'  => $funds_ready,
				'message'      => sprintf(
					/* translators: 1: reference_id, 2: cashback. */
					__( 'Транзакция %1$s создана. Кэшбэк: %2$s.', 'cashback-plugin' ),
					$reference_id !== '' ? $reference_id : ( '#' . $tx_id ),
					$cashback
				),
			);

			if ( $idem_request !== '' ) {
				Cashback_Idempotency::store_result( $idem_scope, $admin_id, $idem_request, $payload );
			}

			wp_send_json_success( $payload );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			if ( $idem_request !== '' ) {
				Cashback_Idempotency::forget( $idem_scope, $admin_id, $idem_request );
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic logging.
			error_log( '[cashback_stuck_claim_create_tx] ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}
}
