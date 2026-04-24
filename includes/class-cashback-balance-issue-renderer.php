<?php
/**
 * Человеко-читаемый рендер результатов Mariadb_Plugin::validate_user_balance_consistency.
 *
 * Два метода:
 *   - translate_issue($issue)         — перевод технического issue-сообщения в
 *                                       русскую фразу (9 известных паттернов +
 *                                       fallback на сырой текст).
 *   - render_mismatch_details_html($details) — «бухгалтерский» HTML-блок с:
 *       * таблицей сравнения бакетов (available/pending/paid/frozen)
 *         по ledger vs кэшу с колонкой «Разница»;
 *       * списком issues человеческими фразами;
 *       * разбивкой операций ledger'а (приходы / расходы / бан)
 *         с количеством и суммой по каждому типу, скрывая нулевые.
 *
 * Используется в Cashback_Balance_Reconciliation_Admin (таблица расхождений,
 * Группа 15, S1.B) и в admin/payouts.php через translate_issue (DRY).
 *
 * @since 1.3.1 (Группа 15, follow-up: UX-шлифовка details-ячейки)
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Cashback_Balance_Issue_Renderer {

	/**
	 * Типографский минус (U+2212) — для отображения отрицательных сумм.
	 */
	private const MINUS_SIGN = "\xe2\x88\x92";

	/**
	 * Переводит технический issue из validate_user_balance_consistency
	 * в русскую фразу для админа. Незнакомые форматы возвращаются as-is
	 * (escape'ится выше по стеку).
	 */
	public static function translate_issue( string $issue ): string {
		// 1. total balance mismatch
		if ( preg_match( '/^total balance mismatch: ledger=([\d.\-]+), cache=([\d.\-]+)/', $issue, $m ) ) {
			$diff = bcsub( (string) $m[2], (string) $m[1], 2 );
			return sprintf(
				'Общая сумма средств не совпадает: по журналу операций %s ₽, по кэшу баланса %s ₽ (разница: %s ₽).',
				self::format_money( (string) $m[1] ),
				self::format_money( (string) $m[2] ),
				self::format_money( $diff, true )
			);
		}

		// 2. available_balance mismatch
		if ( preg_match( '/^available_balance mismatch: ledger=([\d.\-]+), cache=([\d.\-]+)/', $issue, $m ) ) {
			$diff = bcsub( (string) $m[2], (string) $m[1], 2 );
			return sprintf(
				'Доступный баланс не совпадает: по журналу %s ₽, в кэше %s ₽ (разница: %s ₽).',
				self::format_money( (string) $m[1] ),
				self::format_money( (string) $m[2] ),
				self::format_money( $diff, true )
			);
		}

		// 3. pending_balance mismatch
		if ( preg_match( '/^pending_balance mismatch: ledger=([\d.\-]+), cache=([\d.\-]+)/', $issue, $m ) ) {
			$diff = bcsub( (string) $m[2], (string) $m[1], 2 );
			return sprintf(
				'Баланс «в ожидании выплаты» не совпадает: по журналу %s ₽, в кэше %s ₽ (разница: %s ₽).',
				self::format_money( (string) $m[1] ),
				self::format_money( (string) $m[2] ),
				self::format_money( $diff, true )
			);
		}

		// 4. frozen_balance mismatch (banned)
		if ( preg_match( '/^frozen_balance mismatch \(banned\): ledger.*=([\d.\-]+), cache.*=([\d.\-]+)/', $issue, $m ) ) {
			return sprintf(
				'Замороженный баланс (пользователь забанен) не совпадает: по журналу %s ₽, в кэше %s ₽.',
				self::format_money( (string) $m[1] ),
				self::format_money( (string) $m[2] )
			);
		}

		// 5. frozen_balance mismatch (обычный)
		if ( preg_match( '/^frozen_balance mismatch: ledger.*=([\d.\-]+), cache=([\d.\-]+)/', $issue, $m ) ) {
			return sprintf(
				'Замороженный баланс не совпадает: по журналу %s ₽, в кэше %s ₽.',
				self::format_money( (string) $m[1] ),
				self::format_money( (string) $m[2] )
			);
		}

		// 6. paid_balance mismatch
		if ( preg_match( '/^paid_balance mismatch: ledger=([\d.\-]+), cache=([\d.\-]+)/', $issue, $m ) ) {
			return sprintf(
				'Сумма «Выплачено» не совпадает: по журналу %s ₽, в кэше %s ₽.',
				self::format_money( (string) $m[1] ),
				self::format_money( (string) $m[2] )
			);
		}

		// 7. duplicate accrual entries
		if ( preg_match( '/^duplicate accrual entries: (\d+)/', $issue, $m ) ) {
			return sprintf(
				'Обнаружено %d дублированных начислений кэшбэка — одна транзакция начислена несколько раз.',
				(int) $m[1]
			);
		}

		// 8. negative calculated available balance
		if ( preg_match( '/^negative calculated available balance: ([\d.\-]+)/', $issue, $m ) ) {
			return sprintf(
				'Расчётный доступный баланс отрицательный: %s ₽ — возможно, списано больше, чем начислено.',
				self::format_money( (string) $m[1] )
			);
		}

		// 9. payout_complete without payout_hold
		if ( preg_match( '/^payout_complete without payout_hold: (\d+)/', $issue, $m ) ) {
			$n = (int) $m[1];
			return sprintf(
				'Обнаружено %d %s без предварительной блокировки средств (payout_complete без парного payout_hold).',
				$n,
				self::russian_plural( $n, 'выплата', 'выплаты', 'выплат' )
			);
		}

		// 10. payout_cancel without payout_hold — legacy-отмены без parent-холда.
		if ( preg_match( '/^payout_cancel without payout_hold: (\d+) cancels, total ([\d.\-]+)/', $issue, $m ) ) {
			$n     = (int) $m[1];
			$total = (string) $m[2];
			return sprintf(
				'Обнаружено %d %s без парной блокировки средств — возвратов на общую сумму %s ₽ (payout_cancel без payout_hold; типично для legacy-выплат, созданных до ledger-first). Из-за этого баланс «в ожидании выплаты» в журнале уходит в минус.',
				$n,
				self::russian_plural( $n, 'отмена выплаты', 'отмены выплаты', 'отмен выплат' ),
				self::format_money( $total )
			);
		}

		// Неизвестный паттерн — возвращаем as-is, caller escape'ит.
		return $issue;
	}

	/**
	 * Строит HTML-блок для столбца «Действия» в таблице расхождений.
	 *
	 * @param array{
	 *   issues?: array<int, string>,
	 *   ledger?: array{available?: string, pending?: string, paid?: string, sums?: array<string,string>, counts?: array<string,int>},
	 *   cache?:  array{available_balance?: string, pending_balance?: string, paid_balance?: string, frozen_balance?: string}
	 * } $details Snapshot из cashback_audit_log.details.
	 */
	public static function render_mismatch_details_html( array $details ): string {
		$issues = isset( $details['issues'] ) && is_array( $details['issues'] ) ? $details['issues'] : array();
		$ledger = isset( $details['ledger'] ) && is_array( $details['ledger'] ) ? $details['ledger'] : array();
		$cache  = isset( $details['cache'] ) && is_array( $details['cache'] ) ? $details['cache'] : array();

		$html  = '<div class="cashback-reconcil-details">';
		$html .= self::render_buckets_table( $ledger, $cache );
		$html .= self::render_issues_section( $issues );
		$html .= self::render_operations_section(
			isset( $ledger['sums'] ) && is_array( $ledger['sums'] ) ? $ledger['sums'] : array(),
			isset( $ledger['counts'] ) && is_array( $ledger['counts'] ) ? $ledger['counts'] : array()
		);
		$html .= '</div>';

		return $html;
	}

	// =========================================================================
	// Внутренние помощники — рендеринг секций
	// =========================================================================

	private static function render_buckets_table( array $ledger, array $cache ): string {
		// Для не-забаненного: ledger-бакеты в 'available', 'pending', 'paid'.
		// frozen_balance — только в кэше (ledger.frozen не сохраняется в details).
		$ledger_available = isset( $ledger['available'] ) ? (string) $ledger['available'] : '0.00';
		$ledger_pending   = isset( $ledger['pending'] ) ? (string) $ledger['pending'] : '0.00';
		$ledger_paid      = isset( $ledger['paid'] ) ? (string) $ledger['paid'] : '0.00';

		$cache_available = isset( $cache['available_balance'] ) ? (string) $cache['available_balance'] : '0.00';
		$cache_pending   = isset( $cache['pending_balance'] ) ? (string) $cache['pending_balance'] : '0.00';
		$cache_paid      = isset( $cache['paid_balance'] ) ? (string) $cache['paid_balance'] : '0.00';
		$cache_frozen    = isset( $cache['frozen_balance'] ) ? (string) $cache['frozen_balance'] : '0.00';

		$rows = array(
			array( 'Доступно', $ledger_available, $cache_available ),
			array( 'В ожидании выплаты', $ledger_pending, $cache_pending ),
			array( 'Выплачено', $ledger_paid, $cache_paid ),
			array( 'Заморожено', null, $cache_frozen ),
		);

		// ИТОГО: ledger_total = available + pending + paid (frozen в snapshot'е не лежит).
		$ledger_total = bcadd( bcadd( $ledger_available, $ledger_pending, 2 ), $ledger_paid, 2 );
		$cache_total  = bcadd( bcadd( bcadd( $cache_available, $cache_pending, 2 ), $cache_paid, 2 ), $cache_frozen, 2 );

		$out  = '<table class="widefat striped cashback-reconcil-buckets">';
		$out .= '<thead><tr>';
		$out .= '<th>' . esc_html( 'Бакет' ) . '</th>';
		$out .= '<th class="num">' . esc_html( 'По журналу' ) . '</th>';
		$out .= '<th class="num">' . esc_html( 'В кэше' ) . '</th>';
		$out .= '<th class="num">' . esc_html( 'Разница' ) . '</th>';
		$out .= '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			list( $label, $ledger_val, $cache_val ) = $row;

			$diff = $ledger_val !== null
				? bcsub( (string) $cache_val, (string) $ledger_val, 2 )
				: null;
			$row_class = ( $diff !== null && bccomp( $diff, '0', 2 ) !== 0 ) ? ' class="mismatch"' : '';

			$out .= '<tr' . $row_class . '>';
			$out .= '<td>' . esc_html( (string) $label ) . '</td>';
			$out .= '<td class="num">' . ( $ledger_val === null ? '—' : esc_html( self::format_money( (string) $ledger_val ) ) . ' ₽' ) . '</td>';
			$out .= '<td class="num">' . esc_html( self::format_money( (string) $cache_val ) ) . ' ₽</td>';
			$out .= '<td class="num">';
			if ( $diff === null ) {
				$out .= '—';
			} elseif ( bccomp( $diff, '0', 2 ) === 0 ) {
				$out .= '—';
			} else {
				$out .= esc_html( self::format_money( $diff, true ) ) . ' ₽';
			}
			$out .= '</td>';
			$out .= '</tr>';
		}

		$total_diff      = bcsub( $cache_total, $ledger_total, 2 );
		$total_row_class = bccomp( $total_diff, '0', 2 ) !== 0 ? ' class="mismatch total"' : ' class="total"';
		$out            .= '<tr' . $total_row_class . '>';
		$out            .= '<td><strong>' . esc_html( 'ИТОГО' ) . '</strong></td>';
		$out            .= '<td class="num"><strong>' . esc_html( self::format_money( $ledger_total ) ) . ' ₽</strong></td>';
		$out            .= '<td class="num"><strong>' . esc_html( self::format_money( $cache_total ) ) . ' ₽</strong></td>';
		$out            .= '<td class="num"><strong>';
		$out            .= bccomp( $total_diff, '0', 2 ) === 0 ? '—' : ( esc_html( self::format_money( $total_diff, true ) ) . ' ₽' );
		$out            .= '</strong></td>';
		$out            .= '</tr>';
		$out            .= '</tbody></table>';

		return $out;
	}

	private static function render_issues_section( array $issues ): string {
		if ( empty( $issues ) ) {
			return '';
		}
		$out = '<div class="cashback-reconcil-issues-section">';
		$out .= '<h4>' . esc_html( 'Что не сходится' ) . '</h4>';
		$out .= '<ul class="cashback-reconcil-issues-list">';
		foreach ( $issues as $issue ) {
			$human = self::translate_issue( (string) $issue );
			$out  .= '<li>' . esc_html( $human ) . '</li>';
		}
		$out .= '</ul>';
		$out .= '</div>';
		return $out;
	}

	private static function render_operations_section( array $sums, array $counts ): string {
		// Группировка по знаку amount: приходы / расходы / бан.
		// Справочник: label + группа. Нулевые типы скрываем.
		$catalog = array(
			'accrual'            => array( 'Начислен кэшбэк', 'income' ),
			'affiliate_accrual'  => array( 'Партнёрская комиссия начислена', 'income' ),
			'affiliate_unfreeze' => array( 'Партнёрская: разморожено', 'income' ),
			'payout_cancel'      => array( 'Возврат по отменённой заявке', 'income' ),
			'payout_hold'        => array( 'Заявка на выплату (блокировка)', 'outflow' ),
			'payout_complete'    => array( 'Выплачено на реквизиты', 'outflow' ),
			'payout_declined'    => array( 'Выплата отклонена (заморожено)', 'outflow' ),
			'affiliate_reversal' => array( 'Партнёрская: отмена начисления', 'outflow' ),
			'affiliate_freeze'   => array( 'Партнёрская: заморожено', 'outflow' ),
			'ban_freeze'         => array( 'Заморожено при бане', 'ban' ),
			'ban_unfreeze'       => array( 'Разморожено при разбане', 'ban' ),
			'adjustment'         => array( 'Ручная корректировка', 'adjustment' ),
		);

		$groups = array(
			'income'     => array( 'label' => 'Приходы', 'sign' => '+', 'rows' => array() ),
			'outflow'    => array( 'label' => 'Расходы', 'sign' => self::MINUS_SIGN, 'rows' => array() ),
			'ban'        => array( 'label' => 'Бан / разбан', 'sign' => '', 'rows' => array() ),
			'adjustment' => array( 'label' => 'Ручные корректировки', 'sign' => '', 'rows' => array() ),
		);

		foreach ( $catalog as $type => $meta ) {
			$count = (int) ( $counts[ $type ] ?? 0 );
			$raw   = (string) ( $sums[ $type ] ?? '0.00' );
			if ( $count === 0 && bccomp( $raw, '0', 2 ) === 0 ) {
				continue; // скрываем нулевые типы
			}
			// Абсолютное значение для отображения: знак даёт заголовок группы.
			$abs = bccomp( $raw, '0', 2 ) < 0 ? bcmul( $raw, '-1', 2 ) : $raw;
			$groups[ $meta[1] ]['rows'][] = array(
				'label' => $meta[0],
				'count' => $count,
				'abs'   => $abs,
				'raw'   => $raw, // со знаком — для корректировки (может быть как + так и −)
				'sign'  => $groups[ $meta[1] ]['sign'],
			);
		}

		$has_any = false;
		foreach ( $groups as $g ) {
			if ( ! empty( $g['rows'] ) ) {
				$has_any = true;
				break;
			}
		}
		if ( ! $has_any ) {
			return '';
		}

		$out = '<div class="cashback-reconcil-operations-section">';
		$out .= '<h4>' . esc_html( 'Журнал операций (за всё время)' ) . '</h4>';

		foreach ( $groups as $group_key => $group ) {
			if ( empty( $group['rows'] ) ) {
				continue;
			}
			$out .= '<div class="cashback-reconcil-ops-group cashback-reconcil-ops-' . esc_attr( $group_key ) . '">';
			$out .= '<div class="cashback-reconcil-ops-group-title">' . esc_html( (string) $group['label'] ) . '</div>';
			$out .= '<ul class="cashback-reconcil-ops-list">';

			foreach ( $group['rows'] as $row ) {
				// Для adjustment знак неизвестен (может быть как +, так и −) — выводим из raw.
				$sign = (string) $row['sign'];
				if ( $sign === '' ) {
					$sign = bccomp( (string) $row['raw'], '0', 2 ) >= 0 ? '+' : self::MINUS_SIGN;
				}
				$count        = (int) $row['count'];
				$count_phrase = sprintf(
					'%d %s',
					$count,
					self::russian_plural( $count, 'операция', 'операции', 'операций' )
				);

				$out .= '<li>';
				$out .= '<span class="op-label">' . esc_html( (string) $row['label'] ) . '</span>';
				$out .= '<span class="op-count">' . esc_html( $count_phrase ) . '</span>';
				$out .= '<span class="op-sum">' . esc_html( $sign . ' ' . self::format_money( (string) $row['abs'] ) ) . ' ₽</span>';
				$out .= '</li>';
			}

			$out .= '</ul></div>';
		}

		$out .= '</div>';
		return $out;
	}

	// =========================================================================
	// Утилиты форматирования
	// =========================================================================

	/**
	 * Форматирует money-string в русском стиле: разделитель тысяч — обычный
	 * пробел, 2 знака после точки, типографский минус для отрицательных.
	 *
	 * @param string $raw        Канонический DECIMAL(18,2) как string, напр. '1234.56' или '-5.00'.
	 * @param bool   $show_plus  Ставить ли '+' перед положительными (для diff-ячеек).
	 */
	private static function format_money( string $raw, bool $show_plus = false ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			$raw = '0.00';
		}
		$is_negative = ( $raw !== '' && $raw[0] === '-' );
		$abs         = $is_negative ? ltrim( substr( $raw, 1 ), ' ' ) : $raw;
		// number_format требует float; DECIMAL(18,2) влезает без потерь.
		$formatted = number_format( (float) $abs, 2, '.', ' ' );
		if ( $is_negative ) {
			return self::MINUS_SIGN . $formatted;
		}
		if ( $show_plus && bccomp( $raw, '0', 2 ) > 0 ) {
			return '+' . $formatted;
		}
		return $formatted;
	}

	/**
	 * Русский плюрал: 1 → one, 2-4 → few, иначе many.
	 * С учётом 11-14 (exception): всегда → many.
	 */
	private static function russian_plural( int $n, string $one, string $few, string $many ): string {
		$n = abs( $n );
		$mod100 = $n % 100;
		if ( $mod100 >= 11 && $mod100 <= 14 ) {
			return $many;
		}
		$mod10 = $n % 10;
		if ( $mod10 === 1 ) {
			return $one;
		}
		if ( $mod10 >= 2 && $mod10 <= 4 ) {
			return $few;
		}
		return $many;
	}
}
