<?php
/**
 * Admin-UI для ежедневной сверки баланса (Группа 15, шаг S1).
 *
 * Подстраница `cashback-overview` → «Сверка баланса».
 * Показывает:
 *  - Summary последнего reconciliation-round'а (option cashback_balance_reconciliation_last_summary).
 *  - Таблицу расхождений (audit_log action='balance_consistency_mismatch') — S1.B.
 *  - Таблицу зависших approved claims (action='claim_approved_no_transaction') — S1.B.
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

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
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

		?>
		<div class="wrap cashback-balance-reconciliation">
			<h1><?php esc_html_e( 'Сверка баланса', 'cashback-plugin' ); ?></h1>

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
				<p><?php esc_html_e( 'Таблица будет добавлена в следующем шаге.', 'cashback-plugin' ); ?></p>
			</div>

			<div class="cashback-reconcil-stuck-claims">
				<h2><?php esc_html_e( 'Зависшие approved claims', 'cashback-plugin' ); ?></h2>
				<p><?php esc_html_e( 'Таблица будет добавлена в следующем шаге.', 'cashback-plugin' ); ?></p>
			</div>
		</div>
		<?php
	}
}
